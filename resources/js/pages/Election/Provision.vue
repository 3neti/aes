<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import { activate } from '@/routes/election/provision';

defineProps<{ snapshot: ElectionSnapshot }>();
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Provision">
        <section class="border border-stone-300 bg-white p-5">
            <h2 class="text-lg font-semibold">Sample Precinct Package</h2>
            <p class="mt-2 text-sm text-stone-700">
                Activate the embedded sample package to derive the local ballot
                mapping.
            </p>
            <Form v-bind="activate.form()" class="mt-5">
                <button class="primary-button" type="submit">
                    Activate Package
                </button>
            </Form>
            <dl v-if="snapshot.configuration.mapping_hash" class="mt-5 text-sm">
                <dt class="font-semibold">Mapping Hash</dt>
                <dd class="break-all text-stone-700">
                    {{ snapshot.configuration.mapping_hash }}
                </dd>
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
</style>
