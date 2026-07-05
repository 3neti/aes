<?php

namespace App\Providers;

use App\Election\Core\ActivityJournal;
use App\Election\Core\CanonicalJson;
use App\Election\Devices\CupsPrinterHealthCheck;
use App\Election\Devices\DeviceCertificationService;
use App\Election\Devices\DeviceHealthCheck;
use App\Election\Devices\SimulatedPrinterHealthCheck;
use App\Election\Devices\SimulatedScannerHealthCheck;
use App\Election\Printing\BallotPrinter;
use App\Election\Printing\FileBallotPrinter;
use App\Election\Support\ElectionStorage;
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
        $this->app->bind(BallotPrinter::class, FileBallotPrinter::class);
        $this->app->bind(DeviceCertificationService::class, function (Application $app): DeviceCertificationService {
            return new DeviceCertificationService(
                $app->make(ElectionStorage::class),
                $app->make(ActivityJournal::class),
                $app->make(CanonicalJson::class),
                $this->deviceHealthChecks(),
            );
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
            new SimulatedScannerHealthCheck,
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
}
