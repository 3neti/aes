# Evidence Folder Scenario Compass

## Objective

Create `php artisan election:scenario evidence-folder-demo` so a complete simulated precinct lifecycle produces a durable, readable evidence folder plus a final summary report with flow, statistics, hashes, and pointers to all important files.

## Principles

- Paper and printable artifacts remain primary evidence.
- Scenario output is deterministic and reproducible.
- Runtime resets must not delete persisted scenario evidence folders.
- The final report is the operator-facing table of contents.
- The scenario remains simulation-first and requires no real printer or scanner hardware.
- `.codex/config.toml` is never staged or committed.

## Current Status

| Slice | Status | Notes |
| --- | --- | --- |
| Plan and compass persisted | Complete | This compass and the implementation plan are the slice source of truth. |
| Storage helpers | Complete | Added durable scenario artifact root helper plan target. |
| Scenario registration | Complete | `evidence-folder-demo` is registered and callable. |
| Evidence folder builder | Complete | Copies runtime artifacts into numbered durable evidence folders and writes an artifact index. |
| Summary reports | Complete | Generates JSON and text reports with flow, statistics, hashes, and artifact pointers. |
| Tally sheet artifacts | Complete | Generates deterministic tally sheet TXT/PDF artifacts and copies them into the evidence folder. |
| Scenario verification | Complete | Verifies numbered folders, required artifacts, summary pointers, copied hashes, and persistence after a later scenario run. |
| Deterministic reruns | Complete | Rebuilds the durable evidence folder on each run to prevent stale artifact accumulation. |
| Final run and status | Complete | Final commands passed and generated stable evidence folder/report paths. |

## Commit Log

| Slice | Commit | Tests/Checks | Result |
| --- | --- | --- | --- |
| Plan and compass persisted | `ea28ceb` | Documentation-only | Committed |
| Storage and scenario registration | `7ad825e` | `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --compact` | Passed: 33 tests, 175 assertions |
| Evidence folder builder | `1a6831e` | `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --compact` | Passed: 33 tests, 180 assertions |
| Summary reports | `88cfaf7` | `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --compact` | Passed: 33 tests, 187 assertions |
| Tally sheet artifacts | `670465d` | `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --compact` | Passed: 33 tests, 192 assertions |
| Scenario verification | `fd03d39` | `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --compact` | Passed: 33 tests, 363 assertions |
| Deterministic reruns | `730a908` | `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --compact`; `php artisan election:scenario evidence-folder-demo` | Passed: 33 tests, 363 assertions; scenario passed |
| Final run and status | Pending | `vendor/bin/pint --dirty --format agent`; `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --compact`; `php artisan election:scenario evidence-folder-demo`; `vendor/bin/pest --compact` | Passed; 33 lifecycle tests and 363 assertions; scenario passed; 62 configured tests and 819 assertions |

## Final Artifacts

- Evidence folder: `storage/app/election-scenario-artifacts/2026-05-08-080001-0421-a-evidence-folder-demo-4dedf7c65e38`
- Summary report: `storage/app/election-scenario-artifacts/2026-05-08-080001-0421-a-evidence-folder-demo-4dedf7c65e38/summary-report.json`
- Summary text report: `storage/app/election-scenario-artifacts/2026-05-08-080001-0421-a-evidence-folder-demo-4dedf7c65e38/summary-report.txt`
- Artifact index: `storage/app/election-scenario-artifacts/2026-05-08-080001-0421-a-evidence-folder-demo-4dedf7c65e38/artifact-index.json`
- Durable scenario report: `storage/app/election-scenario-reports/2026-05-08-080001-0421-a-evidence-folder-demo-855437357857-report.json`

## Final Statistics

- Scan documents generated: 6
- Device checks passed: 2
- Device checks failed: 0
- Certification ballots counted: 3
- Officer attestations captured: 2
- Signatures captured: 2
- Ballots finalized: 2
- Ballots printed: 2
- Ballots spoiled: 1
- Accepted ballots: 1
- Rejected ballots: 1
- Contests tallied: 3
- Journal entries: 28
- Evidence files copied: 36
- Evidence bytes copied: 275451

## Next Slice

No remaining planned slice for `evidence-folder-demo`; future work should start from a new compass entry.
