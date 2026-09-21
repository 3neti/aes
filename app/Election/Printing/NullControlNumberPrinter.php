<?php

namespace App\Election\Printing;

use App\Election\Core\ActivityJournal;

final class NullControlNumberPrinter implements ControlNumberPrinter
{
    public function __construct(
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>
     */
    public function print(array $release): array
    {
        $job = [
            'schema_version' => 'control-number-print-job-1',
            'release_id' => $release['release_id'] ?? null,
            'printer' => 'disabled',
            'status' => 'disabled',
        ];

        $this->journal->record('control_number.print_disabled', [
            'release_id' => $job['release_id'],
        ]);

        return $job;
    }
}
