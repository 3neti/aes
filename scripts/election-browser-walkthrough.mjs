import { createHash } from 'node:crypto';
import { mkdir, readFile, rename, rm, stat, writeFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { chromium } from 'playwright';
import { renderPrintedArtifacts } from './election-print-artifact-renderer.mjs';
import { generateWalkthroughStoryboard } from './election-walkthrough-storyboard.mjs';

const requiredEnvironment = [
    'ELECTION_WALKTHROUGH_SCENARIO',
    'ELECTION_WALKTHROUGH_BASE_URL',
    'ELECTION_WALKTHROUGH_TOKEN',
    'ELECTION_WALKTHROUGH_ARTIFACT_DIR',
];

for (const name of requiredEnvironment) {
    if (!process.env[name]) {
        throw new Error(`Missing required environment variable ${name}.`);
    }
}

const scenario = process.env.ELECTION_WALKTHROUGH_SCENARIO;
const baseUrl = process.env.ELECTION_WALKTHROUGH_BASE_URL.replace(/\/+$/, '');
const token = process.env.ELECTION_WALKTHROUGH_TOKEN;
const artifactDirectory = path.resolve(
    process.env.ELECTION_WALKTHROUGH_ARTIFACT_DIR,
);
const videoStagingDirectory = path.join(artifactDirectory, '.video-staging');
const actionLogPath = path.join(artifactDirectory, 'action-log.jsonl');
const tracePath = path.join(artifactDirectory, 'playwright-trace.zip');
const metadataPath = path.join(artifactDirectory, 'recording-metadata.json');
const videoPath = path.join(artifactDirectory, 'full-election.webm');
const storyboardFrameDirectory = path.join(
    artifactDirectory,
    'storyboard-frames',
);
const viewport = { width: 1440, height: 900 };
const actions = [];
const browserMessages = [];
const walkthroughStatistics = {
    ballots_finalized: 0,
    ballots_printed: 0,
    ballots_spoiled: 0,
    ballots_accepted: 0,
    scans_rejected: 0,
    scans_adjudicated: 0,
    voter_selection_checkpoints: 0,
    return_generated: false,
    return_approved: false,
    handoff_completed: false,
    precinct_closed: false,
    audit_archive_verified: false,
    printed_artifacts_rendered: 0,
    printed_artifact_pages: 0,
};
let sequence = 0;
let browser;
let context;
let page;
let nativeVideoPath;
let errorMessage = null;
let storyboardArtifacts = [];

await mkdir(artifactDirectory, { recursive: true });
await mkdir(storyboardFrameDirectory, { recursive: true });
await rm(videoStagingDirectory, { force: true, recursive: true });
await mkdir(videoStagingDirectory, { recursive: true });

function recordAction(action, status, details = {}) {
    actions.push({
        sequence: ++sequence,
        action,
        status,
        details,
    });
}

async function describeArtifact(label, artifactPath) {
    const contents = await readFile(artifactPath);
    const artifactStat = await stat(artifactPath);

    return {
        label,
        path: artifactPath,
        relative_path: path.relative(artifactDirectory, artifactPath),
        bytes: artifactStat.size,
        sha256: createHash('sha256').update(contents).digest('hex'),
    };
}

async function capture(name) {
    const screenshot = path.join(artifactDirectory, name);
    const storyboardFrame = path.join(storyboardFrameDirectory, name);

    await page.screenshot({
        path: screenshot,
        fullPage: true,
    });
    await page.screenshot({
        path: storyboardFrame,
        fullPage: false,
    });

    return { screenshot, storyboardFrame };
}

async function captureViewport(name) {
    const screenshot = path.join(artifactDirectory, name);
    const storyboardFrame = path.join(storyboardFrameDirectory, name);

    await page.screenshot({
        path: screenshot,
        fullPage: false,
    });
    await page.screenshot({
        path: storyboardFrame,
        fullPage: false,
    });

    return { screenshot, storyboardFrame };
}

async function runStep(action, callback, screenshotName) {
    recordAction(action, 'started');
    const details = (await callback()) ?? {};
    let captureResult;

    if (screenshotName) {
        captureResult = await capture(screenshotName);
    }

    recordAction(action, 'passed', {
        ...details,
        url: page.url(),
        heading: await page.locator('h1').first().textContent(),
        screenshot: captureResult?.screenshot,
        storyboard_frame: captureResult?.storyboardFrame,
    });
}

async function postButton(
    buttonName,
    pathname,
    timeout = 60_000,
    reload = true,
) {
    const button = page.getByRole('button', {
        name: buttonName,
        exact: true,
    });

    await button.waitFor({ state: 'visible' });
    await Promise.all([
        page.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                new URL(response.url()).pathname === pathname,
            { timeout },
        ),
        button.click(),
    ]);
    await page.waitForLoadState('networkidle');

    if (reload) {
        await page.reload({
            waitUntil: 'networkidle',
            timeout,
        });
    }
}

async function openPath(pathname) {
    await page.goto(`${baseUrl}${pathname}`, {
        waitUntil: 'networkidle',
        timeout: 60_000,
    });
}

async function recordSignedCheckpoint() {
    const signOff = page.locator('details').filter({
        has: page.getByText('Officer sign-off', { exact: true }),
    });

    await signOff.locator('summary').click();

    const canvas = signOff.locator(
        'canvas[aria-label="Officer signature pad"]',
    );
    await canvas.evaluate((signatureCanvas) => {
        const context = signatureCanvas.getContext('2d');

        if (!context) {
            throw new Error('The officer signature pad is not available.');
        }

        context.lineCap = 'round';
        context.lineJoin = 'round';
        context.lineWidth = 4;
        context.strokeStyle = '#1c1917';
        context.beginPath();
        context.moveTo(70, 145);
        context.bezierCurveTo(140, 55, 190, 175, 260, 75);
        context.bezierCurveTo(300, 35, 345, 155, 420, 90);
        context.stroke();
        signatureCanvas.dispatchEvent(
            new PointerEvent('pointerup', {
                bubbles: true,
                pointerId: 1,
            }),
        );
    });

    await signOff.locator('input[name="officer_code"]').fill('SIM-OFFICER-001');
    await signOff.locator('input[name="officer_pin"]').fill('123456');
    await signOff
        .getByRole('button', {
            name: 'Record signed checkpoint',
            exact: true,
        })
        .waitFor({ state: 'visible' });
    await signOff
        .getByRole('button', {
            name: 'Record signed checkpoint',
            exact: true,
        })
        .evaluate((button) => {
            if (button.disabled) {
                throw new Error(
                    'The signed checkpoint is not ready to submit.',
                );
            }
        });
    await postButton('Record signed checkpoint', '/election/attestations');
}

async function finalizeVoterBallot(ballotNumber) {
    await openPath('/election/voting');
    const authorizationButton = page
        .getByRole('button', {
            name: /^(Issue anonymous voting code|Issue next voter code|Generate replacement code)$/,
        })
        .first();
    const authorizationForm = page.locator('form').filter({
        has: authorizationButton,
    });
    await authorizationForm
        .locator('input[name="officer_code"]')
        .fill('SIM-OFFICER-001');
    await authorizationForm.locator('input[name="officer_pin"]').fill('123456');
    const authorizationAction = (
        await authorizationButton.textContent()
    ).trim();
    await postButton(
        authorizationAction,
        '/election/voting/voter-authorizations',
        60_000,
        false,
    );
    const voterCode = await page.locator('p.font-mono').first().textContent();

    await openPath('/election/voter');
    await page.locator('input[name="code"]').fill(voterCode.trim());
    await postButton('Begin voting', '/election/voter/claim', 60_000, false);
    await page.waitForURL('**/election/voter/ballot');

    const contests = page.locator('fieldset');
    const contestCount = await contests.count();

    if (contestCount === 0) {
        throw new Error('The voter ballot contains no contests.');
    }

    const openingCapture = await capture(
        `16-voter-ballot-${ballotNumber}-opened.png`,
    );
    recordAction(`voter-ballot-${ballotNumber}-opened`, 'passed', {
        ballot_number: ballotNumber,
        contest_count: contestCount,
        url: page.url(),
        heading: await page.locator('h1').first().textContent(),
        screenshot: openingCapture.screenshot,
        storyboard_frame: openingCapture.storyboardFrame,
    });

    let selectionSequence = 0;

    for (let contestIndex = 0; contestIndex < contestCount; contestIndex++) {
        const contest = contests.nth(contestIndex);
        const contestTitle = (
            await contest.locator('legend').textContent()
        ).trim();
        const instruction = await contest
            .locator('p')
            .filter({ hasText: 'Select up to' })
            .first()
            .textContent();
        const maximumSelections = Number(instruction?.match(/\d+/)?.[0] ?? 0);
        const candidates = contest.locator('button[type="button"]');
        const candidateCount = await candidates.count();
        const selections = Math.min(maximumSelections, candidateCount);

        for (let selection = 0; selection < selections; selection++) {
            const candidate = candidates.nth(
                (ballotNumber + selection - 1) % candidateCount,
            );
            const candidateName = (
                await candidate.locator('strong').textContent()
            ).trim();

            await candidate.click();
            selectionSequence++;
            walkthroughStatistics.voter_selection_checkpoints++;

            const selectionCapture = await captureViewport(
                `16-voter-ballot-${ballotNumber}-selection-${String(selectionSequence).padStart(2, '0')}.png`,
            );
            recordAction(
                `voter-ballot-${ballotNumber}-selection-${selectionSequence}`,
                'passed',
                {
                    ballot_number: ballotNumber,
                    contest: contestTitle,
                    candidate: candidateName,
                    contest_selection: selection + 1,
                    contest_maximum: maximumSelections,
                    selection_sequence: selectionSequence,
                    url: page.url(),
                    heading: await page.locator('h1').first().textContent(),
                    screenshot: selectionCapture.screenshot,
                    storyboard_frame: selectionCapture.storyboardFrame,
                },
            );
        }
    }

    await page
        .getByRole('button', { name: /^Review \d+ selections?$/ })
        .click();
    const reviewCapture = await capture(
        `16-voter-ballot-${ballotNumber}-review.png`,
    );
    recordAction(`voter-ballot-${ballotNumber}-reviewed`, 'passed', {
        ballot_number: ballotNumber,
        total_selections: selectionSequence,
        url: page.url(),
        heading: await page.locator('h1').first().textContent(),
        screenshot: reviewCapture.screenshot,
        storyboard_frame: reviewCapture.storyboardFrame,
    });
    await postButton(
        'Finalize and get print code',
        '/election/voter/ballot',
        60_000,
        false,
    );
    await page.waitForURL('**/election/voter/complete');
    await page
        .getByText('Ballot finalized privately', { exact: true })
        .waitFor();
    walkthroughStatistics.ballots_finalized++;

    return {
        release_code: (await page.locator('p.font-mono').textContent()).trim(),
    };
}

async function printAndDepositPreparedBallot(release) {
    await openPath('/election/print-station');
    await page.locator('input[name="code"]').fill(release.release_code);
    await postButton('Prepare paper ballot', '/election/print-station/redeem');
    await postButton('Print paper ballot', '/election/print-station/print');
    walkthroughStatistics.ballots_printed++;
    await postButton(
        'Scan and deposit verified ballot',
        '/election/print-station/deposit',
        60_000,
        false,
    );
    await page.getByText('Ballot accepted', { exact: true }).waitFor();
    walkthroughStatistics.ballots_accepted++;

    return release;
}

try {
    recordAction('launch-browser', 'started', {
        headless: process.env.ELECTION_WALKTHROUGH_HEADED !== '1',
        slow_motion_ms: Number(process.env.ELECTION_WALKTHROUGH_SLOW_MO ?? 0),
    });
    browser = await chromium.launch({
        headless: process.env.ELECTION_WALKTHROUGH_HEADED !== '1',
        slowMo: Number(process.env.ELECTION_WALKTHROUGH_SLOW_MO ?? 0),
    });
    context = await browser.newContext({
        viewport,
        extraHTTPHeaders: {
            'X-Election-Walkthrough-Token': token,
        },
        recordVideo: {
            dir: videoStagingDirectory,
            size: viewport,
        },
    });
    await context.tracing.start({
        screenshots: true,
        snapshots: true,
        sources: true,
    });
    page = await context.newPage();
    page.on('console', (message) => {
        if (['error', 'warning'].includes(message.type())) {
            browserMessages.push({
                type: message.type(),
                text: message.text(),
            });
        }
    });
    page.on('pageerror', (error) => {
        browserMessages.push({
            type: 'pageerror',
            text: error.message,
        });
    });
    recordAction('launch-browser', 'passed');

    await runStep(
        'open-election-home',
        async () => {
            await openPath('/election');
            await page.locator('body').waitFor({ state: 'visible' });

            return {
                title: await page.title(),
            };
        },
        '01-home.png',
    );

    await runStep(
        'open-precinct-setup',
        async () => {
            await page
                .getByRole('link', {
                    name: 'Open current ceremony',
                    exact: true,
                })
                .click();
            await page.waitForURL('**/election/provision');
            await page
                .getByRole('heading', {
                    name: 'Load this precinct',
                    exact: true,
                })
                .waitFor();
        },
        '02-precinct-setup.png',
    );

    await runStep(
        'activate-precinct-package',
        async () => {
            await postButton(
                'Import and activate precinct',
                '/election/provision/activate',
                240_000,
            );
            await openPath('/election/certification');
            await page.getByText('39010001', { exact: true }).first().waitFor();
        },
        '03-package-activated.png',
    );

    await runStep(
        'record-dual-control-setup',
        async () => {
            await openPath('/election/provision');
            const form = page.locator('form').filter({
                has: page.getByRole('button', {
                    name: 'Record setup under dual control',
                    exact: true,
                }),
            });

            const setupValues = {
                chairperson_code: 'SIM-OFFICER-001',
                chairperson_pin: '123456',
                poll_clerk_code: 'SIM-OFFICER-002',
                poll_clerk_pin: '123456',
                third_member_code: 'SIM-OFFICER-003',
                device_serial: 'AES-PI-39010001-001',
                printer_serial: 'AES-PRINTER-39010001-001',
                scanner_serial: 'AES-SCANNER-39010001-001',
                ballot_stock_start: '1',
                ballot_stock_end: '1000',
                ballot_box_id: 'AES-BOX-39010001-001',
                custody_envelope_id: 'AES-ENV-39010001-001',
                seal_numbers: 'AES-SEAL-39010001-001,AES-SEAL-39010001-002',
            };

            for (const [name, value] of Object.entries(setupValues)) {
                await form.locator(`input[name="${name}"]`).fill(value);
            }

            await postButton(
                'Record setup under dual control',
                '/election/provision/setup',
            );
            await page.getByText('Recorded', { exact: true }).first().waitFor();
        },
        '04-dual-control-setup.png',
    );

    await runStep(
        'record-board-and-supply-baselines',
        async () => {
            await postButton(
                'Record EB roster baseline',
                '/election/provision/eb-role-baseline',
            );
            await postButton(
                'Verify election supplies',
                '/election/provision/supply-verification-baseline',
            );
            await page.getByText('Ready', { exact: true }).first().waitFor();

            recordAction('external-legal-scenario-baseline', 'not-applicable', {
                reason: 'The lifecycle harness is an external preflight and may not reset an active operational rehearsal.',
            });
        },
        '05-opening-readiness.png',
    );

    await runStep(
        'generate-initialization-report',
        async () => {
            await openPath('/election/diagnostics');
            await postButton(
                'Run device readiness check',
                '/election/diagnostics/certify-devices',
            );
            await postButton(
                'Generate Initialization Report',
                '/election/diagnostics/initialization-report',
            );
            const initialization = page.locator('section').filter({
                has: page.getByRole('heading', {
                    name: 'Initialization Report',
                    exact: true,
                }),
            });
            await initialization.getByText('true', { exact: true }).waitFor();
        },
        '06-initialization-report.png',
    );

    await runStep(
        'run-friday-certification',
        async () => {
            await openPath('/election/certification');
            await postButton(
                'Run Certification',
                '/election/certification/run',
                120_000,
            );
            await page.getByText('PASS', { exact: true }).first().waitFor();
        },
        '07-friday-certification.png',
    );

    await runStep(
        'run-manual-verification',
        async () => {
            await postButton(
                'Run Manual Verification',
                '/election/certification/manual-verification',
            );
            await page.getByText('Matched', { exact: true }).waitFor();
        },
        '08-manual-verification.png',
    );

    await runStep(
        'run-discrepancy-analysis',
        async () => {
            await postButton(
                'Run Discrepancy Analysis',
                '/election/certification/discrepancy',
            );
            await page.getByText('No discrepancy', { exact: true }).waitFor();
        },
        '09-discrepancy-analysis.png',
    );

    await runStep(
        'run-zero-out',
        async () => {
            await postButton(
                'Run Zero-Out',
                '/election/certification/zero-out',
            );
            await page.getByText('Cleared', { exact: true }).waitFor();
        },
        '10-zero-out.png',
    );

    await runStep(
        'record-certification-signature',
        async () => {
            await recordSignedCheckpoint();
        },
        '11-certification-signature.png',
    );

    await runStep(
        'seal-certification',
        async () => {
            await postButton('Record Sealing', '/election/certification/seal');
            await page.getByText('Sealed', { exact: true }).waitFor();
        },
        '12-certification-sealed.png',
    );

    await runStep(
        'initialize-opening-of-polls',
        async () => {
            await openPath('/election/voting');
            const form = page.locator('form').filter({
                has: page.getByRole('button', {
                    name: 'Open polls',
                    exact: true,
                }),
            });
            await form
                .locator('input[name="officer_code"]')
                .fill('SIM-OFFICER-001');
            await form.locator('input[name="officer_pin"]').fill('123456');
            await postButton('Open polls', '/election/voting/open-polls');
            await page
                .getByRole('button', {
                    name: 'Begin voting',
                    exact: true,
                })
                .waitFor();
        },
        '13-polls-initialized.png',
    );

    await runStep(
        'record-opening-signature',
        async () => {
            await recordSignedCheckpoint();
        },
        '14-opening-signature.png',
    );

    await runStep(
        'begin-active-voting',
        async () => {
            const form = page.locator('form').filter({
                has: page.getByRole('button', {
                    name: 'Begin voting',
                    exact: true,
                }),
            });
            await form
                .locator('input[name="officer_code"]')
                .fill('SIM-OFFICER-001');
            await form.locator('input[name="officer_pin"]').fill('123456');
            await postButton('Begin voting', '/election/voting/open-polls');
            await page
                .getByRole('button', {
                    name: 'Issue anonymous voting code',
                    exact: true,
                })
                .waitFor();
        },
        '15-voting-open.png',
    );

    const requestedBallots = Number(
        process.env.ELECTION_WALKTHROUGH_BALLOTS ?? 1,
    );

    for (
        let ballotNumber = 1;
        ballotNumber <= requestedBallots;
        ballotNumber++
    ) {
        let release;
        await runStep(
            `finalize-voter-ballot-${ballotNumber}`,
            async () => {
                release = await finalizeVoterBallot(ballotNumber);

                return {
                    release_ready: true,
                };
            },
            `16-voter-ballot-${ballotNumber}-finalized.png`,
        );

        await runStep(
            `print-and-deposit-voter-ballot-${ballotNumber}`,
            async () => {
                return printAndDepositPreparedBallot(release);
            },
            `17-voter-ballot-${ballotNumber}-deposited.png`,
        );
    }

    await runStep(
        'complete-voting-and-printing-segment',
        async () => {
            await openPath('/election/voting');

            return {
                sealed_ballots_ready_for_counting:
                    walkthroughStatistics.ballots_accepted,
            };
        },
        '18-voting-and-printing-complete.png',
    );

    recordAction('voting-printing-and-spoilage', 'passed', {
        final_url: page.url(),
        ...walkthroughStatistics,
    });

    await runStep(
        'close-polls',
        async () => {
            await postButton('Close polls', '/election/voting/close-polls');
            await openPath('/election/counting');
            await page
                .getByRole('heading', {
                    name: 'Scan paper ballots',
                    exact: true,
                })
                .waitFor();
        },
        '19-polls-closed.png',
    );

    await runStep(
        'record-physical-ballot-control',
        async () => {
            const form = page.locator('form').filter({
                has: page.getByRole('button', {
                    name: 'Record physical control',
                    exact: true,
                }),
            });

            await form
                .locator('input[name="physical_count"]')
                .fill(String(walkthroughStatistics.ballots_accepted));
            await form
                .locator('input[name="officer_code"]')
                .fill('SIM-OFFICER-001');
            await form.locator('input[name="officer_pin"]').fill('123456');
            await postButton(
                'Record physical control',
                '/election/counting/physical-count',
            );
            await page.getByText('Reconciled', { exact: true }).waitFor();
        },
        '20-physical-ballots-reconciled.png',
    );

    await runStep(
        'complete-counting-and-tally',
        async () => {
            await postButton(
                'Complete counting and tally',
                '/election/counting/complete',
            );
            await openPath('/election/returns');
            await page
                .getByRole('heading', {
                    name: 'Generate the Election Return',
                    exact: true,
                })
                .waitFor();
        },
        '21-counting-complete.png',
    );

    recordAction('counting-adjudication-and-tally', 'passed', {
        final_url: page.url(),
        accepted_ballots: walkthroughStatistics.ballots_accepted,
        rejected_scans: walkthroughStatistics.scans_rejected,
        adjudicated_scans: walkthroughStatistics.scans_adjudicated,
    });

    await runStep(
        'generate-election-return',
        async () => {
            await postButton(
                'Generate Election Return',
                '/election/returns/generate',
            );
            await page.getByText('Counts match', { exact: true }).waitFor();
            walkthroughStatistics.return_generated = true;
        },
        '28-election-return-generated.png',
    );

    await runStep(
        'prepare-return-copies-and-posting',
        async () => {
            await postButton(
                'Prepare copies and posting',
                '/election/returns/copy-distribution',
            );
            await page.getByText('Copies prepared', { exact: true }).waitFor();
        },
        '29-return-copies-and-posting.png',
    );

    await runStep(
        'approve-election-return',
        async () => {
            const form = page.locator('form').filter({
                has: page.getByRole('button', {
                    name: 'Approve Election Return',
                    exact: true,
                }),
            });

            await form
                .locator('input[name="chairperson_code"]')
                .fill('SIM-OFFICER-001');
            await form.locator('input[name="chairperson_pin"]').fill('123456');
            await form
                .locator('input[name="poll_clerk_code"]')
                .fill('SIM-OFFICER-002');
            await form.locator('input[name="poll_clerk_pin"]').fill('123456');
            await postButton(
                'Approve Election Return',
                '/election/returns/approve',
            );
            await page
                .getByText('Dual-control approval recorded', { exact: true })
                .waitFor();
            walkthroughStatistics.return_approved = true;
        },
        '30-election-return-approved.png',
    );

    await runStep(
        'complete-election-return-ceremony',
        async () => {
            await postButton(
                'Continue to official handoff',
                '/election/returns/close',
            );
            await openPath('/election/transmission');
            await page
                .getByRole('heading', {
                    name: 'Transmission or Official Handoff',
                    exact: true,
                })
                .waitFor();
        },
        '31-official-handoff-opened.png',
    );

    await runStep(
        'prepare-transmission-report',
        async () => {
            await postButton(
                'Prepare Transmission Report',
                '/election/transmission/send',
            );
            await page.getByText('Transmission ID', { exact: true }).waitFor();
        },
        '32-transmission-report-prepared.png',
    );

    await runStep(
        'prepare-delivery-package',
        async () => {
            await postButton(
                'Prepare Delivery Package',
                '/election/transmission/package',
            );
            await page.getByText('Package Hash', { exact: true }).waitFor();
        },
        '33-delivery-package-prepared.png',
    );

    await runStep(
        'record-handoff-officer-verification',
        async () => {
            const form = page.locator('form').filter({
                has: page.getByRole('button', {
                    name: 'Record Officer Verification',
                    exact: true,
                }),
            });

            await form
                .locator('input[name="officer_code"]')
                .fill('SIM-OFFICER-001');
            await form.locator('input[name="officer_pin"]').fill('123456');
            await form
                .locator('input[name="verification_note"]')
                .fill('Chairperson verified the official handoff package.');
            await postButton(
                'Record Officer Verification',
                '/election/transmission/officer-verification',
            );
            await page.getByText('verified', { exact: true }).waitFor();
        },
        '34-handoff-officer-verified.png',
    );

    await runStep(
        'record-handoff-recipient-verification',
        async () => {
            const form = page.locator('form').filter({
                has: page.getByRole('button', {
                    name: 'Record Recipient Verification',
                    exact: true,
                }),
            });

            await form
                .locator('input[name="recipient"]')
                .fill('City Board of Canvassers Receiving Officer');
            await form
                .locator('input[name="recipient_role"]')
                .fill('Authorized Receiving Officer');
            await form
                .locator('input[name="acknowledgement_note"]')
                .fill(
                    'Recipient acknowledged the sealed offline evidence package.',
                );
            await postButton(
                'Record Recipient Verification',
                '/election/transmission/recipient-verification',
            );
            await page
                .getByText('City Board of Canvassers Receiving Officer', {
                    exact: true,
                })
                .waitFor();
        },
        '35-handoff-recipient-verified.png',
    );

    await runStep(
        'generate-delivery-receipt',
        async () => {
            await postButton(
                'Generate Delivery Receipt',
                '/election/transmission/receipt',
            );
            await page.getByText('Receipt ID', { exact: true }).waitFor();
        },
        '36-delivery-receipt-generated.png',
    );

    await runStep(
        'record-final-backup',
        async () => {
            await postButton(
                'Record Final Backup',
                '/election/transmission/final-backup',
            );
            await page.getByText('Backup ID', { exact: true }).waitFor();
        },
        '37-final-backup-recorded.png',
    );

    await runStep(
        'record-custody-turnover',
        async () => {
            await postButton(
                'Record Custody Turnover',
                '/election/transmission/custody',
            );
            await page
                .getByText('Custody ID', { exact: true })
                .first()
                .waitFor();
            walkthroughStatistics.handoff_completed = true;
        },
        '38-custody-turnover-recorded.png',
    );

    await runStep(
        'close-precinct',
        async () => {
            await postButton(
                'Close Precinct',
                '/election/transmission/close-precinct',
            );
            await openPath('/election/diagnostics');
            await page
                .getByRole('button', {
                    name: 'Begin Audit and Reconciliation',
                    exact: true,
                })
                .waitFor();
            walkthroughStatistics.precinct_closed = true;
        },
        '39-precinct-closed.png',
    );

    await runStep(
        'begin-audit-and-reconciliation',
        async () => {
            await postButton(
                'Begin Audit and Reconciliation',
                '/election/diagnostics/begin-audit',
            );
            await page
                .getByRole('heading', {
                    name: 'Precinct Evidence Manifest',
                    exact: true,
                })
                .waitFor();
        },
        '40-audit-opened.png',
    );

    await runStep(
        'generate-evidence-reference-baseline',
        async () => {
            await postButton(
                'Generate Baseline',
                '/election/diagnostics/evidence-reference-baseline',
            );
        },
        '41-evidence-reference-baseline.png',
    );

    await runStep(
        'generate-official-minutes-baseline',
        async () => {
            await postButton(
                'Generate Official Minutes',
                '/election/diagnostics/official-minutes-baseline',
            );
        },
        '42-official-minutes-baseline.png',
    );

    await runStep(
        'generate-audit-reconciliation-baseline',
        async () => {
            await postButton(
                'Generate Reconciliation',
                '/election/diagnostics/audit-reconciliation-baseline',
            );
        },
        '43-audit-reconciliation-baseline.png',
    );

    await runStep(
        'generate-final-evidence-manifest',
        async () => {
            await postButton(
                'Generate Manifest',
                '/election/diagnostics/evidence-manifest',
            );
            await page.getByText('Manifest Hash', { exact: true }).waitFor();
        },
        '44-final-evidence-manifest.png',
    );

    await runStep(
        'build-evidence-bundle-archive',
        async () => {
            await postButton(
                'Build Download Archive',
                '/election/diagnostics/evidence-bundle-archive',
                120_000,
            );
            await page
                .getByText('Archive Hash', { exact: true })
                .first()
                .waitFor();
        },
        '45-evidence-bundle-archive.png',
    );

    await runStep(
        'verify-evidence-bundle-archive',
        async () => {
            await postButton(
                'Verify Built Archive',
                '/election/diagnostics/evidence-bundle-archive/verify',
                120_000,
            );
            await page
                .getByText('Latest archive verification passed.', {
                    exact: true,
                })
                .waitFor();
            walkthroughStatistics.audit_archive_verified = true;
        },
        '46-evidence-bundle-verified.png',
    );

    recordAction('returns-handoff-custody-and-audit', 'passed', {
        final_url: page.url(),
        ...walkthroughStatistics,
    });
} catch (error) {
    errorMessage = error instanceof Error ? error.message : String(error);
    recordAction('walkthrough', 'failed', { error: errorMessage });

    if (page) {
        try {
            await page.screenshot({
                path: path.join(artifactDirectory, 'failure.png'),
                fullPage: true,
            });
        } catch {
            // The browser may already be unavailable.
        }
    }
} finally {
    if (page?.video()) {
        nativeVideoPath = await page
            .video()
            .path()
            .catch(() => null);
    }

    if (context) {
        await context.tracing.stop({ path: tracePath }).catch(() => {});
        await context.close().catch(() => {});
    }

    if (browser) {
        await browser.close().catch(() => {});
    }
}

if (nativeVideoPath) {
    await rm(videoPath, { force: true });
    await rename(nativeVideoPath, videoPath);
}

await rm(videoStagingDirectory, { force: true, recursive: true });

if (errorMessage === null) {
    try {
        recordAction('render-printed-artifacts', 'started');
        const runPath = path.resolve(artifactDirectory, '../..');
        const printedArtifacts = await renderPrintedArtifacts({
            runPath,
            artifactDirectory,
            ghostscriptBinary:
                process.env.ELECTION_WALKTHROUGH_GHOSTSCRIPT ?? 'gs',
        });

        for (const document of printedArtifacts) {
            for (const pageArtifact of document.pages) {
                recordAction('review-printed-artifact', 'passed', {
                    screenshot: pageArtifact.imagePath,
                    printed_artifact: {
                        type: document.type,
                        title: document.title,
                        source_relative_path: document.relativePath,
                        source_filename: path.basename(document.sourcePath),
                        source_bytes: document.bytes,
                        source_sha256: document.sha256,
                        page: pageArtifact.page,
                        page_count: pageArtifact.pageCount,
                    },
                });
            }
        }

        walkthroughStatistics.printed_artifacts_rendered =
            printedArtifacts.length;
        walkthroughStatistics.printed_artifact_pages = printedArtifacts.reduce(
            (total, document) => total + document.pages.length,
            0,
        );
        recordAction('render-printed-artifacts', 'passed', {
            documents: walkthroughStatistics.printed_artifacts_rendered,
            pages: walkthroughStatistics.printed_artifact_pages,
        });
    } catch (error) {
        errorMessage = error instanceof Error ? error.message : String(error);
        recordAction('render-printed-artifacts', 'failed', {
            error: errorMessage,
        });
    }
}

if (errorMessage === null) {
    try {
        recordAction('generate-walkthrough-storyboard', 'started');
        storyboardArtifacts = await generateWalkthroughStoryboard({
            artifactDirectory,
            actions,
            statistics: {
                ...walkthroughStatistics,
                browser_messages: browserMessages.length,
            },
            scenario,
        });
        recordAction('generate-walkthrough-storyboard', 'passed', {
            artifacts: storyboardArtifacts.map(([, artifactPath]) =>
                path.basename(artifactPath),
            ),
        });
    } catch (error) {
        errorMessage = error instanceof Error ? error.message : String(error);
        recordAction('generate-walkthrough-storyboard', 'failed', {
            error: errorMessage,
        });
    }
}

await writeFile(
    actionLogPath,
    `${actions.map((action) => JSON.stringify(action)).join('\n')}\n`,
);

const artifactCandidates = [
    ['Video', videoPath],
    ['Playwright trace', tracePath],
    ['Failure screenshot', path.join(artifactDirectory, 'failure.png')],
    ['Action log', actionLogPath],
    ...storyboardArtifacts,
];

for (const action of actions) {
    const actionScreenshot = action.details?.screenshot;

    if (actionScreenshot) {
        artifactCandidates.push([
            `Screenshot ${path.basename(actionScreenshot, '.png')}`,
            actionScreenshot,
        ]);
    }

    const storyboardFrame = action.details?.storyboard_frame;

    if (storyboardFrame) {
        artifactCandidates.push([
            `Storyboard frame ${path.basename(storyboardFrame, '.png')}`,
            storyboardFrame,
        ]);
    }
}

const artifacts = [];

for (const [label, artifactPath] of artifactCandidates) {
    try {
        artifacts.push(await describeArtifact(label, artifactPath));
    } catch {
        // Optional artifacts are omitted when the browser failed before creating them.
    }
}

const passed = errorMessage === null;
const metadata = {
    schema_version: 'browser-walkthrough-recording-metadata-1',
    scenario,
    passed,
    base_url: baseUrl,
    viewport,
    ballots_requested: Number(process.env.ELECTION_WALKTHROUGH_BALLOTS ?? 0),
    headed: process.env.ELECTION_WALKTHROUGH_HEADED === '1',
    slow_motion_ms: Number(process.env.ELECTION_WALKTHROUGH_SLOW_MO ?? 0),
    browser_version: browser?.version() ?? null,
    browser_messages: browserMessages,
    actions_completed: actions.filter((action) => action.status === 'passed')
        .length,
    error: errorMessage,
    artifacts,
};

await writeFile(metadataPath, `${JSON.stringify(metadata, null, 2)}\n`);
artifacts.push(await describeArtifact('Recording metadata', metadataPath));

const result = {
    schema_version: 'browser-walkthrough-recorder-result-1',
    passed,
    error: errorMessage,
    statistics: {
        actions_recorded: actions.length,
        actions_completed: metadata.actions_completed,
        browser_messages: browserMessages.length,
        ballots_requested: metadata.ballots_requested,
        screenshots: actions.filter(
            (action) =>
                action.status === 'passed' &&
                typeof action.details?.screenshot === 'string',
        ).length,
        storyboard_frames: actions.filter(
            (action) =>
                action.status === 'passed' &&
                typeof action.details?.storyboard_frame === 'string',
        ).length,
        ...walkthroughStatistics,
    },
    artifacts,
};

process.stdout.write(`${JSON.stringify(result)}\n`);
process.exitCode = passed ? 0 : 1;
