<script setup lang="ts">
import type { JournalEntry } from './types';

defineProps<{
    entries: JournalEntry[];
}>();

function readableEvent(eventType: string): string {
    return eventType
        .split('.')
        .map((word) => word.replaceAll('_', ' '))
        .join(' · ');
}
</script>

<template>
    <section class="border border-stone-300 bg-white">
        <div
            class="flex items-center justify-between gap-3 border-b border-stone-200 px-4 py-3"
        >
            <h2 class="text-sm font-bold text-stone-900">
                Latest journal events
            </h2>
            <span class="text-xs text-stone-500">Append-only</span>
        </div>
        <ol class="divide-y divide-stone-200">
            <li
                v-for="entry in entries.slice(0, 6)"
                :key="entry.sequence"
                class="grid grid-cols-[30px_1fr] gap-2 px-4 py-3"
            >
                <span
                    class="flex h-6 w-6 items-center justify-center bg-stone-900 text-[10px] font-bold text-white"
                >
                    {{ entry.sequence }}
                </span>
                <span class="min-w-0">
                    <span
                        class="block text-xs font-semibold text-stone-900 capitalize"
                    >
                        {{ readableEvent(entry.event_type) }}
                    </span>
                    <span class="mt-0.5 block text-xs text-stone-500">
                        {{ entry.occurred_at }}
                    </span>
                </span>
            </li>
            <li
                v-if="entries.length === 0"
                class="px-4 py-5 text-sm text-stone-600"
            >
                The journal will begin when the first ceremony action is
                recorded.
            </li>
        </ol>
    </section>
</template>
