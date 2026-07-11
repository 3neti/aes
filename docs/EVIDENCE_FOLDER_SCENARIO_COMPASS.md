# Run-First Evidence Scenario Compass

## Objective

Keep scenario and operator evidence under one intuitive run folder with numbered ceremony directories.

## Current Status

| Slice | Status | Notes |
| --- | --- | --- |
| Legacy artifact cleanup | Complete | `ElectionStorage::reset()` removes old `election-scenario-*` roots and recreates only the new skeleton. |
| Run context | Complete | Scenarios write `current-run.json`, `LATEST_RUN.txt`, and `runs/{run_id}`. |
| Numbered ceremony folders | Complete | Artifacts are routed to `00-start-here` through `13-journal` using General Instructions ceremony names. |
| Source data separation | Complete | POP/CLC imports and imported package skeletons live under `source-data`. |
| Scenario command output | Complete | Commands print run id, run folder, start-here path, report, summary, and artifact index. |
| Diagnostics/export integration | Complete | Evidence manifests, TAR archives, removable-media exports, and verification reports use the active run. |
| Tests | Complete | Lifecycle, page diagnostics, POP/CLC focused tests, and the full configured Pest suite passed. |

## Current Run Shape

```text
storage/app/election/runs/20260508-080000-39010001-evidence-folder-demo/
  00-start-here/
  01-precinct-package-and-configuration/
  02-final-testing-and-sealing/
  03-opening-of-polls/
  04-voting/
  05-closing-of-polls/
  06-counting-and-tally/
  07-election-return/
  08-transmission-or-official-handoff/
  09-final-backup/
  10-custody-turnover/
  11-close-precinct/
  12-audit-and-reconciliation/
  13-journal/
```

## Verified Commands

```bash
vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --compact
vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --compact
vendor/bin/pest tests/Feature/Election/PopWorkbookImportTest.php --compact
vendor/bin/pest tests/Feature/Election/PrecinctCandidatesCommandTest.php --compact
vendor/bin/pest tests/Feature/Election/ClcCandidateImportTest.php --compact
vendor/bin/pest --compact
php artisan election:scenario pop-import-demo
php artisan election:scenario full-demo
php artisan election:scenario evidence-folder-demo
```

## Next Slice

Add a Diagnostics UI panel that links directly to `LATEST_RUN.txt`, the active run summary, artifact index, ballot folder, tally folder, and Election Return folder.
