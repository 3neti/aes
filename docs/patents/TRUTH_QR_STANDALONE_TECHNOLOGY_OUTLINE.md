# Truth QR Standalone Technology Outline

## 1. Working Title
Truth QR System for Self Describing Verifiable Machine Readable Records

## 2. Purpose
Truth QR is a technology for placing a compact, verifiable, machine readable payload on a physical or digital artifact so that the artifact can be decoded, reconstructed, audited, and independently checked without relying on a remote database as the sole source of meaning.

The core idea is not limited to elections. Truth QR can be applied to any regulated workflow where a document, form, record, voucher, survey response, field report, certificate, or transaction summary must remain human readable while also carrying enough machine readable evidence to support offline verification.

## 3. Problem Addressed
Many QR based systems encode either a plain text representation, a web link, or a database identifier. These approaches create several problems:

- The QR may point to a database that is unavailable, mutable, or controlled by one party.
- The QR may not carry enough context to prove what the encoded data means.
- The QR may be too dense if full human readable labels are encoded directly.
- A large payload may require several QR symbols, but fragments can be mixed, duplicated, or scanned out of order.
- The displayed document and the QR payload may drift unless both are bound to the same canonical evidence.
- Independent reviewers may be unable to reconstruct the record without privileged system access.

Truth QR addresses these problems by combining compact coded payloads, local mapping registries, document profiles, cryptographic hashes, optional signatures, append only ingestion, and deterministic reconstruction.

## 4. Core Concept
A Truth QR is a machine readable payload that carries compact data codes plus enough context to resolve, verify, and reconstruct the record using locally commissioned reference materials.

The QR does not merely say, “look up record 123 on a server.” Instead, it carries the essential coded facts, the identity of the mapping needed to interpret them, the scope of the record, and verification values that allow another device to determine whether the record belongs to the expected context.

## 5. Principal Components
### 5.1 Mapping Registry
A mapping registry assigns compact codes to domain objects. Examples include candidate codes, survey answer codes, inspection finding codes, product item codes, field form item codes, or certificate attribute codes.

Each mapping is canonicalized and hashed. A scanner or verifier accepts a payload only when it has the same commissioned mapping hash.

### 5.2 Payload Encoder
The encoder forms a canonical payload containing the selected or recorded compact codes, record context, document profile, serial or record identity, and verification metadata.

### 5.3 Truth QR Carrier
The payload is encoded in one QR symbol or, when necessary, several QR fragments. The carrier may be printed on paper, displayed on screen, stored as an image, or embedded in a generated document.

### 5.4 Multipart Fragment System
When the payload exceeds a practical QR capacity, the system splits it into fragments. Each fragment includes a common group hash, part number, total part count, and chunk data. The receiver may scan fragments in any order and release the payload only after completeness and group verification.

### 5.5 Local Verifier
The verifier decodes the QR, checks the context, mapping hash, payload hash, document profile, optional signature, and duplicate status. It does not need live network access if the required mapping, public keys, document profiles, and assets were commissioned in advance.

### 5.6 Document Reconstruction Kit
A document profile points to a local rendering kit containing layouts, labels, images, fonts, paper dimensions, and other assets. The verifier can reconstruct a human readable representation from the decoded payload and local assets.

### 5.7 Append Only Ledger
Each scan or verification attempt can be recorded in an append only ledger. Accepted, rejected, duplicate, partial, or mismatched records can be replayed later to reproduce the resulting totals, findings, or status.

## 6. Data Elements
A Truth QR payload may include:

- Protocol version.
- Domain or application identifier.
- Organization or issuer identifier.
- Record type.
- Record scope, such as location, precinct, site, branch, office, batch, or project.
- Compact payload codes.
- Mapping hash.
- Document profile identifier and hash.
- Asset bundle hash.
- Record serial or document identity.
- Timestamp or lifecycle stage.
- Payload hash.
- Previous hash or ledger reference, when chained evidence is required.
- Signature or issuer authentication data.
- Fragment group hash, part number, and total part count for multipart payloads.

## 7. General Lifecycle
1. Define the domain data model and assign compact local codes.
2. Canonicalize and hash the mapping registry.
3. Commission the mapping, verification rules, public keys, document profiles, and assets onto verifier devices.
4. Generate a human readable artifact and a matching Truth QR payload.
5. Print, display, or store the artifact with its Truth QR.
6. Scan the Truth QR on a separate verifier.
7. Validate the context, mapping hash, document profile, signature, and payload structure.
8. Resolve compact codes through the commissioned mapping.
9. Reconstruct the human readable record if needed.
10. Append the verification event to a ledger.
11. Replay accepted events to reproduce totals, reports, summaries, or audit findings.

## 8. Technical Differentiators
Truth QR differs from ordinary QR usage in several ways:

- It encodes meaningful compact domain data, not merely a remote lookup key.
- It binds encoded data to a specific mapping registry through a mapping hash.
- It supports offline verification using precommissioned reference materials.
- It can reconstruct a document locally using a document profile and asset hashes.
- It supports multipart QR transport with unordered reassembly and group hash verification.
- It can append accepted records once and reject duplicates without changing derived totals.
- It can link item level records to aggregate records through hashes, signatures, and replayable ledgers.
- It separates human readable presentation from compact machine readable truth while binding both to the same canonical payload.

## 9. Security and Integrity Model
Truth QR can provide the following controls:

- Context rejection for wrong organization, site, record type, version, or scope.
- Mapping rejection when compact codes do not match the commissioned registry.
- Duplicate detection through stable document identities or payload hashes.
- Tamper detection through hashes and optional signatures.
- Fragment substitution detection through group hashes.
- Offline verification without trusting a live server.
- Reproducible record reconstruction from local assets.
- Auditability through append only scan journals.

## 10. Privacy Model
Truth QR can be designed to avoid personal identifiers. A workflow may use temporary control numbers or authorization tokens to permit an action, while excluding those values from the final public or auditable Truth QR payload.

This allows the artifact to carry verifiable facts without exposing unnecessary personal data.

## 11. Example Embodiments
Truth QR may be used in:

- Election ballots, tally sheets, election returns, canvassing reports, and audit records.
- Survey systems where responses are coded, printed, and independently aggregated.
- Field data extraction where inspection findings are encoded on site and later verified offline.
- Voucher systems where entitlement, redemption, and approval states are encoded and audited.
- Certificates, permits, licenses, laboratory reports, and compliance documents.
- Logistics, inventory, chain of custody, and delivery confirmation records.
- Disaster response forms where remote connectivity is unreliable.

## 12. Possible Patentable Themes
Potential invention themes include:

- Compact code mapping with canonical mapping hashes for offline QR interpretation.
- QR payloads that carry domain meaning without requiring remote record lookup.
- Multipart QR fragmentation with group hash, unordered reassembly, and substitution rejection.
- Local reconstruction of human readable documents from payload plus commissioned document profiles.
- Append once verification ledgers that produce replayable aggregates from scanned artifacts.
- A general framework for binding human readable documents, QR payloads, rendering profiles, evidence manifests, and verification reports.

## 13. Advantages
Truth QR enables:

- Independent verification.
- Offline operation.
- Reduced QR density through compact codes.
- Stronger binding between printed documents and encoded data.
- Better transparency for observers and auditors.
- Repeatable reconstruction of records and reports.
- Adaptability across regulated domains beyond elections.

## 14. Relationship to Future Applications
Truth QR can be treated as a foundational technology. Specific applications may then be separately protected or described as systems using Truth QR, including:

- Election System using Truth QR.
- Survey System using Truth QR.
- Field Data Extraction System using Truth QR.
- Voucher and Entitlement System using Truth QR.
- Compliance Reporting System using Truth QR.
