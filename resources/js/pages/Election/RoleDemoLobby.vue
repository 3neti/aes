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
        scannerTally: string;
        canvassingDemo: string;
        publicCanvassBoard: string;
        publicNationalCanvass: string;
        publicLocalCanvass: string;
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

        <section class="mx-auto max-w-7xl px-5 py-6 sm:px-8">
            <div
                class="grid gap-4 border border-stone-300 bg-white p-5 lg:grid-cols-[1.4fr_1fr]"
            >
                <div>
                    <p class="text-sm font-bold text-blue-800">
                        Role POV simulation
                    </p>
                    <h1 class="mt-2 text-3xl font-bold">
                        Role demo control hub
                    </h1>
                    <p class="mt-3 max-w-3xl text-sm text-stone-700">
                        One open precinct for the officer, voter, watcher,
                        ballot QR tally, ER canvassing, and public canvass
                        boards.
                    </p>
                </div>

                <div class="grid gap-2 text-xs sm:grid-cols-2">
                    <p class="border border-stone-200 bg-stone-50 p-3">
                        <strong class="block text-stone-500">Precinct</strong>
                        {{ precinct.label }}
                    </p>
                    <p class="border border-stone-200 bg-stone-50 p-3">
                        <strong class="block text-stone-500">Cluster</strong>
                        {{ precinct.clustered_precinct }}
                    </p>
                    <p class="border border-stone-200 bg-stone-50 p-3">
                        <strong class="block text-stone-500">Location</strong>
                        {{ precinct.city_municipality }} ·
                        {{ precinct.province }}
                    </p>
                    <p class="border border-emerald-300 bg-emerald-50 p-3">
                        <strong class="block text-emerald-700">Status</strong>
                        Always open for demo
                    </p>
                </div>
            </div>

            <div class="mt-4 grid gap-4 lg:grid-cols-[1fr_1fr_0.85fr]">
                <section class="border border-stone-300 bg-white p-4">
                    <p class="text-xs font-black uppercase text-stone-500">
                        Core role views
                    </p>
                    <div class="mt-3 grid gap-2">
                        <Link
                            :href="actions.officer"
                            class="group border-l-4 border-blue-800 bg-stone-50 p-4 transition hover:bg-blue-50"
                        >
                            <p class="font-bold text-blue-900">
                                Election Officer
                            </p>
                            <p class="mt-1 text-sm text-stone-700">
                                Admit voters, accept print PINs, and produce
                                precinct forms.
                            </p>
                        </Link>

                        <Link
                            :href="actions.voter"
                            class="group border-l-4 border-emerald-700 bg-stone-50 p-4 transition hover:bg-emerald-50"
                        >
                            <p class="font-bold text-emerald-900">Voter</p>
                            <p class="mt-1 text-sm text-stone-700">
                                Claim a ballot, make selections, review, and get
                                the print PIN.
                            </p>
                        </Link>

                        <Link
                            :href="actions.watcher"
                            class="group border-l-4 border-stone-800 bg-stone-50 p-4 transition hover:bg-stone-100"
                        >
                            <p class="font-bold text-stone-900">
                                Poll Watcher
                            </p>
                            <p class="mt-1 text-sm text-stone-700">
                                View accepted ballots, public tally state, and
                                transparency artifacts.
                            </p>
                        </Link>
                    </div>
                </section>

                <section class="border border-stone-300 bg-white p-4">
                    <p class="text-xs font-black uppercase text-stone-500">
                        QR tally and canvass
                    </p>
                    <div class="mt-3 grid gap-2">
                        <Link
                            :href="actions.scannerTally"
                            class="border-l-4 border-amber-700 bg-stone-50 p-4 transition hover:bg-amber-50"
                        >
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <p class="font-bold text-amber-900">
                                    Ballot QR Scanner Tally
                                </p>
                                <span
                                    class="border border-amber-300 bg-amber-100 px-2 py-1 text-[11px] font-black text-amber-900"
                                >
                                    Ballots
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-stone-700">
                                Scan ballot payloads into live tally sticks,
                                scan log, and document previews.
                            </p>
                        </Link>

                        <Link
                            :href="actions.canvassingDemo"
                            class="border-l-4 border-sky-800 bg-stone-50 p-4 transition hover:bg-sky-50"
                        >
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <p class="font-bold text-sky-950">
                                    ER Canvassing Operator
                                </p>
                                <span
                                    class="border border-sky-300 bg-sky-100 px-2 py-1 text-[11px] font-black text-sky-950"
                                >
                                    Scan ERs
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-stone-700">
                                Generate precinct ER payload sets and scan
                                multipart QR codes into the canvass sheet.
                            </p>
                        </Link>

                        <Link
                            :href="actions.publicCanvassBoard"
                            class="border-l-4 border-blue-800 bg-stone-50 p-4 transition hover:bg-blue-50"
                        >
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <p class="font-bold text-blue-950">
                                    Public Canvass Board
                                </p>
                                <span
                                    class="border border-blue-300 bg-blue-100 px-2 py-1 text-[11px] font-black text-blue-950"
                                >
                                    Public
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-stone-700">
                                Show live scanner-derived ER totals without the
                                QR ingestion controls.
                            </p>
                        </Link>

                        <div class="grid grid-cols-2 gap-2">
                            <Link
                                :href="actions.publicNationalCanvass"
                                class="border border-stone-300 bg-white px-3 py-2 text-center text-sm font-bold text-blue-800 transition hover:bg-blue-50"
                            >
                                National board
                            </Link>
                            <Link
                                :href="actions.publicLocalCanvass"
                                class="border border-stone-300 bg-white px-3 py-2 text-center text-sm font-bold text-blue-800 transition hover:bg-blue-50"
                            >
                                Local board
                            </Link>
                        </div>
                    </div>
                </section>

                <section class="border border-red-200 bg-red-50 p-4">
                    <p class="text-xs font-black uppercase text-red-700">
                        Demo control
                    </p>
                    <h2 class="mt-2 text-xl font-bold text-red-950">
                        Reset precinct state
                    </h2>
                    <p class="mt-2 text-sm text-red-900">
                        Clears the current role demo precinct so the flow can
                        start again from a clean room.
                    </p>
                    <Form :action="actions.reset" method="post" class="mt-4">
                        <button
                            class="min-h-12 w-full border-2 border-red-700 bg-white px-5 font-bold text-red-700 transition hover:bg-red-100"
                            type="submit"
                        >
                            Reset role demo precinct
                        </button>
                    </Form>
                </section>
            </div>
        </section>
    </main>
</template>
