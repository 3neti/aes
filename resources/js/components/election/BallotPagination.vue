<script setup lang="ts">
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    selectedIndex: number;
    total: number;
}>();

const emit = defineEmits<{
    'update:selectedIndex': [value: number];
}>();

type PageItem = number | 'gap';

const jumpValue = ref('');

const selectedPage = computed<number>(() => props.selectedIndex + 1);

const pageItems = computed<PageItem[]>(() => {
    if (props.total <= 9) {
        return Array.from({ length: props.total }, (_, index) => index + 1);
    }

    const pages = new Set<number>([
        1,
        props.total,
        selectedPage.value - 2,
        selectedPage.value - 1,
        selectedPage.value,
        selectedPage.value + 1,
        selectedPage.value + 2,
    ]);

    const normalized = [...pages]
        .filter((page) => page >= 1 && page <= props.total)
        .sort((left, right) => left - right);

    return normalized.reduce<PageItem[]>((items, page, index) => {
        const previous = normalized[index - 1];

        if (previous && page - previous > 1) {
            items.push('gap');
        }

        items.push(page);

        return items;
    }, []);
});

const canGoPrevious = computed<boolean>(() => props.selectedIndex > 0);
const canGoNext = computed<boolean>(() => props.selectedIndex < props.total - 1);

watch(
    () => selectedPage.value,
    (page) => {
        jumpValue.value = String(page);
    },
    { immediate: true },
);

function selectPage(page: number): void {
    emit('update:selectedIndex', Math.min(Math.max(page - 1, 0), props.total - 1));
}

function previous(): void {
    if (canGoPrevious.value) {
        selectPage(selectedPage.value - 1);
    }
}

function next(): void {
    if (canGoNext.value) {
        selectPage(selectedPage.value + 1);
    }
}

function jump(): void {
    const page = Number(jumpValue.value);

    if (Number.isInteger(page) && page >= 1 && page <= props.total) {
        selectPage(page);
    } else {
        jumpValue.value = String(selectedPage.value);
    }
}
</script>

<template>
    <nav
        class="border-t border-stone-200 bg-stone-50 p-3"
        aria-label="Ballot review navigation"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <button
                type="button"
                class="min-h-10 border-2 border-stone-400 bg-white px-4 text-sm font-bold text-stone-800 disabled:opacity-40"
                :disabled="!canGoPrevious"
                @click="previous"
            >
                Previous
            </button>

            <div class="hidden flex-wrap items-center justify-center gap-1 sm:flex">
                <template v-for="(item, index) in pageItems" :key="`${item}-${index}`">
                    <span
                        v-if="item === 'gap'"
                        class="px-2 text-sm font-bold text-stone-500"
                    >
                        ...
                    </span>
                    <button
                        v-else
                        type="button"
                        class="h-10 min-w-10 border px-3 text-sm font-bold"
                        :class="
                            item === selectedPage
                                ? 'border-blue-700 bg-blue-700 text-white'
                                : 'border-stone-300 bg-white text-stone-700 hover:border-blue-400'
                        "
                        :aria-current="item === selectedPage ? 'page' : undefined"
                        @click="selectPage(item)"
                    >
                        {{ item }}
                    </button>
                </template>
            </div>

            <p class="text-sm font-bold text-stone-700 sm:hidden">
                Ballot {{ selectedPage }} of {{ total }}
            </p>

            <button
                type="button"
                class="min-h-10 border-2 border-stone-400 bg-white px-4 text-sm font-bold text-stone-800 disabled:opacity-40"
                :disabled="!canGoNext"
                @click="next"
            >
                Next
            </button>
        </div>

        <form
            class="mt-3 flex flex-wrap items-center justify-center gap-2 text-sm"
            @submit.prevent="jump"
        >
            <label class="font-bold text-stone-700" for="ballot-jump">
                Jump to ballot
            </label>
            <input
                id="ballot-jump"
                v-model="jumpValue"
                class="h-10 w-24 border-2 border-stone-300 bg-white px-3 text-center font-mono font-bold"
                inputmode="numeric"
                type="text"
                :aria-label="`Jump to ballot number between 1 and ${total}`"
            />
            <button
                type="submit"
                class="h-10 border-2 border-blue-800 bg-white px-3 font-bold text-blue-800"
            >
                Go
            </button>
        </form>
    </nav>
</template>
