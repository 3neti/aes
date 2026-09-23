<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import LiveDocumentView from '@/components/election/LiveDocumentView.vue';
import PrecinctTallyBoard from '@/components/election/PrecinctTallyBoard.vue';

type Tally = Record<string, Record<string, number>>;

type TallyDelta = Record<
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

type Contest = {
    id: string;
    title: string;
    max_selections: number;
    candidates: Array<{
        id: string;
        name: string;
    }>;
};

type ScannerBallot = {
    type?: 'official-ballot';
    sequence: number;
    source: string;
    ballot_id: string;
    precinct_id: string;
    paper_ballot_serial: string | number | null;
    payload: string;
    canonical_payload: string;
    payload_hash: string;
    pdf_available?: boolean;
    pdf_url?: string | null;
    document_profile?: Record<string, string> | null;
    selections: Record<string, string[]>;
    this_ballot_tally: Tally;
};

type LedgerDocument = {
    id: string;
    title: string;
    subtitle?: string | null;
    meta?: string | null;
    hash?: string | null;
    kind: 'official-ballot';
    ballot: ScannerBallot & {
        type: 'official-ballot';
    };
};

type RenderingKit = {
    profiles: Record<string, Record<string, unknown>>;
    asset_bundle: {
        id: string;
        hash: string;
        assets: Record<string, Record<string, string | null>>;
    };
};

type ScannerState = {
    station_id: string;
    revision: number;
    accepted_ballot_hashes: string[];
    latest_accepted_ballot_hash?: string | null;
    accepted_count: number;
    tally: Tally;
    accepted_ballots: ScannerBallot[];
    latest_message?: string | null;
    latest_status?: string | null;
};

const props = defineProps<{
    precinct: {
        code: string;
        label: string;
        clustered_precinct: string;
        city_municipality: string | null;
        province: string | null;
        status: string;
    };
    simulation: {
        ballot: {
            contests: Contest[];
        };
        document_rendering: RenderingKit;
    };
    scannerState: ScannerState;
    view: string;
    actions: {
        scannerState: string;
        simulatorTick: string;
        operatorBoard: string;
        publicBoard: string;
    };
}>();

const stationId = 'role-demo-precinct';
const scannerState = ref<ScannerState>({ ...props.scannerState });
const statePoller = ref<number | null>(null);
const lastUpdatedAt = ref<string | null>(null);
const scannedBallots = computed(() => scannerState.value.accepted_ballots);
const lastBallot = computed(
    () => scannedBallots.value[scannedBallots.value.length - 1] ?? null,
);
const latestAcceptedBallot = computed(
    () =>
        scannedBallots.value.find(
            (ballot) =>
                ballot.payload_hash ===
                scannerState.value.latest_accepted_ballot_hash,
        ) ?? lastBallot.value,
);
const latestScannedDocument = computed<LedgerDocument | null>(() =>
    latestAcceptedBallot.value
        ? ballotLedgerDocument(latestAcceptedBallot.value)
        : null,
);
const lastScanDelta = computed<TallyDelta>(() =>
    latestAcceptedBallot.value
        ? deltaForTally(latestAcceptedBallot.value.this_ballot_tally)
        : {},
);

function ballotLedgerDocument(ballot: ScannerBallot): LedgerDocument {
    return {
        id: ballot.payload_hash,
        title: `Ballot ${ballot.sequence}`,
        subtitle: String(ballot.paper_ballot_serial ?? ''),
        meta: ballot.precinct_id,
        hash: ballot.payload_hash,
        kind: 'official-ballot',
        ballot: {
            ...ballot,
            type: 'official-ballot',
        },
    };
}

function deltaForTally(tally: Tally): TallyDelta {
    const delta: TallyDelta = {};

    Object.entries(tally).forEach(([contestId, candidateVotes]) => {
        Object.entries(candidateVotes).forEach(([candidateId, addedVotes]) => {
            if (addedVotes < 1) {
                return;
            }

            const currentTotal =
                scannerState.value.tally[contestId]?.[candidateId] ?? 0;

            delta[contestId] ??= {};
            delta[contestId][candidateId] = {
                previousTotal: Math.max(0, currentTotal - addedVotes),
                addedVotes,
                finalTotal: currentTotal,
            };
        });
    });

    return delta;
}

async function fetchScannerState(): Promise<void> {
    try {
        const url = new URL(props.actions.scannerState, window.location.origin);
        url.searchParams.set('station_id', stationId);
        const response = await fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const state = await response.json();

        if (response.ok && state.revision !== scannerState.value.revision) {
            scannerState.value = state;
            lastUpdatedAt.value = new Date().toLocaleTimeString();
        }
    } catch {
        // Keep showing the last known tally if the polling request misses a beat.
    }
}

onMounted(() => {
    void fetchScannerState();
    statePoller.value = window.setInterval(() => {
        if (document.visibilityState === 'hidden') {
            return;
        }

        void fetchScannerState();
    }, 2000);
});

onBeforeUnmount(() => {
    if (statePoller.value !== null) {
        window.clearInterval(statePoller.value);
    }
});
</script>

<template>
    <Head :title="`${precinct.code} public precinct tally`" />

    <main class="min-h-screen bg-stone-100 text-stone-950">
        <div class="grid h-1.5 grid-cols-3">
            <span class="bg-blue-800" /><span class="bg-yellow-400" /><span
                class="bg-red-700"
            />
        </div>

        <section class="mx-auto max-w-[1800px] px-3 py-4">
            <div
                class="mb-3 flex flex-col gap-3 border border-stone-300 bg-white p-4 lg:flex-row lg:items-end lg:justify-between"
            >
                <div>
                    <Link
                        :href="actions.operatorBoard"
                        class="text-sm font-bold text-blue-800"
                    >
                        Precinct tally scanner
                    </Link>
                    <p class="mt-3 text-sm font-bold text-amber-800">
                        PUBLIC PRECINCT TALLY
                    </p>
                    <h1 class="mt-1 text-2xl font-bold">
                        {{ precinct.label }}
                    </h1>
                    <p class="mt-1 text-sm text-stone-600">
                        {{
                            scannerState.latest_message ?? 'Waiting for scans.'
                        }}
                    </p>
                </div>
            </div>

            <section class="grid gap-3 xl:grid-cols-2">
                <LiveDocumentView
                    :key="latestScannedDocument?.id ?? 'empty-ballot-preview'"
                    title="Live Ballot View"
                    eyebrow="Latest accepted ballot"
                    :document="latestScannedDocument"
                    :contests="simulation.ballot.contests"
                    :rendering-kit="simulation.document_rendering"
                    empty-message="No completed ballot scan yet."
                />

                <PrecinctTallyBoard
                    eyebrow="Timer-updated public board"
                    title="Precinct ballot QR tally"
                    :accepted-count="scannerState.accepted_count"
                    accepted-label="accepted ballot scans"
                    :contests="simulation.ballot.contests"
                    :tally="scannerState.tally"
                    :view="view"
                    :last-scan-delta="lastScanDelta"
                    :flash-key="scannerState.revision"
                    :revision="scannerState.revision"
                    :last-updated-at="lastUpdatedAt"
                    :status-message="
                        scannerState.latest_message ?? 'Waiting for scans.'
                    "
                    :public-board-url="actions.publicBoard"
                />
            </section>
        </section>
    </main>
</template>
