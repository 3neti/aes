# Browser Testing Workflow

This project uses Pest Browser with Playwright for browser-level ceremony workflow tests. The browser suite is intentionally separate from the main PHP-version matrix because the Alternative Election System tests write deterministic evidence files under `storage/app/election`, and those files can race when the full suite is parallelized.

## Local Setup

Install PHP and Node dependencies first:

```bash
composer install
npm ci
```

Install the Chromium browser used by the project browser suite:

```bash
npx playwright install chromium
```

If the local machine is missing Playwright system dependencies, use the CI-style install:

```bash
npx playwright install --with-deps chromium
```

Build frontend assets before browser runs that exercise Inertia pages:

```bash
npm run build
```

## Local Runs

Run only the browser suite:

```bash
vendor/bin/pest tests/Browser --compact
```

Run the Diagnostics evidence bundle workflow test directly:

```bash
vendor/bin/pest tests/Browser/DiagnosticsEvidenceBundleWorkflowTest.php --compact
```

Run only the ceremony page smoke coverage:

```bash
vendor/bin/pest tests/Browser/ElectionCeremonyPagesSmokeTest.php --compact
```

Run only the Counting camera capture workflow:

```bash
vendor/bin/pest tests/Browser/CountingCameraCaptureWorkflowTest.php --compact
```

For local debugging, Pest Browser supports headed and debug modes:

```bash
vendor/bin/pest tests/Browser --headed
vendor/bin/pest tests/Browser --debug
```

Do not run AES feature, browser, or scenario tests in parallel until the election storage reset/test isolation model is improved. The storage-backed evidence files are part of the behavior under test.

## CI Workflow

The GitHub Actions workflow has two jobs:

- `ci`: PHP 8.3, 8.4, and 8.5 matrix for the normal Pest suite and type checks.
- `browser`: PHP 8.4 with Node 22, Playwright Chromium install, asset build, and `vendor/bin/pest tests/Browser --ci`.

The browser job runs separately so Playwright installation and browser failures are easier to inspect without multiplying that work across every PHP version.

## Controlled Artifact Check

The workflow can be run manually with `force_browser_artifact_failure` enabled. This creates harmless marker files in both artifact locations, then fails the `browser` job so the failure-only upload steps can be verified.

Use this check when changing CI artifact wiring:

1. Open GitHub Actions.
2. Select the `tests` workflow.
3. Run the workflow manually.
4. Enable `force_browser_artifact_failure`.
5. Wait for the `browser` job to fail after the browser test step.
6. Confirm that both artifacts are available:
   - `browser-screenshots`
   - `browser-backend-logs`
7. Download each artifact and confirm it contains the `controlled-browser-artifact-failure` marker file.

Do not enable this input for routine verification. It is intentionally a failing workflow run.

## Screenshot Artifacts

Browser screenshots are written under:

```text
tests/Browser/Screenshots
```

Laravel backend logs are written under:

```text
storage/logs
```

Both paths are uploaded by CI only when the `browser` job fails. In a failed GitHub Actions run:

1. Open the failed workflow run.
2. Open the `browser` job summary.
3. Download the `browser-screenshots` artifact if it is present.
4. Download the `browser-backend-logs` artifact if it is present.
5. Compare the captured page state against the failing Pest assertion and the Diagnostics ceremony workflow.
6. Inspect backend logs for Laravel exceptions, validation failures, filesystem errors, or evidence export errors that did not surface clearly in the browser assertion.

An artifact may be absent when the job fails before the path exists, or when the failure is outside browser execution, such as dependency installation. The controlled artifact check exists to verify the upload path itself.

## Troubleshooting

If browser tests fail before opening the app, check that `npm ci`, `composer install`, `npx playwright install --with-deps chromium`, `.env` creation, `php artisan key:generate`, and `npm run build` completed in CI.

If the UI renders without expected text, verify that the Inertia page still exposes the expected ceremony labels and that the Vite build is current. The ceremony smoke test intentionally checks Home, Provision, Certification, Voting, Printing, Counting, Returns, and Diagnostics for JavaScript errors and console logs.

If archive upload verification fails only in browser tests, remember that Pest Browser's Laravel request bridge does not currently forward multipart file uploads in this project. The browser workflow uses the supported base64 TAR payload route for browser-level coverage; feature tests cover real uploaded files.

If Counting camera capture fails only in browser tests, check the media shim in `tests/Browser/CountingCameraCaptureWorkflowTest.php`. The test intentionally avoids physical camera hardware by mocking `navigator.mediaDevices.getUserMedia`, video dimensions, and canvas QR image capture. The same file also covers the permission denied/unavailable path by forcing `getUserMedia` to reject and asserting no scan is accepted.

If tests pass locally but fail in CI, inspect the screenshot artifact first, then the backend log artifact, then the browser job logs. Screenshots explain the browser state; Laravel logs explain server-side failures that may be hidden behind a generic browser assertion.
