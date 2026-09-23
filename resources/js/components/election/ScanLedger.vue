<script setup lang="ts">
import { computed, ref } from 'vue';
import ReconstructedDocumentPanel from '@/components/election/ReconstructedDocumentPanel.vue';

type Tally = Record<string, Record<string, number>>;

type Candidate = {
    id: string;
    name: string;
};

type Contest = {
    id: string;
    title: string;
    max_selections: number;
    candidates: Candidate[];
};

type RenderingKit = {
    profiles: Record<string, Record<string, unknown>>;
    asset_bundle: {
        id: string;
        hash: string;
        assets: Record<string, Record<string, string | null | undefined>>;
    };
};

type BallotDocument = {
    type: 'official-ballot';
    ballot_id: string;
    paper_ballot_serial: string | number | null;
    precinct_id?: string | null;
    payload_hash: string;
    document_profile?: Record<string, string> | null;
    selections: Record<string, string[]>;
};

type ReturnDocument = {
    type: 'election-return';
    precinct_id: string;
    return_scope: string;
    payload_hash: string;
    return_hash: string;
    document_profile?: Record<string, string> | null;
    accepted_ballots: number;
    rejected_ballots: number;
    tally: Tally;
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
    kind: 'official-ballot' | 'election-return';
    ballot?: BallotDocument | null;
    electionReturn?: ReturnDocument | null;
};

const props = withDefaults(
    defineProps<{
        title?: string;
        entries: ScanLogEntry[];
        documents?: LedgerDocument[];
        contests?: Contest[];
        renderingKit?: RenderingKit | null;
        emptyMessage: string;
        emptyDocumentsMessage?: string;
        pendingMessage?: string | null;
        newestFirst?: boolean;
        flashLatest?: boolean;
        visibleEntryLimit?: number | null;
        initialTab?: 'events' | 'documents';
    }>(),
    {
        title: 'Scan ledger',
        documents: () => [],
        contests: () => [],
        renderingKit: null,
        emptyDocumentsMessage: 'No completed documents yet.',
        pendingMessage: null,
        newestFirst: true,
        flashLatest: true,
        visibleEntryLimit: 17,
        initialTab: 'events',
    },
);

const activeTab = ref<'events' | 'documents'>(props.initialTab);
const selectedDocument = ref<LedgerDocument | null>(null);
const orderedEntries = computed(() =>
    props.newestFirst ? [...props.entries].reverse() : props.entries,
);
const orderedDocuments = computed(() =>
    props.newestFirst ? [...props.documents].reverse() : props.documents,
);
const displayedEntries = computed(() => {
    if (props.visibleEntryLimit === null || props.visibleEntryLimit < 1) {
        return orderedEntries.value;
    }

    return orderedEntries.value.slice(0, props.visibleEntryLimit);
});
const latestEntryId = computed(
    () => props.entries[props.entries.length - 1]?.id ?? null,
);
const latestDocumentId = computed(
    () => props.documents[props.documents.length - 1]?.id ?? null,
);
const hiddenEntryCount = computed(() =>
    Math.max(0, props.entries.length - displayedEntries.value.length),
);
const canRenderDocuments = computed(
    () => props.contests.length > 0 && props.renderingKit !== null,
);
const documentAssets = computed(
    () => props.renderingKit?.asset_bundle.assets ?? {},
);
const thumbnailLogos = computed(() =>
    ['republic_seal', 'bagong_pilipinas', 'comelec']
        .map((key) => documentAssets.value[key]?.data_uri)
        .filter((dataUri): dataUri is string => typeof dataUri === 'string'),
);

function statusLabel(status?: ScanLogEntry['status']): string {
    if (!status) {
        return 'Logged';
    }

    return status;
}

function statusClass(status?: ScanLogEntry['status']): string {
    if (status === 'accepted') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-900';
    }

    if (status === 'partial') {
        return 'border-amber-200 bg-amber-50 text-amber-900';
    }

    if (status === 'duplicate') {
        return 'border-stone-300 bg-stone-100 text-stone-700';
    }

    if (status === 'rejected') {
        return 'border-red-200 bg-red-50 text-red-900';
    }

    return 'border-stone-200 bg-stone-50 text-stone-700';
}

function scanDateTimeLabel(entry: ScanLogEntry): string | null {
    if (!entry.scanned_at) {
        return null;
    }

    const date = new Date(entry.scanned_at);

    if (Number.isNaN(date.getTime())) {
        return entry.scanned_at;
    }

    return new Intl.DateTimeFormat(undefined, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    }).format(date);
}

function openDocument(document: LedgerDocument): void {
    selectedDocument.value = document;
}

function closeDocument(): void {
    selectedDocument.value = null;
}
</script>

<template>
    <div>
        <section class="border border-stone-300 bg-white p-3">
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-bold">{{ title }}</h2>
                <div
                    class="grid grid-cols-2 border border-stone-300 text-xs font-black"
                >
                    <button
                        type="button"
                        class="px-3 py-1.5"
                        :class="
                            activeTab === 'events'
                                ? 'bg-stone-950 text-white'
                                : 'bg-white text-stone-700'
                        "
                        @click="activeTab = 'events'"
                    >
                        Events {{ entries.length }}
                    </button>
                    <button
                        type="button"
                        class="border-l border-stone-300 px-3 py-1.5"
                        :class="
                            activeTab === 'documents'
                                ? 'bg-stone-950 text-white'
                                : 'bg-white text-stone-700'
                        "
                        @click="activeTab = 'documents'"
                    >
                        Docs {{ documents.length }}
                    </button>
                </div>
            </div>

            <div v-if="activeTab === 'events'">
                <ol
                    v-if="displayedEntries.length > 0"
                    class="mt-2 max-h-[68rem] space-y-1.5 overflow-auto text-xs"
                >
                    <li
                        v-for="entry in displayedEntries"
                        :key="entry.id"
                        class="border bg-stone-50 p-2"
                        :class="
                            entry.id === latestEntryId
                                ? 'border-red-300 ring-1 ring-red-200'
                                : 'border-stone-200'
                        "
                        :data-latest="
                            entry.id === latestEntryId ? 'true' : undefined
                        "
                    >
                        <div
                            :class="
                                props.flashLatest && entry.id === latestEntryId
                                    ? 'scan-ledger-flash'
                                    : ''
                            "
                        >
                            <div class="flex justify-between gap-3">
                                <div class="min-w-0">
                                    <strong class="block">
                                        {{ entry.title }}
                                    </strong>
                                    <time
                                        v-if="scanDateTimeLabel(entry)"
                                        class="mt-0.5 block font-mono text-[10px] text-stone-500"
                                        :datetime="
                                            entry.scanned_at ?? undefined
                                        "
                                    >
                                        Scanned {{ scanDateTimeLabel(entry) }}
                                    </time>
                                </div>
                                <div class="flex min-w-0 items-center gap-2">
                                    <span
                                        v-if="entry.subtitle"
                                        class="truncate text-right font-mono text-xs"
                                    >
                                        {{ entry.subtitle }}
                                    </span>
                                    <span
                                        class="border px-1.5 py-0.5 text-[10px] font-black uppercase"
                                        :class="statusClass(entry.status)"
                                    >
                                        {{ statusLabel(entry.status) }}
                                    </span>
                                </div>
                            </div>
                            <p v-if="entry.meta" class="mt-1 text-stone-600">
                                {{ entry.meta }}
                            </p>
                            <p
                                v-if="entry.hash"
                                class="mt-1 font-mono text-xs break-all text-stone-600"
                            >
                                {{ entry.hash }}
                            </p>
                        </div>
                    </li>
                </ol>
                <p
                    v-if="displayedEntries.length === 0"
                    class="mt-2 text-sm text-stone-600"
                >
                    {{ emptyMessage }}
                </p>
                <p
                    v-if="hiddenEntryCount > 0"
                    class="mt-2 text-xs text-stone-500"
                >
                    {{ hiddenEntryCount }} older scan logs hidden.
                </p>
                <p v-if="pendingMessage" class="mt-3 text-sm text-stone-600">
                    {{ pendingMessage }}
                </p>
            </div>

            <div v-else>
                <div
                    v-if="orderedDocuments.length > 0"
                    class="mt-3 grid grid-cols-2 gap-2"
                >
                    <button
                        v-for="document in orderedDocuments"
                        :key="document.id"
                        type="button"
                        class="border bg-stone-50 p-2 text-left"
                        :class="
                            document.id === latestDocumentId
                                ? 'border-red-300 ring-1 ring-red-200'
                                : 'border-stone-200'
                        "
                        :data-latest="
                            document.id === latestDocumentId
                                ? 'true'
                                : undefined
                        "
                        @click="openDocument(document)"
                    >
                        <div
                            :class="
                                props.flashLatest &&
                                document.id === latestDocumentId
                                    ? 'scan-ledger-flash'
                                    : ''
                            "
                        >
                            <div
                                class="grid aspect-[3/4] content-start gap-1 border-2 border-stone-900 bg-white p-2"
                            >
                                <div
                                    class="grid grid-cols-[1fr_auto_1fr] items-center gap-1 border-b border-stone-900 pb-1"
                                >
                                    <img
                                        v-for="logo in thumbnailLogos"
                                        :key="logo"
                                        :src="logo"
                                        alt=""
                                        class="h-4 w-4 object-contain"
                                    />
                                    <template
                                        v-if="thumbnailLogos.length === 0"
                                    >
                                        <span
                                            class="h-2 w-2 rounded-full bg-blue-800"
                                        />
                                        <span
                                            class="h-2 w-2 rounded-full bg-yellow-400"
                                        />
                                        <span
                                            class="ml-auto h-2 w-2 rounded-full bg-red-700"
                                        />
                                    </template>
                                </div>
                                <p
                                    class="truncate text-[10px] font-black uppercase"
                                >
                                    {{
                                        document.kind === 'official-ballot'
                                            ? 'Official Ballot'
                                            : 'Election Return'
                                    }}
                                </p>
                                <div class="space-y-1">
                                    <span
                                        v-for="line in 7"
                                        :key="line"
                                        class="block h-1 bg-stone-300"
                                    />
                                </div>
                                <div
                                    class="mt-auto grid grid-cols-4 gap-0.5 border-t border-stone-900 pt-1"
                                >
                                    <span
                                        v-for="mark in 8"
                                        :key="mark"
                                        class="h-1.5 bg-stone-900"
                                    />
                                </div>
                            </div>
                            <strong class="mt-2 block truncate text-xs">
                                {{ document.title }}
                            </strong>
                            <p
                                v-if="document.subtitle"
                                class="mt-0.5 truncate font-mono text-[10px] text-stone-600"
                            >
                                {{ document.subtitle }}
                            </p>
                            <p
                                v-if="document.meta"
                                class="mt-1 text-[10px] text-stone-600"
                            >
                                {{ document.meta }}
                            </p>
                        </div>
                    </button>
                </div>
                <p v-else class="mt-2 text-sm text-stone-600">
                    {{ emptyDocumentsMessage }}
                </p>
            </div>
        </section>

        <div
            v-if="selectedDocument"
            class="fixed inset-0 z-50 grid place-items-center bg-stone-950/75 p-4"
            role="dialog"
            aria-modal="true"
            @click.self="closeDocument"
        >
            <section
                class="max-h-[92vh] w-full max-w-5xl overflow-auto border border-stone-300 bg-white p-3 shadow-2xl"
            >
                <div class="mb-3 flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-black text-blue-800 uppercase">
                            Ledger document
                        </p>
                        <h2 class="text-xl font-black">
                            {{ selectedDocument.title }}
                        </h2>
                        <p
                            v-if="selectedDocument.hash"
                            class="mt-1 max-w-2xl truncate font-mono text-xs text-stone-600"
                        >
                            {{ selectedDocument.hash }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="border border-stone-400 px-3 py-2 text-sm font-black"
                        @click="closeDocument"
                    >
                        Close
                    </button>
                </div>

                <ReconstructedDocumentPanel
                    v-if="
                        canRenderDocuments &&
                        selectedDocument.kind === 'official-ballot'
                    "
                    kind="official-ballot"
                    :contests="contests"
                    :rendering-kit="renderingKit!"
                    :ballot="selectedDocument.ballot"
                />
                <ReconstructedDocumentPanel
                    v-else-if="
                        canRenderDocuments &&
                        selectedDocument.kind === 'election-return'
                    "
                    kind="election-return"
                    :contests="contests"
                    :rendering-kit="renderingKit!"
                    :election-return="selectedDocument.electionReturn"
                />
                <div v-else class="border border-stone-200 bg-stone-50 p-5">
                    <p class="text-sm font-semibold text-stone-600">
                        This ledger entry does not have a renderable document.
                    </p>
                </div>
            </section>
        </div>
    </div>
</template>

<style scoped>
.scan-ledger-flash {
    animation: scan-ledger-latest-flash 900ms ease-in-out 3;
}

@keyframes scan-ledger-latest-flash {
    0%,
    100% {
        background: transparent;
    }

    50% {
        background: rgb(254 226 226);
    }
}
</style>
