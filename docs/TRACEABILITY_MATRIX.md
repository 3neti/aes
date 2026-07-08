# Alternative Election System
# Requirements Traceability & Verification Specification (RTVS)
## Requirements Traceability Matrix, Test Strategy, Test Cases, and Acceptance Verification

> **Document Status:** Draft 1.0
>
> This document defines how every business requirement of the Alternative Election System shall be verified.
>
> It establishes:
>
> - the Requirements Traceability Matrix (RTM);
> - the verification strategy;
> - the testing philosophy;
> - the hierarchy of automated and operational tests;
> - user acceptance testing;
> - certification testing;
> - and post-election verification.
>
> The objective is simple:
>
> **No requirement shall exist without a corresponding verification.**

---

# Table of Contents

1. Philosophy
2. Verification Layers
3. Requirements Traceability Matrix
4. Traceability Rules
5. Testing Hierarchy
6. Test Categories
7. Lifecycle Verification
8. Functional Test Cases
9. Hardware Verification
10. Certification Verification
11. User Acceptance Testing
12. Regression Strategy
13. Post-Election Verification
14. Completion Criteria

---

# 1. Philosophy

The Alternative Election System shall be developed using evidence-driven engineering.

Every important capability shall be:

- implemented;
- tested;
- replayable;
- reproducible;
- certifiable.

Testing is not an activity performed after implementation.

Testing is part of the architecture.

---

# 2. Verification Layers

The project adopts multiple verification layers.

```text id="xyx3lh"
Business Requirement

↓

Functional Requirement

↓

Architecture

↓

Implementation

↓

Unit Test

↓

Integration Test

↓

Scenario Test

↓

Hardware Test

↓

Certification

↓

User Acceptance Test

↓

Operational Audit
```

Failure at any layer shall prevent progression to the next.

---

# 3. Requirements Traceability Matrix

Every requirement shall be traceable.

The following chain shall exist.

```text id="m3n8m6"
Business Requirement

↓

Functional Requirement

↓

Domain

↓

Wave

↓

Vertical Slice

↓

Use Case

↓

Scenario

↓

Implementation

↓

Automated Test

↓

Certification

↓

Documentation
```

Nothing shall be implemented without traceability.

Nothing shall be tested without a requirement.

---

# 4. Traceability Matrix Structure

Each requirement shall contain:

| Field | Description |
|--------|-------------|
| Requirement ID | Unique identifier |
| Business Requirement | Original objective |
| Functional Requirement | System behavior |
| Domain | Functional domain |
| Wave | Development wave |
| Slice | Vertical slice |
| Use Case | User interaction |
| Scenario | Lifecycle scenario |
| Tests | Automated verification |
| Evidence | Journals, Minutes, reports, receipts, or artifacts proving the requirement |
| Custody | Containers, seals, recipients, and turnover evidence when applicable |
| Certification | Operational verification |
| Status | Planned / Implemented / Verified |

---

# 5. Example Traceability

| Requirement | Verification |
|-------------|--------------|
| Voter can cast a ballot | Voting Scenario + UAT |
| QR generated after finalization | Unit + Integration + Scenario |
| Printer can print ballot | Hardware Certification |
| Backup Pi reproduces tally | Recovery Scenario |
| Election Return generated | Scenario + Certification |
| Manual recount supported | Audit Test |

---

# 6. Testing Hierarchy

The project adopts the following testing hierarchy.

## Level 1

Unit Tests

Verify:

- value objects
- services
- business rules
- deterministic mapping
- QR payloads

Technology:

- Pest

---

## Level 2

Integration Tests

Verify:

- repositories
- actions
- storage
- adapters

Technology:

- Pest

---

## Level 3

Component Tests

Verify:

- Vue components
- interaction
- validation
- rendering

Technology:

- Vitest

---

## Level 4

Feature Tests

Verify:

- complete workflows

Technology:

- Pest

---

## Level 5

Scenario Runner

Verify:

- complete lifecycle

Technology:

- Scenario Runner

---

## Level 6

Hardware Tests

Verify:

- printer
- scanner
- Raspberry Pi
- tablet

---

## Level 7

Final Testing and Sealing

Verify:

- operational readiness
- diagnostic evidence
- initialization evidence
- manual verification
- zero-out
- sealing

---

## Level 8

Election Day

Real-world execution.

---

## Level 9

Post-Election Audit

Independent verification.

---

# 7. Test Categories

The following categories shall be maintained.

## Functional

Does the system perform correctly?

---

## Regression

Does existing functionality remain correct?

---

## Hardware

Does physical equipment operate correctly?

---

## Performance

Can the appliance perform under load?

---

## Recovery

Can hardware be replaced?

---

## Security

Can integrity be verified?

---

## Certification

Can the precinct be certified?

---

## Audit

Can the election be independently reproduced?

---

# 8. Lifecycle Test Cases

Every ceremony shall have a complete test suite.

## Prepare Precinct

Verify:

- package loading
- registry verification
- deterministic mapping

---

## Final Testing and Sealing

Verify:

- certification deck
- printer
- scanner
- hashes
- reports
- initialization report
- manual verification
- VVPAT or equivalent verification
- zero-out
- sealing evidence

---

## Open Polls

Verify:

- authorization
- lifecycle transition

---

## Voting

Verify:

- candidate selection
- review
- finalization
- QR generation

---

## Printing

Verify:

- print job
- journal
- spoilage

---

## Counting

Verify:

- QR decoding
- append journal
- tally

---

## Election Return

Verify:

- totals
- report
- printing
- signing evidence
- posting or distribution evidence

---

## Transmission

Verify:

- destination status
- retransmission
- Transmission Report
- failure or deferral evidence

---

## Final Backup

Verify:

- backup completion
- storage-device evidence
- report or receipt evidence

---

## Custody

Verify:

- envelopes
- paper seals
- ballot box custody
- storage device custody
- recipient turnover
- chain-of-custody record

---

## Close Precinct

Verify:

- lifecycle completion
- exports
- reports
- custody completion

---

# 9. Scenario Runner Tests

Every major workflow shall exist as a scenario.

Examples:

```text id="h2j2p7"
Provision

Final Testing and Sealing

Voting

Spoilage

Counting

Election Return

Transmission

Final Backup

Custody

Recovery

Audit
```

The same scenarios shall execute:

- in CI
- locally
- on Raspberry Pi

---

# 10. Hardware Verification

Hardware verification shall include:

Printer

↓

Scanner

↓

Tablet

↓

Camera

↓

Backup Appliance

↓

Power Recovery

↓

Storage Recovery

↓

Network Recovery

---

# 11. Certification Verification

Final Testing and Sealing shall verify:

- package integrity
- mapping integrity
- printer
- scanner
- Certification Ballots
- expected Election Return
- generated Election Return
- diagnostic report
- initialization report
- manual verification
- VVPAT or approved equivalent verification
- zero-out
- sealing evidence

PASS requires exact agreement.

Certification consumes evidence.

Evidence includes journals, legal Minutes, reports, receipts, certificates, signatures, seals, containers, storage-device records, and custody records.

---

# 12. Certification Ballots

Certification Ballots shall:

- contain known votes;
- produce predetermined tallies;
- produce predetermined Election Returns.

Every appliance shall produce identical results.

---

# 13. User Acceptance Testing

User Acceptance Testing shall involve:

Election Officers

↓

Watchers

↓

Technicians

↓

Pilot Voters

Acceptance focuses on:

- usability;
- simplicity;
- workflow;
- confidence.

---

# 14. Sample UAT Cases

## Voting

User:

Cast a vote.

Expected:

Printed ballot exactly matches selections.

---

## Counting

Operator:

Scan official ballot.

Expected:

Correct tally.

---

## Recovery

Technician:

Replace Raspberry Pi.

Expected:

Election continues.

---

## Certification

Officer:

Run Final Testing and Sealing.

Expected:

PASS.

---

## Evidence

Operator:

Complete a ceremony.

Expected:

Journal evidence and Minutes evidence reference the same legally meaningful event.

---

## Custody

Operator:

Turn over election artifacts.

Expected:

Recipient, container, seal, and custody status are recorded.

---

## Transmission

Operator:

Transmit Election Return.

Expected:

Transmission status is evidenced, including retry or deferral when needed.

---

# 15. Regression Strategy

Every bug shall produce:

- failing test;
- implementation;
- passing test.

Regression tests shall never be removed.

---

# 16. Continuous Verification

Every pull request shall execute:

Unit Tests

↓

Integration Tests

↓

Feature Tests

↓

Scenario Runner

↓

UI Tests

↓

Static Analysis

↓

Build

Failure blocks merge.

---

# 17. Post-Election Verification

Verification includes:

Original Machine

↓

Backup Machine

↓

Manual Recount

↓

Election Return

↓

Certification Reports

↓

Transmission Reports

↓

Minutes

↓

Custody Records

↓

Audit Report

All discrepancies shall be documented.

---

# 18. Acceptance Criteria

A requirement is complete only when:

- implemented;
- documented;
- automated;
- scenario-tested;
- hardware-tested (if applicable);
- certified;
- accepted.

---

# 19. Deliverables

Verification artifacts include:

- Requirements Traceability Matrix
- Pest Test Suite
- Vitest Suite
- Scenario Library
- Hardware Test Procedures
- FTS Certification Reports
- Diagnostic Reports
- Initialization Reports
- Transmission Reports
- Custody Records
- User Acceptance Scripts
- Regression Library
- Audit Reports

---

# 20. Guiding Principle

The Alternative Election System shall not rely on confidence alone.

Every important requirement shall be traceable.

Every important feature shall be tested.

Every important workflow shall be replayable.

Every important ceremony shall be certifiable.

Every important election shall remain auditable.

The ultimate objective is not merely to prove that the software functions.

It is to demonstrate, through repeatable evidence, that the election process remains trustworthy from preparation through post-election audit.
