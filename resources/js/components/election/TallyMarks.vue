<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps<{
    count: number;
    highlightFrom?: number | null;
    highlightTo?: number | null;
    flashKey?: string | number | null;
}>();

const normalizedCount = computed(() => Math.max(0, Math.floor(props.count)));
const fullGroups = computed(() => Math.floor(normalizedCount.value / 5));
const remainingMarks = computed(() => normalizedCount.value % 5);
const isSettled = ref(false);
let settleTimeout: number | null = null;
const accessibleLabel = computed(
    () =>
        `${normalizedCount.value} ${normalizedCount.value === 1 ? 'vote' : 'votes'} shown as tally marks`,
);

const highlightStart = computed(() =>
    Math.max(1, Math.floor(props.highlightFrom ?? 0)),
);
const highlightEnd = computed(() =>
    Math.min(normalizedCount.value, Math.floor(props.highlightTo ?? 0)),
);

function isHighlighted(mark: number): boolean {
    if (highlightEnd.value < highlightStart.value) {
        return false;
    }

    if (isSettled.value) {
        return mark === highlightEnd.value;
    }

    return mark >= highlightStart.value && mark <= highlightEnd.value;
}

function markClass(mark: number): string {
    return isHighlighted(mark) ? 'text-red-700 tally-flash' : 'text-stone-950';
}

function slashClass(mark: number): string {
    return isHighlighted(mark) ? 'bg-red-700 tally-flash' : 'bg-stone-950';
}

watch(
    () => props.flashKey,
    () => {
        if (settleTimeout !== null) {
            window.clearTimeout(settleTimeout);
        }

        isSettled.value = false;

        if (highlightEnd.value >= highlightStart.value) {
            settleTimeout = window.setTimeout(() => {
                isSettled.value = true;
                settleTimeout = null;
            }, 900);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (settleTimeout !== null) {
        window.clearTimeout(settleTimeout);
    }
});
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
            <span :class="markClass((group - 1) * 5 + 1)">|</span>
            <span :class="markClass((group - 1) * 5 + 2)">|</span>
            <span :class="markClass((group - 1) * 5 + 3)">|</span>
            <span :class="markClass((group - 1) * 5 + 4)">|</span>
            <span
                class="absolute top-1/2 left-0 h-0.5 w-11 -translate-y-1/2 rotate-[21deg]"
                :class="slashClass(group * 5)"
            />
        </span>
        <span
            v-if="remainingMarks > 0"
            class="inline-flex h-5 items-center gap-px font-bold"
            aria-hidden="true"
        >
            <span
                v-for="mark in remainingMarks"
                :key="`mark-${mark}`"
                :class="markClass(fullGroups * 5 + mark)"
            >
                |
            </span>
        </span>
    </div>
</template>

<style scoped>
.tally-flash {
    animation: tally-flash 900ms ease-in-out;
}

@keyframes tally-flash {
    0%,
    100% {
        opacity: 1;
    }

    25%,
    65% {
        opacity: 0.25;
    }

    45%,
    85% {
        opacity: 1;
    }
}
</style>
