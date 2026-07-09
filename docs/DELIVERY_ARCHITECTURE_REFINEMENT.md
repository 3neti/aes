# Delivery Architecture Refinement

## Refinement Adopted

Transmission remains acceptable domain terminology because it aligns with election practice, but the architecture now makes the institutional act explicit:

```text
Official Handoff

↓

Delivery Driver

↓

Delivery Evidence
```

The Official Handoff is the ceremony.

Delivery drivers are interchangeable mechanisms that perform the ceremony.

Every delivery driver produces a Delivery Receipt.

## Rationale

The Alternative Election System is a paper-backed election appliance. The legal act is not networking, upload, synchronization, or API exchange. The legal act is the transfer of official election artifacts from one custodian to another.

Technology answers how artifacts are delivered. The election institution defines what official act is taking place.

## Impact on Architecture

Transmission is implemented through Official Handoff.

Official Handoff is executed through Delivery Drivers.

Delivery Drivers include:

- Manual Handoff
- SD Card
- USB Storage
- LTE
- REST API
- Government Network
- Satellite
- Future Technologies

The Delivery Package is independent of delivery method and may contain:

- printed Election Return
- export package
- report bundle
- evidence hashes
- custody metadata

Delivery Evidence includes the Delivery Receipt, custody records, recipient acknowledgement, and delivery-method-specific proof.

## Impact on Implementation

Implementation should begin with Manual Handoff as the first delivery driver.

The initial slice should model:

- Delivery Package preparation
- officer verification
- recipient verification
- Official Handoff
- custody transfer
- Delivery Receipt production

The recipient model should stay intentionally simple for now:

- recipient
- role
- date
- time
- delivery method
- acknowledgement

## Relationship With Evidence and Custody

Artifacts become evidence.

Evidence is handed over.

The handoff transfers custody.

The Delivery Receipt documents the transfer and becomes evidence for certification.

Conceptually:

```text
Custody

↓

Official Handoff

↓

New Custodian

↓

Delivery Receipt
```

## Impact on Scenario Runner

Scenario Runner should support ceremony-level simulations such as:

- Generate Delivery Package
- Manual Handoff
- Manual Handoff Failure
- SD Card Delivery
- Delivery Verification
- Recipient Refusal
- Delivery Retry
- Custody Verification

These scenarios remain deterministic and simulation-friendly.

## Concepts Intentionally Deferred

The architecture does not yet require complex recipient routing, hierarchy management, online delivery orchestration, or government network integration.

Those concerns can be added later as delivery-driver capabilities without changing the core Official Handoff model.
