<script setup lang="ts">
defineProps<{
    artifacts: Array<{
        label: string;
        path: string;
        detail?: string;
    }>;
}>();
</script>

<template>
    <div class="divide-y divide-stone-200 border border-stone-200">
        <div
            v-for="artifact in artifacts"
            :key="artifact.path"
            class="grid gap-1 px-3 py-2.5 text-sm"
        >
            <span class="font-semibold text-stone-900">{{
                artifact.label
            }}</span>
            <span class="font-mono text-xs break-all text-stone-600">
                {{ artifact.path }}
            </span>
            <span v-if="artifact.detail" class="text-xs text-stone-500">
                {{ artifact.detail }}
            </span>
        </div>
    </div>
</template>
