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
| Storage helpers | Pending | Add durable scenario artifact root helpers. |
| Scenario registration | Pending | Register `evidence-folder-demo` and command output. |
| Evidence folder builder | Pending | Copy artifacts into numbered ceremony folders. |
| Summary reports | Pending | Generate JSON and text reports with flow, statistics, and pointers. |
| Tally sheet artifacts | Pending | Generate tally sheet text and PDF artifacts. |
| Scenario verification | Pending | Add focused feature coverage for folder contents and hashes. |
| Final run and status | Pending | Run scenario and update docs with paths and results. |

## Commit Log

| Slice | Commit | Tests/Checks | Result |
| --- | --- | --- | --- |
| Plan and compass persisted | Pending | Documentation-only | Pending |

## Next Slice

Add durable storage helpers and register the `evidence-folder-demo` scenario.
