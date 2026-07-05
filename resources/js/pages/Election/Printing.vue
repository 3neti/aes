<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import { print, spoil } from '@/routes/election/printing';

defineProps<{
    snapshot: ElectionSnapshot;
    payload: Record<string, any>;
    qrImageDataUri: string;
}>();
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Printing">
        <section class="border border-stone-300 bg-white p-5">
            <h2 class="text-lg font-semibold">Official Ballot Artifact</h2>
            <div v-if="payload.ballot_id" class="mt-4 space-y-3">
                <dl class="text-sm">
                    <dt class="font-semibold">Ballot</dt>
                    <dd>{{ payload.ballot_id }}</dd>
                    <dt class="mt-3 font-semibold">Payload Hash</dt>
                    <dd class="break-all text-stone-700">
                        {{ payload.payload_hash }}
                    </dd>
                    <dt class="mt-3 font-semibold">PDF Artifact</dt>
                    <dd class="break-all text-stone-700">
                        ballots/{{ payload.ballot_id }}.pdf
                    </dd>
                </dl>
                <div
                    v-if="qrImageDataUri"
                    class="inline-block border border-stone-300 bg-white p-3"
                >
                    <img
                        class="h-64 w-64"
                        :src="qrImageDataUri"
                        alt="Ballot QR code"
                    />
                </div>
                <div class="flex flex-wrap gap-3">
                    <Form
                        v-bind="print.form(payload.ballot_id)"
                        #default="{ errors }"
                    >
                        <button class="primary-button" type="submit">
                            Print Ballot
                        </button>
                        <p
                            v-if="errors.printer"
                            class="mt-2 max-w-xl text-sm font-semibold text-red-700"
                        >
                            {{ errors.printer }}
                        </p>
                    </Form>
                    <Form v-bind="spoil.form(payload.ballot_id)">
                        <button class="secondary-button" type="submit">
                            Spoil Ballot
                        </button>
                    </Form>
                </div>
                <textarea
                    class="h-36 w-full border border-stone-300 p-3 text-xs"
                    readonly
                    :value="payload.qr_payload"
                />
            </div>
            <p v-else class="mt-3 text-sm text-stone-700">
                No finalized ballot selected.
            </p>
        </section>
    </CeremonyLayout>
</template>

<style scoped>
.primary-button {
    background: rgb(4 120 87);
    color: white;
    padding: 0.7rem 1rem;
    font-weight: 700;
}

.secondary-button {
    border: 1px solid rgb(120 113 108);
    padding: 0.65rem 0.9rem;
    font-weight: 700;
}
</style>
