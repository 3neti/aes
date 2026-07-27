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
    ballot_number?: number;
    full_name?: string;
    political_party?: string | null;
    source_file?: string | null;
    source_page?: number | null;
    candidate_hash?: string | null;
    candidate_image?: {
        status: string;
        type: string | null;
        uri: string | null;
        source: string | null;
        sha256: string | null;
        alt_text: string;
    } | null;
};

export type Contest = {
    id: string;
    title: string;
    office?: string;
    geography?: string | null;
    district?: string | null;
    max_selections: number;
    candidates: Candidate[];
};

export type ReviewOfficerDefault = {
    code: string;
    pin: string;
};

export type ElectionReviewDefaults = {
    primary_officer?: ReviewOfficerDefault;
    chairperson?: ReviewOfficerDefault;
    poll_clerk?: ReviewOfficerDefault;
    third_member?: {
        code: string;
    };
    setup?: {
        chairperson_code: string;
        chairperson_pin: string;
        poll_clerk_code: string;
        poll_clerk_pin: string;
        third_member_code: string;
        device_serial: string;
        printer_serial: string;
        scanner_serial: string;
        ballot_stock_start: number;
        ballot_stock_end: number;
        ballot_box_id: string;
        custody_envelope_id: string;
        seal_numbers: string;
    };
    handoff?: {
        verification_note: string;
    };
};

export type ElectionReviewMode = {
    enabled: boolean;
    label: string | null;
    defaults: ElectionReviewDefaults;
};

export type ElectionReviewRoomStation = {
    id: string;
    role: 'officer' | 'voter' | 'print_station' | 'watcher' | 'presentation';
    role_label: string;
    label: string;
    slot: number;
    status: 'waiting' | 'connected' | 'inactive';
    joined_at: string | null;
    last_seen_at: string | null;
    join_url?: string;
    join_qr_url?: string;
};

export type ElectionReviewRoomEvent = {
    sequence: number;
    event_type: string;
    occurred_at: string;
    payload: Record<string, unknown>;
    previous_hash: string | null;
    event_hash: string;
};

export type ElectionReviewRoom = {
    id: string;
    code: string;
    name: string;
    precinct_id: string | null;
    run_id: string | null;
    status: 'open' | 'closed';
    opened_at: string;
    closed_at: string | null;
    station_count: number;
    connected_station_count: number;
    stations: ElectionReviewRoomStation[];
    events: ElectionReviewRoomEvent[];
};

export type ElectionReviewRoomContext = {
    enabled: boolean;
    station: {
        id: string;
        role: ElectionReviewRoomStation['role'];
        role_label: string;
        label: string;
        room_code: string;
        room_name: string;
        room_status: 'open' | 'closed';
    } | null;
};

export type WorkflowStep = {
    id: string;
    label: string;
    description: string;
    stages: string[];
};

export type ElectionSnapshot = {
    appName: string;
    operatorLabel: string;
    stage: string;
    stageLabel: string;
    ceremony: string;
    nextAction: string;
    nextStage: string | null;
    workflow: WorkflowStep[];
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
        attestations: number;
        transmissions?: number;
        custody_records?: number;
    };
    paperBallots: {
        total_stock: number;
        issued: number;
        printed: number;
        spoiled: number;
        deposited: number;
        unused: number;
        event_count: number;
        balanced: boolean;
    };
};
