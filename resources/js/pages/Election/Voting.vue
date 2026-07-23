<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import CeremonyActionPanel from '@/components/election/CeremonyActionPanel.vue';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import StatusBadge from '@/components/election/StatusBadge.vue';
import type { Candidate, ElectionSnapshot } from '@/components/election/types';
import { counting } from '@/routes/election';
import {
    closePolls,
    finalize,
    openPolls,
    specialPollingIntake as specialPollingIntakeRoute,
} from '@/routes/election/voting';

type SpecialPollingIntake = {
    exists?: boolean;
    entry_count?: number;
    total_ballots?: number;
    latest_entry_hash?: string;
    artifact?: string;
    totals_by_type?: Record<string, number>;
};

defineProps<{
    snapshot: ElectionSnapshot;
    specialPollingIntake: SpecialPollingIntake;
}>();

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
const canRecordSpecialPolling = (stage: string): boolean =>
    stage === 'voting' || stage === 'close_polls';

const specialPollingTypes = [
    { value: 'ppp', label: 'PPP / S-PPP' },
    { value: 'pdl', label: 'PDL / PPD' },
    { value: 'ip', label: 'Indigenous Peoples' },
];

function candidateInitials(candidate: Candidate): string {
    return candidate.name
        .split(/\s+/)
        .slice(0, 2)
        .map((name) => name.charAt(0))
        .join('')
        .toUpperCase();
}
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Voting">
        <CeremonyActionPanel
            v-if="canOpenPolls(snapshot.stage)"
            title="Open the polls"
            description="The Election Board Chairperson must confirm the precinct initialization report before voting begins."
            eyebrow="Opening ceremony"
            status="Officer confirmation required"
            tone="warning"
        >
            <Form
                v-bind="openPolls.form()"
                #default="{ errors, processing }"
                class="grid gap-4 sm:grid-cols-2"
            >
                <label class="block">
                    <span class="text-sm font-bold text-stone-700">
                        Chairperson officer ID
                    </span>
                    <input
                        class="mt-1 block min-h-11 w-full border border-stone-300 bg-white px-3 py-2 text-sm"
                        name="officer_code"
                        required
                        type="text"
                        autocomplete="off"
                    />
                    <span
                        v-if="errors.officer_code"
                        class="mt-1 block text-sm font-semibold text-red-700"
                    >
                        {{ errors.officer_code }}
                    </span>
                </label>
                <label class="block">
                    <span class="text-sm font-bold text-stone-700">
                        Officer PIN
                    </span>
                    <input
                        class="mt-1 block min-h-11 w-full border border-stone-300 bg-white px-3 py-2 text-sm"
                        name="officer_pin"
                        required
                        type="password"
                        inputmode="numeric"
                        autocomplete="off"
                    />
                    <span
                        v-if="errors.officer_pin"
                        class="mt-1 block text-sm font-semibold text-red-700"
                    >
                        {{ errors.officer_pin }}
                    </span>
                </label>
                <div
                    class="flex flex-col gap-2 sm:col-span-2 sm:flex-row sm:items-center sm:justify-between"
                >
                    <p class="text-sm text-stone-600">
                        This action records the opening officer and advances the
                        appliance to active voting.
                    </p>
                    <button
                        class="primary-button shrink-0"
                        type="submit"
                        :disabled="processing"
                    >
                        {{ processing ? 'Opening polls...' : 'Open polls' }}
                    </button>
                </div>
                <p
                    v-if="errors.lifecycle"
                    class="text-sm font-bold text-red-700 sm:col-span-2"
                >
                    {{ errors.lifecycle }}
                </p>
            </Form>
        </CeremonyActionPanel>

        <CeremonyActionPanel
            v-if="canFinalize(snapshot.stage)"
            title="Prepare the voter ballot"
            description="Select the voter choices by contest. The voter reviews the printed paper ballot before casting it."
            eyebrow="Voting session"
            status="Polls open"
            tone="complete"
        >
            <Form
                v-bind="finalize.form()"
                #default="{ errors, processing }"
                class="space-y-7"
            >
                <fieldset
                    v-for="contest in snapshot.configuration.contests ?? []"
                    :key="contest.id"
                    class="border-t border-stone-300 pt-5 first:border-t-0 first:pt-0"
                >
                    <legend class="w-full">
                        <span
                            class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between sm:gap-4"
                        >
                            <span>
                                <span
                                    class="block text-lg font-bold text-stone-950"
                                >
                                    {{ contest.title }}
                                </span>
                                <span
                                    v-if="contest.geography"
                                    class="mt-0.5 block text-xs text-stone-500"
                                >
                                    {{ contest.geography }}
                                </span>
                            </span>
                            <StatusBadge
                                :label="`Select up to ${contest.max_selections}`"
                                tone="neutral"
                            />
                        </span>
                    </legend>

                    <label class="mt-4 block">
                        <span class="sr-only">
                            Search {{ contest.title }} candidates
                        </span>
                        <input
                            v-model="filters[contest.id]"
                            class="min-h-11 w-full border border-stone-300 bg-stone-50 px-3 py-2 text-sm"
                            :name="`filter-${contest.id}`"
                            :placeholder="`Search ${contest.title} candidates`"
                            type="search"
                        />
                    </label>

                    <div
                        class="mt-3 grid max-h-[32rem] gap-2 overflow-y-auto pr-1 md:grid-cols-2"
                    >
                        <label
                            v-for="candidate in filteredCandidates(
                                contest.id,
                                contest.candidates,
                            )"
                            :key="candidate.id"
                            class="group grid min-h-20 cursor-pointer grid-cols-[24px_48px_1fr] items-center gap-3 border border-stone-300 bg-white p-3 hover:border-blue-700 has-checked:border-blue-700 has-checked:bg-blue-50"
                        >
                            <input
                                :type="
                                    contest.max_selections === 1
                                        ? 'radio'
                                        : 'checkbox'
                                "
                                :name="`selections[${contest.id}][]`"
                                :value="candidate.id"
                                class="h-5 w-5 accent-blue-800"
                            />
                            <span
                                class="flex h-12 w-12 items-center justify-center overflow-hidden border border-stone-300 bg-stone-100 text-xs font-bold text-stone-600"
                            >
                                <img
                                    v-if="
                                        candidate.candidate_image?.status ===
                                            'available' &&
                                        candidate.candidate_image.uri
                                    "
                                    :src="candidate.candidate_image.uri"
                                    :alt="candidate.candidate_image.alt_text"
                                    class="h-full w-full object-cover"
                                />
                                <span v-else aria-hidden="true">
                                    {{ candidateInitials(candidate) }}
                                </span>
                            </span>
                            <span class="min-w-0">
                                <span class="block font-bold text-stone-950">
                                    {{
                                        candidate.ballot_number ??
                                        candidate.ordinal
                                    }}.
                                    {{ candidate.name }}
                                </span>
                                <span
                                    class="mt-0.5 block text-xs text-stone-600"
                                >
                                    {{
                                        candidate.political_party ||
                                        'Independent / no party listed'
                                    }}
                                </span>
                            </span>
                        </label>
                    </div>
                    <p
                        v-if="
                            filteredCandidates(contest.id, contest.candidates)
                                .length === 0
                        "
                        class="mt-3 border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950"
                    >
                        No candidates match this search.
                    </p>
                </fieldset>

                <div
                    class="sticky bottom-3 flex flex-col gap-3 border border-stone-300 bg-white p-4 shadow-lg sm:flex-row sm:items-center sm:justify-between"
                >
                    <p class="text-sm text-stone-600">
                        Finalizing creates the QR payload and opens the paper
                        ballot printing station.
                    </p>
                    <button
                        class="primary-button shrink-0"
                        type="submit"
                        :disabled="processing"
                    >
                        {{
                            processing
                                ? 'Preparing ballot...'
                                : 'Review and prepare ballot'
                        }}
                    </button>
                </div>
                <p
                    v-if="errors.lifecycle"
                    class="text-sm font-bold text-red-700"
                >
                    {{ errors.lifecycle }}
                </p>
            </Form>
        </CeremonyActionPanel>

        <div
            v-if="canRecordSpecialPolling(snapshot.stage)"
            class="grid gap-4 lg:grid-cols-2"
        >
            <CeremonyActionPanel
                title="Special polling intake"
                description="Record ballots formally received from an authorized special polling process."
                status="As needed"
                tone="neutral"
            >
                <Form
                    v-bind="specialPollingIntakeRoute.form()"
                    #default="{ errors, processing }"
                    class="space-y-3"
                >
                    <input name="stage" :value="snapshot.stage" type="hidden" />
                    <label class="block">
                        <span class="text-sm font-bold text-stone-700">
                            Polling type
                        </span>
                        <select
                            class="mt-1 block min-h-11 w-full border border-stone-300 px-3 py-2 text-sm"
                            name="intake_type"
                            required
                        >
                            <option value="" disabled selected>
                                Choose polling type
                            </option>
                            <option
                                v-for="type in specialPollingTypes"
                                :key="type.value"
                                :value="type.value"
                            >
                                {{ type.label }}
                            </option>
                        </select>
                        <span
                            v-if="errors.intake_type"
                            class="mt-1 block text-sm font-bold text-red-700"
                        >
                            {{ errors.intake_type }}
                        </span>
                    </label>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-bold text-stone-700">
                                Ballot count
                            </span>
                            <input
                                class="mt-1 block min-h-11 w-full border border-stone-300 px-3 py-2 text-sm"
                                name="ballot_count"
                                required
                                type="number"
                                min="1"
                                max="2000"
                            />
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-stone-700">
                                Received from
                            </span>
                            <input
                                class="mt-1 block min-h-11 w-full border border-stone-300 px-3 py-2 text-sm"
                                name="received_from"
                                required
                                type="text"
                                autocomplete="off"
                            />
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-stone-700">
                                Received by
                            </span>
                            <input
                                class="mt-1 block min-h-11 w-full border border-stone-300 px-3 py-2 text-sm"
                                name="received_by"
                                type="text"
                                autocomplete="off"
                            />
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-stone-700">
                                Notes
                            </span>
                            <input
                                class="mt-1 block min-h-11 w-full border border-stone-300 px-3 py-2 text-sm"
                                name="notes"
                                type="text"
                                autocomplete="off"
                            />
                        </label>
                    </div>
                    <p
                        v-if="
                            errors.ballot_count ||
                            errors.received_from ||
                            errors.stage
                        "
                        class="text-sm font-bold text-red-700"
                    >
                        {{
                            errors.ballot_count ||
                            errors.received_from ||
                            errors.stage
                        }}
                    </p>
                    <button
                        class="secondary-button"
                        type="submit"
                        :disabled="processing"
                    >
                        Record special polling intake
                    </button>
                </Form>
            </CeremonyActionPanel>

            <CeremonyActionPanel
                title="Special polling summary"
                description="Running total of separately received ballots for this precinct."
                :status="specialPollingIntake.exists ? 'Recorded' : 'No intake'"
                :tone="specialPollingIntake.exists ? 'complete' : 'neutral'"
            >
                <dl
                    v-if="specialPollingIntake.exists"
                    class="grid gap-4 text-sm sm:grid-cols-2"
                >
                    <div>
                        <dt class="text-stone-500">Intake entries</dt>
                        <dd class="mt-1 text-2xl font-bold">
                            {{ specialPollingIntake.entry_count }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Special ballots</dt>
                        <dd class="mt-1 text-2xl font-bold">
                            {{ specialPollingIntake.total_ballots }}
                        </dd>
                    </div>
                    <div
                        v-for="(
                            count, type
                        ) in specialPollingIntake.totals_by_type ?? {}"
                        :key="type"
                    >
                        <dt class="text-stone-500">
                            {{ type.toUpperCase() }}
                        </dt>
                        <dd class="mt-1 font-bold">{{ count }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-stone-500">Evidence artifact</dt>
                        <dd class="mt-1 font-mono text-xs break-all">
                            {{ specialPollingIntake.artifact }}
                        </dd>
                    </div>
                </dl>
                <p v-else class="text-sm text-stone-600">
                    No special polling ballots have been received.
                </p>
            </CeremonyActionPanel>
        </div>

        <CeremonyActionPanel
            v-if="canClosePolls(snapshot.stage)"
            title="Close the polls"
            description="Use only after the legally prescribed closing time and after all voters already in line have voted."
            eyebrow="Closing ceremony"
            status="Irreversible stage change"
            tone="danger"
        >
            <Form
                v-bind="closePolls.form()"
                #default="{ errors, processing }"
                class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="max-w-2xl text-sm text-stone-700">
                    Closing polls ends ballot preparation and advances directly
                    to counting. Confirm the physical ballot box and precinct
                    records are ready.
                </p>
                <div class="shrink-0">
                    <button
                        class="danger-button"
                        type="submit"
                        :disabled="processing"
                    >
                        {{ processing ? 'Closing polls...' : 'Close polls' }}
                    </button>
                    <p
                        v-if="errors.lifecycle"
                        class="mt-2 max-w-xs text-sm font-bold text-red-700"
                    >
                        {{ errors.lifecycle }}
                    </p>
                </div>
            </Form>
        </CeremonyActionPanel>

        <div
            v-if="snapshot.stage === 'close_polls'"
            class="border border-blue-300 bg-blue-50 p-5"
        >
            <p class="font-bold text-blue-950">Polls are closed.</p>
            <p class="mt-1 text-sm text-blue-900">
                Continue to the Counting and Tally ceremony with the sealed
                physical ballot box.
            </p>
            <Link
                :href="counting.url()"
                class="mt-4 inline-flex min-h-11 items-center justify-center bg-blue-800 px-5 py-3 text-sm font-bold text-white"
            >
                Continue to Counting and Tally
            </Link>
        </div>

        <div
            v-if="
                !canOpenPolls(snapshot.stage) &&
                !canFinalize(snapshot.stage) &&
                snapshot.stage !== 'close_polls'
            "
            class="border border-stone-300 bg-white p-5 text-sm text-stone-700"
        >
            Voting actions are unavailable at the current lifecycle stage.
            Follow the highlighted ceremony in the precinct run sequence.
        </div>
    </CeremonyLayout>
</template>

<style scoped>
.primary-button,
.secondary-button,
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

.secondary-button {
    border: 1px solid rgb(87 83 78);
    background: white;
    color: rgb(28 25 23);
}

.danger-button {
    background: rgb(185 28 28);
    color: white;
}

.primary-button:disabled,
.secondary-button:disabled,
.danger-button:disabled {
    cursor: not-allowed;
    opacity: 0.5;
}
</style>
