<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    round: { code: string; name: string };
    precinct: {
        code: string;
        label: string;
        clustered_precinct: string;
        city_municipality: string | null;
        province: string | null;
        status: string;
    };
    downloads: {
        tally: string;
        return: string;
        vvdatAuditExport: string;
        watcher: string;
    };
}>();
</script>

<template>
    <Head :title="`${precinct.code} handoff guide`" />
    <main class="min-h-screen bg-stone-100 text-stone-950">
        <div class="grid h-1.5 grid-cols-3">
            <span class="bg-blue-800" /><span class="bg-yellow-400" /><span
                class="bg-red-700"
            />
        </div>
        <section class="mx-auto max-w-4xl px-5 py-10 sm:px-8">
            <Link
                :href="`/election/demo-room/${round.code}/${precinct.code}`"
                class="text-sm font-bold text-blue-800"
                >Role QR room</Link
            >
            <article class="mt-4 border border-stone-300 bg-white p-6">
                <p class="text-sm font-bold text-blue-800">
                    Handoff and transmission placeholder
                </p>
                <h1 class="mt-2 text-3xl font-bold">
                    {{ precinct.label }} closeout packet
                </h1>
                <ol class="mt-6 grid gap-3">
                    <li class="border-l-4 border-blue-800 bg-blue-50 p-4">
                        Print and sign the tally sheet.
                    </li>
                    <li class="border-l-4 border-blue-800 bg-blue-50 p-4">
                        Print and sign the Election Return.
                    </li>
                    <li class="border-l-4 border-blue-800 bg-blue-50 p-4">
                        Seal deposited paper ballots and record custody.
                    </li>
                    <li class="border-l-4 border-blue-800 bg-blue-50 p-4">
                        Provide poll watchers the public VVDAT audit export.
                    </li>
                    <li class="border-l-4 border-amber-600 bg-amber-50 p-4">
                        Transmission is intentionally a placeholder in this
                        public demo profile.
                    </li>
                </ol>
                <div class="mt-6 grid gap-3 sm:grid-cols-2">
                    <a :href="downloads.tally" class="secondary-button text-center"
                        >Tally sheet</a
                    >
                    <a :href="downloads.return" class="secondary-button text-center"
                        >Election Return</a
                    >
                    <a
                        :href="downloads.vvdatAuditExport"
                        class="secondary-button text-center"
                        >VVDAT audit export</a
                    >
                    <a
                        :href="downloads.watcher"
                        class="secondary-button text-center"
                        >Watcher publication</a
                    >
                </div>
            </article>
        </section>
    </main>
</template>
