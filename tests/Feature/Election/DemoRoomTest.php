<?php

use App\Election\Core\ActivityJournal;
use App\Election\Printing\CloseoutArtifactPrinter;
use App\Election\Printing\PrintFormProfile;
use App\Election\PublicSimulation\PublicSimulationScope;
use App\Election\Support\ElectionStorage;
use App\Models\SimulationPrecinct;
use App\Models\SimulationRound;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config()->set('election.review.access.enabled', false);
    config()->set('election.public_simulation.enabled', true);
    config()->set('election.public_simulation.participation_required', false);
    config()->set('election.devices.printer.driver', 'file');
    config()->set('election.closeout_printer.driver', 'file');
    config()->set('election.closeout_printer.default_profile', 'thermal-80');
    $this->withoutVite();
});

test('the demo room runs a precinct through officer, voter, print station, watcher, and handoff roles', function (): void {
    config()->set('election.public_simulation.demo_control_number_share.enabled', true);

    $this->get(route('election.demo-room.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/DemoRoomLobby')
            ->has('round.precincts', 3)
        );

    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();
    expect($precinct)->toBeInstanceOf(SimulationPrecinct::class);

    $credentials = [
        'officer_code' => $precinct->officer_code,
        'officer_pin' => '123456',
    ];

    $this->get(route('election.demo-room.show', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/DemoRoomPrecinct')
            ->has('roles', 5)
            ->where('officerDefaults.officer_code', $precinct->officer_code)
            ->where('officerDefaults.officer_pin', '123456')
        );

    $this->get(route('election.demo-room.officer', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/DemoRoomOfficer')
            ->where('actions.open', route('election.demo-room.open', [$round, $precinct]))
        );

    $this->post(route('election.demo-room.open', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.demo-room.officer', [$round, $precinct]));
    expect($precinct->fresh()->status)->toBe('open');

    $this->post(route('election.demo-room.admit', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.demo-room.officer', [$round, $precinct]));
    $authorization = session('public_simulation.control_number');
    expect($authorization)->toBeArray()
        ->and($authorization['code'])->toMatch('/^[0-9]{4}$/');

    $this->get(route('election.demo-room.officer', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('controlNumber.code', $authorization['code'])
            ->where('controlNumber.voter_entry.url', route('election.public-simulation.voter.show', [$round, $precinct, 'code' => $authorization['code']]))
            ->where('controlNumber.voter_entry.qr', fn (string $qr): bool => str_starts_with($qr, 'data:image/png;base64,'))
            ->where('actions.dismissControlNumber', route('election.demo-room.dismiss-control-number', [$round, $precinct]))
        );

    $this->get(route('election.public-simulation.voter.show', [$round, $precinct, 'code' => $authorization['code']]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/VoterWelcome')
            ->where('initialControlNumber', $authorization['code'])
        );

    $this->get(route('election.demo-room.officer', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('controlNumber.code', $authorization['code'])
        );

    $this->post(route('election.demo-room.dismiss-control-number', [$round, $precinct]))
        ->assertRedirect(route('election.demo-room.officer', [$round, $precinct]));

    $this->get(route('election.demo-room.officer', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('controlNumber', null)
        );

    $this->post(route('election.public-simulation.voter.claim', [$round, $precinct]), [
        'code' => $authorization['code'],
    ])->assertRedirect(route('election.public-simulation.voter.ballot', [$round, $precinct]));

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    $configuration = app(ElectionStorage::class)->readJson('runtime/active-precinct.json');
    $selections = collect($configuration['contests'])
        ->mapWithKeys(fn (array $contest): array => [
            $contest['id'] => collect($contest['candidates'])
                ->take(min(1, (int) $contest['max_selections']))
                ->pluck('id')
                ->all(),
        ])
        ->all();

    $this->post(route('election.public-simulation.voter.finalize', [$round, $precinct]), [
        'selections' => $selections,
    ])->assertRedirect(route('election.public-simulation.voter.complete', [$round, $precinct]));
    $release = session("public_simulation.{$precinct->id}.release");
    expect($release)->toBeArray()
        ->and($release['release_code'])->toMatch('/^[0-9]{4,6}$/');

    $this->get(route('election.demo-room.print.station', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/DemoRoomPrintStation')
            ->where('enabled', false)
            ->where('officerDefaults.officer_code', $precinct->officer_code)
        );

    $this->post(route('election.demo-room.print.enable', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.demo-room.print.station', [$round, $precinct]));

    $this->get(route('election.demo-room.print.station', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('enabled', true)
            ->where('isVoting', true)
            ->where('isCloseoutReady', false)
            ->where('artifacts.tally_sheet_pdf', false)
            ->where('artifacts.election_return_pdf', false)
        );

    $this->post(route('election.demo-room.print.redeem', [$round, $precinct]), [
        'code' => $release['release_code'],
    ])->assertRedirect(route('election.demo-room.print.station', [$round, $precinct]));
    $this->post(route('election.demo-room.print.print', [$round, $precinct]))
        ->assertRedirect(route('election.demo-room.print.station', [$round, $precinct]));

    $this->get(route('election.demo-room.print.station', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('release.status', 'printed')
            ->where('ballotPreview.ballot_id', fn (?string $ballotId): bool => $ballotId !== null && $ballotId !== '')
            ->where('ballotPreview.qr_payload', fn (?string $payload): bool => $payload !== null && str_starts_with($payload, 'aes-ballot-compact-1:') && ! str_contains($payload, '||CAND'))
            ->where('ballotPreview.decoded.paper_ballot_serial', fn (?string $serial): bool => $serial !== null && $serial !== '')
            ->where('ballotPreview.candidate_mapping.0.code', 'CAND00001')
            ->where('ballotPreviewUrl', fn (?string $url): bool => $url !== null && str_contains($url, '/print/ballot-preview'))
            ->has('ballotPreview.rows')
        );

    $this->get(route('election.demo-room.print.ballot-preview', [$round, $precinct]))
        ->assertSuccessful()
        ->assertHeader('content-disposition', 'inline; filename="'.$precinct->code.'-printed-ballot-preview.pdf"');

    $printedBallotPdf = collect(app(ElectionStorage::class)->files('ballots'))
        ->first(fn (string $path): bool => str_ends_with($path, '.pdf'));

    expect($printedBallotPdf)->toBeString()
        ->and(file_get_contents($printedBallotPdf))->toStartWith('%PDF-')
        ->and(file_get_contents($printedBallotPdf))->toContain('SELECTED CANDIDATES ONLY')
        ->and(file_get_contents($printedBallotPdf))->toContain('Ballot QR Verification')
        ->and(file_get_contents($printedBallotPdf))->not->toContain('BALLOT QR VERIFICATION COPY')
        ->and(file_get_contents($printedBallotPdf))->toContain('/Count 1');

    $this->post(route('election.demo-room.print.deposit', [$round, $precinct]))
        ->assertRedirect(route('election.demo-room.print.station', [$round, $precinct]));

    $this->get(route('election.demo-room.print.station', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('release', [])
            ->where('ballotPreview', null)
            ->where('depositFeedback.status', 'accepted')
        );

    $this->get(route('election.demo-room.print.tally-sheet', [$round, $precinct]))
        ->assertRedirect(route('election.demo-room.print.station', [$round, $precinct]));

    $this->get(route('election.demo-room.print.station', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('closeoutFeedback', 'Close the precinct first. The tally sheet is generated after closeout.')
        );

    $this->post(route('election.demo-room.close', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.demo-room.officer', [$round, $precinct]));
    expect($precinct->fresh()->status)->toBe('results_ready');

    $this->get(route('election.demo-room.print.station', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('enabled', true)
            ->where('isVoting', false)
            ->where('isCloseoutReady', true)
            ->where('isPublished', false)
            ->where('artifacts.tally_sheet_pdf', true)
            ->where('artifacts.election_return_pdf', true)
            ->where('actions.tally', route('election.demo-room.print.tally-sheet', [$round, $precinct]))
            ->where('actions.return', route('election.demo-room.print.election-return', [$round, $precinct]))
            ->where('closeoutPrinter.driver', 'file')
            ->where('closeoutPrinter.default_profile', 'thermal-80')
            ->where('closeoutPrinter.submit_label', 'Prepare for local print')
            ->has('printProfiles', 3)
            ->where('printProfiles.0.profile', 'a4')
            ->where('printProfiles.0.tally_available', true)
            ->where('printProfiles.0.return_available', true)
            ->where('printProfiles.1.profile', 'thermal-80')
            ->where('printProfiles.1.tally_url', route('election.demo-room.print.tally-sheet', [$round, $precinct, 'thermal-80']))
            ->where('printProfiles.1.return_url', route('election.demo-room.print.election-return', [$round, $precinct, 'thermal-80']))
            ->where('printProfiles.1.tally_submit_url', route('election.demo-room.print.tally-sheet.submit', [$round, $precinct, 'thermal-80']))
            ->where('printProfiles.1.return_submit_url', route('election.demo-room.print.election-return.submit', [$round, $precinct, 'thermal-80']))
            ->where('printProfiles.2.profile', 'thermal-58')
        );

    $this->get(route('election.demo-room.print.tally-sheet', [$round, $precinct]))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'application/pdf');

    $this->get(route('election.demo-room.print.election-return', [$round, $precinct]))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'application/pdf');

    $this->get(route('election.demo-room.print.tally-sheet', [$round, $precinct, 'thermal-80']))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'application/pdf');

    $this->get(route('election.demo-room.print.election-return', [$round, $precinct, 'thermal-58']))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'application/pdf');

    $this->post(route('election.demo-room.print.tally-sheet.submit', [$round, $precinct, 'thermal-80']))
        ->assertRedirect(route('election.demo-room.print.station', [$round, $precinct]))
        ->assertSessionHas("demo_room.{$precinct->id}.closeout_feedback", 'Tally sheet 80 mm thermal roll PDF is ready for browser printing.');

    config()->set('election.closeout_printer.driver', 'cups');
    config()->set('election.closeout_printer.cups.name', 'USB_Thermal_Printer');
    Process::fake();

    $this->post(route('election.demo-room.print.election-return.submit', [$round, $precinct, 'thermal-58']))
        ->assertRedirect(route('election.demo-room.print.station', [$round, $precinct]))
        ->assertSessionHas("demo_room.{$precinct->id}.closeout_feedback", 'Election Return submitted to USB_Thermal_Printer.');

    Process::assertRan(function (PendingProcess $process): bool {
        return is_array($process->command)
            && $process->command[0] === 'lp'
            && $process->command[1] === '-d'
            && $process->command[2] === 'USB_Thermal_Printer'
            && str_contains((string) $process->command[4], 'Election Return')
            && str_ends_with((string) $process->command[5], '/thermal-58.pdf');
    });

    $this->post(route('election.demo-room.publish', [$round, $precinct]), $credentials)
        ->assertRedirect(route('election.demo-room.officer', [$round, $precinct]));
    expect($precinct->fresh()->status)->toBe('published');

    $this->get(route('election.demo-room.print.station', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('enabled', true)
            ->where('isVoting', false)
            ->where('isPublished', true)
            ->where('actions.tally', route('election.demo-room.print.tally-sheet', [$round, $precinct]))
            ->where('actions.return', route('election.demo-room.print.election-return', [$round, $precinct]))
            ->where('printProfiles.1.profile', 'thermal-80')
            ->where('printProfiles.1.tally_available', true)
            ->where('printProfiles.1.return_available', true)
        );

    $this->get(route('election.demo-room.handoff', [$round, $precinct]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/DemoRoomHandoff')
            ->where('downloads.watcher', route('election.public-simulation.watcher.show', [$round, $precinct]))
        );

    app(PublicSimulationScope::class)->apply($precinct->fresh('round'));
    $storage = app(ElectionStorage::class);
    $return = $storage->readJson("returns/{$configuration['precinct_id']}-return.json");

    expect($return['accepted_ballots'])->toBe(1)
        ->and($storage->path('runtime/tally-sheet.pdf'))->toBeReadableFile()
        ->and($storage->path("returns/{$configuration['precinct_id']}-return.pdf"))->toBeReadableFile()
        ->and(collect(app(ActivityJournal::class)->entries())->pluck('event_type'))
        ->toContain('closeout.print_requested', 'closeout.print_submitted');
});

test('closeout printer reports missing artifacts and failed cups configuration', function (): void {
    app(ElectionStorage::class)->reset();
    config()->set('election.closeout_printer.driver', 'file');

    $missing = app(CloseoutArtifactPrinter::class)->print(
        'election-return',
        PrintFormProfile::A4,
        'TONDO-01',
        '39010001',
    );

    expect($missing['status'])->toBe('missing')
        ->and($missing['message'])->toBe('Election Return PDF is not available for A4 evidence copy.');

    $storage = app(ElectionStorage::class);
    $storage->writeText('print-forms/tally-sheet/a4.pdf', '%PDF-1.4 closeout test');
    config()->set('election.closeout_printer.driver', 'cups');
    config()->set('election.closeout_printer.cups.name', '');

    $failed = app(CloseoutArtifactPrinter::class)->print(
        'tally-sheet',
        PrintFormProfile::A4,
        'TONDO-01',
        '39010001',
    );

    expect($failed['status'])->toBe('failed')
        ->and($failed['message'])->toBe('CUPS printer name is not configured.')
        ->and(collect(app(ActivityJournal::class)->entries())->pluck('event_type'))
        ->toContain('closeout.print_failed');
});

test('the demo room can start a fresh three precinct set before closeout', function (): void {
    $this->get(route('election.demo-room.index'))
        ->assertSuccessful();

    $round = SimulationRound::query()->with('precincts')->sole();
    $precinct = $round->precincts->first();

    $this->post(route('election.demo-room.open', [$round, $precinct]), [
        'officer_code' => $precinct->officer_code,
        'officer_pin' => '123456',
    ])->assertRedirect(route('election.demo-room.officer', [$round, $precinct]));

    expect($round->fresh()->status)->toBe('open')
        ->and($precinct->fresh()->status)->toBe('open');

    $this->post(route('election.demo-room.refresh'))
        ->assertRedirect(route('election.demo-room.index'))
        ->assertSessionHas('public_simulation.officer_feedback');

    $freshRound = SimulationRound::query()
        ->with('precincts')
        ->where('status', 'open')
        ->sole();

    expect($round->fresh()->status)->toBe('archived')
        ->and($round->fresh()->archived_at)->not->toBeNull()
        ->and($freshRound->id)->not->toBe($round->id)
        ->and($freshRound->precincts)->toHaveCount(3)
        ->and($freshRound->precincts->pluck('status')->all())->toBe(['ready', 'ready', 'ready']);
});
