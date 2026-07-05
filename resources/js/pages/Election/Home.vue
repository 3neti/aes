<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import {
    provision,
    certification,
    voting,
    counting,
    returns,
    diagnostics,
} from '@/routes/election';

defineProps<{ snapshot: ElectionSnapshot }>();
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Election Home">
        <section class="border border-stone-300 bg-white p-5">
            <h2 class="text-lg font-semibold">Precinct Appliance</h2>
            <p class="mt-2 text-sm text-stone-700">
                This appliance is guided by ceremonies. Follow the next required
                action and preserve the printed artifacts.
            </p>
            <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Link class="action-link" :href="provision.url()"
                    >Provision</Link
                >
                <Link class="action-link" :href="certification.url()"
                    >Certification</Link
                >
                <Link class="action-link" :href="voting.url()">Voting</Link>
                <Link class="action-link" :href="counting.url()">Counting</Link>
                <Link class="action-link" :href="returns.url()"
                    >Election Return</Link
                >
                <Link class="action-link" :href="diagnostics.url()"
                    >Diagnostics</Link
                >
            </div>
        </section>
    </CeremonyLayout>
</template>

<style scoped>
.action-link {
    border: 1px solid rgb(168 162 158);
    padding: 0.9rem 1rem;
    font-weight: 600;
}
</style>
