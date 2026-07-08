# Run-First Evidence Scenario Plan

## Summary

`php artisan election:scenario evidence-folder-demo` now produces a normal run folder under `storage/app/election/runs/{run_id}`. The run folder is the operator-facing evidence bundle; it uses numbered ceremony directories so non-developers can browse files in lifecycle order.

## Run Folder Shape

```text
storage/app/election/
  README.txt
  LATEST_RUN.txt
  current-run.json
  runs/{run_id}/
    README.txt
    run-summary.json
    run-summary.txt
    artifact-index.json
    00-start-here/
    01-precinct-preparation/
    02-device-certification/
    03-polls-opening/
    04-voting-and-printing/
    05-polls-closing/
    06-counting-and-tally/
    07-election-return/
    08-precinct-closing/
    09-exports-and-verification/
    10-journal/
  source-data/
    pop/
    clc/
    imported-packages/
```

## Operator Flow

1. Open `storage/app/election/LATEST_RUN.txt`.
2. Open the pointed run folder.
3. Read `README.txt` and `run-summary.txt`.
4. Browse numbered ceremony folders in order.
5. Use `artifact-index.json` for SHA-256 hashes and file sizes.

## Scenario Flow

1. Reset old generated election artifacts.
2. Start run `20260508-080000-39010001-evidence-folder-demo`.
3. Import POP, activate the Manila precinct, and bind sample ballot data.
4. Run certification, voting, printing, counting, tally, Election Return, attestations, and journal writes into ceremony folders.
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
