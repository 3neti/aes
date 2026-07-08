# Legal Authority Reconciliation Report

## 1. Executive Summary

Overall assessment: **Architecture is sound with required legal adjustments.**

The current Alternative Election System architecture is directionally aligned with COMELEC Resolution No. 11076 because it is already organized around election ceremonies, printed artifacts, officer attestation, public auditability, watcher visibility, deterministic counting, and paper-backed evidence. Its central idea - an appliance that guides operators through legally meaningful ceremonies rather than administrative screens - is compatible with the General Instructions.

However, the current architecture is still an engineering abstraction. Resolution No. 11076 is more specific and more procedural. It requires the architecture to treat the following as first-class election concepts, not implementation details:

- Electoral Board composition and role-specific actions
- public proceedings and watcher participation
- Final Testing and Sealing (FTS)
- diagnostic, initialization, zero-out, VVPAT audit, transmission, audit log, statistical, and Election Return reports
- official forms, envelopes, paper seals, custody, posting, and disposition
- rejected ballot handling
- special voting flows for PPP/S-PPP, PDL, and IP voting
- transmission and retransmission
- final backup and storage-device custody

The largest required change is to evolve **Friday Certification** into a COMELEC-grounded **Final Testing and Sealing** domain and ceremony. Friday Certification can remain a plain-language product label, but legally and architecturally it should implement FTS.

## 2. Legal Concepts Inventory

| Legal Concept | Purpose | Architectural Equivalent | Implementation Impact |
|---|---|---|---|
| COMELEC General Instructions | Governing authority for FTS, voting, counting, transmission, actors, forms, and custody | Legal source layer | Requirements and scenarios should trace to Resolution sections and annex procedures. |
| Electoral Board (EB) | Conducts FTS, voting, counting, and transmission in the polling place | Election Officer actor is too broad | Split Election Officer into Chairperson, Poll Clerk, Third Member/EB Member, and EB as collective authority. |
| EB Chairperson | Leads proceedings, authenticates ballots, announces actions, signs reports, performs certain machine actions | Officer role | Role-specific permissions, attestations, and checklist ownership. |
| Poll Clerk | Records identity, challenges, minutes, manual ER markings, and voter list actions | Officer role / recorder | Minutes and incident recording must be first-class. |
| Third Member / EB Member | Assists voting, confirms deposits, applies indelible ink, participates in signatures | Officer role | Voting ceremony needs role assignment and handoff support. |
| DESO | Supervises voting center setup, VAD, crowd flow, APP/PPP, support staff coordination | Voting center supervisor | Add actor for voting-center operations outside precinct appliance. |
| DESO Technical Support Staff | Reports technical milestones, installs/troubleshoots ACM, escalates to NTSC | Technician actor, but more constrained | Diagnostics and incident workflows need DESO/NTSC escalation path. |
| EB Support Staff | Assists EB without voting on EB questions | Support Staff actor | Add non-decision support actor and permissions. |
| Watchers | Witness proceedings, take notes/photos where allowed, file protests, sign ERs/seals | Watcher actor | Watcher presence, protest certificates, signatures, and public viewing states must be represented. |
| Citizens' arms / party representatives | Observe, receive ER copies, sign selected artifacts | Watcher subtype / recipient | Artifact distribution model should support official recipients. |
| Public proceedings | EB meetings and key actions are public | Ceremony visibility requirement | UI should expose public display/announcement moments. |
| Final Testing and Sealing (FTS) | Proves ACM readiness before Election Day and seals machine afterward | Friday Certification | Rename or generalize to FTS; include diagnostics, initialization, test voting, ERs, manual verification, VVPAT audit, re-zero, sealing. |
| FTS notice | Public/legal notice of date, time, and place | Scheduling / notice artifact | Add FTS notice artifact outside the device or as record metadata. |
| Diagnostic Report | Proves hardware/component diagnostics were run | Certification evidence | Certification must include component-level diagnostic results and printed report. |
| Initialization Report | Shows zero votes and machine/precinct metadata at opening | Open Voting / zero report | Add required report before voting and during FTS. |
| Test ballots | Ballots used during FTS | Certification Ballots | Align certification ballots with COMELEC's ten-person test ballot ceremony or equivalent legal substitute. |
| Manual Verification of Results | Manual count compares against machine ER and VVPAT audit | Certification comparison | FTS must include manual ER and tri-way comparison. |
| Manual ER | Hand-prepared return from manual count | Artifact | Add manual return artifact for FTS and discrepancy resolution. |
| Re-zero / zero-out | Clears FTS data and restores zero state | Resetting appliance | Reset must generate Zero-Out Report and shut down/seal. |
| Sealing | Secures ACM, ballot box, receptacle, envelopes, storage compartments | Sealed state | Model physical seals, seal numbers, signatures, and custody transitions. |
| Opening polls | Public setup and proof that equipment/ballot box/ballots are sealed or empty | Open Polls ceremony | Expand with seal checks, ballot-box empty proof, official ballot package opening, EB registration, initialization report. |
| PCVL / EDCVL | Posted and operational voter lists | External voter eligibility artifacts | Architecture correctly excludes eligibility, but should represent lists as external legal artifacts used in ceremony. |
| Voters Assistance Desk (VAD) | Directs voters to correct polling place | External support process | Out of core scope, but should be acknowledged in actor/process boundaries. |
| Ballot authentication | Chairperson signs official ballot before issuance | Ballot issuance procedure | AES printed ballot model must specify authentication/signature equivalent if paper is generated by appliance. |
| Ballot secrecy folder / marking pen | Protects secrecy in current paper process | Privacy controls | Tablet/privacy booth equivalent should be documented. |
| VVPAT / voter receipt | Voter-verifiable receipt generated after valid ballot feed | In AES, printed ballot partly assumes this role | Decide whether official printed ballot, voter receipt, and VVPAT are separate or unified artifacts. |
| VVPAT objection | Voter may object to receipt contents; objection is recorded | Spoilage/protest workflow | Add contested receipt/ballot objection path and minutes attachment. |
| Rejected ballots | ACM rejects misread, previously read, invalid, or fake ballots | Rejected ballot workflow | Current rejected ballot support is too generic; add categories, re-feed attempts, replacement rules, envelope custody. |
| Poll closing | Ends voting, handles pending special ballots, initiates result generation | Close Polls ceremony | Must include pre-close receipt of PPP/PDL/IP ballots and no-more-voters checks. |
| Counting / tabulation | Machine generates results after close voting | Counting ceremony | AES QR counting aligns, but should include EB signing and public announcement. |
| Election Returns (ERs) | Official precinct results printed, signed, posted, distributed | Election Return domain | Add copy counts, recipients, signatures/thumbmarks, posting, envelopes, paper seals. |
| Transmission | Sends precinct results to CCS, central, party, media, citizen servers | Optional LTE/export | Must become a legal ceremony after ER generation; offline-first may still support deferred/failed transmission handling. |
| Retransmission | Attempts failed destinations and prints report | Transmission recovery | Add retry/retransmission state and report. |
| Transmission Report | Evidence of destination receipt status | Report artifact | Add to artifacts and traceability. |
| VVPAT Audit | Scans/counts voter receipts and prints audit report | Audit / certification checkpoint | Add VVPAT Audit ceremony before shutdown/final backup. |
| Ballot Review | Post-close review of ballot images/records | Audit support | Add controlled review screen/report, watcher-visible. |
| Audit Log Report | Machine report of operational events | Activity Journal printout | Current journal exists; add official printable audit log report. |
| Precinct Statistical Report | Summary of operational statistics | Report artifact | Add first-class report. |
| Final Backup | Preserves machine data after transmission/reports | Close Precinct ceremony | Add backup ceremony, storage-device handling, and custody. |
| Storage devices | Main and backup media with custody rules | Evidence media | Add storage-device artifact and chain-of-custody model. |
| Ballot box custody | Holds ballots, selected ER copy, minutes copy, unused halves, rejected ballots, VVPAT receptacle | Ballot box artifact | Add contents checklist and sealed custody transition. |
| Envelopes and paper seals | Legal packaging/custody mechanism | Artifact containers | Add generic Official Container model with seal numbers, contents, signatures, recipients. |
| Minutes | Official running record of legally required facts | Activity Journal is not enough | Add human/legal Minutes as separate artifact, linked to journal entries. |
| Protests/challenges/oaths | Resolve voter identity or watcher objections | Incident workflow | Add incident/protest artifacts and EB decision records. |
| PPP/S-PPP | Priority voting away from regular ACM for PWD, senior, pregnant voters | Special polling flow | Add inbound ballot transfer, waiver, aide, envelope, delayed feed, and turnout forms. |
| PDL-SPP | Jail/detention voting with SEB-PDL and return of ballots to regular precinct | Special polling flow | Add remote ballot custody, escort, deadline, and receipt before close. |
| IP AVC/E-SPP/C-SPP/IP-SVR | Indigenous Peoples voting arrangements | Special polling flow | Add special polling variants or extension points. |
| Forms, documents, and supplies | Official materials checked, received, used, reproduced, and disposed | Artifact registry | Add Official Forms Catalog by class, not by exhaustive form UI. |

## 3. Ceremony Mapping

| COMELEC Ceremony / Procedure | Proposed Ceremony | Status |
|---|---|---|
| Delivery and verification of forms, documents, and supplies | Prepare Precinct / Supply Verification | Missing as ceremony |
| Notice and convening of FTS | Schedule FTS / FTS Notice | Missing |
| Final Testing and Sealing | Friday Certification | Present but must evolve into FTS |
| Diagnostic procedure | Certification diagnostics | Partially present |
| EB registration/authentication | Officer Attestation | Partially present |
| Open voting and print Initialization Report | Open Polls | Partially present |
| FTS test voting with ten ballots | Certification Ballots | Partially present; legal mechanics differ |
| FTS close voting and print ERs | Certification ER generation | Partially present |
| FTS VVPAT audit | Certification Audit | Missing |
| FTS manual verification and manual ER | Certification comparison | Missing |
| FTS re-zero and shutdown | Reset Appliance / Seal Appliance | Partially present |
| FTS disposition of forms and supplies | Close Certification / Custody | Missing |
| Election Day preliminaries | Open Precinct / Open Polls | Partially present |
| Voter identification and ballot issuance | External election procedure / Voting | Mostly out of scope, but interface points missing |
| Voter ballot marking/casting | Voting | Present but AES uses tablet-first flow |
| VVPAT verification and objection | Ballot Verification / Spoilage | Partially present |
| Rejected ballot handling | Rejected Ballot | Partially present |
| PPP/S-PPP voting | Special Polling | Missing |
| PDL voting | Special Polling | Missing |
| IP voting | Special Polling | Missing |
| Close voting | Close Polls | Present but thin |
| ER generation and signing | Election Return | Present but thin |
| Transmission and retransmission | Transmission | Missing |
| Post-transmission ER printing/reporting | Election Return / Reporting | Missing |
| VVPAT audit after close | Audit / Close Polls | Missing |
| Audit log and statistical report printing | Reporting | Missing |
| Final backup | Close Precinct | Missing |
| Disposition of ERs, documents, ballot box, ACM | Close Precinct / Custody | Missing |

Recommended ceremony additions:

- Supply Verification
- Final Testing and Sealing
- Open Voting / Initialization
- Special Polling Intake
- Transmission
- VVPAT Audit
- Final Backup
- Disposition and Turnover

Recommended naming change:

- Replace **Friday Certification** in formal architecture with **Final Testing and Sealing (FTS)**. The UI may still present it as "Certification" if the domain dictionary maps legal terminology to simpler labels.

## 4. Lifecycle Comparison

Current lifecycle:

```text
Provision
Certification
Open Precinct
Open Polls
Voting
Close Polls
Counting
Election Return
Close Precinct
Audit
```

COMELEC-derived lifecycle:

```text
Supply Receipt and Verification
FTS Notice
Final Testing and Sealing
FTS Disposition and Sealing
Election Day Setup
Open Voting / Initialization Report
Voting
Special Polling Ballot Intake
Close Voting
ER Generation and Signing
Transmission / Retransmission
Post-Transmission Printing and Reports
VVPAT Audit
Audit Log / Statistical Reports
Final Backup
Disposition and Turnover
Post-Election Audit / Custody Review
```

Missing states:

- FTS Scheduled / Notice Posted
- FTS In Progress
- FTS Manual Verification
- Re-Zeroed
- Physically Sealed
- Initialization Report Printed
- Awaiting Special Polling Ballots
- ER Printed / Signed / Posted / Distributed
- Transmission Pending / Transmitted / Partially Failed / Retransmitted
- VVPAT Audit Complete
- Final Backup Complete
- Turned Over

Incorrect or incomplete ordering:

- Election Return is currently modeled before transmission, but COMELEC requires ER generation, transmission, additional printing/reporting, VVPAT audit, audit log/statistical reports, and final backup before shutdown/turnover.
- Counting is currently a separate scan-each-ballot ceremony. The COMELEC ACM model tabulates as ballots are fed and generates results after close voting. AES may retain post-close QR counting, but the legal lifecycle must still include ER generation, transmission, reports, VVPAT audit, and custody in the correct order.
- Certification currently resets and seals after comparing expected returns, but COMELEC FTS requires diagnostic report, initialization report, ERs, VVPAT audit report, manual ER, re-zero, shutdown, envelope disposition, and physical sealing.

Additional checkpoints:

- public explanation of each key ceremony
- watcher visibility and optional signatures
- EB majority/role authentication
- paper seal number recording
- envelope contents checklist
- discrepancy resolution and escalation to DESO/NTSC
- print confirmation for required official reports

## 5. Friday Certification vs Final Testing and Sealing

Friday Certification should evolve into a generalized implementation of COMELEC **Final Testing and Sealing**.

| FTS Requirement | Current Friday Certification | Recommendation |
|---|---|---|
| Public notice and convening | Not modeled | Add FTS schedule/notice artifact and public ceremony start. |
| Explain purpose/procedure to those present | Not modeled | Add opening explanation checklist and journal/minutes entry. |
| Verify machine/case contents and seals | Partially covered by diagnostics | Add physical inventory and seal inspection. |
| Diagnostics | Printer/scanner verification exists | Expand to touchscreen/display, audio/accessibility, network, USB/storage, printer, scanner/camera, cards/authentication, power if applicable. |
| Initialization Report | Missing | Add zero-vote Initialization Report before test ballots. |
| Test ballots | Certification Ballots exist | Preserve deterministic certification deck, but support legal FTS mode with public test ballots or approved equivalents. |
| VVPAT / voter receipt verification | Missing | Add VVPAT/receipt generation, voter verification, receptacle, and audit scan/count. |
| Election Returns during FTS | Present conceptually | Generate FTS ERs as separate non-election artifacts. |
| Manual verification | Missing | Add manual count, Manual ER, and tri-way comparison among machine ER, Manual ER, and VVPAT Audit Report. |
| Discrepancy handling | Generic fail | Add re-appreciation/review, technical escalation, incident report, and resolution before re-zero. |
| No transmission during FTS | Not explicit | Add guardrail preventing transmission/export of FTS results. |
| Re-zero / machine reset | Present as reset | Add Zero-Out Report and enforce clean operational storage after FTS. |
| Printing | Certification Report only | Add Diagnostic, Initialization, ER, VVPAT Audit, Manual ER, Zero-Out, and Certification Summary reports. |
| Sealing | Present as sealed state | Add physical seals, envelope contents, signatures, and custody location. |
| Machine reopened only on Election Day | Not modeled | Add sealed-until state and authorized opening transition. |

Architectural recommendation:

- Rename the domain from **Certification** to **Certification and FTS**, or introduce **FTS** as a subdomain under Certification.
- Treat Certification Reports as summary artifacts, not substitutes for COMELEC-required reports.
- Make FTS executable through the Scenario Runner in both simulated and hardware modes.
- Add legal mode profiles: `FTS`, `Election Day`, `Recovery`, and `Audit`.

## 6. Actor Analysis

Current architecture actors:

- Voter
- Election Officer
- Poll Clerk
- Watcher
- Technician
- Auditor
- Precinct Appliance
- Tablet
- Printer
- QR Scanner
- Backup Appliance

Resolution actors and role gaps:

| Resolution Actor | Current Match | Gap |
|---|---|---|
| Electoral Board | Election Officer | EB must be a collective body with Chairperson, Poll Clerk, Third Member, majority decisions, and collective signatures. |
| EB Chairperson | Election Officer | Needs separate permissions and ceremony ownership. |
| Poll Clerk | Poll Clerk | Needs minutes, EDCVL, challenge/protest, and manual tally responsibilities. |
| Third Member / EB Member | Missing | Add for voter flow, indelible ink, receipt handling, and multi-role authentication. |
| DESO | Missing | Add voting center supervisory actor outside precinct appliance. |
| DESO Technical Support Staff | Technician | Needs formal escalation/reporting responsibilities. |
| EB Support Staff | Missing | Add non-voting support actor. |
| PPP/S-PPP Support Staff and Aides | Missing | Required for priority polling flows. |
| SEB-PDL and PDL Support Staff | Missing | Required for PDL voting flows. |
| IP-SVR Support Staff | Missing | Required for IP special voting flows. |
| Election Officer (EO) | National/local authority | Current "Election Officer" may conflict with COMELEC EO; clarify terminology. |
| NTSC | Missing | Add technical escalation endpoint. |
| City/Municipal Treasurer | Missing | Required for supplies, ballot box custody, and turnover. |
| Logistics provider | Missing | Required for ACM/equipment turnover. |
| Watchers / citizens' arms / party representatives | Watcher | Add accreditation, signature, protest, documentation, and ER copy recipient roles. |

Recommended actor changes:

- Replace generic **Election Officer** with **Electoral Board** in legal workflows.
- Reserve **Election Officer (EO)** for the COMELEC local official when that actor appears.
- Add role-based attestation for EB Chairperson, Poll Clerk, and EB Member.
- Add special-polling support actors as extension actors, not primary UI operators unless the system directly serves those flows.

## 7. Artifact Analysis

Current artifacts:

- Election Package
- Printed Ballot
- Certification Report
- Election Return
- Journal
- Reports
- Audit Log
- Initialization Report is implied only by examples, not fully modeled

COMELEC-required or implied artifacts:

| Artifact | Current Status | Recommendation |
|---|---|---|
| Election Package | Present | Keep; map to legal configuration material, not a COMELEC form. |
| Official Ballot | Present but redesigned | Define legal relationship among printed AES ballot, ballot authentication, and paper source of truth. |
| VVPAT / voter receipt | Missing or merged | Decide if AES printed ballot is VVPAT-equivalent or if a separate receipt exists. |
| Ballot box | Present | Add custody, contents, seal, and turnover model. |
| VVPAT receptacle | Missing | Add if VVPAT remains separate. |
| Diagnostic Report | Missing | Add as first-class report. |
| Initialization Report | Missing | Add as first-class report. |
| Election Return | Present | Expand with copies, recipients, signatures, thumbmarks, posting, envelopes. |
| Manual ER | Missing | Add for FTS/manual verification. |
| VVPAT Audit Report | Missing | Add for FTS and Election Day. |
| Transmission Report | Missing | Add. |
| Audit Log Report | Journal exists | Add printable official audit log report. |
| Precinct Statistical Report | Missing | Add. |
| Zero-Out Report | Missing | Add. |
| Certification Report | Present | Keep as AES summary, not legal substitute for FTS reports. |
| Minutes | Missing | Add separate official artifact linked to journal. |
| Certificate of Receipt | Missing | Add under forms registry. |
| Protest/challenge certificates and oaths | Missing | Add incident forms. |
| Envelopes | Missing | Add Official Container model. |
| Paper seals/security seals | Missing | Add seal model with serial number, signatures, status. |
| Main storage device envelope | Missing | Add evidence media custody. |
| Half-torn unused ballots | Missing | Add unused-ballot disposition. |
| Rejected ballot envelope | Missing | Add rejected-ballot container and reason categories. |
| Waiver/authorization forms | Missing | Add special polling support forms. |
| Turnout forms | Missing | Add special polling turnout artifacts. |

Artifacts that should become first-class concepts:

- Official Report
- Official Form
- Official Container / Envelope
- Seal
- Minutes
- Custody Transfer
- Public Posting
- Artifact Recipient
- Signature / Thumbmark / Attestation
- Evidence Media

## 8. Forms Analysis

The Resolution defines many forms and annexes. The architecture should not reproduce every form as a screen, but it should classify and support them.

Recommended classification:

| Class | Examples | Architectural Fit |
|---|---|---|
| Operational forms | Minutes, Written Order, appointment/oath forms | Ceremony record and incident management |
| Receipt/custody forms | Certificate of Receipt, supply checklists | Supply Verification and Turnover |
| Election forms | EDCVL, PCVL, official ballots, ERs | External election artifacts and generated outputs |
| Reports | Diagnostic, Initialization, ER, Transmission, VVPAT Audit, Audit Log, Statistical, Zero-Out | Official Report domain |
| Envelopes/containers | A17-FTS, rejected ballot envelopes, storage-device envelopes | Official Container model |
| Seals | paper seals, plastic security seals, serially numbered seals | Seal model and custody chain |
| Special polling forms | Waiver/Authorization, PPP/PDL/IP turnout forms | Special Polling module |
| Protest/challenge forms | Certificate of Challenge/Protest, oath forms | Incident and EB decision module |

Recommendation:

- Build a configurable **Official Forms Catalog** with metadata: legal name, code, ceremony, required contents, copy count, recipient, container, signatures, and retention rule.
- Generate or record only the forms the AES is responsible for. Acknowledge externally supplied forms as inputs.

## 9. Functional Specification Impact

Requirements to add:

- The system shall support COMELEC Final Testing and Sealing as a legal certification ceremony.
- The system shall generate Diagnostic, Initialization, FTS ER, Manual ER, VVPAT Audit, Zero-Out, Transmission, Audit Log, Statistical, and Election Return reports where applicable.
- The system shall support EB role-based authentication and attestation for Chairperson, Poll Clerk, and EB Member.
- The system shall support watcher-visible ceremony states, watcher protests, and watcher signatures where legally required.
- The system shall maintain official Minutes separate from the internal append-only journal.
- The system shall model official forms, envelopes, seals, copy recipients, posting, custody, and turnover.
- The system shall support rejected ballot reason categories and replacement rules.
- The system shall include transmission and retransmission workflows, even if network operation is deferred or optional.
- The system shall support special polling intake for PPP/S-PPP, PDL, and IP ballots at least as custody/checklist workflows.
- The system shall support final backup and evidence media custody.

Requirements to modify:

- Section 9 Friday Certification should become Final Testing and Sealing or explicitly state that Friday Certification implements FTS.
- Section 21 Election Return should include signing, thumbmarks/signatures, posting, copy distribution, and recipient/container tracking.
- Section 24 Activity Journal should distinguish system journal from official Minutes.
- Section 25 Diagnostics should include printable Diagnostic Report and component-level pass/fail/skip evidence.
- Section 28 Offline Operation should clarify that voting/counting must continue if communication fails, but transmission remains a required lifecycle ceremony with failure/retry evidence.
- Section 33 Audit should include VVPAT audit, audit log report, statistical report, and custody review.

Requirements to remove:

- None. The current functional principles are compatible with the Resolution. The issue is under-specification, not contradiction.

## 10. Compass Impact

Recommended new domains:

- **Legal Procedure and Forms**: legal procedure catalog, official forms, minutes, copy distribution.
- **Custody and Sealing**: envelopes, seals, ballot box, evidence media, turnover.
- **Transmission**: transmission, retransmission, status reports, destination registry.
- **Special Polling**: PPP/S-PPP, PDL, IP voting intake and custody.
- **Public Observation**: watcher presence, public display, protest, signatures.

Recommended domain changes:

- Rename **Certification** to **Certification and FTS**.
- Expand **Election Return** into **Election Returns and Transmission** only if Transmission is not made its own domain.
- Expand **Audit** to include VVPAT audit and legal custody audit, not only technical recount.

Recommended wave changes:

- Move FTS legal reconciliation into Wave 3 before general voting implementation continues.
- Add official forms, minutes, and custody primitives before Wave 7 Election Return.
- Add Transmission before Close Precinct can be considered legally complete.
- Add Special Polling after core voting but before any election-readiness milestone.

Recommended implementation order adjustment:

1. Lifecycle/legal procedure model
2. Official artifact/report/form model
3. FTS ceremony
4. Open Polls / Initialization Report
5. Voting and printing
6. Close Polls / Election Return
7. Transmission
8. VVPAT audit / reports / final backup
9. Disposition and turnover
10. Special polling extensions

## 11. Traceability Impact

New traceability entries:

- FTS notice posted and actors convened
- Diagnostic Report generated and printed
- Initialization Report generated, signed, and stored
- FTS test ballots completed
- FTS ER generated
- Manual ER completed
- VVPAT Audit Report generated
- FTS re-zero completed and Zero-Out Report printed
- Machine sealed and custody recorded
- Election Day setup shows sealed ACM, empty ballot box, sealed ballot package
- EB roles authenticated
- Rejected ballot categories handled
- Special polling ballots received before close
- ER copies printed, signed, posted, distributed, and sealed
- Transmission and retransmission reports generated
- Audit Log Report and Statistical Report generated
- Final backup completed
- Ballot box, ACM, storage device, and documents turned over

New certification tests:

- FTS happy path with diagnostic, initialization, test ballots, ER, manual verification, VVPAT audit, re-zero, sealing.
- FTS discrepancy path with re-appreciation/escalation.
- FTS no-transmission guardrail.
- Opening polls zero-report validation.
- Election Day close with transmission success.
- Election Day close with partial transmission failure and retransmission.
- ER copy distribution and envelope/seal requirements.
- Custody turnover completeness.

New Scenario Runner scenarios:

- `fts_full_legal_flow`
- `fts_manual_verification_discrepancy`
- `open_polls_initialization_report`
- `rejected_ballot_with_replacement`
- `rejected_ballot_without_replacement`
- `special_polling_ppp_intake`
- `special_polling_pdl_intake`
- `election_return_transmission_success`
- `election_return_transmission_retry`
- `vvpat_audit_after_close`
- `final_backup_and_turnover`

New UAT cases:

- EB Chairperson conducts FTS in front of watchers.
- Poll Clerk records protest/challenge in Minutes.
- Watcher signs ER/seal or records refusal/unavailability.
- Technician resolves diagnostic failure with NTSC escalation.
- EB completes close precinct with all envelopes, reports, and custody transfers accounted for.

## 12. UI / UX Impact

The ceremony-driven UX remains correct. Legal compliance requires more ceremonies and more evidence screens, but not a return to a complex administration dashboard.

Additional ceremonies:

- Supply Verification
- Final Testing and Sealing
- Initialization Report
- Transmission
- VVPAT Audit
- Final Backup
- Disposition and Turnover
- Special Polling Intake

Additional operator screens:

- EB role registration/authentication
- public explanation/checklist screen
- diagnostics summary and printed report confirmation
- initialization report preview/print/sign confirmation
- manual verification comparison screen
- seal/envelope/custody checklist
- ER copy distribution checklist
- transmission status and retransmission screen
- final backup confirmation

Additional dialogs:

- discrepancy found
- rejected ballot category
- replacement allowed/not allowed
- watcher protest recorded
- special polling envelope discrepancy
- transmission destination failed
- seal broken/missing

Additional reports:

- Diagnostic Report
- Initialization Report
- Manual ER
- VVPAT Audit Report
- Transmission Report
- Audit Log Report
- Statistical Report
- Zero-Out Report
- Custody/Turnover Checklist

UX principle:

Each screen should still ask: **What legally required action must happen next?** The system should not expose a menu of features. It should expose the next required ceremony step, the actor responsible, the public/watcher visibility requirement, the artifact produced, and the evidence that completion was valid.

## 13. Recommendations

### Must Adopt

- Treat Resolution No. 11076 as a traceable legal source for lifecycle, actors, artifacts, reports, and custody.
- Evolve Friday Certification into COMELEC Final Testing and Sealing.
- Add official reports beyond Certification Report and Election Return.
- Add EB role model and role-specific attestation.
- Add Minutes as a legal record separate from the activity journal.
- Add forms/envelopes/seals/custody as first-class architecture.
- Add transmission/retransmission lifecycle.
- Add VVPAT audit or formally define the AES printed ballot as the legally accepted VVPAT-equivalent artifact.
- Add rejected ballot categories and replacement rules.

### Should Adopt

- Add Special Polling as an extension domain covering PPP/S-PPP, PDL, and IP ballot custody.
- Add watcher protest/signature workflows.
- Add public display/announcement checkpoints.
- Add Official Forms Catalog with metadata instead of hardcoding every form.
- Add Scenario Runner legal profiles for FTS and Election Day.
- Add custody checklists for ERs, storage devices, ballot boxes, and official containers.

### Nice To Have

- Configurable copy-recipient registry for ERs and reports.
- Visual ceremony timeline showing legal artifact completion.
- Public monitor mode for counting, ER generation, and transmission status.
- Guided printable packet index for election officers.

### Deferred

- Full reproduction of every COMELEC form as a native generated form.
- Full voting-center crowd-management module.
- Full VAD workflow.
- Full logistics-provider management.
- Automated canvassing beyond precinct transmission/export.

## 14. Verdict

The architecture is **fundamentally sound, but it requires legal adjustment before implementation continues deeply into election workflows**.

Its core ideas are strong:

- ceremony-driven operation
- paper as authoritative evidence
- deterministic configuration
- printable artifacts
- append-only journals
- certification before use
- manual recount and auditability
- hardware replacement

The gap is not philosophical. The gap is legal specificity.

COMELEC Resolution No. 11076 requires the system to model the election as a chain of legally witnessed, printed, signed, sealed, transmitted, audited, and turned-over acts. The current architecture models the main election flow, but it does not yet fully model the legal evidence system around that flow.

Implementation should continue only after the architecture absorbs FTS, actor roles, official reports, minutes, forms, seals, transmission, special polling intake, and custody as first-class concepts. Once those adjustments are adopted, the Alternative Election System can remain simple for operators while becoming much more faithful to the governing legal procedure.
