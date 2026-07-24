<?php

namespace App\Election\Certification;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Counting\CountingService;
use App\Election\Preparation\ActivateConfiguredPrecinct;
use App\Election\Support\ElectionStorage;
use App\Election\Voting\BallotPayloadService;

final class CertificationService
{
    public function __construct(
        private readonly ActivateConfiguredPrecinct $activate,
        private readonly PackageIntegrityService $integrity,
        private readonly CertificationDeckBuilder $deckBuilder,
        private readonly BallotPayloadService $payloads,
        private readonly CountingService $counting,
        private readonly ElectionStorage $storage,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $configuration = $this->storage->readJson('runtime/active-precinct.json');

        if ($configuration === []) {
            $configuration = $this->activate->handle()['configuration'];
        }

        $integrity = $this->integrity->verify();

        if (! ($integrity['passed'] ?? false)) {
            $report = [
                'schema_version' => 'certification-report-1',
                'precinct_id' => $configuration['precinct_id'],
                'mapping_hash' => $configuration['mapping_hash'],
                'package_integrity_passed' => false,
                'package_integrity_report_hash' => $integrity['report_hash'] ?? null,
                'expected_tally' => [],
                'actual_tally' => [],
                'expected_ballots' => 0,
                'actual_ballots' => 0,
                'accepted_ballots' => 0,
                'rejected_ballots' => 0,
                'passed' => false,
            ];
            $report['report_hash'] = $this->json->hash($report);
            $this->storage->writeJson('certification/friday-certification-report.json', $report);
            $this->journal->record('certification.failed', [
                'reason' => 'package-integrity',
                'report_hash' => $report['report_hash'],
                'package_integrity_report_hash' => $integrity['report_hash'] ?? null,
            ]);

            return $report;
        }

        $deck = $this->deckBuilder->build($configuration);
        $this->clearCountingFiles();

        foreach ($deck['ballots'] as $ballot) {
            $payload = $this->payloads->finalize($ballot['selections'], $ballot['id'], false);
            $this->counting->accept($payload['qr_payload']);
        }

        $tally = $this->counting->tally();
        $passed = $tally['tally'] === $deck['expected_tally'];
        $report = [
            'schema_version' => 'certification-report-1',
            'precinct_id' => $configuration['precinct_id'],
            'mapping_hash' => $configuration['mapping_hash'],
            'package_integrity_passed' => true,
            'package_integrity_report_hash' => $integrity['report_hash'] ?? null,
            'expected_tally' => $deck['expected_tally'],
            'actual_tally' => $tally['tally'],
            'expected_ballots' => count($deck['ballots']),
            'actual_ballots' => $tally['accepted_ballots'],
            'accepted_ballots' => $tally['accepted_ballots'],
            'rejected_ballots' => $tally['rejected_ballots'],
            'passed' => $passed,
        ];
        $report['report_hash'] = $this->json->hash($report);

        $this->storage->writeJson('certification/friday-certification-report.json', $report);
        $this->clearCountingFiles();
        $this->journal->record($passed ? 'certification.passed' : 'certification.failed', [
            'report_hash' => $report['report_hash'],
        ]);

        return $report;
    }

    private function clearCountingFiles(): void
    {
        foreach (['counting/accepted', 'counting/rejected'] as $directory) {
            foreach ($this->storage->files($directory) as $path) {
                unlink($path);
            }
        }
    }
}
