<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import ScanLedger from '@/components/election/ScanLedger.vue';
import TallyBoard from '@/components/election/TallyBoard.vue';
import { index as roleDemoIndex } from '@/routes/election/role-demo';

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
    document_profile?: Record<string, string> | null;
    selections: Record<string, string[]>;
    this_ballot_tally: Tally;
};

type ScanLogEntry = {
    id: string;
    title: string;
    subtitle?: string | null;
    meta?: string | null;
    hash?: string | null;
    status?: 'accepted' | 'partial' | 'duplicate' | 'rejected';
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
        precinct: {
            election_id: string | null;
            precinct_id: string | null;
            ballot_style_id: string | null;
            mapping_hash: string | null;
        };
        ballot: {
            contests: Contest[];
        };
        scanner: {
            ballots: ScannerBallot[];
            initial_tally: Tally;
        };
        document_rendering: {
            profiles: Record<string, Record<string, unknown>>;
            asset_bundle: {
                id: string;
                hash: string;
                assets: Record<string, Record<string, string | null>>;
            };
        };
    };
}>();

const scannedBallots = ref<ScannerBallot[]>([]);
const scanEvents = ref<ScanLogEntry[]>([]);
const runningTally = ref<Tally>(
    cloneTally(props.simulation.scanner.initial_tally),
);
const lastScanDelta = ref<TallyDelta>({});
const lastScanFlashKey = ref(0);
const scannerStatus = ref<'ready' | 'scanning'>('ready');
const automaticScanner = ref<number | null>(null);

const nextBallot = computed(
    () => props.simulation.scanner.ballots[scannedBallots.value.length] ?? null,
);
const lastBallot = computed(
    () => scannedBallots.value[scannedBallots.value.length - 1] ?? null,
);
const scannedCount = computed(() => scannedBallots.value.length);
const scannerPulsePercent = computed(() =>
    scannerStatus.value === 'scanning' ? 72 : scannedCount.value > 0 ? 100 : 0,
);
const scannedDocuments = computed<LedgerDocument[]>(() =>
    scannedBallots.value.map((ballot) => ({
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
    })),
);

function scanNext(): void {
    if (!nextBallot.value || scannerStatus.value === 'scanning') {
        return;
    }

    scannerStatus.value = 'scanning';
    const ballot = nextBallot.value;

    window.setTimeout(() => {
        const delta = deltaForSelections(ballot.selections);

        scannedBallots.value.push(ballot);
        scanEvents.value.push({
            id: `${ballot.payload_hash}-${scanEvents.value.length}`,
            title: `Ballot ${ballot.sequence}`,
            subtitle: String(ballot.paper_ballot_serial ?? ''),
            meta: 'Single QR document · accepted',
            hash: ballot.payload_hash,
            status: 'accepted',
        });
        addSelections(ballot.selections);
        lastScanDelta.value = delta;
        lastScanFlashKey.value += 1;
        scannerStatus.value = 'ready';
    }, 220);
}

function toggleAutomaticScanner(): void {
    if (automaticScanner.value !== null) {
        stopAutomaticScanner();

        return;
    }

    scanNext();
    automaticScanner.value = window.setInterval(() => {
        if (!nextBallot.value) {
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
    scannedBallots.value = [];
    scanEvents.value = [];
    runningTally.value = cloneTally(props.simulation.scanner.initial_tally);
    lastScanDelta.value = {};
    lastScanFlashKey.value += 1;
    scannerStatus.value = 'ready';
}

function deltaForSelections(selections: Record<string, string[]>): TallyDelta {
    const delta: TallyDelta = {};

    Object.entries(selections).forEach(([contestId, candidateIds]) => {
        candidateIds.forEach((candidateId) => {
            const previousTotal =
                runningTally.value[contestId]?.[candidateId] ?? 0;
            const currentDelta = delta[contestId]?.[candidateId];

            delta[contestId] ??= {};
            delta[contestId][candidateId] = {
                previousTotal,
                addedVotes: (currentDelta?.addedVotes ?? 0) + 1,
                finalTotal: previousTotal + (currentDelta?.addedVotes ?? 0) + 1,
            };
        });
    });

    return delta;
}

function addSelections(selections: Record<string, string[]>): void {
    Object.entries(selections).forEach(([contestId, candidateIds]) => {
        runningTally.value[contestId] ??= {};

        candidateIds.forEach((candidateId) => {
            runningTally.value[contestId][candidateId] =
                (runningTally.value[contestId][candidateId] ?? 0) + 1;
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

function sourceLabel(source: string): string {
    return source === 'sealed-role-demo-ballots'
        ? 'Sealed role-demo ballot QR payloads'
        : 'Generated demo ballot payloads';
}

onBeforeUnmount(stopAutomaticScanner);
</script>

<template>
    <Head :title="`${precinct.code} QR scanner tally`" />

    <main class="min-h-screen bg-stone-100 text-stone-950">
        <div class="grid h-1.5 grid-cols-3">
            <span class="bg-blue-800" /><span class="bg-yellow-400" /><span
                class="bg-red-700"
            />
        </div>

        <section
            class="mx-auto grid max-w-[1800px] gap-3 px-3 py-4 lg:grid-cols-[300px_minmax(0,1fr)] xl:grid-cols-[320px_minmax(0,1fr)]"
        >
            <aside class="space-y-3">
                <section class="border border-stone-300 bg-white p-3">
                    <Link
                        :href="roleDemoIndex.url()"
                        class="text-sm font-bold text-blue-800"
                    >
                        Role POV room
                    </Link>
                    <p class="mt-4 text-sm font-bold text-amber-800">
                        QR SCANNER TALLY SIMULATION
                    </p>
                    <h1 class="mt-1 text-xl leading-tight font-bold">
                        {{ precinct.label }}
                    </h1>
                    <dl class="mt-3 grid gap-1.5 text-xs">
                        <div class="flex justify-between gap-4">
                            <dt class="font-bold text-stone-600">Source</dt>
                            <dd class="text-right">
                                {{ sourceLabel(simulation.source) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="font-bold text-stone-600">Precinct</dt>
                            <dd class="font-mono">
                                {{ simulation.precinct.precinct_id }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="font-bold text-stone-600">Accepted</dt>
                            <dd>{{ scannedCount }} scans</dd>
                        </div>
                    </dl>
                </section>

                <section
                    class="border border-stone-300 bg-stone-950 p-3 text-stone-50"
                >
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="font-bold">Scanner station</h2>
                            <p class="text-sm text-stone-300">
                                One truth:// ballot URI per trigger.
                            </p>
                        </div>
                        <span
                            class="h-3 w-3 rounded-full"
                            :class="
                                scannerStatus === 'scanning'
                                    ? 'bg-yellow-300'
                                    : 'bg-blue-400'
                            "
                        />
                    </div>

                    <div
                        class="mt-3 overflow-hidden border border-stone-700 bg-black"
                    >
                        <div
                            class="h-1 bg-emerald-400 transition-all duration-300"
                            :style="{ width: `${scannerPulsePercent}%` }"
                        />
                        <textarea
                            class="h-24 w-full resize-none bg-black p-2 font-mono text-[10px] text-emerald-300 outline-none"
                            readonly
                            :value="
                                nextBallot
                                    ? nextBallot.payload
                                    : 'Sample source has no next ballot payload.'
                            "
                        />
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button
                            class="primary-button"
                            type="button"
                            :disabled="
                                !nextBallot || scannerStatus === 'scanning'
                            "
                            @click="scanNext"
                        >
                            Feed next sample
                        </button>
                        <button
                            class="secondary-button"
                            type="button"
                            :disabled="simulation.scanner.ballots.length === 0"
                            @click="toggleAutomaticScanner"
                        >
                            {{
                                automaticScanner
                                    ? 'Stop auto'
                                    : 'Auto feed samples'
                            }}
                        </button>
                        <button
                            class="secondary-button col-span-2"
                            type="button"
                            @click="resetScanner"
                        >
                            Reset scanner
                        </button>
                    </div>

                    <div
                        v-if="lastBallot"
                        class="mt-3 border border-emerald-500 bg-emerald-950/40 p-2 text-xs"
                        role="status"
                    >
                        <p class="font-bold">
                            Accepted ballot {{ lastBallot.sequence }}
                        </p>
                        <p
                            class="mt-1 font-mono text-xs break-all text-emerald-200"
                        >
                            {{ lastBallot.payload_hash }}
                        </p>
                    </div>
                    <p v-else class="mt-4 text-sm text-stone-300">
                        Ready to receive the first ballot QR payload.
                    </p>
                </section>

                <ScanLedger
                    :entries="scanEvents"
                    :documents="scannedDocuments"
                    :contests="simulation.ballot.contests"
                    :rendering-kit="simulation.document_rendering"
                    empty-message="No ballot scans yet."
                    empty-documents-message="No completed ballots yet."
                />
            </aside>

            <section class="space-y-4">
                <TallyBoard
                    eyebrow="Live tally sheet"
                    title="Ballot payload count"
                    :accepted-count="scannedCount"
                    accepted-label="accepted scans"
                    :contests="simulation.ballot.contests"
                    :tally="runningTally"
                    :last-scan-delta="lastScanDelta"
                    :flash-key="lastScanFlashKey"
                />
            </section>
        </section>
    </main>
</template>

<style scoped>
.primary-button,
.secondary-button {
    min-height: 2.75rem;
    border-width: 2px;
    padding-inline: 1rem;
    font-weight: 700;
}

.primary-button {
    border-color: rgb(252 211 77);
    background: rgb(252 211 77);
    color: rgb(28 25 23);
}

.secondary-button {
    border-color: rgb(214 211 209);
    background: transparent;
    color: rgb(245 245 244);
}

.primary-button:disabled,
.secondary-button:disabled {
    cursor: not-allowed;
    opacity: 0.45;
}
</style>
