# Run-First Evidence Scenario Plan

## Summary

`php artisan election:scenario evidence-folder-demo` now produces a normal run folder under `storage/app/election/runs/{run_id}`. The run folder is the operator-facing evidence bundle; it uses numbered ceremony directories so non-developers can browse files in lifecycle order.

## Run Folder Shape

```text
storage/app/election/
  README.txt
  README.md
  LATEST_RUN.txt
  current-run.json
  runs/{run_id}/
    README.txt
    README.md
    run-summary.json
    run-summary.txt
    artifact-index.json
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
  source-data/
    pop/
    clc/
    imported-packages/
```

## Operator Flow

1. Open `storage/app/election/LATEST_RUN.txt`.
2. Open the pointed run folder.
3. Read `README.md`, `README.txt`, and `run-summary.txt`.
4. Browse numbered ceremony folders in order.
5. Use `artifact-index.json` for SHA-256 hashes and file sizes.

## Scenario Flow

1. Reset old generated election artifacts.
2. Start run `20260508-080000-39010001-evidence-folder-demo`.
3. Import POP/CLC, activate the Manila precinct, and bind the actual Tondo ballot definition.
4. Run final testing and sealing, voting, printing, counting, tally, Election Return, handoff/backup/custody evidence when the scenario includes them, attestations, and journal writes into ceremony folders.
5. Write scenario report in `00-start-here`.
6. Write `run-summary.json`, `run-summary.txt`, and `artifact-index.json`.

## Verification

```bash
vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --compact
php artisan election:scenario evidence-folder-demo
vendor/bin/pest --compact
```

Current verified run:

```text
storage/app/election/runs/20260508-080000-39010001-evidence-folder-demo
```
