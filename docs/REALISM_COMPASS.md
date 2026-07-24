# Precinct Realism Compass

## Objective

Evolve the deterministic simulation into a credible offline precinct appliance while preserving paper authority, ceremony discipline, reproducibility, and inspectable evidence.

## Current Position

The browser lifecycle reaches Audit and Reconciliation and produces a verified evidence archive. The implementation is not field-ready because browser provisioning still uses sample election data, run namespaces are not isolated, paper accountability is incomplete, and legal transitions do not yet require complete physical and dual-control evidence.

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
| Return, posting, and custody | Pending | Ordered handoff and all cross-references pass first time. |
| Appliance hardening | Pending | Failure and recovery scenarios are deterministic. |
| 50-ballot field simulation | Pending | Full field scenario and archive verification pass. |

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

Return, Posting, and Custody.
