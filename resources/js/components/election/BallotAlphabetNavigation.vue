<script setup lang="ts">
import type { BallotLetterJump } from '@/components/election/types';

defineProps<{
    label: string;
    letters: BallotLetterJump[];
    activeLetter?: string;
}>();

const emit = defineEmits<{
    jump: [letter: BallotLetterJump];
}>();
</script>

<template>
    <div
        v-if="letters.length > 0"
        class="sticky top-[76px] z-10 mt-4 border-y border-stone-200 bg-white/95 py-3 shadow-sm backdrop-blur"
    >
        <div class="flex items-center justify-between gap-3">
            <p class="text-xs font-bold text-stone-600 uppercase">
                {{ label }}
            </p>
            <p class="text-xs font-semibold text-stone-500">
                Stays here while browsing this position
            </p>
        </div>
        <div class="mt-2 flex gap-1.5 overflow-x-auto pb-1">
            <button
                v-for="letter in letters"
                :key="letter.letter"
                class="flex h-9 min-w-9 shrink-0 items-center justify-center border px-2 text-sm font-bold"
                :class="
                    activeLetter === letter.letter
                        ? 'border-blue-800 bg-blue-800 text-white'
                        : 'border-stone-300 bg-stone-50 text-stone-900'
                "
                type="button"
                @click="emit('jump', letter)"
            >
                {{ letter.letter }}
            </button>
        </div>
    </div>
</template>
