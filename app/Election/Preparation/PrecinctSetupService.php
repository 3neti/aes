<?php

namespace App\Election\Preparation;

use App\Election\Attestation\OfficerRegistry;
use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Validation\ValidationException;

final class PrecinctSetupService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
        private readonly OfficerRegistry $officers,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function record(array $data): array
    {
        $chairperson = $this->verifyOfficer((string) $data['chairperson_code'], (string) $data['chairperson_pin'], 'Election Board Chairperson', 'chairperson_pin');
        $pollClerk = $this->verifyOfficer((string) $data['poll_clerk_code'], (string) $data['poll_clerk_pin'], 'Poll Clerk', 'poll_clerk_pin');
        $thirdMember = collect($this->officers->all())->firstWhere('code', (string) $data['third_member_code']);

        if (! is_array($thirdMember) || ($thirdMember['role'] ?? null) !== 'Third Member') {
            throw ValidationException::withMessages(['third_member_code' => 'Select the configured Third Member.']);
        }

        $seals = collect(explode(',', (string) $data['seal_numbers']))
            ->map(fn (string $seal): string => trim($seal))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (count($seals) < 2) {
            throw ValidationException::withMessages(['seal_numbers' => 'Record at least two distinct seal numbers.']);
        }

        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $run = $this->storage->currentRun();
        $report = [
            'schema_version' => 'precinct-setup-record-1',
            'run_id' => $run['run_id'] ?? null,
            'precinct_id' => $configuration['precinct_id'] ?? null,
            'recorded_at' => $this->clock->now()->toIso8601String(),
            'dual_control' => [
                'passed' => $chairperson['code'] !== $pollClerk['code'],
                'approvers' => [$this->officerEvidence($chairperson), $this->officerEvidence($pollClerk)],
            ],
            'electoral_board' => [
                $this->officerEvidence($chairperson),
                $this->officerEvidence($pollClerk),
                $this->officerEvidence($thirdMember),
            ],
            'inventory' => [
                'device_serial' => trim((string) $data['device_serial']),
                'printer_serial' => trim((string) $data['printer_serial']),
                'scanner_serial' => trim((string) $data['scanner_serial']),
                'ballot_stock_start' => (int) $data['ballot_stock_start'],
                'ballot_stock_end' => (int) $data['ballot_stock_end'],
                'ballot_stock_count' => (int) $data['ballot_stock_end'] - (int) $data['ballot_stock_start'] + 1,
                'ballot_box_id' => trim((string) $data['ballot_box_id']),
                'custody_envelope_id' => trim((string) $data['custody_envelope_id']),
                'seal_numbers' => $seals,
            ],
            'passed' => true,
        ];
        $report['setup_hash'] = $this->json->hash($report);
        $report['artifact_path'] = $this->storage->path('runtime/precinct-setup.json');
        $this->storage->writeJson('runtime/precinct-setup.json', $report);

        $this->journal->record('precinct.setup_recorded', [
            'run_id' => $report['run_id'],
            'precinct_id' => $report['precinct_id'],
            'setup_hash' => $report['setup_hash'],
            'ballot_stock_count' => $report['inventory']['ballot_stock_count'],
            'seal_count' => count($seals),
        ]);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return $this->storage->readJson('runtime/precinct-setup.json');
    }

    /**
     * @return array<string, string>
     */
    private function verifyOfficer(string $code, string $pin, string $role, string $errorKey): array
    {
        $officer = $this->officers->verify($code, $pin);

        if ($officer === null || $officer['role'] !== $role) {
            throw ValidationException::withMessages([$errorKey => "The {$role} code or PIN is invalid."]);
        }

        return $officer;
    }

    /**
     * @param  array<string, mixed>  $officer
     * @return array<string, string>
     */
    private function officerEvidence(array $officer): array
    {
        return [
            'code_hash' => hash('sha256', (string) $officer['code']),
            'name' => (string) $officer['name'],
            'role' => (string) $officer['role'],
        ];
    }
}
