<?php

namespace App\Providers;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Devices\CupsPrinterHealthCheck;
use App\Election\Devices\DeviceCertificationService;
use App\Election\Devices\DeviceHealthCheck;
use App\Election\Devices\HandheldScannerHealthCheck;
use App\Election\Devices\SimulatedPrinterHealthCheck;
use App\Election\Devices\SimulatedScannerHealthCheck;
use App\Election\Printing\BallotPrinter;
use App\Election\Printing\CupsBallotPrinter;
use App\Election\Printing\FileBallotPrinter;
use App\Election\Scanning\BallotScanner;
use App\Election\Scanning\HandheldPayloadScanner;
use App\Election\Scanning\ManualPayloadScanner;
use App\Election\Support\ElectionStorage;
use App\Election\Support\SimplePdf;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(BallotPrinter::class, function (Application $app): BallotPrinter {
            if (config('election.devices.printer.driver') === 'cups') {
                return new CupsBallotPrinter(
                    new FileBallotPrinter(
                        $app->make(ElectionStorage::class),
                        $app->make(ActivityJournal::class),
                        $app->make(SimplePdf::class),
                    ),
                    $app->make(ElectionStorage::class),
                    $app->make(ActivityJournal::class),
                    (string) config('election.devices.printer.cups.name', ''),
                    (int) config('election.devices.printer.cups.timeout', 10),
                );
            }

            return $app->make(FileBallotPrinter::class);
        });
        $this->app->bind(DeviceCertificationService::class, function (Application $app): DeviceCertificationService {
            return new DeviceCertificationService(
                $app->make(ElectionStorage::class),
                $app->make(ActivityJournal::class),
                $app->make(CanonicalJson::class),
                $this->deviceHealthChecks(),
            );
        });
        $this->app->bind(BallotScanner::class, function (Application $app): BallotScanner {
            if (config('election.devices.scanner.driver') === 'handheld') {
                return new HandheldPayloadScanner(
                    $app->make(ActivityJournal::class),
                    (string) config('election.devices.scanner.handheld.name', 'keyboard-wedge'),
                );
            }

            return $app->make(ManualPayloadScanner::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * @return array<int, DeviceHealthCheck>
     */
    private function deviceHealthChecks(): array
    {
        return [
            $this->printerHealthCheck(),
            $this->scannerHealthCheck(),
        ];
    }

    private function printerHealthCheck(): DeviceHealthCheck
    {
        if (config('election.devices.printer.adapter') === 'cups') {
            return new CupsPrinterHealthCheck(
                (string) config('election.devices.printer.cups.name', ''),
                (int) config('election.devices.printer.cups.timeout', 3),
            );
        }

        return new SimulatedPrinterHealthCheck;
    }

    private function scannerHealthCheck(): DeviceHealthCheck
    {
        if (config('election.devices.scanner.adapter') === 'handheld') {
            return new HandheldScannerHealthCheck(
                (string) config('election.devices.scanner.handheld.name', ''),
            );
        }

        return new SimulatedScannerHealthCheck;
    }
}
