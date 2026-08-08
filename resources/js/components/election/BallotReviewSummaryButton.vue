<script setup lang="ts">
defineProps<{
    summary: string;
    emphasized: boolean;
}>();

const emit = defineEmits<{
    review: [];
}>();
</script>

<template>
    <div class="sticky bottom-0 border-t-4 border-blue-800 bg-white p-4">
        <button
            class="min-h-12 w-full bg-blue-800 px-6 py-3 text-sm leading-snug font-bold text-white sm:text-base"
            :class="{ 'review-next-action-button': emphasized }"
            type="button"
            @click="emit('review')"
        >
            Review: {{ summary }}
        </button>
    </div>
</template>
