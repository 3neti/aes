import { execFile } from 'node:child_process';
import { createHash } from 'node:crypto';
import { access, mkdir, readFile, readdir, stat } from 'node:fs/promises';
import path from 'node:path';
import { promisify } from 'node:util';

const executeFile = promisify(execFile);

const staticArtifacts = [
    {
        type: 'tally_sheet',
        title: 'Tally sheet',
        relativePath: '06-counting-and-tally/tally-sheet.pdf',
        required: true,
    },
    {
        type: 'transmission_report',
        title: 'Transmission report',
        relativePath:
            '08-transmission-or-official-handoff/transmission-report.pdf',
        required: false,
    },
    {
        type: 'delivery_receipt',
        title: 'Delivery receipt',
        relativePath:
            '08-transmission-or-official-handoff/delivery-receipt.pdf',
        required: false,
    },
    {
        type: 'final_backup_report',
        title: 'Final backup report',
        relativePath: '09-final-backup/final-backup-report.pdf',
        required: false,
    },
    {
        type: 'custody_record',
        title: 'Custody record',
        relativePath: '10-custody-turnover/custody-record.pdf',
        required: false,
    },
    {
        type: 'custody_turnover_report',
        title: 'Custody turnover report',
        relativePath: '10-custody-turnover/custody-turnover-report.pdf',
        required: false,
    },
];

async function exists(filePath) {
    try {
        await access(filePath);

        return true;
    } catch {
        return false;
    }
}

async function pdfFiles(directory) {
    if (!(await exists(directory))) {
        return [];
    }

    return (await readdir(directory, { withFileTypes: true }))
        .filter(
            (entry) =>
                entry.isFile() && entry.name.toLowerCase().endsWith('.pdf'),
        )
        .map((entry) => entry.name)
        .sort((left, right) => left.localeCompare(right));
}

async function directories(directory) {
    if (!(await exists(directory))) {
        return [];
    }

    return (await readdir(directory, { withFileTypes: true }))
        .filter((entry) => entry.isDirectory())
        .map((entry) => entry.name)
        .sort((left, right) => left.localeCompare(right));
}

export async function discoverPrintedArtifacts(runPath) {
    const artifacts = [];
    const ballotDirectory = path.join(runPath, '04-voting', 'ballots');
    const ballotFiles = await pdfFiles(ballotDirectory);

    if (ballotFiles.length === 0) {
        throw new Error(
            'The walkthrough did not produce a printable ballot PDF.',
        );
    }

    ballotFiles.forEach((filename, index) => {
        artifacts.push({
            type: 'ballot',
            title: `Printed ballot ${index + 1}`,
            relativePath: path.posix.join('04-voting', 'ballots', filename),
            required: true,
        });
    });

    const tallyArtifact = staticArtifacts[0];
    const tallyPath = path.join(runPath, tallyArtifact.relativePath);

    if (!(await exists(tallyPath))) {
        throw new Error(
            `The walkthrough did not produce required printable artifact [${tallyArtifact.relativePath}].`,
        );
    }

    artifacts.push(tallyArtifact);

    const returnDirectory = path.join(runPath, '07-election-return');
    const returnFiles = (await pdfFiles(returnDirectory)).filter((filename) =>
        filename.endsWith('-return.pdf'),
    );

    if (returnFiles.length === 0) {
        throw new Error(
            'The walkthrough did not produce an Election Return PDF.',
        );
    }

    returnFiles.forEach((filename) => {
        artifacts.push({
            type: 'election_return',
            title: 'Election Return',
            relativePath: path.posix.join('07-election-return', filename),
            required: true,
        });
    });

    for (const artifact of staticArtifacts.slice(1)) {
        const sourcePath = path.join(runPath, artifact.relativePath);

        if (await exists(sourcePath)) {
            artifacts.push(artifact);
        } else if (artifact.required) {
            throw new Error(
                `The walkthrough did not produce required printable artifact [${artifact.relativePath}].`,
            );
        }
    }

    return artifacts;
}

async function publicSimulationCandidateRunPaths(publicRoot) {
    const runDirectory = path.join(publicRoot, 'runs');
    const names = await directories(runDirectory);

    return names
        .reverse()
        .map((name) => path.join(runDirectory, name));
}

function publicSimulationPrintFormDefinition(type, title, relativePath) {
    return {
        type,
        title,
        relativePath,
        required: false,
    };
}

export async function discoverPublicSimulationPrintedArtifacts(publicRoot) {
    for (const runPath of await publicSimulationCandidateRunPaths(publicRoot)) {
        const definitions = [];
        const ballotDirectory = path.join(runPath, '04-voting', 'ballots');
        const ballotFiles = await pdfFiles(ballotDirectory);

        ballotFiles.forEach((filename, index) => {
            definitions.push({
                type: 'ballot',
                title: `Public simulation printed ballot ${index + 1}`,
                relativePath: path.posix.join(
                    '04-voting',
                    'ballots',
                    filename,
                ),
                required: true,
            });
        });

        const ballotPrintFormRoot = path.join(
            runPath,
            '04-voting',
            'print-forms',
            'ballots',
        );
        const ballotPrintFormDirectories =
            await directories(ballotPrintFormRoot);

        for (const ballotDirectoryName of ballotPrintFormDirectories) {
            for (const filename of await pdfFiles(
                path.join(ballotPrintFormRoot, ballotDirectoryName),
            )) {
                definitions.push(
                    publicSimulationPrintFormDefinition(
                        'ballot',
                        `Public simulation ballot ${filename.replace('.pdf', '')} print form`,
                        path.posix.join(
                            '04-voting',
                            'print-forms',
                            'ballots',
                            ballotDirectoryName,
                            filename,
                        ),
                    ),
                );
            }
        }

        const tallyPath = path.join(
            runPath,
            '06-counting-and-tally',
            'tally-sheet.pdf',
        );

        if (await exists(tallyPath)) {
            definitions.push({
                type: 'tally_sheet',
                title: 'Public simulation tally sheet',
                relativePath: '06-counting-and-tally/tally-sheet.pdf',
                required: true,
            });
        }

        for (const filename of await pdfFiles(
            path.join(
                runPath,
                '06-counting-and-tally',
                'print-forms',
                'tally-sheet',
            ),
        )) {
            definitions.push(
                publicSimulationPrintFormDefinition(
                    'tally_sheet',
                    `Public simulation tally sheet ${filename.replace('.pdf', '')} print form`,
                    path.posix.join(
                        '06-counting-and-tally',
                        'print-forms',
                        'tally-sheet',
                        filename,
                    ),
                ),
            );
        }

        const returnDirectory = path.join(runPath, '07-election-return');
        const returnFiles = (await pdfFiles(returnDirectory)).filter(
            (filename) => filename.endsWith('-return.pdf'),
        );

        returnFiles.forEach((filename) => {
            definitions.push({
                type: 'election_return',
                title: 'Public simulation Election Return',
                relativePath: path.posix.join(
                    '07-election-return',
                    filename,
                ),
                required: true,
            });
        });

        const returnPrintFormRoot = path.join(
            runPath,
            '07-election-return',
            'print-forms',
        );
        const returnPrintFormDirectories =
            await directories(returnPrintFormRoot);

        for (const precinctDirectory of returnPrintFormDirectories) {
            for (const filename of await pdfFiles(
                path.join(returnPrintFormRoot, precinctDirectory),
            )) {
                definitions.push(
                    publicSimulationPrintFormDefinition(
                        'election_return',
                        `Public simulation Election Return ${filename.replace('.pdf', '')} print form`,
                        path.posix.join(
                            '07-election-return',
                            'print-forms',
                            precinctDirectory,
                            filename,
                        ),
                    ),
                );
            }
        }

        if (
            definitions.some((artifact) => artifact.type === 'ballot') &&
            definitions.some((artifact) => artifact.type === 'tally_sheet') &&
            definitions.some((artifact) => artifact.type === 'election_return')
        ) {
            return { runPath, definitions };
        }
    }

    throw new Error(
        `No completed public simulation run with ballot, tally sheet, and Election Return PDFs was found under [${publicRoot}].`,
    );
}

async function resolveGhostscript(configuredBinary) {
    const candidates = configuredBinary.includes(path.sep)
        ? [configuredBinary]
        : [
              configuredBinary,
              `/opt/homebrew/bin/${configuredBinary}`,
              `/usr/local/bin/${configuredBinary}`,
              `/usr/bin/${configuredBinary}`,
          ];

    for (const candidate of [...new Set(candidates)]) {
        try {
            await executeFile(candidate, ['--version'], {
                timeout: 10_000,
            });

            return candidate;
        } catch {
            // Try the next standard executable location.
        }
    }

    throw new Error(
        `Ghostscript executable [${configuredBinary}] is unavailable; printed artifacts cannot be rendered for review.`,
    );
}

function artifactSlug(artifact, index) {
    const base = path
        .basename(artifact.relativePath, '.pdf')
        .toLowerCase()
        .replaceAll(/[^a-z0-9]+/g, '-')
        .replaceAll(/^-|-$/g, '');

    return `${String(index + 1).padStart(2, '0')}-${artifact.type}-${base}`;
}

async function renderPdf(binary, sourcePath, outputTemplate) {
    await executeFile(
        binary,
        [
            '-q',
            '-dSAFER',
            '-dBATCH',
            '-dNOPAUSE',
            '-sDEVICE=png16m',
            '-r144',
            `-sOutputFile=${outputTemplate}`,
            sourcePath,
        ],
        {
            maxBuffer: 10 * 1024 * 1024,
            timeout: 120_000,
        },
    );
}

export async function renderPrintedArtifacts({
    runPath,
    artifactDirectory,
    ghostscriptBinary = 'gs',
    definitions = null,
    outputDirectoryName = 'printed-artifacts',
}) {
    definitions ??= await discoverPrintedArtifacts(runPath);
    const binary = await resolveGhostscript(ghostscriptBinary);
    const outputDirectory = path.join(artifactDirectory, outputDirectoryName);

    await mkdir(outputDirectory, { recursive: true });

    const documents = [];

    for (const [index, definition] of definitions.entries()) {
        const sourcePath = path.join(runPath, definition.relativePath);
        const sourceContents = await readFile(sourcePath);
        const sourceStat = await stat(sourcePath);
        const slug = artifactSlug(definition, index);
        const outputTemplate = path.join(
            outputDirectory,
            `${slug}-page-%02d.png`,
        );

        await renderPdf(binary, sourcePath, outputTemplate);

        const pageFiles = (await readdir(outputDirectory))
            .filter(
                (filename) =>
                    filename.startsWith(`${slug}-page-`) &&
                    filename.endsWith('.png'),
            )
            .sort((left, right) => left.localeCompare(right));

        if (pageFiles.length === 0) {
            throw new Error(
                `Ghostscript rendered no pages for [${definition.relativePath}].`,
            );
        }

        documents.push({
            ...definition,
            sourcePath,
            bytes: sourceStat.size,
            sha256: createHash('sha256').update(sourceContents).digest('hex'),
            pages: pageFiles.map((filename, pageIndex) => ({
                page: pageIndex + 1,
                pageCount: pageFiles.length,
                imagePath: path.join(outputDirectory, filename),
            })),
        });
    }

    return documents;
}
