<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import ScanLedger from '@/components/election/ScanLedger.vue';
import TallyBoard from '@/components/election/TallyBoard.vue';

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

type ReturnScan = {
    type?: 'election-return';
    sequence: number;
    source: string;
    precinct_id: string;
    return_scope: string;
    payloads: string[];
    canonical_payload: string;
    payload_hash: string;
    return_hash: string;
    document_profile?: Record<string, string> | null;
    accepted_ballots: number;
    rejected_ballots: number;
    tally: Tally;
    display_tally: Tally;
};

type DemoRun = {
    ballots_per_return?: number;
    return_count?: number;
    total_ballots?: number;
    ballot_count: number;
    generated_at: string;
    tally_hash: string;
    return_hash: string;
    truth_tally_payload_hash: string;
    return_qr_count: number;
    candidate_count: number;
    contest_count: number;
    tally_hashes?: string[];
    return_hashes?: string[];
    sample_ballots: Array<{
        sequence: number;
        precinct_id: string;
        paper_ballot_serial: string;
        payload_hash: string;
        qr_payload: string;
        canonical_payload: string;
    }>;
    return_scan: ReturnScan;
    return_scans?: ReturnScan[];
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
    kind: 'election-return';
    electionReturn: ReturnScan & {
        type: 'election-return';
    };
};

type MultipartBuffer = {
    groupId: string;
    totalParts: number;
    receivedParts: Record<number, string>;
};

type ErEnvelopeMetadata =
    | {
          kind: 'complete';
          totalParts: 1;
      }
    | {
          kind: 'fragment';
          groupId: string;
          partNumber: number;
          totalParts: number;
      }
    | {
          kind: 'unknown';
      };

const props = defineProps<{
    simulation: {
        maximum_ballots: number;
        maximum_returns: number;
        default_ballots: number;
        default_returns: number;
        configuration: {
            election_id: string | null;
            precinct_id: string | null;
            ballot_style_id: string | null;
            mapping_hash: string | null;
            tabulation_profile: string | null;
            city_municipality: string | null;
            province: string | null;
            candidate_count: number;
            contest_count: number;
        };
        ballot: {
            contests: Contest[];
        };
        run: DemoRun | null;
        scanner: {
            returns: ReturnScan[];
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
    actions: {
        generate: string;
    };
}>();

const scannedReturns = ref<ReturnScan[]>([]);
const scannedQrPayloadsInCurrentReturn = ref(0);
const currentMultipartBuffer = ref<MultipartBuffer | null>(null);
const scanEvents = ref<ScanLogEntry[]>([]);
const runningTally = ref<Tally>(
    cloneTally(props.simulation.scanner.initial_tally),
);
const lastScanDelta = ref<TallyDelta>({});
const lastScanFlashKey = ref(0);
const scannerStatus = ref<'ready' | 'scanning'>('ready');
const automaticScanner = ref<number | null>(null);

const nextReturn = computed(
    () => props.simulation.scanner.returns[scannedReturns.value.length] ?? null,
);
const nextPayload = computed(
    () =>
        nextReturn.value?.payloads[scannedQrPayloadsInCurrentReturn.value] ??
        null,
);
const lastReturn = computed(
    () => scannedReturns.value[scannedReturns.value.length - 1] ?? null,
);
const scannedCount = computed(() => scannedReturns.value.length);
const acceptedBallots = computed(() =>
    scannedReturns.value.reduce(
        (total, scannedReturn) => total + scannedReturn.accepted_ballots,
        0,
    ),
);
const maxQrPayloadsPerReturn = computed(() =>
    props.simulation.scanner.returns.reduce(
        (maximum, scannedReturn) =>
            Math.max(maximum, scannedReturn.payloads.length),
        0,
    ),
);
const scannerPulsePercent = computed(() => {
    if (scannerStatus.value === 'scanning') {
        return 72;
    }

    if (!currentMultipartBuffer.value) {
        return scannedCount.value > 0 ? 100 : 0;
    }

    return Math.round(
        (Object.keys(currentMultipartBuffer.value.receivedParts).length /
            currentMultipartBuffer.value.totalParts) *
            100,
    );
});
const nextPayloadMetadata = computed(() =>
    nextPayload.value ? parseErEnvelopeMetadata(nextPayload.value) : null,
);
const scannedDocuments = computed<LedgerDocument[]>(() =>
    scannedReturns.value.map((scannedReturn) => ({
        id: scannedReturn.return_hash,
        title: `ER ${scannedReturn.sequence}`,
        subtitle: scannedReturn.precinct_id,
        meta: `${scannedReturn.accepted_ballots} ballots canvassed`,
        hash: scannedReturn.return_hash,
        kind: 'election-return',
        electionReturn: {
            ...scannedReturn,
            type: 'election-return',
        },
    })),
);

function scanNext(): void {
    if (
        !nextReturn.value ||
        !nextPayload.value ||
        scannerStatus.value === 'scanning'
    ) {
        return;
    }

    scannerStatus.value = 'scanning';
    const scannedReturn = nextReturn.value;

    window.setTimeout(() => {
        const payload = nextPayload.value;

        if (!payload) {
            scannerStatus.value = 'ready';

            return;
        }

        const metadata = parseErEnvelopeMetadata(payload);

        scannedQrPayloadsInCurrentReturn.value += 1;

        if (metadata.kind === 'complete') {
            const delta = deltaForTally(scannedReturn.tally);

            scannedReturns.value.push(scannedReturn);
            scannedQrPayloadsInCurrentReturn.value = 0;
            currentMultipartBuffer.value = null;
            addTally(scannedReturn.tally);
            lastScanDelta.value = delta;
            lastScanFlashKey.value += 1;
            scanEvents.value.push({
                id: `${scannedReturn.return_hash}-${scanEvents.value.length}`,
                title: `ER ${scannedReturn.sequence} accepted`,
                subtitle: scannedReturn.precinct_id,
                meta: `Single QR document · ${scannedReturn.accepted_ballots} ballots`,
                hash: scannedReturn.return_hash,
                status: 'accepted',
            });
            scannerStatus.value = 'ready';

            return;
        }

        if (metadata.kind === 'unknown') {
            scanEvents.value.push({
                id: `rejected-${scanEvents.value.length}`,
                title: 'Rejected QR',
                meta: 'Invalid or unsupported ER payload envelope',
                status: 'rejected',
            });
            scannerStatus.value = 'ready';

            return;
        }

        if (
            currentMultipartBuffer.value &&
            currentMultipartBuffer.value.groupId !== metadata.groupId
        ) {
            scanEvents.value.push({
                id: `rejected-mixed-${metadata.groupId}-${scanEvents.value.length}`,
                title: 'Rejected ER QR part',
                subtitle: scannedReturn.precinct_id,
                meta: 'Started a different multipart ER before completing the current set',
                hash: metadata.groupId,
                status: 'rejected',
            });
        }

        if (
            !currentMultipartBuffer.value ||
            currentMultipartBuffer.value.groupId !== metadata.groupId
        ) {
            currentMultipartBuffer.value = {
                groupId: metadata.groupId,
                totalParts: metadata.totalParts,
                receivedParts: {},
            };
        }

        if (currentMultipartBuffer.value.receivedParts[metadata.partNumber]) {
            scanEvents.value.push({
                id: `duplicate-${metadata.groupId}-${metadata.partNumber}-${scanEvents.value.length}`,
                title: 'Duplicate ER QR part',
                subtitle: scannedReturn.precinct_id,
                meta: `Part ${metadata.partNumber} of ${metadata.totalParts} already scanned`,
                hash: metadata.groupId,
                status: 'duplicate',
            });
            scannerStatus.value = 'ready';

            return;
        }

        currentMultipartBuffer.value.receivedParts[metadata.partNumber] =
            payload;
        const receivedParts = Object.keys(
            currentMultipartBuffer.value.receivedParts,
        ).length;

        if (receivedParts >= metadata.totalParts) {
            const delta = deltaForTally(scannedReturn.tally);

            scannedReturns.value.push(scannedReturn);
            scannedQrPayloadsInCurrentReturn.value = 0;
            currentMultipartBuffer.value = null;
            addTally(scannedReturn.tally);
            lastScanDelta.value = delta;
            lastScanFlashKey.value += 1;
            scanEvents.value.push({
                id: `${metadata.groupId}-complete-${scanEvents.value.length}`,
                title: `ER ${scannedReturn.sequence} accepted`,
                subtitle: scannedReturn.precinct_id,
                meta: `${metadata.totalParts} of ${metadata.totalParts} parts complete · ${scannedReturn.accepted_ballots} ballots`,
                hash: scannedReturn.return_hash,
                status: 'accepted',
            });
            scannerStatus.value = 'ready';

            return;
        }

        scanEvents.value.push({
            id: `${metadata.groupId}-${metadata.partNumber}-${scanEvents.value.length}`,
            title: 'ER QR set',
            subtitle: scannedReturn.precinct_id,
            meta: `${receivedParts} of ${metadata.totalParts} parts received`,
            hash: metadata.groupId,
            status: 'partial',
        });

        if (
            scannedQrPayloadsInCurrentReturn.value >=
            scannedReturn.payloads.length
        ) {
            scannedQrPayloadsInCurrentReturn.value = 0;
        }

        scannerStatus.value = 'ready';
    }, 260);
}

function toggleAutomaticScanner(): void {
    if (automaticScanner.value !== null) {
        stopAutomaticScanner();

        return;
    }

    scanNext();
    automaticScanner.value = window.setInterval(() => {
        if (!nextPayload.value) {
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
    scannedQrPayloadsInCurrentReturn.value = 0;
    currentMultipartBuffer.value = null;
    scanEvents.value = [];
    runningTally.value = cloneTally(props.simulation.scanner.initial_tally);
    lastScanDelta.value = {};
    lastScanFlashKey.value += 1;
    scannerStatus.value = 'ready';
}

function deltaForTally(tally: Tally): TallyDelta {
    const delta: TallyDelta = {};

    Object.entries(tally).forEach(([contestId, candidateTotals]) => {
        Object.entries(candidateTotals).forEach(([candidateId, votes]) => {
            const addedVotes = Math.max(0, votes);

            if (addedVotes === 0) {
                return;
            }

            const previousTotal =
                runningTally.value[contestId]?.[candidateId] ?? 0;

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

function parseErEnvelopeMetadata(payload: string): ErEnvelopeMetadata {
    try {
        const url = new URL(payload);
        const segments = url.pathname.split('/').filter(Boolean);

        if (
            url.protocol !== 'truth:' ||
            url.hostname !== 'v1' ||
            segments[0] !== 'waes-election-return'
        ) {
            return { kind: 'unknown' };
        }

        if (segments[1] === 'waes-er-compact-1') {
            return {
                kind: 'complete',
                totalParts: 1,
            };
        }

        if (segments[1] !== 'waes-er-fragment-1') {
            return { kind: 'unknown' };
        }

        const partNumber = Number.parseInt(segments[2] ?? '', 10);
        const totalParts = Number.parseInt(segments[3] ?? '', 10);
        const groupId = url.searchParams.get('h') ?? '';

        if (
            !Number.isInteger(partNumber) ||
            !Number.isInteger(totalParts) ||
            partNumber < 1 ||
            totalParts < 2 ||
            partNumber > totalParts ||
            groupId === ''
        ) {
            return { kind: 'unknown' };
        }

        return {
            kind: 'fragment',
            groupId,
            partNumber,
            totalParts,
        };
    } catch {
        return { kind: 'unknown' };
    }
}

onBeforeUnmount(stopAutomaticScanner);
</script>

<template>
    <Head title="Canvassing Demo" />

    <main class="min-h-screen bg-stone-100 text-stone-950">
        <div class="grid h-1.5 grid-cols-3">
            <span class="bg-blue-800" /><span class="bg-yellow-400" /><span
                class="bg-red-700"
            />
        </div>

        <section
            class="mx-auto grid max-w-[1800px] gap-3 px-3 py-4 lg:grid-cols-[320px_minmax(0,1fr)] xl:grid-cols-[340px_minmax(0,1fr)]"
        >
            <aside class="space-y-3">
                <section class="border border-stone-300 bg-white p-3">
                    <p class="text-sm font-bold text-sky-800">
                        WAES canvassing demo
                    </p>
                    <h1 class="mt-1 text-xl leading-tight font-bold">
                        {{ simulation.configuration.city_municipality }}
                    </h1>
                    <dl class="mt-3 grid gap-1.5 text-xs">
                        <div class="flex justify-between gap-4">
                            <dt class="font-bold text-stone-600">Election</dt>
                            <dd class="text-right font-mono">
                                {{ simulation.configuration.election_id }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="font-bold text-stone-600">Precinct</dt>
                            <dd class="font-mono">
                                {{ simulation.configuration.precinct_id }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="font-bold text-stone-600">Max per ER</dt>
                            <dd class="font-bold">
                                {{ simulation.maximum_ballots }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="font-bold text-stone-600">Max ERs</dt>
                            <dd class="font-bold">
                                {{ simulation.maximum_returns }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="font-bold text-stone-600">Contests</dt>
                            <dd class="font-bold">
                                {{ simulation.configuration.contest_count }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="font-bold text-stone-600">Candidates</dt>
                            <dd class="font-bold">
                                {{ simulation.configuration.candidate_count }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="border border-stone-300 bg-white p-3">
                    <h2 class="font-bold">Generate precinct returns</h2>
                    <Form
                        :action="actions.generate"
                        method="post"
                        class="mt-3 grid gap-2.5"
                        #default="{ errors, processing }"
                    >
                        <label class="grid gap-1.5 text-xs font-bold">
                            Ballot QR payloads per ER
                            <input
                                class="min-h-10 border border-stone-300 bg-white px-3 text-base font-bold"
                                name="ballot_count"
                                type="number"
                                min="1"
                                :max="simulation.maximum_ballots"
                                :value="
                                    simulation.run?.ballots_per_return ??
                                    simulation.run?.ballot_count ??
                                    simulation.default_ballots
                                "
                            />
                        </label>
                        <p
                            v-if="errors.ballot_count"
                            class="text-sm text-red-700"
                        >
                            {{ errors.ballot_count }}
                        </p>
                        <label class="grid gap-1.5 text-xs font-bold">
                            Precinct ERs to canvass
                            <input
                                class="min-h-10 border border-stone-300 bg-white px-3 text-base font-bold"
                                name="return_count"
                                type="number"
                                min="1"
                                :max="simulation.maximum_returns"
                                :value="
                                    simulation.run?.return_count ??
                                    simulation.default_returns
                                "
                            />
                        </label>
                        <p
                            v-if="errors.return_count"
                            class="text-sm text-red-700"
                        >
                            {{ errors.return_count }}
                        </p>
                        <button
                            type="submit"
                            class="min-h-10 bg-blue-800 px-4 font-bold text-white disabled:cursor-not-allowed disabled:bg-stone-400"
                            :disabled="processing"
                        >
                            {{
                                processing
                                    ? 'Generating...'
                                    : 'Generate ER payload'
                            }}
                        </button>
                    </Form>
                </section>

                <section class="border border-stone-300 bg-white p-3">
                    <h2 class="font-bold">Generated ERs</h2>
                    <dl v-if="simulation.run" class="mt-2 grid gap-1.5 text-xs">
                        <div class="flex justify-between gap-4">
                            <dt class="font-bold text-stone-600">ERs</dt>
                            <dd class="font-bold">
                                {{ simulation.run.return_count ?? 1 }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="font-bold text-stone-600">
                                Ballots per ER
                            </dt>
                            <dd class="font-bold">
                                {{
                                    simulation.run.ballots_per_return ??
                                    simulation.run.ballot_count
                                }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="font-bold text-stone-600">
                                Total ballots
                            </dt>
                            <dd class="font-bold">
                                {{
                                    simulation.run.total_ballots ??
                                    simulation.run.ballot_count
                                }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="font-bold text-stone-600">ER QRs</dt>
                            <dd>{{ simulation.run.return_qr_count }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="font-bold text-stone-600">QRs per ER</dt>
                            <dd>
                                {{ maxQrPayloadsPerReturn }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="font-bold text-stone-600">Candidates</dt>
                            <dd>{{ simulation.run.candidate_count }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="font-bold text-stone-600">
                                Return hash
                            </dt>
                            <dd class="max-w-52 truncate font-mono">
                                {{ simulation.run.return_hash }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="font-bold text-stone-600">
                                Payload hash
                            </dt>
                            <dd class="max-w-52 truncate font-mono">
                                {{ simulation.run.truth_tally_payload_hash }}
                            </dd>
                        </div>
                    </dl>
                    <p v-else class="mt-3 text-sm text-stone-600">
                        No election returns have been generated yet.
                    </p>
                </section>

                <section
                    class="border border-stone-300 bg-stone-950 p-3 text-stone-50"
                >
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="font-bold">Canvassing scanner</h2>
                            <p class="text-sm text-stone-300">
                                Scan the ER truth:// payload set.
                            </p>
                        </div>
                        <span
                            class="h-3 w-3 rounded-full"
                            :class="
                                scannerStatus === 'scanning'
                                    ? 'bg-yellow-300'
                                    : 'bg-sky-400'
                            "
                        />
                    </div>

                    <div
                        class="mt-3 overflow-hidden border border-stone-700 bg-stone-900"
                    >
                        <div
                            class="h-1 bg-emerald-400 transition-all duration-300"
                            :style="{ width: `${scannerPulsePercent}%` }"
                        />
                        <div class="p-3">
                            <p class="text-xs font-bold text-stone-400">
                                NEXT ER PAYLOAD
                            </p>
                            <p
                                v-if="
                                    nextReturn &&
                                    nextPayloadMetadata?.kind === 'fragment'
                                "
                                class="mt-1 text-xs font-bold text-sky-200"
                            >
                                Part {{ nextPayloadMetadata.partNumber }} of
                                {{ nextPayloadMetadata.totalParts }} · Precinct
                                {{ nextReturn.precinct_id }}
                            </p>
                            <p
                                v-else-if="
                                    nextReturn &&
                                    nextPayloadMetadata?.kind === 'complete'
                                "
                                class="mt-1 text-xs font-bold text-sky-200"
                            >
                                Single QR document · Precinct
                                {{ nextReturn.precinct_id }}
                            </p>
                            <p
                                v-else-if="nextReturn"
                                class="mt-1 text-xs font-bold text-red-200"
                            >
                                Unsupported ER QR envelope · Precinct
                                {{ nextReturn.precinct_id }}
                            </p>
                            <p
                                class="mt-2 min-h-14 font-mono text-[10px] break-all text-yellow-200"
                            >
                                {{
                                    nextPayload
                                        ? nextPayload
                                        : 'Sample source has no next ER QR payload.'
                                }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            class="min-h-10 bg-yellow-300 px-3 font-bold text-stone-950 disabled:cursor-not-allowed disabled:bg-stone-700 disabled:text-stone-400"
                            :disabled="
                                !nextPayload || scannerStatus === 'scanning'
                            "
                            @click="scanNext"
                        >
                            Feed next ER QR
                        </button>
                        <button
                            type="button"
                            class="min-h-10 border border-stone-600 px-3 font-bold text-stone-50 disabled:cursor-not-allowed disabled:text-stone-500"
                            :disabled="
                                !nextPayload && automaticScanner === null
                            "
                            @click="toggleAutomaticScanner"
                        >
                            {{
                                automaticScanner === null
                                    ? 'Auto feed samples'
                                    : 'Stop auto'
                            }}
                        </button>
                    </div>
                    <button
                        type="button"
                        class="mt-2 min-h-10 w-full border border-stone-600 px-4 font-bold text-stone-50"
                        @click="resetScanner"
                    >
                        Reset canvass
                    </button>
                </section>

                <ScanLedger
                    :entries="scanEvents"
                    :documents="scannedDocuments"
                    :contests="simulation.ballot.contests"
                    :rendering-kit="simulation.document_rendering"
                    empty-message="No ER scans yet."
                    empty-documents-message="No completed ERs yet."
                />
            </aside>

            <section class="space-y-4">
                <section
                    v-if="lastReturn"
                    class="border border-emerald-300 bg-emerald-50 p-3 text-sm"
                >
                    <p class="font-bold text-emerald-900">
                        Last scan accepted: precinct
                        {{ lastReturn.precinct_id }}
                    </p>
                    <p class="mt-1 font-mono text-emerald-800">
                        {{ lastReturn.return_hash }}
                    </p>
                </section>

                <TallyBoard
                    eyebrow="City/municipal canvass sheet"
                    title="Scanner-derived ER totals"
                    :accepted-count="acceptedBallots"
                    accepted-label="accepted ballots canvassed"
                    :contests="simulation.ballot.contests"
                    :tally="runningTally"
                    :last-scan-delta="lastScanDelta"
                    :flash-key="lastScanFlashKey"
                >
                    <template #stats>
                        <p class="mt-2 font-bold">
                            {{ scannedCount }} ERs accepted
                        </p>
                        <p class="text-stone-600">
                            {{ scanEvents.length }} scanner events logged
                        </p>
                    </template>
                </TallyBoard>
            </section>
        </section>
    </main>
</template>
