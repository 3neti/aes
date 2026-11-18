# Patent Drafting Context

This folder preserves technical drafting context for Truth QR and the Alternative Election System.

These documents are engineering outlines for counsel and product planning. They are not legal filing documents, do not establish patentability, and should be reviewed by a qualified patent professional before use in any filing.

## Documents

- `TRUTH_QR_STANDALONE_TECHNOLOGY_OUTLINE.md` describes Truth QR as a reusable verification technology independent of elections.
- `ELECTION_SYSTEM_USING_TRUTH_QR_OUTLINE.md` describes AES as one election-system application of Truth QR.

## Portfolio Relationship

Truth QR is treated as the foundational technology. AES is a first application that uses Truth QR for voter-verifiable paper ballots, offline verification, tallying, election returns, watcher review, and audit workflows.

Future applications may include survey systems, field data extraction, vouchers, inspections, logistics, certificates, compliance reporting, and other regulated paper or offline workflows.

## End-to-End Truth QR Method

Truth QR is intended to carry compact, offline-verifiable records across printed QR fragments and domain profiles:

1. A versioned domain profile defines record types, compact field codes, local reference mappings, and verification rules. An election profile covers ballots and election returns; other profiles can cover surveys or field collection.
2. The domain driver validates a record against its profile, converts it to compact codes, and produces deterministic canonical bytes. Mapping and profile identifiers or hashes bind those bytes to the reference material needed to interpret them.
3. The transport encodes the bytes for QR carriage. A versioned envelope identifies the record type and profile, and divides oversized payloads into numbered fragments bound by a common payload hash.
4. One or more QR symbols are printed with the human-readable artifact. Image size and rendering choices do not change the encoded record.
5. An offline verifier scans fragments in any order, rejects mismatched or incomplete sets, reassembles the canonical bytes, and checks the envelope, mapping, context, hashes, and any required signature using locally available reference material.
6. The verifier reconstructs the domain record for human review. An application may record the verification result in an append-only audit trail.

The existing AES `truth://` ballot and election-return formats are the initial election profile. Extracting them into a package should preserve their encoded bytes and verification behavior; additional profiles require explicit versions and compatibility tests.
