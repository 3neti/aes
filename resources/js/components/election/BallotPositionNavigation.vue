<script setup lang="ts">
import type { BallotNavigationContest } from '@/components/election/types';

defineProps<{
    contests: BallotNavigationContest[];
}>();

const emit = defineEmits<{
    jump: [contestId: string];
}>();
</script>

<template>
    <nav
        class="sticky top-0 z-20 border border-stone-300 bg-white p-3 shadow-sm"
        aria-label="Ballot position navigation"
    >
        <p class="text-xs font-bold text-stone-600 uppercase">
            Jump to position
        </p>
        <div class="mt-2 flex gap-2 overflow-x-auto pb-1">
            <button
                v-for="contest in contests"
                :key="contest.id"
                class="shrink-0 border px-3 py-2 text-sm font-bold"
                :class="
                    contest.selected > 0
                        ? 'border-blue-800 bg-blue-50 text-blue-900'
                        : 'border-stone-300 bg-white text-stone-800'
                "
                type="button"
                @click="emit('jump', contest.id)"
            >
                {{ contest.label }}
                <span class="font-mono"
                    >{{ contest.selected }}/{{ contest.max }}</span
                >
            </button>
        </div>
    </nav>
</template>
