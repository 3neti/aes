<?php

use App\Election\Preparation\ActivateSamplePackage;
use App\Election\Support\ElectionStorage;

beforeEach(function (): void {
    app(ElectionStorage::class)->reset();
    app(ActivateSamplePackage::class)->handle();
});

test('ceremony page has no browser smoke failures: :label', function (string $path, string $label): void {
    visit($path)
        ->assertSee($label)
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
})->with([
    'home' => ['/', 'Alternative Election System'],
    'provision' => ['/election/provision', 'Provision'],
    'certification' => ['/election/certification', 'Certification'],
    'voting' => ['/election/voting', 'Voting'],
    'printing' => ['/election/printing', 'Official Ballot Artifact'],
    'counting' => ['/election/counting', 'Counting'],
    'returns' => ['/election/returns', 'Election Return'],
    'diagnostics' => ['/election/diagnostics', 'Diagnostics'],
]);
