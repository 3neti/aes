# Public Election Simulation Server Implementation Plan

## Executive Summary

This plan evolves the protected Multi-Tablet Review Room into a public, multi-precinct learning simulation. It deliberately adopts the existing **Device Tabulation with Paper Audit** profile as the default:

- voting creates a sealed anonymous VVDAT record during deposit;
- closing polls freezes and validates the ledger;
- tally and Election Return are generated from the ledger;
- the printed paper ballot and QR code are reserved for voter verification and sampled Random Manual Audit.

Participants should not inspect or count paper ballots one by one by default. The system must still make the end-to-end process understandable, verifiable, and safe to demonstrate.

## Assumptions

1. This is an opt-in public simulation, never a production election service.
2. Precinct packages use approved simulation POP and CLC fixtures until source-data governance approves additional packages.
3. The public service runs separately from appliance and election-day storage namespaces.
4. A participant may be a voter in one simulation and a watcher or observer in another, but roles are isolated per simulation.
5. The existing review-room database and Redis locks are the starting point for shared public simulation state.
6. No real voter roster, identity-document image, or official credential is imported into the public service.
7. Paper forms remain simulation artefacts pending COMELEC approval of prescribed forms.

## Architecture Overview

```text
Public lobby
  -> Simulation catalog
  -> Precinct room
       -> Officer host console
       -> Voter stations
       -> Private print station
       -> Watcher view
       -> God Mode facilitator console

Shared state
  -> PostgreSQL: public simulation metadata, role bindings, rate limits, publication state
  -> Redis: locks, idempotency keys, presence, short-lived admission codes
  -> Election run storage: append-only journal, sealed VVDAT records, print evidence, outputs, archives
  -> Private object storage: completed evidence bundles and approved public exports
```

The public simulation model must use a distinct `simulation_id` and storage namespace. A simulation cannot target an election-day run, and an election-day run cannot be made public by configuration.

## Core Domain Decisions

### VVDAT Tabulation

- `DeviceTabulationLedger` is the authoritative tally input for the default simulation profile.
- Deposit is idempotent and atomically records one sealed logical ballot per valid print release.
- Close polls obtains a run lock, blocks new admissions and deposits, records a ledger freeze artifact, validates chain/record completeness, and then derives the tally.
- The tally and Election Return carry the ledger root/hash, tally hash, tabulation-profile identifier, and public-publication status.
- Routine counting UI does not accept paper QR scans in this profile.

### Paper Audit

- Paper ballots are printed and voter-verified, then deposited as physical audit evidence.
- At close, the Electoral Board records the physical ballot count and reconciles it to VVDAT record count.
- The RMA sample is deterministic, auditable, and selected only after the official tabulation snapshot is sealed.
- Scanning an RMA QR proposes a tally comparison; two distinct officers must approve a match or record a discrepancy.
- RMA never mutates the official VVDAT tally or Election Return.

### Public Publication

- The watcher package is unavailable until `close_polls`, ledger freeze, tally generation, and officer publication approval succeed.
- Default publication contains tally, Election Return, result-form PDFs, ceremony timeline, hash manifest, and RMA status.
- The optional VVDAT audit export is a distinct, approved artifact. It has no identity, admission-code, station, timestamp, print-release, or raw session reference.
- Public downloads are rate limited, immutable, hash-addressed, and journaled.

### God Mode

- God Mode belongs to the simulation facilitator, never an operational election role.
- It uses separate authorization, a conspicuous simulation banner, and redaction by default.
- It shows room maps, lifecycle state, counts, journals, pending actions, certification status, artifact generation, and anonymized participant activity.
- It cannot retrieve live voter selections, individual printed ballots, raw QR payloads, or release data.
- Training fixtures may be replayed in a separate, explicit demonstration mode.

## Waves and Vertical Slices

### Wave 1: Simulation Foundation

1. **Simulation catalog and run isolation**
   - Domain: public simulation, simulation package catalog, lifecycle ownership.
   - Storage: `simulations/{simulation-id}` namespace and immutable scenario configuration.
   - UI: public lobby, precinct selector, active/closed state.
   - Tests: tenant isolation, no election-day run selection, deterministic simulation creation.
   - Done when a host can create an isolated simulation from an approved precinct package.

2. **Role enrollment and safe invites**
   - Domain: participant, host, watcher, observer, facilitator role bindings.
   - Storage: hashed invite/session bindings and journal entries.
   - UI: join flow appropriate to each role; no passwords or station credentials on public QR graphics.
   - Tests: expiry, role isolation, replay prevention, rate limiting.
   - Done when multiple people can join a simulation without gaining another role's data.

### Wave 2: Officer and Voter Simulation

3. **Officer-host ceremony console**
   - Reuse the ceremony UI with a host setup wizard, a selected precinct, defaults clearly marked as simulation fixtures, and a start-fresh action limited to the host's simulation.
   - Done when a public host can complete setup, certification, and opening without affecting other simulations.

4. **Public voter admission and private ballot**
   - Officer issues four-digit control codes; voters claim a code and cast privately.
   - Locks and idempotency cover authorization, finalization, print release, printing, and deposit.
   - Done when concurrent voter sessions create exactly one sealed record and one print/deposit lifecycle per admission.

### Wave 3: VVDAT Close and Results

5. **Explicit VVDAT freeze and validation**
   - Add `vvdat-ledger-freeze.json`, root hash, expected/deposited count, validation report, and `vvdat.ledger_frozen` journal event.
   - Fail close if records are duplicated, incomplete, invalid, or changed after freeze.
   - Done when close polls deterministically freezes a ledger and prevents further voting mutations.

6. **Device tabulation tally and result printing**
   - Derive tally directly from the frozen ledger.
   - Generate tally sheet and Election Return in A4, 80 mm, and 58 mm forms.
   - Done when a completed simulation produces an immutable tally and ER without routine paper-QR counting.

7. **Watcher publication package**
   - Create a publication approval ceremony and a public result page.
   - Publish result PDFs, hashes, timeline, aggregate statistics, and approved downloader links.
   - Done when watchers can independently obtain and hash-check the post-close result package.

### Wave 4: Paper Audit and Understanding

8. **Anonymized VVDAT audit export**
   - Generate a shuffled post-close export plus manifest and independent local tally command.
   - Make export policy configurable and disabled by default outside the public simulation profile.
   - Done when a watcher can reproduce the published aggregate result without receiving correlatable voter metadata.

9. **QR-assisted Random Manual Audit room**
   - Present a public tally comparison screen that scans paper QR selections into an audit-only tally and requires two officer approvals per sample.
   - Done when the audit evidence either confirms the sample or publishes an unresolved discrepancy without changing official results.

10. **Privacy-safe God Mode**
    - Build an explainer command center with lifecycle map, room/station status, redacted journal, artifact board, and training-fixture replay.
    - Done when a facilitator can explain every role without exposing participant selections.

### Wave 5: Public Hardening

11. **Concurrent multi-precinct controls**
    - Distributed locks, idempotency keys, capacity limits, session expiry, queue/backpressure policy, and abuse controls.
    - Done when parallel precinct simulations cannot cross-contaminate records or exhaust shared resources.

12. **Retention, consent, and review kit**
    - Public consent page, retention schedule, deletion workflow, incident contact, accessibility review, and downloadable COMELEC Review Kit.
    - Done when a reviewer can understand the simulation boundaries, inspect evidence, and request expiry/deletion through documented controls.

## Scenario Runner Strategy

Add deterministic scenarios that create separate public-simulation runs:

```bash
php artisan election:scenario public-vvdat-demo
php artisan election:scenario public-vvdat-concurrent --voters=10
php artisan election:scenario public-vvdat-rma
php artisan election:scenario public-vvdat-publication
```

Each scenario must persist a run summary with:

- simulation ID, precinct, package and configuration hashes;
- participants admitted, ballots finalized, printed, deposited, spoiled, and expired;
- VVDAT ledger count, freeze root/hash, tally source, tally hash, and Return hash;
- physical ballot reconciliation and RMA result;
- publication and audit-export hashes;
- privacy assertions and forbidden-field scan result;
- result artifact paths and archive verification result.

## Test Strategy

1. Unit tests: ledger freeze, deterministic tally, privacy transformation, export hash, role authorization, code expiry, and idempotency.
2. Feature tests: cross-simulation isolation, close lock, watcher publication gate, RMA separation, audit-export reproduction.
3. Browser tests: host create/join, participant voting, watcher post-close download, God Mode redaction, simultaneous-voter behavior.
4. Scenario tests: one-voter happy path, ten-voter concurrent path, spoiled/reprint, close-vs-finalize race, RMA discrepancy, and export verification.
5. Security tests: rate limiting, signed-link replay, data-field leakage, unauthorized artifact access, and no pre-close results.

## Risks and Mitigations

| Risk | Mitigation |
| --- | --- |
| Public transparency compromises ballot secrecy | Redact live choices; remove correlatable metadata; make individual ballots unavailable by default. |
| A public demo is mistaken for an official election | Persistent simulation labelling, separate hostname/storage, no real registry, and no election-day package access. |
| Concurrent sessions duplicate a ballot or journal event | Distributed locks, idempotency keys, unique constraints, and append-only validation. |
| Watcher exports enable reconstruction in a small precinct | Configurable publication policy, deterministic metadata stripping, minimum-participant threshold, and approval gate. |
| Device ledger is changed after close | Close lock, ledger root, frozen artifact, hash validation, and fail-closed tally generation. |
| QR audit is mistaken for official recount | Separate RMA terminology, UI, artifacts, tally, and legal explanation. |

## Exact Implementation Order

1. Persist public-simulation configuration and extend the compass/status documents.
2. Add simulation catalog, isolated run ownership, and host creation.
3. Add role invites, enrollment, rate limits, and public boundaries.
4. Harden concurrent admission, finalization, printing, deposit, and journaling.
5. Add explicit VVDAT freeze/validation and migrate close-polls to use it.
6. Add tally/ER publication approval and watcher result package.
7. Add anonymized VVDAT audit export plus independent verification command.
8. Add QR-assisted RMA tally room and public audit report.
9. Add redacted God Mode and training-fixture replay.
10. Add concurrent multi-precinct scenarios, browser tests, retention policy, and Review Kit.

## Definition of Done

A simulation is complete when a host can select a precinct, invite participants, open polls, issue codes, collect private votes, close polls, freeze and count the VVDAT ledger, reconcile paper count, print tally and Election Return, publish a safe watcher package, perform an audit-only QR sample, and export an evidence bundle. Every output must be reproducible, privacy-checked, scenario-tested, and visibly labelled as a simulation.
