# Election System Using Truth QR Outline

## 1. Working Title
Election System Using Truth QR for Voter Verifiable Paper Ballots Offline Tallying and Public Audit

## 2. Purpose
This election system uses Truth QR as a differentiating verification layer for a precinct based election process. It allows voters to create a voter verifiable paper ballot, allows the precinct device to tally digitally stored or scanned ballot evidence, and allows poll watchers or auditors to independently inspect the records using Truth QR payloads.

The system is designed so that the physical paper ballot remains visible and understandable to the voter, while the Truth QR on the ballot carries compact candidate codes and election context sufficient for later verification and tallying.

## 3. Design Principles
The system is based on the following principles:

- Paper ballots remain the voter visible record.
- The device is a precinct appliance, not the legal authority by itself.
- The system can operate offline.
- The user interface is ceremony driven rather than a generic administrative dashboard.
- Every important event is journaled.
- Hardware is abstracted through adapters.
- Printed artifacts and append only evidence remain primary audit materials.
- Digital ledgers, databases, and displays are convenience layers that must be reproducible from evidence.
- Truth QR makes paper artifacts independently machine readable without depending only on a central database.

## 4. Problem Addressed
Election systems face several competing requirements:

- Voters need a clear and private way to mark selections.
- Election officers need a simple ceremony flow.
- Teachers and precinct staff need fast closeout and reduced manual counting burden.
- Poll watchers need transparency without compromising ballot secrecy.
- Auditors need a way to verify paper ballots after election day.
- The system must still work when internet connectivity is poor or absent.
- Printed returns must be compact, inspectable, and reproducible.

A conventional electronic record alone is difficult to trust. A paper ballot alone can take too long to count manually. A QR that only points to a database record is inadequate because post election verification may occur away from the original device. The proposed system uses Truth QR so that each paper ballot and return can carry its own compact, verifiable election meaning.

## 5. Main Actors
### 5.1 Election Officer
The officer opens the precinct, admits voters, issues voter control numbers, operates or supervises the print station, closes the precinct, generates tally sheets and election returns, and enables publication for watchers.

### 5.2 Voter
The voter receives or generates a voter control number, marks the electronic ballot privately, reviews selections, finalizes the ballot, receives a print PIN, claims the paper ballot at the print station, verifies the paper ballot, and deposits it in the ballot box.

### 5.3 Printing Station Operator
The printing station receives the voter print PIN, prints the voter verifiable paper ballot, and after closeout prints the tally sheet and election returns.

### 5.4 Poll Watcher
The watcher observes the running or post close tally, reviews published ballot artifacts, downloads the tally sheet and election returns, and may inspect Truth QR derived evidence.

### 5.5 Auditor
The auditor performs random manual audit or paper ballot verification by scanning the Truth QR on selected paper ballots and comparing the decoded selections with the paper record and published ledger.

## 6. System Components
### 6.1 Precinct Appliance
A local server or Raspberry Pi class device hosts the precinct application, registries, ballot styles, candidate mappings, journals, print artifacts, tally ledgers, and watcher publication.

### 6.2 Voter Tablets
Tablets display the ballot face, enforce contest selection limits, allow review and correction, and produce a final print PIN or QR for the printing station.

### 6.3 Officer Console
The officer console guides the election officer through setup, opening, voter admission, closeout, tallying, printing, publication, and handoff.

### 6.4 Printing Station
A laptop or appliance connected to a printer accepts print PINs and produces official paper ballots. After precinct closeout, it prints tally sheets and election returns.

### 6.5 Watcher and Auditor Views
Watcher and auditor views display ballot artifacts, live or post close tallies, election returns, logs, and audit tools without exposing voter identity.

### 6.6 Truth QR Registry
The registry maps compact candidate codes to candidate names, positions, parties, ballot numbers, localities, and precinct or ballot style context. The registry is hashed and commissioned before voting.

## 7. Key Election Tokens
### 7.1 Voter Control Number
A short code used to authorize a voter to begin ballot marking. It is issued by the election officer or by a controlled demonstration flow. It is not the ballot payload and should not reveal selections.

### 7.2 Voter Print PIN
A separate short code generated after the voter finalizes selections. It allows the printing station to retrieve and print the completed paper ballot without displaying voter selections to others.

### 7.3 Paper Ballot Serial
A serial printed on the paper ballot and included in the Truth QR payload. It supports artifact tracking, duplicate detection, and audit reference without exposing voter identity.

## 8. Truth QR Ballot Payload
The Truth QR printed on the paper ballot should contain enough information to be interpreted without looking up a voter record by ballot ID. A representative payload includes:

- Protocol version.
- Election identifier.
- Precinct identifier.
- Ballot style identifier.
- Mapping hash.
- Paper ballot serial.
- Document profile identifier.
- Tabulation profile.
- Candidate codes selected by the voter.
- Payload hash.
- Optional signature or issuer authentication data.

The selected candidates are encoded as compact candidate codes, such as CAND00001, CAND00003, and CAND00007, or a more compact equivalent. These codes resolve to candidate names, offices, and localities only when read against the commissioned mapping registry.

## 9. Election Lifecycle
### 9.1 Preparation
The system imports precinct data, candidate lists, ballot styles, officer credentials, document profiles, logos, printer profiles, and Truth QR mappings. It produces a mapping hash and evidence manifest.

### 9.2 Certification
Election officers run a certification ceremony using known test ballots or expected results. The system journals the ceremony and produces certification evidence.

### 9.3 Opening of Polls
The election officer opens the precinct. The system records the officer action, stage transition, time, configuration identity, and device identity.

### 9.4 Voter Admission
The officer physically verifies the voter outside the system, then issues a voter control number. In demonstration mode, the voter may generate this number directly for ease of testing.

### 9.5 Ballot Marking
The voter enters the control number, marks the ballot on a tablet, navigates by position or surname, reviews selections, and confirms the vote.

### 9.6 Print PIN Generation
After confirmation, the tablet generates a voter print PIN. The voter writes the PIN on paper or presents it privately at the printing station.

### 9.7 Paper Ballot Printing
The printing station accepts the print PIN and prints a voter verifiable paper ballot. The ballot shows only the voter selections in human readable form and includes the Truth QR payload.

### 9.8 Ballot Deposit
The voter verifies the printed paper ballot and deposits it in the sealed ballot box. The system may persist the corresponding voter verifiable digital audit trail as sealed VVDAT evidence.

### 9.9 Closeout and Tally
At close of polls, the officer closes the precinct. Depending on the configured profile, the system may tally sealed VVDAT records, scan paper ballot Truth QR payloads, or support both approaches.

### 9.10 Tally Sheet and Election Returns
The system generates tally sheets and separate election returns, including national and local election returns where required. Each return may include its own Truth QR aggregate payload and result hash.

### 9.11 Watcher Publication
The officer enables a watcher publication page that exposes safe artifacts: tally sheets, election returns, logs, ballot artifact viewer, hashes, and download bundles.

### 9.12 Random Manual Audit
Auditors open the ballot box, select or scan sampled paper ballots, decode the Truth QR, compare the decoded selections with the human readable paper ballot, and record verified or discrepant findings.

### 9.13 Handoff
The system prints or displays handoff instructions. Transmission is not required for the core local verification flow, though future profiles may add controlled transmission.

## 10. Tallying Profiles
### 10.1 Device Tabulation with Paper Audit
The default profile can tally sealed VVDAT records at close of polls and generate the tally sheet and election returns immediately. Paper ballots remain available for random manual audit and dispute resolution.

### 10.2 Paper First QR Scan Tally
An alternative profile can tally by scanning each paper ballot Truth QR after closeout. This supports a closer paper led count while avoiding manual reading of every candidate name.

### 10.3 Hybrid Verification Profile
The system may tally from sealed VVDAT records and then verify a sample or full set of paper ballots using Truth QR scans.

## 11. Truth QR Differentiator in the Election System
Truth QR is the differentiator because:

- The ballot QR contains election meaning, not merely a server reference.
- Candidate selections are represented as compact codes tied to a mapping hash.
- A post election scanner can decode the paper ballot even if it is not connected to the original voting device.
- Tally sheets and election returns can carry aggregate Truth QR payloads for independent canvassing or verification.
- Watchers and auditors can inspect decoded payloads, candidate mappings, hashes, and reconstructed documents.
- The same method can support ballot verification, tally verification, return verification, and audit publication.

## 12. Evidence and Artifacts
The system produces and preserves:

- Precinct package and mapping registry.
- Certification reports.
- Officer journal entries.
- Voter admission records without vote selections.
- Sealed ballot or VVDAT records.
- Printable paper ballot artifacts.
- Truth QR payload text and decoded mapping view.
- Counting ledgers.
- Tally sheets.
- National election return.
- Local election return.
- Watcher publication packet.
- Random manual audit report.
- Evidence bundle manifest.

## 13. Security and Privacy
The system should preserve secrecy and integrity through:

- Physical voter identity verification outside the ballot payload.
- Separation of voter control number and voter print PIN.
- No voter identity in the Truth QR ballot payload.
- Offline operation during precinct voting.
- Append only event journals.
- Hashes for mappings, payloads, ledgers, rendered documents, and evidence manifests.
- Optional signatures for election returns and aggregate records.
- Watcher safe publication that excludes voter identity.
- Duplicate detection for scanned ballot or return payloads.

## 14. User Interface Model
The interface is ceremony driven:

- Setup and certification.
- Open precinct.
- Admit voter.
- Ballot marking.
- Print ballot.
- Deposit ballot.
- Close precinct.
- Generate tally.
- Generate election returns.
- Publish watcher packet.
- Conduct audit.
- Handoff.

This avoids exposing election workers to a generic administrative dashboard.

## 15. Print and Document Profiles
The system can support multiple document profiles:

- Full page ballot.
- Thermal printer ballot.
- Tally sheet.
- National election return.
- Local election return.
- Watcher packet.
- Audit report.

Truth QR binds each document to a profile identifier and hash so the generated artifact can be verified against the intended format.

## 16. Possible Patentable Themes
Potential election specific invention themes include:

- An election workflow using separate voter control numbers and voter print PINs while excluding voter identity from the final Truth QR ballot payload.
- A voter verifiable paper ballot containing compact candidate codes, precinct context, mapping hash, paper ballot serial, and document profile data.
- Offline tallying of sealed digital ballot evidence or scanned paper Truth QR payloads using the same commissioned mapping registry.
- Election returns carrying aggregate Truth QR payloads with candidate code totals, result hashes, reconciliation values, and signatures.
- Watcher and auditor interfaces that reconstruct ballot and return documents from Truth QR payloads and local mappings.
- Random manual audit using ballot Truth QR scans to assist tallying while preserving paper ballot inspection.
- A precinct appliance architecture that produces reproducible evidence bundles without requiring live transmission.

## 17. Relationship to Broader Truth QR Portfolio
This election system can be positioned as one application of Truth QR. The broader patent portfolio can include:

- Truth QR as a foundational verification technology.
- Election System using Truth QR.
- Survey System using Truth QR.
- Field Data Extraction System using Truth QR.
- Voucher or entitlement distribution using Truth QR.
- Public sector compliance reporting using Truth QR.

## 18. Short Summary
The Election System using Truth QR is a precinct appliance and public audit framework in which voters create paper ballots, each paper ballot carries a self describing compact Truth QR payload, the precinct can tally digital or scanned evidence, and watchers or auditors can independently verify ballot and return artifacts without trusting a hidden database or requiring internet access.
