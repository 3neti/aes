<script setup lang="ts">
import { computed } from 'vue';

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

type DocumentReference = {
    type?: string;
    id?: string;
    hash?: string;
    asset_bundle_id?: string;
    asset_bundle_hash?: string;
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
    document_profile?: DocumentReference | null;
    selections: Record<string, string[]>;
};

type ReturnDocument = {
    type: 'election-return';
    precinct_id: string;
    return_scope: string;
    payload_hash: string;
    return_hash: string;
    document_profile?: DocumentReference | null;
    accepted_ballots: number;
    rejected_ballots: number;
    tally: Tally;
};

const props = defineProps<{
    kind: 'official-ballot' | 'election-return';
    contests: Contest[];
    renderingKit: RenderingKit;
    ballot?: BallotDocument | null;
    electionReturn?: ReturnDocument | null;
    partial?: {
        precinct_id: string;
        scanned: number;
        total: number;
    } | null;
}>();

const assets = computed(() => props.renderingKit.asset_bundle.assets);
const republicSeal = computed(() => assetDataUri('republic_seal'));
const bagongPilipinas = computed(() => assetDataUri('bagong_pilipinas'));
const comelec = computed(() => assetDataUri('comelec'));
const expectedProfile = computed(() =>
    props.kind === 'official-ballot'
        ? props.renderingKit.profiles.official_ballot
        : props.renderingKit.profiles.election_return,
);
const documentReference = computed(
    () => props.ballot?.document_profile ?? props.electionReturn?.document_profile ?? null,
);
const profileStatus = computed(() => {
    if (!documentReference.value) {
        return 'No document profile reference in payload';
    }

    if (
        documentReference.value.id !== expectedProfile.value?.id ||
        documentReference.value.hash !== expectedProfile.value?.hash ||
        documentReference.value.asset_bundle_id !== props.renderingKit.asset_bundle.id ||
        documentReference.value.asset_bundle_hash !== props.renderingKit.asset_bundle.hash
    ) {
        return 'Document profile mismatch';
    }

    return 'Document profile verified';
});

function isSelected(contest: Contest, candidate: Candidate): boolean {
    return (props.ballot?.selections[contest.id] ?? []).includes(candidate.id);
}

function voteCount(contest: Contest, candidate: Candidate): number {
    return props.electionReturn?.tally[contest.id]?.[candidate.id] ?? 0;
}

function assetDataUri(key: string): string | undefined {
    const dataUri = assets.value[key]?.data_uri;

    return typeof dataUri === 'string' ? dataUri : undefined;
}
</script>

<template>
    <section class="border border-stone-300 bg-white p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm font-bold text-blue-800">
                    Reconstructed document
                </p>
                <h2 class="mt-1 text-2xl font-bold">
                    {{
                        kind === 'official-ballot'
                            ? 'Official ballot view'
                            : 'Election return view'
                    }}
                </h2>
            </div>
            <p
                class="border px-3 py-2 text-sm font-bold"
                :class="
                    profileStatus === 'Document profile verified'
                        ? 'border-emerald-300 bg-emerald-50 text-emerald-900'
                        : 'border-amber-300 bg-amber-50 text-amber-900'
                "
            >
                {{ profileStatus }}
            </p>
        </div>

        <div
            v-if="partial && partial.scanned < partial.total"
            class="mt-5 border border-amber-300 bg-amber-50 p-5 text-amber-950"
        >
            <p class="text-sm font-bold uppercase">Partial QR set</p>
            <h3 class="mt-1 text-xl font-bold">
                Precinct {{ partial.precinct_id }}
            </h3>
            <p class="mt-2 font-mono text-sm">
                {{ partial.scanned }} of {{ partial.total }} ER QR payloads scanned
            </p>
        </div>

        <div
            v-else-if="kind === 'official-ballot' && ballot"
            class="mt-5 border-2 border-stone-950 bg-white p-4"
        >
            <header class="border-b-2 border-stone-950 pb-3">
                <div
                    class="grid gap-3 text-center uppercase sm:grid-cols-[1fr_auto_1fr] sm:items-center"
                >
                    <div>
                        <p class="text-xs font-black tracking-widest">
                            May 9, 2022 National and Local Elections
                        </p>
                        <p class="mt-1 text-[11px] font-bold text-stone-700">
                            Barangay 147, Tondo, National Capital Region - Manila
                        </p>
                    </div>
                    <div class="flex items-center justify-center gap-2">
                        <img
                            v-if="republicSeal"
                            :src="republicSeal"
                            alt="Republic seal"
                            class="h-10 w-10 object-contain"
                        />
                        <img
                            v-if="bagongPilipinas"
                            :src="bagongPilipinas"
                            alt="Bagong Pilipinas logo"
                            class="h-10 w-10 object-contain"
                        />
                        <img
                            v-if="comelec"
                            :src="comelec"
                            alt="COMELEC logo"
                            class="h-10 w-10 object-contain"
                        />
                    </div>
                    <div class="text-right">
                        <p class="text-xs font-black">Clustered Precinct ID</p>
                        <p class="font-mono text-sm font-black">
                            {{ ballot.precinct_id ?? '39010402' }}
                        </p>
                        <p class="mt-1 text-[11px] font-bold">
                            Serial {{ ballot.paper_ballot_serial }}
                        </p>
                    </div>
                </div>
            </header>

            <div class="mt-4 space-y-3">
                <section
                    v-for="contest in contests"
                    :key="contest.id"
                    class="break-inside-avoid border border-stone-900"
                >
                    <header
                        class="grid grid-cols-[1fr_auto] border-b border-stone-900 bg-blue-800 text-white"
                    >
                        <h3 class="px-3 py-2 text-sm font-black uppercase">
                            {{ contest.title }}
                        </h3>
                        <p
                            class="border-l border-white/50 px-3 py-2 text-sm font-black"
                        >
                            Vote for {{ contest.max_selections }}
                        </p>
                    </header>
                    <div class="grid gap-0 sm:grid-cols-2">
                        <div
                            v-for="(candidate, candidateIndex) in contest.candidates"
                            :key="candidate.id"
                            class="grid min-h-10 grid-cols-[34px_30px_1fr] items-center border-b border-stone-200 px-2 text-sm"
                        >
                            <span class="font-mono text-xs font-bold">
                                {{ candidateIndex + 1 }}
                            </span>
                            <span
                                class="flex h-4 w-4 items-center justify-center rounded-full border border-stone-900 text-[10px] font-black"
                                :class="
                                    isSelected(contest, candidate)
                                        ? 'bg-stone-950 text-white'
                                        : 'bg-white text-transparent'
                                "
                            >
                                X
                            </span>
                            <strong class="truncate text-xs uppercase">
                                {{ candidate.name }}
                            </strong>
                        </div>
                    </div>
                </section>
            </div>

            <p class="mt-4 break-all font-mono text-xs text-stone-600">
                Payload {{ ballot.payload_hash }}
            </p>
        </div>

        <div
            v-else-if="kind === 'election-return' && electionReturn"
            class="mt-5 border-2 border-stone-950 bg-white p-5"
        >
            <header class="border-b-2 border-stone-950 pb-4 text-center">
                <div class="mb-3 flex items-center justify-center gap-3">
                    <img
                        v-if="republicSeal"
                        :src="republicSeal"
                        alt="Republic seal"
                        class="h-12 w-12 object-contain"
                    />
                    <img
                        v-if="bagongPilipinas"
                        :src="bagongPilipinas"
                        alt="Bagong Pilipinas logo"
                        class="h-12 w-12 object-contain"
                    />
                    <img
                        v-if="comelec"
                        :src="comelec"
                        alt="COMELEC logo"
                        class="h-12 w-12 object-contain"
                    />
                </div>
                <p class="text-xs font-black tracking-widest uppercase">
                    Simulation copy - subject to COMELEC form approval
                </p>
                <h3 class="mt-1 text-xl font-black uppercase">
                    Election Return
                </h3>
                <p class="mt-1 font-mono text-sm">
                    Precinct {{ electionReturn.precinct_id }}
                </p>
            </header>

            <dl class="mt-4 grid gap-2 text-sm sm:grid-cols-2">
                <div class="flex justify-between gap-3 border border-stone-200 p-2">
                    <dt class="font-bold">Accepted ballots</dt>
                    <dd class="font-mono">{{ electionReturn.accepted_ballots }}</dd>
                </div>
                <div class="flex justify-between gap-3 border border-stone-200 p-2">
                    <dt class="font-bold">Rejected scans</dt>
                    <dd class="font-mono">{{ electionReturn.rejected_ballots }}</dd>
                </div>
            </dl>

            <div class="mt-4 space-y-4">
                <section
                    v-for="contest in contests"
                    :key="contest.id"
                    class="border border-stone-900"
                >
                    <h4
                        class="border-b border-stone-900 bg-stone-100 px-3 py-2 text-sm font-black uppercase"
                    >
                        {{ contest.title }}
                    </h4>
                    <div class="divide-y divide-stone-200">
                        <div
                            v-for="candidate in contest.candidates"
                            :key="candidate.id"
                            class="grid min-h-9 grid-cols-[1fr_64px] items-center px-3 text-sm"
                        >
                            <span class="truncate font-medium">
                                {{ candidate.name }}
                            </span>
                            <strong class="text-right font-mono">
                                {{ voteCount(contest, candidate) }}
                            </strong>
                        </div>
                    </div>
                </section>
            </div>

            <p class="mt-4 break-all font-mono text-xs text-stone-600">
                Return {{ electionReturn.return_hash }}
            </p>
        </div>

        <div v-else class="mt-5 border border-stone-200 bg-stone-50 p-5">
            <p class="text-sm font-semibold text-stone-600">
                Scan a complete payload to reconstruct the document.
            </p>
        </div>
    </section>
</template>
