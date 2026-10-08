# EML Interoperability Compass

## North Star

WAES exchanges election configuration, count, result, statistics, and audit data
through a transparent, versioned EML profile without allowing an external format
to redefine canonical election truth or weaken existing verification controls.

## Status Rule

The initial implementation is the `WAES EML 7 Base Profile`. It demonstrates
OASIS EML interoperability. It is not described as COMELEC-mandated,
COMELEC-certified, or procurement-compliant unless COMELEC supplies an
authoritative profile and the implementation passes its acceptance criteria.

The software baseline currently supports EML 110, 230, 410, 480, 510, 520, and
530. Configuration intake is staging-only. Precinct package intake cannot update
the canonical canvass until its custody, authorization, duplicate, and
supersession ceremony is implemented and accepted on the physical appliance.

## Canonical Rule

The WAES domain model and frozen election snapshots remain canonical. EML, PDF,
JSON, CSV, and Truth QR are deterministic representations or evidence derived
from canonical state. No representation may silently become a parallel tally.

When representations disagree, processing stops and records a discrepancy. No
format is preferred merely because it is XML, signed, printed, or transmitted.
The discrepancy must be resolved through the defined election procedure.

## Boundary Rule

Use EML when information crosses a controlled system boundary:

- pre-election configuration import or export;
- precinct count packaging and transmission;
- canvassing result exchange;
- standardized statistics publication; and
- standardized audit export.

Do not use EML as the application database, internal event format, browser state,
or routine ballot QR representation.

## Profile Rule

Every artifact declares an exact profile and schema version. Schema artifacts are
stored locally, hash-pinned, and unavailable for silent network replacement.
Generic OASIS EML, the WAES EML 7 Base Profile, and any future COMELEC profile
are distinct compatibility claims with distinct fixtures and tests.

Unknown fields and extensions are never silently ignored when they may affect an
election outcome, jurisdiction, ballot entitlement, authorization, or audit fact.

## Truth QR Rule

Truth QR remains compact and optimized for reliable paper scanning. Full XML is
not embedded in the QR merely to advertise EML support.

Election-return Truth payloads bind to the canonical result, signed manifest, EML
profile, EML artifact hash, issuer, and signature. Multipart QR framing protects
transport reconstruction; signatures and manifest validation establish document
authenticity and election context.

## Security Rule

Schema validity is not trust. An EML artifact affects election state only after
schema, signature, manifest, lifecycle, jurisdiction, mapping, document identity,
and idempotency checks all pass.

XML processing is offline and hostile-input-safe. External entities, DTDs, remote
schema retrieval, unbounded expansion, and uncontrolled transforms are disabled.
Private signing keys are not stored in source control or exported with evidence.

## One-Snapshot Rule

The printed election return, Truth payload, EML count artifact, and evidence
manifest originate from one immutable closeout snapshot. They are not generated
from one another through independent tally logic.

This preserves a single counting engine while allowing several independently
verifiable representations.

## Canvassing Rule

Canvassing accepts official document identities, not merely files or QR scans.
An EML package received electronically and a Truth payload scanned from paper
must resolve to the same authenticated election return before either can update
the canvass.

Duplicate prevention is atomic. Replacement or supersession is an explicit,
journaled election action and never an automatic last-write-wins update.

## Audit Rule

EML audit and statistics exports supplement native evidence. The native activity
journal, scanner ledger, commissioning report, source artifacts, PDFs, custody
records, signatures, and evidence manifests remain available for forensic review.

Public audit exports minimize ballot-level disclosure and never associate voter
identity with selections.

## Air-Gapped Rule

All EML creation, validation, signing, verification, and transformation work with
no internet connection. Schemas, profiles, keys, fixtures, and verification tools
required for an election are commissioned onto the appliance in advance.

## Localization Rule

COMELEC familiarity is a reason to provide an adapter, not a reason to guess its
requirements. When authoritative COMELEC materials arrive, WAES adds a separate
profile with a traceable field mapping. It does not redefine the base profile or
silently alter historical artifacts.

## Change Test

An EML-related change is acceptable only if it preserves all of the following:

- Canonical counting and canvassing do not depend on XML availability.
- The same frozen state produces verifiably equivalent representations.
- EML cannot bypass signatures, manifests, lifecycle gates, or idempotency.
- Full EML documents are not required for reliable ballot QR scanning.
- Validation remains completely functional while air-gapped.
- Profile and schema versions are explicit and hash-pinned.
- Unsupported or ambiguous election-significant data fails visibly.
- EML exports do not expose voter identity or unnecessary ballot-level data.
- Compatibility claims are limited to profiles and message types actually tested.
