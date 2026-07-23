<script setup lang="ts">
withDefaults(
    defineProps<{
        label: string;
        tone?: 'neutral' | 'current' | 'complete' | 'warning' | 'danger';
    }>(),
    {
        tone: 'neutral',
    },
);
</script>

<template>
    <span
        class="inline-flex min-h-6 items-center border px-2 py-1 text-xs font-bold"
        :class="{
            'border-stone-300 bg-stone-100 text-stone-700': tone === 'neutral',
            'border-blue-700 bg-blue-50 text-blue-900': tone === 'current',
            'border-emerald-700 bg-emerald-50 text-emerald-900':
                tone === 'complete',
            'border-amber-600 bg-amber-50 text-amber-950': tone === 'warning',
            'border-red-700 bg-red-50 text-red-900': tone === 'danger',
        }"
    >
        {{ label }}
    </span>
</template>
