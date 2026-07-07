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
| Scenario verification | Pending | Add focused feature coverage for folder contents and hashes. |
| Final run and status | Pending | Run scenario and update docs with paths and results. |

## Commit Log

| Slice | Commit | Tests/Checks | Result |
| --- | --- | --- | --- |
| Plan and compass persisted | `ea28ceb` | Documentation-only | Committed |
| Storage and scenario registration | `7ad825e` | `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --compact` | Passed: 33 tests, 175 assertions |
| Evidence folder builder | `1a6831e` | `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --compact` | Passed: 33 tests, 180 assertions |
| Summary reports | `88cfaf7` | `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --compact` | Passed: 33 tests, 187 assertions |
| Tally sheet artifacts | Pending | `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --compact` | Passed: 33 tests, 192 assertions |

## Next Slice

Add focused verification for folder contents, summary pointers, copied hashes, and persistence after later scenario runs.
