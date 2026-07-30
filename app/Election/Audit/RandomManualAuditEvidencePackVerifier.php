<?php

namespace App\Election\Audit;

use App\Election\Core\CanonicalJson;
use JsonException;

final class RandomManualAuditEvidencePackVerifier
{
    public function __construct(private readonly CanonicalJson $json) {}

    /**
     * @return array<string, mixed>
     */
    public function verify(string $contents): array
    {
        try {
            $pack = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->failed(['The uploaded file is not valid JSON.']);
        }

        if (! is_array($pack)) {
            return $this->failed(['The uploaded JSON pack must contain an object.']);
        }

        $errors = [];
        $sample = $pack['sample_selection'] ?? null;
        $reconciliation = $pack['reconciliation_report'] ?? null;
        $approved = $pack['approved_paper_comparisons'] ?? null;
        $discrepancies = $pack['paper_discrepancies'] ?? null;

        if (($pack['schema_version'] ?? null) !== 'random-manual-audit-evidence-pack-1') {
            $errors[] = 'The evidence pack schema version is not recognized.';
        }

        if (! is_array($sample) || ! is_array($reconciliation) || ! is_array($approved) || ! is_array($discrepancies)) {
            return $this->failed([...$errors, 'The evidence pack is missing one or more required audit records.']);
        }

        if (($pack['evidence_pack_hash'] ?? null) !== $this->hashWithout($pack, ['evidence_pack_hash'])) {
            $errors[] = 'The evidence pack hash does not match its contents.';
        }

        if (($sample['sample_hash'] ?? null) !== $this->hashWithout($sample, ['sample_hash', 'artifact_path'])) {
            $errors[] = 'The embedded audit sample hash does not match its contents.';
        }

        if (($reconciliation['report_hash'] ?? null) !== $this->hashWithout($reconciliation, ['report_hash', 'artifact_path'])) {
            $errors[] = 'The embedded reconciliation report hash does not match its contents.';
        }

        foreach ($approved as $record) {
            if (! is_array($record) || ($record['record_hash'] ?? null) !== $this->hashWithout($record, ['record_hash', 'artifact_path'])) {
                $errors[] = 'An approved paper-comparison record hash does not match its contents.';
            }
        }

        foreach ($discrepancies as $record) {
            if (! is_array($record) || ($record['record_hash'] ?? null) !== $this->hashWithout($record, ['record_hash', 'artifact_path'])) {
                $errors[] = 'A paper-discrepancy record hash does not match its contents.';
            }
        }

        $selectedHashes = collect($sample['selected_ballots'] ?? [])
            ->pluck('payload_hash')
            ->filter(fn (mixed $hash): bool => is_string($hash))
            ->values()
            ->all();
        $entries = is_array($reconciliation['entries'] ?? null) ? $reconciliation['entries'] : [];
        $entryHashes = collect($entries)
            ->pluck('payload_hash')
            ->filter(fn (mixed $hash): bool => is_string($hash))
            ->values()
            ->all();

        if (count($selectedHashes) !== (int) ($sample['sample_size'] ?? -1)) {
            $errors[] = 'The sample size does not match its selected ballot list.';
        }

        sort($selectedHashes);
        sort($entryHashes);

        if ($selectedHashes !== $entryHashes) {
            $errors[] = 'The reconciliation entries do not match the frozen audit sample.';
        }

        $statusCounts = collect($entries)->countBy('status')->all();
        $expectedVerified = $statusCounts['verified'] ?? 0;
        $expectedDiscrepancies = $statusCounts['paper-discrepancy-recorded'] ?? 0;
        $expectedPending = $statusCounts['pending-paper-comparison'] ?? 0;
        $expectedDeviceIssues = ($statusCounts['device-record-missing'] ?? 0) + ($statusCounts['device-record-selection-mismatch'] ?? 0);

        if ((int) ($reconciliation['verified_ballots'] ?? -1) !== $expectedVerified
            || (int) ($reconciliation['discrepancy_ballots'] ?? -1) !== $expectedDiscrepancies
            || (int) ($reconciliation['pending_ballots'] ?? -1) !== $expectedPending
            || (int) ($reconciliation['device_record_issues'] ?? -1) !== $expectedDeviceIssues) {
            $errors[] = 'The reconciliation summary counts do not match its entries.';
        }

        $expectedPassed = count($entries) === count($selectedHashes)
            && $expectedVerified === count($selectedHashes);

        if ((bool) ($reconciliation['passed'] ?? false) !== $expectedPassed) {
            $errors[] = 'The reconciliation pass status does not match its entries.';
        }

        return [
            'schema_version' => 'random-manual-audit-evidence-pack-verification-1',
            'passed' => $errors === [],
            'errors' => $errors,
            'evidence_pack_hash' => $pack['evidence_pack_hash'] ?? null,
            'sample_hash' => $sample['sample_hash'] ?? null,
            'reconciliation_report_hash' => $reconciliation['report_hash'] ?? null,
            'sample_size' => $sample['sample_size'] ?? null,
            'verified_ballots' => $expectedVerified,
            'discrepancy_ballots' => $expectedDiscrepancies,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<int, string>  $keys
     */
    private function hashWithout(array $record, array $keys): string
    {
        return $this->json->hash(array_diff_key($record, array_fill_keys($keys, true)));
    }

    /**
     * @param  array<int, string>  $errors
     * @return array<string, mixed>
     */
    private function failed(array $errors): array
    {
        return [
            'schema_version' => 'random-manual-audit-evidence-pack-verification-1',
            'passed' => false,
            'errors' => $errors,
            'evidence_pack_hash' => null,
            'sample_hash' => null,
            'reconciliation_report_hash' => null,
            'sample_size' => null,
            'verified_ballots' => 0,
            'discrepancy_ballots' => 0,
        ];
    }
}
