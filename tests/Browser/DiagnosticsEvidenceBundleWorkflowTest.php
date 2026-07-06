<?php

use App\Election\Preparation\ActivateSamplePackage;
use App\Election\Support\ElectionStorage;

beforeEach(function (): void {
    app(ElectionStorage::class)->reset();
});

test('operator can build download upload and verify an evidence bundle archive from diagnostics', function (): void {
    app(ActivateSamplePackage::class)->handle();

    $page = visit('/election/diagnostics')
        ->assertSee('Diagnostics')
        ->assertSee('Evidence Bundle Archive')
        ->assertSee('Archive Verification')
        ->assertNoSmoke();

    $page->pressAndWaitFor('Build Download Archive', 1)
        ->assertSee('Download Archive')
        ->assertSee('Verify Built Archive')
        ->assertNoSmoke();

    $archive = app(ElectionStorage::class)->readJson('diagnostics/evidence-bundle-archive.json');

    expect($archive['archive_path'])->toBeReadableFile();

    expect($page->attribute('Download Archive', 'href'))->toContain('/election/diagnostics/evidence-bundle-archive/download');

    $page->click('Download Archive')
        ->wait(1)
        ->assertNoSmoke();

    $archiveBase64 = base64_encode(file_get_contents($archive['archive_path']));
    $archiveFilename = json_encode($archive['archive_artifact'], JSON_THROW_ON_ERROR);
    $archiveContents = json_encode($archiveBase64, JSON_THROW_ON_ERROR);

    $upload = $page->script(<<<JS
        async () => {
            const token = document.querySelector('meta[name="csrf-token"]').content;
            const body = new URLSearchParams();
            body.append('archive_name', {$archiveFilename});
            body.append('archive_payload', {$archiveContents});

            const response = await fetch('/election/diagnostics/evidence-bundle-archive/upload-verify', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body,
            });

            const responseBody = await response.text();

            return {
                body: responseBody.slice(0, 500),
                redirected: response.redirected,
                status: response.status,
                url: response.url,
            };
        }
    JS);

    expect($upload['status'])->toBe(200, $upload['body']);

    $page->navigate('/election/diagnostics')
        ->assertSee('Latest archive verification passed.')
        ->assertSee('operator-upload')
        ->assertSee('Uploaded Artifact')
        ->assertSee('Passed')
        ->assertNoSmoke();

    $verification = app(ElectionStorage::class)->readJson('diagnostics/evidence-bundle-archive-verification.json');

    expect($verification['passed'])->toBeTrue()
        ->and($verification['archive_source'])->toBe('operator-upload')
        ->and($verification['uploaded_archive_artifact'])->toStartWith('uploaded-evidence-bundle-')
        ->and($verification['mismatches'])->toBe([]);
});
