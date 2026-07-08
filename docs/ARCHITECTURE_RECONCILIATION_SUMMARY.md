# Architecture Reconciliation Summary

## Documents Modified

- `docs/COMPASS.md`
- `docs/FUNCTIONAL_SPECIFICATIONS.md`
- `docs/JOURNEY.md`
- `docs/PREPARATION.md`
- `docs/STRATEGY.md`
- `docs/SYSTEM_ANALYSIS.md`
- `docs/SYSTEM_DESIGN.md`
- `docs/TRACEABILITY_MATRIX.md`

## Architectural Changes Adopted

The architecture now treats COMELEC Final Testing and Sealing as the legal ceremony implemented by the Certification domain.

Friday Certification remains a useful plain-language concept, but the governing architecture now names the legal ceremony as **Final Testing and Sealing** and includes diagnostics, initialization report, certification ballots, manual verification, comparison, VVPAT or approved equivalent verification, zero-out, and sealing.

The architecture now introduces **Evidence** as a first-class domain.

Evidence includes journals, Minutes, reports, certificates, receipts, printed artifacts, QR artifacts, generated forms, seals, envelopes, storage devices, and custody records.

The core evidence principle is now:

```text
Ceremony

↓

Evidence

↓

Certification

↓

Trust
```

The architecture now separates **Activity Journal** from **Minutes**.

The Activity Journal is machine evidence: append-only, operational, and internally produced.

Minutes are legal evidence: human proceedings, legally required observations, and official election record.

The architecture now introduces **Custody** as a first-class domain.

Custody covers envelopes, paper seals, ballot boxes, storage devices, evidence containers, recipients, turnover, and chain of custody.

The architecture now introduces **Transmission** as a ceremony and domain concern.

Transmission is modeled after Election Return generation and before final backup, custody, and close precinct. Offline operation remains non-negotiable, but failed or deferred transmission must be evidenced.

## Recommendations Intentionally Deferred

The Official Forms Catalog was intentionally deferred.

Forms will emerge naturally from artifacts, reports, receipts, certificates, and Minutes. A separate forms catalog may become an extraction candidate after implementation stabilizes.

Detailed special polling workflows were not expanded into full implementation architecture in this pass.

The architecture now leaves room for PPP/S-PPP, PDL, and IP voting intake through Evidence and Custody concepts, but does not model every special polling form or staffing variant.

Full logistics-provider, VAD, and voting-center crowd-management systems remain outside the core appliance architecture.

## Rationale

The legal reconciliation strengthened the architecture without replacing its philosophy.

The project still rests on the same principles:

- paper is the legal source of truth
- Raspberry Pi is an appliance
- the application is ceremony-driven
- the application is offline-first
- behavior is deterministic
- hardware is abstracted
- Scenario Runner is central
- vertical slices remain the implementation strategy

The main change is that trust now flows through evidence.

Certification no longer owns every report and artifact. Certification consumes evidence produced by ceremonies.

## Impact On Implementation Plan

The existing implementation plan was not modified.

Future implementation planning should incorporate the reconciled architecture in this order:

1. Lifecycle and ceremony model
2. Evidence primitives
3. Activity Journal and Minutes separation
4. FTS ceremony
5. Custody primitives
6. Election Return evidence
7. Transmission and retransmission evidence
8. Final backup and custody turnover
9. Audit and reconciliation

This preserves vertical slices while making each slice legally meaningful.

## Impact On Spark Implementation

Spark should treat the updated architecture documents as the source of truth.

The Legal Reconciliation Report remains historical evidence, not a second architecture that must be mentally merged.

Implementation should avoid creating a broad forms subsystem early.

Spark should instead build small, reusable concepts:

- ceremony steps
- evidence records
- reports
- Minutes entries
- custody records
- attestations
- transmission records

Every new implementation slice should answer:

1. Which ceremony does this support?
2. What evidence does the ceremony produce?
3. What certification or audit consumes that evidence?
4. What custody obligation follows from the artifact?
