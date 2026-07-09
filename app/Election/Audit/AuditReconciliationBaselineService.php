<?php

namespace App\Election\Audit;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Filesystem\Filesystem;

final class AuditReconciliationBaselineService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly Filesystem $files,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function write(): array
    {
        $runPath = $this->storage->activeRunPath();
        $runPathTail = basename($runPath);
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $precinct = (string) ($configuration['precinct_id'] ?? 'unknown');
        $journalEntries = $this->journal->entries();
        $journalSequence = 0;

        if ($journalEntries !== []) {
            $journalSequence = (int) ($journalEntries[array_key_last($journalEntries)]['sequence'] ?? 0);
        }

        $checks = [];
        $return = $this->storage->readJson("returns/{$precinct}-return.json");
        $copyDistribution = $this->storage->readJson("returns/{$precinct}-copy-distribution.json");
        $transmission = $this->storage->readJson('transmission/transmission-report.json');
        $package = $this->storage->readJson('transmission/delivery-package.json');
        $receipt = $this->storage->readJson('transmission/delivery-receipt.json');
        $finalBackup = $this->storage->readJson('transmission/final-backup-report.json');
        $custodyRecord = $this->storage->readJson('custody/custody-record.json');
        $custodyTurnover = $this->storage->readJson('custody/custody-turnover-report.json');
        $runSummary = $this->storage->readJson('run-summary.json');
        $runArtifactIndex = $this->storage->readJson('artifact-index.json');

        $this->addCheck($checks, 'election_return_generated', $return !== []);
        $this->addCheck($checks, 'copy_distribution_generated', $copyDistribution !== []);
        $this->addCheck($checks, 'transmission_generated', $transmission !== []);
        $this->addCheck($checks, 'delivery_package_generated', $package !== []);
        $this->addCheck($checks, 'delivery_receipt_generated', $receipt !== []);
        $this->addCheck($checks, 'final_backup_generated', $finalBackup !== []);
        $this->addCheck($checks, 'custody_record_generated', $custodyRecord !== []);
        $this->addCheck($checks, 'custody_turnover_generated', $custodyTurnover !== []);

        $this->addCheck(
            $checks,
            'return_matches_distribution',
            $return !== [] && $copyDistribution !== [] && (string) ($return['return_hash'] ?? null) === (string) ($copyDistribution['return_hash'] ?? null),
            (string) ($return['return_hash'] ?? ''),
            (string) ($copyDistribution['return_hash'] ?? ''),
        );

        $this->addCheck(
            $checks,
            'package_matches_transmission',
            $package !== [] && $transmission !== [] && (string) ($package['transmission']['transmission_hash'] ?? null) === (string) ($transmission['transmission_hash'] ?? null),
            (string) ($package['transmission']['transmission_hash'] ?? ''),
            (string) ($transmission['transmission_hash'] ?? ''),
        );

        $this->addCheck(
            $checks,
            'receipt_matches_transmission',
            $receipt !== [] && $transmission !== [] && (string) ($receipt['transmission_id'] ?? null) === (string) ($transmission['transmission_id'] ?? null),
            (string) ($receipt['transmission_id'] ?? ''),
            (string) ($transmission['transmission_id'] ?? ''),
        );

        $this->addCheck(
            $checks,
            'backup_matches_receipt',
            $finalBackup !== [] && $receipt !== [] && (string) ($finalBackup['delivery_receipt_id'] ?? null) === (string) ($receipt['delivery_receipt_id'] ?? null),
            (string) ($finalBackup['delivery_receipt_id'] ?? ''),
            (string) ($receipt['delivery_receipt_id'] ?? ''),
        );

        $this->addCheck(
            $checks,
            'backup_matches_package',
            $finalBackup !== [] && $package !== [] && (string) ($finalBackup['delivery_package_id'] ?? null) === (string) ($package['package_id'] ?? null),
            (string) ($finalBackup['delivery_package_id'] ?? ''),
            (string) ($package['package_id'] ?? ''),
        );

        $this->addCheck(
            $checks,
            'custody_matches_backup',
            $custodyRecord !== [] && $finalBackup !== [] && (string) ($custodyRecord['final_backup_id'] ?? null) === (string) ($finalBackup['backup_id'] ?? null),
            (string) ($custodyRecord['final_backup_id'] ?? ''),
            (string) ($finalBackup['backup_id'] ?? ''),
        );

        $this->addCheck(
            $checks,
            'custody_turnover_matches_record',
            $custodyTurnover !== [] && $custodyRecord !== [] && (string) ($custodyTurnover['custody_id'] ?? null) === (string) ($custodyRecord['custody_id'] ?? null),
            (string) ($custodyTurnover['custody_id'] ?? ''),
            (string) ($custodyRecord['custody_id'] ?? ''),
        );

        $returnDigest = $this->artifactDigest('returns/'.$precinct.'-return.json');
        $copyDistributionDigest = $this->artifactDigest("returns/{$precinct}-copy-distribution.json");
        $transmissionDigest = $this->artifactDigest('transmission/transmission-report.json');
        $packageDigest = $this->artifactDigest('transmission/delivery-package.json');
        $receiptDigest = $this->artifactDigest('transmission/delivery-receipt.json');
        $finalBackupDigest = $this->artifactDigest('transmission/final-backup-report.json');
        $custodyRecordDigest = $this->artifactDigest('custody/custody-record.json');
        $custodyTurnoverDigest = $this->artifactDigest('custody/custody-turnover-report.json');

        $artifactCatalog = [
            'return' => $returnDigest,
            'copy_distribution' => $copyDistributionDigest,
            'transmission' => $transmissionDigest,
            'delivery_package' => $packageDigest,
            'delivery_receipt' => $receiptDigest,
            'final_backup' => $finalBackupDigest,
            'custody_record' => $custodyRecordDigest,
            'custody_turnover' => $custodyTurnoverDigest,
        ];

        $passedChecks = collect($checks)->filter(fn (array $check): bool => (bool) $check['passed'])->count();
        $report = [
            'schema_version' => 'audit-reconciliation-baseline-1',
            'reconciliation_profile' => 'post-custody-v1',
            'generated_at' => $this->clock->now()->toIso8601String(),
            'run_id' => $runPathTail,
            'precinct_id' => $precinct,
            'run_path' => $runPath,
            'run_summary_hash' => $runSummary['run_hash'] ?? null,
            'run_artifact_index_hash' => $runArtifactIndex['artifact_index_hash'] ?? null,
            'source_journal_event_count' => count($journalEntries),
            'journal_sequence' => $journalSequence,
            'artifacts_found' => collect($artifactCatalog)->filter(fn (array $artifact): bool => (bool) $artifact['found'])->count(),
            'artifacts_expected' => count($artifactCatalog),
            'checks' => collect($checks)->values()->all(),
            'checks_total' => count($checks),
            'checks_passed' => $passedChecks,
            'reconciliation_complete' => $checks !== [] && $passedChecks === count($checks),
            'reconciliation_ready' => $checks !== [] && $passedChecks === count($checks),
            'artifacts' => $artifactCatalog,
            'artifact_catalog_count' => count($artifactCatalog),
        ];

        $report['audit_reconciliation_hash'] = $this->json->hash($report);
        $report['artifact_path'] = $this->storage->writeJson('diagnostics/audit-reconciliation-baseline.json', $report);

        $this->journal->record('audit_reconciliation_baseline.generated', [
            'run_id' => $runPathTail,
            'precinct_id' => $precinct,
            'checks_total' => $report['checks_total'],
            'checks_passed' => $report['checks_passed'],
            'reconciliation_complete' => $report['reconciliation_complete'],
            'audit_reconciliation_hash' => $report['audit_reconciliation_hash'],
        ]);

        return $report;
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     */
    private function addCheck(array &$checks, string $name, bool $passed, ?string $expected = null, ?string $actual = null): void
    {
        $checks[] = [
            'check_name' => $name,
            'passed' => $passed,
            'expected' => $expected,
            'actual' => $actual,
        ];
    }

    /**
     * @return array{relative_path: string, found: bool, bytes: int, sha256: string|null}
     */
    private function artifactDigest(string $relativePath): array
    {
        $path = $this->storage->path($relativePath);

        if (! $this->files->exists($path)) {
            return [
                'relative_path' => $relativePath,
                'found' => false,
                'bytes' => 0,
                'sha256' => null,
            ];
        }

        return [
            'relative_path' => $relativePath,
            'found' => true,
            'bytes' => (int) $this->files->size($path),
            'sha256' => (string) hash_file('sha256', $path),
        ];
    }
}
