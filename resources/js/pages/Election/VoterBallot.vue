<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import {
    candidateAnchor,
    candidateName,
    contestAnchor,
    contestShortLabel,
} from '@/components/election/ballotNavigation';
import PaperFacsimileBallot from '@/components/election/PaperFacsimileBallot.vue';
import ReviewStationBar from '@/components/election/ReviewStationBar.vue';
import TouchGuidedBallot from '@/components/election/TouchGuidedBallot.vue';
import type {
    Candidate,
    Contest,
    ElectionReviewRoomContext,
} from '@/components/election/types';
import { finalize } from '@/routes/election/voter';

type SelectionTarget = 'circle' | 'circle_with_label' | 'row';

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
    ballotMaxColumns?: number;
    selectionTarget?: string;
    demoRandomFillEnabled?: boolean;
    analytics?: {
        enabled: boolean;
        display_mode: 'hidden' | 'review' | 'presentation';
    };
}>();

const step = ref<'ballot' | 'review'>('ballot');
const selections = ref<Record<string, string[]>>({});
const activeLetters = ref<Record<string, string>>({});
const nowMs = ref(Date.now());
const analyticsSessionId = ref('');
const analyticsStartedAtMs = ref(Date.now());
const firstSelectionAtMs = ref<number | null>(null);
const lastSelectionAtMs = ref<number | null>(null);
const reviewOpenedAtMs = ref<number | null>(null);
const finalizedAtMs = ref<number | null>(null);
const selectionEditCount = ref(0);
const randomFillClickCount = ref(0);
const randomFillCompletedContests = ref(0);
const contestNavigationClicks = ref(0);
const surnameNavigationClicks = ref(0);
const reviewCount = ref(0);
const overvoteAttemptsBlocked = ref(0);
let timerInterval: number | undefined;
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
const maximumSelectionCount = computed(() =>
    props.ballot.contests.reduce(
        (total, contest) => total + contest.max_selections,
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
    props.ballotUiProfile === 'touch_guided'
        ? 'touch_guided'
        : props.ballotUiProfile === 'paper_facsimile'
          ? 'paper_facsimile'
          : 'comelec_2022_facsimile',
);
const resolvedSelectionTarget = computed<SelectionTarget>(() =>
    props.selectionTarget === 'circle_with_label' ||
    props.selectionTarget === 'row'
        ? props.selectionTarget
        : 'circle',
);
const ballotKicker = computed(() =>
    resolvedBallotUiProfile.value !== 'touch_guided'
        ? 'Official ballot'
        : 'Touch guided ballot',
);
const analyticsEnabled = computed(() => props.analytics?.enabled === true);
const analyticsVisible = computed(
    () =>
        analyticsEnabled.value &&
        ['review', 'presentation'].includes(
            props.analytics?.display_mode ?? 'hidden',
        ),
);
const ballotCandidateCount = computed(() =>
    props.ballot.contests.reduce(
        (total, contest) => total + contest.candidates.length,
        0,
    ),
);
const hasBallotChoices = computed(
    () => props.ballot.contests.length > 0 && ballotCandidateCount.value > 0,
);
const elapsedSeconds = computed(() =>
    Math.max(0, Math.floor((nowMs.value - analyticsStartedAtMs.value) / 1000)),
);
const timeToFirstSelectionSeconds = computed(() =>
    firstSelectionAtMs.value === null
        ? 0
        : Math.max(
              0,
              Math.floor(
                  (firstSelectionAtMs.value - analyticsStartedAtMs.value) /
                      1000,
              ),
          ),
);
const formattedElapsed = computed(() => formatDuration(elapsedSeconds.value));
const analyticsPayload = computed(() => ({
    session_id: analyticsSessionId.value,
    started_at: isoFromMs(analyticsStartedAtMs.value),
    first_selection_at: isoFromMs(firstSelectionAtMs.value),
    last_selection_at: isoFromMs(lastSelectionAtMs.value),
    review_opened_at: isoFromMs(reviewOpenedAtMs.value),
    finalized_at: isoFromMs(finalizedAtMs.value),
    total_duration_seconds: elapsedSeconds.value,
    time_to_first_selection_seconds: timeToFirstSelectionSeconds.value,
    selection_edit_count: selectionEditCount.value,
    random_fill_used: randomFillClickCount.value > 0,
    random_fill_clicks: randomFillClickCount.value,
    random_fill_completed_contests: randomFillCompletedContests.value,
    contest_navigation_clicks: contestNavigationClicks.value,
    surname_navigation_clicks: surnameNavigationClicks.value,
    review_count: reviewCount.value,
    overvote_attempts_blocked: overvoteAttemptsBlocked.value,
    final_selection_count: selectionCount.value,
}));

function toggle(contest: Contest, candidate: Candidate): void {
    const current = selections.value[contest.id] ?? [];

    if (current.includes(candidate.id)) {
        selections.value[contest.id] = current.filter(
            (id) => id !== candidate.id,
        );
        recordSelectionEdit();

        return;
    }

    if (current.length < contest.max_selections) {
        selections.value[contest.id] = [...current, candidate.id];
        recordSelectionEdit();

        return;
    }

    overvoteAttemptsBlocked.value += 1;
}

function clearDraft(): void {
    sessionStorage.removeItem('aes-voter-draft');
}

function clearSelections(): void {
    selections.value = {};
    sessionStorage.removeItem('aes-voter-draft');
}

function fillRemainingChoices(): void {
    randomFillClickCount.value += 1;
    randomFillCompletedContests.value = 0;

    props.ballot.contests.forEach((contest) => {
        const current = selections.value[contest.id] ?? [];
        const remainingSlots = Math.max(
            0,
            contest.max_selections - current.length,
        );

        if (remainingSlots === 0) {
            randomFillCompletedContests.value += 1;

            return;
        }

        const selectedCandidates = new Set(current);
        const additions = shuffle(
            contest.candidates.filter(
                (candidate) => !selectedCandidates.has(candidate.id),
            ),
        )
            .slice(0, remainingSlots)
            .map((candidate) => candidate.id);

        if (additions.length === 0) {
            return;
        }

        selections.value[contest.id] = [...current, ...additions];
        randomFillCompletedContests.value += 1;
    });

    recordSelectionEdit();
}

function scrollToElement(elementId: string): void {
    document.getElementById(elementId)?.scrollIntoView({
        behavior: 'smooth',
        block: 'start',
    });
}

function jumpToContest(contestId: string): void {
    contestNavigationClicks.value += 1;
    scrollToElement(contestAnchor(contestId));
}

function jumpToCandidateLetter(
    contest: Contest,
    letter: { letter: string; candidateId: string },
): void {
    surnameNavigationClicks.value += 1;
    activeLetters.value[contest.id] = letter.letter;
    scrollToElement(candidateAnchor(contest.id, letter.candidateId));
}

function openReview(): void {
    reviewCount.value += 1;
    reviewOpenedAtMs.value ??= Date.now();
    step.value = 'review';
}

function markFinalized(): void {
    finalizedAtMs.value = Date.now();
    nowMs.value = finalizedAtMs.value;
}

function transformSubmission(
    data: Record<string, unknown>,
): Record<string, unknown> {
    markFinalized();

    if (!analyticsEnabled.value) {
        return data;
    }

    return {
        ...data,
        analytics: analyticsPayload.value,
    };
}

function recordSelectionEdit(): void {
    const changedAt = Date.now();

    firstSelectionAtMs.value ??= changedAt;
    lastSelectionAtMs.value = changedAt;
    selectionEditCount.value += 1;
}

function isoFromMs(value: number | null): string {
    return value === null ? '' : new Date(value).toISOString();
}

function formatDuration(totalSeconds: number): string {
    const minutes = Math.floor(totalSeconds / 60)
        .toString()
        .padStart(2, '0');
    const seconds = (totalSeconds % 60).toString().padStart(2, '0');

    return `${minutes}:${seconds}`;
}

function shuffle<T>(items: T[]): T[] {
    return items
        .map((item) => ({
            item,
            order:
                'crypto' in window && 'getRandomValues' in window.crypto
                    ? window.crypto.getRandomValues(new Uint32Array(1))[0]
                    : Math.random(),
        }))
        .sort((left, right) => left.order - right.order)
        .map(({ item }) => item);
}

function createSessionId(): string {
    if ('crypto' in window && 'randomUUID' in window.crypto) {
        return window.crypto.randomUUID();
    }

    return `ballot-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

onMounted(() => {
    analyticsSessionId.value = createSessionId();
    analyticsStartedAtMs.value = Date.now();
    nowMs.value = analyticsStartedAtMs.value;
    timerInterval = window.setInterval(() => {
        nowMs.value = Date.now();
    }, 1000);
});

onUnmounted(() => {
    if (timerInterval !== undefined) {
        window.clearInterval(timerInterval);
    }
});
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
                    <p
                        v-if="analyticsVisible"
                        class="rounded-full border border-stone-300 bg-stone-50 px-3 py-1 font-mono text-xs font-bold text-stone-700"
                    >
                        Time {{ formattedElapsed }}
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
            :transform="transformSubmission"
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
                <section
                    v-if="!hasBallotChoices"
                    class="border-2 border-amber-400 bg-amber-50 p-5 text-amber-950"
                >
                    <p class="text-sm font-black tracking-wide uppercase">
                        Ballot package needs refresh
                    </p>
                    <h2 class="mt-2 text-xl font-black">
                        No candidate choices are available on this tablet.
                    </h2>
                    <p class="mt-2 text-sm font-semibold">
                        Return to the precinct lobby or ask the Election
                        Officer to reset the demo room before continuing. The
                        app will not let a blank ballot proceed silently.
                    </p>
                </section>

                <section
                    v-if="demoRandomFillEnabled && hasBallotChoices"
                    class="flex flex-wrap items-center justify-between gap-3 border border-blue-200 bg-blue-50 p-4"
                    aria-label="Demo ballot helper"
                >
                    <div>
                        <p class="text-sm font-black text-blue-950">
                            Demo helper only
                        </p>
                        <p class="mt-1 text-sm font-semibold text-blue-900">
                            Existing selections are preserved. Use this to fill
                            the remaining choices quickly during walkthroughs.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            class="min-h-11 bg-blue-800 px-4 font-bold text-white disabled:opacity-50"
                            data-testid="fill-remaining-choices"
                            type="button"
                            :disabled="selectionCount >= maximumSelectionCount"
                            @click="fillRemainingChoices"
                        >
                            Fill remaining choices
                        </button>
                        <button
                            class="min-h-11 border border-blue-300 bg-white px-4 font-bold text-blue-900 disabled:opacity-50"
                            data-testid="clear-ballot"
                            type="button"
                            :disabled="selectionCount === 0"
                            @click="clearSelections"
                        >
                            Clear ballot
                        </button>
                    </div>
                </section>

                <TouchGuidedBallot
                    v-if="
                        hasBallotChoices &&
                        resolvedBallotUiProfile === 'touch_guided'
                    "
                    :active-letters="activeLetters"
                    :contest-navigation="contestNavigation"
                    :contests="ballot.contests"
                    :review-emphasized="
                        reviewRoom.enabled && selectionCount > 0
                    "
                    :review-summary="reviewSummary"
                    :selections="selections"
                    @jump-to-contest="jumpToContest"
                    @jump-to-letter="jumpToCandidateLetter"
                    @review="openReview"
                    @toggle="toggle"
                />
                <PaperFacsimileBallot
                    v-else-if="hasBallotChoices"
                    :active-letters="activeLetters"
                    :contest-navigation="contestNavigation"
                    :contests="ballot.contests"
                    :review-emphasized="
                        reviewRoom.enabled && selectionCount > 0
                    "
                    :review-summary="reviewSummary"
                    :max-columns="ballotMaxColumns ?? 4"
                    :profile="resolvedBallotUiProfile"
                    :selection-target="resolvedSelectionTarget"
                    :selections="selections"
                    @jump-to-contest="jumpToContest"
                    @jump-to-letter="jumpToCandidateLetter"
                    @review="openReview"
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
