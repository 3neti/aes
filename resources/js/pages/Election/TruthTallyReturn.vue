<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import TallyMarks from '@/components/election/TallyMarks.vue';
import { index as roleDemoIndex } from '@/routes/election/role-demo';

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
    source: string;
    precinct_id: string;
    return_scope: string;
    payloads: string[];
    canonical_payload: string;
    payload_hash: string;
    return_hash: string;
    accepted_ballots: number;
    rejected_ballots: number;
    tally: Tally;
    display_tally: Tally;
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
        source: string;
        canvass: {
            jurisdiction: string | null;
            election_id: string | null;
            mapping_hash: string | null;
        };
        return: {
            contests: Contest[];
        };
        scanner: {
            returns: ReturnScan[];
            initial_tally: Tally;
        };
    };
}>();

const scannedReturns = ref<ReturnScan[]>([]);
const runningTally = ref<Tally>(
    cloneTally(props.simulation.scanner.initial_tally),
);
const scannerStatus = ref<'ready' | 'scanning' | 'complete'>('ready');
const automaticScanner = ref<number | null>(null);

const nextReturn = computed(
    () => props.simulation.scanner.returns[scannedReturns.value.length] ?? null,
);
const lastReturn = computed(
    () => scannedReturns.value[scannedReturns.value.length - 1] ?? null,
);
const scannedCount = computed(() => scannedReturns.value.length);
const remainingCount = computed(() =>
    Math.max(props.simulation.scanner.returns.length - scannedCount.value, 0),
);
const acceptedBallots = computed(() =>
    scannedReturns.value.reduce(
        (total, scannedReturn) => total + scannedReturn.accepted_ballots,
        0,
    ),
);

function scanNext(): void {
    if (!nextReturn.value || scannerStatus.value === 'scanning') {
        return;
    }

    scannerStatus.value = 'scanning';
    const scannedReturn = nextReturn.value;

    window.setTimeout(() => {
        scannedReturns.value.push(scannedReturn);
        addTally(scannedReturn.tally);
        scannerStatus.value = nextReturn.value ? 'ready' : 'complete';
    }, 260);
}

function toggleAutomaticScanner(): void {
    if (automaticScanner.value !== null) {
        stopAutomaticScanner();

        return;
    }

    scanNext();
    automaticScanner.value = window.setInterval(() => {
        if (!nextReturn.value) {
            stopAutomaticScanner();

            return;
        }

        scanNext();
    }, 900);
}

function stopAutomaticScanner(): void {
    if (automaticScanner.value !== null) {
        window.clearInterval(automaticScanner.value);
    }

    automaticScanner.value = null;
}

function resetScanner(): void {
    stopAutomaticScanner();
    scannedReturns.value = [];
    runningTally.value = cloneTally(props.simulation.scanner.initial_tally);
    scannerStatus.value = 'ready';
}

function addTally(tally: Tally): void {
    Object.entries(tally).forEach(([contestId, candidateTotals]) => {
        runningTally.value[contestId] ??= {};

        Object.entries(candidateTotals).forEach(([candidateId, votes]) => {
            runningTally.value[contestId][candidateId] =
                (runningTally.value[contestId][candidateId] ?? 0) + votes;
        });
    });
}

function cloneTally(tally: Tally): Tally {
    return Object.fromEntries(
        Object.entries(tally).map(([contestId, candidates]) => [
            contestId,
            { ...candidates },
        ]),
    );
}

function contestTotal(contestId: string): number {
    return Object.values(runningTally.value[contestId] ?? {}).reduce(
        (total, votes) => total + votes,
        0,
    );
}

onBeforeUnmount(stopAutomaticScanner);
</script>

<template>
    <Head :title="`${precinct.code} TruthTally ER`" />

    <main class="min-h-screen bg-stone-100 text-stone-950">
        <div class="grid h-1.5 grid-cols-3">
            <span class="bg-blue-800" /><span class="bg-yellow-400" /><span
                class="bg-red-700"
            />
        </div>

        <section
            class="mx-auto grid max-w-7xl gap-5 px-5 py-6 lg:grid-cols-[420px_minmax(0,1fr)]"
        >
            <aside class="space-y-5">
                <section class="border border-stone-300 bg-white p-5">
                    <Link
                        :href="roleDemoIndex.url()"
                        class="text-sm font-bold text-blue-800"
                    >
                        Role POV room
                    </Link>
                    <p class="mt-4 text-sm font-bold text-sky-800">
                        TRUTHTALLY ELECTION RETURN
                    </p>
                    <h1 class="mt-1 text-2xl font-bold">
                        {{ simulation.canvass.jurisdiction }}
                    </h1>
                    <dl class="mt-4 grid gap-2 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="font-bold text-stone-600">Precinct</dt>
                            <dd class="font-mono">{{ precinct.code }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="font-bold text-stone-600">
                                ER payloads
                            </dt>
                            <dd>
                                {{ scannedCount }} /
                                {{ simulation.scanner.returns.length }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="font-bold text-stone-600">
                                Accepted ballots
                            </dt>
                            <dd class="font-bold">{{ acceptedBallots }}</dd>
                        </div>
                    </dl>
                </section>

                <section
                    class="border border-stone-300 bg-stone-950 p-5 text-stone-50"
                >
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="font-bold">Canvass scanner</h2>
                            <p class="text-sm text-stone-300">
                                One truth:// ER payload set per precinct.
                            </p>
                        </div>
                        <span
                            class="h-3 w-3 rounded-full"
                            :class="
                                scannerStatus === 'scanning'
                                    ? 'bg-yellow-300'
                                    : scannerStatus === 'complete'
                                      ? 'bg-emerald-400'
                                      : 'bg-sky-400'
                            "
                        />
                    </div>

                    <div class="mt-5 border border-stone-700 bg-stone-900 p-4">
                        <p class="text-xs font-bold text-stone-400">
                            NEXT ER PAYLOAD
                        </p>
                        <p
                            class="mt-2 min-h-16 break-all font-mono text-xs text-yellow-200"
                        >
                            {{
                                nextReturn
                                    ? nextReturn.payloads[0]
                                    : 'No pending election return QR payload.'
                            }}
                        </p>
                    </div>

                    <div class="mt-5 grid grid-cols-2 gap-3">
                        <button
                            type="button"
                            class="min-h-12 bg-yellow-300 px-4 font-bold text-stone-950 disabled:cursor-not-allowed disabled:bg-stone-700 disabled:text-stone-400"
                            :disabled="!nextReturn || scannerStatus === 'scanning'"
                            @click="scanNext"
                        >
                            Scan next ER
                        </button>
                        <button
                            type="button"
                            class="min-h-12 border border-stone-600 px-4 font-bold text-stone-50"
                            @click="toggleAutomaticScanner"
                        >
                            {{
                                automaticScanner === null
                                    ? 'Auto scan'
                                    : 'Stop auto'
                            }}
                        </button>
                    </div>
                    <button
                        type="button"
                        class="mt-3 min-h-11 w-full border border-stone-600 px-4 font-bold text-stone-50"
                        @click="resetScanner"
                    >
                        Reset canvass
                    </button>
                </section>

                <section class="border border-stone-300 bg-white p-5">
                    <h2 class="font-bold">Last accepted return</h2>
                    <dl v-if="lastReturn" class="mt-3 grid gap-2 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="font-bold text-stone-600">Precinct</dt>
                            <dd class="font-mono">
                                {{ lastReturn.precinct_id }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="font-bold text-stone-600">QR count</dt>
                            <dd>{{ lastReturn.payloads.length }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="font-bold text-stone-600">
                                Return hash
                            </dt>
                            <dd class="max-w-48 truncate font-mono">
                                {{ lastReturn.return_hash }}
                            </dd>
                        </div>
                    </dl>
                    <p v-else class="mt-3 text-sm text-stone-600">
                        Waiting for the first ER QR scan.
                    </p>
                </section>
            </aside>

            <section class="space-y-4">
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border border-stone-300 bg-white p-5"
                >
                    <div>
                        <p class="text-sm font-bold text-sky-800">
                            Municipal/city canvass sheet
                        </p>
                        <h2 class="mt-1 text-2xl font-bold">
                            QR-derived election return totals
                        </h2>
                    </div>
                    <p class="text-sm font-bold text-stone-600">
                        {{ remainingCount }} pending
                    </p>
                </div>

                <section
                    v-for="contest in simulation.return.contests"
                    :key="contest.id"
                    class="border border-stone-300 bg-white p-5"
                >
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-3"
                    >
                        <div>
                            <p class="text-xs font-bold text-stone-500">
                                {{ contest.max_selections }} allowed
                            </p>
                            <h3 class="text-xl font-bold">
                                {{ contest.title }}
                            </h3>
                        </div>
                        <p class="font-mono text-sm font-bold text-stone-600">
                            {{ contestTotal(contest.id) }} votes
                        </p>
                    </div>

                    <div class="mt-4 grid gap-2">
                        <div
                            v-for="candidate in contest.candidates"
                            :key="candidate.id"
                            class="grid grid-cols-[minmax(0,1fr)_96px_130px] items-center gap-3 border border-stone-200 bg-stone-50 px-3 py-2 text-sm"
                        >
                            <p class="truncate font-medium">
                                {{ candidate.name }}
                            </p>
                            <p class="text-right font-mono text-lg font-bold">
                                {{
                                    runningTally[contest.id]?.[candidate.id] ??
                                    0
                                }}
                            </p>
                            <TallyMarks
                                :count="
                                    runningTally[contest.id]?.[candidate.id] ??
                                    0
                                "
                            />
                        </div>
                    </div>
                </section>
            </section>
        </section>
    </main>
</template>
