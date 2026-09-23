<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import LiveDocumentView from '@/components/election/LiveDocumentView.vue';
import PrecinctTallyBoard from '@/components/election/PrecinctTallyBoard.vue';
import {
    cumulativeTallyThroughBallot,
    deltaForReplayBallot,
    type Tally,
    type TallyDelta,
} from '@/components/election/tallyReplay';

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
const selectedBallotHash = ref<string | null>(
    selectedHashFromState(props.scannerState),
);
const statePoller = ref<number | null>(null);
const lastUpdatedAt = ref<string | null>(null);
const scannedBallots = computed(() => scannerState.value.accepted_ballots);
const selectedBallotIndex = computed(() => {
    if (scannedBallots.value.length === 0) {
        return -1;
    }

    const index = scannedBallots.value.findIndex(
        (ballot) => ballot.payload_hash === selectedBallotHash.value,
    );

    return index >= 0 ? index : scannedBallots.value.length - 1;
});
const selectedBallot = computed(() =>
    selectedBallotIndex.value >= 0
        ? (scannedBallots.value[selectedBallotIndex.value] ?? null)
        : null,
);
const selectedBallotPosition = computed(() =>
    selectedBallotIndex.value >= 0 ? selectedBallotIndex.value + 1 : 0,
);
const canNavigateBallots = computed(() => scannedBallots.value.length > 1);
const canMoveToPreviousBallot = computed(() => selectedBallotIndex.value > 0);
const canMoveToNextBallot = computed(
    () =>
        selectedBallotIndex.value >= 0 &&
        selectedBallotIndex.value < scannedBallots.value.length - 1,
);
const selectedScannedDocument = computed<LedgerDocument | null>(() =>
    selectedBallot.value ? ballotLedgerDocument(selectedBallot.value) : null,
);
const replayTally = computed(() =>
    cumulativeTallyThroughBallot(
        scannedBallots.value,
        selectedBallotIndex.value,
    ),
);
const replayDelta = computed<TallyDelta>(() =>
    deltaForReplayBallot(scannedBallots.value, selectedBallotIndex.value),
);
const replayFlashKey = computed(
    () => selectedBallot.value?.payload_hash ?? scannerState.value.revision,
);
const replayStatusMessage = computed(() =>
    selectedBallotPosition.value > 0
        ? `As of ballot ${selectedBallotPosition.value} of ${scannedBallots.value.length}`
        : 'No ballots selected.',
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

function selectedHashFromState(state: ScannerState): string | null {
    if (state.latest_accepted_ballot_hash) {
        return state.latest_accepted_ballot_hash;
    }

    return (
        state.accepted_ballots[state.accepted_ballots.length - 1]
            ?.payload_hash ?? null
    );
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
            const previousLatestHash =
                scannerState.value.latest_accepted_ballot_hash;
            scannerState.value = state;

            if (
                state.latest_accepted_ballot_hash !== null &&
                state.latest_accepted_ballot_hash !== previousLatestHash
            ) {
                selectedBallotHash.value = selectedHashFromState(state);
            }

            lastUpdatedAt.value = new Date().toLocaleTimeString();
        }
    } catch {
        // Keep showing the last known tally if the polling request misses a beat.
    }
}

function showFirstBallot(): void {
    selectedBallotHash.value = scannedBallots.value[0]?.payload_hash ?? null;
}

function showPreviousBallot(): void {
    if (!canMoveToPreviousBallot.value) {
        return;
    }

    selectedBallotHash.value =
        scannedBallots.value[selectedBallotIndex.value - 1]?.payload_hash ??
        null;
}

function showNextBallot(): void {
    if (!canMoveToNextBallot.value) {
        return;
    }

    selectedBallotHash.value =
        scannedBallots.value[selectedBallotIndex.value + 1]?.payload_hash ??
        null;
}

function showLatestBallot(): void {
    selectedBallotHash.value =
        scannedBallots.value[scannedBallots.value.length - 1]?.payload_hash ??
        null;
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
                <section class="space-y-2">
                    <div
                        class="flex flex-wrap items-center justify-between gap-3 border border-stone-300 bg-white p-3"
                    >
                        <div>
                            <p
                                class="text-xs font-black text-blue-800 uppercase"
                            >
                                Ballot navigation
                            </p>
                            <p class="mt-1 text-sm font-bold text-stone-700">
                                Showing ballot {{ selectedBallotPosition }} of
                                {{ scannedBallots.length }}
                            </p>
                        </div>
                        <div
                            class="grid grid-cols-4 border border-stone-300 text-xs font-black"
                        >
                            <button
                                type="button"
                                class="border-r border-stone-300 px-3 py-2 disabled:cursor-not-allowed disabled:opacity-40"
                                :disabled="!canNavigateBallots"
                                @click="showFirstBallot"
                            >
                                First
                            </button>
                            <button
                                type="button"
                                class="border-r border-stone-300 px-3 py-2 disabled:cursor-not-allowed disabled:opacity-40"
                                :disabled="!canMoveToPreviousBallot"
                                @click="showPreviousBallot"
                            >
                                Previous
                            </button>
                            <button
                                type="button"
                                class="border-r border-stone-300 px-3 py-2 disabled:cursor-not-allowed disabled:opacity-40"
                                :disabled="!canMoveToNextBallot"
                                @click="showNextBallot"
                            >
                                Next
                            </button>
                            <button
                                type="button"
                                class="px-3 py-2 disabled:cursor-not-allowed disabled:opacity-40"
                                :disabled="!canNavigateBallots"
                                @click="showLatestBallot"
                            >
                                Last
                            </button>
                        </div>
                    </div>

                    <LiveDocumentView
                        :key="
                            selectedScannedDocument?.id ??
                            'empty-ballot-preview'
                        "
                        title="Live Ballot View"
                        eyebrow="Selected accepted ballot"
                        :document="selectedScannedDocument"
                        :contests="simulation.ballot.contests"
                        :rendering-kit="simulation.document_rendering"
                        empty-message="No completed ballot scan yet."
                    />
                </section>

                <PrecinctTallyBoard
                    eyebrow="Timer-updated public board"
                    title="Precinct ballot QR tally"
                    :accepted-count="selectedBallotPosition"
                    accepted-label="ballots included"
                    :contests="simulation.ballot.contests"
                    :tally="replayTally"
                    :view="view"
                    :last-scan-delta="replayDelta"
                    :flash-key="replayFlashKey"
                    :revision="scannerState.revision"
                    :last-updated-at="lastUpdatedAt"
                    :status-message="replayStatusMessage"
                    :public-board-url="actions.publicBoard"
                />
            </section>
        </section>
    </main>
</template>
