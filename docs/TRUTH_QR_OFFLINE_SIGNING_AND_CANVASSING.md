# Truth QR offline signing and canvassing

## What AES does today

AES creates one canonical election return (ER) per requested scope from the precinct closeout snapshot. Truth QR signs that scope's canonical ER material with Ed25519, places the signature and public key in the compact ER payload, and then wraps the payload in one QR or divides it into numbered QR fragments. AES currently uses `EmlArtifactSigner` as the package's `SignatureProvider`. Its local signing key and configured trusted-public-key list support the role demo; they are not a nationwide key-distribution system. Ballot QR payloads in Truth QR `v0.1.0` are not signed.

The private signing key must never be copied into a printed QR, public evidence bundle, or municipal verifier. A public key carried by a QR is useful for identifying the claimed signer, but is not a trust anchor by itself. A canvasser must compare it with an independently provisioned trusted key.

## Where the signature lives in a multipart ER

1. Build the deterministic canonical ER material, including election and precinct IDs, scope, mapping identity, counts, candidate totals, return hash, document references, and EML evidence reference.
2. Sign those bytes **once**. Add the signature to the compact ER payload.
3. Base64url-encode the entire signed payload. If its complete `truth://` URI exceeds the configured QR limit, split that encoded text into numbered fragments. Each fragment contains its part number, total part count, a SHA-256 hash of the complete canonical payload, and one chunk of the encoded text.
4. At canvass, collect every fragment, in any order. Reject mixed, conflicting, or incomplete sets; reassemble the canonical payload; compare its SHA-256 hash with the fragment group hash; then decode and verify the one ER signature against a trusted key.

The signature is therefore **inside the reassembled payload**. Its encoded characters can fall across fragments; it is neither confined to the first QR nor copied into every QR. A single fragment and its repeated group hash cannot authenticate an ER. No tally is accepted until the whole signed record is verified. The fragment hash checks consistency; the digital signature authenticates the complete record against the trusted signer key.

## Offline election key flow to commission before nationwide use

This section is the target operating design, not an implemented national key ceremony.

1. The election authority establishes an offline trust anchor. Its private key stays under a controlled commissioning ceremony; the authentic public trust anchor is installed on verifiers through an independently checked election package.
2. Each precinct receives or generates an election-scoped signing key. Its private key remains on the authorized precinct appliance. A signed, versioned roster binds each public key and key ID to its election, precinct, permitted purpose, validity period, and status. Municipal stations receive the relevant roster and trust anchor through controlled offline media before canvassing.
3. Precinct ballot signing, if introduced, signs each ballot's canonical fields before printing. The precinct scanner verifies the ballot signature and its precinct, style, and mapping context. This requires a new signed ballot wire version; current unsigned ballots must be identified as such.
4. At closeout, AES reconciles its accepted ballots and paper evidence, produces scoped ERs from the same frozen tally, signs each complete ER, and prints every QR fragment with its scope and part number.
5. The municipal scanner reassembles each ER, verifies the signed roster and ER signature, checks the election, precinct, scope, mapping, trusted key status, and duplicate/supersession policy, and records the result. It does not need a precinct private key or an internet connection. The roster is local; the QR only needs a compact key ID and signature in a future wire revision.
6. Key replacement or revocation requires an authenticated, versioned roster update delivered to offline verifiers. A missing, stale, unknown, or revoked key must not be silently accepted. Preserve the roster version and verification outcome with the canvass evidence.

The authority may use a self-signed root as the trust anchor, but the root's authenticity must be checked during installation; a self-signed key encountered only in a scanned QR is not trusted. The root signs key rosters, not every ballot or ER. AES owns key custody, commissioning, distribution, and election policy. Truth QR owns canonicalization, versioned payloads, signing and verification mechanics, and fragment handling.

## Preflight and audit checks

- On precinct and municipal devices, verify the election package, trust anchor, roster version, assigned election/precinct, public-key binding, and expected document profile before opening polls or canvass.
- Use separate protected signing material for election records. Do not derive a production signing key from `APP_KEY` or reuse a general application secret.
- Test a complete signed ER and a multipart ER offline. Deliberately test a missing part, a part from another ER, changed bytes, an untrusted signer, and a cross-precinct return. Confirm each is rejected and recorded.
- Confirm printed human-readable totals match the signed QR record. A valid signature proves the record's origin and integrity, not that its tally agrees with paper ballots; reconciliation and paper audit remain separate checks.
- Keep voter identity out of ballot signing material and logs. A precise ballot timestamp or serial can create a correlation path if linked with voter activity; decide those fields as part of the ballot-secrecy design.

## Current implementation boundary

Truth QR `v0.1.0` signs ER payloads through a host-provided `SignatureProvider` and checks the signature after reassembly. AES's `EmlArtifactSigner` accepts its locally derived public key and configured `ELECTION_EML_TRUSTED_PUBLIC_KEYS`; its `APP_KEY` fallback is a demo convenience. Signed ballot payloads, an authority-signed key roster, key rotation/revocation, and municipal provisioning ceremonies are future work. Do not describe a role-demo signature result as proof that these operational controls have been commissioned.
