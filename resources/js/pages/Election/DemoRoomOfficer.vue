<script setup lang="ts">
import { Form, Head, Link, usePoll } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    round: { code: string; name: string };
    precinct: {
        code: string;
        label: string;
        clustered_precinct: string;
        city_municipality: string | null;
        province: string | null;
        status: string;
        officer_name: string;
        accepted_ballots: number | null;
    };
    admission: {
        active_admissions: number;
        maximum_active_admissions: number;
        available_admissions: number;
        queue: {
            enabled: boolean;
            waiting_voters: number;
            intake: { status: string };
        };
    };
    operationsBoard: {
        booths: { active: number; completed: number; issued_unclaimed: number };
        print_station: {
            pending_pins: number;
            redeemed_pins: number;
            printed_awaiting_deposit: number;
            deposited: number;
        };
        closeout: {
            unresolved_voter_work: number;
            can_close: boolean;
            next_required_action: string;
        };
        timeline: Array<{
            event_type: string;
            occurred_at: string;
            label: string;
        }>;
        privacy_notice: string;
    };
    actions: {
        open: string;
        admit: string;
        dismissControlNumber: string;
        admitQueued: string;
        close: string;
        forceClose: string;
        publish: string;
        roles: string;
        print: string;
        watch: string;
        handoff: string;
    };
    officerDefaults: { officer_code: string; officer_pin: string };
    officerFeedback?: string | null;
    controlNumber?: {
        code: string;
        expires_at: string;
        voter_entry?: { url: string; qr: string } | null;
    } | null;
}>();

const showForceCloseoutConfirm = ref(false);

usePoll(
    5000,
    {
        only: [
            'precinct',
            'admission',
            'operationsBoard',
            'officerFeedback',
            'controlNumber',
        ],
    },
    { keepAlive: true },
);
</script>

<template>
    <Head :title="`${precinct.code} officer console`" />
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
                    <Link
                        :href="actions.roles"
                        class="text-sm font-bold text-blue-800"
                        >Role QR room</Link
                    >
                    <h1 class="mt-1 text-2xl font-bold">
                        Election Officer: {{ precinct.label }}
                    </h1>
                    <p class="text-sm text-stone-600">
                        {{ precinct.city_municipality }} ·
                        {{ precinct.province }} · Cluster
                        {{ precinct.clustered_precinct }}
                    </p>
                </div>
                <span
                    class="border border-stone-400 bg-white px-3 py-2 text-sm font-bold"
                    >{{ precinct.status }}</span
                >
            </div>
        </header>
        <section
            class="mx-auto grid max-w-6xl gap-5 px-5 py-6 sm:px-8 lg:grid-cols-[1.2fr_.8fr]"
        >
            <div class="space-y-5">
                <section class="border border-stone-300 bg-white p-5">
                    <p class="text-sm font-bold text-blue-800">
                        Ceremony controls
                    </p>
                    <h2 class="mt-1 text-xl font-bold">Next officer action</h2>
                    <p class="mt-2 text-sm text-stone-700">
                        {{ operationsBoard.closeout.next_required_action }}
                    </p>
                    <div
                        v-if="officerFeedback"
                        class="mt-4 border-l-4 border-emerald-700 bg-emerald-50 p-4 text-sm font-semibold text-emerald-950"
                    >
                        {{ officerFeedback }}
                    </div>
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
                        <div
                            v-if="controlNumber.voter_entry"
                            class="mt-4 grid gap-4 border border-blue-200 bg-white p-4 text-left sm:grid-cols-[auto_1fr] sm:items-center"
                        >
                            <img
                                :src="controlNumber.voter_entry.qr"
                                alt="Voter tablet entry QR code"
                                class="h-32 w-32 border border-stone-300 bg-white p-2"
                            />
                            <div class="min-w-0">
                                <p
                                    class="text-sm font-bold text-blue-950 uppercase"
                                >
                                    Testing shortcut
                                </p>
                                <p class="mt-1 text-sm text-stone-700">
                                    Share this QR or link with a test voter. It
                                    opens the voter tablet page with this
                                    control number prefilled.
                                </p>
                                <a
                                    :href="controlNumber.voter_entry.url"
                                    class="mt-3 block border border-blue-200 bg-blue-50 p-2 font-mono text-xs font-semibold break-all text-blue-900"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    {{ controlNumber.voter_entry.url }}
                                </a>
                            </div>
                        </div>
                        <Form
                            :action="actions.dismissControlNumber"
                            method="post"
                            #default="{ processing }"
                            class="mt-4"
                        >
                            <button
                                class="min-h-11 border-2 border-blue-800 bg-white px-5 font-bold text-blue-800 disabled:opacity-50"
                                type="submit"
                                :disabled="processing"
                            >
                                Dismiss after writing number
                            </button>
                        </Form>
                    </div>
                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                        <Form
                            :action="actions.open"
                            method="post"
                            #default="{ errors, processing }"
                            class="border border-stone-200 bg-stone-50 p-4"
                        >
                            <input
                                type="hidden"
                                name="officer_code"
                                :value="officerDefaults.officer_code"
                            />
                            <input
                                type="hidden"
                                name="officer_pin"
                                :value="officerDefaults.officer_pin"
                            />
                            <p class="font-bold">Open precinct</p>
                            <p class="mt-1 text-sm text-stone-600">
                                Start voting for this precinct after setup and
                                certification are complete.
                            </p>
                            <p
                                v-if="errors.officer_pin"
                                class="mt-2 text-sm font-bold text-red-700"
                            >
                                {{ errors.officer_pin }}
                            </p>
                            <button
                                class="review-next-action-button mt-4 min-h-12 w-full bg-blue-800 px-4 font-bold text-white disabled:opacity-50"
                                type="submit"
                                :disabled="
                                    processing || precinct.status !== 'ready'
                                "
                            >
                                Open precinct
                            </button>
                        </Form>
                        <Form
                            :action="actions.admit"
                            method="post"
                            #default="{ errors, processing }"
                            class="border border-stone-200 bg-stone-50 p-4"
                        >
                            <input
                                type="hidden"
                                name="officer_code"
                                :value="officerDefaults.officer_code"
                            />
                            <input
                                type="hidden"
                                name="officer_pin"
                                :value="officerDefaults.officer_pin"
                            />
                            <p class="font-bold">Admit next voter</p>
                            <p class="mt-1 text-sm text-stone-600">
                                {{ admission.available_admissions }} active
                                slot{{
                                    admission.available_admissions === 1
                                        ? ''
                                        : 's'
                                }}
                                available.
                            </p>
                            <p
                                v-if="errors.officer_pin"
                                class="mt-2 text-sm font-bold text-red-700"
                            >
                                {{ errors.officer_pin }}
                            </p>
                            <button
                                class="review-next-action-button mt-4 min-h-12 w-full bg-blue-800 px-4 font-bold text-white disabled:opacity-50"
                                type="submit"
                                :disabled="
                                    processing || precinct.status !== 'open'
                                "
                            >
                                Generate voter control number
                            </button>
                        </Form>
                        <Form
                            :action="actions.close"
                            method="post"
                            #default="{ errors, processing }"
                            class="border border-stone-200 bg-stone-50 p-4"
                        >
                            <input
                                type="hidden"
                                name="officer_code"
                                :value="officerDefaults.officer_code"
                            />
                            <input
                                type="hidden"
                                name="officer_pin"
                                :value="officerDefaults.officer_pin"
                            />
                            <p class="font-bold">Close and tally</p>
                            <p class="mt-1 text-sm text-stone-600">
                                Tally all deposited VVDAT records and generate
                                closeout forms.
                            </p>
                            <p
                                v-if="errors.officer_pin"
                                class="mt-2 text-sm font-bold text-red-700"
                            >
                                {{ errors.officer_pin }}
                            </p>
                            <button
                                class="review-next-action-button mt-4 min-h-12 w-full bg-emerald-700 px-4 font-bold text-white disabled:opacity-50"
                                type="submit"
                                :disabled="
                                    processing ||
                                    precinct.status !== 'open' ||
                                    !operationsBoard.closeout.can_close
                                "
                            >
                                Close precinct and tally
                            </button>
                        </Form>
                        <section
                            class="border border-amber-300 bg-amber-50 p-4"
                        >
                            <p class="font-bold">Finalize all and close</p>
                            <p class="mt-1 text-sm text-amber-950">
                                Presentation safeguard. Cancels unfinished voter
                                booths, completes finalized print releases, then
                                closes and tallies this precinct.
                            </p>
                            <button
                                class="mt-4 min-h-12 w-full border-2 border-amber-800 bg-white px-4 font-bold text-amber-900 disabled:opacity-50"
                                type="button"
                                :disabled="precinct.status !== 'open'"
                                @click="showForceCloseoutConfirm = true"
                            >
                                Finalize all ballots and close
                            </button>
                        </section>
                        <Form
                            :action="actions.publish"
                            method="post"
                            #default="{ errors, processing }"
                            class="border border-stone-200 bg-stone-50 p-4"
                        >
                            <input
                                type="hidden"
                                name="officer_code"
                                :value="officerDefaults.officer_code"
                            />
                            <input
                                type="hidden"
                                name="officer_pin"
                                :value="officerDefaults.officer_pin"
                            />
                            <p class="font-bold">Publish watcher packet</p>
                            <p class="mt-1 text-sm text-stone-600">
                                Make tally, ER, and audit downloads visible to
                                watchers.
                            </p>
                            <p
                                v-if="errors.officer_pin"
                                class="mt-2 text-sm font-bold text-red-700"
                            >
                                {{ errors.officer_pin }}
                            </p>
                            <button
                                class="review-next-action-button mt-4 min-h-12 w-full bg-blue-800 px-4 font-bold text-white disabled:opacity-50"
                                type="submit"
                                :disabled="
                                    processing ||
                                    precinct.status !== 'results_ready'
                                "
                            >
                                Publish results
                            </button>
                        </Form>
                    </div>
                </section>
            </div>
            <aside class="space-y-5">
                <section class="border border-stone-300 bg-white p-5">
                    <p class="text-sm font-bold text-blue-800">
                        Booth and printer status
                    </p>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <div class="border border-stone-200 p-3">
                            <dt>Active booths</dt>
                            <dd class="text-3xl font-bold">
                                {{ operationsBoard.booths.active }}
                            </dd>
                        </div>
                        <div class="border border-stone-200 p-3">
                            <dt>Print PINs</dt>
                            <dd class="text-3xl font-bold">
                                {{ operationsBoard.print_station.pending_pins }}
                            </dd>
                        </div>
                        <div class="border border-stone-200 p-3">
                            <dt>Printed</dt>
                            <dd class="text-3xl font-bold">
                                {{
                                    operationsBoard.print_station
                                        .printed_awaiting_deposit
                                }}
                            </dd>
                        </div>
                        <div class="border border-stone-200 p-3">
                            <dt>Deposited</dt>
                            <dd class="text-3xl font-bold">
                                {{ operationsBoard.print_station.deposited }}
                            </dd>
                        </div>
                    </dl>
                    <div class="mt-4 grid gap-2">
                        <a :href="actions.print" class="secondary-button"
                            >Open print station</a
                        >
                        <a :href="actions.watch" class="secondary-button"
                            >Open watcher view</a
                        >
                        <a :href="actions.handoff" class="secondary-button"
                            >Open handoff guide</a
                        >
                    </div>
                </section>
                <section class="border border-stone-300 bg-white p-5">
                    <p class="text-sm font-bold text-blue-800">Timeline</p>
                    <ol class="mt-4 grid gap-3 text-sm">
                        <li
                            v-for="event in operationsBoard.timeline"
                            :key="`${event.occurred_at}-${event.event_type}`"
                            class="border-l-2 border-stone-300 pl-3"
                        >
                            <strong>{{ event.label }}</strong>
                            <p class="text-stone-600">
                                {{ event.occurred_at }}
                            </p>
                        </li>
                    </ol>
                </section>
            </aside>
        </section>
        <div
            v-if="showForceCloseoutConfirm"
            class="fixed inset-0 z-50 grid place-items-center bg-stone-950/70 p-5"
            role="dialog"
            aria-modal="true"
            aria-labelledby="force-closeout-title"
        >
            <section
                class="w-full max-w-lg border border-stone-300 bg-white p-5"
            >
                <h2 id="force-closeout-title" class="text-xl font-bold">
                    Finalize all voter work and close?
                </h2>
                <p class="mt-3 text-sm text-stone-700">
                    This is for demonstrations. It will cancel unfinished booth
                    sessions, print and deposit any finalized but unresolved
                    paper ballots, then generate the tally and Election Return.
                </p>
                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                    <div class="border border-stone-200 p-3">
                        <dt>Active booths</dt>
                        <dd class="text-2xl font-bold">
                            {{ operationsBoard.booths.active }}
                        </dd>
                    </div>
                    <div class="border border-stone-200 p-3">
                        <dt>Unresolved print work</dt>
                        <dd class="text-2xl font-bold">
                            {{
                                operationsBoard.print_station.pending_pins +
                                operationsBoard.print_station.redeemed_pins +
                                operationsBoard.print_station
                                    .printed_awaiting_deposit
                            }}
                        </dd>
                    </div>
                </dl>
                <Form
                    :action="actions.forceClose"
                    method="post"
                    #default="{ errors, processing }"
                    class="mt-5"
                >
                    <input
                        type="hidden"
                        name="officer_code"
                        :value="officerDefaults.officer_code"
                    />
                    <input
                        type="hidden"
                        name="officer_pin"
                        :value="officerDefaults.officer_pin"
                    />
                    <input
                        type="hidden"
                        name="confirm_force_closeout"
                        value="FINALIZE"
                    />
                    <p
                        v-if="
                            errors.officer_pin || errors.confirm_force_closeout
                        "
                        class="text-sm font-bold text-red-700"
                    >
                        {{
                            errors.officer_pin ?? errors.confirm_force_closeout
                        }}
                    </p>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <button
                            class="min-h-11 border border-stone-500 px-4 font-bold"
                            type="button"
                            :disabled="processing"
                            @click="showForceCloseoutConfirm = false"
                        >
                            Return to officer console
                        </button>
                        <button
                            class="min-h-11 bg-amber-700 px-4 font-bold text-white disabled:opacity-50"
                            type="submit"
                            :disabled="processing"
                        >
                            {{
                                processing
                                    ? 'Finalizing...'
                                    : 'Finalize all and close'
                            }}
                        </button>
                    </div>
                </Form>
            </section>
        </div>
    </main>
</template>
