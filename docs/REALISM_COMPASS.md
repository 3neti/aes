# Precinct Realism Compass

## Objective

Evolve the deterministic simulation into a credible offline precinct appliance while preserving paper authority, ceremony discipline, reproducibility, and inspectable evidence.

## Current Position

The precinct realism program is complete for simulation mode. The browser and scenario lifecycle use the configured Tondo POP + CLC package, preserve isolated election-day and rehearsal evidence, account for serialized paper ballots, require dual-control approvals, inspect evidence-chain integrity after restart, and pass a persisted 50-ballot rehearsal with full automated verification.

## Slice Status

| Slice | Status | Exit Gate |
| --- | --- | --- |
| Realism plan and compass | Complete | Plan, compass, main compass, and status agree. |
| Operational run isolation | Complete | Tests use isolated storage; rehearsals cannot alter election-day evidence or pointers. |
| Real precinct activation | Complete | Browser and scenarios activate configured Tondo POP + CLC ballot with provenance evidence. |
| Strict package certification | Complete | Source, registry, package, mapping, and activation hashes fail closed before known-ballot testing. |
| Electoral Board and inventory | Complete | Three officers, dual approval, serialized devices, ballot stock, seals, and custody containers are bound to the run. |
| Paper ballot lifecycle | Complete | Serialized stock events link issuance, printing, spoilage, and accepted counting to paper disposition. |
| Voter station | Complete | Dedicated voter routes receive ballot data only and hand finalized stock back to the operator console. |
| Public counting and adjudication | Complete | Physical box count, accepted records, and every rejected-scan disposition must reconcile before completion. |
| Return, posting, and custody | Complete | Dual approval gates handoff; delivery and custody require the same posted return evidence chain. |
| Appliance hardening | Complete | Restart inspection preserves the ceremony, verifies evidence chains, blocks tampered runs, and reports degraded devices. |
| 50-ballot field simulation | Complete | Fifty accepted ballots, two spoil/reprints, duplicate adjudication, physical reconciliation, custody, audit, and archive verification pass. |
| Full precinct realism verification | Complete | PHP, browser, frontend, formatting, build, and persisted rehearsal gates pass without changing the election-day pointer. |

## Non-Negotiable Gates

1. Election-day storage is protected from reset.
2. Browser and scenario activation use the same configured election package.
3. Package and registry verification fail closed.
4. Irreversible transitions require configured officer approvals.
5. Paper lifecycle accounting balances.
6. Counting discrepancies require adjudication.
7. Election Return approval and custody evidence are complete.
8. Audit reconciliation and evidence archive verification pass.

## Update Rule

Update this compass after every realism slice. A slice is complete only after focused tests pass, documentation status is updated, and the slice is committed.

## Next Slice

Field Hardware Pilot.
