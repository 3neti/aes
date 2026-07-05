<?php

namespace App\Election\Devices;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Support\ElectionStorage;

final class DeviceCertificationService
{
    /**
     * @param  array<int, DeviceHealthCheck>  $checks
     */
    public function __construct(
        private readonly ElectionStorage $storage,
        private readonly ActivityJournal $journal,
        private readonly CanonicalJson $json,
        private readonly array $checks = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $checks = $this->checks === [] ? [
            new SimulatedPrinterHealthCheck,
            new SimulatedScannerHealthCheck,
        ] : $this->checks;

        $results = collect($checks)
            ->mapWithKeys(fn (DeviceHealthCheck $check): array => [
                $check->deviceType() => $check->check(),
            ])
            ->all();

        $passed = collect($results)->every(fn (array $result): bool => ($result['status'] ?? null) === 'ready');
        $report = [
            'schema_version' => 'device-certification-report-1',
            'passed' => $passed,
            'devices' => $results,
        ];
        $report['report_hash'] = $this->json->hash($report);

        $this->storage->writeJson('certification/device-certification-report.json', $report);
        $this->journal->record($passed ? 'devices.certification_passed' : 'devices.certification_failed', [
            'report_hash' => $report['report_hash'],
            'devices' => array_keys($results),
        ]);

        return $report;
    }
}
