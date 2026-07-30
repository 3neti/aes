<?php

namespace App\Election\Counting;

use App\Election\Attestation\OfficerRegistry;
use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Election\Tabulation\DeviceTabulationLedger;
use App\Election\Tabulation\TabulationProfileResolver;
use Illuminate\Validation\ValidationException;

final class CountingReconciliationService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly OfficerRegistry $officers,
        private readonly TabulationProfileResolver $tabulation,
        private readonly DeviceTabulationLedger $deviceLedger,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function recordPhysicalCount(int $count, string $officerCode, string $pin): array
    {
        $officer = $this->officers->verify($officerCode, $pin);

        if ($officer === null) {
            throw ValidationException::withMessages(['officer_pin' => 'The officer code or PIN is invalid.']);
        }

        $record = [
            'schema_version' => 'physical-ballot-control-1',
            'recorded_at' => $this->clock->now()->toIso8601String(),
            'physical_ballots_removed_from_box' => $count,
            'officer_code_hash' => hash('sha256', $officer['code']),
            'officer_name' => $officer['name'],
            'officer_role' => $officer['role'],
        ];
        $record['control_hash'] = $this->json->hash($record);
        $record['artifact_path'] = $this->storage->path('counting/physical-ballot-control.json');
        $this->storage->writeJson('counting/physical-ballot-control.json', $record);
        $this->journal->record('counting.physical_control_recorded', [
            'physical_count' => $count,
            'control_hash' => $record['control_hash'],
        ]);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    public function adjudicate(int $sequence, string $disposition, string $reason, string $officerCode, string $pin): array
    {
        $officer = $this->officers->verify($officerCode, $pin);

        if ($officer === null) {
            throw ValidationException::withMessages(['officer_pin' => 'The officer code or PIN is invalid.']);
        }

        $rejected = collect($this->rejectedRecords())->firstWhere('sequence', $sequence);

        if (! is_array($rejected)) {
            throw ValidationException::withMessages(['sequence' => 'The rejected scan record was not found.']);
        }

        $record = [
            'schema_version' => 'counting-adjudication-1',
            'sequence' => $sequence,
            'adjudicated_at' => $this->clock->now()->toIso8601String(),
            'rejected_record_hash' => $rejected['raw_payload_hash'] ?? null,
            'disposition' => $disposition,
            'reason' => trim($reason),
            'officer_code_hash' => hash('sha256', $officer['code']),
            'officer_name' => $officer['name'],
        ];
        $record['adjudication_hash'] = $this->json->hash($record);
        $record['artifact_path'] = $this->storage->path("counting/adjudications/{$sequence}.json");
        $this->storage->writeJson("counting/adjudications/{$sequence}.json", $record);
        $this->journal->record('counting.scan_adjudicated', [
            'sequence' => $sequence,
            'disposition' => $disposition,
            'adjudication_hash' => $record['adjudication_hash'],
        ]);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $control = $this->storage->readJson('counting/physical-ballot-control.json');
        $profile = $this->tabulation->current();
        $accepted = count($this->storage->files('counting/accepted'));
        $rejected = $this->rejectedRecords();
        $adjudications = $this->adjudications();
        $excludedPaper = collect($adjudications)->where('disposition', 'excluded-paper-ballot')->count();
        $physical = $control['physical_ballots_removed_from_box'] ?? null;
        $deviceRecords = $this->deviceLedger->summary()['recorded_ballots'];
        $represented = $profile->routineScanningEnabled()
            ? $accepted + $excludedPaper
            : $deviceRecords;
        $unresolved = max(0, count($rejected) - count($adjudications));

        return [
            'schema_version' => 'counting-reconciliation-1',
            'physical_count_recorded' => $control !== [],
            'physical_ballots' => $physical,
            'accepted_ballots' => $profile->routineScanningEnabled() ? $accepted : $deviceRecords,
            'tabulation_profile' => $profile->value,
            'tally_source' => $profile->tallySource(),
            'device_tabulation_records' => $deviceRecords,
            'rejected_scans' => count($rejected),
            'adjudicated_rejections' => count($adjudications),
            'excluded_paper_ballots' => $excludedPaper,
            'represented_paper_ballots' => $represented,
            'unresolved_rejections' => $unresolved,
            'difference' => is_int($physical) ? $physical - $represented : null,
            'passed' => is_int($physical) && $physical === $represented
                && ($profile->routineScanningEnabled() ? $unresolved === 0 : true),
            'control_hash' => $control['control_hash'] ?? null,
            'adjudications' => $adjudications,
            'rejected_records' => $rejected,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rejectedRecords(): array
    {
        return collect($this->storage->files('counting/rejected'))
            ->map(fn (string $path): array => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function adjudications(): array
    {
        return collect($this->storage->files('counting/adjudications'))
            ->map(fn (string $path): array => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR))
            ->values()
            ->all();
    }
}
