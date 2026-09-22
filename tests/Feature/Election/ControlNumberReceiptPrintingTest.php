<?php

use App\Election\Lifecycle\Lifecycle;
use App\Election\Lifecycle\LifecycleState;
use App\Election\Preparation\ActivateSamplePackage;
use App\Election\Preparation\PrecinctSetupService;
use App\Election\Printing\ControlNumberPrinter;
use App\Election\Support\ElectionClock;
use App\Election\Support\ElectionStorage;
use App\Election\Support\PdfTextExtractor;
use App\Election\Voting\AnonymousVoterAuthorization;
use App\Election\Voting\PrivateBallotRelease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->withoutVite();
    app(ElectionStorage::class)->reset();
    app(ElectionClock::class)->unfreeze();
    app(ActivateSamplePackage::class)->handle();
    app(PrecinctSetupService::class)->record(config('election.simulation.precinct_setup'));
    app(LifecycleState::class)->set(Lifecycle::Voting);
});

test('voter finalization submits the control number receipt to the thermal cups queue', function (): void {
    config()->set('election.control_number_printer.driver', 'cups');
    config()->set('election.control_number_printer.cups.name', 'Thermal80');
    config()->set('election.control_number_printer.cups.timeout', 7);
    Process::fake([
        '*' => Process::result('request id is Thermal80-21 (1 file)'),
    ]);

    $authorization = app(AnonymousVoterAuthorization::class)->issue();

    $this->post(route('election.voter.claim'), ['code' => $authorization['code']])
        ->assertRedirect(route('election.voter.ballot'));

    $finalize = $this->post(route('election.voter.finalize'), [
        'selections' => [
            'president' => ['pres-ada'],
            'mayor' => ['mayor-lina'],
            'council' => ['council-ana'],
        ],
    ]);
    $release = $finalize->getSession()->get('election.voter_print_release');
    $job = app(ElectionStorage::class)->readJson("print-jobs/control-number/{$release['release_id']}.json");

    $finalize->assertRedirect(route('election.voter.complete'));
    expect($release['release_code'])->toBe($authorization['code'])
        ->and($job['printer'])->toBe('cups')
        ->and($job['printer_name'])->toBe('Thermal80')
        ->and($job['status'])->toBe('submitted')
        ->and($job['cups_output'])->toContain('Thermal80-21')
        ->and($job['print_form_profile'])->toBe('thermal-80')
        ->and($job['pdf_artifact_path'])->toBeReadableFile();

    Process::assertRan(fn ($process): bool => $process->command === [
        'lp',
        '-d',
        'Thermal80',
        '-t',
        'AES Control Number '.$release['release_id'],
        $job['pdf_artifact_path'],
    ]);
});

test('control number cups printer falls back to file only when no thermal queue is configured', function (): void {
    config()->set('election.control_number_printer.driver', 'cups');
    config()->set('election.control_number_printer.cups.name', '');
    Process::fake();

    $release = controlNumberReceiptRelease('2468');
    $job = app(ControlNumberPrinter::class)->print($release);

    expect($job['printer'])->toBe('file')
        ->and($job['status'])->toBe('printed')
        ->and($job['printer_fallback_reason'])->toBe('cups-printer-not-configured')
        ->and($job['requested_cups_printer'])->toBe('')
        ->and($job['pdf_artifact_path'])->toBeReadableFile();

    Process::assertNothingRan();
});

test('control number receipt does not contain ballot selections or payload hash', function (): void {
    config()->set('election.control_number_printer.driver', 'file');

    $release = controlNumberReceiptRelease('1357');
    $job = app(ControlNumberPrinter::class)->print($release);
    $pdfBytes = file_get_contents($job['pdf_artifact_path']);
    $pdfText = collect(app(PdfTextExtractor::class)->extract($job['pdf_artifact_path']))
        ->map(fn ($page): string => $page->text)
        ->implode("\n");
    $printedAt = CarbonImmutable::parse($job['printed_at'])
        ->setTimezone(date_default_timezone_get())
        ->format('d Hi\H M Y');

    expect($job)->not->toHaveKey('payload_hash')
        ->and($job)->not->toHaveKey('qr_artifact_path')
        ->and($job['printed_at'])->toBeString()
        ->and($pdfText)->toContain('1357')
        ->and($pdfText)->toContain('Precinct 0421-A | CITY OF MANILA')
        ->and($pdfText)->toContain($printedAt)
        ->and($pdfText)->not->toContain('VOTER CONTROL NUMBER')
        ->and($pdfText)->not->toContain('CONTROL NUMBER')
        ->and($pdfText)->not->toContain('BRING THIS TO THE PRINT STATION')
        ->and($pdfText)->not->toContain('aes-print-release:1357')
        ->and($pdfBytes)->not->toContain('/Subtype /Image')
        ->and($pdfText)->not->toContain('Ada Santos')
        ->and($pdfText)->not->toContain('pres-ada')
        ->and($pdfText)->not->toContain('payload_hash')
        ->and($pdfText)->not->toContain('Payload Hash');
});

test('voter finalization still completes when control number printing throws', function (): void {
    app()->bind(ControlNumberPrinter::class, fn (): ControlNumberPrinter => new class implements ControlNumberPrinter
    {
        public function print(array $release): array
        {
            throw new RuntimeException('thermal printer offline');
        }
    });

    $authorization = app(AnonymousVoterAuthorization::class)->issue();

    $this->post(route('election.voter.claim'), ['code' => $authorization['code']])
        ->assertRedirect(route('election.voter.ballot'));

    $finalize = $this->post(route('election.voter.finalize'), [
        'selections' => [
            'president' => ['pres-ada'],
            'mayor' => ['mayor-lina'],
            'council' => ['council-ana'],
        ],
    ]);
    $release = $finalize->getSession()->get('election.voter_print_release');

    $finalize->assertRedirect(route('election.voter.complete'));
    expect($release['release_code'])->toBe($authorization['code']);
});

/**
 * @return array<string, mixed>
 */
function controlNumberReceiptRelease(string $controlNumber): array
{
    $authorizations = app(AnonymousVoterAuthorization::class);
    $authorization = $authorizations->issue();
    $authorizations->claim($authorization['code']);

    return app(PrivateBallotRelease::class)->create($authorization['authorization_id'], [
        'president' => ['pres-ada'],
        'mayor' => ['mayor-lina'],
        'council' => ['council-ana'],
    ], $controlNumber);
}
