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
- Final Testing and Sealing certification
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

Transmission

↓

Final Backup

↓

Custody

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

# 9. Final Testing and Sealing

The appliance shall provide a complete certification workflow that implements COMELEC Final Testing and Sealing (FTS).

FTS shall include:

- package verification
- mapping verification
- diagnostics
- initialization report
- printer verification
- scanner verification
- QR verification
- counting verification
- manual verification
- comparison of expected and actual results
- VVPAT or approved architectural equivalent verification
- zero-out
- sealing evidence
- Election Return verification

FTS shall generate the required evidence artifacts, including diagnostic evidence, initialization evidence, comparison evidence, zero-out evidence, and a certification summary report.

Certification consumes evidence.

It does not replace the evidence.

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

Opening shall produce both journal evidence and legal Minutes entries.

Opening shall include initialization evidence showing that the precinct begins from a zero state.

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
- special polling ballots, if any, shall be received or accounted for before final result generation.

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

The Election Return workflow shall support signing, posting, copy distribution, and evidence of public completion.

---

# 22. Transmission

The appliance shall support transmission as the official handoff of election artifacts from one legally recognized custodian to another.

Manual Handoff shall be the first supported delivery driver.

Transmission shall:

- occur after Election Return generation;
- prepare a Delivery Package containing the official artifacts being handed over;
- record Delivery Package checksum or hash evidence when digital artifacts are included;
- record recipient, role, date, time, delivery method, and acknowledgement;
- record custody transfer facts;
- support officer acknowledgement;
- generate a Delivery Receipt or Transmission Report;
- preserve evidence when a later electronic delivery driver is unavailable, deferred, or unsuccessful.

Offline operation shall not prevent voting or counting.

Offline operation shall not prevent official transmission through Manual Handoff.

Future electronic transport mechanisms, including removable media, LTE, REST APIs, government networks, and satellite links, shall be modeled as delivery drivers within the Transmission domain rather than as separate election domains.

The Official Handoff ceremony shall include Delivery Package preparation, officer verification, recipient verification, custody transfer, and Delivery Receipt production.

---

# 23. Certification During Operations

The appliance shall support certification checkpoints including:

- precinct ready
- polls opened
- polls closed
- counting completed
- Election Return generated
- transmission completed or evidenced
- final backup completed
- custody turnover completed
- precinct closed

Each certification shall consume evidence from journals, Minutes, reports, receipts, signatures, and custody records.

---

# 24. Officer Attestation

The appliance shall support officer attestation.

The architecture shall support multiple methods including:

- officer code + PIN
- electronic signatures
- future signing mechanisms

---

# 25. Evidence

The system shall treat evidence as a first-class domain.

Evidence shall include:

- activity journals;
- legal Minutes;
- reports;
- certificates;
- receipts;
- printed artifacts;
- QR artifacts;
- generated forms;
- seals;
- envelopes;
- storage devices;
- custody records.

The evidence principle is:

```text
Ceremony

↓

Evidence

↓

Certification

↓

Trust
```

---

# 26. Activity Journal

Every important machine state transition shall be journaled.

Examples include:

- activation
- certification
- voting
- printing
- spoilage
- counting
- Election Return generation
- transmission
- final backup
- closure

The journal shall be append-only.

---

# 27. Minutes

The system shall distinguish legal Minutes from the Activity Journal.

The Activity Journal is machine evidence.

The Minutes are the legal record of human proceedings.

Minutes shall record legally meaningful observations such as:

- ceremony opening and completion;
- officer attestations;
- watcher presence or protest;
- report printing;
- manual verification;
- rejected or spoiled ballots;
- custody and turnover facts.

Minutes and journal entries shall reference each other where appropriate.

---

# 28. Custody

The system shall support custody records.

Custody shall cover:

- envelopes;
- paper seals;
- ballot boxes;
- storage devices;
- evidence containers;
- recipients;
- turnover;
- chain of custody.

The system shall model the concepts behind official forms without requiring a separate forms catalog at this stage.

---

# 29. Diagnostics

The appliance shall provide diagnostics including:

- printer status
- scanner status
- package information
- registry versions
- configuration hashes
- journal inspection
- diagnostic report generation

Diagnostics shall be protected from normal operators.

---

# 30. Backup Appliance

A replacement appliance shall:

- activate using the same Election Package;
- derive identical configuration;
- read official ballots;
- produce identical tally;
- generate identical Election Return.

---

# 31. Hardware Independence

Business logic shall not depend upon:

- specific printer models;
- specific scanner models;
- specific cameras;
- specific Raspberry Pi revisions.

Hardware shall be abstracted through adapters.

---

# 32. Offline Operation

The appliance shall operate without Internet connectivity.

Network connectivity shall be optional.

Failure of external communication shall not interrupt voting or counting.

---

# 33. User Interface

The application shall guide operators through ceremonies rather than administration screens.

The system shall always display:

- current lifecycle stage;
- current ceremony;
- next required action.

---

# 34. Domain Dictionary

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

# 35. Scenario Runner

The system shall provide a Lifecycle Scenario Runner.

Scenarios shall support:

- simulation
- hardware execution
- deterministic replay
- legal ceremony replay
- evidence verification

Scenarios shall be executable through automated testing.

---

# 36. Acceptance Testing

The system shall support end-to-end acceptance testing including:

- PDF ballot generation
- QR extraction from generated PDF
- physical print verification
- physical QR scanning
- Certification Ballot execution
- Election Return comparison
- FTS execution
- initialization report verification
- transmission report verification
- custody turnover verification
- backup appliance verification

---

# 37. Audit

The system shall support post-election audit.

Audit workflows shall include:

- manual recount
- independent scanner recount
- journal comparison
- Minutes comparison
- Election Return comparison
- custody review

Paper ballots shall remain the authoritative reference.

---

# 38. Security

The system shall provide:

- signed Election Packages;
- immutable registries during election;
- deterministic configuration;
- integrity verification;
- append-only journals;
- legal Minutes;
- custody records;
- officer authentication;
- optional electronic signatures.

---

# 39. Non-Functional Requirements

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

# 40. Guiding Functional Principles

The implementation shall preserve the following principles:

1. Printed ballots are the legal source of truth.
2. The appliance is an operational tool, not the authority.
3. Every important artifact shall be printable.
4. Every important machine operation shall be journaled.
5. Every important ceremony shall be certifiable.
6. Every lifecycle shall be executable through the Scenario Runner.
7. Every certified appliance shall produce identical results from identical inputs.
8. Hardware replacement shall not alter election outcomes.
9. Manual recount shall always remain possible.
10. Technology shall simplify election administration without diminishing transparency, auditability, or public confidence.
11. Legal Minutes and machine journals shall remain distinct but cross-referenced.
12. Custody shall be evidenced from artifact creation through turnover.
