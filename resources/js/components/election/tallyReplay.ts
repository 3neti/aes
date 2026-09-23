export type Tally = Record<string, Record<string, number>>;

export type TallyDelta = Record<
    string,
    Record<
        string,
        {
            previousTotal: number;
            addedVotes: number;
            finalTotal: number;
        }
    >
>;

export type TallyReplayBallot = {
    this_ballot_tally: Tally;
};

export function cumulativeTallyThroughBallot(
    ballots: TallyReplayBallot[],
    selectedBallotIndex: number,
): Tally {
    if (selectedBallotIndex < 0) {
        return {};
    }

    return ballots
        .slice(0, selectedBallotIndex + 1)
        .reduce<Tally>((tally, ballot) => {
            addTallyInto(tally, ballot.this_ballot_tally);

            return tally;
        }, {});
}

export function deltaForReplayBallot(
    ballots: TallyReplayBallot[],
    selectedBallotIndex: number,
): TallyDelta {
    const selectedBallot = ballots[selectedBallotIndex];

    if (!selectedBallot) {
        return {};
    }

    const previousTally = cumulativeTallyThroughBallot(
        ballots,
        selectedBallotIndex - 1,
    );
    const selectedTally = selectedBallot.this_ballot_tally;
    const delta: TallyDelta = {};

    Object.entries(selectedTally).forEach(([contestId, candidateVotes]) => {
        Object.entries(candidateVotes).forEach(([candidateId, addedVotes]) => {
            if (addedVotes < 1) {
                return;
            }

            const previousTotal = previousTally[contestId]?.[candidateId] ?? 0;

            delta[contestId] ??= {};
            delta[contestId][candidateId] = {
                previousTotal,
                addedVotes,
                finalTotal: previousTotal + addedVotes,
            };
        });
    });

    return delta;
}

function addTallyInto(target: Tally, source: Tally): void {
    Object.entries(source).forEach(([contestId, candidateVotes]) => {
        target[contestId] ??= {};

        Object.entries(candidateVotes).forEach(([candidateId, votes]) => {
            if (votes < 1) {
                return;
            }

            target[contestId][candidateId] =
                (target[contestId][candidateId] ?? 0) + votes;
        });
    });
}
