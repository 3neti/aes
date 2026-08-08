<script setup lang="ts">
import BallotAlphabetNavigation from '@/components/election/BallotAlphabetNavigation.vue';
import BallotPositionNavigation from '@/components/election/BallotPositionNavigation.vue';
import BallotReviewSummaryButton from '@/components/election/BallotReviewSummaryButton.vue';
import {
    candidateAnchor,
    contestAnchor,
    letterIndex,
    letterNavigationLabel,
} from '@/components/election/ballotNavigation';
import type {
    BallotLetterJump,
    BallotNavigationContest,
    BallotSelections,
    Candidate,
    Contest,
} from '@/components/election/types';

const props = defineProps<{
    contests: Contest[];
    contestNavigation: BallotNavigationContest[];
    selections: BallotSelections;
    activeLetters: Record<string, string>;
    reviewSummary: string;
    reviewEmphasized: boolean;
}>();

const emit = defineEmits<{
    toggle: [contest: Contest, candidate: Candidate];
    jumpToContest: [contestId: string];
    jumpToLetter: [contest: Contest, letter: BallotLetterJump];
    review: [];
}>();

function selected(contestId: string, candidateId: string): boolean {
    return (props.selections[contestId] ?? []).includes(candidateId);
}

function isAtLimit(contest: Contest): boolean {
    return (
        (props.selections[contest.id] ?? []).length >= contest.max_selections
    );
}
</script>

<template>
    <BallotPositionNavigation
        :contests="contestNavigation"
        @jump="emit('jumpToContest', $event)"
    />

    <section
        v-for="contest in contests"
        :id="contestAnchor(contest.id)"
        :key="contest.id"
        class="scroll-mt-28 border border-stone-300 bg-white p-4 sm:p-5"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-bold text-blue-800 uppercase">
                    {{ contest.office || contest.title }}
                </p>
                <h2 class="mt-1 text-xl font-bold">
                    {{ contest.title }}
                </h2>
                <p class="mt-1 text-sm text-stone-600">
                    Select up to {{ contest.max_selections }}
                </p>
            </div>
            <div
                class="border border-blue-800 bg-blue-50 px-3 py-2 text-right text-blue-950"
            >
                <p class="text-xs font-bold uppercase">Selected</p>
                <p class="font-mono text-lg font-black">
                    {{ (selections[contest.id] ?? []).length }} /
                    {{ contest.max_selections }}
                </p>
            </div>
        </div>

        <BallotAlphabetNavigation
            :active-letter="activeLetters[contest.id]"
            :label="letterNavigationLabel(contest)"
            :letters="letterIndex(contest)"
            @jump="emit('jumpToLetter', contest, $event)"
        />

        <div class="mt-4 grid gap-3 lg:grid-cols-2">
            <button
                v-for="candidate in contest.candidates"
                :id="candidateAnchor(contest.id, candidate.id)"
                :key="candidate.id"
                class="grid min-h-24 scroll-mt-32 grid-cols-[48px_1fr_auto] items-center gap-3 border p-3 text-left disabled:cursor-not-allowed disabled:opacity-45"
                :class="
                    selected(contest.id, candidate.id)
                        ? 'border-blue-800 bg-blue-50 ring-2 ring-blue-800'
                        : 'border-stone-300 bg-white'
                "
                :data-testid="`candidate-${contest.id}-${candidate.id}`"
                :disabled="
                    isAtLimit(contest) && !selected(contest.id, candidate.id)
                "
                type="button"
                @click="emit('toggle', contest, candidate)"
            >
                <span
                    class="flex h-11 w-11 items-center justify-center border-2 text-base font-black"
                    :class="
                        selected(contest.id, candidate.id)
                            ? 'border-blue-800 bg-blue-800 text-white'
                            : 'border-stone-400 bg-stone-50 text-stone-800'
                    "
                >
                    {{
                        selected(contest.id, candidate.id)
                            ? 'X'
                            : (candidate.ballot_number ?? candidate.ordinal)
                    }}
                </span>
                <span class="min-w-0">
                    <strong class="block text-base leading-snug sm:text-lg">{{
                        candidate.name
                    }}</strong>
                    <span class="block text-sm text-stone-600">{{
                        candidate.political_party ||
                        'Independent / no party listed'
                    }}</span>
                </span>
                <span
                    v-if="selected(contest.id, candidate.id)"
                    class="border border-blue-800 px-2 py-1 text-xs font-bold text-blue-900 uppercase"
                >
                    Selected
                </span>
            </button>
        </div>
    </section>

    <BallotReviewSummaryButton
        :emphasized="reviewEmphasized"
        :summary="reviewSummary"
        @review="emit('review')"
    />
</template>
