# Alternative Election System
## Strategic Vision and Architectural Narrative

> **Working Draft**
>
> This document is intentionally **not** a Functional Specification.
>
> It is **not** an Implementation Plan.
>
> It is the architectural narrative that explains **why** the system exists, **how** it evolved, and the principles that govern every future technical decision.

---

# 1. Introduction

Every successful engineering project eventually reaches a point where the question changes.

Initially the question is:

> *Can we build it?*

Eventually it becomes:

> *Should we build it this way?*

This document exists to answer the second question.

It is the story of how the Alternative Election System evolved—not from theory—but from practical experience, operational pain, and a desire to produce an election system that is simpler, more trustworthy, easier to audit, and easier to recover.

---

# 2. The Previous Generation

The previous Hybrid Election System attempted to preserve the familiar paper ballot while accelerating the counting process through Optical Character Recognition (OCR).

The concept was attractive.

Voters continued using paper.

Machines interpreted the marks.

Counting became faster.

However, practical deployment revealed an uncomfortable reality.

The system depended on the machine correctly interpreting handwritten intent.

Even with carefully designed ballots, the following variables proved difficult to eliminate:

- ballot alignment
- paper skew
- scanner tolerances
- lighting
- print quality
- mark darkness
- voter writing habits
- calibration drift

The result was a system that worked extremely well—

but not perfectly.

The accuracy was very high.

It simply was not 100%.

For an election system, that distinction is everything.

---

# 3. The Architectural Question

Instead of asking:

> **How do we improve OCR?**

we asked a different question.

> **Can we eliminate OCR altogether?**

That single question changed the entire architecture.

Instead of trying to infer voter intent from paper...

...the system captures voter intent electronically,

then produces a human-readable paper ballot that the voter verifies before depositing into the ballot box.

The paper is no longer interpreted by OCR.

It is already known.

---

# 4. A Change in Philosophy

The previous architecture treated paper as an input.

The new architecture treats paper as the authoritative record.

That distinction is fundamental.

The architecture now follows a single principle.

> **All legal sources of truth should exist outside the computing device.**

The Raspberry Pi is no longer the authority.

It is merely an appliance.

---

# 5. Sources of Truth

The system intentionally minimizes trust in electronic storage.

The hierarchy is:

## Printed Ballots

The printed ballot represents the official expression of voter intent.

It is the legal source of truth.

It remains readable by humans.

It remains countable manually.

It survives hardware failure.

---

## Printed Election Package

The precinct configuration can be reproduced from a printed package.

If necessary, a new device can reconstruct its configuration by scanning the printed package.

The configuration itself is no longer trapped inside electronics.

---

## Printed Election Return

The Election Return remains an official paper artifact.

Digital representations exist only for convenience, verification, and artifact handoff.

The first official transmission of election results is the legally recognized transfer of the printed Election Return and related election artifacts from one custodian to another.

Networking is not the architectural center of transmission. LTE, REST APIs, government networks, satellite links, and other electronic paths are future transport drivers that may support the same handoff.

Manual Handoff is the reference transmission driver because it preserves the principle that paper remains the legal source of truth.

---

## Printed Certification Reports

Operational confidence is established through printed certification reports generated before and after election activities.

## Minutes and Evidence

The machine journal is not the only record.

The election also produces legal evidence:

- Minutes
- reports
- receipts
- certificates
- printed artifacts
- QR artifacts
- seals
- envelopes
- custody records

The architecture therefore treats evidence as a first-class concern.

The relationship is:

```
Ceremony

↓

Evidence

↓

Certification

↓

Trust
```

Certification does not create trust by assertion.

Certification consumes evidence already produced by the ceremony.

---

# 6. The Raspberry Pi

The Raspberry Pi is intentionally "dumb."

It is not the vault.

It is not the authority.

It performs four primary responsibilities:

- host the Progressive Web Application
- coordinate peripherals
- derive deterministic precinct configuration
- generate operational artifacts

Nothing more.

Replacing the Raspberry Pi should never invalidate the election.

---

# 7. Universal Device

Every Raspberry Pi should be identical.

There is no "Precinct 1234 Raspberry Pi."

There is simply:

> A Certified Election Appliance.

The device contains:

- operating system
- Laravel application
- Vue PWA
- printer support
- scanner support
- nationwide precinct registry
- nationwide candidate registry
- public verification keys

It contains no precinct-specific configuration.

---

# 8. Precinct Activation

A precinct becomes active only after loading its official Election Package.

The package may be transported through:

- SD card
- printed QR package
- future transport mechanisms

The transport method is irrelevant.

The Election Package is the authoritative artifact.

---

# 9. Deterministic Configuration

Given:

- the nationwide registries
- the official Election Package

every certified device must derive the exact same:

- ballot definition
- contest ordering
- local candidate ordinals
- mapping hashes

No randomness is permitted.

Every device should produce identical results.

---

# 10. The Printed Ballot

The ballot is intentionally simple.

It contains:

- human-readable selections
- identifying metadata
- QR code
- ballot identifiers

Humans read it.

Machines read it.

Neither interpretation changes the other.

---

# 11. Counting

Counting no longer depends on OCR.

Each accepted ballot contributes one append-only digital record.

```
ballot scanned

↓

decoded

↓

validated

↓

append file

↓

next ballot
```

At the end of counting, the tally is reconstructed from these append-only records.

SQLite exists only as a temporary read model for convenience.

It is never the source of truth.

---

# 12. Recovery

The architecture assumes hardware failure.

Recovery should require only:

- another Raspberry Pi
- the Election Package
- the ballot box

Everything else can be regenerated.

No proprietary database recovery.

No hidden state.

No specialized technician.

---

# 13. Certification and Final Testing and Sealing

Confidence should never rely upon trust alone.

Every important operational phase should be certified.

Examples include:

- precinct activation
- Final Testing and Sealing
- opening polls
- closing polls
- counting
- Election Return generation
- transmission
- final backup
- custody turnover
- precinct closure

Certification is evidence-based.

It is generated from evidence.

That evidence includes machine journals, legal Minutes, printed reports, officer attestations, receipt records, and custody records.

It produces reports that may later be electronically signed.

Friday Certification remains a useful plain-language idea.

Architecturally, it is now understood as the implementation of COMELEC Final Testing and Sealing.

Final Testing and Sealing proves readiness through diagnostics, initialization, certification ballots, manual verification, comparison, VVPAT or approved equivalent verification, zero-out, and sealing.

---

# 14. Lifecycle Instead of Administration

The application should not resemble an administration system.

It should resemble an election ceremony.

The software always knows its current stage.

It guides the operator.

It minimizes decisions.

It minimizes training.

The lifecycle becomes:

```
Provision

↓

Certification

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

Custody Turnover

↓

Close Precinct

↓

Audit
```

The operator should almost never need menus.

The legal detail should not make the interface feel like an administration system.

Each additional legal ceremony exists only to answer the same question:

> What must happen next?

---

# 15. Audit

Audit remains independent.

Confidence comes from comparing multiple independent views.

Examples include:

- manual count
- original machine tally
- independent recount device

Whenever doubt exists...

the paper ballots prevail.

---

# 16. Testing

The system is designed to be tested continuously.

Every lifecycle can be executed through deterministic scenarios.

Examples include:

- precinct activation
- printer verification
- scanner verification
- QR decoding
- certification deck
- election return generation
- backup recovery

Testing becomes operational rehearsal.

The same scenarios certify the precinct before Election Day.

---

# 17. Vocabulary

Institutions speak different languages.

Software should adapt.

The application should maintain a configurable Election Dictionary so terminology may change without affecting business logic.

Examples include:

- Precinct
- Polling Station
- Voting Center

or

- Election Return
- Statement of Votes
- Official Canvass

The engine remains unchanged.

Only the vocabulary changes.

---

# 18. Lessons Learned

This architecture owes much to lessons learned from previous systems.

The project adopts principles refined through years of developing transactional and evidentiary systems:

- deterministic execution
- append-only journals
- legal Minutes
- evidence-first certification
- custody-aware artifacts
- reproducible workflows
- adapter-based hardware integration
- comprehensive lifecycle testing
- package-oriented architecture where appropriate
- operational simplicity over technical cleverness

The objective is not to build the most sophisticated election machine.

The objective is to build the most understandable one.

---

# 19. Guiding Principles

The project will continue to be evaluated against the following principles.

1. **Paper is the legal source of truth.**
2. **No critical information should exist only inside a device.**
3. **Every important artifact should be printable.**
4. **Every important process should be reproducible.**
5. **Every important machine state transition should be journaled.**
6. **Every important legal proceeding should have Minutes where required.**
7. **Every important ceremony should be certifiable from evidence.**
8. **Any certified device should replace another without changing the election outcome.**
9. **Technology should simplify elections, not complicate them.**
10. **When doubt exists, paper prevails.**
11. **The architecture should be understandable by engineers, election officers, watchers, and the public alike.**

---

# Epilogue

The Alternative Election System is not an attempt to remove paper.

It is an attempt to restore confidence.

Instead of asking citizens to trust software,

the architecture asks software to continuously prove itself.

The computer becomes an appliance.

The paper becomes the evidence.

The election becomes reproducible.

And trust is earned—not assumed.
