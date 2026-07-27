<?php

use App\Election\Diagnostics\CloudEvidenceMirror;
use App\Election\Lifecycle\ElectionRunType;
use App\Election\Support\ElectionOperationLock;
use App\Election\Support\ElectionStorage;
use Illuminate\Support\Facades\Storage;

test('a run is mirrored to private object storage with a verified manifest', function (): void {
    Storage::fake('election_evidence');
    config()->set('election.cloud_evidence.enabled', true);
    config()->set('election.cloud_evidence.disk', 'election_evidence');
    config()->set('election.cloud_evidence.prefix', 'review-evidence');

    $storage = app(ElectionStorage::class);
    $storage->selectRunType(ElectionRunType::AutomatedTest);
    $storage->reset(ElectionRunType::AutomatedTest);
    $run = $storage->startRun(
        'cloud-evidence-test',
        '39010001',
        '20260508-080000',
        ElectionRunType::AutomatedTest,
        'automated-test',
    );
    $storage->writeJson('runtime/lifecycle.json', ['stage' => 'provision']);

    $report = app(CloudEvidenceMirror::class)->mirror($run['run_path']);

    expect($report)
        ->passed->toBeTrue()
        ->run_id->toBe($run['run_id'])
        ->artifact_count->toBeGreaterThan(0)
        ->manifest_hash->toBeString();

    Storage::disk('election_evidence')
        ->assertExists($report['manifest_path'])
        ->assertExists($report['remote_root'].'/00-start-here/lifecycle.json');

    $manifest = json_decode(
        Storage::disk('election_evidence')->get($report['manifest_path']),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest['artifact_count'])->toBe($report['artifact_count'])
        ->and($manifest['manifest_hash'])->toBe($report['manifest_hash']);
});

test('the mirror command reports the remote evidence manifest', function (): void {
    Storage::fake('election_evidence');
    config()->set('election.cloud_evidence.enabled', true);

    $storage = app(ElectionStorage::class);
    $storage->selectRunType(ElectionRunType::AutomatedTest);
    $storage->reset(ElectionRunType::AutomatedTest);
    $run = $storage->startRun(
        'cloud-evidence-command-test',
        '39010001',
        '20260508-081000',
        ElectionRunType::AutomatedTest,
        'automated-test',
    );

    $this->artisan('election:evidence-mirror', ['run' => $run['run_path']])
        ->expectsOutputToContain('Mirrored election run')
        ->expectsOutputToContain('Manifest:')
        ->assertSuccessful();
});

test('election operation locks execute through the configured shared cache', function (): void {
    config()->set('cache.default', 'array');

    $result = app(ElectionOperationLock::class)->execute(
        'run:39010001:issue-ballot:000001',
        fn (): string => 'completed',
    );

    expect($result)->toBe('completed');
});
