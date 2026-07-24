import { createHash } from 'node:crypto';
import {
    mkdir,
    readFile,
    rename,
    rm,
    stat,
    writeFile,
} from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { chromium } from 'playwright';

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
const viewport = { width: 1440, height: 900 };
const actions = [];
const browserMessages = [];
let sequence = 0;
let browser;
let context;
let page;
let nativeVideoPath;
let errorMessage = null;

await mkdir(artifactDirectory, { recursive: true });
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

    await page.screenshot({
        path: screenshot,
        fullPage: true,
    });

    return screenshot;
}

async function runStep(action, callback, screenshotName) {
    recordAction(action, 'started');
    const details = (await callback()) ?? {};
    let screenshot;

    if (screenshotName) {
        screenshot = await capture(screenshotName);
    }

    recordAction(action, 'passed', {
        ...details,
        url: page.url(),
        heading: await page.locator('h1').first().textContent(),
        screenshot,
    });
}

async function postButton(buttonName, pathname, timeout = 60_000) {
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
    await page.reload({
        waitUntil: 'networkidle',
        timeout,
    });
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
    await signOff.locator('input[name="officer_code"]').fill('SIM-OFFICER-001');
    await signOff.locator('input[name="officer_pin"]').fill('123456');

    const canvas = signOff.locator('canvas[aria-label="Officer signature pad"]');
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
        signatureCanvas.dispatchEvent(new PointerEvent('pointerup', {
            bubbles: true,
            pointerId: 1,
        }));
    });

    await signOff.getByRole('button', {
        name: 'Record signed checkpoint',
        exact: true,
    }).waitFor({ state: 'visible' });
    await signOff.getByRole('button', {
        name: 'Record signed checkpoint',
        exact: true,
    }).evaluate((button) => {
        if (button.disabled) {
            throw new Error('The signed checkpoint is not ready to submit.');
        }
    });
    await postButton('Record signed checkpoint', '/election/attestations');
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

    await runStep('open-election-home', async () => {
        await openPath('/election');
        await page.locator('body').waitFor({ state: 'visible' });

        return {
            title: await page.title(),
        };
    }, '01-home.png');

    await runStep('open-precinct-setup', async () => {
        await page.getByRole('link', {
            name: 'Open current ceremony',
            exact: true,
        }).click();
        await page.waitForURL('**/election/provision');
        await page.getByRole('heading', {
            name: 'Load this precinct',
            exact: true,
        }).waitFor();
    }, '02-precinct-setup.png');

    await runStep('activate-precinct-package', async () => {
        await postButton(
            'Import and activate precinct',
            '/election/provision/activate',
            240_000,
        );
        await openPath('/election/certification');
        await page.getByText('39010001', { exact: true }).first().waitFor();
    }, '03-package-activated.png');

    await runStep('record-dual-control-setup', async () => {
        await openPath('/election/provision');
        const form = page.locator('form').filter({
            has: page.getByRole('button', {
                name: 'Record setup under dual control',
                exact: true,
            }),
        });

        await form.locator('input[name="chairperson_pin"]').fill('123456');
        await form.locator('input[name="poll_clerk_pin"]').fill('123456');
        await postButton(
            'Record setup under dual control',
            '/election/provision/setup',
        );
        await page.getByText('Recorded', { exact: true }).first().waitFor();
    }, '04-dual-control-setup.png');

    await runStep('record-board-and-supply-baselines', async () => {
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
    }, '05-opening-readiness.png');

    await runStep('generate-initialization-report', async () => {
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
    }, '06-initialization-report.png');

    await runStep('run-friday-certification', async () => {
        await openPath('/election/certification');
        await postButton(
            'Run Certification',
            '/election/certification/run',
            120_000,
        );
        await page.getByText('PASS', { exact: true }).first().waitFor();
    }, '07-friday-certification.png');

    await runStep('run-manual-verification', async () => {
        await postButton(
            'Run Manual Verification',
            '/election/certification/manual-verification',
        );
        await page.getByText('Matched', { exact: true }).waitFor();
    }, '08-manual-verification.png');

    await runStep('run-discrepancy-analysis', async () => {
        await postButton(
            'Run Discrepancy Analysis',
            '/election/certification/discrepancy',
        );
        await page.getByText('No discrepancy', { exact: true }).waitFor();
    }, '09-discrepancy-analysis.png');

    await runStep('run-zero-out', async () => {
        await postButton(
            'Run Zero-Out',
            '/election/certification/zero-out',
        );
        await page.getByText('Cleared', { exact: true }).waitFor();
    }, '10-zero-out.png');

    await runStep('record-certification-signature', async () => {
        await recordSignedCheckpoint();
    }, '11-certification-signature.png');

    await runStep('seal-certification', async () => {
        await postButton(
            'Record Sealing',
            '/election/certification/seal',
        );
        await page.getByText('Sealed', { exact: true }).waitFor();
    }, '12-certification-sealed.png');

    await runStep('initialize-opening-of-polls', async () => {
        await openPath('/election/voting');
        const form = page.locator('form').filter({
            has: page.getByRole('button', {
                name: 'Open polls',
                exact: true,
            }),
        });
        await form.locator('input[name="officer_code"]').fill('SIM-OFFICER-001');
        await form.locator('input[name="officer_pin"]').fill('123456');
        await postButton('Open polls', '/election/voting/open-polls');
        await page.getByRole('button', {
            name: 'Begin voting',
            exact: true,
        }).waitFor();
    }, '13-polls-initialized.png');

    await runStep('record-opening-signature', async () => {
        await recordSignedCheckpoint();
    }, '14-opening-signature.png');

    await runStep('begin-active-voting', async () => {
        const form = page.locator('form').filter({
            has: page.getByRole('button', {
                name: 'Begin voting',
                exact: true,
            }),
        });
        await form.locator('input[name="officer_code"]').fill('SIM-OFFICER-001');
        await form.locator('input[name="officer_pin"]').fill('123456');
        await postButton('Begin voting', '/election/voting/open-polls');
        await page.getByRole('link', {
            name: 'Open voter ballot',
            exact: true,
        }).waitFor();
    }, '15-voting-open.png');

    recordAction('provisioning-through-opening', 'passed', {
        final_url: page.url(),
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
        nativeVideoPath = await page.video().path().catch(() => null);
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
await writeFile(
    actionLogPath,
    `${actions.map((action) => JSON.stringify(action)).join('\n')}\n`,
);

const artifactCandidates = [
    ['Video', videoPath],
    ['Playwright trace', tracePath],
    ['Failure screenshot', path.join(artifactDirectory, 'failure.png')],
    ['Action log', actionLogPath],
];

for (const action of actions) {
    const actionScreenshot = action.details?.screenshot;

    if (actionScreenshot) {
        artifactCandidates.push([
            `Screenshot ${path.basename(actionScreenshot, '.png')}`,
            actionScreenshot,
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
        screenshots: artifacts.filter((artifact) =>
            artifact.relative_path.endsWith('.png'),
        ).length,
    },
    artifacts,
};

process.stdout.write(`${JSON.stringify(result)}\n`);
process.exitCode = passed ? 0 : 1;
