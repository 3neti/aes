<script setup lang="ts">
import { computed } from 'vue';
import TallyCandidateRow from '@/components/election/TallyCandidateRow.vue';

type Candidate = {
    id: string;
    name: string;
};
type CandidateSortMode = 'ballot' | 'votes';

type Contest = {
    id: string;
    title: string;
    max_selections: number;
    candidates: Candidate[];
};

type TallyDelta = Record<
    string,
    {
        previousTotal: number;
        addedVotes: number;
        finalTotal: number;
    }
>;

const props = defineProps<{
    contest: Contest;
    candidateTotals: Record<string, number>;
    candidateDeltas?: TallyDelta;
    flashKey?: string | number | null;
    candidateSortMode?: CandidateSortMode;
}>();

const contestTotal = computed(() =>
    Object.values(props.candidateTotals).reduce(
        (total, votes) => total + votes,
        0,
    ),
);
const isLongContest = computed(
    () =>
        props.contest.title.toLowerCase().includes('senator') ||
        props.contest.candidates.length > 18,
);
const sortedCandidates = computed(() => {
    if (props.candidateSortMode === 'ballot') {
        return props.contest.candidates;
    }

    return props.contest.candidates
        .map((candidate, ballotIndex) => ({ ballotIndex, candidate }))
        .sort((left, right) => {
            const voteDifference =
                (props.candidateTotals[right.candidate.id] ?? 0) -
                (props.candidateTotals[left.candidate.id] ?? 0);

            if (voteDifference !== 0) {
                return voteDifference;
            }

            return left.ballotIndex - right.ballotIndex;
        })
        .map((candidatePosition) => candidatePosition.candidate);
});
</script>

<template>
    <section
        class="border border-stone-300 bg-white"
        :class="{ 'xl:col-span-2': isLongContest }"
    >
        <header
            class="flex items-center justify-between gap-2 border-b border-stone-200 bg-stone-50 px-2.5 py-2"
        >
            <div class="min-w-0">
                <p class="text-[10px] font-bold text-stone-500">
                    {{ contest.max_selections }} allowed
                </p>
                <h3 class="truncate text-sm font-black uppercase">
                    {{ contest.title }}
                </h3>
            </div>
            <p class="font-mono text-xs font-bold text-stone-600">
                {{ contestTotal }}
            </p>
        </header>

        <div
            class="grid gap-px bg-stone-100"
            :class="{ 'sm:grid-cols-2': isLongContest }"
        >
            <TallyCandidateRow
                v-for="candidate in sortedCandidates"
                :key="candidate.id"
                :candidate="candidate"
                :votes="candidateTotals[candidate.id] ?? 0"
                :delta="candidateDeltas?.[candidate.id] ?? null"
                :flash-key="flashKey"
            />
        </div>
    </section>
</template>
