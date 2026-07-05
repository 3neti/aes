<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import { complete, scan } from '@/routes/election/counting';

defineProps<{
    snapshot: ElectionSnapshot;
    tally: {
        accepted_ballots: number;
        rejected_ballots: number;
        tally: Record<string, Record<string, number>>;
    };
}>();
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Counting">
        <section class="border border-stone-300 bg-white p-5">
            <h2 class="text-lg font-semibold">Scan Ballot Payload</h2>
            <Form v-bind="scan.form()" class="mt-4 space-y-3">
                <textarea
                    name="payload"
                    class="h-32 w-full border border-stone-300 p-3 text-sm"
                    required
                />
                <button class="primary-button" type="submit">
                    Accept Scan
                </button>
            </Form>
        </section>

        <section class="border border-stone-300 bg-white p-5">
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
            >
                <div>
                    <h2 class="text-lg font-semibold">Current Tally</h2>
                    <p class="mt-1 text-sm text-stone-700">
                        Accepted {{ tally.accepted_ballots }} · Rejected
                        {{ tally.rejected_ballots }}
                    </p>
                </div>
                <Form v-bind="complete.form()">
                    <button class="secondary-button" type="submit">
                        Complete Counting
                    </button>
                </Form>
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div
                    v-for="(totals, contest) in tally.tally"
                    :key="contest"
                    class="border border-stone-200 p-3"
                >
                    <h3 class="font-semibold">{{ contest }}</h3>
                    <dl class="mt-2 text-sm">
                        <div
                            v-for="(votes, candidate) in totals"
                            :key="candidate"
                            class="flex justify-between gap-3"
                        >
                            <dt>{{ candidate }}</dt>
                            <dd class="font-semibold">{{ votes }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
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
