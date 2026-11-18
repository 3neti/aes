# Precinct ceremonies: user flow and contract

## Purpose and status

This document specifies the **proposed precinct ceremony order** for extraction into `election-ceremonies`. It describes what a user does, what each action consumes, how its evidence evolves, and what it produces. It is a design contract, not a claim that the current package profile or AES already enforces every step. Canvassing ceremonies and the separate research-bundle review run are outside this scope.

`election-ceremonies` should own stage transitions, commissioned run identity, authorization hooks, idempotency, evidence requirements, and the durable ceremony result. The election profile should choose the ordered ceremonies and required evidence. AES should supply screens, officer authentication, device access, persistence, and artifact-producing adapters. Country-specific rules and labels belong in an election driver or profile.

A **ceremony** is an auditable action with prerequisites and a result. A **stage** is the durable condition reached after an action. A **pipe** performs one ordered check or projection inside a ceremony. A new context is created for each action; the durable run and its artifact references connect actions over time. Ordinary ballot transactions during voting are repeatable operations, not one-time stage transitions.

## Revised order

| Order | Ceremony | Completion point |
|---:|---|---|
| 1 | Provision precinct | Verified bundle, assignment, precinct setup, and devices are loaded. |
| 2 | Run Friday testing | Test ballots are printed, scanned, and counted against expected results. |
| 3 | Verify Friday results | Manual verification and discrepancy review are recorded; any discrepancy blocks progress. |
| 4 | Zero-out | Test ballots and test tallies are cleared; a zero-out report is produced. |
| 5 | Seal and certify | Required FTS reports pass and sealing evidence is recorded. **Only now is the precinct certified.** |
| 6 | Open precinct | Officers formally start precinct operations. |
| 7 | Open polls | Voting is authorized to begin. |
| 8 | Voting | Repeated ballot issuance, casting, and spoilage occur within this stage. |
| 9 | Close polls | Further voting is stopped. |
| 10 | Count and reconcile | Accepted ballots are tallied and reconciled. |
| 11 | Approve election return | Produce and approve the return, including EML and Truth QR artifacts. |
| 12 | Handoff and backup | Record transfer or transmission and final backup. |
| 13 | Record custody | Record who holds the materials and artifacts. |
| 14 | Close precinct | Formally finish precinct operations. |
| 15 | Begin audit | Start the post-election audit process. |

FTS means Final Testing and Sealing, referred to in AES as “Friday certification.” Orders 2–5 are **separate recorded actions within one FTS phase**: manual verification can take time, and a discrepancy may need resolution before the next action. Order 8 is an operating window rather than one click. Orders 10–12 likewise contain multiple user actions; their final approvals form the stage gates.

## Shared ceremony input and result

Every action receives a common envelope: `run_id`, `ceremony_id`, `actor_id`, exact `bundle_hash`, exact `assignment_hash`, `idempotency_key`, and action-specific input. The runner also knows the signed profile hash, current stage, and target stage. The context accumulates a working record, named evidence references, and an execution trace. The store commits the result only after required evidence is present. A retry with the same idempotency key and identical input returns the same result; changed input with that key must fail.

Action-specific payloads below describe the **data contract**, not a mandate to create fifteen PHP DTO classes immediately. A typed DTO is useful when an action reaches the package boundary; plain arrays may remain inside a host adapter. Credentials such as officer PINs are verified by AES and must not be retained in the ceremony result. Where an artifact exists, record its stable ID, digest, schema or profile version, and custody-relevant metadata. A filename alone is insufficient evidence. A hash proves byte equality, not who approved an action; approvals require separately verified officer identity and an appropriate signature or attestation policy.

The normal result of every successful action is an auditable ceremony event containing actor, transition, input hash, bundle and profile hashes, evidence references, and previous-event linkage. The sections below describe the additional domain artifact and stage reached. A failed check records a visible failure or remediation path but must not advance the stage. Exact event persistence is a host concern under the package's store contract.

## 1. Provision precinct

**User experience.** An authorized setup officer selects or imports the election bundle, verifies its signature with a locally trusted authority key, selects this workstation's signed assignment, and checks the displayed election, precinct, ballot style, device identity, and source hashes. The officer records the Electoral Board, printer/scanner inventory, ballot stock, containers, seals, and local setup details. The screen presents missing or mismatched items before allowing FTS.

**Payload.** Bundle and assignment references, device ID, assigned precinct and jurisdiction, setup and inventory details, officer attestation, and hashes of the verified bundle/profile/registry. The package must bind the run to these exact identities; it must not infer authority from the ZIP alone.

**Context and gate.** Verify signature and manifest, assigned role and jurisdiction, required registry/profile entries, device binding, and setup completeness. Add bundle verification, assignment verification, device check, and setup evidence. A research or pilot bundle cannot be promoted to election-day readiness merely by finishing this action.

**Result.** A commissioned precinct run and setup report with the verified identities. The run enters the FTS-ready stage. If any trust or scope check fails, activation stops and the officer sees the failing item. AES already has preparation, activation, setup, and device services; live commissioning from the signed national bundle remains a separate integration task.

## 2. Run Friday testing

**User experience.** On the Certification screen, the officer starts known test ballots. The system checks package integrity, builds the certification deck, runs it through the ballot/QR and counting path, and shows expected versus actual accepted ballots and candidate totals. Failed checks remain visible and require correction and a new test run.

**Payload.** Commissioned run and precinct IDs, active mapping/profile references, certification deck definition or hash, device check references, and the authenticated officer. The test deck must be distinguished from live voting records.

**Context and gate.** Verify that provisioning succeeded; add package-integrity, deck, printed/scanned test, machine-count, and comparison evidence. A mismatch leaves FTS incomplete. Test records must never become live votes.

**Result.** A hashed Friday certification report with expected and actual tallies, accepted/rejected counts, and pass/fail status. The next user action is manual verification; this report alone does not certify or open the precinct. AES currently produces `certification/friday-certification-report.json` through `CertificationService`.

## 3. Verify Friday results

**User experience.** Election personnel inspect the test output, enter an independently prepared manual return summary, and compare it with the machine result. They run discrepancy analysis and review the report and official minutes. If counts differ, the screen explains the differences and holds the FTS sequence for authorized re-appreciation and a repeat comparison.

**Payload.** Manual accepted/rejected ballot counts and contest/candidate tally, the Friday report hash, precinct identity, officer identity, and any discrepancy or resolution references. The submitted manual tally is user-supplied evidence; it must not be silently copied from the machine tally.

**Context and gate.** Verify the manual-return shape and precinct, compare all totals and candidates, link the machine and manual report hashes, and evaluate the discrepancy. A missing or failed manual verification or unresolved discrepancy blocks zero-out and sealing.

**Result.** Manual-return, manual-verification, discrepancy, and minutes references with hashes and an explicit clear/discrepancy status. The gate passes only when the comparison and discrepancy report pass. AES currently writes `manual-verification-report.json` and `fts-discrepancy-report.json` as separate actions; the proposed ceremony boundary may retain them as separate invocations under this phase.

## 4. Zero-out

**User experience.** After reviewing a clear FTS result, the officer runs Zero-Out and sees counts before and after. The screen lists the test artifacts cleared and confirms that accepted, rejected, and spoiled test-ballot counts are zero.

**Payload.** Run and precinct IDs, passing FTS and manual-verification/discrepancy report hashes, authorized officer, and the test-artifact set to clear. No live ballot may be included.

**Context and gate.** Confirm the FTS comparison is clear, identify only ephemeral test tally/ballot records, clear them, then independently read back the remaining counts. Add before/after counts and cleared-artifact references. Failure to reach zero blocks sealing.

**Result.** A hashed zero-out report and zero test counts. AES's `ZeroOutService` writes `certification/zero-out-report.json`; the package should treat this as required FTS evidence rather than assuming the clear operation succeeded.

## 5. Seal and certify

**User experience.** The officer reviews a readiness checklist, confirms physical seal details and custody as applicable, and records sealing. The system displays the hashes of the setup, initialization, certification, manual-verification, discrepancy, and zero-out reports. A missing or failed item gives an actionable error.

**Payload.** The exact FTS report references and hashes, seal/container details when applicable, authorized approver identities, and the run/precinct identities. The election profile specifies which approvals are required; credentials are verified outside the retained payload.

**Context and gate.** Re-read and verify all referenced reports, require clear discrepancy and zero counts, ensure they belong to the same run and precinct, then create sealing evidence. This is the **single FTS completion gate**. Repeating a failed FTS action must invalidate or supersede downstream evidence based on the old report hashes.

**Result.** Passing sealing report and certified-and-sealed stage. AES currently writes `certification/sealing-report.json`; its controller also sets the lifecycle directly to `open_precinct` after a pass. In the extracted flow, sealing should finish FTS first, leaving a distinct officer action for opening the precinct.

## 6. Open precinct

**User experience.** The responsible officer checks the displayed certified-and-sealed status, precinct identity, equipment and seal status, then explicitly opens the precinct. The system records who opened it and when.

**Payload.** Officer identity/attestation, FTS sealing report hash, precinct and device IDs, opening time, and any opening observations.

**Context and gate.** Confirm a current passing FTS seal for this run and no disqualifying device, assignment, or custody change. Add opening evidence and advance only once.

**Result.** Open-precinct event and stage. Polls are not yet open, so no ballot may be cast. AES has `CeremonyActions::openPrecinct()`, but its current sealing controller bypasses this distinct user action by setting `open_precinct` immediately.

## 7. Open polls

**User experience.** An authorized officer enters credentials, reviews the initialization/zero-vote status, and opens the polls. The screen displays the opening time and active precinct. A failed initialization or wrong officer prevents opening.

**Payload.** Verified officer identity, opening time, certified seal hash, initialization/zero report hash, and precinct/run IDs.

**Context and gate.** Confirm `open_precinct`, valid officer, current initialization evidence, and no live votes before opening. Add officer and initialization evidence. If the profile keeps `open_polls` and `voting` as distinct stages, starting voting must be an explicit next transition; it must not happen implicitly without an event.

**Result.** Open-polls event and voting authorization. AES currently verifies officer code/PIN and writes an opening initialization report. Its `openPolls()` lifecycle helper may advance from `open_precinct` to `open_polls`, or from `open_polls` to `voting`; this needs one unambiguous package sequence.

## 8. Voting

**User experience.** While polls are open, officers admit eligible voters and manage special polling cases. A voter reviews selections, finalizes a ballot, verifies the printed ballot, and deposits it; an authorized officer can handle spoiled or rejected ballots through the existing controls. The system presents no running candidate totals to voting users.

**Payload.** Each repeatable operation has its own minimal input: authorization or one-use control reference, ballot ID, selected candidate codes or encrypted print release, print/deposit status, and a spoilage reason when relevant. Do not place voter identity alongside plaintext choices in the ceremony event. Bundle/profile/precinct identity is inherited from the commissioned run.

**Context and gate.** For every operation, confirm voting is active, authorization is unspent, the ballot style belongs to this precinct, QR payload and print output agree, and a deposited ballot is recorded once. Append ballot/deposit evidence without advancing the precinct stage for each voter.

**Result.** A voter-verifiable ballot and an accepted sealed deposit or a recorded rejection/spoilage. The operating stage remains `voting` until close polls. These operations may use narrower services and DTOs rather than one giant voting ceremony context.

## 9. Close polls

**User experience.** Officers review admitted/in-progress voters, ballot stock and physical-box counts, then close the polls. The system blocks new authorizations and ballot finalization, records closing evidence, and prepares sealed records for counting.

**Payload.** Closing officer identities, close time, issued/deposited/spoiled/unused counts, physical ballot count or pending reconciliation reference, and any exceptional close reason.

**Context and gate.** Ensure no unresolved active voter session or pending deposit; freeze voting intake; record legal close-polls evidence and the sealed-box boundary. After closing, opening the box for counting is a separate auditable step or a clearly named subaction.

**Result.** Closed-polls event, frozen voting ledger, and counting-ready stage. AES's current `VotingController::closePolls()` also starts counting and opens the sealed ballot box in the same request; extraction should make those results individually visible even if one screen guides the officer through them.

## 10. Count and reconcile

**User experience.** Officers start counting, see accepted/rejected scan feedback and the machine tally, enter the independent physical count, adjudicate exceptional scans or paper ballots with reasons, and review the reconciliation. They cannot proceed to an official return while reconciliation fails.

**Payload.** Closed-polls evidence, sealed-ledger/box reference, scan or deposit records, physical count, adjudication sequence/disposition/reason, officer identities, and selected tally/print profile. Repeated scans and adjudications are separate events within counting.

**Context and gate.** Validate each QR or sealed record against the assigned election, precinct, mapping, ballot uniqueness and signature policy; aggregate accepted votes; keep rejected/adjudicated records; compare electronic and physical counts; attach counting legal evidence. The final `count_complete` action requires a passing reconciliation.

**Result.** Tally, counting and reconciliation reports, exception records, and a counting-complete stage. AES already exposes scan, physical-count, adjudication, and completion actions. The current pilot YAML only has `begin_counting` and does not model the completion gate.

## 11. Approve election return

**User experience.** Officers review the tally and generated Election Return, print and post required copies, record distribution, and obtain the required distinct approvals. They inspect the displayed return, EML, and Truth QR identity/hashes before accepting it as official. A failed reconciliation, incomplete posting, or missing approval blocks handoff.

**Payload.** Passing tally/reconciliation hashes, election and precinct IDs, return scope/profile, copy-distribution and legal-evidence references, two verified approver identities, and EML/Truth QR profile IDs. Never include officer PINs in the persisted result.

**Context and gate.** Build the return from the frozen tally; check legal counts and distribution; apply the approver policy. Export and verify EML, place its artifact reference in the working return record, then encode that record with Truth QR and record every fragment hash. The order matters: the QR projection must refer to the final EML-backed record. After approval, the return and its print/QR bytes must be immutable; changes require a versioned superseding return.

**Result.** Approved return, print forms, EML artifact, QR fragments, distribution and approval reports, and `election_return` stage. The package has `EmlProjectionPipe` and `TruthQrProjectionPipe`, and the pilot profile lists them under `approve_return`; AES's live return generation/approval currently uses its own services, so this package action is not yet the live path.

## 12. Handoff and backup

**User experience.** An officer prepares a delivery package, verifies the releasing officer and recipient, attempts the configured transfer or records deferred/manual handoff, obtains a receipt, and creates a final backup. The UI shows each destination and whether it was transmitted, deferred, or acknowledged; “deferred” must never be displayed as an acknowledged receipt.

**Payload.** Approved return hash, delivery package/manifest hash, destination and transfer mode, officer/recipient verification, attempt IDs and statuses, receipt details, backup media/device ID, and backup verification hash.

**Context and gate.** Confirm official approval and package integrity; record each handoff attempt and receipt independently; verify the backup can be read and matches the return/package hashes. The profile should state whether an offline deferred transfer is allowed for this assignment and what evidence is required to leave the stage.

**Result.** Delivery package, transfer/receipt report, and verified final backup reference. The current pilot profile separates `begin_handoff` and `complete_handoff`; AES has distinct package, officer/recipient, send, receipt, and backup actions. The package transition should reflect actual evidence, not just a “send” button.

## 13. Record custody

**User experience.** Officers review the inventory of returns, storage media, ballot containers, seals, receipts, and the named recipient. They record condition and transfer time, then confirm that the recipient accepted custody.

**Payload.** Item and artifact IDs/hashes, container and seal identifiers, sender and recipient identities, transfer/acceptance timestamps, condition notes, and delivery/backup references.

**Context and gate.** Match each custody item to this run and the approved return; verify the preceding handoff and backup requirements; record both sides of the custody event under the configured approval policy.

**Result.** Custody/turnover record and `custody` stage. A missing recipient or unmatched item blocks completion. AES has a `CustodyService` and a `recordCustody` action; the extracted ceremony must consume its actual evidence.

## 14. Close precinct

**User experience.** The responsible officer reviews a final checklist: polls closed, counts reconciled, return approved, transfer/backup addressed, and custody recorded. They attest to unresolved exceptions and close the precinct. The UI makes the record read-only for ordinary operations.

**Payload.** Checklist artifact hashes, closing officer identity, close time, and explicit exception references. An exception may be recorded; whether it permits closure is profile policy, not a hidden UI choice.

**Context and gate.** Verify all mandatory upstream evidence and the current `custody` stage. Reject a second closure or an attempt to return to voting without a separately authorized recovery path.

**Result.** Closure record and `close_precinct` stage. AES currently advances this stage through `CeremonyActions::closePrecinct()`.

## 15. Begin audit

**User experience.** An authorized auditor or officer opens the post-election audit view, checks the sealed return and custody chain, selects the applicable sample or audit scope, and starts the audit. The screen identifies the frozen artifacts being examined.

**Payload.** Auditor identity, audit type and scope, selection method/seed when relevant, sealed return/tally/custody hashes, and sample references. The audit must not rewrite the original vote or return record.

**Context and gate.** Confirm precinct closure and artifact integrity, record the selection method, and bind the audit to immutable source hashes. Later sample examination, discrepancy resolution, evidence-pack production, and publication are repeatable audit actions with their own results.

**Result.** Audit-start event and `audit` stage; later actions produce an audit report and evidence pack. AES has `beginAudit()` plus random-manual-audit actions, but the pilot YAML models only the audit entry transition.

## Package extraction checkpoints

The current `election-ph` pilot profile has 13 stages and 12 transitions. Most transitions have no pipes or required evidence. It omits FTS's internal sequence, the distinct provision gate, counting completion, and the multi-action return/handoff/audit gates described here. It should be revised **after** the first real precinct ceremony and its AES adapter are proved end to end, rather than treating empty transitions as completed extraction.

Start with FTS because AES already exposes its individual reports and sealing checks. Extract a profile-driven FTS order and completion gate while AES continues to execute its tested services. Then connect the explicit open-precinct/open-polls actions, counting closeout, and finally the EML/Truth QR return action. At each step, compare the package-driven result with today's AES output and retain the existing flow until parity is demonstrated.

The package should not take ownership of Vue pages, Laravel controllers, passwords/PINs, local device drivers, or AES file paths. Its stable boundary is the verified request, stage transition, ordered pipes, evidence contract, and committed result. Canvassing gets a separate profile after this precinct sequence is working.
