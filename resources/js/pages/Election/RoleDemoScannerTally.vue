<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import LiveDocumentView from '@/components/election/LiveDocumentView.vue';
import PrecinctTallyBoard from '@/components/election/PrecinctTallyBoard.vue';
import ScanLedger from '@/components/election/ScanLedger.vue';
import { type CandidateCodeMapEntry } from '@/components/election/truthQr';
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
    pdf_available?: boolean;
    pdf_url?: string | null;
    document_profile?: Record<string, string> | null;
    selections: Record<string, string[]>;
    this_ballot_tally: Tally;
};

type ScanLogEntry = {
    id: string;
    title: string;
    subtitle?: string | null;
    scanned_at?: string | null;
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

type ScannerState = {
    station_id: string;
    revision: number;
    accepted_ballot_hashes: string[];
    latest_accepted_ballot_hash?: string | null;
    accepted_count: number;
    tally: Tally;
    scan_events: ScanLogEntry[];
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
            candidate_code_map: {
                mapping_hash: string | null;
                candidates: Record<string, CandidateCodeMapEntry>;
            };
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
    scannerState: ScannerState;
    actions: {
        scannerState: string;
        scannerIngest: string;
        scannerReset: string;
        simulatorTick: string;
        publicBoard: string;
        roleDemo: string;
    };
}>();

const stationId = 'role-demo-precinct';
const liveScannerState = ref<ScannerState>({ ...props.scannerState });
const selectedBallotHash = ref<string | null>(
    selectedHashFromState(props.scannerState),
);
const scannedBallots = computed(() => liveScannerState.value.accepted_ballots);
const scanEvents = computed(() => liveScannerState.value.scan_events);
const runningTally = computed(() => liveScannerState.value.tally);
const lastScanDelta = computed(() =>
    latestAcceptedBallot.value
        ? deltaForTally(latestAcceptedBallot.value.this_ballot_tally)
        : {},
);
const lastScanFlashKey = computed(() => liveScannerState.value.revision);
const scannerStatus = ref<'ready' | 'scanning'>('ready');
const automaticScanner = ref<number | null>(null);
const scannerCaptureEnabled = ref(true);
const autoSubmitPastedScans = ref(true);
const keyboardScanBuffer = ref('');
const manualScanPayload = ref('');
const hardwareScanStatus = ref('Ready for scanner input.');
const manualScanInput = ref<HTMLTextAreaElement | null>(null);
const statePoller = ref<number | null>(null);
const lastUpdatedAt = ref<string | null>(null);

const nextBallot = computed(
    () =>
        props.simulation.scanner.ballots.find(
            (ballot) =>
                !liveScannerState.value.accepted_ballot_hashes.includes(
                    ballot.payload_hash,
                ),
        ) ?? null,
);
const lastBallot = computed(
    () => scannedBallots.value[scannedBallots.value.length - 1] ?? null,
);
const latestAcceptedBallot = computed(
    () =>
        scannedBallots.value.find(
            (ballot) =>
                ballot.payload_hash ===
                liveScannerState.value.latest_accepted_ballot_hash,
        ) ?? lastBallot.value,
);
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
const scannedCount = computed(() => liveScannerState.value.accepted_count);
const scannerPulsePercent = computed(() =>
    scannerStatus.value === 'scanning' ? 72 : scannedCount.value > 0 ? 100 : 0,
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

const scannedDocuments = computed<LedgerDocument[]>(() =>
    scannedBallots.value.map((ballot) => ballotLedgerDocument(ballot)),
);
const selectedScannedDocument = computed<LedgerDocument | null>(() =>
    selectedBallot.value ? ballotLedgerDocument(selectedBallot.value) : null,
);
const liveDocumentPendingMessage = computed(() =>
    liveScannerState.value.latest_status === 'partial'
        ? liveScannerState.value.latest_message ||
          'Waiting for remaining QR payload parts.'
        : null,
);

function scanNext(): void {
    if (!nextBallot.value || scannerStatus.value === 'scanning') {
        return;
    }

    scannerStatus.value = 'scanning';
    const payload = nextBallot.value.payload;

    window.setTimeout(() => {
        void processBallotPayload(payload, 'demo_feed');
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

async function resetScanner(): Promise<void> {
    stopAutomaticScanner();
    keyboardScanBuffer.value = '';
    manualScanPayload.value = '';
    scannerStatus.value = 'ready';

    try {
        const response = await fetch(props.actions.scannerReset, {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify({ station_id: stationId }),
        });
        const state = await response.json();

        if (response.ok) {
            liveScannerState.value = state;
            selectedBallotHash.value = selectedHashFromState(state);
            hardwareScanStatus.value = state.latest_message;
        }
    } catch {
        hardwareScanStatus.value = 'Scanner reset failed.';
    }
}

function submitManualScan(): void {
    void processBallotPayload(manualScanPayload.value, 'keyboard_wedge');
    manualScanPayload.value = '';
}

async function processBallotPayload(
    payload: string,
    source: string,
): Promise<void> {
    const normalizedPayload = payload.trim();

    if (!normalizedPayload) {
        hardwareScanStatus.value = 'No scan payload received.';

        return;
    }

    scannerStatus.value = 'scanning';

    try {
        const response = await fetch(props.actions.scannerIngest, {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify({
                station_id: stationId,
                source,
                payload: normalizedPayload,
            }),
        });
        const result = await response.json();

        if (result.state) {
            liveScannerState.value = result.state;
            selectedBallotHash.value = selectedHashFromState(result.state);
        }

        hardwareScanStatus.value =
            result.state?.latest_message ??
            result.event?.meta ??
            'Scan recorded.';
    } catch {
        hardwareScanStatus.value =
            'Scanner endpoint is temporarily unavailable.';
    } finally {
        scannerStatus.value = 'ready';
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

        if (response.ok && state.revision !== liveScannerState.value.revision) {
            const previousLatestHash =
                liveScannerState.value.latest_accepted_ballot_hash;
            liveScannerState.value = state;

            if (
                state.latest_accepted_ballot_hash !== null &&
                state.latest_accepted_ballot_hash !== previousLatestHash
            ) {
                selectedBallotHash.value = selectedHashFromState(state);
            }

            hardwareScanStatus.value =
                state.latest_message ?? 'Scanner state refreshed.';
            lastUpdatedAt.value = new Date().toLocaleTimeString();
        }
    } catch {
        // Keep the last visible tally if a timer refresh misses a beat.
    }
}

function startStatePolling(): void {
    stopStatePolling();

    statePoller.value = window.setInterval(() => {
        if (document.visibilityState === 'hidden') {
            return;
        }

        void fetchScannerState();
    }, 1000);
}

function stopStatePolling(): void {
    if (statePoller.value !== null) {
        window.clearInterval(statePoller.value);
    }

    statePoller.value = null;
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
            void processBallotPayload(
                keyboardScanBuffer.value,
                'keyboard_wedge',
            );
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

    if (keyboardScanBuffer.value.length > 4096) {
        keyboardScanBuffer.value = keyboardScanBuffer.value.slice(-4096);
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
                    submitManualScan();
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

    Object.entries(tally).forEach(([contestId, candidateVotes]) => {
        Object.entries(candidateVotes).forEach(([candidateId, addedVotes]) => {
            if (addedVotes < 1) {
                return;
            }

            const previousTotal =
                runningTally.value[contestId]?.[candidateId] ?? 0;

            delta[contestId] ??= {};
            delta[contestId][candidateId] = {
                previousTotal: Math.max(0, previousTotal - addedVotes),
                addedVotes,
                finalTotal: previousTotal,
            };
        });
    });

    return delta;
}

function csrfToken(): string | null {
    return (
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? null
    );
}

function jsonHeaders(): Record<string, string> {
    const token = csrfToken();

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(token ? { 'X-CSRF-TOKEN': token } : {}),
    };
}

function sourceLabel(source: string): string {
    return source === 'sealed-role-demo-ballots'
        ? 'Sealed role-demo ballot QR payloads'
        : 'Generated demo ballot payloads';
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
    window.addEventListener('keydown', handleGlobalScannerKeydown);
    window.addEventListener('paste', handleGlobalScannerPaste);
    void fetchScannerState();
    startStatePolling();
});

onBeforeUnmount(() => {
    stopAutomaticScanner();
    stopStatePolling();
    window.removeEventListener('keydown', handleGlobalScannerKeydown);
    window.removeEventListener('paste', handleGlobalScannerPaste);
});
</script>

<template>
    <Head :title="`${precinct.code} QR scanner tally`" />

    <main class="min-h-screen bg-stone-100 text-stone-950">
        <div class="grid h-1.5 grid-cols-3">
            <span class="bg-blue-800" /><span class="bg-yellow-400" /><span
                class="bg-red-700"
            />
        </div>

        <section class="mx-auto max-w-[1800px] space-y-3 px-3 py-4">
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
                        :pending-message="liveDocumentPendingMessage"
                        empty-message="No completed ballot scan yet."
                    />
                </section>

                <PrecinctTallyBoard
                    eyebrow="Live tally sheet"
                    title="Ballot payload count"
                    :accepted-count="scannedCount"
                    accepted-label="accepted scans"
                    :contests="simulation.ballot.contests"
                    :tally="runningTally"
                    view="all"
                    :last-scan-delta="lastScanDelta"
                    :flash-key="lastScanFlashKey"
                    :revision="liveScannerState.revision"
                    :last-updated-at="lastUpdatedAt"
                    :status-message="liveScannerState.latest_message"
                />
            </section>

            <section
                class="grid gap-3 lg:grid-cols-[260px_minmax(0,1fr)] xl:grid-cols-[280px_minmax(0,0.95fr)_minmax(360px,0.8fr)]"
            >
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
                                @keydown.enter.prevent="submitManualScan"
                            />
                            <div class="grid grid-cols-2 gap-2">
                                <button
                                    type="button"
                                    class="secondary-button"
                                    @click="submitManualScan"
                                >
                                    Submit scan
                                </button>
                                <button
                                    type="button"
                                    class="secondary-button"
                                    @click="manualScanPayload = ''"
                                >
                                    Clear
                                </button>
                            </div>
                        </div>
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
                    class="lg:col-span-2 xl:col-span-1"
                    :entries="scanEvents"
                    :documents="scannedDocuments"
                    :contests="simulation.ballot.contests"
                    :rendering-kit="simulation.document_rendering"
                    empty-message="No ballot scans yet."
                    empty-documents-message="No completed ballots yet."
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
