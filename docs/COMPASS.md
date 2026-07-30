# Alternative Election System

# Architecture Compass

## Implementation Status

- Completed Slice: Public Admission Capacity and Independent VVDAT Export Verification
- Active Program: Public Election Simulation Server
- Current Slice: Concurrent-voter race scenarios, controlled reset, and public release policy
- Next Slice: Deterministic multi-voter concurrent scenario and close-vs-finalize race coverage
- Program Compass: `docs/PUBLIC_SIMULATION_SERVER_COMPASS.md`

## Domains, Waves, and Vertical Slices

> **Working Draft**
>
> This document is the engineering compass for the Alternative Election System.
>
> It is not an implementation plan.
>
> It is not a project schedule.
>
> It is the architectural roadmap that allows the team to continuously answer:
>
> - Where are we now?
> - What remains to be built?
> - What belongs together?
> - What should be implemented next?
>
> Every future implementation plan should derive from this document.

---

# 1. Philosophy

The project shall be developed using **vertical slices**.

Each slice must produce a working increment of the system.

No wave should exist merely to "prepare infrastructure."

Every wave should produce something demonstrable.

---

# 2. Development Strategy

The project is divided into three dimensions.

```
Domains
        ↓
Waves
        ↓
Vertical Slices
```

The domains remain relatively stable.

Waves represent maturity.

Slices represent implementation work.

---

# 3. Domains

The current architecture is divided into the following domains.

```
Election Core

Lifecycle

Preparation

Voting

Printing

Counting

Election Return

Evidence

Certification / FTS

Custody

Transmission

Audit

Scenario Runner

Diagnostics

Devices

Infrastructure
```

These domains intentionally describe business capabilities rather than software packages.

---

# 4. Domain Overview

## Election Core

The heart of the election.

Responsibilities:

- ceremonies
- state transitions
- domain models
- legal procedure boundaries

---

## Lifecycle

Responsibilities:

- ceremony ordering
- workflow engine
- navigation
- timeline
- next-action guidance

---

## Preparation

Responsibilities:

- national registries
- Election Package
- precinct activation
- deterministic mapping
- configuration persistence

---

## Voting

Responsibilities:

- voting session
- ballot navigation
- review
- finalization
- QR generation

---

## Printing

Responsibilities:

- print jobs
- printer abstraction
- PDF rendering
- ESC/POS rendering
- print evidence

---

## Counting

Responsibilities:

- QR decoding
- tally journal
- ballot validation
- counting workflow
- temporary tally generation

---

## Election Return

Responsibilities:

- Election Return generation
- printable returns
- digital return artifacts
- return signing and posting evidence

---

## Evidence

Responsibilities:

- activity journals
- legal Minutes
- reports
- certificates
- receipts
- printed artifacts
- QR artifacts
- generated forms
- signatures and attestations

---

## Certification / FTS

Responsibilities:

- COMELEC Final Testing and Sealing
- certification ballots
- diagnostics
- initialization report
- manual verification
- comparison
- VVPAT or approved equivalent verification
- zero-out
- sealing readiness

---

## Custody

Responsibilities:

- envelopes
- paper seals
- ballot boxes
- storage devices
- evidence containers
- recipients
- turnover
- chain of custody

---

## Transmission

Responsibilities:

- official handoff of election artifacts
- Official Handoff as the transmission ceremony
- Manual Handoff as the first delivery driver
- Delivery Package generation
- export checksum or hash evidence
- recipient acknowledgement
- Delivery Receipts and transmission reports
- future delivery drivers such as SD cards, USB storage, LTE, REST APIs, government networks, satellites, and future technologies

---

## Audit

Responsibilities:

- evidence reconciliation
- manual audit
- independent recount
- custody review
- verification

---

## Scenario Runner

Responsibilities:

- deterministic scenarios
- lifecycle replay
- hardware simulation
- legal ceremony simulation
- acceptance testing

---

## Diagnostics

Responsibilities:

- logs
- hashes
- versions
- printer health
- scanner health
- technician tools
- diagnostic reports

---

## Devices

Responsibilities:

- Raspberry Pi
- printers
- scanners
- cameras
- tablets
- storage media

---

## Infrastructure

Responsibilities:

- PWA
- storage
- security
- adapters
- configuration

---

# 5. Waves

The project shall evolve through successive waves.

Each wave leaves behind a usable system.

---

# Wave 1

## Foundation

Objective

Build a functioning precinct appliance.

Deliverables

- Laravel application
- Vue PWA
- lifecycle shell
- dictionary
- Scenario Runner
- diagnostics shell

No election logic yet.

---

# Wave 2

## Preparation

Deliverables

- nationwide registries
- Election Package
- deterministic mapping
- activation
- configuration persistence

End Result

Generic appliance becomes a precinct appliance.

---

# Wave 3

## Certification

Deliverables

- Final Testing and Sealing workflow
- certification summary report
- diagnostic report
- initialization report
- certification ballots
- manual verification
- zero-out
- sealing evidence
- readiness verification

End Result

Certified and sealed precinct.

---

# Wave 4

## Voting

Deliverables

- voting UI
- review
- finalize
- QR generation
- tablet workflow

End Result

Electronic ballot completed.

---

# Wave 5

## Printing

Deliverables

- print abstraction
- PDF driver
- ESC/POS driver
- print journal
- spoilage workflow

End Result

Official paper ballot.

---

# Wave 6

## Counting

Deliverables

- QR scanning
- append-only counting journal
- tally generation
- counting UI

End Result

Machine-assisted counting.

---

# Wave 7

## Election Return

Deliverables

- Election Return
- printable reports
- QR representation
- digital artifacts
- return signing and posting evidence

End Result

Official precinct result.

---

# Wave 8

## Transmission and Custody

Deliverables

- Official Handoff workflow
- Delivery Package
- export checksum or hash evidence
- Delivery Receipt
- recipient acknowledgement
- future delivery-driver extension point
- custody records
- envelopes and seals
- final backup
- turnover checklist

End Result

Official election artifacts handed off with custody evidence; election artifacts secured.

---

# Wave 9

## Audit

Deliverables

- manual audit support
- independent recount
- reconciliation
- comparison reports

End Result

Public confidence.

---

# Wave 10

## Hardening

Deliverables

- backup appliance
- recovery
- performance
- stress testing
- resilience

End Result

Election-ready appliance.

---

# 6. Vertical Slice Strategy

Every implementation task should complete a vertical path.

Example

```
Voting

↓

Domain

↓

Action

↓

Storage

↓

API

↓

Vue

↓

Scenario

↓

Tests
```

Nothing should be implemented halfway.

---

# 7. Testing Strategy

Every slice should include:

Unit Tests

↓

Integration Tests

↓

Scenario Tests

↓

Hardware Tests

↓

Certification Tests

---

# 8. User Interface Strategy

The UI is organized around ceremonies.

Not administration.

Primary ceremonies:

```
Provision

Final Testing and Sealing

Open Polls

Voting

Close Polls

Counting

Election Return

Transmission

Final Backup

Custody Turnover

Close Precinct
```

The application always displays one primary action.

The UI may use simpler local labels, but the underlying ceremony model must remain legally explicit. "Certification" is a product concept; **Final Testing and Sealing** is the legal ceremony it implements.

---

# 9. Adapter Strategy

All hardware shall be abstracted.

Adapters include:

```
Printer

Scanner

Camera

Storage

Signing

Export

Import
```

Business logic shall never depend on hardware.

---

# 10. Cross-Cutting Concerns

The following capabilities apply across every domain.

- Domain Dictionary
- Evidence
- Activity Journals
- Minutes
- Scenario Runner
- Certification
- Diagnostics
- Custody
- Hashing
- Signatures
- Reporting

---

# 11. Extraction Candidates

The following concepts may eventually become reusable packages.

Not before they stabilize.

Potential candidates include:

- Certification
- Evidence
- Custody
- Scenario Runner
- Election Dictionary
- Printer Abstraction
- QR Package Loader
- Device Management

No extraction shall occur until practical experience justifies reuse.

---

# 12. Completion Criteria

A wave is complete only when:

- functionality exists;
- UI exists;
- Scenario Runner supports it;
- automated tests exist;
- hardware tests pass;
- documentation is updated.

---

# 13. Current Position

Current implementation status:

- Current wave: Wave 9 - Hardening
- Completed slice: Multi-Tablet Review Room
- Next recommended slice: Concurrent voting hardening, followed by the COMELEC Review Kit

Completed:

- architectural vision
- strategy
- customer journey
- precinct preparation narrative
- functional specification
- architecture compass
- ceremony-driven browser lifecycle from precinct activation through audit
- deterministic POP and CLC precinct configuration for clustered precinct 39010001
- ballot finalization, printing, spoilage, scanning, tally, Election Return, handoff, custody, and archive verification
- recorded WebM walkthrough, Playwright trace, full-page screenshots, and readable viewport frames
- offline HTML, 49-page PDF, and structured JSON storyboard with precise COMELEC review notes and screenshot hashes
- final evidence TAR containing the storyboard and its source captures, re-hashed with zero mismatches
- protected Laravel Cloud review mode with PHP 8.4, deployment migrations, continuous demonstration availability, PostgreSQL, Redis, and private evidence storage
- role-paired Multi-Tablet Review Room for one officer, five default voter tablets, private printing, watcher observation, and a projection-safe presentation screen
- signed QR joins, one-browser station binding, role-restricted routes, connectivity status, and an append-only hash-chained room journal
- public three-precinct simulation lobby with isolated evidence roots, Device Tabulation with Paper Audit closeout, sealed VVDAT freeze, tally/ER watcher publication, policy-controlled anonymized VVDAT export, and a non-destructive archive/reset flow
- public precinct Random Manual Audit room: deterministic sample selection, selected paper QR comparison, dual officer approval, reconciliation, and separate PDF evidence without mutation of official result artifacts
- deterministic three-voter public precinct exercise that proves repeated admission, private vote, print/deposit, device closeout, tally, and neighbouring-precinct storage isolation

Next:

Exercise five and ten independent voter flows through verified evidence
bundles, including close-versus-finalize races and backpressure behaviour.
Then add RMA camera capture, discrepancy handling, watcher-safe audit
publication, and package the results into the offline COMELEC Review Kit.

---

# 14. Guiding Principle

The compass exists to keep the project moving in one direction.

Whenever uncertainty arises, ask three questions:

**Which domain does this belong to?**

**Which wave should introduce it?**

**Which vertical slice will prove it works?**

If those questions can be answered clearly...

the implementation will remain simple, incremental, testable, and understandable.

That is the purpose of this compass.
