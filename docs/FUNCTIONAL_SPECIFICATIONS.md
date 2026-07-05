# Alternative Election System
# Comprehensive Functional Specification (Version 1.0)

> **Working Draft**
>
> This document defines the functional behavior of the Alternative Election System.
>
> It intentionally avoids implementation details such as database schemas, Laravel classes, APIs, Vue components, or deployment strategies.
>
> Those belong to the implementation plan.
>
> This document answers one question:
>
> **"What must the system do?"**

---

# 1. Objectives

The system shall provide an end-to-end election solution that:

- assists voters in casting votes electronically;
- produces a voter-verified paper ballot;
- uses the paper ballot as the official source of truth;
- provides deterministic electronic counting;
- supports complete manual recounts;
- minimizes operational complexity;
- operates completely offline;
- tolerates hardware replacement;
- produces auditable election artifacts.

---

# 2. Scope

The system covers:

- precinct preparation
- precinct activation
- Friday certification
- voting
- ballot printing
- ballot spoilage
- poll opening
- poll closing
- counting
- election return generation
- precinct closure
- post-election audit

The system does **not** determine voter eligibility.

Existing election procedures remain responsible for voter verification.

---

# 3. Actors

Primary actors:

- Voter
- Election Officer
- Poll Clerk
- Watcher
- Technician
- Auditor

System actors:

- Precinct Appliance
- Tablet
- Printer
- QR Scanner
- Backup Appliance

---

# 4. Election Lifecycle

The appliance shall implement the following lifecycle.

```text
Provision

↓

Certification

↓

Open Precinct

↓

Open Polls

↓

Voting

↓

Close Polls

↓

Counting

↓

Election Return

↓

Close Precinct

↓

Audit
```

Only valid lifecycle transitions shall be permitted.

---

# 5. National Registries

The appliance shall contain embedded nationwide registries including:

- precinct registry
- candidate registry
- contest registry
- ballot style registry
- public verification keys

These registries shall be versioned.

These registries shall be immutable during the election.

---

# 6. Election Package

The system shall support an Election Package.

The Election Package shall contain:

- election identifier
- precinct identifier
- ballot style identifier
- registry references
- package metadata
- integrity information
- digital signature

The package shall be transportable through:

- SD card
- QR package
- future transport mechanisms

---

# 7. Precinct Activation

The appliance shall:

- verify Election Package integrity;
- verify signatures;
- verify registry compatibility;
- derive precinct configuration;
- persist derived configuration;
- freeze configuration after activation.

The appliance shall reject invalid packages.

---

# 8. Deterministic Mapping

The appliance shall derive:

- ballot definition
- contest ordering
- local candidate ordinals

The derivation shall be deterministic.

Identical inputs shall always produce identical outputs.

---

# 9. Friday Certification

The appliance shall provide a complete certification workflow.

Certification shall include:

- package verification
- mapping verification
- printer verification
- scanner verification
- QR verification
- counting verification
- Election Return verification

Certification shall generate a Certification Report.

---

# 10. Certification Ballots

The system shall support official Certification Ballots.

Certification Ballots shall:

- contain predefined selections;
- produce predetermined results;
- generate predetermined Election Returns.

Certification shall fail if expected and actual results differ.

---

# 11. Poll Opening

The appliance shall prevent voting until polls are officially opened.

Opening polls shall require authorized officer approval.

Opening shall be journaled.

---

# 12. Voting Session

Each voter shall receive an isolated voting session.

The session shall:

- display the correct ballot;
- allow candidate selection;
- prevent invalid selections;
- support review;
- permit changes before finalization.

No changes shall be permitted after finalization.

---

# 13. Ballot Finalization

Upon finalization the appliance shall:

- generate ballot payload;
- generate ballot QR;
- prepare official ballot for printing.

Finalization alone shall not constitute an official vote.

---

# 14. Ballot Printing

The appliance shall support multiple printer technologies.

Examples include:

- PDF/CUPS
- ESC/POS
- future adapters

Every print operation shall produce a journaled print job.

The system shall detect printing failures.

---

# 15. Ballot Verification

The voter shall inspect the printed ballot.

The voter shall verify:

- candidate selections;
- ballot information.

Only verified ballots shall be deposited.

---

# 16. Spoiled Ballots

The appliance shall support spoiled ballots.

Spoilage shall:

- be journaled;
- preserve audit history;
- prevent spoiled ballots from being counted.

---

# 17. Poll Closing

The appliance shall support official poll closing.

After closing:

- no new voting sessions shall begin;
- incomplete sessions shall be handled according to election policy.

---

# 18. Counting

The appliance shall support QR-based ballot counting.

For each accepted ballot the appliance shall:

- decode QR;
- validate ballot;
- reconstruct selections;
- append counting record.

Rejected ballots shall be recorded separately.

---

# 19. Counting Journal

The counting journal shall:

- be append-only;
- preserve ballot order;
- record timestamps;
- record decoding metadata;
- record validation results.

Previously accepted records shall never be modified.

---

# 20. Tally

The system shall generate tally information from the counting journal.

SQLite or equivalent temporary storage may be used solely as a read model.

The tally database shall not constitute the source of truth.

---

# 21. Election Return

The appliance shall generate an Election Return.

The Election Return shall contain:

- precinct information;
- vote totals;
- election metadata;
- integrity information.

The Election Return shall be printable.

The Election Return may also be digitally represented.

---

# 22. Certification During Operations

The appliance shall support certification checkpoints including:

- precinct ready
- polls opened
- polls closed
- counting completed
- Election Return generated
- precinct closed

Each certification shall produce journal evidence.

---

# 23. Officer Attestation

The appliance shall support officer attestation.

The architecture shall support multiple methods including:

- officer code + PIN
- electronic signatures
- future signing mechanisms

---

# 24. Activity Journal

Every important state transition shall be journaled.

Examples include:

- activation
- certification
- voting
- printing
- spoilage
- counting
- Election Return generation
- closure

The journal shall be append-only.

---

# 25. Diagnostics

The appliance shall provide diagnostics including:

- printer status
- scanner status
- package information
- registry versions
- configuration hashes
- journal inspection

Diagnostics shall be protected from normal operators.

---

# 26. Backup Appliance

A replacement appliance shall:

- activate using the same Election Package;
- derive identical configuration;
- read official ballots;
- produce identical tally;
- generate identical Election Return.

---

# 27. Hardware Independence

Business logic shall not depend upon:

- specific printer models;
- specific scanner models;
- specific cameras;
- specific Raspberry Pi revisions.

Hardware shall be abstracted through adapters.

---

# 28. Offline Operation

The appliance shall operate without Internet connectivity.

Network connectivity shall be optional.

Failure of external communication shall not interrupt voting or counting.

---

# 29. User Interface

The application shall guide operators through ceremonies rather than administration screens.

The system shall always display:

- current lifecycle stage;
- current ceremony;
- next required action.

---

# 30. Domain Dictionary

The system shall provide a configurable domain dictionary.

Labels shall be configurable including:

- ceremonies
- buttons
- reports
- workflow names
- messages
- election terminology

Business logic shall not depend on displayed terminology.

---

# 31. Scenario Runner

The system shall provide a Lifecycle Scenario Runner.

Scenarios shall support:

- simulation
- hardware execution
- deterministic replay

Scenarios shall be executable through automated testing.

---

# 32. Acceptance Testing

The system shall support end-to-end acceptance testing including:

- PDF ballot generation
- QR extraction from generated PDF
- physical print verification
- physical QR scanning
- Certification Ballot execution
- Election Return comparison
- backup appliance verification

---

# 33. Audit

The system shall support post-election audit.

Audit workflows shall include:

- manual recount
- independent scanner recount
- journal comparison
- Election Return comparison

Paper ballots shall remain the authoritative reference.

---

# 34. Security

The system shall provide:

- signed Election Packages;
- immutable registries during election;
- deterministic configuration;
- integrity verification;
- append-only journals;
- officer authentication;
- optional electronic signatures.

---

# 35. Non-Functional Requirements

The system shall be:

- deterministic;
- reproducible;
- offline-first;
- hardware-replaceable;
- auditable;
- testable;
- modular;
- printer-independent;
- scanner-independent;
- recoverable;
- understandable by non-technical election officers.

---

# 36. Guiding Functional Principles

The implementation shall preserve the following principles:

1. Printed ballots are the legal source of truth.
2. The appliance is an operational tool, not the authority.
3. Every important artifact shall be printable.
4. Every important operation shall be journaled.
5. Every important ceremony shall be certifiable.
6. Every lifecycle shall be executable through the Scenario Runner.
7. Every certified appliance shall produce identical results from identical inputs.
8. Hardware replacement shall not alter election outcomes.
9. Manual recount shall always remain possible.
10. Technology shall simplify election administration without diminishing transparency, auditability, or public confidence.
