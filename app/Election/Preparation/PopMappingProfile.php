<?php

namespace App\Election\Preparation;

use App\Election\Core\CanonicalJson;
use RuntimeException;

final readonly class PopMappingProfile
{
    /**
     * @param  array<string, string>  $fieldMap
     */
    public function __construct(
        public string $name,
        public string $sourceLabel,
        public array $fieldMap,
        public bool $requiresExactHeaders,
    ) {}

    /**
     * @return array<int, string>
     */
    public static function canonicalFields(): array
    {
        return [
            'region',
            'province',
            'city_municipality',
            'barangay',
            'clustered_precinct',
            'precinct_cluster',
            'cluster_total',
            'polling_place',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function expectedSourceHeaders(): array
    {
        return array_map(
            fn (string $field): string => $this->fieldMap[$field],
            self::canonicalFields(),
        );
    }

    /**
     * @param  array<int, string>  $headers
     */
    public function validateHeaders(array $headers): void
    {
        $duplicates = array_values(array_unique(array_diff_assoc($headers, array_unique($headers))));

        if ($duplicates !== []) {
            throw new RuntimeException('POP source headers contain duplicates: '.implode(', ', $duplicates).'.');
        }

        if ($this->requiresExactHeaders && $headers !== $this->expectedSourceHeaders()) {
            throw new RuntimeException('POP workbook headers do not match the expected 2025 NLE POP format.');
        }

        foreach ($this->fieldMap as $field => $sourceHeader) {
            if (! in_array($sourceHeader, $headers, true)) {
                throw new RuntimeException("Missing required POP source header [{$sourceHeader}] for canonical field [{$field}].");
            }
        }
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, string>  $row
     * @return array<string, mixed>
     */
    public function map(array $headers, array $row, int $sourceRow, CanonicalJson $json): array
    {
        $values = array_combine($headers, array_slice(array_pad($row, count($headers), ''), 0, count($headers)));

        if ($values === false) {
            throw new RuntimeException("Unable to map POP source row [{$sourceRow}].");
        }

        $record = [
            'schema_version' => 'pop-precinct-row-1',
            'region' => trim((string) $values[$this->fieldMap['region']]),
            'province' => trim((string) $values[$this->fieldMap['province']]),
            'city_municipality' => trim((string) $values[$this->fieldMap['city_municipality']]),
            'barangay' => trim((string) $values[$this->fieldMap['barangay']]),
            'clustered_precinct' => trim((string) $values[$this->fieldMap['clustered_precinct']]),
            'precinct_cluster' => trim((string) $values[$this->fieldMap['precinct_cluster']]),
            'cluster_total' => (int) trim((string) $values[$this->fieldMap['cluster_total']]),
            'polling_place' => trim((string) $values[$this->fieldMap['polling_place']]),
            'source_row' => $sourceRow,
        ];

        if ($record['clustered_precinct'] === '') {
            throw new RuntimeException("POP source row [{$sourceRow}] is missing clustered precinct.");
        }

        $record['row_hash'] = $json->hash($record);

        return $record;
    }
}
