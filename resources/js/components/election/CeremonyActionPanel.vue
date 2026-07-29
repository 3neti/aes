<script setup lang="ts">
import { computed } from 'vue';
import { useElectionReview } from '@/stores/electionReview';
import StatusBadge from './StatusBadge.vue';

const props = withDefaults(
    defineProps<{
        title: string;
        description?: string;
        eyebrow?: string;
        status?: string;
        tone?: 'neutral' | 'current' | 'complete' | 'warning' | 'danger';
        recommended?: boolean;
    }>(),
    {
        description: '',
        eyebrow: '',
        status: '',
        tone: 'neutral',
        recommended: false,
    },
);

const { review } = useElectionReview();
const isRecommended = computed(() => review.value.enabled && props.recommended);
</script>

<template>
    <section
        class="border bg-white"
        :class="
            isRecommended
                ? 'border-blue-700 ring-2 ring-blue-100'
                : 'border-stone-300'
        "
        :data-recommended-action="isRecommended ? 'true' : 'false'"
    >
        <header
            class="flex flex-col gap-3 border-b border-stone-200 px-5 py-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="min-w-0">
                <p
                    v-if="eyebrow"
                    class="text-xs font-bold text-blue-800 uppercase"
                >
                    {{ eyebrow }}
                </p>
                <h2 class="text-lg font-bold text-stone-950">{{ title }}</h2>
                <p
                    v-if="description"
                    class="mt-1 max-w-3xl text-sm text-stone-600"
                >
                    {{ description }}
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <span
                    v-if="isRecommended"
                    class="border border-yellow-500 bg-yellow-100 px-2.5 py-1 text-xs font-black text-stone-950 uppercase"
                >
                    Click this next
                </span>
                <StatusBadge v-if="status" :label="status" :tone="tone" />
            </div>
        </header>
        <div class="p-5">
            <slot />
        </div>
    </section>
</template>
