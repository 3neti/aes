# Alternative Election System Functional Specification

## Purpose

AES provides a ceremony-driven precinct appliance for election demonstrations,
precinct rehearsals, and future field deployment. It helps election workers run
setup, voting, printing, counting, election-return generation, watcher review,
audit, and handoff ceremonies while preserving voter secrecy and inspectable
evidence.

The system is simulation-first in this repository, but its target operating model
is an offline local appliance connected to voter tablets, a printing station, and
scanner devices.

## Operating Principles

- Paper ballots remain voter-visible evidence.
- The appliance assists election procedure; it does not replace legal election
  authority.
- The system must continue without internet access after commissioning.
- The operator experience must follow election ceremonies rather than generic
  administration.
- Every important action must produce a journal entry or artifact.
- Hardware is accessed through adapters.
- Printed artifacts, append-only files, signatures, and manifests are primary
  evidence.
- Database state and browser state are convenience layers.
- Deterministic scenario and browser walkthroughs must be able to reproduce core
  lifecycles.

## Election Profiles

### Device Tabulation with Paper Audit

This is the default profile. The voter marks a ballot electronically, verifies a
printed paper ballot, and deposits it. The appliance seals a VVDAT record for
closeout. At close of polls, the system counts sealed VVDAT records and produces
the tally sheet and election returns. Paper ballots remain available for Random
Manual Audit and dispute resolution.

### Paper-First QR Scan Tally

This alternate profile counts by scanning the Truth QR payload from each paper
ballot. It supports a paper-led closeout while still avoiding manual reading of
every candidate name.

### Hybrid Verification

The appliance may tally from sealed VVDAT records and then audit a sample or full
set of paper ballots through Truth QR scans.

## Primary Actors

- **Election Officer** opens the precinct, admits voters, manages printing,
  closes polls, generates tallies and returns, publishes watcher packets, and
  performs handoff.
- **Voter** receives or generates a Voter Control Number, marks the ballot,
  reviews selections, finalizes, receives a Voter Print PIN, verifies the paper
  ballot, and deposits it.
- **Printing Station Operator** redeems print PINs and produces paper ballots,
  tally sheets, and election returns through the configured print profile.
- **Poll Watcher** views safe tally, ballot-artifact, election-return, log, and
  download materials.
- **Auditor** scans paper ballot Truth QR payloads for Random Manual Audit,
  records matches or discrepancies, and produces audit evidence.
- **Canvassing Operator** scans or ingests election-return evidence and verifies
  Truth QR, EML, signatures, manifests, and duplicate status before canvassing.

## Token Model

### Voter Control Number

A short code authorizing one voter to begin marking a ballot. In operational
mode, this is issued by the Election Officer after physical voter verification.
In demo mode, the voter can request one directly to reduce presentation friction.
The control number is not a ballot payload and must not reveal selections.

### Voter Print PIN

A separate short code generated after the voter finalizes selections. It allows
the printing station to print the paper ballot without showing candidate choices
on the station screen. The print PIN is short-lived and one-use.

### Paper Ballot Serial

A paper ballot serial identifies the printed artifact for evidence and duplicate
detection. It is included in the paper ballot and Truth QR payload but must not
identify the voter.

## Truth QR Requirements

Truth QR payloads must carry compact election meaning rather than a mere remote
database identifier.

Ballot Truth QR payloads include:

- protocol version;
- election identifier;
- precinct identifier;
- ballot style or document profile;
- candidate mapping hash;
- paper ballot serial;
- selected candidate codes;
- payload hash; and
- optional issuer authentication.

Election-return Truth QR payloads include:

- election and precinct identity;
- return scope, such as national, local, or combined;
- tally or result hash;
- candidate code totals;
- reconciliation values;
- document profile;
- EML profile and artifact hash when available;
- issuer key identifier and signature; and
- multipart QR metadata when the payload is split across several symbols.

The receiving verifier resolves candidate codes only through a locally
commissioned mapping with the expected mapping hash.

## Core Ceremony Flow

1. Prepare precinct data, candidate registries, mappings, print profiles, and
   officer defaults where review mode permits them.
2. Certify configuration and known test ballots.
3. Open the precinct.
4. Admit a voter using a Voter Control Number.
5. Let the voter mark, navigate, review, and finalize the ballot privately.
6. Generate a Voter Print PIN.
7. Print the selected-candidate paper ballot at the private print station.
8. Deposit the paper ballot and seal VVDAT evidence where the active profile
   requires it.
9. Repeat voting until closeout.
10. Close polls and freeze admissions.
11. Count according to the active tabulation profile.
12. Generate tally sheet, national election return, and local election return.
13. Publish watcher-safe artifacts.
14. Conduct Random Manual Audit or QR-assisted verification.
15. Archive evidence and perform handoff.

## User Interface Requirements

- The first screen for election workers must show the current ceremony, next
  action, and evidence status.
- Role-demo pages must expose Election Officer, Voter, Poll Watcher, Auditor,
  and supporting print/canvass perspectives.
- Voter ballot UI must preserve position navigation and surname navigation.
- Paper-facsimile ballot UI must minimize accidental selection during scrolling.
- Demo mode may expose helper controls such as automatic selection filling,
  previews, and QR inspection panels.
- Election-day mode must hide or disable demo-only shortcuts.

## Printed Artifacts

The system must generate:

- selected-candidate paper ballot;
- ballot QR verification page or payload panel for reviewers;
- tally sheet;
- national election return;
- local election return;
- election-return Truth QR pages where needed;
- handoff instructions;
- Random Manual Audit reports;
- evidence manifests; and
- EML evidence package artifacts where enabled.

Print output must support both full-page and constrained thermal profiles where
practical.

## Watcher and Audit Requirements

- Watchers may inspect published tally, election returns, ballot artifact
  previews, logs, and downloads according to publication state.
- Watcher views must not expose voter identity.
- Auditors may scan ballot Truth QR payloads and record match or discrepancy
  results.
- Audit findings never silently change the official tally or election return.

## Canvassing and EML Requirements

EML is an interoperability representation at controlled boundaries. It is not
the internal database, not the canonical tally, and not the routine ballot QR
format.

The system currently supports a WAES EML 7 base profile for:

- EML 110 election event;
- EML 230 candidate list;
- EML 410 ballot definition;
- EML 480 audit log;
- EML 510 precinct count;
- EML 520 canvass result; and
- EML 530 statistics.

Configuration import is staging-only. A future package intake ceremony must
authorize custody, signatures, duplicates, and supersession before any EML
package can affect the canonical canvass.

## Evidence Requirements

The system must preserve:

- precinct configuration and mapping hashes;
- activity journals;
- voter admission records without voter choices;
- sealed ballot or VVDAT records;
- paper ballot and print artifacts;
- scanner ledgers;
- tally files;
- election return files;
- Truth QR payloads and decoded mappings;
- EML artifacts, signatures, and manifests when enabled;
- watcher publication manifests;
- Random Manual Audit reports; and
- evidence bundle manifests.

## Non-Goals

- The system does not determine voter eligibility.
- The system does not require live internet transmission for the core precinct
  flow.
- EML support does not claim COMELEC certification without an authoritative
  COMELEC profile and acceptance criteria.
- Demo shortcuts are not election-day controls.
