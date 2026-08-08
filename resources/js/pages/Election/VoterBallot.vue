<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import PaperFacsimileBallot from '@/components/election/PaperFacsimileBallot.vue';
import ReviewStationBar from '@/components/election/ReviewStationBar.vue';
import TouchGuidedBallot from '@/components/election/TouchGuidedBallot.vue';
import {
    candidateAnchor,
    candidateName,
    contestAnchor,
    contestShortLabel,
} from '@/components/election/ballotNavigation';
import type {
    Candidate,
    Contest,
    ElectionReviewRoomContext,
} from '@/components/election/types';
import { finalize } from '@/routes/election/voter';

const props = defineProps<{
    ballot: {
        election_id: string;
        precinct_id: string;
        ballot_style_id: string;
        contests: Contest[];
    };
    finalizeAction?: string;
    publicSimulation?: boolean;
    ballotUiProfile?: string;
}>();

const step = ref<'ballot' | 'review'>('ballot');
const selections = ref<Record<string, string[]>>({});
const activeLetters = ref<Record<string, string>>({});
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
const contestNavigation = computed(() =>
    props.ballot.contests.map((contest) => ({
        id: contest.id,
        title: contest.title,
        label: contestShortLabel(contest),
        selected: selections.value[contest.id]?.length ?? 0,
        max: contest.max_selections,
    })),
);
const reviewSummary = computed(() =>
    contestNavigation.value
        .map((contest) => `${contest.label} (${contest.selected})`)
        .join(', '),
);
const resolvedBallotUiProfile = computed(() =>
    props.ballotUiProfile === 'paper_facsimile'
        ? 'paper_facsimile'
        : 'touch_guided',
);
const ballotKicker = computed(() =>
    resolvedBallotUiProfile.value === 'paper_facsimile'
        ? 'Official ballot'
        : 'Touch guided ballot',
);

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

function clearDraft(): void {
    sessionStorage.removeItem('aes-voter-draft');
}

function scrollToElement(elementId: string): void {
    document.getElementById(elementId)?.scrollIntoView({
        behavior: 'smooth',
        block: 'start',
    });
}

function jumpToCandidateLetter(
    contest: Contest,
    letter: { letter: string; candidateId: string },
): void {
    activeLetters.value[contest.id] = letter.letter;
    scrollToElement(candidateAnchor(contest.id, letter.candidateId));
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
                            {{ ballotKicker }}
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
            v-bind="
                finalizeAction
                    ? { action: finalizeAction, method: 'post' }
                    : finalize.form()
            "
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
                <TouchGuidedBallot
                    v-if="resolvedBallotUiProfile === 'touch_guided'"
                    :active-letters="activeLetters"
                    :contest-navigation="contestNavigation"
                    :contests="ballot.contests"
                    :review-emphasized="
                        reviewRoom.enabled && selectionCount > 0
                    "
                    :review-summary="reviewSummary"
                    :selections="selections"
                    @jump-to-contest="scrollToElement(contestAnchor($event))"
                    @jump-to-letter="jumpToCandidateLetter"
                    @review="step = 'review'"
                    @toggle="toggle"
                />
                <PaperFacsimileBallot
                    v-else
                    :active-letters="activeLetters"
                    :contest-navigation="contestNavigation"
                    :contests="ballot.contests"
                    :review-emphasized="
                        reviewRoom.enabled && selectionCount > 0
                    "
                    :review-summary="reviewSummary"
                    :selections="selections"
                    @jump-to-contest="scrollToElement(contestAnchor($event))"
                    @jump-to-letter="jumpToCandidateLetter"
                    @review="step = 'review'"
                    @toggle="toggle"
                />
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
                                : 'Finalize and get print PIN'
                        }}
                    </button>
                </div>
            </template>
        </Form>
    </main>
</template>
