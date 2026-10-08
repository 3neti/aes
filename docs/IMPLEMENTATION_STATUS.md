# Alternative Election System Implementation Status

## Current Status

The codebase is a mature simulation and demonstration appliance for AES. The
current intentional uncommitted work is the EML interoperability baseline plus
Truth QR and canvassing-demo bindings.

`main` currently matches `origin/main`, but the working tree is dirty with
intentional EML source, route, UI, test, config, and documentation changes.

## Current Active Slice

**Slice:** Repo-memory refresh and EML interoperability baseline

**Goal:** Make the repository understandable to a fresh agent and preserve the
current EML work as an intentional, testable slice.

## Implemented Product Areas

| Area | Status | Notes |
| --- | --- | --- |
| Ceremony-driven precinct appliance | Implemented | Operator flows are organized by ceremonies instead of a generic dashboard. |
| POP precinct importer | Implemented | Used to derive real precinct context. |
| CLC candidate importer | Implemented | Used to derive candidate registries from PDFs. |
| Role-demo | Implemented | Election Officer, Voter, Watcher, Auditor, print, and closeout flows exist. |
| Voter Control Number | Implemented | Supports officer-issued and demo self-issued flows. |
| Voter Print PIN | Implemented | Separates private ballot marking from printing-station release. |
| Paper-facsimile ballot UI | Implemented | Includes position and surname navigation. |
| Voter preview and helper fill | Implemented | Demo supports quick selection and ballot preview. |
| Selected-candidate ballot artifact | Implemented | Includes official-style layout, logos, Truth QR, and payload inspection. |
| Tally sheet | Implemented | Includes compact output and tally-stick display. |
| Split election returns | Implemented | National and local ER artifacts are generated. |
| Truth QR ballot payload | Implemented | Encodes precinct context and selected candidate codes. |
| Truth QR election-return payload | Implemented | Supports signed scoped payloads and multipart QR pages. |
| Watcher ballot viewer | Implemented | Supports ballot navigation and tally horizon display. |
| Random Manual Audit | Implemented | Supports QR capture, discrepancy recording, and watcher-safe publication. |
| Print profiles | Implemented | Full page and thermal-oriented profiles exist. |
| Direct-print scaffold | Implemented | Prepared for local appliance and USB printer workflows. |
| Air-gapped realtime runtime | Implemented | Reverb is notification-only; polling remains canonical recovery. |
| Canvassing demo | Implemented | Supports scanner ingestion and public canvass board simulation. |
| EML 7 base profile | Implemented in dirty tree | Supports 110, 230, 410, 480, 510, 520, and 530. |
| EML artifact validation UI | Implemented in dirty tree | Upload validation panel and controller exist. |
| EML package canonical intake | Pending | Requires custody, authorization, duplicate, and supersession ceremony. |
| COMELEC EML profile | Pending | Awaiting authoritative profile, fixtures, schemas, or criteria. |

## Intentional Dirty Work

The dirty work currently includes:

- `.env.example` EML/runtime configuration additions.
- `app/Election/Interoperability/Eml/*`.
- `app/Console/Commands/VerifyEmlArtifact.php`.
- `app/Http/Controllers/Election/EmlArtifactController.php`.
- Role-demo and canvassing-demo controller routes for EML artifacts.
- Truth QR election-return payload binding to EML artifact hashes and signatures.
- `resources/js/components/election/EmlValidationPanel.vue`.
- EML fixtures under `resources/election/eml/`.
- EML feature tests.
- EML compass and implementation plan.
- This documentation refresh and patent context files.

## Local Files To Classify Before Commit

These files or directories need an explicit staging decision:

- `.cloud/`
- `.env.pre-demo-backup`
- `.env.testing`
- `docs/submissions/`
- `output/`
- `tmp/`

Default recommendation:

- Commit source, fixtures, tests, and intentional documentation.
- Do not commit local backups, generated temporary output, or environment files
  unless they are deliberately promoted as review-kit evidence or deployment
  configuration.

## Verification Target For This Slice

Run the focused checks:

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact --filter=Eml
php artisan test --compact tests/Feature/TruthTallyReturnTest.php
php artisan test --compact tests/Feature/CanvassingScannerIngestionTest.php
php artisan test --compact tests/Feature/Election/RoleDemoTest.php
```

If browser socket restrictions prevent browser tests in the current environment,
record that explicitly rather than weakening the tests.

## Known Gaps

- EML evidence-package intake into canonical canvass is not implemented.
- No COMELEC-certified EML profile exists yet.
- Physical USB printer validation is still environment-specific.
- Physical scanner ownership and appliance runtime validation need field testing.
- Demo shortcuts remain separate from election-day behavior and must stay
  configurable.
- Transmission remains out of scope for the core local verification flow.

## Next Recommended Steps

1. Finish this repo-memory refresh.
2. Run the focused tests.
3. Stage only intentional EML, Truth QR, docs, tests, fixtures, and UI changes.
4. Commit the slice.
5. Open a clean thread using `docs/AGENT_HANDOFF.md` as the starting context.
6. Implement EML package intake ceremony only after the baseline is committed.
