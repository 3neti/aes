# Alternative Election System Implementation Status

## Completed Waves

- Wave 1 Foundation: lifecycle shell, dictionary, append-only activity journal, storage helper, Home UI, diagnostics shell, scenario command.
- Wave 2 Precinct Preparation: embedded sample registries/package, package activation, deterministic mapping, persisted active precinct configuration.
- Wave 3 Certification: Friday certification deck, expected tally comparison, certification report artifact.
- Wave 4 Voting: poll opening/closing, simulated ballot selection, finalization, deterministic QR payload string.
- Wave 5 Printing: `BallotPrinter` interface, `FileBallotPrinter`, `NullBallotPrinter`, print job journaling, spoilage simulation.
- Wave 6 Counting: simulated payload scan, accepted/rejected append files, duplicate/spoilage rejection, tally generation.
- Wave 7 Election Return: deterministic Election Return JSON and text artifacts.
- Wave 8 Audit: journal/timeline summary and diagnostics projection.
- Wave 9 Hardening: deterministic scenario reset, full-demo scenario, duplicate rejection, spoilage rejection, build/test verification.

## Completed Slices

- Domain services under `app/Election` for Core, Lifecycle, Preparation, Certification, Voting, Printing, Counting, Returns, Diagnostics, Scenarios, and Support.
- Ceremony routes/controllers under `/election/*`.
- Inertia Vue pages under `resources/js/pages/Election`.
- Shared ceremony layout and typed snapshot props under `resources/js/components/election`.
- Sample election data under `resources/election/sample`.
- Standards-compliant QR PNG artifacts for finalized ballots, with `zbarimg` decode support for counting tests.
- Deterministic PDF artifacts for printed ballots and Election Returns.
- Ceremony page smoke coverage for every Inertia operator page.
- Simulated printer/scanner adapter certification with a persisted device certification report.
- Configurable printer health adapter selection with a CUPS `lpstat` certification scaffold.
- Opt-in CUPS ballot printer adapter that submits generated ballot artifacts while retaining file evidence.
- CUPS ballot submission gate requiring a passing matching device certification before printer submission.
- Configurable scanner health and scan adapter boundaries for manual and handheld keyboard-wedge workflows.
- Camera/image scanner scaffold that decodes PNG QR image data URIs into canonical ballot payloads.
- Browser camera capture UI on the Counting ceremony page that submits captured QR image frames through the camera scanner route.
- Immediate Counting ceremony operator feedback after scan attempts, including accepted/rejected status, adapter, sequence, reason, and evidence hashes.
- Local officer PIN registry validation and browser signature capture for attestation checkpoints, with one JSON evidence artifact and one PNG signature artifact per attestation.
- Diagnostics attestation evidence bundle with inline views and download links for attestation JSON and signature PNG artifacts.
- Precinct evidence manifest export that summarizes ceremony artifact categories, file sizes, and SHA-256 hashes in one JSON file.
- Optional removable-media export workflow that stages the evidence manifest, referenced artifacts, and an export report in a deterministic local media directory.
- Evidence export verification command that re-hashes a staged removable-media bundle and reports missing, size-mismatched, and hash-mismatched artifacts.
- Diagnostics UI support for running evidence export verification and inspecting the latest persisted verification report with mismatch details.
- Physical removable-media readiness check scaffold for configured export targets, including directory availability, process writability, and probe write/delete checks.
- Operator-facing evidence bundle archive build and download workflow for environments without mounted removable media.
- Downloadable TAR evidence bundle archive verification through an Artisan command and Diagnostics ceremony action, including persisted verification reports and mismatch inspection.
- Upload-and-verify workflow for externally returned TAR evidence bundles copied back onto the appliance, with staged uploaded archive artifacts and source-aware verification reports.
- Browser-level Diagnostics workflow coverage for building an evidence bundle archive, exercising the download link, uploading returned TAR bytes through the verification route, and confirming the visible verification result without JavaScript or console errors.
- GitHub Actions CI wiring for a dedicated Playwright/Pest Browser job that installs Chromium and runs `tests/Browser` separately from the PHP-version matrix.
- GitHub Actions failure artifact upload for Pest Browser screenshots so failed browser runs expose captured UI evidence without committing screenshots.
- Browser testing workflow documentation for local setup, CI troubleshooting, and screenshot artifact inspection.
- GitHub Actions failure artifact upload for Laravel backend logs during browser-test failures.
- Browser smoke coverage for Home, Provision, Certification, Voting, Printing, Counting, Returns, and Diagnostics with JavaScript error and console log assertions.
- Manual GitHub Actions controlled browser artifact failure input that writes screenshot/log marker files and fails the browser job to verify artifact uploads.
- Browser-level Counting camera-capture workflow coverage with mocked media capture and deterministic QR canvas output.
- Browser-level Counting camera permission denied/unavailable workflow coverage with zero accepted scans.
- Shared Pest browser media/canvas shim helpers for future camera workflow tests.
- Counting scanner decode failures are converted into rejected scan feedback with one rejected counting evidence file instead of a server error.
- Browser-level Counting rejected camera QR frame coverage for PNG frames that reach the scanner route but fail QR decode.
- Artisan scenarios:
  - `php artisan election:scenario friday-certification`
  - `php artisan election:scenario full-demo`

## Tests Added

- `tests/Feature/Election/ElectionLifecycleTest.php`
  - lifecycle transition guard
  - package activation
  - deterministic mapping
  - Friday certification expected result
  - ballot finalization and QR payload
  - rendered QR artifact decode
  - print job artifact
  - ballot PDF artifact
  - accepted counting append file
  - duplicate rejection
  - spoilage rejection
  - Election Return artifact
  - Election Return PDF artifact
  - full scenario command success
  - Home Inertia component render
  - simulated device adapter certification report
  - CUPS printer health adapter selection and not-configured behavior
  - CUPS ballot printer submission and failed-submission evidence retention
  - CUPS ballot printer certification gate
  - handheld scanner health adapter selection and not-configured behavior
  - manual and handheld scan normalization before counting
  - camera scanner health adapter selection, not-configured behavior, and QR PNG image data URI decode before counting
  - officer attestation artifact, signature artifact, journal event, local registry metadata, invalid PIN rejection, and invalid signature rejection
  - removable-media evidence export verification success path and tampered artifact mismatch command failure
  - downloadable TAR evidence bundle archive verification success path and tampered archive mismatch command failure
- `tests/Feature/Election/ElectionPagesSmokeTest.php`
  - Home, Provision, Certification, Voting, Printing, Counting, Returns, and Diagnostics Inertia smoke coverage
  - finalized ballot Printing page QR image data URI smoke coverage
  - Diagnostics page device adapter certification action
  - ceremony shell officer attestation action
  - Printing ceremony certification gate error path for CUPS driver
  - Counting route scan through configured handheld and camera scanner adapters
  - Counting route scanner decode failure feedback and rejected evidence file
  - Counting page operator feedback after accepted and rejected scan attempts
  - ceremony shell officer PIN validation failure path
  - ceremony shell officer signature required validation path
  - Diagnostics attestation evidence bundle projection and artifact view/download routes
  - Diagnostics precinct evidence manifest generation, summary projection, and download route
  - Diagnostics removable-media export staging, copied artifact evidence, summary projection, and journal event
  - Diagnostics evidence export verification action, persisted report projection, and journal event
  - Diagnostics removable-media readiness action for simulated and missing configured targets
  - Diagnostics evidence bundle archive build, TAR content smoke check, download route, and journal event
  - Diagnostics downloadable TAR evidence bundle archive verification action, persisted report projection, and journal event
  - Diagnostics returned TAR archive upload verification action, staged upload artifact, source metadata projection, and journal event
- `tests/Browser/DiagnosticsEvidenceBundleWorkflowTest.php`
  - Diagnostics evidence bundle archive build, download link interaction, returned archive upload verification, visible verification status, and browser smoke assertions
- `tests/Browser/ElectionCeremonyPagesSmokeTest.php`
  - Home, Provision, Certification, Voting, Printing, Counting, Returns, and Diagnostics browser smoke coverage with `assertNoJavaScriptErrors()` and `assertNoConsoleLogs()`
- `tests/Browser/CountingCameraCaptureWorkflowTest.php`
  - Counting ceremony camera controls with mocked `getUserMedia`, deterministic QR canvas capture, camera scanner route submission, accepted scan feedback, and counting append file assertion
  - Counting ceremony camera permission denied/unavailable feedback with no accepted counting file
  - Counting ceremony camera frame with no decodable QR code rejected through scanner route feedback and rejected evidence assertion
- `tests/Helpers/BrowserMedia.php`
  - reusable `browserMediaCaptureShim()` and `browserMediaDeniedShim()` helpers loaded from `tests/Pest.php`
- `.github/workflows/tests.yml`
  - dedicated browser test job with PHP 8.4, Node 22, `npm ci`, `npx playwright install --with-deps chromium`, asset build, and `vendor/bin/pest tests/Browser --ci`
  - browser screenshot artifact upload on failed browser-test runs
  - Laravel backend log artifact upload on failed browser-test runs
  - manual `workflow_dispatch` controlled browser artifact failure input
- `docs/BROWSER_TESTING_WORKFLOW.md`
  - local Pest Browser setup and run commands
  - CI browser job shape and screenshot/backend-log artifact inspection workflow
  - controlled artifact verification workflow for screenshot/log upload checks
  - troubleshooting notes for Playwright, Inertia/Vite, returned TAR upload verification, and CI backend context
- Updated the starter `tests/Feature/ExampleTest.php` to use `withoutVite()` for server-side test stability.

## Commands Run

- `php artisan wayfinder:generate --with-form --no-interaction`
- `composer require bacon/bacon-qr-code --no-interaction`
- `composer require pestphp/pest-plugin-browser --dev --no-interaction`
- `npm install --save-dev playwright@latest`
- `npx playwright install chromium`
- `php artisan test --compact tests/Feature/Election/ElectionLifecycleTest.php`
- `php artisan test --compact tests/Feature/Election/ElectionPagesSmokeTest.php`
- `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --compact`
- `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --compact`
- `vendor/bin/pint --dirty --format agent`
- `npm run format -- resources/js/pages/Election/Diagnostics.vue`
- `vendor/bin/pest --compact`
- `php artisan test --compact`
- `npm run lint:check`
- `npm run types:check`
- `npm run format:check`
- `npm run build`
- `php artisan election:scenario friday-certification`
- `php artisan election:scenario full-demo`
- `vendor/bin/pest tests/Browser/DiagnosticsEvidenceBundleWorkflowTest.php --compact`
- `vendor/bin/pest tests/Browser --compact`
- `vendor/bin/pest tests/Browser/ElectionCeremonyPagesSmokeTest.php --compact`
- `vendor/bin/pest tests/Browser/CountingCameraCaptureWorkflowTest.php --compact`
- `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --compact`
- `vendor/bin/pest --compact`
- `composer validate --strict`
- `ruby -e 'require "yaml"; YAML.load_file(".github/workflows/tests.yml")'`
- Documentation-only slice; no additional test command required.
- `ruby -e 'require "yaml"; YAML.load_file(".github/workflows/tests.yml")'`
- `ruby -e 'require "yaml"; YAML.load_file(".github/workflows/tests.yml")'`

## Verification Results

- Focused Pest lifecycle suite: passed, 30 tests and 143 assertions.
- Focused Pest ceremony page suite: passed, 26 tests and 437 assertions.
- Pest configured feature/unit suite: passed, 58 tests and 582 assertions.
- TypeScript: passed.
- ESLint: passed.
- Prettier check: passed.
- Vite production build: passed.
- Friday certification scenario: passed.
- Full demo scenario: passed.
- Focused Pest browser Diagnostics suite: passed, 1 test and 25 assertions.
- Pest Browser suite: passed, 1 test and 25 assertions.
- Focused Pest browser ceremony smoke suite: passed, 8 tests and 24 assertions.
- Pest Browser suite with Diagnostics workflow and ceremony smoke coverage: passed, 9 tests and 49 assertions.
- Focused Pest browser Counting camera capture workflow suite: passed, 1 test and 12 assertions.
- Pest Browser suite with Diagnostics workflow, ceremony smoke coverage, and Counting camera capture workflow: passed, 10 tests and 61 assertions.
- Focused Pest browser Counting camera workflow suite with permission denied/unavailable coverage: passed, 2 tests and 21 assertions.
- Pest Browser suite with Diagnostics workflow, ceremony smoke coverage, Counting camera capture, and camera unavailable coverage: passed, 11 tests and 70 assertions.
- Focused Pest browser Counting camera workflow suite after media helper extraction: passed, 2 tests and 21 assertions.
- Focused Pest browser Counting rejected camera QR frame workflow suite: passed, 3 tests and 34 assertions.
- Pest Browser suite with rejected camera QR frame coverage: passed, 12 tests and 83 assertions.
- Focused Pest ceremony page suite after scanner decode hardening: passed, 27 tests and 442 assertions.
- Pest configured feature/unit suite after scanner decode hardening: passed, 59 tests and 587 assertions.
- Pest configured feature/unit suite after browser smoke addition: passed, 58 tests and 582 assertions.
- GitHub Actions workflow YAML parse: passed.
- GitHub Actions browser backend-log artifact workflow YAML parse: passed.
- GitHub Actions controlled browser artifact failure workflow YAML parse: passed.

## Known Gaps

- QR decoding currently uses the local `zbarimg` binary; a pure PHP or packaged decoder adapter may be preferable for deployment portability.
- PDF ballot and Election Return artifacts are generated with a simple internal PDF renderer.
- Printer health certification can probe CUPS status when configured, and CUPS ballot submission is available behind an opt-in driver only after matching certification. File artifact printing remains the default and no ESC/POS output is implemented.
- Scanner certification and scanning are adapter-driven for manual, handheld keyboard-wedge, and camera/image QR workflows. Browser camera capture is scaffolded for the Counting ceremony.
- Browser camera capture requires operator browser permission and a secure origin as enforced by the browser.
- Officer attestation uses a local deterministic PIN registry and PNG signature artifacts; no identity proofing workflow yet.
- Removable-media export is currently simulated as a local staging directory by default; configured physical targets are readiness-checked but not auto-mounted or write-protection-aware yet.
- Evidence bundle archive downloads are currently uncompressed deterministic TAR files.
- Downloaded archive verification currently supports the appliance-generated uncompressed TAR format only.
- Returned archive upload verification stages uploaded TAR files locally before verification; no malware scanning or external media provenance workflow is implemented yet.
- Browser tests use Pest Browser and Playwright. The Pest Browser Laravel request bridge does not currently forward multipart file uploads, so the upload verification route also accepts a base64 TAR payload for browser-level workflow coverage.
- In this sandbox, `php artisan test` can fail after installing Pest Browser because the plugin attempts socket operations under sandbox restrictions; `vendor/bin/pest` is the verified test entry point for this slice.
- SQLite read models are not introduced.
- x-journal, x-change, and x-feedback are intentionally not integrated.
- Backup appliance support is limited to deterministic re-derivation behavior in services and scenarios.

## Next Recommended Steps

- Improve PDF visual design and add Poppler-based render checks in an environment with Poppler installed.
- Add a short CI operations note after running the controlled artifact check remotely and confirming both artifacts appear in GitHub Actions.
- Add browser coverage for rejected camera QR frames that reach the scanner route but fail QR decode.
