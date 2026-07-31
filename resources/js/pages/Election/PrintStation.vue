<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ReviewStationBar from '@/components/election/ReviewStationBar.vue';
import type { ElectionReviewRoomContext } from '@/components/election/types';
import { deposit, print, redeem } from '@/routes/election/print-station';

const props = defineProps<{
    release: {
        release_id?: string;
        paper_ballot_serial?: string;
        status?: string;
        pin_digits?: number;
        expires_at?: string;
    };
    depositFeedback?: {
        status: string;
        paper_ballot_serial: string;
    };
    actions?: {
        redeem: string;
        print: string;
        deposit: string;
    };
    printPinDigits?: number;
    publicSimulation?: boolean;
}>();

const page = usePage();
const reviewRoom = computed(
    () => page.props.electionReviewRoom as ElectionReviewRoomContext,
);
const pinDigits = computed(() => props.printPinDigits ?? 4);
const pinPlaceholder = computed(() => '0'.repeat(pinDigits.value));
</script>

<template>
    <main
        class="flex min-h-screen items-center justify-center bg-stone-950 p-5 text-stone-950"
    >
        <section class="w-full max-w-xl bg-white p-6 shadow-lg sm:p-8">
            <ReviewStationBar />
            <p class="text-sm font-bold text-blue-800">
                Private paper ballot station
            </p>

            <div
                v-if="depositFeedback"
                class="mt-5 border-l-8 border-emerald-700 bg-emerald-50 p-5"
            >
                <h1 class="text-2xl font-bold text-emerald-900">
                    Ballot accepted
                </h1>
                <p class="mt-2 text-emerald-900">
                    Paper ballot {{ depositFeedback.paper_ballot_serial }} is
                    recorded in the sealed ballot box.
                </p>
            </div>

            <template v-if="!release.release_id">
                <h1 class="mt-2 text-3xl font-bold">
                    Enter the voter's print PIN
                </h1>
                <p class="mt-3 text-stone-700">
                    The voter writes the PIN in the covered voting booth. This
                    station prints the ballot without showing candidate
                    selections on screen.
                </p>
                <Form
                    v-bind="
                        actions
                            ? { action: actions.redeem, method: 'post' }
                            : redeem.form()
                    "
                    #default="{ errors, processing }"
                    class="mt-7 space-y-4"
                >
                    <label class="block">
                        <span class="text-sm font-bold">Print PIN</span>
                        <input
                            class="mt-1 min-h-14 w-full border-2 border-stone-400 px-4 text-center text-2xl font-bold"
                            name="code"
                            required
                            autocomplete="off"
                            autofocus
                            inputmode="numeric"
                            :maxlength="pinDigits"
                            :pattern="`[0-9]{${pinDigits}}`"
                            :placeholder="pinPlaceholder"
                        />
                    </label>
                    <p v-if="errors.code" class="font-bold text-red-700">
                        {{ errors.code }}
                    </p>
                    <button
                        class="min-h-14 w-full bg-blue-800 px-5 py-3 text-lg font-bold text-white disabled:opacity-50"
                        :class="{
                            'review-next-action-button': reviewRoom.enabled,
                        }"
                        type="submit"
                        :disabled="processing"
                    >
                        {{ processing ? 'Checking...' : 'Claim paper ballot' }}
                    </button>
                </Form>
            </template>

            <template v-else>
                <h1 class="mt-2 text-3xl font-bold">
                    {{
                        release.status === 'printed'
                            ? 'Verify the paper ballot'
                            : 'Paper ballot ready'
                    }}
                </h1>
                <p class="mt-3 text-stone-700">
                    Candidate selections are intentionally hidden on this
                    station. Paper stock serial:
                    <strong>{{ release.paper_ballot_serial }}</strong>
                </p>

                <Form
                    v-if="
                        ['pending', 'redeemed'].includes(release.status ?? '')
                    "
                    v-bind="
                        actions
                            ? { action: actions.print, method: 'post' }
                            : print.form()
                    "
                    #default="{ errors, processing }"
                    class="mt-7"
                >
                    <p
                        v-if="errors.printer"
                        class="mb-3 font-bold text-red-700"
                    >
                        {{ errors.printer }}
                    </p>
                    <button
                        class="min-h-16 w-full bg-blue-800 px-5 py-3 text-xl font-bold text-white disabled:opacity-50"
                        :class="{
                            'review-next-action-button': reviewRoom.enabled,
                        }"
                        type="submit"
                        :disabled="processing"
                    >
                        {{ processing ? 'Printing...' : 'Print paper ballot' }}
                    </button>
                </Form>

                <Form
                    v-else
                    v-bind="
                        actions
                            ? { action: actions.deposit, method: 'post' }
                            : deposit.form()
                    "
                    #default="{ errors, processing }"
                    class="mt-7"
                >
                    <div
                        class="border border-amber-300 bg-amber-50 p-4 text-amber-950"
                    >
                        The voter must privately verify the printed names before
                        the ballot is scanned and deposited.
                    </div>
                    <p
                        v-if="errors.deposit"
                        class="mt-3 font-bold text-red-700"
                    >
                        {{ errors.deposit }}
                    </p>
                    <button
                        class="mt-4 min-h-16 w-full bg-emerald-700 px-5 py-3 text-xl font-bold text-white disabled:opacity-50"
                        :class="{
                            'review-next-action-button': reviewRoom.enabled,
                        }"
                        type="submit"
                        :disabled="processing"
                    >
                        {{
                            processing
                                ? 'Recording deposit...'
                                : 'Scan and deposit verified ballot'
                        }}
                    </button>
                </Form>
            </template>
        </section>
    </main>
</template>
