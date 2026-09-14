<script setup lang="ts">
import TallyContestPanel from '@/components/election/TallyContestPanel.vue';
import { computed, ref } from 'vue';

type Tally = Record<string, Record<string, number>>;
type CandidateSortMode = 'ballot' | 'votes';

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

const props = withDefaults(defineProps<{
    eyebrow: string;
    title: string;
    acceptedCount: number;
    acceptedLabel: string;
    contests: Contest[];
    tally: Tally;
    lastScanDelta?: TallyDelta;
    flashKey?: string | number | null;
    enableCandidateSort?: boolean;
}>(), {
    enableCandidateSort: false,
});

const sortByVotes = ref(false);
const candidateSortMode = computed<CandidateSortMode>(() => {
    if (!props.enableCandidateSort) {
        return 'ballot';
    }

    return sortByVotes.value ? 'votes' : 'ballot';
});
</script>

<template>
    <section class="border border-stone-300 bg-white p-4">
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
        >
            <div>
                <p
                    class="text-xs font-bold tracking-wide text-blue-800 uppercase"
                >
                    {{ eyebrow }}
                </p>
                <h2 class="mt-0.5 text-2xl font-bold">
                    {{ title }}
                </h2>
            </div>
            <div class="flex flex-col items-start gap-2 text-sm sm:items-end">
                <label
                    v-if="enableCandidateSort"
                    class="flex cursor-pointer items-center gap-3 border border-stone-200 bg-stone-50 px-3 py-2 text-left"
                >
                    <span class="text-xs font-bold text-stone-700">
                        Sort by votes
                    </span>
                    <span class="relative inline-flex items-center">
                        <input
                            v-model="sortByVotes"
                            type="checkbox"
                            class="peer sr-only"
                        />
                        <span
                            class="h-6 w-11 border border-stone-300 bg-white transition-colors peer-checked:border-blue-800 peer-checked:bg-blue-800"
                        />
                        <span
                            class="absolute left-1 h-4 w-4 bg-stone-400 transition-transform peer-checked:translate-x-5 peer-checked:bg-white"
                        />
                    </span>
                    <span class="text-xs font-semibold text-stone-500">
                        {{ sortByVotes ? 'Highest first' : 'Ballot order' }}
                    </span>
                </label>
                <p class="border border-stone-200 bg-stone-50 px-3 py-2">
                    <strong class="text-2xl">{{ acceptedCount }}</strong>
                    {{ acceptedLabel }}
                </p>
                <slot name="stats" />
            </div>
        </div>

        <div
            class="mt-4 grid items-start gap-3 md:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-5"
        >
            <TallyContestPanel
                v-for="contest in contests"
                :key="contest.id"
                :contest="contest"
                :candidate-totals="tally[contest.id] ?? {}"
                :candidate-deltas="lastScanDelta?.[contest.id] ?? {}"
                :flash-key="flashKey"
                :candidate-sort-mode="candidateSortMode"
            />
        </div>
    </section>
</template>
