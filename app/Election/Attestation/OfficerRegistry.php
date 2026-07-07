<?php

namespace App\Election\Attestation;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use Illuminate\Validation\ValidationException;

final class OfficerRegistry
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ElectionClock $clock,
        private readonly CanonicalJson $json,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @return array<string, string>|null
     */
    public function verify(string $officerCode, string $pin): ?array
    {
        $officerCode = trim($officerCode);
        $pinHash = hash('sha256', trim($pin));

        foreach ($this->officers() as $officer) {
            if (($officer['code'] ?? null) !== $officerCode) {
                continue;
            }

            if (! hash_equals((string) ($officer['pin_hash'] ?? ''), $pinHash)) {
                return null;
            }

            return [
                'code' => (string) $officer['code'],
                'name' => (string) $officer['name'],
                'role' => (string) $officer['role'],
            ];
        }

        return null;
    }

    /**
     * @return array<int, array{code_hash: string, name: string, role: string}>
     */
    public function summaries(): array
    {
        return collect($this->officers())
            ->map(fn (array $officer): array => [
                'code_hash' => hash('sha256', (string) $officer['code']),
                'name' => (string) $officer['name'],
                'role' => (string) $officer['role'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function rotatePin(string $officerCode, string $currentPin, string $newPin): array
    {
        if (strlen(trim($newPin)) < 6) {
            throw ValidationException::withMessages([
                'new_pin' => 'The new officer PIN must be at least 6 digits.',
            ]);
        }

        $officer = $this->verify($officerCode, $currentPin);

        if ($officer === null) {
            throw ValidationException::withMessages([
                'current_pin' => 'The officer code or current PIN is invalid.',
            ]);
        }

        $officers = collect($this->officers())
            ->map(function (array $record) use ($officerCode, $newPin): array {
                if (($record['code'] ?? null) !== trim($officerCode)) {
                    return $record;
                }

                return [
                    ...$record,
                    'pin_hash' => hash('sha256', trim($newPin)),
                ];
            })
            ->values()
            ->all();

        $registry = [
            'schema_version' => 'officer-registry-1',
            'updated_at' => $this->clock->now()->toIso8601String(),
            'officers' => $officers,
        ];
        $registry['registry_hash'] = $this->json->hash($registry);
        $registry['artifact_path'] = $this->storage->writeJson('runtime/officer-registry.json', $registry);

        $event = [
            'code_hash' => hash('sha256', trim($officerCode)),
            'name' => $officer['name'],
            'registry_hash' => $registry['registry_hash'],
            'rotated_at' => $registry['updated_at'],
        ];

        $this->journal->record('officer.pin_rotated', $event);

        return [
            ...$event,
            'artifact_path' => $registry['artifact_path'],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function officers(): array
    {
        if (file_exists($this->storage->path('runtime/officer-registry.json'))) {
            return $this->storage->readJson('runtime/officer-registry.json')['officers'] ?? [];
        }

        return config('election.officers', []);
    }
}
