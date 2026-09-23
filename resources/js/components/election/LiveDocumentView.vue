<script setup lang="ts">
import { computed, ref } from 'vue';
import BallotResultPanel from '@/components/election/BallotResultPanel.vue';
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
    pdf_available?: boolean;
    pdf_url?: string | null;
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
        eyebrow?: string;
        document: LedgerDocument | null;
        contests: Contest[];
        renderingKit: RenderingKit | null;
        emptyMessage?: string;
        pendingMessage?: string | null;
    }>(),
    {
        title: 'Live document view',
        eyebrow: 'Latest completed scan',
        emptyMessage: 'No completed document scan yet.',
        pendingMessage: null,
    },
);

const canRenderDocument = computed(
    () =>
        props.document !== null &&
        props.contests.length > 0 &&
        props.renderingKit !== null,
);
const selectedBallotView = ref<'result' | 'official' | 'preview'>('preview');
const canShowBallotViewSwitch = computed(
    () => canRenderDocument.value && props.document?.kind === 'official-ballot',
);
const hasBallotPreview = computed(
    () =>
        props.document?.kind === 'official-ballot' &&
        props.document.ballot?.pdf_available === true &&
        typeof props.document.ballot.pdf_url === 'string' &&
        props.document.ballot.pdf_url !== '',
);
</script>

<template>
    <section class="border border-stone-300 bg-white">
        <header
            class="flex flex-wrap items-start justify-between gap-3 border-b border-stone-200 p-4"
        >
            <div>
                <p class="text-xs font-black text-blue-800 uppercase">
                    {{ eyebrow }}
                </p>
                <h2 class="mt-1 text-xl font-black text-stone-950">
                    {{ title }}
                </h2>
                <p
                    v-if="document?.hash"
                    class="mt-1 max-w-2xl truncate font-mono text-xs text-stone-500"
                >
                    {{ document.hash }}
                </p>
            </div>
            <div
                v-if="document"
                class="border border-stone-300 px-3 py-2 text-right text-xs font-bold text-stone-700"
            >
                <p>{{ document.title }}</p>
                <p v-if="document.subtitle" class="font-mono">
                    {{ document.subtitle }}
                </p>
            </div>
        </header>

        <div
            v-if="canShowBallotViewSwitch"
            class="border-b border-stone-200 bg-stone-50 px-4 py-3"
        >
            <div
                class="inline-grid grid-cols-3 border border-stone-300 bg-white text-xs font-black"
            >
                <button
                    type="button"
                    class="px-4 py-2"
                    :class="
                        selectedBallotView === 'preview'
                            ? 'bg-stone-950 text-white'
                            : 'bg-white text-stone-700'
                    "
                    @click="selectedBallotView = 'preview'"
                >
                    Ballot Preview
                </button>
                <button
                    type="button"
                    class="border-l border-stone-300 px-4 py-2"
                    :class="
                        selectedBallotView === 'official'
                            ? 'bg-stone-950 text-white'
                            : 'bg-white text-stone-700'
                    "
                    @click="selectedBallotView = 'official'"
                >
                    Official Ballot
                </button>
                <button
                    type="button"
                    class="border-l border-stone-300 px-4 py-2"
                    :class="
                        selectedBallotView === 'result'
                            ? 'bg-stone-950 text-white'
                            : 'bg-white text-stone-700'
                    "
                    @click="selectedBallotView = 'result'"
                >
                    Ballot Result
                </button>
            </div>
        </div>

        <div class="max-h-[44rem] overflow-auto p-3">
            <div
                v-if="pendingMessage"
                class="border border-amber-300 bg-amber-50 p-4 text-sm font-semibold text-amber-950"
            >
                {{ pendingMessage }}
            </div>

            <BallotResultPanel
                v-else-if="
                    canRenderDocument &&
                    document?.kind === 'official-ballot' &&
                    selectedBallotView === 'result' &&
                    document.ballot
                "
                :contests="contests"
                :ballot="document.ballot"
            />
            <ReconstructedDocumentPanel
                v-else-if="
                    canRenderDocument &&
                    document?.kind === 'official-ballot' &&
                    selectedBallotView === 'official'
                "
                kind="official-ballot"
                :contests="contests"
                :rendering-kit="renderingKit!"
                :ballot="document.ballot"
            />
            <div
                v-else-if="
                    document?.kind === 'official-ballot' &&
                    selectedBallotView === 'preview'
                "
                class="border border-stone-300 bg-stone-100"
            >
                <iframe
                    v-if="hasBallotPreview"
                    :src="document.ballot?.pdf_url ?? undefined"
                    title="Rendered ballot PDF preview"
                    class="h-[42rem] w-full bg-white"
                />
                <div v-else class="bg-stone-50 p-5">
                    <p class="text-sm font-semibold text-stone-600">
                        No rendered ballot PDF is available for this scan.
                    </p>
                </div>
            </div>
            <ReconstructedDocumentPanel
                v-else-if="
                    canRenderDocument && document?.kind === 'election-return'
                "
                kind="election-return"
                :contests="contests"
                :rendering-kit="renderingKit!"
                :election-return="document.electionReturn"
            />
            <div v-else class="border border-stone-200 bg-stone-50 p-5">
                <p class="text-sm font-semibold text-stone-600">
                    {{ emptyMessage }}
                </p>
            </div>
        </div>
    </section>
</template>
