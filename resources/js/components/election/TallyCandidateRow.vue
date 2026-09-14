<script setup lang="ts">
import TallyMarks from '@/components/election/TallyMarks.vue';
import { computed } from 'vue';

const props = defineProps<{
    candidate: {
        id: string;
        name: string;
    };
    votes: number;
    delta?: {
        previousTotal: number;
        addedVotes: number;
        finalTotal: number;
    } | null;
    flashKey?: string | number | null;
}>();

const displayName = computed(() => {
    const normalizedName = props.candidate.name
        .replace(/^\s*\d{1,3}\s*(?:[.)\-:]\s*|\s+)/, '')
        .trim();

    return normalizedName.length > 0 ? normalizedName : props.candidate.name;
});
</script>

<template>
    <div
        class="grid min-h-8 grid-cols-[minmax(0,1fr)_3.5rem] items-center gap-x-2 gap-y-0.5 bg-white px-2 py-1"
    >
        <p
            class="truncate text-xs leading-tight font-semibold text-stone-950"
            :title="displayName"
        >
            {{ displayName }}
        </p>
        <p class="text-right font-mono text-base leading-none font-black tabular-nums text-blue-900">
            {{ votes }}
        </p>
        <div class="compact-tally col-span-2">
            <TallyMarks
                :count="votes"
                :highlight-from="delta ? delta.previousTotal + 1 : null"
                :highlight-to="delta?.finalTotal ?? null"
                :flash-key="flashKey"
            />
        </div>
    </div>
</template>

<style scoped>
.compact-tally :deep(div) {
    gap: 0.125rem 0.25rem;
    font-size: 0.75rem;
    line-height: 0.75rem;
}

.compact-tally :deep(.relative) {
    height: 0.875rem;
    width: 1.75rem;
}

.compact-tally :deep(.absolute) {
    width: 1.75rem;
}
</style>
