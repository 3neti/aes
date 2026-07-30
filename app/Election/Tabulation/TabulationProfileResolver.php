<?php

namespace App\Election\Tabulation;

use App\Election\Support\ElectionStorage;
use RuntimeException;

final class TabulationProfileResolver
{
    public function __construct(private readonly ElectionStorage $storage) {}

    public function current(): TabulationProfile
    {
        $configuration = $this->storage->readJson('runtime/active-precinct.json');
        $value = $configuration['tabulation_profile'] ?? config('election.tabulation.profile');

        if (! is_string($value)) {
            throw new RuntimeException('The configured election tabulation profile is invalid.');
        }

        return TabulationProfile::tryFrom($value)
            ?? throw new RuntimeException("Unknown election tabulation profile [{$value}].");
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public function freeze(array $configuration): array
    {
        $value = config('election.tabulation.profile');

        if (! is_string($value) || TabulationProfile::tryFrom($value) === null) {
            throw new RuntimeException('The configured election tabulation profile is invalid.');
        }

        return [
            ...$configuration,
            'tabulation_profile' => $value,
        ];
    }

    /**
     * @return array{key: string, label: string, tally_source: string, routine_scanning_enabled: bool, paper_audit_required: bool}
     */
    public function describe(): array
    {
        $profile = $this->current();

        return [
            'key' => $profile->value,
            'label' => $profile->label(),
            'tally_source' => $profile->tallySource(),
            'routine_scanning_enabled' => $profile->routineScanningEnabled(),
            'paper_audit_required' => true,
        ];
    }
}
