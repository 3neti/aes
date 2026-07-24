import assert from 'node:assert/strict';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { discoverPrintedArtifacts } from '../../scripts/election-print-artifact-renderer.mjs';

async function fixtureRun(files) {
    const runPath = await mkdtemp(
        path.join(os.tmpdir(), 'election-print-artifacts-'),
    );

    for (const relativePath of files) {
        const filePath = path.join(runPath, relativePath);

        await mkdir(path.dirname(filePath), { recursive: true });
        await writeFile(filePath, '%PDF-1.4 fixture');
    }

    return runPath;
}

test('discovers mandatory and supporting printable artifacts in review order', async () => {
    const runPath = await fixtureRun([
        '04-voting/ballots/ballot-b.pdf',
        '04-voting/ballots/ballot-a.pdf',
        '06-counting-and-tally/tally-sheet.pdf',
        '07-election-return/39010001-return.pdf',
        '08-transmission-or-official-handoff/delivery-receipt.pdf',
        '10-custody-turnover/custody-turnover-report.pdf',
    ]);

    try {
        const artifacts = await discoverPrintedArtifacts(runPath);

        assert.deepEqual(
            artifacts.map((artifact) => artifact.type),
            [
                'ballot',
                'ballot',
                'tally_sheet',
                'election_return',
                'delivery_receipt',
                'custody_turnover_report',
            ],
        );
        assert.equal(
            artifacts[0].relativePath,
            '04-voting/ballots/ballot-a.pdf',
        );
    } finally {
        await rm(runPath, { recursive: true, force: true });
    }
});

test('fails closed when a mandatory printable artifact is missing', async () => {
    const runPath = await fixtureRun([
        '04-voting/ballots/ballot-a.pdf',
        '07-election-return/39010001-return.pdf',
    ]);

    try {
        await assert.rejects(
            discoverPrintedArtifacts(runPath),
            /tally-sheet\.pdf/,
        );
    } finally {
        await rm(runPath, { recursive: true, force: true });
    }
});
