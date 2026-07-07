<?php

namespace App\Election\Preparation;

use RuntimeException;

final class PopMappingProfiles
{
    public const Default = 'comelec-pop-2025-nle';

    public const RenamedReorderedDemo = 'comelec-pop-renamed-reordered-demo';

    public static function get(?string $name = null): PopMappingProfile
    {
        return match ($name ?? self::Default) {
            self::Default => new PopMappingProfile(
                name: self::Default,
                sourceLabel: 'FINAL_Clustered.POP_NLE_2025',
                fieldMap: [
                    'region' => 'REGION',
                    'province' => 'PROVINCE',
                    'city_municipality' => 'CITY_MUNICIPALITY',
                    'barangay' => 'BARANGAY',
                    'clustered_precinct' => 'CLUSTERED_PRECINCT',
                    'precinct_cluster' => 'PRECINCT_CLUSTER',
                    'cluster_total' => 'CLUSTERTOTAL',
                    'polling_place' => 'POLLING_PLACE',
                ],
                requiresExactHeaders: true,
            ),
            self::RenamedReorderedDemo => new PopMappingProfile(
                name: self::RenamedReorderedDemo,
                sourceLabel: 'FINAL_Clustered.POP_NLE_2025',
                fieldMap: [
                    'region' => 'REGION_NAME',
                    'province' => 'PROVINCE_NAME',
                    'city_municipality' => 'CITY_OR_MUNICIPALITY',
                    'barangay' => 'BARANGAY_NAME',
                    'clustered_precinct' => 'CLUSTERED_PRECINCT_ID',
                    'precinct_cluster' => 'PRECINCTS_INCLUDED',
                    'cluster_total' => 'REGISTERED_VOTERS',
                    'polling_place' => 'POLLING_PLACE_NAME',
                ],
                requiresExactHeaders: false,
            ),
            default => throw new RuntimeException("Unknown POP mapping profile [{$name}]."),
        };
    }
}
