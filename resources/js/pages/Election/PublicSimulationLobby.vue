<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    round: {
        code: string;
        name: string;
        status: string;
        precincts: Array<{
            code: string;
            label: string;
            city_municipality: string | null;
            province: string | null;
            status: string;
        }>;
    };
}>();

function statusTone(status: string): string {
    return status === 'open' ? 'bg-emerald-100 text-emerald-900' : status === 'closed' ? 'bg-stone-200 text-stone-800' : 'bg-amber-100 text-amber-900';
}
</script>

<template>
    <Head title="Public Election Simulation" />
    <main class="min-h-screen bg-stone-100 text-stone-950">
        <div class="grid h-1.5 grid-cols-3"><span class="bg-blue-800" /><span class="bg-yellow-400" /><span class="bg-red-700" /></div>
        <header class="border-b border-stone-300 bg-white">
            <div class="mx-auto max-w-6xl px-5 py-8 sm:px-8">
                <p class="text-sm font-bold uppercase text-blue-800">Alternative Election System</p>
                <h1 class="mt-2 text-3xl font-bold">{{ round.name }}</h1>
                <p class="mt-3 max-w-3xl text-stone-700">Choose a precinct to participate as a voter, Election Officer, or observer. Candidate totals remain sealed until that precinct closes.</p>
            </div>
        </header>
        <section class="mx-auto grid max-w-6xl gap-5 px-5 py-8 sm:grid-cols-3 sm:px-8">
            <Link v-for="precinct in round.precincts" :key="precinct.code" :href="`/election/play/${round.code}/${precinct.code}`" class="border border-stone-300 bg-white p-5 transition hover:border-blue-800 hover:shadow-sm">
                <div class="flex items-start justify-between gap-3"><span class="text-xs font-bold text-blue-800">PRECINCT</span><span class="px-2 py-1 text-xs font-bold" :class="statusTone(precinct.status)">{{ precinct.status }}</span></div>
                <h2 class="mt-4 text-2xl font-bold">{{ precinct.code }}</h2>
                <p class="mt-2 font-semibold">{{ precinct.label }}</p>
                <p class="mt-5 text-sm text-stone-600">{{ precinct.city_municipality }}<br />{{ precinct.province }}</p>
                <span class="mt-6 inline-flex min-h-11 items-center bg-blue-800 px-4 font-bold text-white">Enter precinct</span>
            </Link>
        </section>
    </main>
</template>
