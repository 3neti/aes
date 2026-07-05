<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import { close, generate } from '@/routes/election/returns';

defineProps<{
    snapshot: ElectionSnapshot;
    returnArtifact: Record<string, any>;
}>();
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Election Return">
        <section class="border border-stone-300 bg-white p-5">
            <h2 class="text-lg font-semibold">Election Return</h2>
            <div class="mt-4 flex flex-wrap gap-3">
                <Form v-bind="generate.form()">
                    <button class="primary-button" type="submit">
                        Generate Election Return
                    </button>
                </Form>
                <Form v-bind="close.form()">
                    <button class="secondary-button" type="submit">
                        Close Precinct
                    </button>
                </Form>
            </div>
            <dl v-if="returnArtifact.return_hash" class="mt-5 text-sm">
                <dt class="font-semibold">Return Hash</dt>
                <dd class="break-all text-stone-700">
                    {{ returnArtifact.return_hash }}
                </dd>
                <dt class="mt-3 font-semibold">Accepted Ballots</dt>
                <dd>{{ returnArtifact.accepted_ballots }}</dd>
            </dl>
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
