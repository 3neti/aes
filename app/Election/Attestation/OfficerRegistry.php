<?php

namespace App\Election\Attestation;

final class OfficerRegistry
{
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
     * @return array<int, array<string, string>>
     */
    private function officers(): array
    {
        return config('election.officers', []);
    }
}
