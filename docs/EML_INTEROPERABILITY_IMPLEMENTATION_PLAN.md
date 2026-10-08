# EML Interoperability Implementation Plan

## Objectives

1. Demonstrate that WAES can exchange election information using a published
   OASIS Election Markup Language profile.
2. Improve compatibility with systems familiar to COMELEC without claiming that
   the supplied FASTrAC terms of reference mandate EML.
3. Preserve the WAES canonical election model, compact signed `truth://`
   protocol, and air-gapped operation.
4. Support standardized election configuration, precinct results, canvassing
   results, statistics, and audit exports.
5. Keep the implementation adaptable if COMELEC later supplies another EML
   version, localization, schema profile, or acceptance suite.
6. Cryptographically bind each EML result artifact to its corresponding printed
   document, Truth QR payload, election manifest, and evidence package.

## Working Position

WAES will adopt OASIS EML 7.0 as the initial `WAES EML 7 Base Profile`. This is
an interoperability baseline, not a claim of COMELEC compliance or procurement
conformance.

EML is an exchange representation at controlled system boundaries. It does not
replace Laravel, PostgreSQL, the canonical WAES domain model, native evidence,
or compact QR payloads. Schema validity proves structural conformance only;
trusted signatures, manifests, lifecycle state, and jurisdiction checks establish
whether an artifact may affect election state.

## Delivery Status

| Phase | Scope | Status |
| --- | --- | --- |
| 1 | Profile foundation, pinned schemas, secure XML, and validation | Implemented |
| 2 | Pre-election EML 110, 230, and 410 mapping | Implemented with staging-only import service |
| 3 | Signed precinct EML 510 count artifact | Implemented |
| 4 | Truth QR, PDF, manifest, and EML artifact binding | Implemented |
| 5 | Canvassing import and signed EML 520 result artifact | EML 520 export implemented; package intake remains pending |
| 6 | EML 480 audit and EML 530 statistics exports | Implemented |
| 7 | Operator verification and evidence-package UI | Implemented for export and schema validation |
| 8 | Conformance, adversarial, and air-gapped testing | Automated baseline implemented; physical acceptance pending |
| 9 | Optional COMELEC localization | Awaiting authoritative profile |

The implemented baseline vendors and hash-pins the official OASIS EML 7.0
schema set, supports messages 110, 230, 410, 480, 510, 520, and 530, and adds
the offline `election:eml-verify` command. Precinct evidence packages are signed
and include their EML artifacts, detached signatures, election-return files,
and a hash manifest. Canvassing appliances use an explicit commissioned list of
trusted precinct public keys; precinct private keys are not shared. Direct
intake of an EML evidence package into the canonical
canvass is deliberately not marked complete until the package custody and
supersession ceremony is specified and tested on the appliance.

## Phase 1: EML Foundation

1. Add a versioned `EmlProfile` contract rather than coupling election services
   directly to EML 7.0 classes.
2. Vendor the authoritative EML 7.0 XSD artifacts needed by the selected profile.
3. Record and verify the expected hash of every schema artifact.
4. Disable external entities, DTD processing, remote schema loading, and network
   retrieval in all XML readers and validators.
5. Define canonical XML serialization rules for stable hashing and signatures.
6. Implement schema validation, artifact hashing, signing, signature
   verification, and structured validation reports.
7. Keep private signing keys outside source control and expose only the minimum
   signing operation required by the closeout ceremony.

Acceptance:

- Validation works without internet access.
- A modified or substituted schema is detected before use.
- Malformed XML, entity expansion, external references, and unsupported message
  types are rejected without changing election state.
- Profile and schema versions are explicit in every artifact and report.

## Phase 2: Pre-Election Configuration

Implement deterministic mappings for:

- EML 110: election event and election identifiers.
- EML 230: contests, candidates, affiliations, and jurisdictions.
- EML 410: ballot definitions and ballot styles.

Validated EML imports feed a staging model. They do not become active election
configuration until WAES validates all identifiers and constraints, produces its
canonical configuration and signed election manifest, and completes the existing
commissioning and officer-authorization ceremony.

Acceptance:

- Importing the same EML produces the same canonical WAES configuration hash.
- Exporting and re-importing supported data preserves all election-significant
  fields.
- Unknown extensions are either preserved in a documented extension area or
  rejected; they are never silently discarded when election-significant.
- No EML import can directly open polls or overwrite an active official run.

## Phase 3: Precinct Count Artifact

At precinct close, derive an EML 510 count artifact from the same frozen canonical
tally used to produce the election return.

The closeout transaction produces:

1. The human-readable election-return PDF.
2. The compact signed Truth election-return payload.
3. The signed EML 510 precinct count artifact.
4. A manifest recording the canonical tally hash, PDF hash, Truth payload hash,
   EML hash, profile versions, signing-key identifiers, and generation time.

The EML artifact must never be independently recomputed from presentation-layer
content. All representations originate from one immutable closeout snapshot.

Acceptance:

- Repeated generation from the same snapshot is deterministic apart from fields
  explicitly defined as non-canonical presentation metadata.
- A one-vote change alters the tally, Truth payload, EML, and evidence-manifest
  hashes in a detectable way.
- The PDF, Truth payload, and EML artifact can be independently compared with
  the frozen closeout snapshot.

## Phase 4: Truth QR and EML Binding

Do not place full EML XML inside ballot or election-return QR codes. The compact
signed Truth payload remains optimized for reliable paper scanning.

For election returns, bind the Truth payload to:

- the election and run identifiers;
- the signed election-manifest hash;
- the precinct and return scope;
- the canonical tally or result hash;
- the EML profile and canonical EML artifact hash; and
- the issuing key and signature.

Multipart QR framing continues to provide transport reconstruction and fragment
integrity. The document signature provides authenticity after reconstruction.

Acceptance:

- Reordered multipart QR scans reconstruct the same authenticated document.
- Mixed, missing, duplicate, or substituted fragments cannot produce an accepted
  result.
- An EML artifact that disagrees with its Truth payload is quarantined and never
  enters the canvass.

## Phase 5: Transmission and Canvassing

Define an evidence package containing the signed EML 510 artifact, election-return
PDF, Truth payload, signed election-manifest reference, signatures, and artifact
manifest. The package may travel over an authenticated channel or authorized
physical media without changing its verification rules.

The canvassing ingestion sequence is:

1. Enforce size, media, and package limits.
2. Validate the declared EML profile and local XSDs.
3. Verify document, package, and election-manifest signatures.
4. Verify election, jurisdiction, precinct, return scope, candidate mapping, and
   lifecycle eligibility.
5. Compare the EML totals with the Truth payload and artifact manifest.
6. Apply atomic duplicate and supersession rules.
7. Persist the complete accepted or rejected ingestion event.
8. Update the canonical canvass only after every required check passes.
9. Generate a signed EML 520 canvass result from the frozen canonical canvass.

Acceptance:

- Wrong-election, wrong-jurisdiction, unsigned, altered, expired, and duplicate
  packages are rejected with stable reason codes.
- Concurrent ingestion cannot count one precinct return twice.
- Canvassing remains reconstructable from accepted persisted source artifacts.
- A transmitted artifact and a physically scanned artifact resolve to the same
  official document identity.

## Phase 6: Audit and Statistics

Implement:

- EML 480 as a standardized audit-log export.
- EML 530 as a standardized statistics export related to counts and results.
- References from EML exports to the native WAES activity journal, scanner
  ledger, commissioning report, and evidence-manifest hashes.
- Offline verification commands that do not require the running web application.

EML exports supplement the native evidence. They do not replace raw accepted
documents, native hash-chained journals, PDFs, signatures, or custody records.

Acceptance:

- An independent verifier can validate schemas, signatures, hashes, and declared
  relationships without database access.
- Audit exports exclude voter identity and unnecessary ballot-level disclosure.
- Exporting to EML cannot mutate the native evidence being represented.

## Phase 7: Operational UI

Provide controlled actions to:

- view and download an EML artifact;
- validate an uploaded EML artifact without importing it;
- compare EML, Truth QR, printed PDF, and manifest identities;
- display profile version, schema hash, signature status, manifest hash, and
  canonical artifact hash; and
- export the complete precinct or canvassing evidence package.

Validation screens must use plain-language outcomes with access to technical
details for authorized support personnel. Public views expose verification facts,
not private keys, internal paths, credentials, or sensitive ballot-level data.

## Phase 8: Verification

Automated coverage includes:

- XSD validation and schema-hash pinning;
- deterministic WAES-to-EML serialization;
- supported EML-to-WAES-to-EML round trips;
- signature creation and verification;
- malformed XML, external entities, entity expansion, and oversized inputs;
- unsupported profiles, extensions, identifiers, and message types;
- wrong election, precinct, jurisdiction, mapping, mode, and lifecycle stage;
- modified totals, signatures, manifests, and evidence packages;
- duplicate and concurrent result ingestion;
- EML, Truth payload, PDF, and manifest disagreement; and
- complete offline operation with no remote schema retrieval.

Physical and operational acceptance covers removable-media custody, package
transfer, failed validation, recovery after interruption, and independent
verification on an air-gapped canvassing appliance.

## Phase 9: COMELEC Localization

When COMELEC supplies an authoritative EML version, schemas, extensions, sample
files, or acceptance criteria:

1. Preserve the WAES EML 7 Base Profile.
2. Add a separate versioned COMELEC profile adapter.
3. Produce a field-level mapping and requirements traceability matrix.
4. Record every deliberate extension, restriction, default, and unsupported
   field.
5. Validate against COMELEC fixtures and acceptance criteria.
6. Claim compatibility only for the explicitly tested profile and message types.

## Explicit Non-Goals

- EML does not replace PHP, Laravel, Vue, PostgreSQL, or the WAES domain model.
- XML does not become the internal database or event-storage format.
- Individual ballot QR codes do not contain full EML documents.
- EML schema validation does not establish authenticity or authorization.
- The initial profile does not include voter-register exchange or individual cast
  vote exchange unless a later legal and privacy review requires them.
- WAES will not claim COMELEC EML compliance without an authoritative profile
  and acceptance criteria.

## Delivery Order

1. EML profile contract, pinned schemas, secure XML, and validation.
2. EML 510 precinct export from a frozen closeout snapshot.
3. Truth QR, PDF, manifest, and EML cryptographic binding.
4. Canvassing verification, import, and EML 520 export.
5. EML 110, 230, and 410 configuration exchange.
6. EML 480 and 530 audit and statistics exports.
7. Operator verification UI and complete evidence packages.
8. Conformance and physical air-gapped acceptance.
9. COMELEC localization when authoritative materials become available.
