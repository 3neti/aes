export type CandidateCodeMapEntry = {
    code: string;
    contest_id: string;
    candidate_id: string;
};

export type DecodedBallotPayload = {
    ballot_id: string;
    election_id: string;
    precinct_id: string;
    ballot_style_id: string;
    mapping_hash: string;
    tabulation_profile: string;
    paper_ballot_serial: string | null;
    payload_hash: string;
    canonical_payload: string;
    document_profile?: Record<string, string> | null;
    selections: Record<string, string[]>;
};

export type ErEnvelopeMetadata =
    | {
          kind: 'complete';
          totalParts: 1;
      }
    | {
          kind: 'fragment';
          groupId: string;
          partNumber: number;
          totalParts: number;
      }
    | {
          kind: 'unknown';
      };

export function decodeBallotTruthPayload(
    payload: string,
    candidates: Record<string, CandidateCodeMapEntry>,
): { ok: true; payload: DecodedBallotPayload } | { ok: false; reason: string } {
    if (!payload.startsWith('truth://')) {
        return { ok: false, reason: 'Payload is not a truth:// URI.' };
    }

    try {
        const canonicalPayload = unwrapTruthEnvelope(payload);

        if (!canonicalPayload.startsWith('aes-ballot-compact-1:')) {
            return {
                ok: false,
                reason: 'Unsupported ballot payload version.',
            };
        }

        return {
            ok: true,
            payload: decodeCompactBallotPayload(canonicalPayload, candidates),
        };
    } catch (error) {
        return {
            ok: false,
            reason:
                error instanceof Error
                    ? error.message
                    : 'Ballot QR payload is malformed.',
        };
    }
}

export function parseElectionReturnEnvelopeMetadata(
    payload: string,
): ErEnvelopeMetadata {
    try {
        const url = new URL(payload);
        const segments = url.pathname.split('/').filter(Boolean);

        if (
            url.protocol !== 'truth:' ||
            url.hostname !== 'v1' ||
            segments[0] !== 'waes-election-return'
        ) {
            return { kind: 'unknown' };
        }

        if (segments[1] === 'waes-er-compact-1') {
            return {
                kind: 'complete',
                totalParts: 1,
            };
        }

        if (segments[1] !== 'waes-er-fragment-1') {
            return { kind: 'unknown' };
        }

        const partNumber = Number.parseInt(segments[2] ?? '', 10);
        const totalParts = Number.parseInt(segments[3] ?? '', 10);
        const groupId = url.searchParams.get('h') ?? '';

        if (
            !Number.isInteger(partNumber) ||
            !Number.isInteger(totalParts) ||
            partNumber < 1 ||
            totalParts < 2 ||
            partNumber > totalParts ||
            groupId === ''
        ) {
            return { kind: 'unknown' };
        }

        return {
            kind: 'fragment',
            groupId,
            partNumber,
            totalParts,
        };
    } catch {
        return { kind: 'unknown' };
    }
}

function unwrapTruthEnvelope(payload: string): string {
    const url = new URL(payload);

    if (url.protocol !== 'truth:') {
        throw new Error('Ballot payload envelope has an unsupported scheme.');
    }

    if (url.hostname !== 'v1') {
        throw new Error('Ballot payload envelope has an unsupported version.');
    }

    const segments = url.pathname.replace(/^\/+|\/+$/g, '').split('/');

    if (
        segments.length !== 2 ||
        !['waes-ballot', 'vaes-ballot'].includes(segments[0] ?? '') ||
        segments[1] !== 'aes-ballot-compact-1'
    ) {
        throw new Error('Ballot payload envelope has an unsupported payload type.');
    }

    const encodedPayload = url.searchParams.get('p');

    if (!encodedPayload) {
        throw new Error('Ballot payload envelope is missing its ballot payload.');
    }

    return base64UrlDecode(encodedPayload);
}

function base64UrlDecode(value: string): string {
    const paddedValue =
        value + '='.repeat((4 - (value.length % 4 || 4)) % 4);

    return window.atob(paddedValue.replace(/-/g, '+').replace(/_/g, '/'));
}

function decodeCompactBallotPayload(
    canonicalPayload: string,
    candidates: Record<string, CandidateCodeMapEntry>,
): DecodedBallotPayload {
    const material = canonicalPayload.replace(/^aes-ballot-compact-1:/, '');
    const parts = material.split('|');

    if (![8, 12].includes(parts.length) || parts[0] !== 'AES2') {
        throw new Error('Compact ballot QR payload is malformed.');
    }

    const candidateCodeIndex = parts.length === 12 ? 11 : 7;
    const candidateCodes =
        parts[candidateCodeIndex] === ''
            ? []
            : parts[candidateCodeIndex]
                  .split(',')
                  .filter((code) => code !== '');
    const documentProfile =
        parts.length === 12
            ? {
                  type: 'official-ballot',
                  id: decodeURIComponent(parts[7] ?? ''),
                  hash: decodeURIComponent(parts[8] ?? ''),
                  asset_bundle_id: decodeURIComponent(parts[9] ?? ''),
                  asset_bundle_hash: decodeURIComponent(parts[10] ?? ''),
              }
            : null;
    const selections = selectionsForCandidateCodes(candidateCodes, candidates);
    const payloadHash = `compact-${simpleHash(canonicalPayload)}`;

    return {
        ballot_id: payloadHash,
        election_id: decodeURIComponent(parts[1] ?? ''),
        precinct_id: decodeURIComponent(parts[2] ?? ''),
        ballot_style_id: decodeURIComponent(parts[3] ?? ''),
        mapping_hash: decodeURIComponent(parts[4] ?? ''),
        tabulation_profile: decodeURIComponent(parts[5] ?? ''),
        paper_ballot_serial:
            decodeURIComponent(parts[6] ?? '') === ''
                ? null
                : decodeURIComponent(parts[6] ?? ''),
        payload_hash: payloadHash,
        canonical_payload: canonicalPayload,
        document_profile: documentProfile,
        selections,
    };
}

function selectionsForCandidateCodes(
    candidateCodes: string[],
    candidates: Record<string, CandidateCodeMapEntry>,
): Record<string, string[]> {
    const selections: Record<string, string[]> = {};

    candidateCodes.forEach((code) => {
        const candidate = candidates[code];

        if (!candidate) {
            throw new Error(`Candidate code ${code} is not in this precinct mapping.`);
        }

        selections[candidate.contest_id] ??= [];
        selections[candidate.contest_id].push(candidate.candidate_id);
    });

    return selections;
}

function simpleHash(value: string): string {
    let hash = 0x811c9dc5;

    for (let index = 0; index < value.length; index += 1) {
        hash ^= value.charCodeAt(index);
        hash = Math.imul(hash, 0x01000193);
    }

    return (hash >>> 0).toString(16).padStart(8, '0');
}
