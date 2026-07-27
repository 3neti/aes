# Precinct Realism Implementation Plan

## Executive Summary

The current application is a deterministic lifecycle simulation with a complete browser path from precinct setup through audit. The realism program converts that simulation into a credible precinct appliance by making operational run ownership explicit, activating the configured POP and CLC election package, enforcing evidence-backed ceremony gates, accounting for every paper ballot, separating voter and Electoral Board interfaces, and exercising physical failure and recovery paths.

The software does not declare legal compliance. It implements configurable procedural controls that must be reviewed against the applicable COMELEC General Instructions by qualified election authorities and counsel.

## Principles

1. Paper ballots remain the legal source of truth.
2. An election-day run cannot be reset, replaced, or mutated by a rehearsal or automated test.
3. Package, registry, mapping, ballot definition, and signature failures fail closed.
4. Irreversible ceremonies require the configured Electoral Board approvals.
5. Every accountable paper ballot has an explicit lifecycle.
6. Counting reconciles physical ballots, scans, accepted ballots, rejected ballots, spoiled ballots, and unused accountable forms.
7. Operator, voter, public-count, and diagnostic interfaces expose only the information appropriate to their roles.
8. Evidence is append-only where practical and every generated summary is reproducible from primary artifacts.
9. Hardware remains behind adapters and simulation remains available in a separate run namespace.
10. Every realism slice is scenario-testable and browser-demonstrable.

## Assumptions

- The default operational precinct is configured through POP and CLC settings, currently clustered precinct `39010001` in Tondo, Manila.
- POP identifies precinct and polling-place facts; CLC supplies contests and candidates.
- Candidate photos are optional package metadata and do not affect ballot identity.
- Officer PINs and signatures are local simulation credentials until COMELEC defines production credential issuance.
- Manual handoff remains the first official delivery driver.
- The first field-ready target uses local files, CUPS printing, a camera or handheld QR scanner, and removable media without network dependency.
- Laravel Cloud is a presentation and review environment, not a replacement for the offline precinct appliance.
- Multiple review tablets use separate browser sessions and one-use voter authorizations against the same isolated rehearsal run.
- Temporary form defaults are permitted only in explicit simulation/review mode and are never exposed in an election-day run.

## Architecture

The implementation remains app-first under `app/Election`. New realism behavior extends existing domains:

```text
app/Election/
  Lifecycle/       run ownership and ceremony gates
  Preparation/     POP + CLC activation and strict provenance
  Attestation/     Electoral Board roles and dual control
  Voting/          accountable ballot lifecycle
  Printing/        physical print outcomes
  Counting/        public count, adjudication, reconciliation
  Returns/         return approval and posting
  Transmission/    ordered handoff and receipts
  Custody/         containers, seals, and turnover
  Audit/           final evidence reconciliation
  Devices/         hardware identity and recovery
  Scenarios/       field simulation and failure injection
```

Primary evidence remains in numbered run folders. Read models may summarize evidence but cannot replace source artifacts.

## Run Types and Storage

Every run has a type:

```text
rehearsal
certification
election-day
audit
automated-test
```

Run context records the immutable run ID, run type, precinct and election identity, creation source, status, lifecycle timestamps, and previous active run where relevant. Operational pointers are separated from test and rehearsal pointers. `LATEST_RUN.txt` points only to the latest operator-visible run. Test cleanup may delete only automated-test runs.

## Vertical Slices

### Slice 1: Operational Run Isolation

- Add typed run context and run status.
- Separate operational, rehearsal, and automated-test pointers.
- Prevent reset or replacement of locked election-day runs.
- Make scenario cleanup namespace-scoped.
- Add immutable run identity and abandon/archive evidence.

Definition of done: tests and rehearsals cannot alter an election-day run or its pointer.

### Slice 2: Real Precinct Activation

- Replace browser sample activation with configured POP + CLC activation.
- Display election, precinct, polling place, district, registry versions, contests, source hashes, and package status.
- Freeze the activated ballot definition.

Definition of done: browser activation produces the configured Tondo ballot and excludes President.

### Slice 3: Strict Package Certification

- Verify package, source, registry, mapping, ballot definition, and signatures.
- Fail closed on any mismatch.
- Provide only reload or formally abort resolution actions.

Definition of done: a mismatched hash cannot produce a passing initialization or sealing report.

### Slice 4: Electoral Board and Physical Inventory

- Record required Electoral Board members and roles.
- Record officer credentials and signatures.
- Record printer, scanner, ballot box, seal, envelope, storage media, and supply identities.
- Require dual control for configured irreversible ceremonies.

Definition of done: opening and closing gates cannot pass without complete required evidence.

### Slice 5: Accountable Paper Ballot Lifecycle

- Model authorized, prepared, printed, voter-verified, deposited, failed, spoiled, abandoned, and quarantined outcomes.
- Prevent counting before voter verification and deposit.
- Invalidate prior payloads when reprinting.
- Reconcile issued, printed, spoiled, deposited, and unused forms.

Definition of done: every counted payload resolves to one deposited paper ballot and every reprint invalidates its predecessor.

### Slice 6: Voter Station

- Separate voter ballot UI from Electoral Board controls.
- Show only ballot instructions, contests, review, confirmation, print progress, and paper inspection.
- Preserve accessibility, privacy, dynamic contests, and optional candidate photos.

Definition of done: voter pages expose no journals, hashes, storage paths, lifecycle controls, or officer actions.

### Slice 7: Public Counting and Adjudication

- Record ballot-box opening and physical count.
- Display each scanned ballot for public review without voter identity.
- Add rejected-ballot categories, Electoral Board adjudication, recount, and reset ceremonies.
- Enforce physical reconciliation before tally completion.

Definition of done: deposited equals scanned equals accepted plus formally rejected.

### Slice 8: Return, Posting, and Custody

- Require return approval signatures.
- Record prescribed copies and public posting.
- Record containers, seals, backup media, receiving officer, and custody turnover.
- Enforce transmission, package, receipt, backup, custody, and precinct-closing order.

Definition of done: audit cross-references pass without corrective regeneration.

### Slice 9: Appliance and Failure Hardening

- Certify actual configured adapters and device identities.
- Add paper-jam, partial-print, unreadable-scan, power-loss, clock, writable-partition, and device-replacement recovery.
- Preserve append-only recovery evidence.

Definition of done: interruption scenarios resume deterministically without losing or duplicating accountable ballots.

### Slice 10: Field Simulation

- Run at least 50 deterministic ballots.
- Include spoiled ballots, print failures, rejected scans, special polling intake, recount, power recovery, and handoff.
- Produce physical-packet and digital-evidence reconciliation reports.

Definition of done: the field scenario reaches audit with all required checks passing and contextual file pointers in its summary.

## COMELEC Review Deployment Program

The review program presents the existing ceremonies to COMELEC personnel through a protected Laravel Cloud environment and several physical tablets. It must preserve the distinction between a convenient Internet-hosted review and the intended offline precinct appliance.

### Slice 11: Review Mode and Temporary Form Defaults

- Add an explicit review-mode configuration flag that is disabled by default.
- Source temporary review values from server-side environment/configuration, not from Vue bundles or browser storage.
- Prefill Electoral Board setup and inventory fields:
    - Chairperson, Poll Clerk, and Third Member simulation codes.
    - Chairperson and Poll Clerk simulation PINs.
    - Device, printer, and scanner serials.
    - Ballot-stock start and end numbers.
    - Ballot box, custody envelope, and seal identifiers.
- Prefill simulation officer codes and PINs on opening, voter admission, attestation, counting reconciliation, adjudication, Return approval, and handoff verification forms.
- Prefill deterministic testing/configuration values only when they represent known simulation fixtures.
- Provide one visible `Review defaults loaded` notice and a control to clear or reload the defaults.
- Never prefill signatures, observed physical ballot counts, discrepancy dispositions, legal acknowledgements, recipient identities, or final approval acts.
- Never auto-submit a ceremony or treat a prefilled field as officer approval.
- Record in the rehearsal report that temporary review defaults were enabled.
- Add a removal switch so the defaults can be disabled without changing Vue pages.

Definition of done: a reviewer can proceed through setup, configuration, certification, and testing without memorizing simulation credentials, while election-day mode renders the same fields empty and exposes none of the temporary values.

### Slice 12: Multi-Tablet Review Room

- Add a facilitator/projector view for one isolated rehearsal room.
- Generate role-specific join links and QR codes for:
    - Electoral Board tablet.
    - Voter tablets 1 through N.
    - Private print-station tablet.
    - Poll-watcher tablet.
    - Read-only presentation screen.
- Show station connectivity, readiness, authorizations, completed ballots, prints, and deposits without exposing voter selections.
- Mirror only one designated training voter tablet during a presentation.
- Mark every page as a simulation review environment.

Definition of done: at least five independent voter browser sessions can join one rehearsal, vote independently, and remain isolated from officer, watcher, and other voter state.

### Slice 13: Concurrent Voting Hardening

- Add distributed locks around journal sequencing, paper serial issuance, authorization claiming, ballot finalization, print-release redemption, and counting-record allocation.
- Add idempotency keys to every retryable tablet action.
- Reject duplicate claims, submissions, redemptions, deposits, and scans deterministically.
- Add concurrent feature/browser scenarios for five and ten voter tablets.

Definition of done: concurrent tablet activity cannot duplicate a paper serial, journal sequence, release, deposit, or counting record.

### Slice 14: Cloud Evidence Storage

- Keep the local filesystem adapter as the precinct-appliance default.
- Add a private shared object-storage adapter for immutable review evidence.
- Use persistent database sessions and shared distributed locks.
- Materialize temporary local files only when PDF, QR, archive, or rendering tools require a local path.
- Rebuild and verify the same numbered evidence bundle from shared review storage.

Definition of done: a review run survives request routing and application restart without missing, split, or conflicting evidence.

### Slice 15: COMELEC Review Kit

- Generate one offline review package containing the executive brief, storyboard, video, forms, scenario report, evidence bundle, reviewer checklist, known gaps, and README.
- Include the exact review-mode configuration, connected-station statistics, test results, and evidence hashes.
- Clearly distinguish simulated controls from field-validated controls.

Definition of done: a reviewer can understand and verify the rehearsal without repository access or a live meeting.

### Slice 16: Laravel Cloud Review Deployment

- Deploy a protected review environment in the Singapore region.
- Use a private object-storage bucket, persistent relational database, shared lock/session driver, file printer, and disabled transmission.
- Keep the environment non-hibernating during scheduled demonstrations.
- Run and record the full multi-tablet rehearsal before external review.

Definition of done: the protected Cloud URL supports a complete multi-tablet COMELEC rehearsal and produces a verified downloadable Review Kit.

## UI Strategy

The operator shell remains ceremony-driven. Each stage presents the legal ceremony name, accountable physical objects, required officers, evidence already recorded, exactly one next legal action, and explicit blocked reasons.

Voter, public-count, and diagnostics views are separate surfaces. Administrative dashboards are not introduced.

The COMELEC Review Room is a separate, read-only presentation surface. It may summarize connectivity and ceremony progress, but it may not perform operational actions or expose individual voter choices.

## Scenario Strategy

Scenarios receive an isolated run type and storage namespace. Required scenarios include:

```text
operational-run-isolation
configured-precinct-activation
strict-package-rejection
dual-control-opening
paper-ballot-accounting
public-count-reconciliation
power-recovery
field-simulation-50
```

Every scenario writes a report with statistics, passed gates, failures injected, and pointers to primary artifacts.

## Test Strategy

- Pest unit tests for state machines, value objects, and deterministic hashing.
- Feature tests for routes, services, validation, file evidence, and transition guards.
- Browser tests for operator, voter, public-count, and diagnostics workflows.
- Scenario command tests for deterministic full lifecycle behavior.
- Archive and reconciliation verification after every full scenario.
- CUPS and scanner tests remain opt-in when physical hardware is unavailable.

## Risks and Mitigations

- Legal procedure ambiguity: keep controls configurable and require formal external review.
- Source format changes: retain adapter and mapping-profile boundaries.
- Test evidence contaminating operations: enforce typed namespaces and protected pointers.
- Hidden paper discrepancies: make physical reconciliation a hard gate.
- Credential misuse: require role-scoped PINs, signatures, and dual control.
- Review defaults leaking into operations: gate them by explicit review mode, source them server-side, test election-day absence, and retain manual signatures and approvals.
- Concurrent tablet races: require distributed locks, idempotency, and multi-session scenarios before Cloud deployment.
- Cloud filesystem loss: retain local appliance storage and use private shared object storage only through a dedicated review adapter.
- Device interruption: use idempotent actions, append-only evidence, and recovery reports.
- Overly complex UI: expose only the current ceremony and blocked reasons.

## Exact Implementation Order

1. Persist this plan and compass.
2. Introduce typed isolated run contexts and protected operational pointers.
3. Switch browser provisioning to configured POP + CLC activation.
4. Make package and initialization checks fail closed.
5. Enforce Electoral Board, physical inventory, and dual-control gates.
6. Add accountable paper ballot state and reprint invalidation.
7. Separate the voter station.
8. Add public count reconciliation and adjudication.
9. Harden return, posting, backup, and custody evidence.
10. Add appliance recovery and failure injection.
11. Add the 50-ballot field scenario.
12. Run complete PHP, browser, type, build, scenario, audit, and archive verification.
13. Add explicit review mode and temporary server-supplied form defaults.
14. Add the role-paired multi-tablet Review Room.
15. Add distributed locking, idempotency, and concurrent voter scenarios.
16. Add shared Cloud evidence storage while retaining local appliance storage.
17. Generate the self-contained COMELEC Review Kit.
18. Deploy and verify the protected Laravel Cloud review environment.
19. Continue with the supervised offline hardware pilot and prescribed-form review.
