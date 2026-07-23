<?php

use App\Election\Preparation\ActivateSamplePackage;
use App\Election\Support\ElectionStorage;

beforeEach(function (): void {
    app(ElectionStorage::class)->reset();
    app(ActivateSamplePackage::class)->handle();
});

test('ceremony pages have no browser smoke failures', function (): void {
    $ceremonies = [
        ['/', 'Alternative Election System'],
        ['/election/provision', 'Precinct Setup'],
        ['/election/certification', 'Certification'],
        ['/election/voting', 'Voting'],
        ['/election/printing', 'Official Ballot Artifact'],
        ['/election/counting', 'Counting'],
        ['/election/returns', 'Election Return'],
        ['/election/diagnostics', 'Diagnostics'],
    ];

    foreach ($ceremonies as [$path, $label]) {
        visit($path)
            ->assertSee($label)
            ->assertNoJavaScriptErrors()
            ->assertNoConsoleLogs();
    }
});
