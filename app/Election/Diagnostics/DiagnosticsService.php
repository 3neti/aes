<?php

namespace App\Election\Diagnostics;

use App\Election\Core\ActivityJournal;
use App\Election\Support\ElectionStorage;

final class DiagnosticsService
{
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ActivityJournal $journal,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        return [
            'storage_root' => $this->storage->root(),
            'configuration' => $this->storage->readJson('runtime/active-precinct.json'),
            'package' => $this->storage->readJson('packages/active-package.json'),
            'journal_entries' => count($this->journal->entries()),
            'accepted_ballots' => count($this->storage->files('counting/accepted')),
            'rejected_ballots' => count($this->storage->files('counting/rejected')),
            'attestations' => count($this->storage->files('attestations')),
            'printer' => config('election.devices.printer.adapter', 'simulated'),
            'scanner' => config('election.devices.scanner.adapter', 'simulated'),
            'device_certification' => $this->storage->readJson('certification/device-certification-report.json'),
        ];
    }
}
