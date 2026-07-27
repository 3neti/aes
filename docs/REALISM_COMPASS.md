# Precinct Realism Compass

## Objective

Evolve the deterministic simulation into a credible offline precinct appliance while preserving paper authority, ceremony discipline, reproducibility, and inspectable evidence.

## Current Position

The precinct realism program is complete for simulation mode. The browser and scenario lifecycle use the configured Tondo POP + CLC package, preserve isolated election-day and rehearsal evidence, account for serialized paper ballots, require dual-control approvals, inspect evidence-chain integrity after restart, and pass a persisted 50-ballot rehearsal with full automated verification. Generated ballots, tally sheets, and Election Returns now use deterministic A4 COMELEC-oriented layouts with complete candidate listings, repeated pagination controls, and a scannable embedded ballot QR.

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
| Printed artifact review            | Complete | The storyboard renders actual generated PDF pages with source paths and hashes for independent COMELEC print-form critique.                                |
| COMELEC print-form redesign        | Complete | A4 ballot, tally, and Return forms paginate all 387 candidates, reserve officer certification areas, and embed a page-capture-decodable ballot QR.         |
| Review mode and temporary defaults | Complete | Review forms load server-supplied simulation values; election-day fields remain empty and signatures/approvals remain manual.                              |
| Cloud review protection            | Complete | Review access is protected and responses explicitly prohibit search-engine indexing before credentials or evidence are enabled.                            |
| Cloud runtime alignment            | Planned  | Cloud runs PHP 8.4, matching local development, CI, and the declared project platform.                                                                     |
| Cloud deployment migration gate    | Planned  | Successful deployments apply pending migrations non-interactively against persistent storage.                                                              |
| Demonstration availability         | Planned  | Hibernation is disabled for the scheduled review window and the cost/availability decision is recorded.                                                    |
| Multi-tablet Review Room           | Planned  | Officer, voter, print, watcher, and presentation tablets join one isolated rehearsal without cross-role or cross-voter disclosure.                         |
| Concurrent voting hardening        | Planned  | Locks and idempotency prevent duplicate serials, journal events, releases, deposits, and scans under simultaneous tablet activity.                         |
| Cloud evidence storage             | Planned  | Private shared review storage survives request routing and restart while the local appliance filesystem remains the operational default.                   |
| COMELEC Review Kit                 | Planned  | One offline package contains the brief, storyboard, video, forms, reports, evidence, checklist, known gaps, and hashes.                                    |
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

Cloud Runtime Alignment, followed by deployment migrations, demonstration availability, shared persistence, and the Multi-Tablet Review Room. The supervised offline hardware pilot remains the next field-validation program.

## Temporary Review Defaults

- Defaults are enabled only by an explicit simulation/review environment flag.
- Values come from server-side configuration and are never compiled into the Vue application.
- Setup defaults include simulation officer codes/PINs, device identities, ballot stock, box/envelope IDs, and seal numbers.
- Ceremony defaults cover officer code/PIN entry for opening, voter admission, attestation, counting, Return approval, and handoff testing.
- Signatures, observed physical counts, adjudication decisions, acknowledgements, recipient identities, and final approvals remain deliberate human acts.
- Prefilled values never auto-submit a form or constitute approval.
- Election-day mode exposes no temporary review credentials or field defaults.

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
- The walkthrough now renders every generated printable PDF page into a final `Printed Artifacts for Review` storyboard ceremony.
- The ballot, tally sheet, and Election Return are mandatory; supporting handoff, backup, and custody documents are included when present.
- Each review page records the original PDF path, page count, byte count, source PDF SHA-256, rendered page SHA-256, and a document-specific COMELEC review checklist.
- Persisted print-review rehearsal: `storage/app/election/runs/20260724-113305-224964-39010001-browser-full-election`.
- Print-review result: 72 storyboard checkpoints, 8 rendered documents/pages, zero browser messages, and a 236-file final archive verified with zero mismatches.
- The initial print review exposed missing QR embedding and truncated result forms; the COMELEC print-form redesign below closes those simulation defects.

## COMELEC Print-Form Redesign

- Added a deterministic A4 PDF document engine with repeated COMELEC-oriented headers, page numbers, precinct/document identity, legal-source notice, crisp embedded PNG support, and lossless multi-page output.
- Replaced generic ballot output with a voter-verifiable selection form containing election and paper-stock identity, complete selected-candidate names, an embedded QR, payload hash, spoilage guidance, and voter verification instructions.
- Increased the standard QR source to 740 by 740 pixels and prints the ballot QR at approximately 65 mm with interpolation disabled.
- Replaced truncated tally and Election Return output with complete contest tables in activated ballot order, including every zero-vote candidate, repeated table headers, contest limits, hashes, reconciliation, certification, and Electoral Board signature areas.
- Supporting evidence PDFs now paginate all supplied lines instead of dropping content after one page.
- Persisted acceptance rehearsal: `storage/app/election/runs/20260724-163307-733872-39010001-browser-full-election`.
- Real Tondo output contains a two-page ballot, 12-page tally sheet, and 13-page Election Return covering all 387 candidates.
- The 2,901-byte QR payload decoded from the 144-dpi rendered ballot page and matched the source payload exactly.
- The final evidence archive verified 260 files with zero mismatches.
- These remain simulation forms pending COMELEC approval of prescribed wording, copy distribution, typography, paper size, and signature requirements.
