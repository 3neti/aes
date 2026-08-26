<script setup lang="ts">
import { computed } from 'vue';
import { Form, Head, Link, usePoll } from '@inertiajs/vue3';

const props = defineProps<{
    precinct: {
        code: string;
        label: string;
        clustered_precinct: string;
        city_municipality: string | null;
        province: string | null;
        status: string;
        election_id: string | null;
        precinct_id: string | null;
    };
    admission: {
        active_admissions: number;
        maximum_active_admissions: number;
        available_admissions: number;
    };
    operationsBoard: {
        booths: { active: number; completed: number; issued_unclaimed: number };
        print_station: {
            pending_pins: number;
            redeemed_pins: number;
            printed_awaiting_deposit: number;
            deposited: number;
        };
        timeline: Array<{
            event_type: string;
            occurred_at: string;
            label: string;
        }>;
    };
    currentTally: {
        accepted_ballots: number;
        rejected_ballots: number;
        tally_hash: string;
    };
    controlNumber?: {
        code: string;
        expires_at: string;
    } | null;
    printFeedback?: {
        status: string;
        paper_ballot_serial?: string | null;
        message: string;
    } | null;
    feedback?: string | null;
    actions: {
        home: string;
        admit: string;
        dismissControlNumber: string;
        acceptPrint: string;
        bulkBallots: string;
        lastBallot: string;
        tally: string;
        return: string;
        watcher: string;
        reset: string;
    };
    printPinDigits: number;
    bulkBallots: {
        enabled: boolean;
        max_count: number;
        rendered_pdf_limit: number;
        presets: number[];
    };
}>();

const largestPreset = computed<number>(() =>
    Math.max(...props.bulkBallots.presets, props.bulkBallots.max_count),
);

function confirmBulk(count: number): boolean {
    return count < largestPreset.value
        ? true
        : window.confirm(
              `Generate ${count} deposited demo ballots? This can take a little while.`,
          );
}

usePoll(
    4000,
    {
        only: [
            'admission',
            'operationsBoard',
            'currentTally',
            'controlNumber',
            'feedback',
            'printFeedback',
        ],
    },
    { keepAlive: true },
);
</script>

<template>
    <Head :title="`${precinct.code} role demo officer`" />

    <main class="min-h-screen bg-stone-100 text-stone-950">
        <div class="grid h-1.5 grid-cols-3">
            <span class="bg-blue-800" /><span class="bg-yellow-400" /><span
                class="bg-red-700"
            />
        </div>

        <header class="border-b border-stone-300 bg-white">
            <div
                class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-5 py-5 sm:px-8"
            >
                <div>
                    <Link :href="actions.home" class="text-sm font-bold text-blue-800">
                        Role POV room
                    </Link>
                    <h1 class="mt-1 text-2xl font-bold">
                        Election Officer: {{ precinct.label }}
                    </h1>
                    <p class="text-sm text-stone-600">
                        Always-open demo · {{ precinct.city_municipality }} ·
                        Cluster {{ precinct.clustered_precinct }}
                    </p>
                </div>
                <Link
                    :href="actions.watcher"
                    class="min-h-11 border border-stone-400 bg-white px-4 py-2 text-sm font-bold"
                >
                    Open watcher POV
                </Link>
            </div>
        </header>

        <section class="mx-auto max-w-6xl px-5 py-6 sm:px-8">
            <div class="grid gap-3 sm:grid-cols-4">
                <p class="border border-stone-300 bg-white p-4">
                    <strong class="block text-2xl">{{
                        currentTally.accepted_ballots
                    }}</strong>
                    <span class="text-sm text-stone-600">printed ballots</span>
                </p>
                <p class="border border-stone-300 bg-white p-4">
                    <strong class="block text-2xl">{{
                        admission.available_admissions
                    }}</strong>
                    <span class="text-sm text-stone-600">available slots</span>
                </p>
                <p class="border border-stone-300 bg-white p-4">
                    <strong class="block text-2xl">{{
                        operationsBoard.print_station.pending_pins
                    }}</strong>
                    <span class="text-sm text-stone-600">pending print PINs</span>
                </p>
                <p class="border border-stone-300 bg-white p-4">
                    <strong class="block font-mono text-sm">{{
                        currentTally.tally_hash.slice(0, 14)
                    }}</strong>
                    <span class="text-sm text-stone-600">current tally hash</span>
                </p>
            </div>

            <div
                v-if="feedback"
                class="mt-5 border-l-8 border-emerald-700 bg-emerald-50 p-4 font-semibold text-emerald-950"
            >
                {{ feedback }}
            </div>

            <div
                v-if="printFeedback"
                class="mt-5 border-l-8 border-blue-800 bg-blue-50 p-4 text-blue-950"
            >
                <p class="font-bold">{{ printFeedback.message }}</p>
                <p
                    v-if="printFeedback.paper_ballot_serial"
                    class="mt-1 text-sm"
                >
                    Paper ballot serial {{ printFeedback.paper_ballot_serial }}
                </p>
                <a
                    :href="actions.lastBallot"
                    class="mt-3 inline-flex min-h-11 items-center border border-blue-800 bg-white px-4 font-bold text-blue-800"
                    target="_blank"
                >
                    Open last ballot PDF
                </a>
            </div>

            <div class="mt-5 grid gap-5 lg:grid-cols-2">
                <section class="border border-stone-300 bg-white p-5">
                    <p class="text-sm font-bold text-blue-800">Step 1</p>
                    <h2 class="mt-1 text-xl font-bold">Admit next voter</h2>
                    <p class="mt-2 text-sm text-stone-700">
                        Generate a four-digit control number after physical
                        identity checking. The voter enters this on the booth
                        tablet.
                    </p>

                    <div
                        v-if="controlNumber"
                        class="mt-4 border-4 border-blue-800 bg-blue-50 p-5 text-center"
                    >
                        <p class="text-sm font-bold text-blue-900">
                            Hand this 4-digit control number to the voter
                        </p>
                        <p class="mt-1 text-6xl font-black tracking-widest">
                            {{ controlNumber.code }}
                        </p>
                        <p class="mt-2 text-sm font-semibold text-blue-950">
                            Stays here until dismissed. Expires
                            {{ controlNumber.expires_at }}.
                        </p>
                        <Form
                            :action="actions.dismissControlNumber"
                            method="post"
                            class="mt-4"
                        >
                            <button
                                class="min-h-11 border-2 border-blue-800 bg-white px-5 font-bold text-blue-800"
                                type="submit"
                            >
                                Dismiss after writing number
                            </button>
                        </Form>
                    </div>

                    <Form
                        v-else
                        :action="actions.admit"
                        method="post"
                        #default="{ errors, processing }"
                        class="mt-5"
                    >
                        <p v-if="errors.admission" class="mb-3 font-bold text-red-700">
                            {{ errors.admission }}
                        </p>
                        <button
                            class="review-next-action-button min-h-14 w-full bg-blue-800 px-5 text-lg font-bold text-white disabled:opacity-50"
                            type="submit"
                            :disabled="processing || admission.available_admissions === 0"
                        >
                            {{
                                processing
                                    ? 'Generating...'
                                    : 'Generate voter control number'
                            }}
                        </button>
                    </Form>
                </section>

                <section class="border border-stone-300 bg-white p-5">
                    <p class="text-sm font-bold text-blue-800">Step 2</p>
                    <h2 class="mt-1 text-xl font-bold">
                        Accept voter print PIN
                    </h2>
                    <p class="mt-2 text-sm text-stone-700">
                        Enter the voter’s private print PIN. This prints the
                        paper ballot PDF and deposits the sealed VVDAT record for
                        the live watcher tally.
                    </p>
                    <Form
                        :action="actions.acceptPrint"
                        method="post"
                        #default="{ errors, processing }"
                        class="mt-5 space-y-3"
                        reset-on-success
                    >
                        <label class="block">
                            <span class="text-sm font-bold">Print PIN</span>
                            <input
                                class="mt-1 min-h-14 w-full border-2 border-stone-400 px-4 text-center font-mono text-3xl font-bold"
                                name="code"
                                required
                                autocomplete="off"
                                inputmode="numeric"
                                :maxlength="printPinDigits"
                                pattern="[0-9]{4,6}"
                                placeholder="0000"
                            />
                        </label>
                        <p v-if="errors.code" class="font-bold text-red-700">
                            {{ errors.code }}
                        </p>
                        <button
                            class="min-h-14 w-full bg-emerald-700 px-5 text-lg font-bold text-white disabled:opacity-50"
                            type="submit"
                            :disabled="processing"
                        >
                            {{
                                processing
                                    ? 'Printing and accepting...'
                                    : 'Print ballot and update tally'
                            }}
                        </button>
                    </Form>
                </section>
            </div>

            <section class="mt-5 border border-stone-300 bg-white p-5">
                <p class="text-sm font-bold text-blue-800">Step 3</p>
                <h2 class="mt-1 text-xl font-bold">
                    Print current tally and Election Return
                </h2>
                <p class="mt-2 text-sm text-stone-700">
                    These are interim demo forms from the currently deposited
                    VVDAT records. The real demo room still handles formal
                    closeout.
                </p>
                <div class="mt-4 flex flex-wrap gap-3">
                    <a :href="actions.tally" class="secondary-button" target="_blank">
                        Open current tally sheet
                    </a>
                    <a :href="actions.return" class="secondary-button" target="_blank">
                        Open current Election Return
                    </a>
                    <a
                        :href="`${actions.tally}/thermal-80`"
                        class="secondary-button"
                        target="_blank"
                    >
                        Thermal tally
                    </a>
                    <a
                        :href="`${actions.return}/thermal-80`"
                        class="secondary-button"
                        target="_blank"
                    >
                        Thermal ER
                    </a>
                </div>
            </section>

            <section class="mt-5 border border-blue-300 bg-white p-5">
                <p class="text-sm font-bold text-blue-800">Demo load tools</p>
                <h2 class="mt-1 text-xl font-bold">
                    Generate deposited demo ballots
                </h2>
                <p class="mt-2 text-sm text-stone-700">
                    Use this to populate the watcher POV quickly. Every generated
                    ballot is sealed into the VVDAT record set and included in
                    the running tally. Rendered ballot PDFs are generated for the
                    first {{ bulkBallots.rendered_pdf_limit }} ballots so the
                    media viewer stays responsive.
                </p>
                <div
                    v-if="!bulkBallots.enabled"
                    class="mt-4 border-l-4 border-stone-500 bg-stone-50 p-4 text-sm font-semibold text-stone-700"
                >
                    Bulk demo ballot generation is disabled by configuration.
                </div>
                <template v-else>
                    <div class="mt-4 grid gap-3 sm:grid-cols-3">
                        <Form
                            v-for="preset in bulkBallots.presets"
                            :key="preset"
                            :action="actions.bulkBallots"
                            method="post"
                            #default="{ processing }"
                        >
                            <input type="hidden" name="count" :value="preset" />
                            <button
                                class="min-h-12 w-full border-2 border-blue-800 bg-blue-800 px-4 font-bold text-white disabled:opacity-50"
                                type="submit"
                                :disabled="
                                    processing || preset > bulkBallots.max_count
                                "
                                @click="(event) => !confirmBulk(preset) && event.preventDefault()"
                            >
                                {{ processing ? 'Generating...' : `${preset} ballots` }}
                            </button>
                        </Form>
                    </div>

                    <Form
                        :action="actions.bulkBallots"
                        method="post"
                        #default="{ errors, processing }"
                        class="mt-4 grid gap-3 sm:grid-cols-[1fr_auto]"
                    >
                        <label class="block">
                            <span class="text-sm font-bold">Custom count</span>
                            <input
                                class="mt-1 min-h-12 w-full border-2 border-stone-400 px-4 font-mono text-xl font-bold"
                                name="count"
                                type="number"
                                min="1"
                                :max="bulkBallots.max_count"
                                placeholder="700"
                            />
                        </label>
                        <button
                            class="min-h-12 self-end border-2 border-blue-800 bg-white px-5 font-bold text-blue-800 disabled:opacity-50"
                            type="submit"
                            :disabled="processing"
                            @click="
                                (event) =>
                                    !window.confirm(
                                        'Generate this many deposited demo ballots?',
                                    ) && event.preventDefault()
                            "
                        >
                            {{ processing ? 'Generating...' : 'Generate custom' }}
                        </button>
                        <p
                            v-if="errors.count"
                            class="font-bold text-red-700 sm:col-span-2"
                        >
                            {{ errors.count }}
                        </p>
                    </Form>

                    <p class="mt-3 text-xs text-stone-600">
                        Maximum {{ bulkBallots.max_count }} per request. Use
                        Reset role demo precinct before loading a fresh batch.
                    </p>
                </template>
            </section>

            <section class="mt-5 border border-stone-300 bg-white p-5">
                <h2 class="text-xl font-bold">Recent activity</h2>
                <div class="mt-3 divide-y divide-stone-200">
                    <p
                        v-for="event in operationsBoard.timeline.slice(0, 8)"
                        :key="`${event.event_type}-${event.occurred_at}`"
                        class="py-2 text-sm"
                    >
                        <strong>{{ event.label }}</strong>
                        <span class="ml-2 text-stone-500">{{
                            event.occurred_at
                        }}</span>
                    </p>
                </div>
                <Form :action="actions.reset" method="post" class="mt-4">
                    <button
                        class="min-h-11 border-2 border-red-700 bg-white px-4 font-bold text-red-700"
                        type="submit"
                    >
                        Reset role demo precinct
                    </button>
                </Form>
            </section>
        </section>
    </main>
</template>
