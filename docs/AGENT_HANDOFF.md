# AES Fresh Agent Handoff

## Read This First

This repository contains the Alternative Election System, a Laravel 13 and Vue
application for a ceremony-driven precinct appliance. The thread that produced
much of the current work became too long and stale for reliable context. Treat
this file and the repository as the durable source of truth.

## Product Summary

AES helps run a precinct election workflow:

1. Prepare precinct and candidate data.
2. Open the precinct.
3. Admit voters with a Voter Control Number.
4. Let voters mark a private tablet ballot.
5. Generate a Voter Print PIN.
6. Print a voter-verifiable paper ballot at a print station.
7. Deposit the ballot and seal digital evidence where the active profile uses it.
8. Close polls.
9. Tally sealed VVDAT records or scanned paper Truth QR payloads.
10. Generate tally sheet, national election return, and local election return.
11. Publish watcher-safe artifacts.
12. Run Random Manual Audit.
13. Archive and hand off evidence.

The default profile is **Device Tabulation with Paper Audit**. Paper ballots are
voter-visible audit evidence. Routine closeout counts sealed VVDAT records unless
the profile is changed.

## Truth QR Summary

Truth QR is the verification layer. It encodes compact domain meaning, not just a
server lookup key. In AES it is used for ballot payloads, election-return
payloads, canvassing, audit, and reviewer inspection.

Read:

- `docs/patents/TRUTH_QR_STANDALONE_TECHNOLOGY_OUTLINE.md`
- `docs/patents/ELECTION_SYSTEM_USING_TRUTH_QR_OUTLINE.md`

## Current Documentation Map

- `docs/COMPASS.md` explains the current product direction.
- `docs/FUNCTIONAL_SPECIFICATIONS.md` describes expected system behavior.
- `docs/IMPLEMENTATION_STATUS.md` states what is implemented, dirty, and pending.
- `docs/EML_INTEROPERABILITY_COMPASS.md` explains EML boundaries.
- `docs/EML_INTEROPERABILITY_IMPLEMENTATION_PLAN.md` describes the EML delivery
  plan and current status.
- `docs/AIR_GAPPED_REALTIME_RUNTIME_COMPASS.md` explains appliance runtime
  boundaries.
- `docs/REALISM_COMPASS.md` preserves the COMELEC review and precinct realism
  program history.
- `docs/PUBLIC_SIMULATION_SERVER_COMPASS.md` preserves the public simulation
  program history.

## Current Implementation Areas

Key backend areas:

- `app/Election/Truth`
- `app/Election/Voting`
- `app/Election/Printing`
- `app/Election/Returns`
- `app/Election/PublicSimulation`
- `app/Election/Interoperability/Eml`
- `app/Http/Controllers/Election`

Key frontend areas:

- `resources/js/pages/Election`
- `resources/js/components/election`

Key configuration and fixtures:

- `config/election.php`
- `resources/election`

Key tests:

- `tests/Feature/Election/RoleDemoTest.php`
- `tests/Feature/TruthTallyReturnTest.php`
- `tests/Feature/CanvassingScannerIngestionTest.php`
- `tests/Feature/EmlInteroperabilityTest.php`
- `tests/Feature/EmlConfigurationStagingTest.php`

## Current Dirty Slice

The current dirty work is intentional EML interoperability work plus this
documentation refresh. It includes:

- EML profile, schema registry, secure XML, signing, artifact, evidence package,
  and staging services.
- EML validation controller and UI panel.
- EML routes from role-demo and canvassing-demo pages.
- EML fixtures.
- Tests for EML exports, validation, staging, Truth QR binding, and canvassing.
- Documentation updates and patent-context files.

Before committing, classify local generated files carefully.

## Local Artifact Staging Rules

Default commit:

- Source files.
- Tests.
- EML fixtures required by tests.
- Documentation.
- Configuration examples.

Default do not commit:

- `.env.pre-demo-backup`
- local `.env.testing` unless deliberately needed by CI or test conventions;
- generated `tmp/`;
- generated `output/`;
- local cloud byproducts unless they are required deployment configuration.

Review manually:

- `.cloud/`
- `docs/submissions/`

## Useful Local Commands

Run focused checks first:

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact --filter=Eml
php artisan test --compact tests/Feature/TruthTallyReturnTest.php
php artisan test --compact tests/Feature/CanvassingScannerIngestionTest.php
php artisan test --compact tests/Feature/Election/RoleDemoTest.php
```

Inspect routes:

```bash
php artisan route:list --path=election
```

## What Not To Do Casually

- Do not make EML the canonical election store.
- Do not place full EML XML inside routine ballot QR payloads.
- Do not remove Truth QR compact payload behavior.
- Do not remove Voter Control Number and Voter Print PIN separation.
- Do not expose voter identity in watcher, audit, or public exports.
- Do not treat demo shortcuts as election-day controls.
- Do not add transmission as a dependency for local closeout.
- Do not delete generated evidence or local artifacts without confirming whether
  they are only temporary output.

## Recommended Next Thread Prompt

Use this when starting a clean task:

```text
Read docs/AGENT_HANDOFF.md, docs/COMPASS.md, docs/FUNCTIONAL_SPECIFICATIONS.md,
docs/IMPLEMENTATION_STATUS.md, and docs/EML_INTEROPERABILITY_COMPASS.md first.
Then continue the current AES EML interoperability slice. Preserve Truth QR as
the canonical compact verification layer and do not make EML the internal source
of truth.
```
