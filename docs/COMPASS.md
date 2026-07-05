# Alternative Election System
# Architecture Compass
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

Precinct Preparation

Certification

Voting

Printing

Counting

Election Return

Audit

Scenario Runner

Devices

Lifecycle

Diagnostics

Infrastructure
```

These domains intentionally describe business capabilities rather than software packages.

---

# 4. Domain Overview

## Election Core

The heart of the election.

Responsibilities:

- election lifecycle
- ceremonies
- state transitions
- domain models

---

## Precinct Preparation

Responsibilities:

- national registries
- Election Package
- precinct activation
- deterministic mapping
- configuration persistence

---

## Certification

Responsibilities:

- Friday certification
- certification ballots
- certification reports
- operational checkpoints
- officer attestations

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
- print journaling

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
- reports
- printable artifacts
- digital artifacts

---

## Audit

Responsibilities:

- journals
- reconciliation
- manual audit
- independent recount
- verification

---

## Scenario Runner

Responsibilities:

- deterministic scenarios
- lifecycle replay
- hardware simulation
- acceptance testing

---

## Devices

Responsibilities:

- Raspberry Pi
- printers
- scanners
- cameras
- tablets

---

## Lifecycle

Responsibilities:

- ceremonies
- workflow engine
- navigation
- timeline

---

## Diagnostics

Responsibilities:

- logs
- hashes
- versions
- printer health
- scanner health
- technician tools

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

## Precinct Preparation

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

- certification workflow
- certification reports
- certification ballots
- Friday rehearsal
- readiness verification

End Result

Certified precinct.

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

End Result

Official precinct result.

---

# Wave 8

## Audit

Deliverables

- manual audit support
- independent recount
- reconciliation
- comparison reports

End Result

Public confidence.

---

# Wave 9

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

Certification

Open Polls

Voting

Close Polls

Counting

Election Return

Close Precinct
```

The application always displays one primary action.

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
- Journaling
- Scenario Runner
- Certification
- Diagnostics
- Hashing
- Signatures
- Reporting

---

# 11. Extraction Candidates

The following concepts may eventually become reusable packages.

Not before they stabilize.

Potential candidates include:

- Certification
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

The project currently resides before Wave 1.

Completed:

- architectural vision
- strategy
- customer journey
- precinct preparation narrative
- functional specification
- architecture compass

Next:

Prepare the detailed implementation plan beginning with Wave 1.

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
