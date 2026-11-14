# Alternative Election System Compass

## North Star

AES is a ceremony-driven precinct appliance for voter-verifiable elections. It
uses local evidence, printed artifacts, append-only journals, and Truth QR
payloads so election workers, watchers, auditors, and later canvassing stations
can verify election records without trusting a hidden mutable database or a live
internet connection.

The current product direction is a configurable election appliance with two
tabulation profiles:

- **Device Tabulation with Paper Audit**, the default profile. Voters verify and
  deposit paper ballots, while closeout counts sealed VVDAT records and preserves
  paper ballots for audit.
- **Paper-First QR Scan Tally**, an alternate profile. Closeout can scan ballot
  Truth QR payloads and tally accepted paper records.

## Current Center of Gravity

The project has moved beyond the original foundation waves. Current work centers
on:

1. Truth QR as the shared verification layer for ballots, election returns,
   canvassing, and future non-election applications.
2. COMELEC-facing role demonstrations that show Election Officer, Voter,
   Printing Station, Poll Watcher, Auditor, and Canvassing perspectives.
3. Split national and local election returns, compact tally sheets, print
   profiles, and watcher/auditor evidence views.
4. Air-gapped runtime readiness: scanner ownership, Reverb-as-notification-only,
   polling fallback, health checks, and appliance diagnostics.
5. EML interoperability at controlled boundaries without making XML canonical
   election truth.

## Package Prerequisite Track

The EMS application is intentionally deferred until its domain packages are
complete and consumable. The required sequence is:

1. Finish `3neti/project-of-precincts`.
2. Implement `3neti/election-rules`.
3. Implement `3neti/candidate-registry`.
4. Define the package contracts and integration boundaries.
5. Only then scaffold the EMS application.

The EMS must consume these packages; it must not absorb their domain logic.

## Canonical Rules

1. Paper artifacts remain voter-visible evidence.
2. The precinct appliance is an evidence appliance, not the legal authority by
   itself.
3. The system must operate offline once commissioned.
4. Election UI is ceremony-driven, not an admin dashboard.
5. Important actions are journaled and reproducible.
6. SQLite, PostgreSQL, caches, and browser state are convenience layers.
7. Truth QR payloads carry compact election meaning, context, mapping identity,
   hashes, and signatures where required.
8. EML, PDF, JSON, CSV, and QR are representations of canonical state; none may
   silently become a parallel tally.
9. Transmission is optional future behavior. The core local verification flow
   must not depend on transmission.

## Active Program Compasses

- Public simulation and role-demo flow:
  `docs/PUBLIC_SIMULATION_SERVER_COMPASS.md`
- Precinct realism and COMELEC review readiness:
  `docs/REALISM_COMPASS.md`
- Air-gapped real-time appliance runtime:
  `docs/AIR_GAPPED_REALTIME_RUNTIME_COMPASS.md`
- EML interoperability:
  `docs/EML_INTEROPERABILITY_COMPASS.md`
- Truth QR offline signing, multipart ER verification, and canvassing preflight:
  `docs/TRUTH_QR_OFFLINE_SIGNING_AND_CANVASSING.md`
- Truth QR and patent drafting context:
  `docs/patents/README.md`

## Implemented Capabilities

- POP and CLC import pipeline for real precinct and candidate data.
- Role-demo entrypoint for Election Officer, Voter, Poll Watcher, Auditor, and
  demonstration flows.
- Voter Control Number for ballot admission.
- Voter Print PIN for privacy-preserving ballot printing.
- Paper-facsimile ballot marking UI with position and surname navigation.
- Demonstration helper to fill remaining candidate selections.
- Voter completion page with printable ballot preview.
- Selected-candidate paper ballot artifact with logos, Truth QR, and decoded QR
  inspection support.
- Configurable ballot UI profile and print profiles.
- Tally sheet with tally-stick visual display and compact result output.
- National and local election return artifacts with COMELEC-oriented layout.
- Truth QR ballot payloads that encode precinct context and candidate codes.
- Truth QR election-return payloads with multipart QR support, signatures, and
  EML binding.
- Watcher ballot viewer with pagination and live tally horizon.
- Random Manual Audit scanner and discrepancy-recording flow.
- Public/role demo canvassing board and scanner simulation.
- Direct-print scaffolding for control-number receipts, ballots, tally sheets,
  and election returns.
- Air-gapped realtime runtime design and implementation baseline.
- EML 7 base profile exports for 110, 230, 410, 480, 510, 520, and 530.
- EML schema validation, staging-only configuration import, artifact signing,
  evidence packages, and offline verification command.

## Current Dirty Slice

The current intentional uncommitted slice is the EML interoperability baseline
plus associated Truth QR and canvassing-demo binding work. It includes EML
services, routes, controller actions, UI validation support, fixtures, tests, and
documentation.

Local generated or environment files must be reviewed before staging. In
particular, `.env.pre-demo-backup`, generated `tmp/`, and generated `output/`
artifacts should not be committed unless deliberately promoted as evidence or
submission material.

## Next Recommended Work

1. `3neti/project-of-precincts` package-consumption boundary is complete:
   approved snapshots, lookup, reference validation, ballot-style inputs, and
   ACM package output are implemented.
2. `3neti/election-rules` first vertical slice is complete: immutable contest
   rules, deterministic contest ordering, and fail-closed selection validation.
3. `3neti/candidate-registry` first vertical slice is complete: immutable
   candidate status, lookup, deterministic hashing, and rule-set compatibility
   validation.
4. Cross-package contracts and integration boundaries are complete and tested:
   POP, election-rules, and candidate-registry hashes are bound in the shared
   ballot-style compilation context.
5. Only now may the EMS application be scaffolded.
6. Finish the repo-memory refresh by updating the functional specification,
   implementation status, and fresh-agent handoff.
7. Run focused EML, Truth QR, and canvassing tests.
8. Stage only intentional source, test, fixture, and documentation files.
9. Commit the EML interoperability baseline and documentation refresh.
10. In a later slice, implement official EML evidence-package intake with custody,
   authorization, duplicate, and supersession ceremonies.
11. In a later slice, add any COMELEC-specific EML profile only after an
   authoritative profile, schemas, fixtures, or acceptance criteria are supplied.

## Update Rule

Update this compass whenever the active program changes, a major ceremony flow
changes, or a representation becomes operationally significant. A new agent
should be able to read this file and immediately understand the current product
direction.
