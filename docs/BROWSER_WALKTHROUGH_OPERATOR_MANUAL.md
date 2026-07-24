# Recorded Browser Walkthrough Operator Manual

## Purpose

`election:browser-walkthrough full-election` opens the local Alternative Election System in Chromium and performs a complete precinct rehearsal through the visible ceremony pages. It records the browser, preserves every ceremony artifact in an isolated rehearsal run, builds the final evidence TAR after recording stops, and verifies that TAR before locking the run.

This command is for demonstrations, training, acceptance checks, and field rehearsals. It does not create or replace the election-day operator run. Paper ballots remain the legal source of truth.

## Before the Run

Confirm the following:

1. The Laravel application is available through the local Herd URL configured by `APP_URL`.
2. PHP and Node dependencies are installed.
3. Chromium is installed for Playwright.
4. Frontend assets are current.
5. The configured POP workbook and CLC PDF directory are readable.
6. The configured Ghostscript executable is available for CLC PDF extraction.
7. `storage/app/election` has enough free space for the video, screenshots, TAR, and duplicated evidence. A one-ballot run currently uses roughly 270 MB after finalization.

Local preparation:

```bash
composer install
npm ci
npx playwright install chromium
npm run build
```

## Run the Walkthrough

Recommended visible rehearsal:

```bash
php artisan election:browser-walkthrough full-election --ballots=3 --headed --slow-mo=150
```

Fast unattended verification:

```bash
php artisan election:browser-walkthrough full-election --ballots=1 --slow-mo=0
```

Options:

| Option              | Meaning                                                                                                         |
| ------------------- | --------------------------------------------------------------------------------------------------------------- |
| `--ballots=1..50`   | Number of valid voter ballots to cast, print, and count. One additional printed ballot is deliberately spoiled. |
| `--headed`          | Show Chromium while the walkthrough runs. Video is recorded in both headed and headless modes.                  |
| `--slow-mo=0..2000` | Delay browser actions by the specified milliseconds.                                                            |
| `--base-url=`       | Override `APP_URL`. Only localhost, loopback, and `.test` URLs are accepted.                                    |

The command uses the configured precinct, currently clustered precinct `39010001` in Tondo, Manila. The configured POP workbook defaults to `resources/election/pop/2025NLE_POP.xlsx`; the configured CLC source supplies the actual contest and candidate registry.

## Ceremony Flow

The recorder performs these operator-visible ceremonies:

1. Precinct package activation and dual-control setup.
2. Electoral Board and supply baselines.
3. Device readiness and initialization report.
4. Friday certification, manual verification, discrepancy review, zero-out, officer signature, and sealing.
5. Two-step opening of polls with officer signature.
6. Deliberate spoilage, valid voter ballot selection, finalization, and paper artifact printing.
7. Closing of polls, QR scanning, rejected spoiled scan, adjudication, physical ballot control, reconciliation, and tally.
8. Election Return generation, copy/posting record, and dual officer approval.
9. Official handoff report, delivery package, officer and recipient verification, receipt, final backup, custody turnover, and precinct closure.
10. Audit baselines, final evidence manifest, TAR creation, and archive verification.

## Find the Result

The command prints the run ID, run folder, report, video, trace, screenshots, lifecycle reports, final TAR, and verification report.

All rehearsal runs are under:

```text
storage/app/election/runs/<run-id>/
```

Start with:

```text
run-summary.txt
artifact-index.json
12-audit-and-reconciliation/browser-recordings/browser-walkthrough-completion.json
12-audit-and-reconciliation/browser-recordings/browser-lifecycle-report.txt
```

The run folders follow ceremony order:

| Folder                                  | Evidence to inspect                                                                        |
| --------------------------------------- | ------------------------------------------------------------------------------------------ |
| `01-precinct-package-and-configuration` | Precinct activation, ballot definition, candidates, officer setup, and source hashes.      |
| `02-final-testing-and-sealing`          | Certification ballots, verification reports, zero-out, signatures, and sealing.            |
| `04-voting`                             | QR payloads, printable ballot PDFs, print jobs, spoiled ballot evidence, and paper ledger. |
| `06-counting-and-tally`                 | Accepted/rejected records, adjudications, physical count, tally JSON, and tally sheet PDF. |
| `07-election-return`                    | Election Return JSON/PDF, legal evidence, posting distribution, and dual approval.         |
| `08-transmission-or-official-handoff`   | Transmission report, delivery package, verifications, and receipt.                         |
| `09-final-backup`                       | Final backup report.                                                                       |
| `10-custody-turnover`                   | Custody record and turnover report.                                                        |
| `12-audit-and-reconciliation`           | Baselines, manifest, final TAR, TAR verification, and browser recordings.                  |
| `13-journal`                            | Append-only ceremony event chain.                                                          |

## Browser Recording Files

Inside `12-audit-and-reconciliation/browser-recordings`:

| File                                     | Purpose                                                                          |
| ---------------------------------------- | -------------------------------------------------------------------------------- |
| `full-election.webm`                     | Complete browser video.                                                          |
| `playwright-trace.zip`                   | Playwright trace for replay and element-level debugging.                         |
| `01-*.png` through `46-*.png`            | Full-page ceremony checkpoints.                                                  |
| `action-log.jsonl`                       | Ordered browser action records.                                                  |
| `recording-metadata.json`                | Browser version, viewport, console messages, and recorder artifact hashes.       |
| `browser-walkthrough-report.json`        | Browser recorder result and statistics.                                          |
| `browser-lifecycle-report.json` / `.txt` | Plain ceremony flow, completion counts, statistics, and principal file pointers. |
| `browser-artifact-index.json`            | SHA-256 index of the completed browser recording files.                          |
| `browser-walkthrough-completion.json`    | Final coordinator result, including post-recording TAR verification.             |
| `browser-walkthrough-recovery.json`      | Present only when a later invocation recovers an interrupted coordinator.        |

## Verify the Final Archive

The final TAR is named `evidence-bundle-YYYYMMDD-HHMMSS.tar` under `12-audit-and-reconciliation`.

Verify the latest archive for the selected run:

```bash
php artisan election:archive-verify
```

Verify a specific copied or downloaded archive:

```bash
php artisan election:archive-verify /path/to/evidence-bundle-YYYYMMDD-HHMMSS.tar
```

Machine-readable output:

```bash
php artisan election:archive-verify /path/to/evidence-bundle.tar --json
```

A valid result reports `passed`, a nonzero checked-file count, and zero mismatches. The final run-level `artifact-index.json` sits outside the TAR and hashes the TAR plus its verification report, avoiding a self-referential hash cycle.

## Failure and Recovery

A normal browser failure still produces the available video, trace, action log, failure screenshot, lifecycle report, browser index, final TAR, and completion record. The rehearsal is marked failed and locked.

If the coordinator process terminates before it can finalize, the next invocation checks the recorded coordinator lease. When the old process is no longer running, the new invocation:

1. Writes a failed completion record if none exists.
2. Writes `browser-walkthrough-recovery.json`.
3. Locks and indexes the interrupted rehearsal.
4. Starts a new isolated rehearsal.

If another coordinator is still alive, the command refuses to overlap it.

For investigation, inspect `browser-walkthrough-completion.json`, `browser-walkthrough-recovery.json` when present, `failure.png`, `action-log.jsonl`, `playwright-trace.zip`, and `storage/logs/laravel.log`.

## Acceptance Checklist

A successful rehearsal has all of the following:

- Command exit status is successful.
- `browser-walkthrough-completion.json` has `passed: true`.
- All eight groups in `browser-lifecycle-report.json` have `status: completed`.
- `browser_messages` is `0`.
- Return generated and approved flags are true.
- Handoff completed and precinct closed flags are true.
- In-browser and post-recording archive verification flags are true.
- Final verification has zero mismatches.
- The rehearsal run context is `locked`.
- `storage/app/election/current-run.json` still points to the election-day run.
