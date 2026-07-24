import { createHash } from 'node:crypto';
import { mkdir, rename, rm, stat, writeFile } from 'node:fs/promises';
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
const screenshotPath = path.join(artifactDirectory, '01-home.png');
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
    const contents = await import('node:fs/promises').then(({ readFile }) =>
        readFile(artifactPath),
    );
    const artifactStat = await stat(artifactPath);

    return {
        label,
        path: artifactPath,
        relative_path: path.relative(artifactDirectory, artifactPath),
        bytes: artifactStat.size,
        sha256: createHash('sha256').update(contents).digest('hex'),
    };
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

    const electionUrl = `${baseUrl}/election`;
    recordAction('open-election-home', 'started', { url: electionUrl });
    await page.goto(electionUrl, {
        waitUntil: 'networkidle',
        timeout: 60_000,
    });
    await page.locator('body').waitFor({ state: 'visible' });
    await page.screenshot({
        path: screenshotPath,
        fullPage: true,
    });
    recordAction('open-election-home', 'passed', {
        url: page.url(),
        title: await page.title(),
        heading: await page.locator('h1').first().textContent(),
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
    ['Home screenshot', screenshotPath],
    ['Failure screenshot', path.join(artifactDirectory, 'failure.png')],
    ['Action log', actionLogPath],
];
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
    },
    artifacts,
};

process.stdout.write(`${JSON.stringify(result)}\n`);
process.exitCode = passed ? 0 : 1;
