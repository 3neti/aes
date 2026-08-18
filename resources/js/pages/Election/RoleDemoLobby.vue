<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';

defineProps<{
    precinct: {
        code: string;
        label: string;
        clustered_precinct: string;
        city_municipality: string | null;
        province: string | null;
        status: string;
    };
    actions: {
        officer: string;
        voter: string;
        watcher: string;
        reset: string;
    };
}>();
</script>

<template>
    <Head title="Role POV Demo" />

    <main class="min-h-screen bg-stone-100 text-stone-950">
        <div class="grid h-1.5 grid-cols-3">
            <span class="bg-blue-800" /><span class="bg-yellow-400" /><span
                class="bg-red-700"
            />
        </div>

        <section class="mx-auto max-w-6xl px-5 py-8 sm:px-8">
            <div class="border border-stone-300 bg-white p-6">
                <p class="text-sm font-bold text-blue-800">
                    Role POV simulation
                </p>
                <h1 class="mt-2 text-3xl font-bold">
                    Choose your election-day point of view
                </h1>
                <p class="mt-3 max-w-3xl text-stone-700">
                    One demo precinct is always open. Use this room to show how
                    the Election Officer, voter, and watcher experience the same
                    live precinct without running the full ceremony sequence.
                </p>
                <div class="mt-5 grid gap-3 text-sm sm:grid-cols-4">
                    <p class="border border-stone-200 bg-stone-50 p-3">
                        <strong class="block">Precinct</strong>
                        {{ precinct.label }}
                    </p>
                    <p class="border border-stone-200 bg-stone-50 p-3">
                        <strong class="block">Cluster</strong>
                        {{ precinct.clustered_precinct }}
                    </p>
                    <p class="border border-stone-200 bg-stone-50 p-3">
                        <strong class="block">Location</strong>
                        {{ precinct.city_municipality }} · {{ precinct.province }}
                    </p>
                    <p class="border border-emerald-300 bg-emerald-50 p-3">
                        <strong class="block">Status</strong>
                        Always open for demo
                    </p>
                </div>
            </div>

            <div class="mt-5 grid gap-4 md:grid-cols-3">
                <Link
                    :href="actions.officer"
                    class="border-2 border-blue-800 bg-white p-6 shadow-sm transition hover:bg-blue-50"
                >
                    <p class="text-sm font-bold text-blue-800">
                        Election Officer
                    </p>
                    <h2 class="mt-2 text-2xl font-bold">
                        Admit, print, and produce forms
                    </h2>
                    <p class="mt-3 text-sm text-stone-700">
                        Generate voter control numbers, accept print PINs,
                        produce ballot PDFs, and open interim tally/ER forms.
                    </p>
                </Link>

                <Link
                    :href="actions.voter"
                    class="border-2 border-emerald-700 bg-white p-6 shadow-sm transition hover:bg-emerald-50"
                >
                    <p class="text-sm font-bold text-emerald-800">Voter</p>
                    <h2 class="mt-2 text-2xl font-bold">
                        Cast a private tablet ballot
                    </h2>
                    <p class="mt-3 text-sm text-stone-700">
                        Enter the officer-issued control number, mark the ballot,
                        review choices, and receive a print PIN.
                    </p>
                </Link>

                <Link
                    :href="actions.watcher"
                    class="border-2 border-stone-800 bg-white p-6 shadow-sm transition hover:bg-stone-50"
                >
                    <p class="text-sm font-bold text-stone-700">Poll Watcher</p>
                    <h2 class="mt-2 text-2xl font-bold">
                        Watch the tally update
                    </h2>
                    <p class="mt-3 text-sm text-stone-700">
                        View the current public tally, ballot count, and interim
                        printable forms as the demo progresses.
                    </p>
                </Link>
            </div>

            <Form :action="actions.reset" method="post" class="mt-6">
                <button
                    class="min-h-12 border-2 border-red-700 bg-white px-5 font-bold text-red-700"
                    type="submit"
                >
                    Reset role demo precinct
                </button>
            </Form>
        </section>
    </main>
</template>
