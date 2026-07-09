# Transmission Architecture Refinement

## Architectural Refinement

Transmission is the movement of official election artifacts from one legally recognized custodian to another.

Networking is one possible transport mechanism, not the architectural center of the domain.

The architecture now models Transmission as:

```text
Transmission

↓

Official Handoff

↓

Delivery Driver
```

Manual Handoff is the reference implementation and first delivery driver.

The Official Handoff is the ceremony. Delivery drivers perform that ceremony and produce Delivery Receipts as evidence.

## Documents Updated

- `docs/STRATEGY.md`
- `docs/FUNCTIONAL_SPECIFICATIONS.md`
- `docs/SYSTEM_DESIGN.md`
- `docs/COMPASS.md`
- `docs/TRACEABILITY_MATRIX.md`

## Rationale

The Alternative Election System is a paper-backed election appliance. Paper remains the legal source of truth, so the first official transmission of election results is the physical handoff of the printed Election Return and related artifacts.

Electronic communication may assist operations, but it does not define the Transmission domain.

## Impact on Implementation

Wave 7 should initially implement:

- Manual Handoff
- Export Package
- Custody Record
- Recipient Acknowledgement
- Delivery Receipt

Electronic transport should be implemented later as additional delivery drivers within the same Transmission domain.

## Impact on Spark

Spark should proceed with Transmission as a ceremony-driven handoff workflow. It should not begin with LTE, REST APIs, or online delivery as the default transmission path.

The implementation should keep Transmission, Evidence, and Custody connected:

- Transmission produces evidence.
- Custody records the legal transfer.
- Certification consumes the resulting evidence.

## Deferred Future Transport Mechanisms

The following mechanisms remain valid future delivery drivers:

- Removable Media
- LTE
- REST API
- Government Network
- Satellite Link

These drivers should implement the same Official Handoff concept and produce Delivery Receipts rather than redefining the Transmission domain.
