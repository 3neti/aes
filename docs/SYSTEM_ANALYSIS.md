# Alternative Election System
# Systems Analysis and Design
## Draft Analytical Diagrams (DFDs, ERD, and Use Cases)

> **Working Draft**
>
> These diagrams are conceptual and intended to establish the system architecture before implementation.
>
> They should evolve together with the Functional Specification and Architecture Compass.

---

# 1. System Context Diagram (DFD Level 0)

```mermaid
flowchart LR

    V[Voter]
    EO[Election Officer]
    W[Watchers]
    A[Auditor]
    N[National Election Authority]

    SYS["Alternative Election System"]

    P[Printer]
    S[QR Scanner]
    T[Tablet]
    B[Ballot Box]
    LTE[Optional LTE]

    V --> T
    EO --> SYS
    W --> SYS
    A --> SYS
    N --> SYS

    SYS --> T
    SYS --> P
    P --> B

    B --> S
    S --> SYS

    SYS --> LTE
```

---

# 2. Data Flow Diagram – Level 1

```mermaid
flowchart TD

    P1[Prepare Precinct]

    P2[Friday Certification]

    P3[Voting]

    P4[Ballot Printing]

    P5[Counting]

    P6[Election Return]

    P7[Audit]

    D1[(National Registries)]

    D2[(Election Package)]

    D3[(Ballot Journal)]

    D4[(Counting Journal)]

    D5[(Election Return)]

    D1 --> P1
    D2 --> P1

    P1 --> P2

    P2 --> P3

    P3 --> P4

    P4 --> D3

    D3 --> P5

    P5 --> D4

    D4 --> P6

    P6 --> D5

    D5 --> P7
```

---

# 3. Voting Process (DFD Level 2)

```mermaid
flowchart LR

Start([Start])

Activate[Activate Tablet]

Ballot[Display Ballot]

Select[Select Candidates]

Review[Review]

Finalize[Finalize Vote]

QR[Generate QR]

Print[Print Official Ballot]

Verify[Voter Verifies]

Deposit[Deposit Ballot]

Spoil[Spoil Ballot]

End([Done])

Start --> Activate

Activate --> Ballot

Ballot --> Select

Select --> Review

Review --> Finalize

Finalize --> QR

QR --> Print

Print --> Verify

Verify --> Deposit

Verify --> Spoil

Deposit --> End

Spoil --> Ballot
```

---

# 4. Counting Process (DFD Level 2)

```mermaid
flowchart TD

Open[Open Ballot Box]

Scan[Scan QR]

Decode[Decode QR]

Validate[Validate]

Append[Append Counting Record]

Update[Update Tally]

Display[Display Ballot]

Next{More Ballots?}

ER[Generate Election Return]

Open --> Scan

Scan --> Decode

Decode --> Validate

Validate --> Append

Append --> Update

Update --> Display

Display --> Next

Next -->|Yes| Scan

Next -->|No| ER
```

---

# 5. Friday Certification Workflow

```mermaid
flowchart TD

Boot

Package

Mapping

Printer

Scanner

Golden[Certification Ballots]

Count

Compare

Report

Seal

Boot --> Package

Package --> Mapping

Mapping --> Printer

Printer --> Scanner

Scanner --> Golden

Golden --> Count

Count --> Compare

Compare --> Report

Report --> Seal
```

---

# 6. Election Lifecycle State Diagram

```mermaid
stateDiagram-v2

[*] --> Provision

Provision --> Certification

Certification --> OpenPrecinct

OpenPrecinct --> OpenPolls

OpenPolls --> Voting

Voting --> ClosePolls

ClosePolls --> Counting

Counting --> ElectionReturn

ElectionReturn --> ClosePrecinct

ClosePrecinct --> Audit

Audit --> [*]
```

---

# 7. Voting Session State Diagram

```mermaid
stateDiagram-v2

[*] --> Created

Created --> Active

Active --> Review

Review --> Finalized

Finalized --> Printed

Printed --> Deposited

Printed --> Spoiled

Deposited --> [*]

Spoiled --> Active
```

---

# 8. Entity Relationship Diagram (Conceptual)

```mermaid
erDiagram

ELECTION ||--o{ PRECINCT : contains

PRECINCT ||--|| ELECTION_PACKAGE : activates

PRECINCT ||--o{ VOTING_SESSION : creates

PRECINCT ||--o{ BALLOT : issues

PRECINCT ||--o{ CERTIFICATION_REPORT : produces

PRECINCT ||--o{ ELECTION_RETURN : generates

PRECINCT ||--o{ JOURNAL_ENTRY : records

BALLOT_STYLE ||--o{ CONTEST : contains

CONTEST ||--o{ CANDIDATE : lists

VOTING_SESSION ||--|| BALLOT : finalizes

BALLOT ||--o{ PRINT_JOB : prints

BALLOT ||--o{ COUNTING_RECORD : counted_as

COUNTING_RECORD }o--|| ELECTION_RETURN : contributes_to

CERTIFICATION_REPORT ||--o{ OFFICER_ATTESTATION : signed_by

DEVICE ||--o{ JOURNAL_ENTRY : generates

DEVICE ||--|| PRECINCT : operates
```

---

# 9. Component Diagram

```mermaid
flowchart TB

Core[Election Core]

Preparation[Precinct Preparation]

Certification

Voting

Printing

Counting

Returns[Election Return]

Audit

ScenarioRunner

Diagnostics

Devices

Infrastructure

Core --> Preparation

Core --> Certification

Core --> Voting

Voting --> Printing

Printing --> Counting

Counting --> Returns

Returns --> Audit

ScenarioRunner --> Core

Diagnostics --> Core

Devices --> Printing

Devices --> Counting

Infrastructure --> Core
```

---

# 10. Use Case Diagram (Conceptual)

```mermaid
flowchart LR

V[Voter]

EO[Election Officer]

A[Auditor]

T[Technician]

UC1((Activate Device))

UC2((Run Certification))

UC3((Open Polls))

UC4((Cast Vote))

UC5((Print Ballot))

UC6((Close Polls))

UC7((Count Ballots))

UC8((Generate Election Return))

UC9((Close Precinct))

UC10((Run Audit))

UC11((Diagnostics))

EO --> UC1

EO --> UC2

EO --> UC3

V --> UC4

EO --> UC5

EO --> UC6

EO --> UC7

EO --> UC8

EO --> UC9

A --> UC10

T --> UC11
```

---

# 11. Deployment Diagram (Conceptual)

```mermaid
flowchart LR

Tablet

Scanner

Printer

Pi["Raspberry Pi
Laravel + Vue PWA"]

Storage[(Local Storage)]

LTE[Optional LTE]

Tablet <--Wi-Fi--> Pi

Scanner --> Pi

Pi --> Printer

Pi --> Storage

Pi --> LTE
```

---

# 12. Traceability Overview

```mermaid
flowchart TD

Strategy

SRS

Domain

Wave

Slice

Implementation

Tests

ScenarioRunner

Certification

Audit

Strategy --> SRS

SRS --> Domain

Domain --> Wave

Wave --> Slice

Slice --> Implementation

Implementation --> Tests

Tests --> ScenarioRunner

ScenarioRunner --> Certification

Certification --> Audit
```

---

# Future Diagrams

The following diagrams are expected to become significantly more detailed during implementation:

- Physical Entity Relationship Diagram
- Sequence Diagrams for every ceremony
- Printer Adapter Sequence Diagram
- QR Generation/Decoding Sequence Diagram
- Backup Appliance Recovery Diagram
- Journal Event Flow Diagram
- Certification Evidence Flow Diagram
- Election Return Generation Flow
- Activity Timeline Diagram
- UI Navigation Map
- Raspberry Pi Hardware Interaction Diagram
- Scenario Runner Execution Graph

These conceptual diagrams provide the analytical foundation upon which the detailed implementation design will be constructed.
