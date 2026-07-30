# Public Election Simulation Server Compass

## Objective

Create a public, education-first election simulation where participants can join a chosen precinct, experience the roles of voter, Election Officer, watcher, or supervised observer, and understand the full election lifecycle without presenting the service as a live election system.

The simulation uses **Device Tabulation with Paper Audit** as its default profile:

1. A voter is admitted by an Election Officer using a one-use four-digit control code.
2. The voter marks an electronic ballot privately.
3. The device creates an anonymous, sealed VVDAT record and a voter-verifiable printed paper ballot with a QR code.
4. The paper ballot is deposited and retained as audit evidence.
5. At close of polls, the device opens and counts the sealed VVDAT ledger.
6. The system produces the tally sheet and Election Return from that ledger.
7. QR paper ballots are used only for the Random Manual Audit, never for default routine counting.

## Scope Boundary

This is a public demonstration and learning environment, not an official election service, voter registry, identity system, or binding tabulation system. Every public page, artifact, export, and room must state this clearly.

The offline precinct appliance remains the operational model. The Cloud-hosted simulation is a separately identified rehearsal environment with no authority over election-day records.

## Non-Negotiable Rules

1. Ballot secrecy survives transparency features.
2. No participant identity is linked to a VVDAT record, printed ballot, QR payload, control code, or published vote.
3. Default result generation counts sealed VVDAT records only after the close-polls ceremony.
4. Paper ballots remain the physical audit evidence; their QR codes support sampled Random Manual Audit only.
5. No vote totals, contest totals, or individual ballots are published before polls close.
6. Every admission, finalization, deposit, close, tally, publication, download, RMA action, and role change is journaled.
7. God Mode is simulation-only and privacy-preserving. It may show lifecycle state, station readiness, timing, artifacts, and anonymized activity, but must not reveal a live participant's ballot choices.
8. Public exports are immutable, hash-addressed, and labelled with their simulation, precinct, close time, and publication policy.
9. A scenario run must be deterministic and reproducible from its package, configuration, journals, and sealed ledger.

## Personas

| Persona | Purpose | Permitted view |
| --- | --- | --- |
| Participant voter | Experiences admission, ballot marking, private release, verification, and deposit. | Own ballot while active; no totals. |
| Election Officer / host | Creates a simulation, selects a precinct, operates ceremonies, admits participants, and closes polls. | Ceremony controls, station state, aggregate operational counts. |
| Poll watcher | Observes procedures and independently checks published outputs after close. | Ceremony timeline, published tally/ER, approved anonymous VVDAT audit export, RMA evidence. |
| God Mode facilitator | Explains the simulation in a classroom, presentation, or review setting. | Anonymized multi-role telemetry, journals, artifact status, and consented demo captures. |
| Guest observer | Learns the process without casting a vote. | Public lifecycle and post-close published material. |

## Privacy Model

- A control code is a one-use admission token, not a voter identity.
- Station IDs, room events, and timestamps are operational metadata. They are never placed in a public VVDAT export when they could help correlate a voter with a ballot.
- A published VVDAT audit export is generated only after close, after officer publication approval, and uses a deterministic privacy transformation: records are identity-free, timestamp-free, station-free, and deterministically shuffled. It includes the ledger root, record hashes, selections, and export hash.
- Individual ballot displays are disabled by default. Any classroom demonstration of a ballot must use explicit training fixtures, not participant votes.
- God Mode screenshots show state transitions and redacted participant screens. They never mirror a live voter ballot or raw release QR.

## Default Ceremony

```text
Create simulation / select precinct
  -> load and certify package
  -> open precinct and polls
  -> admit participants with four-digit codes
  -> private vote, print, verify, deposit, seal VVDAT record
  -> close polls and freeze admissions
  -> validate and count sealed VVDAT ledger
  -> reconcile physical ballot count
  -> print tally sheet and Election Return
  -> publish watcher package and approved VVDAT audit export
  -> run sampled QR Random Manual Audit
  -> close simulation and archive evidence
```

## Evidence Model

```text
04-voting/
  voter-authorizations/               one-use anonymous admission records
  device-tabulation-vvdat-ledger/     one sealed record per deposited ballot
  ballots/                             print evidence and canonical ballot artifacts
  private-print-releases/              short-lived protected release evidence

05-closing-of-polls/
  close-polls-legal-evidence.json
  vvdat-ledger-freeze.json

06-counting-and-tally/
  vvdat-count-validation.json
  tally.json
  tally-sheet.pdf
  print-forms/tally-sheet/

07-election-return/
  {precinct}-return.json
  {precinct}-return.pdf
  print-forms/{precinct}/
  watcher-publication.json
  vvdat-audit-export.jsonl
  vvdat-audit-export-manifest.json

12-audit-and-reconciliation/
  random-manual-audit/
  evidence-manifest.json
  evidence-bundle-archive.tar
```

## Current Position

Already implemented and reusable:

- Device Tabulation with Paper Audit is the default tabulation profile.
- A sealed per-ballot VVDAT ledger exists and becomes the tally source after close.
- Routine QR scanning is blocked under the default profile.
- Random Manual Audit has a separate QR scan, comparison, dual-approval, discrepancy, and reconciliation flow.
- Multi-Tablet Review Room has officer, voter, print-station, watcher, and presentation roles.
- Journal, evidence bundle, print forms, tally sheet, Election Return, and deterministic scenario infrastructure exist.

Implemented in the Public Simulation Server:

- A public three-card precinct lobby at `/election/play` creates one simulation round with three Tondo demonstration precincts.
- Each precinct is isolated in `storage/app/election/public-simulations/{round}/{precinct}/runs`; its active run, journal, releases, VVDAT ledger, tally, and Election Return never share another card's storage root.
- Each precinct receives a generated officer code and a simulation PIN. The code is not shown on the public card and is intended for manual handoff to its volunteer officer.
- A precinct officer opens polls, issues anonymous four-digit control numbers, and closes the precinct with the assigned credentials.
- A voter enters through a common precinct QR/link, claims a control number, marks the existing POP/CLC ballot, obtains a private print release, and never sees any tally.
- The private print station redeems the release, prints with the existing adapter, and deposits a sealed ballot. Deposit records one VVDAT ledger entry.
- Close records physical-ballot reconciliation, tallies the sealed VVDAT ledger, generates the existing tally and Election Return PDFs, and exposes a human-readable watcher view with download links.
- Close writes `06-counting-and-tally/vvdat-ledger-freeze.json`, binding the exact ledger record count and root hash before tabulation. The ledger rejects any later deposit.
- Watcher publication is a distinct officer action. It writes `07-election-return/publication-manifest.json` with hashes for the frozen ledger, tally, PDFs, and Election Return; no watcher result is available before it exists.
- Watchers can download a post-publication VVDAT audit export. It retains only record hashes and selections in deterministic order, and removes ballot IDs, paper serials, timestamps, authorization data, and print-release data.
- `php artisan election:public-simulation:verify-vvdat-export /path/to/export.json` independently checks a downloaded export's content hash, unique record hashes, count, and non-zero tally without reading the precinct ledger.
- Admission issuance observes `ELECTION_PUBLIC_SIMULATION_MAX_ACTIVE_ADMISSIONS` (default `10`) inside a precinct-scoped election lock. The public admission endpoint is also throttled.
- `election:public-simulation:archive {round}` archives only fully published rounds and preserves every precinct evidence namespace.
- `election:public-simulation:reset {round}` is the controlled start-fresh operation: it archives a fully published round first, retains all evidence, then creates a new three-precinct lobby. It refuses to replace an unrelated live round.
- Watcher VVDAT downloads now follow an explicit release policy. `ELECTION_PUBLIC_SIMULATION_VVDAT_AUDIT_EXPORT_ENABLED` enables the export and `ELECTION_PUBLIC_SIMULATION_VVDAT_AUDIT_EXPORT_MINIMUM_RECORDS` sets the minimum sealed-record count. A withheld export is not linked or downloadable.
- A privacy-redacted God Mode page is scaffolded at `/election/play/{round}/god-mode`, disabled by default through `ELECTION_PUBLIC_SIMULATION_GOD_MODE_ENABLED`.

Not yet implemented:

- Public participant enrollment and safe invite links beyond the current anonymous control-number admission.
- Officer-host creation flow, credential-handoff artifact, and configurable public simulation schedules.
- A separate officer approval ceremony for the VVDAT export; the current policy is configured per environment.
- Expanded God Mode authorization, classroom replay fixtures, and screen/capture controls.
- Multi-precinct concurrency-race scenarios, queue/backpressure policy, and abuse controls beyond the current admission capacity/throttle.
- Public education copy, consent, retention, and deletion policy.

## Update Rule

Update this compass after every public-simulation slice. A slice is complete only when its privacy assertions, lifecycle scenario, browser workflow, evidence artifacts, and documentation are tested and committed.
