<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
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
    precinctId: string;
    returnHash: string;
    scannedReturn: ReturnScan;
    receivedParts: Record<number, string>;
};

type ScannerState = {
    station_id: string;
    revision: number;
    accepted_return_hashes: string[];
    latest_accepted_return_hash?: string | null;
    scan_events: ScanLogEntry[];
    current_multipart?: {
        group_id: string;
        total_parts: number;
        received_parts: number[];
        precinct_id: string | null;
        return_hash: string | null;
    } | null;
    latest_message?: string | null;
    latest_status?: string | null;
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
        publicBoard: string;
        scannerState: string;
        scannerIngest: string;
        scannerReset: string;
        simulatorTick: string;
    };
}>();

const stationId = 'canvassing-demo-city';
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
const scannerCaptureEnabled = ref(true);
const autoSubmitPastedScans = ref(true);
const scannerStatePoller = ref<number | null>(null);
const scannerStateRevision = ref(0);
const keyboardScanBuffer = ref('');
const manualScanPayload = ref('');
const hardwareScanStatus = ref('Ready for scanner input.');
const manualScanInput = ref<HTMLTextAreaElement | null>(null);

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
const nextDemoPayloadLabel = computed(() => {
    if (!nextReturn.value) {
        return 'No pending demo QR';
    }

    if (nextPayloadMetadata.value?.kind === 'fragment') {
        return `ER ${nextReturn.value.sequence} · Part ${nextPayloadMetadata.value.partNumber} of ${nextPayloadMetadata.value.totalParts}`;
    }

    if (nextPayloadMetadata.value?.kind === 'complete') {
        return `ER ${nextReturn.value.sequence} · Single QR document`;
    }

    return `ER ${nextReturn.value.sequence} · Unsupported QR envelope`;
});
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
    const payload = nextPayload.value;

    window.setTimeout(() => {
        if (!payload || !scannedReturn) {
            scannerStatus.value = 'ready';

            return;
        }

        advanceSampleCursor(scannedReturn);
        void submitScanPayload(payload, 'demo_feed').finally(() => {
            scannerStatus.value = 'ready';
        });
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
    keyboardScanBuffer.value = '';
    manualScanPayload.value = '';
    scannerStatus.value = 'ready';
    void resetScannerState();
}

function submitManualScannerInput(): void {
    submitManualScan('browser_paste');
}

function submitManualScan(source = 'browser_paste'): void {
    void submitScanPayload(manualScanPayload.value, source);
    manualScanPayload.value = '';
}

function csrfToken(): string | null {
    return (
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? null
    );
}

async function submitScanPayload(
    payload: string,
    source: string,
): Promise<void> {
    const normalizedPayload = payload.trim();

    if (!normalizedPayload) {
        hardwareScanStatus.value = 'No scan payload received.';

        return;
    }

    try {
        const token = csrfToken();
        const response = await fetch(props.actions.scannerIngest, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-CSRF-TOKEN': token } : {}),
            },
            body: JSON.stringify({
                station_id: stationId,
                source,
                payload: normalizedPayload,
            }),
        });
        const result = await response.json();

        if (!response.ok && !result?.state) {
            throw new Error(
                result?.message ??
                    'The scan was not recorded. Please try again.',
            );
        }

        applyScannerState(result.state);
    } catch (error) {
        hardwareScanStatus.value =
            error instanceof Error
                ? error.message
                : 'The scan was not recorded. Please try again.';
    }
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

        if (response.ok) {
            applyScannerState(state);
        }
    } catch {
        hardwareScanStatus.value = 'Scanner state is temporarily unavailable.';
    }
}

async function resetScannerState(): Promise<void> {
    try {
        const token = csrfToken();
        const response = await fetch(props.actions.scannerReset, {
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
        const state = await response.json();

        if (response.ok) {
            scannedQrPayloadsInCurrentReturn.value = 0;
            applyScannerState(state);
        }
    } catch {
        hardwareScanStatus.value = 'Scanner reset did not finish.';
    }
}

function applyScannerState(state: ScannerState): void {
    const previousRevision = scannerStateRevision.value;

    scannerStateRevision.value = state.revision;
    scanEvents.value = state.scan_events;

    const acceptedHashes = new Set(state.accepted_return_hashes);
    const acceptedReturns = props.simulation.scanner.returns.filter(
        (scannedReturn) => acceptedHashes.has(scannedReturn.return_hash),
    );

    runningTally.value = cloneTally(props.simulation.scanner.initial_tally);
    scannedReturns.value = [];
    lastScanDelta.value = {};

    acceptedReturns.forEach((scannedReturn) => {
        const delta = deltaForTally(scannedReturn.tally);
        addTally(scannedReturn.tally);
        scannedReturns.value.push(scannedReturn);

        if (scannedReturn.return_hash === state.latest_accepted_return_hash) {
            lastScanDelta.value = delta;
        }
    });

    if (state.current_multipart) {
        const scannedReturn =
            props.simulation.scanner.returns.find(
                (candidate) =>
                    candidate.return_hash ===
                    state.current_multipart?.return_hash,
            ) ?? null;

        currentMultipartBuffer.value = scannedReturn
            ? {
                  groupId: state.current_multipart.group_id,
                  totalParts: state.current_multipart.total_parts,
                  precinctId: state.current_multipart.precinct_id ?? '',
                  returnHash: state.current_multipart.return_hash ?? '',
                  scannedReturn,
                  receivedParts: Object.fromEntries(
                      state.current_multipart.received_parts.map(
                          (partNumber) => [partNumber, 'received'],
                      ),
                  ),
              }
            : null;
    } else {
        currentMultipartBuffer.value = null;
    }

    if (
        state.latest_accepted_return_hash &&
        state.latest_status === 'accepted' &&
        state.revision !== previousRevision
    ) {
        lastScanFlashKey.value += 1;
    }

    hardwareScanStatus.value =
        state.latest_message ?? 'Ready for scanner input.';
}

function clearScannerPresentation(message = 'Ready for scanner input.'): void {
    scannedReturns.value = [];
    scannedQrPayloadsInCurrentReturn.value = 0;
    currentMultipartBuffer.value = null;
    scanEvents.value = [];
    runningTally.value = cloneTally(props.simulation.scanner.initial_tally);
    lastScanDelta.value = {};
    lastScanFlashKey.value += 1;
    scannerStateRevision.value = 0;
    hardwareScanStatus.value = message;
}

function advanceSampleCursor(scannedReturn: ReturnScan): void {
    scannedQrPayloadsInCurrentReturn.value += 1;

    if (
        scannedQrPayloadsInCurrentReturn.value >= scannedReturn.payloads.length
    ) {
        scannedQrPayloadsInCurrentReturn.value = 0;
    }
}

function handleGlobalScannerKeydown(event: KeyboardEvent): void {
    if (!scannerCaptureEnabled.value || shouldIgnoreGlobalKeydown(event)) {
        return;
    }

    if (event.key === 'Escape') {
        keyboardScanBuffer.value = '';
        hardwareScanStatus.value = 'Scan buffer cleared.';
        event.preventDefault();

        return;
    }

    if (event.key === 'Enter' || event.key === 'NumpadEnter') {
        if (keyboardScanBuffer.value !== '') {
            void submitScanPayload(keyboardScanBuffer.value, 'keyboard_wedge');
            keyboardScanBuffer.value = '';
            event.preventDefault();
        }

        return;
    }

    if (
        event.key.length !== 1 ||
        event.altKey ||
        event.ctrlKey ||
        event.metaKey
    ) {
        return;
    }

    keyboardScanBuffer.value += event.key;

    if (keyboardScanBuffer.value.length > 16384) {
        keyboardScanBuffer.value = keyboardScanBuffer.value.slice(-16384);
    }
}

function handleGlobalScannerPaste(event: ClipboardEvent): void {
    if (!scannerCaptureEnabled.value || shouldIgnoreGlobalPaste(event)) {
        return;
    }

    receivePastedScan(event);
}

function handleManualScannerPaste(event: ClipboardEvent): void {
    receivePastedScan(event);
}

function receivePastedScan(event: ClipboardEvent): void {
    const payload = pastedPayloadFrom(
        event.clipboardData?.getData('text/plain') ?? '',
    );

    if (payload === '') {
        return;
    }

    manualScanPayload.value = payload;
    keyboardScanBuffer.value = '';
    hardwareScanStatus.value = payload.startsWith('truth://')
        ? autoSubmitPastedScans.value
            ? 'Pasted scan payload. Auto-submitting.'
            : 'Pasted scan payload. Press Submit scan or Enter.'
        : 'Pasted text is not a truth:// URI.';
    event.preventDefault();
    event.stopPropagation();

    void nextTick(() => {
        manualScanInput.value?.focus();

        if (payload.startsWith('truth://') && autoSubmitPastedScans.value) {
            window.setTimeout(() => {
                if (manualScanPayload.value === payload) {
                    submitManualScan('browser_paste');
                }
            }, 180);
        }
    });
}

function shouldIgnoreGlobalKeydown(event: KeyboardEvent): boolean {
    const target = event.target;

    if (!(target instanceof HTMLElement)) {
        return false;
    }

    if (target.closest('[data-scanner-manual-input]')) {
        return true;
    }

    if (target.isContentEditable) {
        return true;
    }

    if (target instanceof HTMLSelectElement) {
        return true;
    }

    if (target instanceof HTMLTextAreaElement) {
        return !target.readOnly;
    }

    if (target instanceof HTMLInputElement) {
        return isTextEntryInput(target) && !target.readOnly;
    }

    return false;
}

function shouldIgnoreGlobalPaste(event: ClipboardEvent): boolean {
    const target = event.target;

    if (!(target instanceof HTMLElement)) {
        return false;
    }

    if (target.closest('[data-scanner-manual-input]')) {
        return true;
    }

    if (target.isContentEditable) {
        return true;
    }

    if (target instanceof HTMLTextAreaElement) {
        return true;
    }

    if (target instanceof HTMLInputElement) {
        return isTextEntryInput(target) && !target.readOnly;
    }

    if (target instanceof HTMLSelectElement) {
        return true;
    }

    return false;
}

function isTextEntryInput(target: HTMLInputElement): boolean {
    return [
        '',
        'date',
        'datetime-local',
        'email',
        'month',
        'number',
        'password',
        'search',
        'tel',
        'text',
        'time',
        'url',
        'week',
    ].includes(target.type);
}

function pastedPayloadFrom(text: string): string {
    const trimmedText = text.trim();
    const truthPayload = trimmedText
        .split(/\r?\n/)
        .map((line) => line.trim())
        .find((line) => line.startsWith('truth://'));

    return truthPayload ?? trimmedText;
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

watch(
    () => props.simulation.run?.generated_at ?? null,
    (generatedAt, previousGeneratedAt) => {
        if (generatedAt !== previousGeneratedAt) {
            stopAutomaticScanner();
            keyboardScanBuffer.value = '';
            manualScanPayload.value = '';
            clearScannerPresentation();
            void fetchScannerState();
        }
    },
);

onMounted(() => {
    void fetchScannerState();
    scannerStatePoller.value = window.setInterval(() => {
        void fetchScannerState();
    }, 1000);
    window.addEventListener('keydown', handleGlobalScannerKeydown);
    window.addEventListener('paste', handleGlobalScannerPaste);
});

onBeforeUnmount(() => {
    stopAutomaticScanner();
    if (scannerStatePoller.value !== null) {
        window.clearInterval(scannerStatePoller.value);
    }
    window.removeEventListener('keydown', handleGlobalScannerKeydown);
    window.removeEventListener('paste', handleGlobalScannerPaste);
});
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
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <a
                            :href="`${actions.publicBoard}?view=all`"
                            class="min-h-10 border border-stone-300 px-3 py-2 text-center text-sm font-bold text-blue-800"
                        >
                            Public board
                        </a>
                        <a
                            :href="`${actions.publicBoard}?view=national`"
                            class="min-h-10 border border-stone-300 px-3 py-2 text-center text-sm font-bold text-blue-800"
                        >
                            National view
                        </a>
                    </div>
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

                    <div class="mt-3 border border-stone-700 bg-stone-900 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-bold">
                                    Keyboard wedge
                                </h3>
                                <p class="text-xs text-stone-400">
                                    {{ hardwareScanStatus }}
                                </p>
                            </div>
                            <label
                                class="flex items-center gap-2 text-xs font-black"
                            >
                                <input
                                    v-model="scannerCaptureEnabled"
                                    type="checkbox"
                                    class="h-4 w-4 accent-yellow-300"
                                />
                                Capture
                            </label>
                        </div>
                        <label
                            class="mt-3 flex items-center justify-between gap-3 border border-stone-700 bg-black/40 p-2 text-xs font-black"
                        >
                            <span>Auto-submit paste</span>
                            <input
                                v-model="autoSubmitPastedScans"
                                type="checkbox"
                                class="h-4 w-4 accent-yellow-300"
                            />
                        </label>
                        <div class="mt-2 grid gap-2">
                            <textarea
                                ref="manualScanInput"
                                v-model="manualScanPayload"
                                data-scanner-manual-input
                                class="h-20 w-full resize-none border border-stone-700 bg-black p-2 font-mono text-[10px] text-yellow-200 outline-none"
                                placeholder="truth://..."
                                @paste="handleManualScannerPaste"
                                @keydown.enter.prevent="submitManualScannerInput"
                            />
                            <div class="grid grid-cols-2 gap-2">
                                <button
                                    type="button"
                                    class="min-h-10 border border-stone-600 px-3 font-bold text-stone-50"
                                    @click="submitManualScannerInput"
                                >
                                    Submit scan
                                </button>
                                <button
                                    type="button"
                                    class="min-h-10 border border-stone-600 px-3 font-bold text-stone-50"
                                    @click="manualScanPayload = ''"
                                >
                                    Clear
                                </button>
                            </div>
                        </div>
                    </div>

                    <div
                        class="mt-3 overflow-hidden border border-stone-700 bg-stone-900"
                    >
                        <div
                            class="h-1 bg-emerald-400 transition-all duration-300"
                            :style="{ width: `${scannerPulsePercent}%` }"
                        />
                        <div class="grid gap-3 p-3">
                            <div>
                                <p class="text-xs font-bold text-stone-400">
                                    NEXT DEMO QR
                                </p>
                                <p class="mt-1 text-sm font-black text-sky-100">
                                    {{ nextDemoPayloadLabel }}
                                </p>
                                <p
                                    v-if="nextReturn"
                                    class="mt-1 text-xs text-stone-400"
                                >
                                    Precinct {{ nextReturn.precinct_id }} ·
                                    payload hidden
                                </p>
                                <p v-else class="mt-1 text-xs text-stone-400">
                                    All generated ER payloads have been scanned.
                                </p>
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <button
                                    type="button"
                                    class="min-h-10 bg-yellow-300 px-3 font-bold text-stone-950 disabled:cursor-not-allowed disabled:bg-stone-700 disabled:text-stone-400"
                                    :disabled="
                                        !nextPayload ||
                                        scannerStatus === 'scanning'
                                    "
                                    @click="scanNext"
                                >
                                    Demo feed next QR
                                </button>
                                <button
                                    type="button"
                                    class="min-h-10 border border-stone-600 px-3 font-bold text-stone-50 disabled:cursor-not-allowed disabled:text-stone-500"
                                    :disabled="
                                        !nextPayload &&
                                        automaticScanner === null
                                    "
                                    @click="toggleAutomaticScanner"
                                >
                                    {{
                                        automaticScanner === null
                                            ? 'Auto demo feed'
                                            : 'Stop auto'
                                    }}
                                </button>
                            </div>
                            <button
                                type="button"
                                class="min-h-10 w-full border border-stone-600 px-4 font-bold text-stone-50"
                                @click="resetScanner"
                            >
                                Reset canvass
                            </button>
                        </div>
                    </div>

                    <div
                        class="mt-3 border border-sky-700 bg-sky-950/50 p-3 text-xs text-sky-50"
                    >
                        <h3 class="text-sm font-bold">
                            Scanner setup cheat sheet
                        </h3>
                        <div class="mt-2 grid gap-3">
                            <section class="grid gap-1.5">
                                <p class="font-black text-yellow-200">
                                    Option A: scanner acts like a keyboard
                                </p>
                                <ol
                                    class="grid list-decimal gap-1 pl-4 text-sky-100"
                                >
                                    <li>
                                        Connect the scanner to the device that
                                        has this page open.
                                    </li>
                                    <li>
                                        Configure the scanner suffix as Enter
                                        or carriage return.
                                    </li>
                                    <li>
                                        Keep Capture on, then scan each ER QR
                                        code.
                                    </li>
                                </ol>
                            </section>

                            <section class="grid gap-1.5">
                                <p class="font-black text-yellow-200">
                                    Option B: scanner is on the Linux box
                                </p>
                                <ol
                                    class="grid list-decimal gap-1 pl-4 text-sky-100"
                                >
                                    <li>
                                        Keep this page open on the tablet or
                                        monitor.
                                    </li>
                                    <li>
                                        Feed one truth:// payload per line to
                                        the bridge command.
                                    </li>
                                    <li>
                                        The page updates automatically through
                                        the saved scanner log.
                                    </li>
                                </ol>
                                <code
                                    class="mt-1 block overflow-x-auto border border-sky-800 bg-black/60 p-2 font-mono text-[10px] text-yellow-100"
                                >
                                    scanner-reader-command | php artisan
                                    election:canvassing-scanner-ingest
                                </code>
                            </section>
                        </div>
                    </div>
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
