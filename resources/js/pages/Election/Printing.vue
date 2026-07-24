<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import ArtifactLinks from '@/components/election/ArtifactLinks.vue';
import CeremonyActionPanel from '@/components/election/CeremonyActionPanel.vue';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import { voting } from '@/routes/election';
import { print, spoil } from '@/routes/election/printing';

type BallotPayload = {
    ballot_id?: string;
    payload_hash?: string;
    qr_payload?: string;
    precinct_id?: string;
    ballot_style_id?: string;
    paper_ballot_serial?: string;
};

defineProps<{
    snapshot: ElectionSnapshot;
    payload: BallotPayload;
    qrImageDataUri: string;
}>();
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Ballot Printing">
        <CeremonyActionPanel
            title="Official Ballot Artifact"
            description="Review the prepared ballot identifier and QR mark before producing the paper ballot."
            eyebrow="Ballot preparation"
            :status="payload.ballot_id ? 'Ready to print' : 'No ballot waiting'"
            :tone="payload.ballot_id ? 'current' : 'neutral'"
        >
            <div
                v-if="payload.ballot_id"
                class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_280px]"
            >
                <div class="min-w-0">
                    <div
                        class="border-l-4 border-blue-700 bg-blue-50 px-4 py-3 text-sm text-blue-950"
                    >
                        <p class="font-bold">Paper ballot control</p>
                        <p class="mt-1">
                            Inspect the printed candidate choices and QR mark.
                            The voter must review the paper before casting it.
                        </p>
                    </div>

                    <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-stone-500">Ballot identifier</dt>
                            <dd class="mt-1 font-bold text-stone-950">
                                {{ payload.ballot_id }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-stone-500">Clustered precinct</dt>
                            <dd class="mt-1 font-bold text-stone-950">
                                {{
                                    payload.precinct_id ||
                                    snapshot.configuration.precinct_id
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-stone-500">Paper stock serial</dt>
                            <dd class="mt-1 font-bold text-stone-950">
                                {{
                                    payload.paper_ballot_serial ||
                                    'Certification / unnumbered'
                                }}
                            </dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-stone-500">Payload hash</dt>
                            <dd
                                class="mt-1 font-mono text-xs break-all text-stone-700"
                            >
                                {{ payload.payload_hash }}
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-5 flex flex-wrap gap-3">
                        <Form
                            v-bind="print.form(payload.ballot_id)"
                            #default="{ errors, processing }"
                        >
                            <button
                                class="primary-button"
                                type="submit"
                                :disabled="processing"
                            >
                                {{
                                    processing
                                        ? 'Preparing print...'
                                        : 'Print paper ballot'
                                }}
                            </button>
                            <p
                                v-if="errors.printer"
                                class="mt-2 max-w-xl text-sm font-bold text-red-700"
                            >
                                {{ errors.printer }}
                            </p>
                        </Form>
                        <Form
                            v-bind="spoil.form(payload.ballot_id)"
                            #default="{ processing }"
                        >
                            <button
                                class="danger-button"
                                type="submit"
                                :disabled="processing"
                            >
                                Mark spoiled and return
                            </button>
                        </Form>
                    </div>

                    <ArtifactLinks
                        class="mt-5"
                        :artifacts="[
                            {
                                label: 'Printable ballot artifact',
                                path: `ballots/${payload.ballot_id}.pdf`,
                            },
                            {
                                label: 'Ballot preparation record',
                                path: `ballots/${payload.ballot_id}.json`,
                            },
                        ]"
                    />

                    <details class="mt-4 border border-stone-200">
                        <summary
                            class="cursor-pointer px-4 py-3 text-sm font-bold text-stone-700"
                        >
                            Technical QR payload
                        </summary>
                        <textarea
                            class="h-36 w-full border-t border-stone-200 bg-stone-50 p-3 font-mono text-xs"
                            readonly
                            :value="payload.qr_payload"
                        />
                    </details>
                </div>

                <figure class="border border-stone-300 bg-stone-50 p-4">
                    <div class="aspect-square bg-white p-3">
                        <img
                            v-if="qrImageDataUri"
                            class="h-full w-full object-contain"
                            :src="qrImageDataUri"
                            alt="Ballot QR code for scanner verification"
                        />
                    </div>
                    <figcaption class="mt-3 text-center text-xs text-stone-600">
                        Scanner mark for ballot
                        <span class="font-bold">{{ payload.ballot_id }}</span>
                    </figcaption>
                </figure>
            </div>

            <div v-else class="py-8 text-center">
                <p class="text-lg font-bold text-stone-900">
                    No finalized ballot is waiting.
                </p>
                <p class="mt-2 text-sm text-stone-600">
                    Return to Voting and finalize the voter selections first.
                </p>
                <Link
                    :href="voting.url()"
                    class="mt-5 inline-flex min-h-11 items-center justify-center border border-stone-800 bg-white px-5 py-3 text-sm font-bold text-stone-950"
                >
                    Return to Voting
                </Link>
            </div>
        </CeremonyActionPanel>
    </CeremonyLayout>
</template>

<style scoped>
.primary-button,
.danger-button {
    min-height: 2.75rem;
    padding: 0.7rem 1rem;
    font-size: 0.875rem;
    font-weight: 700;
}

.primary-button {
    background: rgb(30 64 175);
    color: white;
}

.danger-button {
    border: 1px solid rgb(185 28 28);
    background: white;
    color: rgb(153 27 27);
}

.primary-button:disabled,
.danger-button:disabled {
    cursor: not-allowed;
    opacity: 0.5;
}
</style>
