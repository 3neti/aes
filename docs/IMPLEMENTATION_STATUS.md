# Alternative Election System Implementation Status

## Precinct Realism Program

- Status: Simulation implementation complete; hardware and prescribed-form validation pending
- Plan: `docs/REALISM_IMPLEMENTATION_PLAN.md`
- Compass: `docs/REALISM_COMPASS.md`
- Completed slice: Cloud Review Protection
- Current slice: Cloud Runtime Alignment
- Next slice: Cloud Deployment Migration Gate
- Primary acceptance target: a protected multi-tablet COMELEC review rehearsal that reaches audit, preserves role and voter isolation, and produces a verified Review Kit without weakening election-day controls

### Operational Run Isolation

- Added explicit run types for rehearsals, certification, election day, audit, and automated tests.
- Added typed run pointers while retaining `current-run.json` as the election-day compatibility pointer.
- Restricted `LATEST_RUN.txt` updates to election-day runs.
- Added immutable `run-context.json` evidence with run type, status, and creation source.
- Added run locking that prevents replacement of an existing locked run ID.
- Moved Pest and Pest Browser evidence to `storage/app/election-testing` so tests cannot erase operational evidence.
- Changed the Artisan scenario runner to create rehearsal runs without replacing the operator run.
- Preserved operational browser evidence at `storage/app/election/runs/20260724-004938-0421-a-operator`.
- Focused isolation tests: 3 passed, 9 assertions.
- Scenario and run-folder compatibility tests: 4 passed, 70 assertions.
- Full lifecycle regression: 66 of 69 initially passed; the three exposed stale fixtures were corrected and all four affected focused tests now pass.

### Real Precinct Activation

- Replaced the browser sample-package activation path with the configured POP workbook and CLC PDF import pipeline.
- Added a shared configured-precinct activation action used by both the browser and lifecycle scenario runner.
- Browser provisioning now activates clustered precinct `39010001`, First District, at Isabelo Delos Reyes Elementary School in Barangay 1, Tondo.
- The derived ballot contains six supported contests and 387 candidates from the configured source set.
- Activation evidence records the POP source hash and mapping profile, CLC source and registry hashes, package and ballot registry hashes, mapping hash, and extraction review count.
- The Precinct Setup page now displays the configured source files, polling place, district, contest count, and candidate count.
- Configured provisioning feature test: 1 passed, 29 assertions.
- Shared Friday certification scenario regression: 1 passed, 12 assertions.
- Production frontend build: passed.

### Strict Package Certification

- Added a fail-closed package integrity report before certification ballots are generated.
- Recomputes the configured POP source hash, POP registry hash, CLC registry hash, active ballot registry hash, package hash, deterministic mapping hash, and activation evidence hash.
- Confirms the active clustered precinct matches the appliance configuration.
- Blocks certification when unresolved CLC extraction records affect an active ballot geography; the one current review item is reported as unrelated to the Tondo ballot.
- Restricts embedded sample package certification to automated-test runs.
- Certification failures stay in the Certification ceremony and record the failed check names.
- Running known certification ballots no longer advances the lifecycle prematurely.
- A passed sealing report is now the only controller path from Certification to Precinct Initialization.
- Package pass/tamper tests: 2 passed, 16 assertions.
- Certification domain and scenario regressions: 3 passed, 39 assertions.
- Certification page regressions: 4 passed, 56 assertions.
- Production frontend build: passed.

### Electoral Board and Inventory

- Added an authoritative precinct setup artifact under the preparation ceremony.
- Requires distinct Chairperson and Poll Clerk codes and PINs for dual-control approval.
- Binds the configured Chairperson, Poll Clerk, and Third Member identities to the active run using hashed officer codes.
- Records device, printer, and scanner serials; ballot stock range and quantity; ballot box and custody envelope identifiers; and at least two distinct seal numbers.
- Added a ceremony form and readiness status to Precinct Setup.
- Added the setup hash and dual-control result to certification sealing checks.
- Updated the deterministic sealing scenario to generate realistic setup evidence before sealing.
- Setup pass/rejection tests: 2 passed, 13 assertions.
- Sealing scenario regression: 1 passed, 24 assertions.
- Production frontend build: passed.

### Paper Ballot Lifecycle

- Added a hash-chained paper ballot ledger with one immutable event file per issuance, print, spoilage, and deposit.
- Voter QR payloads now include the serialized paper stock identity before payload hashing.
- Certification deck ballots remain explicitly unnumbered and do not consume election stock.
- Printed text and PDF artifacts show the paper ballot serial.
- Spoilage changes the corresponding stock disposition and accepted counting records the same serial as deposited.
- Tally output includes the paper ballot accounting summary.
- Voting and Printing pages expose issued, printed, spoiled, deposited, unused, and current serial information.
- The scenario activation helper now records the configured setup and stock before lifecycle actions.
- Ledger and print artifact tests: 2 passed, 22 assertions.
- Full demo and ledger regression: 2 passed, 14 assertions.
- Production frontend build: passed.

### Voter Station Separation

- Added dedicated voter ballot and voter completion routes and Vue pages.
- Voter page props contain only election, precinct, ballot style, contests, and candidates.
- Voter pages do not receive the operator snapshot, journal, diagnostics, lifecycle actions, evidence links, printing controls, spoilage controls, or counting controls.
- Finalized voter ballots return to a neutral completion screen with the physical paper stock serial.
- The operator Voting page detects the pending unprinted ballot and links separately to the printing ceremony.
- Voter isolation and operator handoff test: 1 passed, 40 assertions.
- Production frontend build: passed; voter pages compile as separate lightweight chunks without the ceremony layout.

### Public Counting and Adjudication

- Added an officer-authorized physical control count for paper ballots removed from the ballot box.
- Added one adjudication artifact per rejected scan with explicit dispositions for excluded paper ballots, duplicate scans, and non-ballot input.
- Counting reconciliation requires every rejected scan to be adjudicated.
- Tally completion is blocked until accepted ballots plus excluded physical ballots equal the declared box count.
- Counting legal evidence now embeds the reconciliation result, physical count, and unresolved rejection count.
- Counting page displays physical, represented, unresolved, and difference totals with operator forms for control count and adjudication.
- The deterministic legal counting scenario now records two physical ballots and adjudicates its spoiled ballot before completion.
- Reconciliation gate and scenario tests: 2 passed, 11 assertions.
- Counting feedback and legal-evidence regressions: 3 passed, 58 assertions.
- Production frontend build: passed.

### Return, Posting, and Custody

- Election Return generation through the operator controller now requires a passed physical ballot reconciliation.
- Added dual-control Election Return approval by the Chairperson and a distinct Poll Clerk.
- Approval binds the run ID, return hash, legal evidence hash, posting distribution hash, count-match result, and both officer identities.
- Official handoff remains blocked until approval passes.
- Delivery packages now require the return approval artifact and include seven tracked artifacts.
- Deterministic return, delivery, receipt, backup, and custody scenarios create approval before entering handoff.
- Custody records use the seal numbers inventoried during precinct setup instead of inventing unrelated seals.
- Return approval, handoff block, and delivery tests: 3 passed, 83 assertions.
- Delivery package and custody scenario regressions: 2 passed, 38 assertions.
- Production frontend build: passed.

## Current Implementation

- Current Wave: 1 (Foundation)
- Completed Slice: Special Polling Intake Slice (Slice 23)
- Next Slice: Future Delivery Drivers Slice (Slice 24) is deferred
- Test status:
    - `vendor/bin/pint --dirty --format agent` (pass)
    - `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='final-backup scenario command succeeds|lifecycle includes transmission, final backup, and custody stages' --compact` (pass)
    - `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --filter='transmission page can prepare and expose delivery package|transmission page can record manual handoff officer and recipient verification|transmission page can record delivery receipt only after recipient verification|transmission page requires final backup before custody transfer' --compact` (pass)
    - `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='delivery package scenario command succeeds' --compact` (blocked in this environment by Pest Browser socket bind restriction)
    - `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --filter='transmission page can prepare and expose delivery package' --compact` (blocked in this environment by Pest Browser socket bind restriction)
    - `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='manual-handoff scenario command succeeds' --compact` (blocked in this environment by Pest Browser socket bind restriction)
    - `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='delivery package scenario command succeeds|manual-handoff scenario command succeeds|delivery-receipt scenario command succeeds|lifecycle includes transmission, final backup, and custody stages' --compact` (blocked in this environment by Pest Browser socket bind restriction)
    - `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --filter='transmission page can record manual handoff officer and recipient verification|transmission page blocks recipient verification before officer verification' --compact` (blocked in this environment by Pest Browser socket bind restriction)
    - `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='open polls initialization report scenario command succeeds|open polls initialization scenario writes opening initialization report artifact' --compact`
    - `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --filter='voting page can run open polls with authorized officer and write opening initialization report|voting page rejects invalid officer pin for open polls' --compact`
    - `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='voting legal edge cases scenario blocks invalid lifecycle transitions' --compact` (blocked in this environment by Pest Browser socket bind restriction)
    - `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --filter='voting page rejects open polls from an invalid lifecycle stage|voting page cannot close polls before voting starts|voting page cannot finalize ballots before polls are active' --compact` (blocked in this environment by Pest Browser socket bind restriction)
    - `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='close polls and counting legal evidence scenario records both evidences' --compact` (blocked in this environment by Pest Browser socket bind restriction)
    - `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --filter='counting page exposes legal evidence summaries|counting completion is blocked outside counting stage|counting completion writes legal evidence and advances to election return' --compact` (blocked in this environment by Pest Browser socket bind restriction)
    - `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='legal scenario suite command succeeds|legal scenario suite includes electoral board baseline artifact|legal scenario suite creates an evidence reference baseline artifact|legal scenario suite includes electoral board baseline artifact|eb-role-baseline scenario writes an electoral board role baseline artifact|supply verification baseline scenario command succeeds|supply verification scenario creates supply verification baseline artifact' --compact`
    - `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --filter='provision page can run and display legal scenario suite harness|provision page can generate and display electoral board role baseline|provision page can generate and display supply verification baseline' --compact`
    - `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='manual verification passes with matching official return|manual verification fails when manual totals differ|friday certification scenario includes manual verification report' --compact` (blocked in this environment by Pest Browser socket bind restriction)
    - `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --filter='certification page can run certification and manual verification' --compact` (blocked in this environment by Pest Browser socket bind restriction)
    - `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='discrepancy report detects manual verification mismatch|fts manual verification discrepancy scenario records discrepancy report' --compact` (blocked in this environment by Pest Browser socket bind restriction)
    - `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --filter='certification page can run discrepancy analysis' --compact` (blocked in this environment by Pest Browser socket bind restriction)
    - `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='fts zero-out and sealing scenario clears counting artifacts' --compact` (blocked in this environment by Pest Browser socket bind restriction)
    - `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='election return legal artifact scenario runs deterministically|election return copy distribution scenario runs deterministically' --compact`
    - `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --filter='certification page can run zero-out and sealing' --compact` (blocked in this environment by Pest Browser socket bind restriction)
    - `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --filter='returns page exposes election return legal evidence summary|returns page can prepare copy distribution and show posting summary' --compact`
    - `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='final-backup scenario command succeeds' --compact` (pass)
    - `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='custody turnover scenario command succeeds' --compact` (pass)
    - `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='audit reconciliation baseline scenario command succeeds' --compact` (pass)
    - `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='special polling intake scenario records deterministic entries and hashes' --compact`
    - `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --filter='voting page can record special polling intake during voting and close-polls' --compact`
    - `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --filter='diagnostics can generate and download audit reconciliation baseline' --compact` (pass)
    - `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --filter='transmission page requires final backup before custody transfer' --compact` (blocked in this environment by Pest Browser socket bind restriction)
    - `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --filter='transmission page can record custody turnover report' --compact` (blocked in this environment by Pest Browser socket bind restriction)
    - `vendor/bin/pint --dirty --format agent`
    - `php -l` checks passed for all changed files
    - `php artisan election:scenario voting-legal-edge-cases` (pass)
    - `php artisan election:scenario close-polls-and-counting-legal-evidence` (pass)
    - `php artisan election:scenario initialization-report` (pass)
    - `php artisan election:scenario friday-certification` (pass)
- Known limitations: pest browser socket binding errors prevent some targeted feature commands from running in this environment when browser plugin is active.
- Remaining work for this slice: none after code updates.

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

- Slice 1: Lifecycle Legal States Slice (implemented)
- Slice 2: Legal Scenario Harness Slice (implemented)
- Slice 3: Evidence Reference Baseline Slice (implemented)
- Slice 4: Official Minutes Baseline Slice (implemented)
- Slice 5: EB Role Baseline Slice (implemented)
- Slice 6: Supply Verification Baseline Slice (implemented)
- Slice 7: FTS Diagnostics and Initialization Slice (implemented)
- Slice 8: FTS Test Ballots and Manual Verification Slice (implemented)
- Slice 9: FTS Discrepancy and Minutes Slice (implemented)
- Slice 10: Zero-Out and Sealing Slice (implemented)
- Slice 11: Election Day Setup and Open Polls Initialization Slice (implemented)
- Slice 12: Official Minutes Baseline Slice (implemented)
- Slice 13: Voting Legal Edge Cases Slice (implemented)
- Slice 14: Close Polls and Counting Legal Evidence Slice (implemented)
- Slice 15: Election Return Legal Artifact Slice (implemented)
- Slice 16: ER Copy Distribution and Posting Slice (implemented)
- Slice 17: Delivery Package Slice (implemented)
- Slice 18: Manual Handoff and Recipient Verification Slice (implemented)
- Slice 19: Delivery Receipt and Custody Transfer Slice (implemented)
- Slice 20: Final Backup After Delivery Slice (implemented)
- Slice 21: Custody Turnover and Custody Report Slice (implemented)
- Slice 22: Audit Reconciliation Baseline Slice (implemented)
- Slice 23: Special Polling Intake Slice (implemented)

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
- CI operations note for manually confirming controlled browser screenshot/log artifact uploads in GitHub Actions.
- QR decode is now behind an internal `QrCodeDecoder` adapter with `ZbarPngQrCodeDecoder` as the default implementation, preparing for a future pure PHP decoder without changing election services.
- QR decoder portability note records the no-bloat dependency criteria and evaluation checklist for any future pure PHP decoder.
- PDF artifacts now use a more structured deterministic layout with header band, subtitle, monospaced body text, separator lines, and source-of-truth footer.
- Evidence manifest entries now use an internal `EvidenceArtifact` value object for file name, relative path, size, and SHA-256 shaping.
- Officer registry management scaffold can rotate a local officer PIN into a runtime registry artifact and journal the rotation without adding authentication or an admin dashboard.
- Removable-media readiness reports now include operator-facing status codes and labels for simulated ready, ready, missing, not writable, probe failed, and not ready states.
- Scenario command reports now live inside run-first storage under `storage/app/election/runs/{run_id}/00-start-here`.
- Evidence folder scenario plan and compass are persisted in `docs/EVIDENCE_FOLDER_SCENARIO_PLAN.md` and `docs/EVIDENCE_FOLDER_SCENARIO_COMPASS.md`.
- Evidence folder scenario command is registered as `php artisan election:scenario evidence-folder-demo`.
- Evidence folder scenario now produces the standard run-first numbered ceremony folder layout under `storage/app/election/runs/{run_id}`.
- Run folders write `run-summary.json`, `run-summary.txt`, and `artifact-index.json` at the run root.
- Counting now writes deterministic `tally-sheet.txt` and `tally-sheet.pdf` artifacts directly into `06-counting-and-tally`.
- Storage reset now removes legacy `storage/app/election-scenario-reports` and `storage/app/election-scenario-artifacts` roots before recreating the run-first skeleton.
- POP workbook importer preserves the 2025 NLE POP XLSX as source evidence and normalizes clustered precinct rows into deterministic local registry files.
- POP workbook importer now uses a source adapter and explicit mapping profiles, with manifest metadata for source type, source label, source headers, mapping profile, and canonical fields.
- POP workbook importer includes a strict alternate Excel mapping profile and developer manual for adding future profiles.
- Lifecycle full-demo and evidence-folder scenarios now import configurable POP workbook defaults and include POP report sections.
- CLC candidate PDF importer uses reusable Ghostscript PDF text extraction, writes candidate registries, and includes placeholder candidate image metadata.
- Precinct candidate command combines imported POP precinct records with imported CLC candidate registries for ballot-facing candidate previews.
- Scenario POP defaults now use real Manila clustered precinct `39010001` in Tondo, with CLC precinct aliases mapping Manila POP subareas to `CITY OF MANILA` candidate contests.
- Active Tondo ballot packages are now generated from POP + CLC data instead of the embedded sample ballot definition, using `BS-2025NLE-39010001`.
- The active Tondo ballot includes Senator, Party List, Manila First Legislative District congressman, Manila Mayor, Manila Vice-Mayor, and Manila First District Councilor contests, and excludes President.
- Friday certification, full demo, and evidence folder scenarios now activate the actual Tondo POP + CLC ballot definition and include `ballot_definition` report sections.
- Voting finalization now accepts dynamic contest-keyed selections, and the operator ballot page includes per-contest candidate search for large Senator and Party List contests.
- Printed ballot artifacts, tally sheets, and Election Return artifacts now render human-readable contest and candidate labels while preserving deterministic candidate IDs in JSON evidence.
- POP registry lookup and imported precinct package skeleton creation are available through Artisan commands.
- POP import demo scenario is available as `php artisan election:scenario pop-import-demo`.
- POP importer documentation is available in `docs/POP_IMPORTER.md`.
- POP importer developer manual is available in `docs/POP_IMPORTER_DEVELOPER_MANUAL.md`.
- CLC candidate importer documentation is available in `docs/CLC_CANDIDATE_IMPORTER.md`.
- PDF text extraction documentation is available in `docs/PDF_TEXT_EXTRACTION.md`.
- Run-first storage is now the operator-facing evidence layout, with `LATEST_RUN.txt` pointing to the current run and source imports under `storage/app/election/source-data`.
- Artisan scenarios:
    - `php artisan election:scenario friday-certification`
    - `php artisan election:scenario full-demo`
    - `php artisan election:scenario legal-suite`
    - `php artisan election:scenario evidence-folder-demo`

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
    - legal scenario suite command success
    - Home Inertia component render
    - simulated device adapter certification report
    - CUPS printer health adapter selection and not-configured behavior
    - CUPS ballot printer submission and failed-submission evidence retention
    - CUPS ballot printer certification gate
    - handheld scanner health adapter selection and not-configured behavior
    - manual and handheld scan normalization before counting
    - camera scanner health adapter selection, not-configured behavior, and QR PNG image data URI decode before counting
    - officer attestation artifact, signature artifact, journal event, local registry metadata, invalid PIN rejection, and invalid signature rejection
    - local officer PIN rotation runtime registry artifact and journal event
    - removable-media evidence export verification success path and tampered artifact mismatch command failure
    - downloadable TAR evidence bundle archive verification success path and tampered archive mismatch command failure
    - scenario command durable report archiving outside resettable election runtime
    - evidence folder demo scenario command registration
    - evidence folder demo numbered folder and artifact index generation
    - evidence folder demo summary report output
    - evidence folder demo tally sheet text and PDF artifacts
    - evidence folder demo evidence folder content, pointer, hash, and persistence verification
    - evidence reference baseline creation within legal-suite scenario
    - official minutes baseline creation within legal-suite scenario
    - legal scenario suite includes electoral board baseline
    - eb-role-baseline scenario writes electoral board role baseline artifact
    - supply-verification-baseline scenario command success
    - supply-verification-baseline scenario persists its own baseline artifact
    - initialization report scenario command succeeds
    - initialization report scenario writes initialization report artifact
    - open polls initialization report scenario command succeeds
    - open polls initialization scenario writes opening initialization report artifact
    - manual verification passes with matching official return
    - manual verification fails when manual totals differ
    - friday certification scenario includes manual verification report
    - fts zero-out and sealing scenario clears counting artifacts
    - election return legal evidence artifact is generated from return
    - election return legal artifact scenario runs deterministically
    - election return copy distribution scenario runs deterministically
    - delivery package scenario command runs deterministically
    - manual handoff scenario command runs deterministically
    - delivery receipt scenario command runs deterministically
    - final-backup scenario command succeeds
    - custody turnover scenario command succeeds
    - audit reconciliation baseline scenario command succeeds
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
    - removable-media readiness status/status label projection for simulated and missing targets
    - Diagnostics evidence bundle archive build, TAR content smoke check, download route, and journal event
    - Diagnostics downloadable TAR evidence bundle archive verification action, persisted report projection, and journal event
    - Diagnostics returned TAR archive upload verification action, staged upload artifact, source metadata projection, and journal event
    - Diagnostics evidence reference baseline generation, summary projection, and download route
    - Diagnostics official minutes baseline generation, summary projection, and download route
    - Diagnostics audit reconciliation baseline generation, summary projection, and download route
    - Provision page can generate and display electoral board baseline report
    - Provision page can run and display legal scenario suite harness
    - Provision page can generate and display supply verification baseline
    - Diagnostics can generate and download initialization report
    - Voting page can run open polls with authorized officer and write opening initialization report
    - Voting page rejects invalid officer pin for open polls
    - certification page can run certification and manual verification
    - certification page can download manual verification report artifact
    - certification page can run zero-out and sealing
    - transmission page can prepare and expose delivery package
    - transmission page can record manual handoff officer and recipient verification
    - transmission page can record delivery receipt only after recipient verification
    - transmission page blocks recipient verification before officer verification
    - returns page exposes election return legal evidence summary
    - returns page can prepare copy distribution and show posting summary
    - transmission page renders
    - transmission page can prepare and expose delivery package
    - transmission page can record manual handoff officer and recipient verification
    - transmission page blocks recipient verification before officer verification
    - election lifecycle includes manual-handoff scenario command success
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
- `docs/CI_OPERATIONS.md`
    - manual controlled browser artifact check procedure and expected artifact names
    - missing-artifact troubleshooting checklist
- `docs/QR_DECODER_PORTABILITY.md`
    - adapter boundary summary, dependency acceptance criteria, and verification checklist for any pure PHP QR decoder candidate
- `docs/PDF_ARTIFACTS.md`
    - current PDF layout, structural verification, and pending Poppler render-check procedure
- `app/Election/Diagnostics/EvidenceArtifact.php`
    - internal value object for deterministic evidence manifest entries without introducing a media package
- Updated the starter `tests/Feature/ExampleTest.php` to use `withoutVite()` for server-side test stability.

## Commands Run

- `php artisan test --compact`
- `vendor/bin/pest tests/Browser --compact`
- `php artisan test --compact --filter='fifty ballot field scenario' tests/Feature/Election/ElectionLifecycleTest.php`
- `php artisan election:scenario field-50-ballots`
- `vendor/bin/pint --dirty --format agent`
- `npm run lint:check`
- `npm run types:check`
- `npm run format:check`
- `npm run build`
- `composer validate --strict`

- `php artisan wayfinder:generate --with-form --no-interaction`
- `composer require bacon/bacon-qr-code --no-interaction`
- `composer require pestphp/pest-plugin-browser --dev --no-interaction`
- `npm install --save-dev playwright@latest`
- `npx playwright install chromium`
- `php artisan test --compact tests/Feature/Election/ElectionLifecycleTest.php`
- `php artisan test --compact tests/Feature/Election/ElectionPagesSmokeTest.php`
- `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --compact`
- `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --compact`
- `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='delivery package scenario command succeeds' --compact` (environment-restricted by Pest browser socket bind)
- `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --filter='transmission page can prepare and expose delivery package|transmission page renders' --compact` (environment-restricted by Pest browser socket bind)
- `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='manual-handoff scenario command succeeds' --compact` (environment-restricted by Pest browser socket bind)
- `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='delivery package scenario command succeeds|manual-handoff scenario command succeeds|delivery-receipt scenario command succeeds|lifecycle includes transmission, final backup, and custody stages' --compact` (environment-restricted by Pest browser socket bind)
- `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --filter='transmission page can record manual handoff officer and recipient verification|transmission page blocks recipient verification before officer verification' --compact` (environment-restricted by Pest browser socket bind)
- `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --filter='transmission page can prepare and expose delivery package|transmission page can record manual handoff officer and recipient verification|transmission page can record delivery receipt only after recipient verification|transmission page blocks recipient verification before officer verification' --compact` (environment-restricted by Pest browser socket bind)
- `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='manual verification passes with matching official return|manual verification fails when manual totals differ|friday certification scenario includes manual verification report' --compact` (environment-restricted by Pest browser socket bind)
- `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --filter='certification page can run certification and manual verification' --compact` (environment-restricted by Pest browser socket bind)
- `php artisan election:scenario initialization-report` (pass)
- `php artisan election:scenario friday-certification` (pass)
- `vendor/bin/pint --dirty --format agent`
- `npm run format -- resources/js/pages/Election/Diagnostics.vue`
- `vendor/bin/pest --compact`
- `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='legal scenario suite command succeeds|legal scenario suite creates an evidence reference baseline artifact|legal scenario suite includes electoral board role baseline artifact|eb-role-baseline scenario writes an electoral board role baseline artifact' --compact`
- `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --filter='electoral board role baseline' --compact`
- `vendor/bin/pint --dirty --format agent`
- `vendor/bin/pest tests/Feature/Election/ClcCandidateImportTest.php --compact`
- `vendor/bin/pest tests/Feature/Election/PrecinctCandidatesCommandTest.php --compact`
- `php artisan election:clc-import`
- `php artisan election:precinct-candidates 39010001 --district="FIRST DIST" --write-report`
- `vendor/bin/pint --dirty --format agent`
- `vendor/bin/pest tests/Feature/Election/PopWorkbookImportTest.php --compact`
- `vendor/bin/pint --dirty --format agent`
- `vendor/bin/pest tests/Feature/Election/PopWorkbookImportTest.php --compact`
- `vendor/bin/pest --compact`
- `vendor/bin/pint --dirty --format agent`
- `vendor/bin/pest tests/Feature/Election/PopWorkbookImportTest.php --compact`
- `vendor/bin/pint --dirty --format agent`
- `vendor/bin/pest tests/Feature/Election/PopWorkbookImportTest.php --compact`
- `php artisan election:pop-import resources/election/pop/2025NLE_POP.xlsx`
- `php artisan election:pop-lookup 7010001`
- `php artisan election:pop-activate 7010001`
- `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='legal scenario suite creates|EvidenceReferenceBaseline' --compact`
- `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --filter='evidence reference baseline' --compact`
- `php artisan election:pop-lookup 39010001`
- `php artisan election:pop-activate 39010001`
- `php artisan election:scenario pop-import-demo`
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
- `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --compact`
- `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --compact`
- `vendor/bin/pest --compact`
- `composer validate --strict`
- `ruby -e 'require "yaml"; YAML.load_file(".github/workflows/tests.yml")'`
- Documentation-only slice; no additional test command required.
- `ruby -e 'require "yaml"; YAML.load_file(".github/workflows/tests.yml")'`
- `ruby -e 'require "yaml"; YAML.load_file(".github/workflows/tests.yml")'`
- Documentation-only CI operations slice; no test command required.
- Documentation-only QR decoder portability slice; no dependency or test command required.
- `vendor/bin/pint --dirty --format agent`
- `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --compact`
- `php artisan election:scenario friday-certification`
- `php artisan election:scenario full-demo`
- `vendor/bin/pest --compact`
- `vendor/bin/pint --dirty --format agent`
- `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --compact`
- `php artisan election:scenario evidence-folder-demo`
- `vendor/bin/pest --compact`
- `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='official minutes|evidence reference baseline|legal scenario suite creates' --compact`
- `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --filter='official minutes baseline|evidence reference baseline|diagnostics can generate and download official minutes baseline' --compact`
- `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --filter='fts zero-out and sealing scenario clears counting artifacts' --compact` (environment-restricted by Pest browser socket bind)
- `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --filter='certification page can run zero-out and sealing' --compact` (environment-restricted by Pest browser socket bind)

## Verification Results

- Browser walkthrough isolation suite: passed, 3 tests and 10 assertions.
- Browser walkthrough requests now require a short-lived hashed local token and are bound to the active rehearsal run.
- Invalid tokens, changed rehearsal pointers, concurrent walkthroughs, and completed tokens fail closed.
- Walkthrough request configuration is restored after every response so rehearsal context cannot leak into ordinary operator requests.
- Final PHP feature/unit suite: passed, 160 tests and 2,402 assertions.
- Final Pest Browser suite: passed, 7 tests and 109 assertions.
- Final 50-ballot scenario after ceremony storage correction: passed, 1 test and 40 assertions.
- Frontend ESLint, Vue TypeScript, Prettier, and Vite production build: passed.
- Composer strict validation and Laravel Pint: passed.
- Persisted Tondo field rehearsal: `storage/app/election/runs/20260508-080000-39010001-field-50-ballots`.
- Persisted operator summary: `storage/app/election/runs/20260508-080000-39010001-field-50-ballots/run-summary.txt`.
- Persisted contextual scenario report: `storage/app/election/runs/20260508-080000-39010001-field-50-ballots/00-start-here/2026-05-08-080051-39010001-field-50-ballots-e049092c3375-report.json`.
- Persisted rehearsal statistics: 50 voters, 52 issued and printed paper ballots, 2 spoiled ballots, 50 deposited and accepted ballots, 1 rejected duplicate scan, 1 adjudication, 216 journal entries, and 546 archive files verified.
- The rehearsal contains 558 files and all six summary gates pass: restart recovery, paper accounting, counting reconciliation, return dual approval, audit reconciliation, and archive verification.
- Closing legal evidence now routes to `05-closing-of-polls`; counting legal evidence now routes to `06-counting-and-tally`.
- The election-day pointer remains `storage/app/election/runs/20260724-004938-0421-a-operator`; the rehearsal did not replace or reset it.
- The hidden legacy operator-side ballot form was removed; the Voting ceremony now exposes only the isolated voter-station handoff.
- Appliance recovery focused suite: passed, 3 tests and 15 assertions.
- Appliance recovery production frontend build: passed.
- `election:recover` now verifies the active run, precinct identity, lifecycle stage, append-only activity journal chain, and serialized paper-ballot ledger chain without changing the active ceremony.
- Diagnostics now shows restart readiness, evidence check results, the recovered run and ceremony, and degraded device status.
- Deterministic 50-ballot field scenario focused suite: passed, 1 test and 36 assertions.
- `php artisan election:scenario field-50-ballots` now runs the configured Tondo POP + CLC ballot from final testing and sealing through Audit.
- The field scenario serves 50 voters, issues and prints 52 serialized paper ballots, spoils 2 originals, accepts 50 deposited ballots, rejects and adjudicates 1 duplicate scan, and reconciles against a physical count of 50.
- The field scenario performs a mid-voting appliance recovery inspection and completes dual Election Return approval, official handoff, final backup, custody turnover, audit reconciliation, and deterministic TAR verification.
- The field report includes statistics, integrity gates, hashes, and direct paths to the ceremony artifacts.
- `run-summary.json` and `run-summary.txt` now carry scenario statistics, checks, and evidence file pointers; the text summary is designed for direct operator inspection.

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
- Slice 4 focused feature attempts for official minutes baseline: failed in this environment due Pest Browser socket restrictions (`socket_create_listen` / `PortNotFoundException`) during host bind.
- Slice 10 focused lifecycle and certification smoke coverage: blocked in this environment due Pest Browser `PortNotFoundException` while binding test socket.
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
- Focused Pest lifecycle suite after QR decoder adapter extraction: passed, 30 tests and 143 assertions.
- Pest configured feature/unit suite after QR decoder adapter extraction: passed, 59 tests and 587 assertions.
- Focused Pest lifecycle suite after PDF layout pass: passed, 30 tests and 148 assertions.
- Pest configured feature/unit suite after PDF layout pass: passed, 59 tests and 592 assertions.
- Focused Pest ceremony page suite after EvidenceArtifact extraction: passed, 27 tests and 442 assertions.
- Pest configured feature/unit suite after EvidenceArtifact extraction: passed, 59 tests and 592 assertions.
- Focused Pest lifecycle suite after officer registry scaffold: passed, 31 tests and 154 assertions.
- Pest configured feature/unit suite after officer registry scaffold: passed, 60 tests and 598 assertions.
- POP importer mapper/profile focused suite: passed, 9 tests and 78 assertions.
- Pest configured feature/unit suite after POP importer mapper/profile extraction: passed, 71 tests and 897 assertions.
- POP importer alternate strict profile focused suite: passed, 11 tests and 94 assertions.
- Pest configured feature/unit suite after alternate POP profile: passed, 73 tests and 913 assertions.
- POP-backed lifecycle focused suite: passed, 34 tests and 415 assertions.
- POP importer repository fixture focused suite: passed, 11 tests and 94 assertions.
- Pest configured feature/unit suite after POP-backed lifecycle scenarios: passed, 74 tests and 965 assertions.
- POP-backed full demo scenario: passed and wrote `storage/app/election-scenario-reports/2026-05-08-080001-7010001-full-demo-5f06678dc00b-report.json`.
- POP-backed evidence folder scenario: passed and wrote `storage/app/election-scenario-artifacts/2026-05-08-080001-7010001-evidence-folder-demo-36e5247059eb`.
- CLC candidate importer focused suite: passed, 2 tests and 26 assertions.
- Precinct candidates command focused suite: passed, 3 tests and 28 assertions.
- Pest configured feature/unit suite after CLC candidate import: passed, 79 tests and 1019 assertions.
- CLC candidate import command: passed with 21 sources, 1329 de-duplicated candidates, and 1 needs-review row.
- Precinct candidate report command for `7010001`: passed with 2 contests and 339 candidates.
- POP lookup command for Manila precinct `39010001`: passed and returned NCR / NCR - MANILA / TONDO / BARANGAY 1 / ISABELO DELOS REYES ELEMENTARY SCHOOL.
- POP package activation command for `39010001`: passed and wrote `storage/app/election/packages/imported/39010001.json`.
- Precinct candidate report command for `39010001 --district="FIRST DIST"`: passed with 6 contests and 387 candidates.
- Manila-backed POP import demo scenario: passed and wrote `storage/app/election-scenario-reports/2026-05-08-080000-39010001-pop-import-demo-cc8b95e8adf4-report.json`.
- Manila-backed full demo scenario: passed and wrote `storage/app/election-scenario-reports/2026-05-08-080001-39010001-full-demo-0be3d5020a77-report.json`.
- Manila-backed evidence folder scenario: passed and wrote `storage/app/election-scenario-artifacts/2026-05-08-080001-39010001-evidence-folder-demo-3a8c4189edbb`.
- POP importer focused suite after Manila default: passed, 11 tests and 94 assertions.
- Precinct candidate focused suite after Manila default: passed, 3 tests and 31 assertions.
- Lifecycle focused suite after Manila default: passed, 34 tests and 415 assertions.
- Pest configured feature/unit suite after Manila default: passed, 79 tests and 1022 assertions.
- Focused Pest ceremony page suite after removable-media readiness status labels: passed, 27 tests and 454 assertions.
- TypeScript after removable-media readiness status labels: passed.
- Pest configured feature/unit suite after removable-media readiness status labels: passed, 60 tests and 610 assertions.
- Pest configured feature/unit suite after browser smoke addition: passed, 58 tests and 582 assertions.
- GitHub Actions workflow YAML parse: passed.
- GitHub Actions browser backend-log artifact workflow YAML parse: passed.
- GitHub Actions controlled browser artifact failure workflow YAML parse: passed.
- CI operations note added; remote controlled artifact confirmation is an operator-run GitHub Actions procedure.
- QR decoder portability note added; no pure PHP decoder dependency selected yet.
- Scenario report archive focused lifecycle suite: passed, 32 tests and 170 assertions.
- Persisted Friday certification report: `storage/app/election-scenario-reports/2026-05-08-080001-0421-a-friday-certification-5b1f4f08f5d7-report.json`.
- Persisted full demo report: `storage/app/election-scenario-reports/2026-05-08-080001-0421-a-full-demo-3de8e721eadb-report.json`.
- Pest configured feature/unit suite after scenario report archiving: passed, 61 tests and 626 assertions.
- Evidence folder scenario final Pint run: passed.
- Evidence folder scenario focused lifecycle suite: passed, 33 tests and 363 assertions.
- Evidence folder scenario command: passed.
- Evidence folder: `storage/app/election-scenario-artifacts/2026-05-08-080001-0421-a-evidence-folder-demo-4dedf7c65e38`.
- Evidence folder summary report: `storage/app/election-scenario-artifacts/2026-05-08-080001-0421-a-evidence-folder-demo-4dedf7c65e38/summary-report.json`.
- Evidence folder durable scenario report: `storage/app/election-scenario-reports/2026-05-08-080001-0421-a-evidence-folder-demo-855437357857-report.json`.
- Evidence folder statistics: 6 scan documents, 2 passed device checks, 3 certification ballots, 2 attestations, 2 signatures, 2 finalized ballots, 2 printed ballots, 1 spoiled ballot, 1 accepted ballot, 1 rejected ballot, 3 contests tallied, 28 journal entries, 36 evidence files copied, and 275451 evidence bytes copied.
- Pest configured feature/unit suite after evidence folder scenario completion: passed, 62 tests and 819 assertions.
- POP importer focused suite: passed, 3 tests and 41 assertions.
- POP import demo scenario focused suite: passed, 4 tests and 52 assertions.
- POP workbook import command: passed with 93629 rows, 93629 unique clustered precincts, 69773653 total registered voters, and registry hash `eb102e2c5b4497f676bfbbb4c5d381cd9d2bbd91c037a69cc8f894080292d0e1`.
- POP lookup command for `7010001`: passed and returned BARMM / BASILAN / CITY OF ISABELA / ISABELA PROPER / ISABELA PROPER BARANGAY HALL.
- POP package activation command for `7010001`: passed and wrote `storage/app/election/packages/imported/7010001.json`.
- POP import demo scenario: passed and wrote `storage/app/election-scenario-reports/2026-05-08-080000-7010001-pop-import-demo-51fd2c0f3bec-report.json`.
- Pest configured feature/unit suite after POP importer completion: passed, 66 tests and 871 assertions.
- Run-first storage lifecycle suite: passed, 34 tests and 407 assertions.
- Run-first storage ceremony page suite: passed, 27 tests and 454 assertions.
- Run-first storage POP importer suite: passed, 11 tests and 94 assertions.
- Run-first storage precinct candidates suite: passed, 3 tests and 31 assertions.
- Run-first storage CLC importer suite: passed, 2 tests and 26 assertions.
- Pest configured feature/unit suite after run-first storage rationalization: passed, 79 tests and 1014 assertions.
- Pest Browser suite after run-first storage rationalization: passed, 12 tests and 83 assertions.
- Run-first POP import demo scenario: passed and wrote `storage/app/election/runs/20260508-080000-39010001-pop-import-demo`.
- Run-first full demo scenario: passed and wrote `storage/app/election/runs/20260508-080000-39010001-full-demo`.
- Run-first evidence folder demo scenario: passed and wrote `storage/app/election/runs/20260508-080000-39010001-evidence-folder-demo`.
- Legacy generated roots `storage/app/election-scenario-reports` and `storage/app/election-scenario-artifacts` are removed by reset and no longer used by active writers.
- Tondo POP + CLC ballot definition focused suite: passed, 3 tests and 39 assertions.
- Tondo scenario/certification focused suite: passed, 4 tests and 50 assertions.
- Tondo artifact label focused suite: passed, 4 tests and 39 assertions.
- Tondo failure-regression focused suite for print/certification/evidence-folder paths: passed, 6 tests and 255 assertions.
- Frontend production build after dynamic voting UI: passed with Wayfinder route/type generation.
- Pint after Tondo POP + CLC ballot integration: passed.
- Pest configured feature/unit suite after Tondo POP + CLC ballot integration: passed, 82 tests and 1065 assertions.
- Tondo Friday certification scenario: passed and wrote `storage/app/election/runs/20260508-080000-39010001-friday-certification`.
- Tondo full demo scenario: passed and wrote `storage/app/election/runs/20260508-080000-39010001-full-demo`.
- Tondo evidence folder scenario: passed and wrote `storage/app/election/runs/20260508-080000-39010001-evidence-folder-demo`.
- Latest Tondo evidence folder report: `storage/app/election/runs/20260508-080000-39010001-evidence-folder-demo/00-start-here/2026-05-08-080001-39010001-evidence-folder-demo-6d91a111bf39-report.json`.
- Latest Tondo evidence folder summary: `storage/app/election/runs/20260508-080000-39010001-evidence-folder-demo/run-summary.json`.
- Latest Tondo evidence folder artifact index: `storage/app/election/runs/20260508-080000-39010001-evidence-folder-demo/artifact-index.json`.
- Slice 11 focused lifecycle coverage: passed, 2 tests and 18 assertions (`open-polls-initialization-report` scenario path).
- Slice 11 focused Inertia smoke coverage: passed, 2 tests and 25 assertions.
- Run folder rationalization now uses General Instructions ceremony names from `00-start-here` through `13-journal`.
- Generated run folders now include `README.md` at the run root and in `00-start-here`, explaining directory purpose, common files, summary files, and artifact index usage for election workers.
- Generated run folders now include a ceremony-specific `README.md` in every numbered directory from `00-start-here` through `13-journal`.
- Transmission/handoff artifacts now route to `08-transmission-or-official-handoff` instead of the old generic exports folder.
- Final backup artifacts now route to `09-final-backup`.
- Custody records and custody turnover reports now route to `10-custody-turnover`.
- Diagnostics, evidence manifests, removable-media exports, downloaded archives, and verification reports now route to `12-audit-and-reconciliation`.
- Journal artifacts now route to `13-journal`.
- Run folder rationalization focused evidence-folder scenario test: passed, 1 test and 238 assertions.
- Run folder rationalization diagnostics archive/export focused tests: passed, 2 tests and 58 assertions.
- Final backup/custody focused lifecycle tests after run folder rationalization: passed, 2 tests and 21 assertions.
- Evidence folder demo scenario after run folder rationalization: passed and wrote `storage/app/election/runs/20260508-080000-39010001-evidence-folder-demo`.
- Custody turnover scenario after run folder rationalization: passed and wrote `storage/app/election/runs/20260508-080000-39010001-custody-turnover`.
- Full Pest suite after run folder rationalization was started but did not complete in this sandbox run; it was interrupted after remaining silent for several minutes. Focused storage, export, final-backup, custody, and scenario checks above passed.
- Per-directory README focused evidence-folder scenario test: passed, 1 test and 316 assertions.
- Per-directory README evidence folder scenario command: passed and wrote `storage/app/election/runs/20260508-080000-39010001-evidence-folder-demo`.

## Known Gaps

- QR decoding currently uses the local `zbarimg` binary; a pure PHP or packaged decoder adapter may be preferable for deployment portability.
- PDF ballot and Election Return artifacts are generated with a simple internal PDF renderer.
- Poppler (`pdftoppm`, `pdfinfo`) was not available in the local environment for PNG render verification during the PDF layout pass.
- Printer health certification can probe CUPS status when configured, and CUPS ballot submission is available behind an opt-in driver only after matching certification. File artifact printing remains the default and no ESC/POS output is implemented.
- Scanner certification and scanning are adapter-driven for manual, handheld keyboard-wedge, and camera/image QR workflows. Browser camera capture is scaffolded for the Counting ceremony.
- Browser camera capture requires operator browser permission and a secure origin as enforced by the browser.
- Officer attestation uses a local deterministic PIN registry and PNG signature artifacts; no identity proofing workflow yet.
- Officer PIN rotation is local and artifact-backed; there is still no officer identity proofing or multi-person approval workflow.
- Removable-media export is currently simulated as a local staging directory by default; configured physical targets are readiness-checked but not auto-mounted or write-protection-aware yet.
- Evidence bundle archive downloads are currently uncompressed deterministic TAR files.
- Downloaded archive verification currently supports the appliance-generated uncompressed TAR format only.
- Returned archive upload verification stages uploaded TAR files locally before verification; no malware scanning or external media provenance workflow is implemented yet.
- Browser tests use Pest Browser and Playwright. The Pest Browser Laravel request bridge does not currently forward multipart file uploads, so the upload verification route also accepts a base64 TAR payload for browser-level workflow coverage.
- Pest Browser requires a locally installed Chromium runtime and permission to bind its test server socket.
- SQLite read models are not introduced.
- x-journal, x-change, and x-feedback are intentionally not integrated.
- Recovery inspection is implemented on the active appliance, but automated failover to a separately provisioned backup appliance is not implemented.
- `election:browser-walkthrough full-election` now records the complete ceremony-driven browser rehearsal from precinct activation through verified audit archive.

## Recorded Browser Walkthrough

- Added `php artisan election:browser-walkthrough full-election` with local-target validation, configurable ballot count, headed mode, and action delay.
- Browser walkthrough requests use a short-lived token passed through the child-process environment and bind only to the isolated rehearsal run.
- Successful and failed walkthroughs are locked and finalized with a browser report, run summary, and SHA-256 artifact index.
- Playwright writes `full-election.webm`, `playwright-trace.zip`, numbered screenshots, `action-log.jsonl`, and `recording-metadata.json` under `12-audit-and-reconciliation/browser-recordings`.
- Focused coordinator coverage passed: 3 tests and 21 assertions.
- Real smoke recording passed against `http://aes.test`.
- Persisted smoke run: `storage/app/election/runs/20260724-035446-826147-39010001-browser-full-election`.
- The election-day pointer remained `storage/app/election/runs/20260724-004938-0421-a-operator`.
- Browser provisioning-through-opening now drives the real precinct package import, dual-control setup, EB and supply baselines, device readiness, initialization report, certification, manual verification, discrepancy analysis, zero-out, sealing, two officer signatures, and two-step poll opening.
- Ghostscript executable discovery now checks the configured path and standard appliance/Homebrew binary locations so the web process can import CLC PDFs consistently.
- The voter printing queue now excludes FTS certification scan documents while evidence counts continue to include them.
- Focused PDF adapter and walkthrough coordinator coverage passed: 6 tests and 50 assertions.
- Focused voter isolation and FTS ballot regression coverage passed: 2 tests and 52 assertions.
- Real browser provisioning-through-opening recording passed with 17 completed actions, 15 screenshots, 2 attestations, 2 signature artifacts, and no browser console errors.
- Persisted provisioning-through-opening run: `storage/app/election/runs/20260724-041438-938925-39010001-browser-full-election`.
- Persisted recording: `storage/app/election/runs/20260724-041438-938925-39010001-browser-full-election/12-audit-and-reconciliation/browser-recordings/full-election.webm`.
- The lifecycle reached `voting`; the election-day pointer remained unchanged.
- Browser voting now selects deterministic candidates in every real Tondo contest, finalizes ballots through the isolated voter screen, opens the operator printing ceremony, and produces file/PDF print artifacts.
- Each walkthrough creates one deliberately spoiled printed ballot before producing the configured number of valid replacement ballots.
- QR payloads from valid and spoiled printed ballots remain in the recorder session for the counting and rejection segment.
- Real browser voting/printing/spoilage recording passed with 23 completed actions, 21 screenshots, 2 finalized ballots, 2 print records, 1 spoiled ballot, 5 paper-ledger events, and no browser console errors.
- Persisted voting/printing checkpoint: `storage/app/election/runs/20260724-041908-002535-39010001-browser-full-election`.
- Counting adjudication now supports `spoiled-ballot-separated` for a real spoiled paper ballot kept in its envelope outside the ballot box.
- Browser counting closes polls, submits each valid QR payload, confirms spoiled QR rejection, records officer adjudication, records the physical ballot count, verifies reconciliation, and completes the tally.
- Focused counting reconciliation coverage passed: 2 tests and 30 assertions.
- Vue type checking and the production frontend build passed.
- Real browser counting recording passed with 30 completed actions, 27 screenshots, 1 accepted ballot file, 1 rejected scan, 1 adjudication, balanced paper accounting, closing/counting legal evidence, and no browser console errors.
- Persisted counting checkpoint: `storage/app/election/runs/20260724-042942-423131-39010001-browser-full-election`.
- Browser returns now generate the Election Return, prepare the prescribed copies and posting record, obtain distinct Chairperson and Poll Clerk approval, and enter official handoff.
- Browser handoff now prepares the deferred transmission report and delivery package, records officer and recipient verification, generates the delivery receipt, records final backup and custody turnover, and closes the precinct.
- Browser audit now opens the final ceremony, generates evidence-reference, official-minutes, and audit-reconciliation baselines, regenerates the evidence manifest, builds the downloadable archive, and verifies the built TAR.
- Real complete browser recording passed with 50 completed actions, 98 action records, 46 screenshots, 1 accepted ballot, 1 rejected and adjudicated spoiled scan, no browser console messages, and a verified evidence archive.
- Persisted complete run: `storage/app/election/runs/20260724-044219-807626-39010001-browser-full-election`.
- Persisted complete video: `storage/app/election/runs/20260724-044219-807626-39010001-browser-full-election/12-audit-and-reconciliation/browser-recordings/full-election.webm`.
- The lifecycle reached `audit`; the election-day pointer remained unchanged.
- Added `browser-lifecycle-report.json` and a plain-text companion that summarize eight ceremony groups, action completion, statistics, numbered evidence folders, and principal artifact pointers.
- Added `browser-artifact-index.json` with SHA-256 hashes for the finished video, trace, screenshots, action log, recording metadata, browser report, and lifecycle reports.
- Browser walkthrough finalization now rebuilds the evidence manifest after the browser closes, creates a final downloadable TAR containing the completed recording artifacts, verifies that TAR, and writes `browser-walkthrough-completion.json`.
- Evidence manifests exclude generated TAR files and archive report/verification records, preventing recursive archive growth during rebuilds.
- Replaced in-memory TAR construction and verification with streaming file I/O after the first 117 MB recording exposed the appliance memory limit.
- The preserved interrupted 117.6 MB run was recovered, verified across 152 files, and locked with its interruption recorded.
- Focused coordinator and archive coverage passed: 6 tests and 55 assertions.
- Final complete run passed with 50 completed browser actions, 46 screenshots, 53 indexed browser artifacts, 161 run artifacts, and no browser console messages.
- Final 120.2 MB evidence TAR verified 152 files with zero mismatches and contains the WebM, Playwright trace, browser report, lifecycle report, and browser artifact index.
- Persisted finalized run: `storage/app/election/runs/20260724-045338-420890-39010001-browser-full-election`.
- Persisted lifecycle report: `storage/app/election/runs/20260724-045338-420890-39010001-browser-full-election/12-audit-and-reconciliation/browser-recordings/browser-lifecycle-report.txt`.
- Persisted final evidence archive: `storage/app/election/runs/20260724-045338-420890-39010001-browser-full-election/12-audit-and-reconciliation/evidence-bundle-20260724-045458.tar`.
- The election-day pointer remains `storage/app/election/runs/20260724-004938-0421-a-operator`.
- Browser walkthrough controls now record the coordinator process ID and lease start.
- A second invocation still refuses to overlap a live local coordinator.
- When the prior coordinator process is dead or its lease expired, the next invocation writes an interruption completion record and recovery report, locks and finalizes the interrupted rehearsal, and only then creates the replacement run.
- Recovery preserves an already completed result when interruption happened after completion, while a run without completion evidence fails closed.
- Interruption recovery, isolation, and coordinator coverage passed: 7 tests and 56 assertions.
- Added `docs/BROWSER_WALKTHROUGH_OPERATOR_MANUAL.md` with prerequisites, command options, ceremony flow, run directory guide, browser artifact descriptions, TAR verification commands, recovery behavior, and an acceptance checklist.
- Linked the operator manual from `docs/BROWSER_TESTING_WORKFLOW.md` while keeping CI/browser-test instructions separate.
- Verified both documented Artisan commands and the configured default POP source path; both browser documents pass Prettier formatting.
- Full Laravel/Pest regression passed: 170 tests and 2,503 assertions.
- Dedicated Pest Browser/Playwright regression passed: 7 tests and 109 assertions.
- Vue TypeScript checking passed.
- ESLint passed.
- Production Vite build passed with 618 modules transformed.
- Composer strict validation passed.
- Browser recorder Node syntax check passed.
- Browser walkthroughs now generate an offline HTML storyboard, a print-ready landscape PDF, and structured JSON from the exact recorded screenshot sequence.
- Every storyboard checkpoint separates the Electoral Board act, appliance control, COMELEC review point, and underlying evidence paths, and records the screenshot SHA-256.
- Storyboard generation is required for a successful recording, and the coordinator includes the storyboard files in the final verified evidence TAR.
- The recorder also preserves 46 readable viewport frames from the same moments as the 46 full-page captures; the storyboard uses the frames and links to the complete screenshots.
- Final storyboard rehearsal passed with 51 completed actions, 46 screenshots, 46 storyboard frames, 2 printed ballots, 1 spoiled ballot, 1 accepted ballot, and no browser console messages.
- Persisted storyboard run: `storage/app/election/runs/20260724-085149-521534-39010001-browser-full-election`.
- Persisted storyboard PDF: `storage/app/election/runs/20260724-085149-521534-39010001-browser-full-election/12-audit-and-reconciliation/browser-recordings/walkthrough-storyboard.pdf`.
- Persisted final archive: `storage/app/election/runs/20260724-085149-521534-39010001-browser-full-election/12-audit-and-reconciliation/evidence-bundle-20260724-085312.tar`.
- Final archive verified 201 files with zero mismatches; storyboard evidence-path validation reported no missing references.
- Representative PDF pages were rendered and visually inspected for setup, ballot printing, counting, Election Return approval, archive verification, cover, and conclusion.
- Storyboard coordinator regression passed: 3 tests and 43 assertions.
- Full Laravel/Pest regression passed: 170 tests and 2,510 assertions.
- Final rehearsal remains locked at `storage/app/election/runs/20260724-045338-420890-39010001-browser-full-election`.
- Election-day evidence remains unchanged at `storage/app/election/runs/20260724-004938-0421-a-operator`.

## Next Recommended Steps

- Run a supervised hardware pilot with the intended Raspberry Pi, CUPS printer, camera/handheld scanner, UPS, and named removable media.
- Define the legally approved transmission policy before enabling any network transmission path.
- Calibrate the redesigned ballot, tally, and Election Return on the intended printer, paper stock, camera, and handheld scanner.
- Exercise backup-appliance recovery with a copied evidence run and documented Election Board custody procedure.

## Private Voter Journey Slice

Completed:

- Added anonymous one-use voter authorization with officer PIN validation, keyed code hashes, expiry, and journal events.
- Added a tablet entry screen, fixed-order official ballot, contest counters, hard maximum enforcement, undervote support, and a separate review step.
- Added encrypted private print releases with opaque QR/manual codes and no plaintext pre-print ballot file.
- Added a private print station that never displays candidate selections and continues to use the configured ballot-printer adapter.
- Added encrypted sealed-ballot deposits during voting and automatic opening through `CountingService` only after polls close.
- Added a watcher view that suppresses candidate totals and individual ballot information until official results are available.
- Updated the full browser walkthrough to record officer admission, voter code claim, private finalization, printing, verification, deposit, and post-close counting.

Verification:

- `php artisan test --compact tests/Feature/PrivateVoterJourneyTest.php`: passed, 4 tests and 103 assertions.
- Focused voter page compatibility tests: passed, 4 tests and 65 assertions.
- Focused legacy lifecycle and full-demo tests: passed, 4 tests and 49 assertions.
- `npm run build`: passed, 626 modules transformed.
- `node --check scripts/election-browser-walkthrough.mjs`: passed.
- `vendor/bin/pint --dirty --format agent`: passed.
- `php artisan election:browser-walkthrough full-election --ballots=1 --slow-mo=0 --base-url=http://aes.test`: passed.
- Recorded rehearsal: `storage/app/election/runs/20260724-110337-556352-39010001-browser-full-election`.
- Browser result: 45 completed actions, 1 finalized/printed/deposited ballot, 40 screenshots, 40 storyboard frames, zero browser messages, approved Election Return, closed precinct, and verified archive.
- Full election page regression: passed, 67 tests and 1,242 assertions.
- Expanded the browser walkthrough with an unmarked ballot checkpoint, one focused screenshot for every candidate selection, and a complete voter review checkpoint.
- Selection storyboard captions now identify the candidate, contest, current selection number, contest maximum, privacy control, reviewer check, and relevant evidence.
- Expanded voter-UI rehearsal: `storage/app/election/runs/20260724-111946-696359-39010001-browser-full-election`.
- Expanded browser result: 69 completed actions, 22 candidate-selection checkpoints, 64 screenshots, 64 storyboard frames, zero browser messages, and a verified final archive.

Known limitations:

- Simulation file printing necessarily writes the human-readable paper-ballot artifact to the evidence folder; a physical CUPS deployment should apply spool retention and access controls.
- The private print station currently models paper QR scanning with a controlled server-side deposit action. Physical scanner confirmation remains an adapter/hardware-pilot task.
- Candidate photographs remain disabled until COMELEC supplies a complete, approved, consistently cropped image set with provenance.
- Individual ballot disclosure remains disabled even after close; watcher access is limited to aggregate official evidence.

## Printed Artifact Storyboard Slice

Completed:

- Added deterministic discovery and Ghostscript rendering for generated ballot, tally, Election Return, transmission, receipt, backup, and custody PDFs.
- Added a final `Printed Artifacts for Review` storyboard ceremony using page images from the actual PDFs rather than reconstructed summaries.
- Required ballot, tally sheet, and Election Return outputs fail the walkthrough when absent or unrenderable.
- Every printed page records its original run-relative PDF path, page number, byte count, source PDF SHA-256, and rendered image SHA-256.
- Added document-specific COMELEC review points covering QR legibility, ballot secrecy, totals, signatures, posting, handoff, backup, and custody.
- Printed page PNGs and all storyboard formats are included in the final verified evidence TAR.

Verification:

- `node --test tests/Node/election-print-artifact-renderer.test.mjs`: passed, 2 tests.
- Browser walkthrough recorder and isolation regression: passed, 7 tests and 63 assertions.
- JavaScript syntax, ESLint, Prettier, and Laravel Pint: passed.
- `php artisan election:browser-walkthrough full-election --ballots=1 --slow-mo=0 --base-url=http://aes.test`: passed.
- Persisted rehearsal: `storage/app/election/runs/20260724-113305-224964-39010001-browser-full-election`.
- Storyboard: 75 PDF pages, 72 checkpoints, 8 generated documents/pages, and zero browser messages.
- Final evidence archive: 236 checked files, zero mismatches, and SHA-256 `bdb6af33fecef859ece89159a0509f67aa62750debbf72786a365e064641e04f`.
- Visually inspected the source ballot, tally, and Election Return images and storyboard pages 67-69.

Known gaps exposed for the next slice:

- The current ballot PDF prints the QR artifact filesystem path but does not embed the QR image.
- The single-page tally sheet and Election Return truncate the multi-contest candidate set.
- The generic evidence PDF format needs COMELEC-approved typography, pagination, signature blocks, certification language, and printer calibration.

## COMELEC Print-Form Redesign Slice

Completed:

- Added a deterministic A4 PDF document engine with repeated headers, precinct/document identity, page numbering, source-of-truth notice, multi-page flow, and embedded grayscale PNG support.
- Added dedicated ballot, tally sheet, and Election Return renderers instead of routing election forms through the generic evidence renderer.
- The ballot now embeds a 740 by 740 pixel QR at approximately 65 mm, disables PDF image interpolation, records the payload hash, and prints every voter selection in activated contest order.
- Long contest titles reserve a separate fixed-width selection-limit column and wrap without collision.
- Tally and Election Return tables now print all activated candidates, including zero totals, and repeat contest/column headers across page breaks.
- Added paper reconciliation and Electoral Board signature/certification sections.
- Generic supporting-evidence PDFs now paginate without the former one-page line limit.
- Preserved existing artifact paths, journal events, hashes, printer adapters, and evidence bundle discovery.

Verification:

- Print-form regression: 5 tests and 234 assertions passed.
- Focused ballot QR/scanner regression: 3 tests and 19 assertions passed.
- Directly affected lifecycle, scanner, counting, Return, and full-demo scenario regression: 6 tests and 57 assertions passed.
- Private voter journey regression: 4 tests and 103 assertions passed.
- Targeted PHPStan analysis of the touched printing, QR, counting, Return, support, and provider surface passed with zero errors.
- The repository-wide PHPStan scan still reports 127 existing errors outside this slice.
- A combined run of the three broader test files was stopped after remaining silent for more than eight minutes; the affected suites and filters above passed separately.
- Real browser walkthrough passed at `storage/app/election/runs/20260724-163307-733872-39010001-browser-full-election`.
- Real output: 2 ballot pages, 12 tally pages, and 13 Election Return pages covering 387 candidate rows.
- Representative first, continuation, and final pages were visually inspected for overlap, clipping, repeated headings, footer separation, and signature space.
- The 2,901-byte QR decoded from the 144-dpi rendered ballot page and matched the source QR payload exactly.
- Final evidence archive verification passed across 260 files with zero mismatches.

Known limitations:

- The new forms are COMELEC-oriented simulation evidence, not approved prescribed forms.
- Physical printer margin, toner, paper, camera-distance, and scanner testing remain part of the field hardware pilot.
- Form wording, official copy counts, typography, signature requirements, and paper dimensions require COMELEC review.

## Planned COMELEC Review Deployment

- The review deployment is a presentation environment and does not replace the offline precinct appliance.
- Review mode now prefills simulation-only officer, setup, inventory, configuration, and testing fields from server-side configuration.
- Temporary defaults are gated by explicit review mode and absent from election-day, voter, watcher, and print-station responses.
- Signatures, observed physical counts, adjudication decisions, acknowledgements, recipient identities, and final approvals will remain manual.
- Operators can clear and reload temporary defaults without submitting a ceremony, and scenario reports disclose review mode without persisting credentials.
- Focused verification passed: 6 feature tests with 76 assertions, 1 browser test with 11 assertions, TypeScript, ESLint, production build, Pint, and formatting.
- The Cloud URL now rejects anonymous access with a review-specific login challenge and accepts configured reviewer credentials.
- Authorized and unauthorized responses prohibit indexing and caching; the deployed `robots.txt` disallows all crawlers.
- Live verification confirmed HTTP 401 for anonymous access, HTTP 200 for an authorized reviewer, the review-mode banner, and the expected response headers.
- Cloud credentials remain deployment secrets and are not persisted in source control, scenario reports, or browser-role props.
- Next slices align PHP, enable migrations, disable hibernation for demonstrations, and provision shared persistence before adding role-paired tablets.
- The supervised printer/scanner/paper-stock pilot remains required after the review deployment.
