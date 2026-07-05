<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import { closePolls, finalize, openPolls } from '@/routes/election/voting';

defineProps<{ snapshot: ElectionSnapshot }>();
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Voting">
        <section class="border border-stone-300 bg-white p-5">
            <div class="flex flex-wrap gap-3">
                <Form v-bind="openPolls.form()">
                    <button class="secondary-button" type="submit">
                        Open Polls
                    </button>
                </Form>
                <Form v-bind="closePolls.form()">
                    <button class="secondary-button" type="submit">
                        Close Polls
                    </button>
                </Form>
            </div>
        </section>

        <Form
            v-bind="finalize.form()"
            class="space-y-4 border border-stone-300 bg-white p-5"
        >
            <h2 class="text-lg font-semibold">Simulated Voter Ballot</h2>
            <div
                v-for="contest in snapshot.configuration.contests ?? []"
                :key="contest.id"
                class="border-t border-stone-200 pt-4"
            >
                <div class="flex items-baseline justify-between gap-3">
                    <h3 class="font-semibold">{{ contest.title }}</h3>
                    <p class="text-sm text-stone-600">
                        Select up to {{ contest.max_selections }}
                    </p>
                </div>
                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                    <label
                        v-for="candidate in contest.candidates"
                        :key="candidate.id"
                        class="flex items-center gap-3 border border-stone-300 p-3"
                    >
                        <input
                            :type="
                                contest.max_selections === 1
                                    ? 'radio'
                                    : 'checkbox'
                            "
                            :name="`${contest.id}[]`"
                            :value="candidate.id"
                        />
                        <span
                            >{{ candidate.ordinal }}. {{ candidate.name }}</span
                        >
                    </label>
                </div>
            </div>
            <button class="primary-button" type="submit">Finalize Vote</button>
        </Form>
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
