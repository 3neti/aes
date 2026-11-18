<?php

use App\Election\Bundle\ResearchElectionBundleService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LBHurtado\ElectionCeremonies\Bundle\AuthoritySignature;
use LBHurtado\ElectionCeremonies\Bundle\ElectionBundleVerifier;

beforeEach(function (): void {
    $this->researchDirectory = sys_get_temp_dir().'/aes-research-run-test-'.bin2hex(random_bytes(6));
    config()->set('election.bundle_workbench.run_directory', $this->researchDirectory);
});

afterEach(function (): void {
    File::deleteDirectory($this->researchDirectory);
});

test('named reviewer can commission the signed research bundle and record a sourced decision', function (): void {
    $reviewer = User::factory()->create();
    $reviewer->research_reviewer = true;
    $reviewer->save();

    $this->get(route('election.bundle.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('review.can_start', true)
            ->etc());

    $this->actingAs($reviewer)->post(route('election.bundle.review.store'))->assertRedirect();
    $run = DB::table('election_research_runs')->first();

    expect($run->stage)->toBe('reviewing')
        ->and($run->bundle_id)->toContain('review-v1');

    $this->get(route('election.bundle.review.show', $run->id))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/ResearchRun')
            ->where('run.stage', 'reviewing')
            ->etc());

    $key = (string) Str::uuid();
    $decision = [
        'case_type' => 'precinct',
        'case_id' => '76040001',
        'decision' => 'approve',
        'rationale' => 'Official district statute matches this barangay.',
        'idempotency_key' => $key,
    ];
    $this->post(route('election.bundle.review.decide', $run->id), $decision)->assertRedirect();
    $this->post(route('election.bundle.review.decide', $run->id), $decision)->assertRedirect();

    expect(DB::table('election_research_decisions')->where('run_id', $run->id)->count())->toBe(1)
        ->and(DB::table('election_research_events')->where('run_id', $run->id)->count())->toBe(2);

    $this->get(route('election.bundle.index', ['q' => '76040001', 'review_decision' => 'undecided']))
        ->assertInertia(fn (Assert $page) => $page->where('browse.total', 0)->etc());
    $this->get(route('election.bundle.index', ['q' => '76040001', 'review_decision' => 'approve']))
        ->assertInertia(fn (Assert $page) => $page->where('browse.total', 1)->etc());

    $this->post(route('election.bundle.review.close', $run->id), ['idempotency_key' => (string) Str::uuid()])
        ->assertRedirect();

    $closed = DB::table('election_research_runs')->where('id', $run->id)->first();
    $report = json_decode((string) file_get_contents($closed->artifact_directory.'/review/research-review.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($closed->stage)->toBe('closed')
        ->and($report['national_coverage_ready'])->toBeFalse()
        ->and($report['decision_event_count'])->toBe(1)
        ->and($report['decision_ledger_sha256'])->toBe(hash_file('sha256', $closed->artifact_directory.'/review/decisions.jsonl'));

    $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $public = sodium_crypto_sign_publickey_from_secretkey($secret);
    $reviewedArchive = $this->researchDirectory.'/reviewed.zip';
    $built = app(ResearchElectionBundleService::class)->build(
        $reviewedArchive,
        $secret,
        $closed->artifact_directory.'/review/research-review.json',
        $closed->artifact_directory.'/review/decisions.jsonl',
    );
    $signatures = app(AuthoritySignature::class);
    $verified = app(ElectionBundleVerifier::class)->verify($reviewedArchive, [
        $signatures->keyId($public) => $public,
    ]);

    expect($built['bundle_id'])->toContain('-review-')
        ->and($verified['readiness'])->toBe('research')
        ->and($verified['files'])->toHaveKeys(['reports/research-review.json', 'reports/review-decisions.jsonl']);
});

test('non reviewers cannot start or alter a research run', function (): void {
    $user = User::factory()->create();

    $this->post(route('election.bundle.review.store'))->assertRedirect('/election/bundle/review/login');
    $this->actingAs($user)->post(route('election.bundle.review.store'))->assertForbidden();
});

test('only a named research reviewer can sign in', function (): void {
    $reviewer = User::factory()->create(['password' => 'correct-password-for-review']);
    $reviewer->research_reviewer = true;
    $reviewer->save();
    $ordinary = User::factory()->create(['password' => 'ordinary-user-password']);

    $this->post(route('election.bundle.review.authenticate'), [
        'email' => $ordinary->email,
        'password' => 'ordinary-user-password',
    ])->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->post(route('election.bundle.review.authenticate'), [
        'email' => $reviewer->email,
        'password' => 'correct-password-for-review',
    ])->assertRedirect();
    $this->assertAuthenticatedAs($reviewer);
});

test('changed ceremony events are rejected before another decision', function (): void {
    $reviewer = User::factory()->create();
    $reviewer->research_reviewer = true;
    $reviewer->save();
    $this->actingAs($reviewer)->post(route('election.bundle.review.store'))->assertRedirect();
    $runId = DB::table('election_research_runs')->value('id');
    DB::table('election_research_events')->where('run_id', $runId)->update(['result' => '{}']);

    $this->get(route('election.bundle.review.show', $runId))->assertNotFound();
});

test('research review rejects unsupported approvals and correction approval', function (): void {
    $reviewer = User::factory()->create();
    $reviewer->research_reviewer = true;
    $reviewer->save();
    $this->actingAs($reviewer)->post(route('election.bundle.review.store'))->assertRedirect();
    $runId = DB::table('election_research_runs')->value('id');

    $this->post(route('election.bundle.review.decide', $runId), [
        'case_type' => 'precinct',
        'case_id' => '7010001',
        'decision' => 'approve',
        'rationale' => 'No reviewed district evidence exists yet.',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasErrors('research');

    $correctionId = (string) config('election.bundle_workbench.reconciliation_directory');
    $ledger = json_decode((string) file_get_contents($correctionId.'/correction-ledger.json'), true, flags: JSON_THROW_ON_ERROR);
    $this->post(route('election.bundle.review.decide', $runId), [
        'case_type' => 'correction',
        'case_id' => $ledger['items'][0]['id'],
        'decision' => 'approve',
        'rationale' => 'This notice still has no reviewed change scope.',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasErrors('research');

    expect(DB::table('election_research_decisions')->count())->toBe(0);
});

test('a changed workstation assignment cannot start research review', function (): void {
    $signaturePath = $this->researchDirectory.'/invalid.sig';
    File::ensureDirectoryExists($this->researchDirectory);
    file_put_contents($signaturePath, '{}');
    config()->set('election.bundle_workbench.assignment_signature', $signaturePath);
    $reviewer = User::factory()->create();
    $reviewer->research_reviewer = true;
    $reviewer->save();

    $this->actingAs($reviewer)->get(route('election.bundle.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('review.can_start', false)
            ->etc());
    $this->post(route('election.bundle.review.store'))->assertSessionHasErrors('research');
    expect(DB::table('election_research_runs')->count())->toBe(0);
});
