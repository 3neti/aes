<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    round: {
        code: string;
        name: string;
        precincts: Array<{
            code: string;
            label: string;
            status: string;
            deposited_ballots: number;
            vvdat_records: number;
            journal: Array<{ event_type: string; occurred_at: string }>;
        }>;
    };
    privacyNotice: string;
}>();
</script>

<template>
    <main class="min-h-screen bg-stone-950 p-5 text-stone-100">
        <Head title="Simulation God Mode" />
        <section class="mx-auto max-w-7xl">
            <Link href="/election/play" class="text-sm font-bold text-yellow-300">Exit facilitator view</Link>
            <p class="mt-6 text-sm font-bold uppercase text-yellow-300">Simulation facilitator</p>
            <h1 class="mt-1 text-3xl font-bold">{{ round.name }}</h1>
            <p class="mt-3 max-w-4xl border-l-4 border-yellow-300 bg-stone-900 p-4 text-sm text-stone-200">{{ privacyNotice }}</p>
            <section class="mt-6 grid gap-5 lg:grid-cols-3">
                <article v-for="precinct in round.precincts" :key="precinct.code" class="border border-stone-700 bg-stone-900 p-5">
                    <div class="flex items-start justify-between gap-3"><div><p class="text-xs font-bold text-yellow-300">{{ precinct.code }}</p><h2 class="mt-1 text-xl font-bold">{{ precinct.label }}</h2></div><span class="border border-stone-600 px-2 py-1 text-xs font-bold">{{ precinct.status }}</span></div>
                    <dl class="mt-5 grid grid-cols-2 gap-3"><div class="bg-stone-800 p-3"><dt class="text-xs text-stone-400">Deposits</dt><dd class="mt-1 text-2xl font-bold">{{ precinct.deposited_ballots }}</dd></div><div class="bg-stone-800 p-3"><dt class="text-xs text-stone-400">VVDAT records</dt><dd class="mt-1 text-2xl font-bold">{{ precinct.vvdat_records }}</dd></div></dl>
                    <ol class="mt-5 space-y-3 border-t border-stone-700 pt-4 text-sm"><li v-for="event in precinct.journal" :key="`${event.occurred_at}-${event.event_type}`"><strong class="block">{{ event.event_type }}</strong><span class="text-stone-400">{{ event.occurred_at }}</span></li></ol>
                </article>
            </section>
        </section>
    </main>
</template>
