<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { Candidate, Contest } from '@/components/election/types';
import { finalize } from '@/routes/election/voter';

defineProps<{
    ballot: {
        election_id: string;
        precinct_id: string;
        ballot_style_id: string;
        contests: Contest[];
    };
}>();

const filters = ref<Record<string, string>>({});

function filteredCandidates(contest: Contest): Candidate[] {
    const term = (filters.value[contest.id] ?? '').trim().toLowerCase();

    if (term === '') {
        return contest.candidates;
    }

    return contest.candidates.filter((candidate) =>
        [candidate.name, candidate.full_name, candidate.political_party]
            .filter(Boolean)
            .join(' ')
            .toLowerCase()
            .includes(term),
    );
}
</script>

<template>
    <main class="min-h-screen bg-stone-100 text-stone-950">
        <header class="border-b-4 border-blue-800 bg-white">
            <div class="mx-auto max-w-5xl px-4 py-5 sm:px-6">
                <p class="text-sm font-bold text-blue-800">
                    Official Voter Ballot
                </p>
                <div class="mt-1 flex flex-wrap items-baseline justify-between gap-3">
                    <h1 class="text-2xl font-bold">Select your candidates</h1>
                    <p class="text-sm font-semibold">
                        Precinct {{ ballot.precinct_id }}
                    </p>
                </div>
            </div>
        </header>

        <Form
            v-bind="finalize.form()"
            #default="{ processing, errors }"
            class="mx-auto max-w-5xl space-y-5 px-4 py-6 sm:px-6"
        >
            <fieldset
                v-for="contest in ballot.contests"
                :key="contest.id"
                class="border border-stone-300 bg-white p-4 sm:p-5"
            >
                <legend class="px-2 text-lg font-bold">
                    {{ contest.title }}
                </legend>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-stone-600">
                        Select up to {{ contest.max_selections }}
                    </p>
                    <input
                        v-model="filters[contest.id]"
                        class="min-h-11 w-full border border-stone-300 px-3 text-sm sm:w-72"
                        :placeholder="`Search ${contest.title}`"
                        type="search"
                    />
                </div>
                <div class="mt-4 grid gap-2 sm:grid-cols-2">
                    <label
                        v-for="candidate in filteredCandidates(contest)"
                        :key="candidate.id"
                        class="grid min-h-16 cursor-pointer grid-cols-[24px_1fr] items-center gap-3 border border-stone-300 p-3 has-checked:border-blue-800 has-checked:bg-blue-50"
                    >
                        <input
                            :type="contest.max_selections === 1 ? 'radio' : 'checkbox'"
                            :name="`selections[${contest.id}][]`"
                            :value="candidate.id"
                            class="h-5 w-5 accent-blue-800"
                        />
                        <span>
                            <strong class="block">
                                {{ candidate.ballot_number ?? candidate.ordinal }}.
                                {{ candidate.name }}
                            </strong>
                            <span class="text-xs text-stone-600">
                                {{ candidate.political_party || 'No party listed' }}
                            </span>
                        </span>
                    </label>
                </div>
            </fieldset>

            <p v-if="errors.lifecycle" class="font-bold text-red-700">
                {{ errors.lifecycle }}
            </p>
            <div class="sticky bottom-0 border-t-4 border-blue-800 bg-white p-4">
                <button
                    class="min-h-12 w-full bg-blue-800 px-6 py-3 text-base font-bold text-white disabled:opacity-50"
                    type="submit"
                    :disabled="processing"
                >
                    {{ processing ? 'Finalizing ballot...' : 'Finalize my ballot' }}
                </button>
            </div>
        </Form>
    </main>
</template>
