<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import type { Candidate, ElectionSnapshot } from '@/components/election/types';
import { closePolls, finalize, openPolls } from '@/routes/election/voting';

defineProps<{ snapshot: ElectionSnapshot }>();

const filters = ref<Record<string, string>>({});

const filteredCandidates = (
    contestId: string,
    candidates: Candidate[],
): Candidate[] => {
    const term = (filters.value[contestId] ?? '').trim().toLowerCase();

    if (term === '') {
        return candidates;
    }

    return candidates.filter((candidate) =>
        [
            candidate.name,
            candidate.full_name ?? '',
            candidate.political_party ?? '',
            String(candidate.ballot_number ?? candidate.ordinal),
        ]
            .join(' ')
            .toLowerCase()
            .includes(term),
    );
};

const canOpenPolls = (stage: string): boolean => stage === 'open_precinct';

const canFinalize = (stage: string): boolean => stage === 'voting';

const canClosePolls = (stage: string): boolean => stage === 'voting';
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Voting">
        <section class="border border-stone-300 bg-white p-5">
            <div class="flex flex-wrap gap-3">
                <Form
                    v-if="canOpenPolls(snapshot.stage)"
                    v-bind="openPolls.form()"
                    #default="{ errors }"
                    class="w-full"
                >
                    <div class="flex flex-wrap gap-2">
                        <label class="w-full sm:w-auto">
                            <span class="text-xs font-semibold"
                                >Officer ID</span
                            >
                            <input
                                class="mt-1 block w-full border border-stone-300 px-2 py-2 text-sm"
                                name="officer_code"
                                required
                                type="text"
                                autocomplete="off"
                            />
                        </label>
                        <label class="w-full sm:w-auto">
                            <span class="text-xs font-semibold"
                                >Officer PIN</span
                            >
                            <input
                                class="mt-1 block w-full border border-stone-300 px-2 py-2 text-sm"
                                name="officer_pin"
                                required
                                type="password"
                                inputmode="numeric"
                                autocomplete="off"
                            />
                        </label>
                        <button class="secondary-button h-fit" type="submit">
                            Open Polls
                        </button>
                    </div>
                    <p
                        v-if="errors.officer_pin"
                        class="mt-2 text-sm font-semibold text-rose-700"
                    >
                        {{ errors.officer_pin }}
                    </p>
                    <p
                        v-if="errors.officer_code"
                        class="mt-2 text-sm font-semibold text-rose-700"
                    >
                        {{ errors.officer_code }}
                    </p>
                    <p
                        v-if="errors.lifecycle"
                        class="mt-2 text-sm font-semibold text-rose-700"
                    >
                        {{ errors.lifecycle }}
                    </p>
                </Form>
                <Form
                    v-if="canClosePolls(snapshot.stage)"
                    v-bind="closePolls.form()"
                    #default="{ errors }"
                    class="w-full"
                >
                    <button class="secondary-button" type="submit">
                        Close Polls
                    </button>

                    <p
                        v-if="errors.lifecycle"
                        class="mt-2 text-sm font-semibold text-rose-700"
                    >
                        {{ errors.lifecycle }}
                    </p>
                </Form>
            </div>
        </section>

        <Form
            v-if="canFinalize(snapshot.stage)"
            v-bind="finalize.form()"
            #default="{ errors }"
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
                <div class="mt-3">
                    <input
                        v-model="filters[contest.id]"
                        class="w-full border border-stone-300 px-3 py-2 text-sm"
                        :name="`filter-${contest.id}`"
                        placeholder="Search candidates"
                        type="search"
                    />
                </div>
                <div
                    class="mt-3 grid max-h-96 gap-2 overflow-y-auto pr-1 sm:grid-cols-2"
                >
                    <label
                        v-for="candidate in filteredCandidates(
                            contest.id,
                            contest.candidates,
                        )"
                        :key="candidate.id"
                        class="flex items-center gap-3 border border-stone-300 p-3"
                    >
                        <input
                            :type="
                                contest.max_selections === 1
                                    ? 'radio'
                                    : 'checkbox'
                            "
                            :name="`selections[${contest.id}][]`"
                            :value="candidate.id"
                        />
                        <span class="min-w-0">
                            <span class="block font-medium"
                                >{{
                                    candidate.ballot_number ??
                                    candidate.ordinal
                                }}. {{ candidate.name }}</span
                            >
                            <span
                                v-if="candidate.political_party"
                                class="block text-xs text-stone-600"
                                >{{ candidate.political_party }}</span
                            >
                        </span>
                    </label>
                </div>
            </div>
            <button class="primary-button" type="submit">Finalize Vote</button>
            <p
                v-if="errors.lifecycle"
                class="mt-2 text-sm font-semibold text-rose-700"
            >
                {{ errors.lifecycle }}
            </p>
        </Form>

        <p
            v-else
            class="mt-3 rounded border border-stone-300 bg-stone-50 p-4 text-sm text-stone-700"
        >
            Voting ballot actions are only available while voting is active.
        </p>
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
