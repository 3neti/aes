<?php

namespace App\Election\Certification;

use App\Election\Devices\DeviceCertificationService;
use App\Election\Preparation\PrecinctSetupService;
use App\Election\Support\ElectionStorage;
use App\Election\Support\ReviewMode;

final class ReviewCertificationReadiness
{
    public function __construct(
        private readonly ReviewMode $reviewMode,
        private readonly ElectionStorage $storage,
        private readonly PrecinctSetupService $precinctSetup,
        private readonly DeviceCertificationService $deviceCertification,
        private readonly InitializationReportService $initializationReport,
    ) {}

    /**
     * @return array{
     *     review_mode: bool,
     *     prepared: bool,
     *     created: list<string>,
     *     precinct_setup: array<string, mixed>,
     *     device_certification: array<string, mixed>,
     *     initialization_report: array<string, mixed>
     * }
     */
    public function ensure(): array
    {
        if (! $this->reviewMode->enabled()) {
            return [
                'review_mode' => false,
                'prepared' => false,
                'created' => [],
                'precinct_setup' => [],
                'device_certification' => [],
                'initialization_report' => [],
            ];
        }

        $created = [];
        $precinctSetup = $this->storage->readJson('runtime/precinct-setup.json');

        if ($precinctSetup === []) {
            $precinctSetup = $this->precinctSetup->record(
                (array) config('election.simulation.precinct_setup', []),
            );
            $created[] = 'precinct-setup';
        }

        $deviceCertification = $this->storage->readJson('certification/device-certification-report.json');

        if ($deviceCertification === []) {
            $deviceCertification = $this->deviceCertification->run();
            $created[] = 'device-certification';
        }

        $initializationReport = $this->storage->readJson('diagnostics/initialization-report.json');

        if ($initializationReport === []) {
            $initializationReport = $this->initializationReport->write();
            $created[] = 'initialization-report';
        }

        return [
            'review_mode' => true,
            'prepared' => (bool) ($precinctSetup['passed'] ?? false)
                && (bool) ($deviceCertification['passed'] ?? false)
                && (bool) ($initializationReport['passed'] ?? false),
            'created' => $created,
            'precinct_setup' => $precinctSetup,
            'device_certification' => $deviceCertification,
            'initialization_report' => $initializationReport,
        ];
    }
}
