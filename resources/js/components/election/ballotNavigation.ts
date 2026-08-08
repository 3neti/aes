import type {
    BallotLetterJump,
    Candidate,
    Contest,
} from '@/components/election/types';

export function candidateName(contest: Contest, candidateId: string): string {
    return (
        contest.candidates.find((candidate) => candidate.id === candidateId)
            ?.name ?? candidateId
    );
}

export function contestShortLabel(contest: Contest): string {
    const title = `${contest.office ?? ''} ${contest.title}`.toUpperCase();

    if (title.includes('SENATOR')) {
        return 'Sen.';
    }

    if (title.includes('PARTY LIST')) {
        return 'Party List';
    }

    if (title.includes('REPRESENTATIVE') || title.includes('HOUSE')) {
        return 'Rep.';
    }

    if (title.includes('VICE-MAYOR') || title.includes('VICE MAYOR')) {
        return 'Vice-Mayor';
    }

    if (title.includes('MAYOR')) {
        return 'Mayor';
    }

    if (title.includes('COUNCILOR')) {
        return 'Councilors';
    }

    return contest.title.split(/[-(]/)[0]?.trim() || contest.id;
}

export function contestAnchor(contestId: string): string {
    return `contest-${stableDomId(contestId)}`;
}

export function candidateAnchor(
    contestId: string,
    candidateId: string,
): string {
    return `candidate-${stableDomId(contestId)}-${stableDomId(candidateId)}`;
}

export function letterIndex(contest: Contest): BallotLetterJump[] {
    if (contest.candidates.length < 20) {
        return [];
    }

    const seen = new Set<string>();

    return contest.candidates.reduce(
        (letters: BallotLetterJump[], candidate) => {
            const letter = candidateLetter(contest, candidate);

            if (letter === '' || seen.has(letter)) {
                return letters;
            }

            seen.add(letter);
            letters.push({ letter, candidateId: candidate.id });

            return letters;
        },
        [],
    );
}

export function letterNavigationLabel(contest: Contest): string {
    const title = `${contest.office ?? ''} ${contest.title}`.toUpperCase();

    if (title.includes('SENATOR')) {
        return 'Senator surname jump';
    }

    if (isPartyListContest(contest)) {
        return 'Party-list name jump';
    }

    return `${contestShortLabel(contest)} name jump`;
}

function candidateLetter(contest: Contest, candidate: Candidate): string {
    const key = candidateIndexKey(contest, candidate);
    const match = key.match(/[A-Z0-9]/);

    return match?.[0] ?? '';
}

function candidateIndexKey(contest: Contest, candidate: Candidate): string {
    const name = (candidate.name || candidate.full_name || '').trim();

    if (isPartyListContest(contest)) {
        return name.toUpperCase();
    }

    if (name.includes(',')) {
        return name.split(',')[0].trim().toUpperCase();
    }

    const words = name.split(/\s+/).filter(Boolean);

    return (words.at(-1) ?? name).toUpperCase();
}

function isPartyListContest(contest: Contest): boolean {
    return `${contest.office ?? ''} ${contest.title}`
        .toUpperCase()
        .includes('PARTY LIST');
}

function stableDomId(value: string): string {
    return (
        value
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-|-$/g, '') || 'item'
    );
}
