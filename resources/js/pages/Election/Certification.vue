<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import { run } from '@/routes/election/certification';

defineProps<{ snapshot: ElectionSnapshot }>();
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Certification">
        <section class="border border-stone-300 bg-white p-5">
            <h2 class="text-lg font-semibold">Friday Certification</h2>
            <p class="mt-2 text-sm text-stone-700">
                Run known certification ballots and compare the generated tally
                with the expected result.
            </p>
            <Form v-bind="run.form()" class="mt-5">
                <button class="primary-button" type="submit">
                    Run Certification
                </button>
            </Form>
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
