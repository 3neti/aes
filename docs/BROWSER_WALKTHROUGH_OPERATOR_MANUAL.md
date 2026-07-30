# Recorded Browser Walkthrough Operator Manual

## Purpose

`election:browser-walkthrough full-election` opens the local Alternative Election System in Chromium and performs a complete precinct rehearsal through the visible ceremony pages. `election:browser-walkthrough public-simulation` records the public lobby, Election Officer, voter, private print station, watcher, and debrief flow. Each scenario records the browser, preserves every walkthrough artifact in an isolated rehearsal run, builds the final evidence TAR after recording stops, and verifies that TAR before locking the run.

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

Public simulation walkthrough:

```bash
php artisan election:browser-walkthrough public-simulation --ballots=1 --slow-mo=0
```

The public simulation scenario creates a fresh three-precinct public round for the recording, selects one ready precinct, opens polls with the generated officer credentials, issues a four-digit voter control number, casts and deposits the ballot, closes the precinct, publishes watcher artifacts, and records one facilitator observation.

Options:

| Option              | Meaning                                                                                                        |
| ------------------- | -------------------------------------------------------------------------------------------------------------- |
| `--ballots=1..50`   | Number of private voter journeys to record from anonymous admission through paper-ballot deposit and counting. |
| `--headed`          | Show Chromium while the walkthrough runs. Video is recorded in both headed and headless modes.                 |
| `--slow-mo=0..2000` | Delay browser actions by the specified milliseconds.                                                           |
| `--base-url=`       | Override `APP_URL`. Only localhost, loopback, and `.test` URLs are accepted.                                   |

The command uses the configured precinct, currently clustered precinct `39010001` in Tondo, Manila. The configured POP workbook defaults to `resources/election/pop/2025NLE_POP.xlsx`; the configured CLC source supplies the actual contest and candidate registry.

## Ceremony Flow

The recorder performs these operator-visible ceremonies:

1. Precinct package activation and dual-control setup.
2. Electoral Board and supply baselines.
3. Device readiness and initialization report.
4. Friday certification, manual verification, discrepancy review, zero-out, officer signature, and sealing.
5. Two-step opening of polls with officer signature.
6. Anonymous voter admission, ballot opening, every candidate selection, voter review, private finalization, printing, verification, and sealed paper-ballot deposit.
7. Closing of polls, opening of the sealed ballot records, physical ballot control, reconciliation, and tally.
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

| Folder                                  | Evidence to inspect                                                                                                                                               |
| --------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `01-precinct-package-and-configuration` | Precinct activation, ballot definition, candidates, officer setup, and source hashes.                                                                             |
| `02-final-testing-and-sealing`          | Certification ballots, verification reports, zero-out, signatures, and sealing.                                                                                   |
| `04-voting`                             | Anonymous authorizations, per-selection visual checkpoints, encrypted print releases, printable ballot PDFs, print jobs, sealed ballot records, and paper ledger. |
| `06-counting-and-tally`                 | Accepted/rejected records, adjudications, physical count, tally JSON, and tally sheet PDF.                                                                        |
| `07-election-return`                    | Election Return JSON/PDF, legal evidence, posting distribution, and dual approval.                                                                                |
| `08-transmission-or-official-handoff`   | Transmission report, delivery package, verifications, and receipt.                                                                                                |
| `09-final-backup`                       | Final backup report.                                                                                                                                              |
| `10-custody-turnover`                   | Custody record and turnover report.                                                                                                                               |
| `12-audit-and-reconciliation`           | Baselines, manifest, final TAR, TAR verification, and browser recordings.                                                                                         |
| `13-journal`                            | Append-only ceremony event chain.                                                                                                                                 |

## Browser Recording Files

Inside `12-audit-and-reconciliation/browser-recordings`:

| File                                            | Purpose                                                                                                           |
| ----------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| `full-election.webm`                            | Complete browser video.                                                                                           |
| `public-simulation.webm`                        | Complete public-simulation browser video when that scenario is run.                                                |
| `playwright-trace.zip`                          | Playwright trace for replay and element-level debugging.                                                          |
| `01-*.png` through `46-*.png`                   | Ceremony checkpoints, including `16-voter-ballot-*-opened`, every `selection-NN`, review, and finalization frame. |
| `storyboard-frames/01-*.png` through `46-*.png` | Readable viewport captures from the same checkpoints, used by the storyboard.                                     |
| `printed-artifacts/*.png`                       | Page-for-page renderings of generated ballot, tally, Return, handoff, backup, and custody PDFs.                   |
| `action-log.jsonl`                              | Ordered browser action records.                                                                                   |
| `recording-metadata.json`                       | Browser version, viewport, console messages, and recorder artifact hashes.                                        |
| `browser-walkthrough-report.json`               | Browser recorder result and statistics.                                                                           |
| `browser-lifecycle-report.json` / `.txt`        | Plain ceremony flow, completion counts, statistics, and principal file pointers.                                  |
| `browser-artifact-index.json`                   | SHA-256 index of the completed browser recording files.                                                           |
| `browser-walkthrough-completion.json`           | Final coordinator result, including post-recording TAR verification.                                              |
| `browser-walkthrough-recovery.json`             | Present only when a later invocation recovers an interrupted coordinator.                                         |
| `walkthrough-storyboard.html`                   | Offline, full-resolution visual narrative with one precisely captioned checkpoint per screenshot.                 |
| `walkthrough-storyboard.pdf`                    | Print-ready landscape briefing document for independent or remote review.                                         |
| `walkthrough-storyboard.json`                   | Structured captions, ceremony grouping, source identity, statistics, and screenshot SHA-256 values.               |

## Review the Storyboard

Open `walkthrough-storyboard.html` first when briefing reviewers who were not present for the rehearsal. It follows the exact recorded browser sequence and distinguishes:

1. What the Electoral Board did.
2. What the appliance enforced or recorded.
3. What a COMELEC reviewer should independently verify.
4. Which run-relative files contain the underlying evidence.

Each screenshot links to its full-resolution PNG and includes its SHA-256 value. The PDF fixes the same account into one landscape page per checkpoint for printing or circulation. The JSON is the machine-readable source for all captions and hashes.

The voter section is intentionally more granular than the other ceremonies. It begins with the unmarked ballot, records one focused viewport after each candidate is selected, records the complete review screen, and ends with the opaque print-release screen. Each selection caption names the candidate, contest, current contest selection number, and legal maximum. These intermediate choices exist only in the rehearsal browser and its recording artifacts; they are not written as operational election evidence before finalization.

The final `Printed Artifacts for Review` section renders every page of the actual generated PDFs. It does not reconstruct or restyle their contents. For `full-election`, the ballot PDF, tally sheet, and Election Return are required; the transmission report, delivery receipt, final backup report, custody record, and custody turnover report are included when produced. For `public-simulation`, the storyboard renders the public precinct ballot, tally sheet, Election Return, and available A4 / 58 mm / 80 mm print-form variants from the isolated public simulation namespace. A missing or unrenderable required PDF fails the walkthrough. Every printed-artifact checkpoint records the original PDF path, byte count, page number, source PDF SHA-256, and rendered-page SHA-256.

Use these pages for print-form critique. In particular, review candidate completeness and ordering, contest limits, precinct identity, QR presence and scan quality, line wrapping, margins, font size, page breaks, signature and annotation space, copy/posting suitability, and chain-of-custody fields. The page image links to its full-resolution PNG for close inspection.

The current simulation print forms use deterministic A4 pages:

- The ballot embeds its QR image and prints every selected candidate in activated contest order. Dense operational payloads use a 740 by 740 pixel QR source printed at approximately 65 mm.
- The tally sheet and Election Return print every candidate, including zero-vote candidates, with repeated contest and column headers after page breaks.
- The tally ends with paper-ballot reconciliation fields. The Election Return ends with a separate Electoral Board certification and signature page.
- Every page repeats the document title, section, precinct/document identity, page count, and paper-source-of-truth notice.
- The forms are clearly marked as simulation evidence. They are COMELEC-oriented review forms, not approved prescribed forms.

For the accepted Tondo rehearsal at `storage/app/election/runs/20260724-163307-733872-39010001-browser-full-election`, the ballot has 2 pages, the tally has 12 pages, and the Election Return has 13 pages for 387 candidate rows. The QR decoded from the rendered ballot page to the same 2,901-byte payload as the source image. The final archive checked 260 files with zero mismatches.

Before field use, print representative pages on the intended printer and paper stock, scan the QR with the intended camera and handheld scanner, confirm margins at actual scale, and obtain COMELEC approval for the prescribed form language, copy counts, signature blocks, and paper dimensions.

The storyboard is generated before the coordinator builds the final TAR so that all three storyboard files and all referenced screenshots are included in the verified archive. For that reason, the storyboard does not claim its own final archive verification. Confirm that separate final fact in `browser-walkthrough-completion.json`.

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
- The storyboard includes the `Printed Artifacts for Review` ceremony and its source-document hashes.
- The rehearsal run context is `locked`.
- `storage/app/election/current-run.json` still points to the election-day run.
