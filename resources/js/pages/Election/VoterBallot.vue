<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import ReviewStationBar from '@/components/election/ReviewStationBar.vue';
import type {
    Candidate,
    Contest,
    ElectionReviewRoomContext,
} from '@/components/election/types';
import { finalize } from '@/routes/election/voter';

defineProps<{
    ballot: {
        election_id: string;
        precinct_id: string;
        ballot_style_id: string;
        contests: Contest[];
    };
    finalizeAction?: string;
    publicSimulation?: boolean;
}>();

const step = ref<'ballot' | 'review'>('ballot');
const selections = ref<Record<string, string[]>>({});
const page = usePage();
const reviewRoom = computed(
    () => page.props.electionReviewRoom as ElectionReviewRoomContext,
);

onMounted(() => {
    const saved = sessionStorage.getItem('aes-voter-draft');
    selections.value = saved ? JSON.parse(saved) : {};
});

watch(
    selections,
    (value) => sessionStorage.setItem('aes-voter-draft', JSON.stringify(value)),
    { deep: true },
);

const selectionCount = computed(() =>
    Object.values(selections.value).reduce(
        (total, selected) => total + selected.length,
        0,
    ),
);

function selected(contestId: string, candidateId: string): boolean {
    return (selections.value[contestId] ?? []).includes(candidateId);
}

function toggle(contest: Contest, candidate: Candidate): void {
    const current = selections.value[contest.id] ?? [];

    if (current.includes(candidate.id)) {
        selections.value[contest.id] = current.filter(
            (id) => id !== candidate.id,
        );

        return;
    }

    if (current.length < contest.max_selections) {
        selections.value[contest.id] = [...current, candidate.id];
    }
}

function isAtLimit(contest: Contest): boolean {
    return (
        (selections.value[contest.id] ?? []).length >= contest.max_selections
    );
}

function candidateName(contest: Contest, candidateId: string): string {
    return (
        contest.candidates.find((candidate) => candidate.id === candidateId)
            ?.name ?? candidateId
    );
}

function clearDraft(): void {
    sessionStorage.removeItem('aes-voter-draft');
}
</script>

<template>
    <main class="min-h-screen bg-stone-100 text-stone-950">
        <ReviewStationBar />
        <header class="border-b-4 border-blue-800 bg-white">
            <div class="mx-auto max-w-5xl px-4 py-4 sm:px-6">
                <div
                    class="flex flex-wrap items-baseline justify-between gap-3"
                >
                    <div>
                        <p class="text-sm font-bold text-blue-800">
                            Official ballot
                        </p>
                        <h1 class="mt-1 text-2xl font-bold">
                            {{
                                step === 'ballot'
                                    ? 'Select your candidates'
                                    : 'Review your ballot'
                            }}
                        </h1>
                    </div>
                    <p class="text-sm font-semibold">
                        Precinct {{ ballot.precinct_id }}
                    </p>
                </div>
            </div>
        </header>

        <Form
            v-bind="finalizeAction ? { action: finalizeAction, method: 'post' } : finalize.form()"
            #default="{ processing, errors }"
            class="mx-auto max-w-5xl space-y-5 px-4 py-6 sm:px-6"
            @success="clearDraft"
        >
            <template
                v-for="(candidateIds, contestId) in selections"
                :key="contestId"
            >
                <input
                    v-for="candidateId in candidateIds"
                    :key="candidateId"
                    :name="`selections[${contestId}][]`"
                    :value="candidateId"
                    type="hidden"
                />
            </template>

            <template v-if="step === 'ballot'">
                <fieldset
                    v-for="contest in ballot.contests"
                    :key="contest.id"
                    class="border border-stone-300 bg-white p-4 sm:p-5"
                >
                    <legend class="px-2 text-lg font-bold">
                        {{ contest.title }}
                    </legend>
                    <div class="flex items-center justify-between gap-4">
                        <p class="text-sm text-stone-600">
                            Select up to {{ contest.max_selections }}
                        </p>
                        <p class="text-sm font-bold text-blue-800">
                            {{ (selections[contest.id] ?? []).length }} /
                            {{ contest.max_selections }}
                        </p>
                    </div>
                    <div class="mt-4 grid gap-2 sm:grid-cols-2">
                        <button
                            v-for="candidate in contest.candidates"
                            :key="candidate.id"
                            class="grid min-h-20 grid-cols-[32px_1fr] items-center gap-3 border p-3 text-left disabled:cursor-not-allowed disabled:opacity-45"
                            :class="
                                selected(contest.id, candidate.id)
                                    ? 'border-blue-800 bg-blue-50'
                                    : 'border-stone-300 bg-white'
                            "
                            :disabled="
                                isAtLimit(contest) &&
                                !selected(contest.id, candidate.id)
                            "
                            type="button"
                            @click="toggle(contest, candidate)"
                        >
                            <span
                                class="flex h-7 w-7 items-center justify-center border-2 text-sm font-bold"
                                :class="
                                    selected(contest.id, candidate.id)
                                        ? 'border-blue-800 bg-blue-800 text-white'
                                        : 'border-stone-400'
                                "
                            >
                                {{
                                    selected(contest.id, candidate.id)
                                        ? 'X'
                                        : (candidate.ballot_number ??
                                          candidate.ordinal)
                                }}
                            </span>
                            <span>
                                <strong class="block">{{
                                    candidate.name
                                }}</strong>
                                <span class="text-xs text-stone-600">{{
                                    candidate.political_party ||
                                    'Independent / no party listed'
                                }}</span>
                            </span>
                        </button>
                    </div>
                </fieldset>

                <div
                    class="sticky bottom-0 border-t-4 border-blue-800 bg-white p-4"
                >
                    <button
                        class="min-h-12 w-full bg-blue-800 px-6 py-3 text-base font-bold text-white"
                        :class="{
                            'review-next-action-button':
                                reviewRoom.enabled && selectionCount > 0,
                        }"
                        type="button"
                        @click="step = 'review'"
                    >
                        Review {{ selectionCount }} selection{{
                            selectionCount === 1 ? '' : 's'
                        }}
                    </button>
                </div>
            </template>

            <template v-else>
                <section class="border border-stone-300 bg-white p-5">
                    <p class="text-sm text-stone-700">
                        Check every selection. You may return to the ballot and
                        change any choice before finalizing.
                    </p>
                    <div class="mt-5 divide-y divide-stone-200">
                        <div
                            v-for="contest in ballot.contests"
                            :key="contest.id"
                            class="py-4"
                        >
                            <div class="flex justify-between gap-4">
                                <h2 class="font-bold">{{ contest.title }}</h2>
                                <span class="text-sm text-stone-600">
                                    {{ (selections[contest.id] ?? []).length }}
                                    selected
                                </span>
                            </div>
                            <ul
                                v-if="(selections[contest.id] ?? []).length"
                                class="mt-2 space-y-1"
                            >
                                <li
                                    v-for="candidateId in selections[
                                        contest.id
                                    ]"
                                    :key="candidateId"
                                >
                                    {{ candidateName(contest, candidateId) }}
                                </li>
                            </ul>
                            <p v-else class="mt-2 font-semibold text-amber-800">
                                No selection (undervote)
                            </p>
                        </div>
                    </div>
                </section>

                <p
                    v-if="errors.selections || errors.lifecycle"
                    class="font-bold text-red-700"
                >
                    {{ errors.selections || errors.lifecycle }}
                </p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <button
                        class="min-h-12 border-2 border-stone-500 bg-white px-5 py-3 font-bold"
                        type="button"
                        @click="step = 'ballot'"
                    >
                        Change selections
                    </button>
                    <button
                        class="min-h-12 bg-emerald-700 px-5 py-3 font-bold text-white disabled:opacity-50"
                        :class="{
                            'review-next-action-button': reviewRoom.enabled,
                        }"
                        type="submit"
                        :disabled="processing"
                    >
                        {{
                            processing
                                ? 'Finalizing privately...'
                                : 'Finalize and get print code'
                        }}
                    </button>
                </div>
            </template>
        </Form>
    </main>
</template>
