<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import TallyBoard from '@/components/election/TallyBoard.vue';

type Tally = Record<string, Record<string, number>>;

type Contest = {
    id: string;
    title: string;
    max_selections: number;
    candidates: Array<{
        id: string;
        name: string;
    }>;
};

type ReturnScan = {
    sequence: number;
    precinct_id: string;
    return_hash: string;
    accepted_ballots: number;
    rejected_ballots: number;
    tally: Tally;
};

type ScannerState = {
    revision: number;
    accepted_return_hashes: string[];
    accepted_returns?: ReturnScan[];
    latest_accepted_return_hash?: string | null;
    latest_message?: string | null;
    latest_status?: string | null;
};

const props = defineProps<{
    simulation: {
        configuration: {
            election_id: string | null;
            city_municipality: string | null;
            province: string | null;
        };
        ballot: {
            contests: Contest[];
        };
        run: {
            return_count?: number;
            total_ballots?: number;
            return_qr_count?: number;
            generated_at?: string;
        } | null;
        scanner: {
            returns: ReturnScan[];
            initial_tally: Tally;
        };
    };
    view: string;
    actions: {
        scannerState: string;
        simulatorTick: string;
        operatorBoard: string;
        publicBoard: string;
    };
}>();

const stationId = 'canvassing-demo-city';
const scannerState = ref<ScannerState>({
    revision: 0,
    accepted_return_hashes: [],
});
const statePoller = ref<number | null>(null);
const simulatorPoller = ref<number | null>(null);
const simulatorRunning = ref(false);
const simulatorBusy = ref(false);
const simulatorMessage = ref('Simulator idle.');
const lastUpdatedAt = ref<string | null>(null);

const activeViewTokens = computed(() =>
    props.view
        .split(',')
        .map((token) => token.trim().toLowerCase())
        .filter(Boolean),
);
const activeViewLabel = computed(() =>
    activeViewTokens.value.length === 0 ||
    activeViewTokens.value.includes('all')
        ? 'All contests'
        : activeViewTokens.value
              .map(
                  (token) =>
                      viewOptions.find((option) => option.value === token)
                          ?.label ?? token,
              )
              .join(' + '),
);
const filteredContests = computed(() => {
    if (
        activeViewTokens.value.length === 0 ||
        activeViewTokens.value.includes('all')
    ) {
        return props.simulation.ballot.contests;
    }

    return props.simulation.ballot.contests.filter((contest) =>
        activeViewTokens.value.some((token) =>
            contestMatchesView(contest, token),
        ),
    );
});
const acceptedReturns = computed(() => {
    if ((scannerState.value.accepted_returns ?? []).length > 0) {
        return scannerState.value.accepted_returns ?? [];
    }

    const acceptedHashes = new Set(scannerState.value.accepted_return_hashes);

    return props.simulation.scanner.returns.filter((scannedReturn) =>
        acceptedHashes.has(scannedReturn.return_hash),
    );
});
const runningTally = computed(() => {
    const tally = cloneTally(props.simulation.scanner.initial_tally);

    acceptedReturns.value.forEach((scannedReturn) => {
        addTally(tally, scannedReturn.tally);
    });

    return tally;
});
const acceptedBallots = computed(() =>
    acceptedReturns.value.reduce(
        (total, scannedReturn) => total + scannedReturn.accepted_ballots,
        0,
    ),
);
const latestReturn = computed(
    () =>
        [...acceptedReturns.value].reverse()[0] ??
        props.simulation.scanner.returns.find(
            (scannedReturn) =>
                scannedReturn.return_hash ===
                scannerState.value.latest_accepted_return_hash,
        ) ??
        null,
);
const hasGeneratedReturns = computed(
    () =>
        props.simulation.run !== null &&
        props.simulation.scanner.returns.length > 0,
);
const simulatorButtonLabel = computed(() =>
    simulatorRunning.value ? 'Stop simulator' : 'Start simulator',
);

const viewOptions = [
    { value: 'all', label: 'All' },
    { value: 'national', label: 'National' },
    { value: 'local', label: 'Local' },
    { value: 'president', label: 'President' },
    { value: 'vice-president', label: 'Vice President' },
    { value: 'senator', label: 'Senator' },
    { value: 'party-list', label: 'Party List' },
];

function publicBoardUrl(view: string): string {
    const url = new URL(props.actions.publicBoard, window.location.origin);
    url.searchParams.set('view', view);

    return url.toString();
}

function contestMatchesView(contest: Contest, token: string): boolean {
    if (token === 'national') {
        return (
            contest.id.includes('philippines') ||
            contest.id.includes('party_list')
        );
    }

    if (token === 'local') {
        return !contestMatchesView(contest, 'national');
    }

    return contest.id.replaceAll('_', '-').includes(token);
}

function csrfToken(): string | null {
    return (
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? null
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
            scannerState.value = state;
            lastUpdatedAt.value = new Date().toLocaleTimeString();
        }
    } catch {
        simulatorMessage.value =
            'Live scanner state is temporarily unavailable.';
    }
}

async function simulatorTick(): Promise<void> {
    if (simulatorBusy.value || !hasGeneratedReturns.value) {
        return;
    }

    simulatorBusy.value = true;

    try {
        const token = csrfToken();
        const response = await fetch(props.actions.simulatorTick, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-CSRF-TOKEN': token } : {}),
            },
            body: JSON.stringify({
                station_id: stationId,
            }),
        });
        const result = await response.json();

        if (response.ok) {
            scannerState.value = result.state;
            simulatorMessage.value = result.message;
            lastUpdatedAt.value = new Date().toLocaleTimeString();

            if (result.completed) {
                stopSimulator();
            }
        } else {
            simulatorMessage.value =
                result?.message ?? 'Simulator did not record a scan.';
            stopSimulator();
        }
    } catch {
        simulatorMessage.value = 'Simulator stopped before the next scan.';
        stopSimulator();
    } finally {
        simulatorBusy.value = false;
    }
}

function toggleSimulator(): void {
    if (simulatorRunning.value) {
        stopSimulator();

        return;
    }

    simulatorRunning.value = true;
    simulatorMessage.value = 'Simulator running.';
    void simulatorTick();
    simulatorPoller.value = window.setInterval(() => {
        void simulatorTick();
    }, 850);
}

function stopSimulator(): void {
    if (simulatorPoller.value !== null) {
        window.clearInterval(simulatorPoller.value);
    }

    simulatorPoller.value = null;
    simulatorRunning.value = false;
}

function cloneTally(tally: Tally): Tally {
    return Object.fromEntries(
        Object.entries(tally).map(([contestId, candidates]) => [
            contestId,
            { ...candidates },
        ]),
    );
}

function addTally(target: Tally, source: Tally): void {
    Object.entries(source).forEach(([contestId, candidateTotals]) => {
        target[contestId] ??= {};

        Object.entries(candidateTotals).forEach(([candidateId, votes]) => {
            target[contestId][candidateId] =
                (target[contestId][candidateId] ?? 0) + votes;
        });
    });
}

onMounted(() => {
    void fetchScannerState();
    statePoller.value = window.setInterval(() => {
        void fetchScannerState();
    }, 1000);
});

onBeforeUnmount(() => {
    stopSimulator();

    if (statePoller.value !== null) {
        window.clearInterval(statePoller.value);
    }
});
</script>

<template>
    <Head title="Public Canvass Board" />

    <main class="min-h-screen bg-stone-100 text-stone-950">
        <div class="grid h-1.5 grid-cols-3">
            <span class="bg-blue-800" /><span class="bg-yellow-400" /><span
                class="bg-red-700"
            />
        </div>

        <section class="mx-auto grid max-w-[1800px] gap-4 px-4 py-4">
            <header class="border border-stone-300 bg-white p-4">
                <div
                    class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between"
                >
                    <div>
                        <p
                            class="text-xs font-bold tracking-wide text-blue-800 uppercase"
                        >
                            Public Canvass Board
                        </p>
                        <h1 class="mt-1 text-3xl font-black">
                            {{
                                simulation.configuration.city_municipality ??
                                'City/Municipality canvass'
                            }}
                        </h1>
                        <p class="mt-1 text-sm text-stone-600">
                            View: {{ activeViewLabel }} · Source: accepted WAES
                            ER QR payloads
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <a
                            v-for="option in viewOptions"
                            :key="option.value"
                            :href="publicBoardUrl(option.value)"
                            class="border px-3 py-2 text-xs font-black"
                            :class="
                                activeViewTokens.includes(option.value) ||
                                (option.value === 'all' &&
                                    (activeViewTokens.length === 0 ||
                                        activeViewTokens.includes('all')))
                                    ? 'border-blue-800 bg-blue-800 text-white'
                                    : 'border-stone-300 bg-white text-stone-700'
                            "
                        >
                            {{ option.label }}
                        </a>
                    </div>
                </div>
            </header>

            <section
                class="grid gap-3 md:grid-cols-2 xl:grid-cols-[repeat(5,minmax(0,1fr))]"
            >
                <div class="border border-stone-300 bg-white p-3">
                    <p class="text-xs font-bold text-stone-600">ERs Received</p>
                    <p class="mt-1 text-3xl font-black">
                        {{ acceptedReturns.length }}
                    </p>
                    <p class="text-xs text-stone-500">
                        of {{ simulation.run?.return_count ?? 0 }} generated
                    </p>
                </div>
                <div class="border border-stone-300 bg-white p-3">
                    <p class="text-xs font-bold text-stone-600">
                        Ballots Represented
                    </p>
                    <p class="mt-1 text-3xl font-black">
                        {{ acceptedBallots }}
                    </p>
                    <p class="text-xs text-stone-500">scanner-derived totals</p>
                </div>
                <div class="border border-stone-300 bg-white p-3">
                    <p class="text-xs font-bold text-stone-600">Latest ER</p>
                    <p class="mt-1 truncate text-lg font-black">
                        {{ latestReturn?.precinct_id ?? 'None yet' }}
                    </p>
                    <p class="truncate font-mono text-xs text-stone-500">
                        {{ latestReturn?.return_hash ?? 'Waiting for scan' }}
                    </p>
                </div>
                <div class="border border-stone-300 bg-white p-3">
                    <p class="text-xs font-bold text-stone-600">Last Updated</p>
                    <p class="mt-1 text-2xl font-black">
                        {{ lastUpdatedAt ?? 'Waiting' }}
                    </p>
                    <p class="text-xs text-stone-500">
                        {{ scannerState.latest_message ?? 'Ready' }}
                    </p>
                </div>
                <div
                    class="border border-stone-300 bg-stone-950 p-3 text-white"
                >
                    <p class="text-xs font-bold text-stone-300">
                        Demo Simulator
                    </p>
                    <button
                        type="button"
                        class="mt-2 min-h-10 w-full bg-yellow-300 px-3 font-black text-stone-950 disabled:cursor-not-allowed disabled:bg-stone-700 disabled:text-stone-400"
                        :disabled="!hasGeneratedReturns"
                        @click="toggleSimulator"
                    >
                        {{ simulatorButtonLabel }}
                    </button>
                    <p class="mt-2 text-xs text-stone-300">
                        {{ simulatorMessage }}
                    </p>
                </div>
            </section>

            <TallyBoard
                eyebrow="Public live tally"
                title="Scanner-derived ER totals"
                :accepted-count="acceptedBallots"
                accepted-label="accepted ballots canvassed"
                :contests="filteredContests"
                :tally="runningTally"
                :flash-key="scannerState.revision"
                enable-candidate-sort
            >
                <template #stats>
                    <p class="mt-2 font-bold">
                        {{ acceptedReturns.length }} ERs accepted
                    </p>
                    <p class="text-stone-600">
                        {{ filteredContests.length }} contests shown
                    </p>
                </template>
            </TallyBoard>
        </section>
    </main>
</template>
