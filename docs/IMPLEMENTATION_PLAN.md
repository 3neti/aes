# Alternative Election System Implementation Plan

## Executive Summary

Build the first working simulation-mode Alternative Election System as a Laravel 13 and Inertia Vue precinct appliance. The implementation follows the documented waves and keeps every wave demonstrable through a vertical slice: domain logic, file storage, routes/controllers, ceremony UI, scenario runner support, Pest tests, and status documentation.

This version is intentionally appliance-first and simulation-first. Paper artifacts remain the legal evidence model, the Raspberry Pi remains an operational appliance, and append-only files are the primary digital evidence. SQLite is avoided for v1 unless a later read model clearly needs it.

## Assumptions

- The source planning documents under `docs/` govern product intent.
- The first version uses embedded sample registries and a sample precinct package.
- Runtime evidence is stored under `storage/app/election`.
- QR payloads are deterministic JSON strings in v1; image QR generation is deferred.
- Printable artifacts are deterministic text and JSON files in v1.
- Officer approvals are simulated with simple officer fields; authentication is deferred.
- Real printer/scanner hardware, x-journal, x-change, and package extraction are out of scope for v1.

## Architecture Overview

- `app/Election/Core` owns lifecycle stage codes, canonical JSON, hashing, dictionary labels, and high-level snapshots.
- `app/Election/Support` owns local storage paths, JSON file reads/writes, and deterministic clocks for scenarios.
- `app/Election/Lifecycle` owns valid transitions and current stage persistence.
- `app/Election/Preparation` owns package activation, registry loading, and deterministic mapping.
- `app/Election/Certification` owns Friday certification execution and reports.
- `app/Election/Voting` owns ballot selections, finalization, and payload generation.
- `app/Election/Printing` owns printer adapters, print jobs, spoilage, and ballot artifacts.
- `app/Election/Counting` owns payload validation, accepted/rejected append files, and tally generation.
- `app/Election/Returns` owns Election Return artifacts.
- `app/Election/Audit` and `app/Election/Diagnostics` expose journal, timeline, and artifact status.
- `app/Election/Scenarios` runs deterministic lifecycle flows through the same services used by HTTP.

## Domains

- Election Core
- Lifecycle
- Precinct Preparation
- Certification
- Voting
- Printing
- Counting
- Election Return
- Audit
- Scenario Runner
- Devices
- Diagnostics
- Support

## Waves And Vertical Slices

1. Foundation: dictionary, lifecycle shell, append-only journal, storage helper, Home UI, scenario shell, diagnostics shell.
2. Precinct Preparation: sample registries, sample package activation, deterministic mapping, persisted active configuration.
3. Certification: certification ballots, simulated counting, expected tally comparison, certification report.
4. Voting: open polls, ballot selection, review, finalization, deterministic QR payload.
5. Printing: printer adapters, file ballot artifact, print job journaling, spoil/reprint simulation.
6. Counting: scan simulation, accepted/rejected append files, tally from accepted files.
7. Election Return: deterministic return JSON/text artifacts and UI.
8. Audit: journal/timeline inspection and basic reconciliation.
9. Hardening: scenario reset isolation, duplicate/rejection checks, deterministic output tests, full demo regression.

Each slice follows: domain -> service/action -> storage -> route/controller -> Vue page/component -> scenario runner support -> tests -> documentation update.

## Data And Storage Strategy

Use local files as the primary evidence store:

```text
storage/app/election/
  registries/
  packages/
  runtime/
  journals/
  ballots/
  print-jobs/
  counting/accepted/
  counting/rejected/
  returns/
  certification/
  scenarios/
```

All persisted JSON is canonicalized before hashing. The activity journal is append-only JSON Lines with `sequence`, `event_type`, `occurred_at`, `payload`, `previous_hash`, and `event_hash`. Counting appends one file per accepted ballot.

## UI/Page Strategy

Use Inertia Vue pages under `resources/js/pages/Election`. The UI is ceremony-driven, not an admin dashboard. Every page shows current lifecycle stage, current ceremony, next action, and a timeline summary. Pages include Home, Provision, Certification, Voting, Printing, Counting, Returns, and Diagnostics.

## Scenario Runner Strategy

Add `php artisan election:scenario {friday-certification|full-demo}`. Scenarios use the same domain services as the UI and a fixed scenario clock. Reports are written to `storage/app/election/scenarios`.

## Test Strategy

Use Pest feature and unit tests for lifecycle transitions, package activation, deterministic mapping, certification, ballot finalization, QR payload generation, printing, counting append files, tally generation, Election Return generation, and full scenario success. Run Pint, Pest, TypeScript checking, and Vite build before final delivery.

## Risks And Mitigations

- Determinism vs. timestamps: use a fixed scenario clock and canonical JSON.
- SQLite becoming source of truth: avoid database tables in v1.
- UI drifting into dashboard: keep each page centered on ceremony state and one action group.
- Hardware dependency creep: ship only file and null printer adapters in v1.
- Storage inconsistency: write complete deterministic files and journal after successful domain actions.

## Definition Of Done Per Wave

- Functionality exists and is demonstrable through UI.
- The scenario runner supports the wave.
- Important events are journaled.
- File artifacts are written where required.
- Pest tests verify the slice.
- Documentation status is updated.

## Exact Implementation Order

1. Write this implementation plan.
2. Add election config, dictionary, sample data, storage helper, canonical JSON, hash service, and journal.
3. Add lifecycle state/transition services and Home UI.
4. Add package activation, deterministic mapping, provision UI, and tests.
5. Add certification workflow, report artifact, scenario support, and tests.
6. Add poll opening/closing and voting finalization.
7. Add print adapters, print jobs, spoilage, and artifact generation.
8. Add counting validation, accepted/rejected append files, and tally.
9. Add Election Return generation and UI.
10. Add audit/diagnostics projections.
11. Add full scenario runner and tests.
12. Regenerate Wayfinder routes if needed.
13. Run Pint, Pest, typecheck, and build.
14. Write `docs/IMPLEMENTATION_STATUS.md`.
