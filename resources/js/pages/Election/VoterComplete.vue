<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';
import ReviewStationBar from '@/components/election/ReviewStationBar.vue';

defineProps<{
    release: {
        release_id: string;
        release_code: string;
        release_qr_data_uri: string;
        pin_digits?: number;
        paper_ballot_serial?: string;
        expires_at: string;
        analytics?: {
            enabled: boolean;
            display_mode: 'hidden' | 'review' | 'presentation';
            total_duration_seconds: number;
            selection_edit_count: number;
            contest_navigation_clicks: number;
            surname_navigation_clicks: number;
            review_count: number;
            final_selection_count: number;
        };
    } | null;
    precinctClosed?: boolean;
    precinct?: {
        code: string;
        label: string;
    };
    returnAction?: string;
    resetAction: string;
    demoBallotPreviewEnabled?: boolean;
    ballotPreviewAction?: string | null;
}>();

const previewOpen = ref(false);

function clearBoothDraft(): void {
    sessionStorage.removeItem('aes-voter-draft');
}

function formatDuration(totalSeconds: number): string {
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    if (minutes === 0) {
        return `${seconds} seconds`;
    }

    return `${minutes} minute${minutes === 1 ? '' : 's'} ${seconds
        .toString()
        .padStart(2, '0')} seconds`;
}
</script>

<template>
    <main
        class="flex min-h-screen items-center justify-center bg-stone-100 p-5 text-stone-950"
    >
        <section
            class="w-full max-w-xl border-t-8 border-emerald-700 bg-white p-6 text-center shadow-sm sm:p-8"
        >
            <ReviewStationBar />
            <template v-if="precinctClosed">
                <p class="text-sm font-bold text-stone-700">
                    {{ precinct?.label ?? 'Precinct' }}
                </p>
                <h1 class="mt-2 text-3xl font-bold">
                    Voting has closed for this precinct
                </h1>
                <p class="mt-4 text-stone-700">
                    This tablet is no longer accepting voter activity for this
                    precinct. Please return the tablet to the Election Officer.
                </p>
                <div
                    class="mt-6 border border-blue-200 bg-blue-50 p-4 text-left text-sm"
                >
                    <strong class="block text-blue-950"
                        >No further voter action is needed</strong
                    >
                    <span class="mt-1 block text-blue-950">
                        If you already printed and deposited your paper ballot,
                        your voting session is complete.
                    </span>
                </div>
                <a
                    v-if="returnAction"
                    :href="returnAction"
                    class="mt-6 inline-flex min-h-12 items-center justify-center bg-stone-900 px-5 font-bold text-white"
                >
                    Ready for Election Officer
                </a>
            </template>
            <template v-else-if="release">
                <p class="text-sm font-bold text-emerald-800">
                    Ballot finalized privately
                </p>
                <h1 class="mt-2 text-3xl font-bold">
                    Write down your print PIN
                </h1>
                <p class="mt-4 text-stone-700">
                    Leave this tablet in the voting booth. Write this PIN on the
                    provided slip, then go to the central print station to print
                    your paper ballot. The print station will not display your
                    choices.
                </p>
                <img
                    :src="release.release_qr_data_uri"
                    alt="Private one-time print PIN QR code"
                    class="mx-auto mt-5 h-64 w-64 border border-stone-300 bg-white p-2"
                />
                <p class="mt-4 text-sm font-bold text-stone-600">
                    {{
                        release.pin_digits ?? release.release_code.length
                    }}-digit print PIN
                </p>
                <button
                    v-if="demoBallotPreviewEnabled && ballotPreviewAction"
                    class="mt-1 w-full border-2 border-dashed border-blue-300 bg-blue-50 px-4 py-3 font-mono text-5xl font-bold text-blue-950 transition hover:border-blue-800 hover:bg-blue-100 focus:ring-4 focus:ring-blue-200 focus:outline-none"
                    data-testid="open-voter-ballot-preview"
                    type="button"
                    @click="previewOpen = true"
                >
                    {{ release.release_code }}
                </button>
                <p v-else class="mt-1 font-mono text-5xl font-bold">
                    {{ release.release_code }}
                </p>
                <p
                    v-if="demoBallotPreviewEnabled && ballotPreviewAction"
                    class="mt-2 text-sm font-semibold text-blue-800"
                >
                    Demo shortcut: tap the PIN to preview the printable ballot.
                </p>
                <p class="mt-2 text-sm text-stone-600">
                    Paper stock serial {{ release.paper_ballot_serial }}
                </p>
                <div
                    v-if="
                        release.analytics &&
                        release.analytics.enabled &&
                        release.analytics.display_mode !== 'hidden'
                    "
                    class="mt-5 border border-emerald-200 bg-emerald-50 p-4 text-left text-sm"
                >
                    <strong class="block text-emerald-950">
                        Ballot timing summary
                    </strong>
                    <span class="mt-1 block text-emerald-950">
                        Completed in
                        {{
                            formatDuration(
                                release.analytics.total_duration_seconds,
                            )
                        }}.
                    </span>
                    <span class="mt-1 block text-emerald-900">
                        {{ release.analytics.final_selection_count }}
                        selections, {{ release.analytics.selection_edit_count }}
                        selection changes,
                        {{ release.analytics.review_count }} review visit.
                    </span>
                </div>
                <div
                    class="mt-6 border border-amber-300 bg-amber-50 p-4 text-left text-sm"
                >
                    <strong class="block text-amber-900"
                        >Before depositing the paper ballot</strong
                    >
                    <span class="mt-1 block text-amber-900">
                        Read the printed candidate names in private. If anything
                        is wrong, do not deposit it; call an Election Board
                        member.
                    </span>
                </div>
                <Form
                    :action="resetAction"
                    method="post"
                    #default="{ processing }"
                    class="mt-5"
                    @submit="clearBoothDraft"
                >
                    <button
                        class="min-h-14 w-full bg-stone-900 px-5 py-3 text-lg font-bold text-white disabled:opacity-50"
                        type="submit"
                        :disabled="processing"
                    >
                        {{
                            processing
                                ? 'Resetting booth...'
                                : 'Reset booth for next voter'
                        }}
                    </button>
                </Form>
            </template>
        </section>

        <section
            v-if="previewOpen && ballotPreviewAction"
            class="fixed inset-0 z-50 flex items-center justify-center bg-stone-950/70 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="voter-ballot-preview-title"
        >
            <div
                class="flex max-h-[92vh] w-full max-w-5xl flex-col bg-white shadow-2xl"
            >
                <header
                    class="flex flex-wrap items-start justify-between gap-3 border-b border-stone-200 p-4 text-left"
                >
                    <div>
                        <p class="text-sm font-bold text-blue-800">
                            Demonstration preview only
                        </p>
                        <h2
                            id="voter-ballot-preview-title"
                            class="mt-1 text-2xl font-bold text-stone-950"
                        >
                            Printable ballot preview
                        </h2>
                        <p class="mt-1 max-w-3xl text-sm text-stone-700">
                            In a real precinct, the voter tablet does not show
                            this preview. The print PIN remains valid for the
                            central print station.
                        </p>
                    </div>
                    <button
                        class="min-h-11 border border-stone-300 px-4 font-bold text-stone-800"
                        type="button"
                        @click="previewOpen = false"
                    >
                        Close preview
                    </button>
                </header>
                <div class="min-h-0 flex-1 bg-stone-100 p-3">
                    <iframe
                        :src="ballotPreviewAction"
                        class="h-[70vh] w-full border border-stone-300 bg-white"
                        title="Printable ballot PDF preview"
                    />
                </div>
                <footer
                    class="flex flex-wrap items-center justify-between gap-3 border-t border-stone-200 p-4 text-left"
                >
                    <p class="text-sm font-semibold text-stone-700">
                        This preview does not deposit, count, or accept the
                        ballot.
                    </p>
                    <a
                        :href="ballotPreviewAction"
                        class="inline-flex min-h-11 items-center justify-center bg-blue-800 px-4 font-bold text-white"
                        target="_blank"
                    >
                        Open PDF in new tab
                    </a>
                </footer>
            </div>
        </section>
    </main>
</template>
