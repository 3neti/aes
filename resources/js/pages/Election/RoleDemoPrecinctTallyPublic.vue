<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
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

type ScannerState = {
    revision: number;
    accepted_count: number;
    tally: Tally;
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

const viewOptions = [
    { value: 'all', label: 'All' },
    { value: 'national', label: 'National' },
    { value: 'local', label: 'Local' },
    { value: 'president', label: 'President' },
    { value: 'vice-president', label: 'Vice President' },
    { value: 'senator', label: 'Senator' },
    { value: 'mayor', label: 'Mayor' },
    { value: 'councilor', label: 'Councilor' },
    { value: 'party-list', label: 'Party List' },
];

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
              .map((token) => viewOptions.find((option) => option.value === token)?.label ?? token)
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
        activeViewTokens.value.some((token) => contestMatchesView(contest, token)),
    );
});

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
                        {{ activeViewLabel }} ·
                        {{ scannerState.latest_message ?? 'Waiting for scans.' }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <a
                        v-for="option in viewOptions"
                        :key="option.value"
                        :href="publicBoardUrl(option.value)"
                        class="border px-3 py-2 text-sm font-bold"
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

            <TallyBoard
                eyebrow="Timer-updated public board"
                title="Precinct ballot QR tally"
                :accepted-count="scannerState.accepted_count"
                accepted-label="accepted ballot scans"
                :contests="filteredContests"
                :tally="scannerState.tally"
                :flash-key="scannerState.revision"
                enable-candidate-sort
            >
                <template #stats>
                    <p class="mt-2 text-xs text-stone-500">
                        Revision {{ scannerState.revision }}
                        <span v-if="lastUpdatedAt">· {{ lastUpdatedAt }}</span>
                    </p>
                </template>
            </TallyBoard>
        </section>
    </main>
</template>
