<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import PrecinctTallyBoard from '@/components/election/PrecinctTallyBoard.vue';

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

            <PrecinctTallyBoard
                eyebrow="Timer-updated public board"
                title="Precinct ballot QR tally"
                :accepted-count="scannerState.accepted_count"
                accepted-label="accepted ballot scans"
                :contests="simulation.ballot.contests"
                :tally="scannerState.tally"
                :view="view"
                :flash-key="scannerState.revision"
                :revision="scannerState.revision"
                :last-updated-at="lastUpdatedAt"
                :status-message="
                    scannerState.latest_message ?? 'Waiting for scans.'
                "
                :public-board-url="actions.publicBoard"
            />
        </section>
    </main>
</template>
