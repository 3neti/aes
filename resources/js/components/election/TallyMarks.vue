<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    count: number;
}>();

const normalizedCount = computed(() => Math.max(0, Math.floor(props.count)));
const fullGroups = computed(() => Math.floor(normalizedCount.value / 5));
const remainingMarks = computed(() => normalizedCount.value % 5);
const accessibleLabel = computed(
    () =>
        `${normalizedCount.value} ${normalizedCount.value === 1 ? 'vote' : 'votes'} shown as tally marks`,
);
</script>

<template>
    <div
        class="inline-flex max-w-full flex-wrap gap-x-2 gap-y-1 font-mono text-lg leading-none text-stone-950"
        role="img"
        :aria-label="accessibleLabel"
    >
        <span
            v-for="group in fullGroups"
            :key="`group-${group}`"
            class="relative inline-flex h-5 w-11 items-center gap-px font-bold"
            aria-hidden="true"
        >
            <span>|</span>
            <span>|</span>
            <span>|</span>
            <span>|</span>
            <span
                class="absolute top-1/2 left-0 h-0.5 w-11 -translate-y-1/2 rotate-[21deg] bg-stone-950"
            />
        </span>
        <span
            v-if="remainingMarks > 0"
            class="inline-flex h-5 items-center gap-px font-bold"
            aria-hidden="true"
        >
            <span v-for="mark in remainingMarks" :key="`mark-${mark}`">|</span>
        </span>
    </div>
</template>
