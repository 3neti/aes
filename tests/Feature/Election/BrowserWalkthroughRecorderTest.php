<?php

use App\Election\Lifecycle\ElectionRunType;
use App\Election\Scenarios\BrowserWalkthroughControl;
use App\Election\Support\ElectionStorage;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    config()->set('election.runtime.run_type', null);
    app(ElectionStorage::class)->reset();
});

test('browser walkthrough command records and finalizes an isolated rehearsal', function (): void {
    $storage = app(ElectionStorage::class);
    $storage->selectRunType(ElectionRunType::ElectionDay);
    $electionDay = $storage->startRun(
        'operator',
        '39010001',
        '20260511-050000',
        ElectionRunType::ElectionDay,
    );
    config()->set('election.runtime.run_type', ElectionRunType::ElectionDay->value);

    Process::fake(function (PendingProcess $process) {
        $artifactDirectory = $process->environment['ELECTION_WALKTHROUGH_ARTIFACT_DIR'];
        $videoPath = $artifactDirectory.'/full-election.webm';
        $tracePath = $artifactDirectory.'/playwright-trace.zip';
        $storyboardHtmlPath = $artifactDirectory.'/walkthrough-storyboard.html';
        $storyboardPdfPath = $artifactDirectory.'/walkthrough-storyboard.pdf';
        $storyboardJsonPath = $artifactDirectory.'/walkthrough-storyboard.json';
        $storyboardFramePath = $artifactDirectory.'/storyboard-frames/01-home.png';

        file_put_contents($videoPath, 'recorded-video');
        file_put_contents($tracePath, 'recorded-trace');
        mkdir(dirname($storyboardFramePath), recursive: true);
        file_put_contents($storyboardFramePath, 'storyboard-frame');
        file_put_contents($storyboardHtmlPath, '<html><body>Walkthrough storyboard</body></html>');
        file_put_contents($storyboardPdfPath, '%PDF-1.4 storyboard');
        file_put_contents($storyboardJsonPath, json_encode([
            'schema_version' => 'browser-walkthrough-storyboard-1',
            'checkpoints' => [],
        ], JSON_THROW_ON_ERROR));

        return Process::result(output: json_encode([
            'schema_version' => 'browser-walkthrough-recorder-result-1',
            'passed' => true,
            'statistics' => [
                'actions_recorded' => 2,
                'actions_completed' => 2,
                'browser_messages' => 0,
                'ballots_requested' => 3,
            ],
            'artifacts' => [
                [
                    'label' => 'Video',
                    'path' => $videoPath,
                    'relative_path' => 'full-election.webm',
                    'bytes' => filesize($videoPath),
                    'sha256' => hash_file('sha256', $videoPath),
                ],
                [
                    'label' => 'Playwright trace',
                    'path' => $tracePath,
                    'relative_path' => 'playwright-trace.zip',
                    'bytes' => filesize($tracePath),
                    'sha256' => hash_file('sha256', $tracePath),
                ],
                [
                    'label' => 'Walkthrough storyboard HTML',
                    'path' => $storyboardHtmlPath,
                    'relative_path' => 'walkthrough-storyboard.html',
                    'bytes' => filesize($storyboardHtmlPath),
                    'sha256' => hash_file('sha256', $storyboardHtmlPath),
                ],
                [
                    'label' => 'Walkthrough storyboard PDF',
                    'path' => $storyboardPdfPath,
                    'relative_path' => 'walkthrough-storyboard.pdf',
                    'bytes' => filesize($storyboardPdfPath),
                    'sha256' => hash_file('sha256', $storyboardPdfPath),
                ],
                [
                    'label' => 'Walkthrough storyboard data',
                    'path' => $storyboardJsonPath,
                    'relative_path' => 'walkthrough-storyboard.json',
                    'bytes' => filesize($storyboardJsonPath),
                    'sha256' => hash_file('sha256', $storyboardJsonPath),
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    });

    $this->artisan('election:browser-walkthrough', [
        'scenario' => 'full-election',
        '--ballots' => 3,
        '--slow-mo' => 0,
        '--base-url' => 'http://127.0.0.1:8000',
    ])
        ->expectsOutputToContain('Browser walkthrough passed.')
        ->assertSuccessful();

    $rehearsal = $storage->currentRun(ElectionRunType::Rehearsal);
    $reportPath = $rehearsal['run_path'].'/12-audit-and-reconciliation/browser-recordings/browser-walkthrough-report.json';
    $report = json_decode(file_get_contents($reportPath), true, flags: JSON_THROW_ON_ERROR);
    $browserDirectory = dirname($reportPath);
    $browserIndex = json_decode(file_get_contents($browserDirectory.'/browser-artifact-index.json'), true, flags: JSON_THROW_ON_ERROR);
    $lifecycleReport = json_decode(file_get_contents($browserDirectory.'/browser-lifecycle-report.json'), true, flags: JSON_THROW_ON_ERROR);
    $completion = json_decode(file_get_contents($browserDirectory.'/browser-walkthrough-completion.json'), true, flags: JSON_THROW_ON_ERROR);
    $archiveReport = json_decode(file_get_contents($rehearsal['run_path'].'/12-audit-and-reconciliation/evidence-bundle-archive.json'), true, flags: JSON_THROW_ON_ERROR);
    $archiveVerification = json_decode(file_get_contents($rehearsal['run_path'].'/12-audit-and-reconciliation/evidence-bundle-archive-verification.json'), true, flags: JSON_THROW_ON_ERROR);
    $archiveContents = file_get_contents($archiveReport['archive_path']);

    expect($rehearsal['status'])->toBe('locked')
        ->and($storage->currentRun(ElectionRunType::ElectionDay)['run_id'])->toBe($electionDay['run_id'])
        ->and($report['passed'])->toBeTrue()
        ->and($report['statistics']['ballots_requested'])->toBe(3)
        ->and($lifecycleReport['passed'])->toBeTrue()
        ->and($lifecycleReport['statistics']['ballots_requested'])->toBe(3)
        ->and($browserIndex['artifact_count'])->toBeGreaterThanOrEqual(5)
        ->and(collect($browserIndex['artifacts'])->pluck('relative_path'))->toContain(
            '12-audit-and-reconciliation/browser-recordings/full-election.webm',
            '12-audit-and-reconciliation/browser-recordings/browser-walkthrough-report.json',
            '12-audit-and-reconciliation/browser-recordings/browser-lifecycle-report.json',
            '12-audit-and-reconciliation/browser-recordings/walkthrough-storyboard.html',
            '12-audit-and-reconciliation/browser-recordings/walkthrough-storyboard.pdf',
            '12-audit-and-reconciliation/browser-recordings/walkthrough-storyboard.json',
        )
        ->and($completion['passed'])->toBeTrue()
        ->and($completion['post_recording']['archive_verified'])->toBeTrue()
        ->and($archiveVerification['passed'])->toBeTrue()
        ->and($archiveContents)->toContain('browser-recordings/full-election.webm')
        ->and($archiveContents)->toContain('browser-recordings/playwright-trace.zip')
        ->and($archiveContents)->toContain('browser-recordings/browser-artifact-index.json')
        ->and($archiveContents)->toContain('browser-recordings/walkthrough-storyboard.html')
        ->and($archiveContents)->toContain('browser-recordings/walkthrough-storyboard.pdf')
        ->and($archiveContents)->toContain('browser-recordings/walkthrough-storyboard.json')
        ->and($archiveContents)->toContain('browser-recordings/storyboard-frames/01-home.png')
        ->and($rehearsal['run_path'].'/artifact-index.json')->toBeFile()
        ->and(config('election.runtime.run_type'))->toBe(ElectionRunType::ElectionDay->value);

    Process::assertRan(function (PendingProcess $process): bool {
        $token = $process->environment['ELECTION_WALKTHROUGH_TOKEN'] ?? '';

        return $process->command === [
            'node',
            base_path('scripts/election-browser-walkthrough.mjs'),
        ]
            && is_string($token)
            && strlen($token) === 64
            && ($process->environment['ELECTION_WALKTHROUGH_GHOSTSCRIPT'] ?? null) === config('election.pdf.ghostscript_binary')
            && ! str_contains(implode(' ', $process->command), $token)
            && $process->timeout === 900;
    });
});

test('browser walkthrough command records public simulation lobby officer voter and watcher flow', function (): void {
    config()->set('election.public_simulation.enabled', true);

    Process::fake(function (PendingProcess $process) {
        $artifactDirectory = $process->environment['ELECTION_WALKTHROUGH_ARTIFACT_DIR'];
        $scenario = $process->environment['ELECTION_WALKTHROUGH_SCENARIO'];
        $videoPath = "{$artifactDirectory}/{$scenario}.webm";
        $tracePath = "{$artifactDirectory}/playwright-trace.zip";
        $storyboardHtmlPath = "{$artifactDirectory}/walkthrough-storyboard.html";
        $storyboardPdfPath = "{$artifactDirectory}/walkthrough-storyboard.pdf";
        $storyboardJsonPath = "{$artifactDirectory}/walkthrough-storyboard.json";
        $storyboardFramePath = "{$artifactDirectory}/storyboard-frames/01-public-simulation-lobby.png";

        file_put_contents($videoPath, 'recorded-public-video');
        file_put_contents($tracePath, 'recorded-public-trace');
        mkdir(dirname($storyboardFramePath), recursive: true);
        file_put_contents($storyboardFramePath, 'storyboard-frame');
        file_put_contents($storyboardHtmlPath, '<html><body>Public walkthrough storyboard</body></html>');
        file_put_contents($storyboardPdfPath, '%PDF-1.4 public storyboard');
        file_put_contents($storyboardJsonPath, json_encode([
            'schema_version' => 'browser-walkthrough-storyboard-1',
            'checkpoints' => [],
        ], JSON_THROW_ON_ERROR));

        return Process::result(output: json_encode([
            'schema_version' => 'browser-walkthrough-recorder-result-1',
            'passed' => true,
            'statistics' => [
                'actions_recorded' => 8,
                'actions_completed' => 8,
                'browser_messages' => 0,
                'ballots_requested' => 2,
                'ballots_finalized' => 2,
                'ballots_printed' => 2,
                'ballots_accepted' => 2,
            ],
            'artifacts' => [
                [
                    'label' => 'Video',
                    'path' => $videoPath,
                    'relative_path' => "{$scenario}.webm",
                    'bytes' => filesize($videoPath),
                    'sha256' => hash_file('sha256', $videoPath),
                ],
                [
                    'label' => 'Playwright trace',
                    'path' => $tracePath,
                    'relative_path' => 'playwright-trace.zip',
                    'bytes' => filesize($tracePath),
                    'sha256' => hash_file('sha256', $tracePath),
                ],
                [
                    'label' => 'Walkthrough storyboard HTML',
                    'path' => $storyboardHtmlPath,
                    'relative_path' => 'walkthrough-storyboard.html',
                    'bytes' => filesize($storyboardHtmlPath),
                    'sha256' => hash_file('sha256', $storyboardHtmlPath),
                ],
                [
                    'label' => 'Walkthrough storyboard PDF',
                    'path' => $storyboardPdfPath,
                    'relative_path' => 'walkthrough-storyboard.pdf',
                    'bytes' => filesize($storyboardPdfPath),
                    'sha256' => hash_file('sha256', $storyboardPdfPath),
                ],
                [
                    'label' => 'Walkthrough storyboard data',
                    'path' => $storyboardJsonPath,
                    'relative_path' => 'walkthrough-storyboard.json',
                    'bytes' => filesize($storyboardJsonPath),
                    'sha256' => hash_file('sha256', $storyboardJsonPath),
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    });

    $this->artisan('election:browser-walkthrough', [
        'scenario' => 'public-simulation',
        '--ballots' => 2,
        '--slow-mo' => 0,
        '--base-url' => 'http://127.0.0.1:8000',
    ])
        ->expectsOutputToContain('Browser walkthrough passed.')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $rehearsal = $storage->currentRun(ElectionRunType::Rehearsal);
    $reportPath = $rehearsal['run_path'].'/12-audit-and-reconciliation/browser-recordings/browser-walkthrough-report.json';
    $report = json_decode(file_get_contents($reportPath), true, flags: JSON_THROW_ON_ERROR);
    $browserDirectory = dirname($reportPath);
    $browserIndex = json_decode(file_get_contents($browserDirectory.'/browser-artifact-index.json'), true, flags: JSON_THROW_ON_ERROR);
    $archiveReport = json_decode(file_get_contents($rehearsal['run_path'].'/12-audit-and-reconciliation/evidence-bundle-archive.json'), true, flags: JSON_THROW_ON_ERROR);
    $archiveContents = file_get_contents($archiveReport['archive_path']);

    expect($report['scenario'])->toBe('public-simulation')
        ->and($report['precinct_id'])->not->toBe('')
        ->and($report['context']['round_code'])->toStartWith('ROUND-')
        ->and($report['context']['precinct_code'])->toStartWith('TONDO-')
        ->and($report['statistics']['ballots_finalized'])->toBe(2)
        ->and(collect($browserIndex['artifacts'])->pluck('relative_path'))->toContain(
            '12-audit-and-reconciliation/browser-recordings/public-simulation.webm',
            '12-audit-and-reconciliation/browser-recordings/walkthrough-storyboard.pdf',
            '12-audit-and-reconciliation/browser-recordings/storyboard-frames/01-public-simulation-lobby.png',
        )
        ->and($archiveContents)->toContain('browser-recordings/public-simulation.webm')
        ->and($rehearsal['status'])->toBe('locked');

    Process::assertRan(function (PendingProcess $process): bool {
        return ($process->environment['ELECTION_WALKTHROUGH_SCENARIO'] ?? null) === 'public-simulation'
            && ($process->environment['ELECTION_WALKTHROUGH_PUBLIC_ROUND'] ?? '') !== ''
            && str_starts_with((string) ($process->environment['ELECTION_WALKTHROUGH_PUBLIC_PRECINCT'] ?? ''), 'TONDO-')
            && str_starts_with((string) ($process->environment['ELECTION_WALKTHROUGH_PUBLIC_OFFICER_CODE'] ?? ''), 'SIM-')
            && ($process->environment['ELECTION_WALKTHROUGH_PUBLIC_OFFICER_PIN'] ?? null) === '123456'
            && ! str_contains(implode(' ', $process->command), (string) $process->environment['ELECTION_WALKTHROUGH_PUBLIC_OFFICER_CODE']);
    });
});

test('browser walkthrough command records demo room role qr officer voter print watcher and handoff flow', function (): void {
    config()->set('election.public_simulation.enabled', true);

    Process::fake(function (PendingProcess $process) {
        $artifactDirectory = $process->environment['ELECTION_WALKTHROUGH_ARTIFACT_DIR'];
        $scenario = $process->environment['ELECTION_WALKTHROUGH_SCENARIO'];
        $videoPath = "{$artifactDirectory}/{$scenario}.webm";
        $tracePath = "{$artifactDirectory}/playwright-trace.zip";
        $storyboardPdfPath = "{$artifactDirectory}/walkthrough-storyboard.pdf";
        $storyboardFramePath = "{$artifactDirectory}/storyboard-frames/01-demo-room-lobby.png";

        file_put_contents($videoPath, 'recorded-demo-room-video');
        file_put_contents($tracePath, 'recorded-demo-room-trace');
        mkdir(dirname($storyboardFramePath), recursive: true);
        file_put_contents($storyboardFramePath, 'storyboard-frame');
        file_put_contents($storyboardPdfPath, '%PDF-1.4 demo room storyboard');

        return Process::result(output: json_encode([
            'schema_version' => 'browser-walkthrough-recorder-result-1',
            'passed' => true,
            'statistics' => [
                'actions_recorded' => 12,
                'actions_completed' => 12,
                'browser_messages' => 0,
                'ballots_requested' => 2,
                'ballots_finalized' => 2,
                'ballots_printed' => 2,
                'ballots_accepted' => 2,
                'handoff_completed' => true,
            ],
            'artifacts' => [
                [
                    'label' => 'Video',
                    'path' => $videoPath,
                    'relative_path' => "{$scenario}.webm",
                    'bytes' => filesize($videoPath),
                    'sha256' => hash_file('sha256', $videoPath),
                ],
                [
                    'label' => 'Walkthrough storyboard PDF',
                    'path' => $storyboardPdfPath,
                    'relative_path' => 'walkthrough-storyboard.pdf',
                    'bytes' => filesize($storyboardPdfPath),
                    'sha256' => hash_file('sha256', $storyboardPdfPath),
                ],
                [
                    'label' => 'Storyboard frame 01-demo-room-lobby',
                    'path' => $storyboardFramePath,
                    'relative_path' => 'storyboard-frames/01-demo-room-lobby.png',
                    'bytes' => filesize($storyboardFramePath),
                    'sha256' => hash_file('sha256', $storyboardFramePath),
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    });

    $this->artisan('election:browser-walkthrough', [
        'scenario' => 'demo-room',
        '--ballots' => 2,
        '--slow-mo' => 0,
        '--base-url' => 'http://127.0.0.1:8000',
    ])
        ->expectsOutputToContain('Browser walkthrough passed.')
        ->assertSuccessful();

    $storage = app(ElectionStorage::class);
    $rehearsal = $storage->currentRun(ElectionRunType::Rehearsal);
    $reportPath = $rehearsal['run_path'].'/12-audit-and-reconciliation/browser-recordings/browser-walkthrough-report.json';
    $report = json_decode(file_get_contents($reportPath), true, flags: JSON_THROW_ON_ERROR);
    $browserDirectory = dirname($reportPath);
    $browserIndex = json_decode(file_get_contents($browserDirectory.'/browser-artifact-index.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($report['scenario'])->toBe('demo-room')
        ->and($report['context']['round_code'])->toStartWith('ROUND-')
        ->and($report['context']['precinct_code'])->toStartWith('TONDO-')
        ->and($report['statistics']['handoff_completed'])->toBeTrue()
        ->and(collect($browserIndex['artifacts'])->pluck('relative_path'))->toContain(
            '12-audit-and-reconciliation/browser-recordings/demo-room.webm',
            '12-audit-and-reconciliation/browser-recordings/walkthrough-storyboard.pdf',
            '12-audit-and-reconciliation/browser-recordings/storyboard-frames/01-demo-room-lobby.png',
        );

    Process::assertRan(function (PendingProcess $process): bool {
        return ($process->environment['ELECTION_WALKTHROUGH_SCENARIO'] ?? null) === 'demo-room'
            && ($process->environment['ELECTION_WALKTHROUGH_PUBLIC_ROUND'] ?? '') !== ''
            && str_starts_with((string) ($process->environment['ELECTION_WALKTHROUGH_PUBLIC_PRECINCT'] ?? ''), 'TONDO-')
            && str_starts_with((string) ($process->environment['ELECTION_WALKTHROUGH_PUBLIC_OFFICER_CODE'] ?? ''), 'SIM-')
            && ($process->environment['ELECTION_WALKTHROUGH_PUBLIC_OFFICER_PIN'] ?? null) === '123456';
    });
});

test('browser walkthrough command preserves and locks failed recorder evidence', function (): void {
    Process::fake(function (PendingProcess $process) {
        $artifactDirectory = $process->environment['ELECTION_WALKTHROUGH_ARTIFACT_DIR'];

        file_put_contents($artifactDirectory.'/failure.png', 'failure-screenshot');

        return Process::result(
            output: json_encode([
                'schema_version' => 'browser-walkthrough-recorder-result-1',
                'passed' => false,
                'error' => 'The election home page did not load.',
                'statistics' => [
                    'actions_recorded' => 2,
                    'actions_completed' => 1,
                ],
                'artifacts' => [
                    [
                        'label' => 'Failure screenshot',
                        'path' => $artifactDirectory.'/failure.png',
                        'relative_path' => 'failure.png',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            errorOutput: 'walkthrough failed',
            exitCode: 1,
        );
    });

    $this->artisan('election:browser-walkthrough', [
        'scenario' => 'full-election',
        '--slow-mo' => 0,
        '--base-url' => 'http://localhost:8000',
    ])
        ->expectsOutputToContain('Browser walkthrough failed.')
        ->assertFailed();

    $storage = app(ElectionStorage::class);
    $rehearsal = $storage->currentRun(ElectionRunType::Rehearsal);
    $reportPath = $rehearsal['run_path'].'/12-audit-and-reconciliation/browser-recordings/browser-walkthrough-report.json';
    $report = json_decode(file_get_contents($reportPath), true, flags: JSON_THROW_ON_ERROR);
    $control = app(BrowserWalkthroughControl::class)->read();
    $completion = json_decode(file_get_contents(dirname($reportPath).'/browser-walkthrough-completion.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($rehearsal['status'])->toBe('locked')
        ->and($report['passed'])->toBeFalse()
        ->and($report['error'])->toBe('The election home page did not load.')
        ->and($report['process_error_output'])->toBe('walkthrough failed')
        ->and($control['status'])->toBe('failed')
        ->and($completion['recording_passed'])->toBeFalse()
        ->and($completion['post_recording']['archive_verified'])->toBeTrue()
        ->and($rehearsal['run_path'].'/artifact-index.json')->toBeFile()
        ->and($rehearsal['run_path'].'/12-audit-and-reconciliation/browser-recordings/failure.png')->toBeFile();
});

test('browser walkthrough command rejects remote targets and invalid limits', function (): void {
    Process::fake();

    $this->artisan('election:browser-walkthrough', [
        'scenario' => 'full-election',
        '--base-url' => 'https://example.com',
    ])->assertExitCode(2);

    $this->artisan('election:browser-walkthrough', [
        'scenario' => 'full-election',
        '--ballots' => 51,
    ])->assertExitCode(2);

    Process::assertNothingRan();
});

test('browser walkthrough recorder follows the fixed booth print station labels', function (): void {
    $recorder = file_get_contents(base_path('scripts/election-browser-walkthrough.mjs'));
    $printStation = file_get_contents(resource_path('js/pages/Election/PrintStation.vue'));

    expect($printStation)->toContain('Claim paper ballot')
        ->and($recorder)->toContain("'Claim paper ballot'")
        ->and($recorder)->not->toContain("'Prepare paper ballot'");
});

test('voter ballot includes position and candidate navigation aids', function (): void {
    $voterBallot = file_get_contents(resource_path('js/pages/Election/VoterBallot.vue'));
    $positionNavigation = file_get_contents(resource_path('js/components/election/BallotPositionNavigation.vue'));
    $alphabetNavigation = file_get_contents(resource_path('js/components/election/BallotAlphabetNavigation.vue'));
    $reviewButton = file_get_contents(resource_path('js/components/election/BallotReviewSummaryButton.vue'));
    $ballotNavigation = file_get_contents(resource_path('js/components/election/ballotNavigation.ts'));
    $paperFacsimile = file_get_contents(resource_path('js/components/election/PaperFacsimileBallot.vue'));

    expect($positionNavigation)->toContain('Jump to position')
        ->and($alphabetNavigation)->toContain('Stays here while browsing this position')
        ->and($reviewButton)->toContain('Review: {{ summary }}')
        ->and($voterBallot)->toContain('function jumpToCandidateLetter')
        ->and($voterBallot)->toContain('resolvedSelectionTarget')
        ->and($ballotNavigation)->toContain('Senator surname jump')
        ->and($ballotNavigation)->toContain('function candidateIndexKey')
        ->and($ballotNavigation)->toContain('function contestShortLabel')
        ->and($paperFacsimile)->toContain('Official ballot facsimile')
        ->and($paperFacsimile)->toContain('Vote for')
        ->and($paperFacsimile)->toContain("type SelectionTarget = 'circle' | 'circle_with_label' | 'row'")
        ->and($paperFacsimile)->toContain("selectionTarget === 'row'")
        ->and($paperFacsimile)->toContain("selectionTarget === 'circle_with_label'")
        ->and($paperFacsimile)->toContain('candidate-marker-')
        ->and($paperFacsimile)->toContain('function candidateColumns')
        ->and($paperFacsimile)->toContain('function contestColumnBorderClass')
        ->and($paperFacsimile)->toContain('columnIndex === 0 || columnIndex === 2')
        ->and($paperFacsimile)->toContain('md:border-r-2 md:border-stone-900')
        ->and($paperFacsimile)->toContain('grid-cols-1 md:grid-cols-2 xl:grid-cols-4')
        ->and($paperFacsimile)->toContain('rounded-full');
});

test('demo room officer action cards include aligned descriptions', function (): void {
    $officerPage = file_get_contents(resource_path('js/pages/Election/DemoRoomOfficer.vue'));

    expect($officerPage)->toContain('Start voting for this precinct after setup and')
        ->and($officerPage)->toContain('certification are complete.')
        ->and($officerPage)->toContain('Tally all deposited VVDAT records and generate');
});
