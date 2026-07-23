<script setup lang="ts">
import StatusBadge from './StatusBadge.vue';

withDefaults(
    defineProps<{
        title: string;
        description?: string;
        eyebrow?: string;
        status?: string;
        tone?: 'neutral' | 'current' | 'complete' | 'warning' | 'danger';
    }>(),
    {
        description: '',
        eyebrow: '',
        status: '',
        tone: 'neutral',
    },
);
</script>

<template>
    <section class="border border-stone-300 bg-white">
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
            <StatusBadge v-if="status" :label="status" :tone="tone" />
        </header>
        <div class="p-5">
            <slot />
        </div>
    </section>
</template>
