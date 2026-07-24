# Precinct Realism Compass

## Objective

Evolve the deterministic simulation into a credible offline precinct appliance while preserving paper authority, ceremony discipline, reproducibility, and inspectable evidence.

## Current Position

The precinct realism program is complete for simulation mode. The browser and scenario lifecycle use the configured Tondo POP + CLC package, preserve isolated election-day and rehearsal evidence, account for serialized paper ballots, require dual-control approvals, inspect evidence-chain integrity after restart, and pass a persisted 50-ballot rehearsal with full automated verification.

## Slice Status

| Slice                              | Status   | Exit Gate                                                                                                                                                  |
| ---------------------------------- | -------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Realism plan and compass           | Complete | Plan, compass, main compass, and status agree.                                                                                                             |
| Operational run isolation          | Complete | Tests use isolated storage; rehearsals cannot alter election-day evidence or pointers.                                                                     |
| Real precinct activation           | Complete | Browser and scenarios activate configured Tondo POP + CLC ballot with provenance evidence.                                                                 |
| Strict package certification       | Complete | Source, registry, package, mapping, and activation hashes fail closed before known-ballot testing.                                                         |
| Electoral Board and inventory      | Complete | Three officers, dual approval, serialized devices, ballot stock, seals, and custody containers are bound to the run.                                       |
| Paper ballot lifecycle             | Complete | Serialized stock events link issuance, printing, spoilage, and accepted counting to paper disposition.                                                     |
| Private voter journey              | Complete | Anonymous one-use admission, client-side ballot review, encrypted print release, private printing, sealed deposit, and post-close disclosure are enforced. |
| Public counting and adjudication   | Complete | Physical box count, accepted records, and every rejected-scan disposition must reconcile before completion.                                                |
| Return, posting, and custody       | Complete | Dual approval gates handoff; delivery and custody require the same posted return evidence chain.                                                           |
| Appliance hardening                | Complete | Restart inspection preserves the ceremony, verifies evidence chains, blocks tampered runs, and reports degraded devices.                                   |
| 50-ballot field simulation         | Complete | Fifty accepted ballots, two spoil/reprints, duplicate adjudication, physical reconciliation, custody, audit, and archive verification pass.                |
| Full precinct realism verification | Complete | PHP, browser, frontend, formatting, build, and persisted rehearsal gates pass without changing the election-day pointer.                                   |

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

## Private Voter Journey

- Election Board officers admit a voter only after the external identity and roster check, then issue a one-use anonymous code.
- The authorization artifact stores no voter identity and no plaintext code.
- The voter tablet exposes only the official ballot, selection limits, review, and finalization. Candidate order remains fixed and the official ballot has no search control.
- Finalization writes an encrypted short-lived print release. Candidate choices are not written as a plaintext ballot payload before printing.
- The tablet displays an opaque release QR and manual release code. Neither reveals candidate choices.
- The private print station redeems the release, displays no choices, and invokes the configured `BallotPrinter`.
- A verified paper ballot is deposited into an encrypted sealed-ballot record during voting. Candidate totals remain unavailable.
- Closing polls opens sealed records through the existing counting validation service, after which reconciliation, tally, and Election Return ceremonies continue normally.
- The poll-watcher view exposes only operational deposited-ballot counts before results are officially available.
- The full browser walkthrough now drives this private voter journey instead of the superseded operator print queue.
- Focused private voter journey coverage passed: 4 tests and 103 assertions.
- Legacy ballot generation, printing, counting, and full-demo scenario coverage passed: 4 tests and 49 assertions.
- Vue production build and browser-recorder syntax checks passed.
- Recorded one-voter full-election rehearsal passed with 45 completed actions, 40 screenshots, 40 storyboard frames, no browser console messages, and a verified final archive.
- Persisted private-voter rehearsal: `storage/app/election/runs/20260724-110337-556352-39010001-browser-full-election`.
- The walkthrough now records the unmarked ballot, each of 22 deterministic candidate selections, the complete review screen, and private finalization as separate storyboard checkpoints.
- Expanded voter-UI rehearsal passed with 69 completed actions, 64 screenshots, 64 storyboard frames, no browser console messages, and a verified final archive.
- Persisted expanded voter-UI rehearsal: `storage/app/election/runs/20260724-111946-696359-39010001-browser-full-election`.
