<script setup lang="ts">
import TallyContestPanel from '@/components/election/TallyContestPanel.vue';

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

defineProps<{
    eyebrow: string;
    title: string;
    acceptedCount: number;
    acceptedLabel: string;
    contests: Contest[];
    tally: Tally;
    lastScanDelta?: TallyDelta;
    flashKey?: string | number | null;
}>();
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
            <div class="text-right text-sm">
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
            />
        </div>
    </section>
</template>
