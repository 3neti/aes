<?php

use App\Election\Counting\CountingService;
use App\Election\Preparation\ActivateSamplePackage;
use App\Election\Printing\BallotPrinter;
use App\Election\Printing\Documents\ElectionReturnPdf;
use App\Election\Printing\Documents\OfficialBallotPdf;
use App\Election\Printing\Documents\TallySheetPdf;
use App\Election\Printing\PrintFormProfile;
use App\Election\Returns\ElectionReturnService;
use App\Election\Support\ElectionStorage;
use App\Election\Support\SimplePdf;
use App\Election\Tabulation\TabulationProfile;
use App\Election\Voting\BallotPayloadService;
use App\Election\Voting\StandardQrCode;

beforeEach(function (): void {
    config()->set('election.tabulation.profile', TabulationProfile::PaperFirst->value);
    config()->set('election.devices.printer.driver', 'file');
    config()->set('election.voter.ballot_ui_profile', 'touch_guided');
    app(ElectionStorage::class)->reset();
});

test('printed ballot embeds its qr image and every voter selection', function (): void {
    app(ActivateSamplePackage::class)->handle();
    $payload = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
        'council' => ['council-ana', 'council-cora'],
    ], 'print-form-ballot');
    $job = app(BallotPrinter::class)->print($payload);
    $pdf = file_get_contents($job['pdf_artifact_path']);
    $qr = new Imagick($payload['qr_artifact_path']);

    expect($pdf)
        ->toContain('/Subtype /Image')
        ->toContain('/BallotQr')
        ->toContain('/Interpolate false')
        ->toContain('1. Ada Santos')
        ->toContain('2. Lina Mercado')
        ->toContain('1. Ana Lopez; 3. Cora Ramos')
        ->toContain('BALLOT QR VERIFICATION COPY')
        ->toContain('SCAN THIS LARGE QR FOR AUDIT VERIFICATION')
        ->not->toContain('QR Artifact:')
        ->and(pdfPageCount($pdf))->toBe(2)
        ->and($qr->getImageWidth())->toBeGreaterThanOrEqual(740)
        ->and($qr->getImageHeight())->toBeGreaterThanOrEqual(740);

    $qr->clear();
    $qr->destroy();
});

test('print-form profiles render A4 and thermal evidence from the same sealed records', function (): void {
    app(ActivateSamplePackage::class)->handle();
    $payload = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
        'council' => ['council-ana', 'council-cora'],
    ], 'thermal-print-form-ballot');
    $job = app(BallotPrinter::class)->print($payload, PrintFormProfile::Thermal80);
    $storage = app(ElectionStorage::class);

    expect($job['print_form_profile'])->toBe('thermal-80')
        ->and($job['selected_pdf_artifact_path'])->toEndWith('thermal-80.pdf')
        ->and(file_get_contents($job['selected_pdf_artifact_path']))->toContain('/MediaBox [0 0 226.77 792]')
        ->and($job['form_artifacts'])->toHaveKeys(['a4', 'thermal-80'])
        ->and($storage->readJson('print-forms/ballots/thermal-print-form-ballot/manifest.json')['source_hash'])
        ->toBe($payload['payload_hash']);

    app(CountingService::class)->accept($payload['qr_payload']);
    $tally = app(CountingService::class)->tally();
    $return = app(ElectionReturnService::class)->generate($tally);

    expect($storage->readJson('print-forms/tally-sheet/manifest.json')['source_hash'])->toBe($tally['tally_hash'])
        ->and($storage->readJson('print-forms/election-return/0421-A/manifest.json')['source_hash'])->toBe($return['return_hash'])
        ->and($storage->readText('print-forms/tally-sheet/thermal-58.pdf'))->toContain('/MediaBox [0 0 164.41 792]')
        ->and($storage->readText('print-forms/election-return/0421-A/thermal-80.pdf'))->toContain('Roll segment 1 of');
});

test('printed ballot reserves space beside long contest titles for selection limits', function (): void {
    app(ActivateSamplePackage::class)->handle();
    $payload = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-ada'],
    ], 'long-contest-title-ballot');
    $contestTitle = 'MEMBER, HOUSE OF REPRESENTATIVES - NCR - CITY OF MANILA - FIRST LEGDIST - FIRST DISTRICT';
    $pdf = app(OfficialBallotPdf::class)->render($payload, [
        'contests' => [
            [
                'id' => 'president',
                'title' => $contestTitle,
                'max_selections' => 1,
                'candidates' => [
                    [
                        'id' => 'pres-ada',
                        'ballot_number' => 1,
                        'name' => 'Ada Santos',
                    ],
                ],
            ],
        ],
    ]);

    expect($pdf)
        ->not->toContain($contestTitle)
        ->toContain('MEMBER, HOUSE OF REPRESENTATIVES')
        ->toContain('FIRST DISTRICT')
        ->and(substr_count($pdf, '1 selected | maximum 1'))->toBe(1);
});

test('official facsimile ballot pdf renders the complete candidate face and qr verification copy', function (): void {
    config()->set('election.voter.ballot_ui_profile', 'comelec_2022_facsimile');
    $payload = [
        'ballot_id' => 'facsimile-test-ballot',
        'election_id' => 'MAY-9-2022-NLE-MANILA-FACSIMILE-DEMO',
        'precinct_id' => '39010402',
        'ballot_style_id' => 'BS-2025NLE-39010402',
        'paper_ballot_serial' => '39010402-DEMO-000001',
        'qr_artifact_path' => sampleQrPath(),
        'qr_payload' => 'aes-ballot-compact-1:fixture',
        'payload_hash' => str_repeat('a', 64),
        'mapping_hash' => str_repeat('b', 64),
        'selections' => [
            'president' => ['president-1'],
            'senator' => ['senator-1'],
        ],
    ];
    $configuration = [
        'contests' => [
            [
                'id' => 'president',
                'office' => 'PRESIDENT',
                'title' => 'PRESIDENT - PHILIPPINES',
                'max_selections' => 1,
                'candidates' => [
                    ['id' => 'president-1', 'ballot_number' => 1, 'name' => 'ABELLA, ERNIE'],
                    ['id' => 'president-2', 'ballot_number' => 2, 'name' => 'DE GUZMAN, LEODY'],
                ],
            ],
            [
                'id' => 'senator',
                'office' => 'SENATOR',
                'title' => 'SENATOR - PHILIPPINES',
                'max_selections' => 12,
                'candidates' => collect(range(1, 64))
                    ->map(fn (int $number): array => [
                        'id' => "senator-{$number}",
                        'ballot_number' => $number,
                        'name' => sprintf('SENATORIAL CANDIDATE %02d', $number),
                    ])
                    ->all(),
            ],
            [
                'id' => 'party_list',
                'office' => 'PARTY LIST',
                'title' => 'PARTY LIST - PHILIPPINES',
                'max_selections' => 1,
                'candidates' => [
                    ['id' => 'party-list-1', 'ballot_number' => 1, 'name' => 'KAMALAYAN'],
                ],
            ],
        ],
    ];

    $pdf = app(OfficialBallotPdf::class)->render($payload, $configuration);

    expect($pdf)
        ->toContain('COMELEC-Style Simulation Ballot')
        ->toContain('PRESIDENT / Vote for 1')
        ->toContain('SENATOR / Vote for 12')
        ->toContain('SENATORIAL CANDIDATE 64')
        ->toContain('PARTY LIST / Vote for 1')
        ->toContain('KAMALAYAN')
        ->toContain('BALLOT QR VERIFICATION COPY')
        ->toContain(' c f')
        ->toContain(' c S')
        ->not->toContain('(X) Tj')
        ->and(pdfPageCount($pdf))->toBeGreaterThanOrEqual(3);
});

test('tally sheet hides zero vote candidates while election return keeps complete listings', function (): void {
    [$configuration, $tally] = largePrintFormFixture();
    $tallyPdf = app(TallySheetPdf::class)->render($configuration, $tally);
    $return = [
        'election_id' => $configuration['election_id'],
        'precinct_id' => $configuration['precinct_id'],
        'accepted_ballots' => $tally['accepted_ballots'],
        'rejected_ballots' => $tally['rejected_ballots'],
        'tally' => $tally['tally'],
        'tally_hash' => $tally['tally_hash'],
        'return_hash' => str_repeat('b', 64),
    ];
    $returnPdf = app(ElectionReturnPdf::class)->render($configuration, $return);

    expect(pdfPageCount($tallyPdf))->toBeGreaterThan(2)
        ->and(pdfPageCount($returnPdf))->toBeGreaterThan(2)
        ->and(substr_count($tallyPdf, 'COMMISSION ON ELECTIONS'))->toBe(pdfPageCount($tallyPdf))
        ->and(substr_count($returnPdf, 'COMMISSION ON ELECTIONS'))->toBe(pdfPageCount($returnPdf))
        ->and($tallyPdf)->toContain('CANDIDATE 001')
        ->and($tallyPdf)->not->toContain('CANDIDATE 007')
        ->and($tallyPdf)->not->toContain('CANDIDATE 098')
        ->and($tallyPdf)->toContain('TALLY MARKS')
        ->and($tallyPdf)->toContain('% AES-TALLY-MARKS count=6 groups=1 remainder=1')
        ->and($returnPdf)->toContain('CANDIDATE 001')
        ->and($returnPdf)->toContain('CANDIDATE 098')
        ->and($returnPdf)->toContain('TALLY MARKS')
        ->and($returnPdf)->toContain('% AES-TALLY-MARKS count=6 groups=1 remainder=1')
        ->and($returnPdf)->toContain('ELECTORAL BOARD CERTIFICATION')
        ->and($tallyPdf)->toBe(app(TallySheetPdf::class)->render($configuration, $tally))
        ->and($returnPdf)->toBe(app(ElectionReturnPdf::class)->render($configuration, $return));

    foreach (range(1, 98) as $number) {
        $candidate = sprintf('CANDIDATE %03d', $number);

        expect(substr_count($tallyPdf, $candidate))->toBe($number % 7 === 0 ? 0 : 1)
            ->and(substr_count($returnPdf, $candidate))->toBe(1);
    }
});

test('supporting evidence pdf paginates without dropping lines', function (): void {
    $lines = collect(range(1, 140))
        ->map(fn (int $line): string => sprintf('Evidence line %03d', $line))
        ->all();
    $pdf = app(SimplePdf::class)->render('Long Evidence Report', $lines);

    expect(pdfPageCount($pdf))->toBeGreaterThan(2)
        ->and($pdf)->toContain('Evidence line 001')
        ->and($pdf)->toContain('Evidence line 140')
        ->and(substr_count($pdf, 'Long Evidence Report'))->toBe(pdfPageCount($pdf));
});

test('lifecycle tally and return retain complete activated configuration order', function (): void {
    app(ActivateSamplePackage::class)->handle();
    $payload = app(BallotPayloadService::class)->finalize([
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
        'council' => ['council-cora'],
    ], 'print-form-lifecycle');
    app(CountingService::class)->accept($payload['qr_payload']);
    $tally = app(CountingService::class)->tally();
    app(ElectionReturnService::class)->generate($tally);
    $storage = app(ElectionStorage::class);
    $tallyPdf = file_get_contents($storage->path('runtime/tally-sheet.pdf'));
    $returnPdf = file_get_contents($storage->path('returns/0421-A-return.pdf'));

    expect($tally['tally']['president']['pres-grace'])->toBe(0)
        ->and($tally['display_tally']['president'])->toHaveKey('pres-ada')
        ->and($tally['display_tally']['president'])->not->toHaveKey('pres-grace')
        ->and($tallyPdf)->toContain('Ada Santos')
        ->and($tallyPdf)->not->toContain('Grace Reyes')
        ->and($tallyPdf)->toContain('Cora Ramos')
        ->and($returnPdf)->toContain('Ada Santos')
        ->and($returnPdf)->toContain('Grace Reyes')
        ->and($returnPdf)->toContain('Cora Ramos')
        ->and($returnPdf)->toContain('Page 1 of 2');
});

function pdfPageCount(string $pdf): int
{
    preg_match_all('/\/Type \/Page\b/', $pdf, $matches);

    return count($matches[0]);
}

function sampleQrPath(): string
{
    $path = storage_path('framework/testing/sample-ballot-qr.png');
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }

    file_put_contents($path, app(StandardQrCode::class)->renderPng('aes-ballot-compact-1:fixture'));

    return $path;
}

/**
 * @return array{array<string, mixed>, array<string, mixed>}
 */
function largePrintFormFixture(): array
{
    $firstContestCandidates = collect(range(1, 70))
        ->map(fn (int $number): array => [
            'id' => "national-{$number}",
            'ordinal' => $number,
            'ballot_number' => $number,
            'name' => sprintf('CANDIDATE %03d', $number),
            'party' => $number % 2 === 0 ? 'PARTY A' : 'INDEPENDENT',
        ])
        ->all();
    $secondContestCandidates = collect(range(71, 98))
        ->map(fn (int $number): array => [
            'id' => "local-{$number}",
            'ordinal' => $number - 70,
            'ballot_number' => $number - 70,
            'name' => sprintf('CANDIDATE %03d', $number),
            'party' => 'LOCAL PARTY',
        ])
        ->all();
    $configuration = [
        'election_id' => 'PRINT-FORM-TEST',
        'precinct_id' => '39010001',
        'ballot_style_id' => 'BS-PRINT-FORM',
        'contests' => [
            [
                'id' => 'national',
                'title' => 'SENATOR - PHILIPPINES',
                'max_selections' => 12,
                'candidates' => $firstContestCandidates,
            ],
            [
                'id' => 'local',
                'title' => 'COUNCILOR - CITY OF MANILA - FIRST DISTRICT',
                'max_selections' => 6,
                'candidates' => $secondContestCandidates,
            ],
        ],
    ];
    $tallyRows = [];

    foreach ($configuration['contests'] as $contest) {
        $tallyRows[$contest['id']] = collect($contest['candidates'])
            ->mapWithKeys(fn (array $candidate): array => [
                $candidate['id'] => ((int) $candidate['ordinal']) % 7,
            ])
            ->all();
    }

    return [
        $configuration,
        [
            'accepted_ballots' => 12,
            'rejected_ballots' => 1,
            'tally_hash' => str_repeat('a', 64),
            'tally' => $tallyRows,
        ],
    ];
}
