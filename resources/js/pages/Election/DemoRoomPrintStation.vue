<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PrintStation from './PrintStation.vue';

const props = defineProps<{
    round: { code: string; name: string };
    precinct: {
        code: string;
        label: string;
        clustered_precinct: string;
        city_municipality: string | null;
        province: string | null;
        status: string;
    };
    enabled: boolean;
    isVoting: boolean;
    isCloseoutReady: boolean;
    isPublished: boolean;
    artifacts: {
        tally_sheet_pdf: boolean;
        election_return_pdf: boolean;
    };
    printProfiles: Array<{
        profile: string;
        label: string;
        description: string;
        width_mm: number;
        thermal: boolean;
        tally_available: boolean;
        return_available: boolean;
        tally_url: string;
        return_url: string;
    }>;
    release: {
        release_id?: string;
        paper_ballot_serial?: string;
        status?: string;
        pin_digits?: number;
        expires_at?: string;
    };
    ballotPreview?: {
        paper_ballot_serial?: string | null;
        ballot_id?: string | null;
        qr_payload?: string | null;
        decoded?: {
            schema_version?: string | null;
            election_id?: string | null;
            precinct_id?: string | null;
            ballot_style_id?: string | null;
            mapping_hash?: string | null;
            tabulation_profile?: string | null;
            paper_ballot_serial?: string | null;
            payload_hash?: string | null;
            candidate_codes: string[];
        };
        candidate_mapping?: Array<{
            code: string;
            contest: string;
            candidate: string;
            party?: string | null;
        }>;
        rows: Array<{ contest: string; selections: string[] }>;
    } | null;
    ballotPreviewUrl?: string | null;
    depositFeedback?: {
        status: string;
        paper_ballot_serial: string;
    };
    closeoutFeedback?: string;
    officerDefaults: { officer_code: string; officer_pin: string };
    actions: {
        enable: string;
        redeem: string;
        print: string;
        deposit: string;
        officer: string;
        handoff: string;
        watch: string;
        tally: string;
        return: string;
    };
    printPinDigits: number;
}>();

const selectedProfile = ref('a4');

const selectedPrintProfile = computed(() => {
    return (
        props.printProfiles.find(
            (profile) => profile.profile === selectedProfile.value,
        ) ??
        props.printProfiles[0] ?? {
            profile: 'a4',
            label: 'A4 evidence copy',
            description: 'Full-page review, posting, and evidence-copy layout.',
            width_mm: 210,
            thermal: false,
            tally_available: props.artifacts.tally_sheet_pdf,
            return_available: props.artifacts.election_return_pdf,
            tally_url: props.actions.tally,
            return_url: props.actions.return,
        }
    );
});
</script>

<template>
    <Head :title="`${precinct.code} central print station`" />

    <main v-if="!enabled" class="min-h-screen bg-stone-100 text-stone-950">
        <div class="grid h-1.5 grid-cols-3">
            <span class="bg-blue-800" /><span class="bg-yellow-400" /><span
                class="bg-red-700"
            />
        </div>
        <section class="mx-auto max-w-xl px-5 py-10 sm:px-8">
            <Link
                :href="actions.officer"
                class="text-sm font-bold text-blue-800"
                >Back to officer console</Link
            >
            <div class="mt-4 border border-stone-300 bg-white p-6">
                <p class="text-sm font-bold text-blue-800">
                    Central print station
                </p>
                <h1 class="mt-2 text-3xl font-bold">
                    Enable printer for {{ precinct.label }}
                </h1>
                <p class="mt-3 text-stone-700">
                    An Election Officer enables this laptop once. After that,
                    the station waits for voter print PINs and prints without
                    displaying vote choices on screen.
                </p>
                <Form
                    :action="actions.enable"
                    method="post"
                    #default="{ errors, processing }"
                    class="mt-6 space-y-4"
                >
                    <label class="block">
                        <span class="text-sm font-bold">Officer username</span>
                        <input
                            class="mt-1 min-h-12 w-full border-2 border-stone-300 px-3 font-mono"
                            name="officer_code"
                            required
                            :value="officerDefaults.officer_code"
                        />
                    </label>
                    <label class="block">
                        <span class="text-sm font-bold">Officer PIN</span>
                        <input
                            class="mt-1 min-h-12 w-full border-2 border-stone-300 px-3 font-mono"
                            name="officer_pin"
                            required
                            inputmode="numeric"
                            maxlength="6"
                            pattern="[0-9]{6}"
                            :value="officerDefaults.officer_pin"
                        />
                    </label>
                    <p v-if="errors.officer_pin" class="font-bold text-red-700">
                        {{ errors.officer_pin }}
                    </p>
                    <button
                        class="review-next-action-button min-h-14 w-full bg-blue-800 px-5 font-bold text-white disabled:opacity-50"
                        type="submit"
                        :disabled="processing"
                    >
                        {{
                            processing
                                ? 'Enabling...'
                                : 'Enable central print station'
                        }}
                    </button>
                </Form>
            </div>
        </section>
    </main>

    <main v-else-if="isVoting" class="min-h-screen bg-stone-950 text-stone-950">
        <div class="bg-white px-5 py-3 text-sm sm:px-8">
            <a :href="actions.officer" class="font-bold text-blue-800"
                >Officer console</a
            >
            <span class="mx-2 text-stone-400">/</span>
            <span>{{ precinct.label }} print station enabled</span>
        </div>
        <div
            v-if="closeoutFeedback"
            class="border-l-8 border-amber-500 bg-amber-50 px-5 py-4 font-semibold text-amber-950 sm:px-8"
        >
            {{ closeoutFeedback }}
        </div>
        <PrintStation
            :release="release"
            :ballot-preview="ballotPreview"
            :ballot-preview-url="ballotPreviewUrl"
            :deposit-feedback="depositFeedback"
            :actions="{
                redeem: actions.redeem,
                print: actions.print,
                deposit: actions.deposit,
            }"
            :print-pin-digits="printPinDigits"
            public-simulation
        />
    </main>

    <main v-else class="min-h-screen bg-stone-100 text-stone-950">
        <div class="grid h-1.5 grid-cols-3">
            <span class="bg-blue-800" /><span class="bg-yellow-400" /><span
                class="bg-red-700"
            />
        </div>
        <section class="mx-auto max-w-4xl px-5 py-10 sm:px-8">
            <Link
                :href="actions.officer"
                class="text-sm font-bold text-blue-800"
                >Back to officer console</Link
            >
            <div class="mt-4 border border-stone-300 bg-white p-6">
                <div
                    v-if="closeoutFeedback"
                    class="mb-5 border-l-8 border-amber-500 bg-amber-50 p-4 font-semibold text-amber-950"
                >
                    {{ closeoutFeedback }}
                </div>
                <p class="text-sm font-bold text-blue-800">Closeout printing</p>
                <h1 class="mt-2 text-3xl font-bold">
                    Print tally, Election Return, and handoff packet
                </h1>
                <p class="mt-3 text-stone-700">
                    {{
                        isCloseoutReady
                            ? 'Polls are no longer accepting voters. Use this printer station to produce the official closeout artifacts. The same laptop may open each PDF in the browser and print through the connected local printer.'
                            : 'Close the precinct from the officer console before printing the tally sheet and Election Return.'
                    }}
                </p>

                <section
                    class="mt-6 border border-stone-300 bg-stone-50 p-4"
                    aria-labelledby="print-format-heading"
                >
                    <div
                        class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div>
                            <h2
                                id="print-format-heading"
                                class="text-lg font-bold"
                            >
                                Print format
                            </h2>
                            <p class="mt-1 text-sm text-stone-700">
                                {{ selectedPrintProfile.description }}
                            </p>
                        </div>
                        <p class="text-sm font-bold text-stone-600">
                            {{ selectedPrintProfile.width_mm }} mm
                        </p>
                    </div>
                    <div class="mt-4 grid gap-2 sm:grid-cols-3">
                        <button
                            v-for="profile in printProfiles"
                            :key="profile.profile"
                            type="button"
                            class="min-h-16 border-2 px-3 py-2 text-left"
                            :class="
                                selectedProfile === profile.profile
                                    ? 'border-blue-800 bg-blue-800 text-white'
                                    : 'border-stone-300 bg-white text-stone-950'
                            "
                            @click="selectedProfile = profile.profile"
                        >
                            <span class="block font-bold">{{
                                profile.label
                            }}</span>
                            <span
                                class="mt-1 block text-xs"
                                :class="
                                    selectedProfile === profile.profile
                                        ? 'text-blue-50'
                                        : 'text-stone-600'
                                "
                            >
                                {{ profile.width_mm }} mm
                            </span>
                        </button>
                    </div>
                </section>

                <div class="mt-6 grid gap-3 sm:grid-cols-2">
                    <a
                        :href="selectedPrintProfile.tally_url"
                        class="min-h-14 bg-blue-800 px-5 py-4 text-center font-bold text-white"
                        :class="{
                            'pointer-events-none opacity-40':
                                !selectedPrintProfile.tally_available,
                        }"
                        >Open / print tally sheet</a
                    >
                    <a
                        :href="selectedPrintProfile.return_url"
                        class="min-h-14 bg-blue-800 px-5 py-4 text-center font-bold text-white"
                        :class="{
                            'pointer-events-none opacity-40':
                                !selectedPrintProfile.return_available,
                        }"
                        >Open / print Election Return</a
                    >
                    <a
                        :href="actions.watch"
                        class="secondary-button text-center"
                        >Open watcher publication</a
                    >
                    <a
                        :href="actions.handoff"
                        class="secondary-button text-center"
                        >Open handoff guide</a
                    >
                </div>
                <p
                    v-if="
                        isCloseoutReady &&
                        (!artifacts.tally_sheet_pdf ||
                            !artifacts.election_return_pdf)
                    "
                    class="mt-5 border-l-4 border-red-600 bg-red-50 p-4 text-sm font-semibold text-red-950"
                >
                    Closeout is marked ready, but one or more PDF artifacts are
                    missing. Return to the officer console and run closeout
                    again.
                </p>
                <p
                    v-else-if="!isPublished"
                    class="mt-5 border-l-4 border-amber-500 bg-amber-50 p-4 text-sm font-semibold text-amber-950"
                >
                    The closeout PDFs are printable now. Publish the watcher
                    packet from the officer console when you are ready to make
                    the public download page visible.
                </p>
            </div>
        </section>
    </main>
</template>
