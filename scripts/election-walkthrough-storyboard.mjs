import { createHash } from 'node:crypto';
import { readFile, stat, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { chromium } from 'playwright';

const checkpointDefinitions = {
    '01-home.png': {
        ceremony: 'Orientation',
        title: 'Appliance lifecycle home',
        operator:
            'The Electoral Board begins at the ceremony home and confirms the precinct, current lifecycle stage, current ceremony, next required action, and recent journal entries.',
        control:
            'The appliance presents a single ordered path instead of a general administration dashboard. No election state changes at this checkpoint.',
        verify: 'Confirm that the precinct identity and next action are unambiguous and that the journal summary is visible before setup begins.',
        evidence: ['run-summary.txt', '13-journal/activity.jsonl'],
    },
    '02-precinct-setup.png': {
        ceremony: 'Precinct Package and Configuration',
        title: 'Precinct package ready for activation',
        operator:
            'The Board opens the setup ceremony and reviews the configured POP workbook, CLC candidate source, clustered precinct identifier, and import readiness.',
        control:
            'Activation remains a deliberate ceremony action. Source files are not treated as active election configuration until import and validation complete.',
        verify: 'Confirm that the displayed source names and precinct identifier correspond to the intended polling place before activation.',
        evidence: ['01-precinct-package-and-configuration/precinct-setup.json'],
    },
    '03-package-activated.png': {
        ceremony: 'Precinct Package and Configuration',
        title: 'POP and CLC package activated',
        operator:
            'The Board imports and activates the configured precinct package for clustered precinct 39010001.',
        control:
            'The appliance deterministically maps the POP precinct record and CLC contests into a local ballot definition, then records source and package hashes.',
        verify: 'Check the precinct location, six contests, candidate count, mapping profile, and source hashes against the activation artifact.',
        evidence: [
            '01-precinct-package-and-configuration/configured-precinct-activation.json',
            '01-precinct-package-and-configuration/active-precinct.json',
            '01-precinct-package-and-configuration/candidate-previews/active-ballot-definition.json',
        ],
    },
    '04-dual-control-setup.png': {
        ceremony: 'Precinct Package and Configuration',
        title: 'Setup recorded under dual control',
        operator:
            'The Chairperson and Poll Clerk separately validate their local officer credentials and jointly acknowledge the configured precinct.',
        control:
            'The appliance requires two distinct authorized roles before setup can be recorded and journals the resulting dual-control checkpoint.',
        verify: 'Confirm both roles are represented and the setup record identifies the same activated precinct package.',
        evidence: ['13-journal/activity.jsonl'],
    },
    '05-opening-readiness.png': {
        ceremony: 'Precinct Package and Configuration',
        title: 'Electoral Board and supply baselines',
        operator:
            'The Board records its role roster and verifies the expected election supplies before final testing.',
        control:
            'The appliance prevents the lifecycle from silently skipping the personnel and physical-supply baselines.',
        verify: 'Review the named roles, completion status, and supply quantities. Physical presence remains a Board observation, not a device fact.',
        evidence: [
            '01-precinct-package-and-configuration/electoral-board-baseline.json',
            '00-start-here/runtime/supply-verification-baseline.json',
        ],
    },
    '06-initialization-report.png': {
        ceremony: 'Final Testing and Sealing',
        title: 'Device readiness and initialization',
        operator:
            'The Board runs the device readiness check and generates the initialization report before certification ballots are processed.',
        control:
            'Configured storage, printer, scanner, source, and runtime checks are captured as a reproducible diagnostic artifact.',
        verify: 'Confirm all required checks passed. A passing software check does not replace physical inspection of cables, seals, paper, or peripherals.',
        evidence: ['03-opening-of-polls/initialization-report.json'],
    },
    '07-friday-certification.png': {
        ceremony: 'Final Testing and Sealing',
        title: 'Known-ballot certification passed',
        operator:
            'The Board runs the Friday certification set using ballots with predetermined expected selections and totals.',
        control:
            'The appliance compares observed results with the embedded expected result and reports a deterministic pass or discrepancy.',
        verify: 'Inspect expected versus observed totals, package hash, and certification result. The screenshot is a view; the JSON report is the substantive record.',
        evidence: [
            '02-final-testing-and-sealing/friday-certification-report.json',
        ],
    },
    '08-manual-verification.png': {
        ceremony: 'Final Testing and Sealing',
        title: 'Manual verification matched',
        operator:
            'The Board records its manual comparison of the certification paper ballots with the computed certification result.',
        control:
            'The appliance requires the human comparison to be recorded separately from the automated known-result check.',
        verify: 'Confirm the manual verification references the certification run and records a matched result.',
        evidence: [
            '02-final-testing-and-sealing/manual-verification-report.json',
        ],
    },
    '09-discrepancy-analysis.png': {
        ceremony: 'Final Testing and Sealing',
        title: 'Discrepancy analysis completed',
        operator:
            'The Board opens the discrepancy review and confirms whether any certification variance requires explanation.',
        control:
            'The appliance produces an explicit analysis artifact even when no discrepancy exists, avoiding an evidentiary gap.',
        verify: 'Confirm the report states no discrepancy and ties back to the same expected and observed certification data.',
        evidence: ['02-final-testing-and-sealing/fts-discrepancy-report.json'],
    },
    '10-zero-out.png': {
        ceremony: 'Final Testing and Sealing',
        title: 'Certification test data zeroed out',
        operator:
            'After certification succeeds, the Board performs the required zero-out before the appliance can be sealed for opening.',
        control:
            'The appliance removes certification ballot state from the operational count while preserving certification evidence in its own ceremony folder.',
        verify: 'Confirm operational counts are zero and certification evidence remains available and hash-verifiable.',
        evidence: ['02-final-testing-and-sealing/zero-out-report.json'],
    },
    '11-certification-signature.png': {
        ceremony: 'Final Testing and Sealing',
        title: 'Certification officer signature captured',
        operator:
            'An authorized simulation officer validates a local PIN and signs the certification checkpoint.',
        control:
            'The appliance stores the attestation and signature image as separate evidence linked to this lifecycle stage.',
        verify: 'Inspect the officer code, role, attestation hash, signature hash, and PNG. This simulation credential is not external identity proofing.',
        evidence: [
            '03-opening-of-polls/attestations',
            '03-opening-of-polls/signatures',
        ],
    },
    '12-certification-sealed.png': {
        ceremony: 'Final Testing and Sealing',
        title: 'Final testing and sealing recorded',
        operator:
            'The Board records that certification is complete and that the appliance and election materials are sealed for the opening ceremony.',
        control:
            'The lifecycle cannot advance to opening without passed certification, manual verification, zero-out, attestation, and sealing evidence.',
        verify: 'Confirm the sealing record references the completed certification evidence and the current precinct package.',
        evidence: ['02-final-testing-and-sealing/sealing-report.json'],
    },
    '13-polls-initialized.png': {
        ceremony: 'Opening of Polls',
        title: 'Opening ceremony initialized',
        operator:
            'The authorized officer starts the opening ceremony but has not yet begun active voting.',
        control:
            'Opening is separated into initialization, signed acknowledgement, and active-voting commencement.',
        verify: 'Confirm the page shows the intermediate opening state and still requires the signed checkpoint and Begin voting action.',
        evidence: ['13-journal/activity.jsonl'],
    },
    '14-opening-signature.png': {
        ceremony: 'Opening of Polls',
        title: 'Opening officer signature captured',
        operator:
            'An authorized simulation officer signs the opening checkpoint after inspecting the zeroed and sealed state.',
        control:
            'The signature artifact and attestation are journaled before active voting is enabled.',
        verify: 'Inspect the signature image and hash linkage. Confirm it is associated with opening, not certification.',
        evidence: [
            '03-opening-of-polls/attestations',
            '03-opening-of-polls/signatures',
        ],
    },
    '15-voting-open.png': {
        ceremony: 'Opening of Polls',
        title: 'Polls opened for active voting',
        operator:
            'The officer performs the second opening action and makes the voter ballot ceremony available.',
        control:
            'The appliance records the transition to active voting and exposes only actions permitted in that stage.',
        verify: 'Confirm the lifecycle stage is voting and that closing/counting actions remain unavailable.',
        evidence: ['13-journal/activity.jsonl'],
    },
    '16-spoiled-ballot-finalized.png': {
        ceremony: 'Voting and Ballot Printing',
        title: 'Demonstration ballot finalized for spoilage',
        operator:
            'A complete voter selection is finalized. This first ballot is intentionally used to demonstrate the spoilage procedure.',
        control:
            'Finalization locks selections and produces a deterministic ballot payload; it does not yet count the ballot.',
        verify: 'Confirm that finalization and printing are separate and that no count is added at this point.',
        evidence: ['04-voting/ballots'],
    },
    '17-spoiled-ballot-printed.png': {
        ceremony: 'Voting and Ballot Printing',
        title: 'Paper ballot artifact printed',
        operator:
            'The operator prints the finalized demonstration ballot and observes its paper stock serial and QR payload.',
        control:
            'Printing occurs through the configured printer adapter and produces a file/PDF artifact and print-job journal entry.',
        verify: 'Inspect the PDF, paper stock serial, print-job record, payload hash, and corresponding physical paper during a field rehearsal.',
        evidence: [
            '04-voting/ballots',
            '04-voting/print-jobs',
            '04-voting/paper-ballot-ledger',
        ],
    },
    '18-ballot-spoiled.png': {
        ceremony: 'Voting and Ballot Printing',
        title: 'Printed ballot marked spoiled',
        operator:
            'The operator marks the printed demonstration ballot spoiled and keeps it segregated from ballots eligible for the ballot box.',
        control:
            'The appliance preserves the original ballot and print evidence while changing its eligibility status and appending a spoilage event.',
        verify: 'Confirm the spoiled identifier and serial match the printed artifact and that the replacement workflow does not erase the first ballot.',
        evidence: ['04-voting/spoiled', '04-voting/paper-ballot-ledger'],
    },
    '21-voting-and-printing-complete.png': {
        ceremony: 'Voting and Ballot Printing',
        title: 'Voting and printing segment complete',
        operator:
            'The Board reviews the number of valid printed ballots ready for counting and the deliberately spoiled ballot.',
        control:
            'The appliance retains separate valid and spoiled states and does not infer a vote from printing alone.',
        verify: 'Compare the displayed valid/spoiled quantities with the paper ledger and physically segregated paper.',
        evidence: [
            '04-voting/paper-ballot-ledger',
            '04-voting/ballots',
            '04-voting/spoiled',
        ],
    },
    '22-polls-closed.png': {
        ceremony: 'Closing of Polls',
        title: 'Polls closed; counting enabled',
        operator: 'The Board closes voting and moves to the counting ceremony.',
        control:
            'The appliance blocks further voter ballot finalization and records the closing transition before scanner input is accepted.',
        verify: 'Confirm the close-polls record precedes all accepted counting files in the journal sequence.',
        evidence: [
            '05-closing-of-polls/close-polls-legal-evidence.json',
            '13-journal/activity.jsonl',
        ],
    },
    '24-spoiled-ballot-rejected.png': {
        ceremony: 'Closing, Counting, and Tally',
        title: 'Spoiled ballot payload rejected',
        operator:
            'The Board deliberately submits the spoiled ballot payload to prove that it cannot enter the accepted count.',
        control:
            'The scanner flow checks ballot status and appends a rejected record without modifying the tally.',
        verify: 'Confirm the reason is Ballot is spoiled, the rejected hash matches the spoiled payload, and accepted totals remain unchanged.',
        evidence: ['06-counting-and-tally/rejected'],
    },
    '25-spoiled-ballot-adjudicated.png': {
        ceremony: 'Closing, Counting, and Tally',
        title: 'Rejected spoilage adjudicated',
        operator:
            'The officer records that the spoiled paper ballot remained separated in the spoil envelope outside the ballot box.',
        control:
            'The appliance requires a disposition, reason, and authorized officer PIN, preserving adjudication separately from the immutable rejection record.',
        verify: 'Inspect disposition spoiled-ballot-separated, reason text, officer identity, and linkage to the rejected scan.',
        evidence: ['06-counting-and-tally/adjudications'],
    },
    '26-physical-ballots-reconciled.png': {
        ceremony: 'Closing, Counting, and Tally',
        title: 'Physical ballot control reconciled',
        operator:
            'The Board enters the physical number of ballots found in the ballot box and compares it with accepted digital payload records.',
        control:
            'The appliance reports whether physical and accepted counts reconcile; it does not manufacture the physical observation.',
        verify: 'Confirm the physical count, accepted count, variance, officer record, and paper accounting are all explicit.',
        evidence: [
            '06-counting-and-tally/physical-ballot-control.json',
            '06-counting-and-tally/counting-legal-evidence.json',
        ],
    },
    '27-counting-complete.png': {
        ceremony: 'Closing, Counting, and Tally',
        title: 'Counting and tally completed',
        operator:
            'After all accepted ballots and adjudications reconcile, the Board completes counting and produces the tally.',
        control:
            'The appliance aggregates only append-only accepted ballot files and generates machine-readable and printable tally artifacts.',
        verify: 'Recompute the tally from accepted files and compare the JSON and PDF. Rejected and spoiled records must not contribute votes.',
        evidence: [
            '06-counting-and-tally/accepted',
            '06-counting-and-tally/tally.json',
            '06-counting-and-tally/tally-sheet.pdf',
        ],
    },
    '28-election-return-generated.png': {
        ceremony: 'Election Return',
        title: 'Election Return generated',
        operator:
            'The Board generates the precinct Election Return from the completed tally.',
        control:
            'The appliance verifies tally linkage and produces JSON, text, and PDF return artifacts without altering the accepted ballot evidence.',
        verify: 'Compare every contest total in the Election Return with tally.json and the accepted ballot files; confirm the return hash and precinct identity.',
        evidence: [
            '07-election-return/39010001-return.json',
            '07-election-return/39010001-return.pdf',
        ],
    },
    '29-return-copies-and-posting.png': {
        ceremony: 'Election Return',
        title: 'Return copies and posting prepared',
        operator:
            'The Board records preparation of the prescribed Election Return copies and posting copy.',
        control:
            'The appliance creates a distribution record linked to the generated return rather than treating file creation as proof of physical posting.',
        verify: 'Confirm the distribution artifact identifies each intended copy. Physical printing, posting, and receipt remain observable human acts.',
        evidence: ['07-election-return/39010001-copy-distribution.json'],
    },
    '30-election-return-approved.png': {
        ceremony: 'Election Return',
        title: 'Election Return approved under dual control',
        operator:
            'The Chairperson and Poll Clerk separately validate their local credentials and jointly approve the generated return.',
        control:
            'The appliance requires distinct authorized roles and binds approval to the specific Election Return hash.',
        verify: 'Confirm both roles, return hash, approval time, and lifecycle state. This simulation PIN flow is not a statutory digital signature.',
        evidence: [
            '07-election-return/election-return-approval.json',
            '07-election-return/election-return-legal-evidence.json',
        ],
    },
    '31-official-handoff-opened.png': {
        ceremony: 'Official Handoff and Custody',
        title: 'Official handoff ceremony opened',
        operator:
            'After return approval, the Board enters the handoff and custody ceremony.',
        control:
            'The appliance separates generation/approval of results from delivery, receipt, backup, and custody evidence.',
        verify: 'Confirm the return is approved before any delivery package is prepared.',
        evidence: ['08-transmission-or-official-handoff'],
    },
    '32-transmission-report-prepared.png': {
        ceremony: 'Official Handoff and Custody',
        title: 'Offline handoff report prepared',
        operator:
            'The Board prepares the transmission-or-handoff report for the approved return.',
        control:
            'In this simulation-first version, the report records an offline/deferred handoff; it does not claim successful network transmission.',
        verify: 'Inspect status, transmission identifier, return hash, and recorded delivery mode. Do not construe preparation as receipt by a canvassing system.',
        evidence: [
            '08-transmission-or-official-handoff/transmission-report.json',
        ],
    },
    '33-delivery-package-prepared.png': {
        ceremony: 'Official Handoff and Custody',
        title: 'Delivery package prepared',
        operator:
            'The Board prepares the evidence package intended for authorized physical or removable-media delivery.',
        control:
            'The appliance enumerates package contents and records a package hash for later comparison.',
        verify: 'Confirm that the package references the approved return and expected evidence, and retain the displayed package hash for receipt comparison.',
        evidence: ['08-transmission-or-official-handoff/delivery-package.json'],
    },
    '34-handoff-officer-verified.png': {
        ceremony: 'Official Handoff and Custody',
        title: 'Releasing officer verified the package',
        operator:
            'The Chairperson verifies the prepared handoff package and records an explanatory note.',
        control:
            'The appliance validates the local officer credential and appends the releasing-side verification.',
        verify: 'Inspect the officer role, package hash, verification note, and event sequence.',
        evidence: [
            '08-transmission-or-official-handoff/manual-handoff-officer-verification.json',
        ],
    },
    '35-handoff-recipient-verified.png': {
        ceremony: 'Official Handoff and Custody',
        title: 'Receiving officer acknowledged the package',
        operator:
            'The designated City Board of Canvassers receiving officer records identity, role, and acknowledgement.',
        control:
            'The receiving-side record is distinct from the releasing officer verification and remains linked to the same package.',
        verify: 'Confirm recipient name/role, acknowledgement text, package hash, and chronology. Field identity proofing is outside this simulation.',
        evidence: [
            '08-transmission-or-official-handoff/manual-handoff-recipient-verification.json',
        ],
    },
    '36-delivery-receipt-generated.png': {
        ceremony: 'Official Handoff and Custody',
        title: 'Delivery receipt generated',
        operator:
            'After both sides verify the package, the Board generates the handoff receipt.',
        control:
            'The appliance binds the receipt to the package and verification records and produces a printable artifact.',
        verify: 'Compare receipt identifier, package hash, releasing officer, recipient, and PDF/text rendering.',
        evidence: [
            '08-transmission-or-official-handoff/delivery-receipt.json',
            '08-transmission-or-official-handoff/delivery-receipt.pdf',
        ],
    },
    '37-final-backup-recorded.png': {
        ceremony: 'Final Backup',
        title: 'Final backup recorded',
        operator:
            'The Board records the final backup after handoff evidence is complete.',
        control:
            'The appliance records backup media/readiness evidence and hashes without treating the working database as the sole record.',
        verify: 'Inspect backup identifier, covered artifacts, hashes, and any configured removable-media readiness result.',
        evidence: ['09-final-backup/final-backup-report.pdf'],
    },
    '38-custody-turnover-recorded.png': {
        ceremony: 'Custody Turnover',
        title: 'Custody turnover recorded',
        operator:
            'The Board records transfer of custody for the device and evidence after delivery and backup.',
        control:
            'The appliance creates a distinct custody record rather than hiding custody inside the transmission report.',
        verify: 'Confirm releasing/receiving roles, custody identifier, included evidence, and chronological position after backup.',
        evidence: ['10-custody-turnover/custody-turnover-report.pdf'],
    },
    '39-precinct-closed.png': {
        ceremony: 'Close Precinct',
        title: 'Precinct lifecycle closed',
        operator:
            'The Board closes the precinct only after return approval, handoff, receipt, backup, and custody turnover are recorded.',
        control:
            'The appliance blocks ordinary election actions after closure and advances only to audit and reconciliation.',
        verify: 'Confirm the close record references all required predecessor ceremonies and that the journal has no later voting/counting event.',
        evidence: ['13-journal/activity.jsonl'],
    },
    '40-audit-opened.png': {
        ceremony: 'Audit and Reconciliation',
        title: 'Audit and reconciliation opened',
        operator:
            'The Board enters the final review ceremony after precinct closure.',
        control:
            'The appliance exposes evidence inspection, baseline generation, manifest creation, archive building, and archive verification.',
        verify: 'Confirm the precinct is already closed and that audit actions do not alter ballot selections or tally totals.',
        evidence: ['12-audit-and-reconciliation'],
    },
    '41-evidence-reference-baseline.png': {
        ceremony: 'Audit and Reconciliation',
        title: 'Evidence reference baseline generated',
        operator:
            'The Board records a baseline inventory of the ceremony evidence expected for this run.',
        control:
            'The appliance records paths, presence, and hashes so missing or changed evidence can be detected.',
        verify: 'Review missing/unexpected entries and independently re-hash a sample of high-value artifacts.',
        evidence: [
            '12-audit-and-reconciliation/evidence-reference-baseline.json',
        ],
    },
    '42-official-minutes-baseline.png': {
        ceremony: 'Audit and Reconciliation',
        title: 'Official minutes baseline generated',
        operator:
            'The Board generates a structured baseline of ceremony events suitable for comparison with official minutes.',
        control:
            'The appliance derives the sequence from the append-only journal and lifecycle artifacts.',
        verify: 'Compare major timestamps and acts with handwritten or prescribed minutes. The generated baseline does not replace official minutes.',
        evidence: [
            '12-audit-and-reconciliation/official-minutes-baseline.json',
        ],
    },
    '43-audit-reconciliation-baseline.png': {
        ceremony: 'Audit and Reconciliation',
        title: 'Audit reconciliation baseline generated',
        operator:
            'The Board generates the final cross-check among paper control, accepted/rejected records, tally, Election Return, handoff, and custody.',
        control:
            'The appliance reports explicit comparisons and exceptions instead of relying on a single overall status.',
        verify: 'Inspect each reconciliation assertion and trace any exception to its source artifact.',
        evidence: [
            '12-audit-and-reconciliation/audit-reconciliation-baseline.json',
        ],
    },
    '44-final-evidence-manifest.png': {
        ceremony: 'Audit and Reconciliation',
        title: 'Final evidence manifest generated',
        operator:
            'The Board creates the run-wide manifest before packaging the evidence.',
        control:
            'The appliance enumerates files and SHA-256 hashes while excluding recursively generated archive reports.',
        verify: 'Confirm manifest entry count, run identity, high-value artifact hashes, and absence of unexplained missing files.',
        evidence: ['12-audit-and-reconciliation/evidence-manifest.json'],
    },
    '45-evidence-bundle-archive.png': {
        ceremony: 'Audit and Reconciliation',
        title: 'Downloadable evidence archive built',
        operator:
            'The Board builds an uncompressed TAR bundle for transfer or review where mounted removable media is unavailable.',
        control:
            'The appliance packages the manifest-referenced evidence and reports archive path, entry count, size, and SHA-256 hash.',
        verify: 'Record the archive hash before copying. Building an archive is not proof that its contents survived transfer.',
        evidence: ['12-audit-and-reconciliation/evidence-bundle-archive.json'],
    },
    '46-evidence-bundle-verified.png': {
        ceremony: 'Audit and Reconciliation',
        title: 'Built evidence archive verified',
        operator:
            'The Board runs verification against the built TAR and observes a passed result.',
        control:
            'The appliance re-reads archive entries and compares their SHA-256 values with the embedded evidence manifest.',
        verify: 'Confirm checked-file count is nonzero and mismatches are zero. The coordinator subsequently rebuilds and verifies the final TAR that includes this storyboard.',
        evidence: [
            '12-audit-and-reconciliation/evidence-bundle-archive-verification.json',
            '12-audit-and-reconciliation/browser-recordings/browser-walkthrough-completion.json',
        ],
    },
};

const dynamicDefinitions = [
    {
        pattern: /^19-voter-ballot-(\d+)-finalized\.png$/,
        build: (ballotNumber) => ({
            ceremony: 'Voting and Ballot Printing',
            title: `Valid voter ballot ${ballotNumber} finalized`,
            operator:
                'A voter-facing simulation selects candidates within each contest limit, reviews the ballot, and finalizes the selections.',
            control:
                'The appliance enforces contest limits, locks the finalized selection set, and creates a deterministic QR payload without adding it to the tally.',
            verify: 'Confirm the final ballot contains the intended six Tondo contests, no overvote, and no count or print assertion before the next checkpoint.',
            evidence: ['04-voting/ballots'],
        }),
    },
    {
        pattern: /^20-voter-ballot-(\d+)-printed\.png$/,
        build: (ballotNumber) => ({
            ceremony: 'Voting and Ballot Printing',
            title: `Valid voter ballot ${ballotNumber} printed`,
            operator:
                'The operator prints the finalized ballot onto the simulated paper artifact and confirms its serial.',
            control:
                'The printer adapter writes the ballot artifact and appends print-job and paper-ledger evidence. Printing still does not count the vote.',
            verify: 'Match ballot identifier, paper stock serial, payload hash, print record, and PDF; physically inspect the paper in a field rehearsal.',
            evidence: [
                '04-voting/ballots',
                '04-voting/print-jobs',
                '04-voting/paper-ballot-ledger',
            ],
        }),
    },
    {
        pattern: /^23-valid-ballot-(\d+)-accepted\.png$/,
        build: (ballotNumber) => ({
            ceremony: 'Closing, Counting, and Tally',
            title: `Valid paper ballot ${ballotNumber} accepted`,
            operator:
                'The Board scans the QR payload from a valid printed paper ballot during counting.',
            control:
                'The appliance validates payload integrity, precinct, lifecycle, uniqueness, and ballot status before appending one accepted file.',
            verify: 'Confirm exactly one new accepted JSON file exists, its payload hash matches the paper ballot, and duplicate scanning would not add another vote.',
            evidence: ['06-counting-and-tally/accepted'],
        }),
    },
];

function checkpointDefinition(filename) {
    if (checkpointDefinitions[filename]) {
        return checkpointDefinitions[filename];
    }

    for (const definition of dynamicDefinitions) {
        const match = filename.match(definition.pattern);

        if (match) {
            return definition.build(match[1]);
        }
    }

    return {
        ceremony: 'Recorded Walkthrough',
        title: filename.replace(/^\d+-/, '').replace(/[-.]/g, ' '),
        operator:
            'The recorder captured this checkpoint while performing the visible browser lifecycle.',
        control:
            'The action log records the associated route, page heading, and execution result.',
        verify: 'Inspect the screenshot together with the action log and underlying ceremony artifacts.',
        evidence: [
            '12-audit-and-reconciliation/browser-recordings/action-log.jsonl',
        ],
    };
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function sha256(contents) {
    return createHash('sha256').update(contents).digest('hex');
}

async function readJsonIfPresent(filePath) {
    try {
        return JSON.parse(await readFile(filePath, 'utf8'));
    } catch {
        return null;
    }
}

function evidenceList(paths) {
    return paths
        .map(
            (evidencePath) =>
                `<li><code>${escapeHtml(evidencePath)}</code></li>`,
        )
        .join('');
}

function renderHtml(storyboard) {
    const checkpointPages = storyboard.checkpoints
        .map(
            (checkpoint) => `
        <section class="page checkpoint">
            <header class="checkpoint-header">
                <div>
                    <p class="eyebrow">${escapeHtml(checkpoint.ceremony)}</p>
                    <h2>${escapeHtml(checkpoint.sequence)}. ${escapeHtml(checkpoint.title)}</h2>
                </div>
                <p class="checkpoint-file">${escapeHtml(checkpoint.screenshot.filename)}</p>
            </header>
            <div class="checkpoint-layout">
                <a class="screenshot-frame" href="${escapeHtml(checkpoint.screenshot.filename)}">
                    <img src="${escapeHtml(checkpoint.storyboard_frame.relative_path)}" alt="Recorded browser checkpoint: ${escapeHtml(checkpoint.title)}">
                </a>
                <div class="narrative">
                    <section>
                        <h3>What the Electoral Board did</h3>
                        <p>${escapeHtml(checkpoint.operator_action)}</p>
                    </section>
                    <section>
                        <h3>What the appliance enforced or recorded</h3>
                        <p>${escapeHtml(checkpoint.appliance_control)}</p>
                    </section>
                    <section class="review">
                        <h3>Review point for COMELEC</h3>
                        <p>${escapeHtml(checkpoint.review_point)}</p>
                    </section>
                    <section>
                        <h3>Underlying evidence</h3>
                        <ul>${evidenceList(checkpoint.evidence)}</ul>
                    </section>
                    <p class="hash"><strong>Full-page screenshot SHA-256</strong><br>${escapeHtml(checkpoint.screenshot.sha256)}<br><strong>Storyboard frame SHA-256</strong><br>${escapeHtml(checkpoint.storyboard_frame.sha256)}</p>
                </div>
            </div>
        </section>`,
        )
        .join('');

    const ceremonyRows = storyboard.ceremonies
        .map(
            (ceremony) => `
                <tr>
                    <td>${escapeHtml(ceremony.sequence)}</td>
                    <td>${escapeHtml(ceremony.name)}</td>
                    <td>${escapeHtml(ceremony.checkpoints)}</td>
                    <td>${escapeHtml(ceremony.first_checkpoint)}-${escapeHtml(ceremony.last_checkpoint)}</td>
                </tr>`,
        )
        .join('');

    const statisticRows = Object.entries(storyboard.statistics)
        .map(
            ([label, value]) => `
                <div class="stat">
                    <span>${escapeHtml(label.replaceAll('_', ' '))}</span>
                    <strong>${escapeHtml(value)}</strong>
                </div>`,
        )
        .join('');

    return `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>${escapeHtml(storyboard.title)}</title>
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; color: #17211c; background: #e8ece9; }
        * { box-sizing: border-box; }
        body { margin: 0; }
        .page { width: min(100%, 1280px); min-height: 720px; margin: 24px auto; padding: 46px 54px; background: #fff; box-shadow: 0 8px 30px rgb(23 33 28 / 12%); page-break-after: always; break-after: page; }
        .cover { display: grid; align-content: space-between; border-top: 14px solid #173f35; }
        .agency { font-size: 14px; font-weight: 700; text-transform: uppercase; color: #4b665d; }
        h1 { max-width: 940px; margin: 44px 0 18px; font-size: 50px; line-height: 1.05; letter-spacing: 0; color: #102f27; }
        .subtitle { max-width: 880px; font-size: 23px; line-height: 1.4; color: #45534d; }
        .identity-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1px; margin-top: 40px; background: #cbd5cf; border: 1px solid #cbd5cf; }
        .identity { min-height: 94px; padding: 18px; background: #f7f9f7; }
        .identity span { display: block; margin-bottom: 8px; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #607068; }
        .identity strong { font-size: 16px; line-height: 1.35; }
        .notice { margin-top: 34px; padding: 20px 24px; border-left: 6px solid #c7a128; background: #fff8df; font-size: 15px; line-height: 1.5; }
        .cover-footer { display: flex; justify-content: space-between; gap: 30px; padding-top: 22px; border-top: 1px solid #cbd5cf; color: #55625c; font-size: 12px; }
        .eyebrow { margin: 0 0 8px; color: #607068; font-size: 12px; font-weight: 700; text-transform: uppercase; }
        h2 { margin: 0; font-size: 29px; line-height: 1.15; color: #123a30; }
        h3 { margin: 0 0 7px; font-size: 13px; line-height: 1.3; text-transform: uppercase; color: #244f44; }
        p { margin: 0; }
        .intro { font-size: 17px; line-height: 1.55; color: #45534d; }
        table { width: 100%; margin-top: 28px; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 12px 14px; border-bottom: 1px solid #d7dfda; text-align: left; }
        th { background: #eef3ef; color: #29463e; font-size: 11px; text-transform: uppercase; }
        .reading-guide { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; margin-top: 32px; }
        .reading-guide article { padding: 19px; border: 1px solid #d7dfda; border-top: 5px solid #2a6f5e; }
        .reading-guide p { font-size: 14px; line-height: 1.5; color: #4a5852; }
        .checkpoint { display: flex; flex-direction: column; }
        .checkpoint-header { display: flex; align-items: end; justify-content: space-between; gap: 24px; padding-bottom: 18px; border-bottom: 2px solid #1d5144; }
        .checkpoint-file { flex: 0 0 auto; font-family: monospace; font-size: 11px; color: #66736d; }
        .checkpoint-layout { display: grid; grid-template-columns: minmax(0, 1.65fr) minmax(330px, 1fr); gap: 28px; flex: 1; min-height: 0; padding-top: 24px; }
        .screenshot-frame { display: flex; min-height: 0; padding: 10px; border: 1px solid #bfcac4; background: #edf1ee; overflow: hidden; }
        .screenshot-frame img { width: 100%; height: 100%; min-height: 0; object-fit: contain; object-position: top center; background: #fff; }
        .narrative { display: flex; flex-direction: column; gap: 17px; min-width: 0; }
        .narrative section { padding-bottom: 14px; border-bottom: 1px solid #dfe5e1; }
        .narrative section p, .narrative li { font-size: 13px; line-height: 1.45; color: #34443d; }
        .narrative .review { padding: 15px; border: 1px solid #d8bd5e; background: #fff9e8; }
        .narrative ul { margin: 0; padding-left: 18px; }
        code { overflow-wrap: anywhere; color: #33453e; font-size: 11px; }
        .hash { margin-top: auto; font-family: monospace; font-size: 9px; line-height: 1.45; color: #67736e; overflow-wrap: anywhere; }
        .statistics { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin: 28px 0; }
        .stat { padding: 15px; border: 1px solid #d4ddd8; background: #f7f9f7; }
        .stat span { display: block; margin-bottom: 9px; font-size: 10px; text-transform: uppercase; color: #68746e; }
        .stat strong { font-size: 22px; color: #153d33; }
        .conclusion-list { display: grid; gap: 12px; padding: 0; list-style: none; }
        .conclusion-list li { padding: 14px 18px; border-left: 5px solid #2a6f5e; background: #f2f6f3; font-size: 14px; line-height: 1.45; }
        .precision-note { margin-top: 24px; padding: 18px; border: 1px solid #caa82f; background: #fff9e6; font-size: 14px; line-height: 1.5; }
        @page { size: A4 landscape; margin: 12mm 10mm 13mm; }
        @media print {
            :root { background: #fff; }
            .page { width: auto; height: 179mm; min-height: 179mm; margin: 0; padding: 8mm 10mm; box-shadow: none; overflow: hidden; }
            .cover { border-top-width: 4mm; }
            h1 { margin-top: 10mm; font-size: 34px; }
            .subtitle { font-size: 17px; }
            .identity-grid { margin-top: 8mm; }
            .identity { min-height: 20mm; padding: 4mm; }
            .notice { margin-top: 7mm; padding: 4mm 5mm; font-size: 11px; }
            .checkpoint-layout { grid-template-columns: minmax(0, 1.7fr) minmax(80mm, 1fr); gap: 6mm; padding-top: 5mm; }
            .checkpoint-header { padding-bottom: 4mm; }
            .checkpoint h2 { font-size: 20px; }
            .narrative { gap: 3mm; }
            .narrative section { padding-bottom: 2.5mm; }
            .narrative section p, .narrative li { font-size: 9px; line-height: 1.35; }
            .narrative h3 { margin-bottom: 1mm; font-size: 9px; }
            .narrative .review { padding: 3mm; }
            .hash { font-size: 6.5px; }
            .intro { font-size: 13px; }
            table { margin-top: 5mm; font-size: 10px; }
            th, td { padding: 2.2mm 3mm; }
            .reading-guide { gap: 4mm; margin-top: 6mm; }
            .reading-guide article { padding: 4mm; }
            .reading-guide p { font-size: 10px; }
            .statistics { margin: 6mm 0; }
            .stat { padding: 3mm; }
            .stat strong { font-size: 17px; }
        }
        @media (max-width: 900px) {
            .page { min-height: auto; margin: 0; padding: 28px 20px; }
            .checkpoint-layout, .reading-guide, .identity-grid, .statistics { grid-template-columns: 1fr; }
            .screenshot-frame img { height: auto; }
            h1 { font-size: 38px; }
        }
    </style>
</head>
<body>
    <section class="page cover">
        <div>
            <p class="agency">Alternative Election System - Recorded Rehearsal Evidence</p>
            <h1>${escapeHtml(storyboard.title)}</h1>
            <p class="subtitle">A precise, screenshot-by-screenshot account of the ceremony-driven precinct appliance walkthrough, prepared for independent review without a live presentation.</p>
            <div class="identity-grid">
                <div class="identity"><span>Clustered precinct</span><strong>${escapeHtml(storyboard.precinct.precinct_id)}</strong></div>
                <div class="identity"><span>Polling place</span><strong>${escapeHtml(storyboard.precinct.polling_place)}</strong></div>
                <div class="identity"><span>Location</span><strong>${escapeHtml(storyboard.precinct.location)}</strong></div>
                <div class="identity"><span>Run ID</span><strong>${escapeHtml(storyboard.run_id)}</strong></div>
                <div class="identity"><span>Ballot style</span><strong>${escapeHtml(storyboard.precinct.ballot_style_id)}</strong></div>
                <div class="identity"><span>Recorded checkpoints</span><strong>${escapeHtml(storyboard.checkpoints.length)}</strong></div>
            </div>
            <div class="notice"><strong>Legal and operational framing.</strong> This is a simulation-first rehearsal artifact. Paper ballots remain the legal source of truth. Screenshots demonstrate the operator-visible flow but do not, by themselves, certify legal compliance, physical custody, voter intent, officer identity, or election results. Review the referenced hashed artifacts and physical paper evidence.</div>
        </div>
        <div class="cover-footer">
            <span>Generated ${escapeHtml(storyboard.generated_at)}</span>
            <span>Source package SHA-256 ${escapeHtml(storyboard.precinct.package_hash)}</span>
        </div>
    </section>
    <section class="page">
        <p class="eyebrow">Reviewer orientation</p>
        <h2>How to read this storyboard</h2>
        <p class="intro">The pages follow the exact browser recording sequence. Each checkpoint separates the human act, the appliance control, and the review question. Screenshot hashes permit reviewers to detect substitution, while the listed run-relative paths identify the stronger machine-readable or printable evidence.</p>
        <div class="reading-guide">
            <article><h3>Human observation</h3><p>Physical paper, seals, supplies, posting, custody, and real officer identity remain matters for the Electoral Board and authorized observers.</p></article>
            <article><h3>Appliance record</h3><p>The device guides lifecycle transitions, validates deterministic rules, and writes append-only or reproducible evidence artifacts.</p></article>
            <article><h3>Independent review</h3><p>Use the action log, journal, hashes, tally, Election Return, manifest, final TAR, and verification report to test the claims shown here.</p></article>
        </div>
        <table>
            <thead><tr><th>No.</th><th>Ceremony</th><th>Checkpoints</th><th>Storyboard pages</th></tr></thead>
            <tbody>${ceremonyRows}</tbody>
        </table>
    </section>
    ${checkpointPages}
    <section class="page">
        <p class="eyebrow">Walkthrough conclusion</p>
        <h2>What this recorded rehearsal established</h2>
        <div class="statistics">${statisticRows}</div>
        <ul class="conclusion-list">
            <li>The recorder completed the ceremony sequence from precinct package activation through in-browser archive verification.</li>
            <li>One deliberately spoiled ballot remained excluded; valid ballots were accepted through one-file-per-ballot counting records.</li>
            <li>The tally, Election Return, dual-control approval, handoff, receipt, backup, custody, closure, manifest, and archive verification were all exercised.</li>
            <li>The coordinator builds and verifies the final evidence TAR after this storyboard is written so that the HTML, PDF, JSON, and screenshots are included in the archive.</li>
        </ul>
        <div class="precision-note"><strong>Required final check.</strong> Open <code>12-audit-and-reconciliation/browser-recordings/browser-walkthrough-completion.json</code> and confirm <code>passed: true</code> and <code>post_recording.archive_verified: true</code>. Then compare the final TAR SHA-256 with its verification report. This closing check cannot be asserted inside the storyboard because the final TAR is generated after the storyboard itself.</div>
    </section>
</body>
</html>`;
}

function ceremonySummary(checkpoints) {
    return Array.from(
        checkpoints
            .reduce((ceremonies, checkpoint) => {
                const existing = ceremonies.get(checkpoint.ceremony);

                if (existing) {
                    existing.checkpoints++;
                    existing.last_checkpoint = checkpoint.sequence;
                } else {
                    ceremonies.set(checkpoint.ceremony, {
                        sequence: ceremonies.size + 1,
                        name: checkpoint.ceremony,
                        checkpoints: 1,
                        first_checkpoint: checkpoint.sequence,
                        last_checkpoint: checkpoint.sequence,
                    });
                }

                return ceremonies;
            }, new Map())
            .values(),
    );
}

export async function generateWalkthroughStoryboard({
    artifactDirectory,
    actions,
    statistics,
    scenario,
}) {
    const runPath = path.resolve(artifactDirectory, '../..');
    const activation =
        (await readJsonIfPresent(
            path.join(
                runPath,
                '01-precinct-package-and-configuration',
                'configured-precinct-activation.json',
            ),
        )) ?? {};
    const screenshotActions = actions.filter(
        (action) =>
            action.status === 'passed' &&
            typeof action.details?.screenshot === 'string',
    );
    const checkpoints = [];

    for (const [index, action] of screenshotActions.entries()) {
        const screenshotPath = action.details.screenshot;
        const storyboardFramePath =
            action.details.storyboard_frame ?? screenshotPath;
        const filename = path.basename(screenshotPath);
        const screenshotContents = await readFile(screenshotPath);
        const screenshotStat = await stat(screenshotPath);
        const storyboardFrameContents = await readFile(storyboardFramePath);
        const storyboardFrameStat = await stat(storyboardFramePath);
        const definition = checkpointDefinition(filename);

        checkpoints.push({
            sequence: index + 1,
            action: action.action,
            ceremony: definition.ceremony,
            title: definition.title,
            operator_action: definition.operator,
            appliance_control: definition.control,
            review_point: definition.verify,
            evidence: definition.evidence,
            observed_url: action.details.url ?? null,
            observed_heading: action.details.heading ?? null,
            screenshot: {
                filename,
                bytes: screenshotStat.size,
                sha256: sha256(screenshotContents),
            },
            storyboard_frame: {
                relative_path: path.relative(
                    artifactDirectory,
                    storyboardFramePath,
                ),
                bytes: storyboardFrameStat.size,
                sha256: sha256(storyboardFrameContents),
            },
        });
    }

    const location = activation.location ?? {};
    const storyboard = {
        schema_version: 'browser-walkthrough-storyboard-1',
        title: 'Full Precinct Lifecycle Rehearsal Storyboard',
        scenario,
        run_id: path.basename(runPath),
        generated_at: new Date().toISOString(),
        legal_framing: {
            paper_ballots_are_legal_source_of_truth: true,
            appliance_is_not_election_authority: true,
            rehearsal_not_official_certification: true,
            screenshots_are_supporting_visual_evidence: true,
        },
        precinct: {
            precinct_id: activation.precinct_id ?? 'unknown',
            ballot_style_id: activation.ballot_style_id ?? 'unknown',
            polling_place: location.polling_place ?? 'unknown',
            location: [
                location.barangay,
                location.city_municipality,
                location.province,
            ]
                .filter(Boolean)
                .join(', '),
            district: activation.district ?? 'unknown',
            contest_count: activation.contest_count ?? null,
            candidate_count: activation.candidate_count ?? null,
            package_hash: activation.package_hash ?? 'unavailable',
            pop_source_hash: activation.pop?.source_hash ?? null,
            clc_registry_hash: activation.clc?.registry_hash ?? null,
        },
        statistics: {
            checkpoints: checkpoints.length,
            ballots_finalized: statistics.ballots_finalized,
            ballots_printed: statistics.ballots_printed,
            ballots_spoiled: statistics.ballots_spoiled,
            ballots_accepted: statistics.ballots_accepted,
            scans_rejected: statistics.scans_rejected,
            scans_adjudicated: statistics.scans_adjudicated,
            browser_messages: statistics.browser_messages ?? 0,
        },
        ceremonies: ceremonySummary(checkpoints),
        checkpoints,
        final_verification_pointer:
            '12-audit-and-reconciliation/browser-recordings/browser-walkthrough-completion.json',
    };
    const jsonPath = path.join(
        artifactDirectory,
        'walkthrough-storyboard.json',
    );
    const htmlPath = path.join(
        artifactDirectory,
        'walkthrough-storyboard.html',
    );
    const pdfPath = path.join(artifactDirectory, 'walkthrough-storyboard.pdf');

    await writeFile(jsonPath, `${JSON.stringify(storyboard, null, 2)}\n`);
    await writeFile(htmlPath, renderHtml(storyboard));

    const pdfBrowser = await chromium.launch({ headless: true });

    try {
        const pdfPage = await pdfBrowser.newPage({
            viewport: { width: 1280, height: 720 },
        });
        await pdfPage.goto(pathToFileURL(htmlPath).href, {
            waitUntil: 'load',
        });
        await pdfPage.emulateMedia({ media: 'print' });
        await pdfPage.pdf({
            path: pdfPath,
            format: 'A4',
            landscape: true,
            printBackground: true,
            displayHeaderFooter: true,
            headerTemplate: '<span></span>',
            footerTemplate:
                '<div style="width:100%;font:8px Arial;color:#68746e;padding:0 10mm;display:flex;justify-content:space-between"><span>Alternative Election System - Recorded rehearsal</span><span><span class="pageNumber"></span> / <span class="totalPages"></span></span></div>',
            margin: {
                top: '12mm',
                right: '10mm',
                bottom: '13mm',
                left: '10mm',
            },
        });
    } finally {
        await pdfBrowser.close();
    }

    return [
        ['Walkthrough storyboard HTML', htmlPath],
        ['Walkthrough storyboard PDF', pdfPath],
        ['Walkthrough storyboard data', jsonPath],
    ];
}
