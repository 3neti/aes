<?php

use App\Election\Support\ElectionStorage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config()->set('election.review.access.enabled', false);
    app(ElectionStorage::class)->reset();
    $this->withoutVite();
});

test('canvassing demo page loads before an election return is generated', function (): void {
    $this->get(route('election.canvassing-demo.show'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/CanvassingDemo')
            ->where('simulation.maximum_ballots', 1000)
            ->where('simulation.maximum_returns', 20)
            ->where('simulation.default_ballots', 1000)
            ->where('simulation.default_returns', 5)
            ->where('simulation.configuration.precinct_id', '39010402')
            ->where('simulation.configuration.contest_count', 8)
            ->where('simulation.configuration.candidate_count', 200)
            ->where('simulation.document_rendering.asset_bundle.id', 'waes-election-assets-2025')
            ->where('simulation.document_rendering.profiles.official_ballot.id', 'waes-official-ballot-a4-tondo-2025')
            ->where('simulation.document_rendering.profiles.election_return.id', 'waes-election-return-a4-tondo-2025')
            ->where('simulation.run', null)
            ->where('simulation.scanner.returns', [])
            ->where('actions.generate', route('election.canvassing-demo.generate'))
        );
});

test('canvassing demo generates an election return from ballot qr payloads', function (): void {
    $this->post(route('election.canvassing-demo.generate'), [
        'ballot_count' => 25,
        'return_count' => 3,
    ])->assertRedirectToRoute('election.canvassing-demo.show')
        ->assertSessionHas('canvassing_demo.feedback', 'Generated 3 election returns from 25 ballot QR payloads each.');

    $storage = app(ElectionStorage::class);
    $state = $storage->readJson('runtime/canvassing-demo.json');
    $firstReturnQrCount = count($state['return_scan']['payloads']);

    expect($state['ballots_per_return'])->toBe(25)
        ->and($state['return_count'])->toBe(3)
        ->and($state['total_ballots'])->toBe(75)
        ->and($state['precinct_id'])->toBe('39010402')
        ->and($state['contest_count'])->toBe(8)
        ->and($state['candidate_count'])->toBe(200)
        ->and($state['return_qr_count'])->toBeGreaterThan($state['return_count'])
        ->and($state['return_scans'])->toHaveCount(3)
        ->and($state['return_scan']['accepted_ballots'])->toBe(25)
        ->and($state['return_scans'][1]['accepted_ballots'])->toBe(25)
        ->and($state['return_scans'][2]['accepted_ballots'])->toBe(25)
        ->and($state['return_scan']['payloads'])->toHaveCount($firstReturnQrCount)
        ->and($firstReturnQrCount)->toBeGreaterThan(1)
        ->and($state['return_scan']['payloads'][0])->toStartWith("truth://v1/waes-election-return/waes-er-fragment-1/1/{$firstReturnQrCount}?h=")
        ->and($state['return_scan']['document_profile']['id'])->toBe('waes-election-return-a4-tondo-2025')
        ->and($state['return_scan']['document_profile']['asset_bundle_id'])->toBe('waes-election-assets-2025')
        ->and($state['sample_ballots'])->toHaveCount(10)
        ->and($state['sample_ballots'][0]['precinct_id'])->toBe('39010402-CV001')
        ->and($state['sample_ballots'][0]['qr_payload'])->toStartWith('truth://v1/waes-ballot/aes-ballot-compact-1?p=')
        ->and($state['sample_ballots'][0]['canonical_payload'])->toContain('waes-official-ballot-a4-tondo-2025')
        ->and($storage->path("returns/canvassing-demo/39010402-CV001-truth-tally-qr-1-of-{$firstReturnQrCount}.png"))->toBeReadableFile()
        ->and($storage->path('returns/canvassing-demo/39010402-CV003-return.json'))->toBeReadableFile();

    $this->get(route('election.canvassing-demo.show'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Election/CanvassingDemo')
            ->where('simulation.run.ballots_per_return', 25)
            ->where('simulation.run.return_count', 3)
            ->where('simulation.run.total_ballots', 75)
            ->where('simulation.run.candidate_count', 200)
            ->has('simulation.scanner.returns', 3)
            ->where('simulation.scanner.returns.0.accepted_ballots', 25)
            ->where('simulation.scanner.returns.0.canonical_payload', fn (string $payload): bool => str_starts_with($payload, 'waes-er-compact-1:'))
            ->where('simulation.scanner.initial_tally', fn (mixed $tally): bool => collect($tally)->flatten()->every(fn (int $votes): bool => $votes === 0))
        );
});

test('canvassing demo enforces a one thousand ballot maximum per election return', function (): void {
    $this->post(route('election.canvassing-demo.generate'), [
        'ballot_count' => 1001,
        'return_count' => 2,
    ])->assertSessionHasErrors('ballot_count');

    $this->post(route('election.canvassing-demo.generate'), [
        'ballot_count' => 1000,
        'return_count' => 2,
    ])->assertRedirectToRoute('election.canvassing-demo.show');

    $state = app(ElectionStorage::class)->readJson('runtime/canvassing-demo.json');

    expect($state['ballots_per_return'])->toBe(1000)
        ->and($state['return_count'])->toBe(2)
        ->and($state['total_ballots'])->toBe(2000)
        ->and($state['return_scan']['accepted_ballots'])->toBe(1000)
        ->and($state['return_scan']['return_hash'])->toBeString()
        ->and($state['return_scan']['payload_hash'])->toBeString();
});

test('canvassing demo enforces a twenty election return maximum per canvass', function (): void {
    $this->post(route('election.canvassing-demo.generate'), [
        'ballot_count' => 1000,
        'return_count' => 21,
    ])->assertSessionHasErrors('return_count');
});
