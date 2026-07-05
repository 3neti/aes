export type JournalEntry = {
    sequence: number;
    event_type: string;
    occurred_at: string;
    payload: Record<string, unknown>;
    event_hash: string;
};

export type Candidate = {
    id: string;
    name: string;
    ordinal: number;
};

export type Contest = {
    id: string;
    title: string;
    max_selections: number;
    candidates: Candidate[];
};

export type ElectionSnapshot = {
    appName: string;
    stage: string;
    stageLabel: string;
    ceremony: string;
    nextAction: string;
    nextStage: string | null;
    configuration: {
        election_id?: string;
        precinct_id?: string;
        ballot_style_id?: string;
        mapping_hash?: string;
        contests?: Contest[];
    };
    journal: JournalEntry[];
    counts: {
        accepted: number;
        rejected: number;
        printJobs: number;
        ballots: number;
    };
};
