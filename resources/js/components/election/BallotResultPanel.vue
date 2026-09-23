<script setup lang="ts">
import { computed } from 'vue';

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

type BallotDocument = {
    ballot_id: string;
    paper_ballot_serial: string | number | null;
    precinct_id?: string | null;
    payload_hash: string;
    selections: Record<string, string[]>;
};

type ContestResult = {
    contest: Contest;
    selectedCandidates: Candidate[];
    selectedCount: number;
    overVote: boolean;
};

const props = defineProps<{
    ballot: BallotDocument;
    contests: Contest[];
}>();

const contestResults = computed<ContestResult[]>(() =>
    props.contests.map((contest) => {
        const selectedIds = props.ballot.selections[contest.id] ?? [];
        const selectedCandidates = selectedIds
            .map((candidateId) =>
                contest.candidates.find(
                    (candidate) => candidate.id === candidateId,
                ),
            )
            .filter(
                (candidate): candidate is Candidate => candidate !== undefined,
            );

        return {
            contest,
            selectedCandidates,
            selectedCount: selectedIds.length,
            overVote: selectedIds.length > contest.max_selections,
        };
    }),
);
</script>

<template>
    <section class="border border-stone-300 bg-white p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm font-bold text-blue-800">
                    Ballot result view
                </p>
                <h2 class="mt-1 text-2xl font-bold">Voted choices</h2>
            </div>
            <div class="border border-stone-300 px-3 py-2 text-right text-xs">
                <p class="font-mono font-black">
                    {{ ballot.paper_ballot_serial }}
                </p>
                <p class="mt-1 text-stone-600">
                    Precinct {{ ballot.precinct_id ?? 'unknown' }}
                </p>
            </div>
        </div>

        <div class="mt-5 grid gap-3">
            <section
                v-for="result in contestResults"
                :key="result.contest.id"
                class="grid gap-3 border border-stone-200 p-3 sm:grid-cols-[minmax(11rem,18rem)_1fr]"
            >
                <div>
                    <h3 class="text-sm font-black text-stone-950 uppercase">
                        {{ result.contest.title }}
                    </h3>
                    <p class="mt-1 text-xs font-semibold text-stone-500">
                        Vote for {{ result.contest.max_selections }}
                    </p>
                    <p
                        v-if="result.overVote"
                        class="mt-2 inline-flex border border-red-300 bg-red-50 px-2 py-1 text-xs font-black text-red-900 uppercase"
                    >
                        Over-vote
                    </p>
                </div>

                <div
                    v-if="result.selectedCandidates.length > 0"
                    class="grid gap-2"
                >
                    <div
                        v-for="candidate in result.selectedCandidates"
                        :key="candidate.id"
                        class="border border-emerald-200 bg-emerald-50 px-3 py-2"
                    >
                        <p
                            class="text-base font-black text-emerald-950 uppercase"
                        >
                            {{ candidate.name }}
                        </p>
                    </div>
                </div>
                <div
                    v-else
                    class="border border-stone-200 bg-stone-50 px-3 py-2 text-sm font-semibold text-stone-500"
                >
                    No vote recorded
                </div>
            </section>
        </div>

        <p class="mt-5 font-mono text-xs break-all text-stone-500">
            Payload {{ ballot.payload_hash }}
        </p>
    </section>
</template>
