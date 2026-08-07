<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
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
    ballotPreview?: {
        paper_ballot_serial?: string | null;
        ballot_id?: string | null;
        rows: Array<{ contest: string; selections: string[] }>;
    } | null;
    ballotPreviewUrl?: string | null;
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
const acceptedDismissed = ref(false);
const previewOpen = ref(false);
const printingOverlayVisible = ref(false);
const printOverlayStorageKey = 'aes-print-station-overlay-until';
let printingOverlayTimer: ReturnType<typeof setTimeout> | null = null;

const canPeekAtBallot = computed(
    () =>
        props.publicSimulation === true &&
        props.release.status === 'printed' &&
        ((props.ballotPreview?.rows.length ?? 0) > 0 ||
            Boolean(props.ballotPreviewUrl)),
);

function showPrintingOverlay(): void {
    window.sessionStorage.setItem(
        printOverlayStorageKey,
        String(Date.now() + 3000),
    );
    syncPrintingOverlay();
}

function syncPrintingOverlay(): void {
    if (printingOverlayTimer !== null) {
        window.clearTimeout(printingOverlayTimer);
    }

    const remaining =
        Number(window.sessionStorage.getItem(printOverlayStorageKey) ?? 0) -
        Date.now();

    if (remaining <= 0) {
        printingOverlayVisible.value = false;
        window.sessionStorage.removeItem(printOverlayStorageKey);

        return;
    }

    printingOverlayVisible.value = true;
    printingOverlayTimer = window.setTimeout(() => {
        printingOverlayVisible.value = false;
        window.sessionStorage.removeItem(printOverlayStorageKey);
    }, remaining);
}

onMounted(syncPrintingOverlay);
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

            <template v-if="depositFeedback && !acceptedDismissed">
                <div
                    class="mt-5 border-l-8 border-emerald-700 bg-emerald-50 p-5"
                >
                    <h1 class="text-3xl font-bold text-emerald-900">
                        Ballot accepted
                    </h1>
                    <p class="mt-3 text-lg text-emerald-900">
                        Paper ballot
                        <strong>{{
                            depositFeedback.paper_ballot_serial
                        }}</strong>
                        is recorded in the sealed ballot box.
                    </p>
                    <button
                        class="mt-6 min-h-14 w-full bg-emerald-700 px-5 py-3 text-lg font-bold text-white"
                        type="button"
                        @click="acceptedDismissed = true"
                    >
                        Dismiss and accept next print PIN
                    </button>
                </div>
            </template>

            <template v-else-if="!release.release_id">
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
                        @click="showPrintingOverlay"
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
                    <button
                        v-if="canPeekAtBallot"
                        class="mb-4 min-h-12 w-full border-2 border-blue-800 px-5 py-3 font-bold text-blue-900"
                        type="button"
                        @click="previewOpen = true"
                    >
                        Demo peek at printed ballot
                    </button>
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

        <div
            v-if="printingOverlayVisible"
            class="fixed inset-0 z-40 flex items-center justify-center bg-stone-950/75 p-5"
        >
            <section class="w-full max-w-sm bg-white p-6 text-center shadow-xl">
                <p class="text-sm font-bold text-blue-800">
                    Central print station
                </p>
                <h2 class="mt-2 text-3xl font-bold">Printing ballot...</h2>
                <p class="mt-3 text-stone-700">
                    Keep the printed ballot face down and hand it to the voter
                    for private verification.
                </p>
            </section>
        </div>

        <div
            v-if="previewOpen && ballotPreview"
            class="fixed inset-0 z-50 flex items-center justify-center bg-stone-950/80 p-5"
        >
            <section
                class="max-h-[90vh] w-full max-w-2xl overflow-auto bg-white p-6 shadow-xl"
            >
                <p class="text-sm font-bold text-red-700">
                    Demonstration preview only
                </p>
                <h2 class="mt-2 text-3xl font-bold">Printed ballot preview</h2>
                <p class="mt-2 text-stone-700">
                    This peek is enabled only for the public simulation demo.
                    Open the PDF to inspect the same printed ballot artifact,
                    including its QR code.
                </p>
                <a
                    v-if="ballotPreviewUrl"
                    :href="ballotPreviewUrl"
                    target="_blank"
                    rel="noopener"
                    class="mt-5 block min-h-12 w-full bg-blue-800 px-5 py-3 text-center font-bold text-white"
                >
                    Open QR ballot PDF
                </a>
                <dl class="mt-5 grid gap-3 border-y border-stone-200 py-4">
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-sm font-bold text-stone-600">Serial</dt>
                        <dd class="font-mono font-bold">
                            {{ ballotPreview.paper_ballot_serial }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-sm font-bold text-stone-600">
                            Ballot ID
                        </dt>
                        <dd class="font-mono text-sm">
                            {{ ballotPreview.ballot_id }}
                        </dd>
                    </div>
                </dl>
                <div class="mt-5 space-y-4">
                    <article
                        v-for="row in ballotPreview.rows"
                        :key="row.contest"
                        class="border border-stone-200 p-4"
                    >
                        <h3 class="font-bold text-stone-950">
                            {{ row.contest }}
                        </h3>
                        <ul class="mt-2 space-y-1 text-stone-800">
                            <li
                                v-for="selection in row.selections"
                                :key="`${row.contest}-${selection}`"
                            >
                                {{ selection }}
                            </li>
                        </ul>
                    </article>
                </div>
                <button
                    class="mt-6 min-h-12 w-full bg-stone-950 px-5 font-bold text-white"
                    type="button"
                    @click="previewOpen = false"
                >
                    Dismiss preview
                </button>
            </section>
        </div>
    </main>
</template>
