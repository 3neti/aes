<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
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
    selectionTarget?: string;
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
        : 'paper_facsimile',
);
const resolvedSelectionTarget = computed<SelectionTarget>(() =>
    props.selectionTarget === 'circle_with_label' ||
    props.selectionTarget === 'row'
        ? props.selectionTarget
        : 'circle',
);
const ballotKicker = computed(() =>
    resolvedBallotUiProfile.value === 'paper_facsimile'
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
                    @jump-to-contest="jumpToContest"
                    @jump-to-letter="jumpToCandidateLetter"
                    @review="openReview"
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
