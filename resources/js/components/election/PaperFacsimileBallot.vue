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

    <fieldset
        v-for="contest in contests"
        :id="contestAnchor(contest.id)"
        :key="contest.id"
        class="scroll-mt-28 border border-stone-300 bg-white p-4 sm:p-5"
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

        <BallotAlphabetNavigation
            :active-letter="activeLetters[contest.id]"
            :label="letterNavigationLabel(contest)"
            :letters="letterIndex(contest)"
            @jump="emit('jumpToLetter', contest, $event)"
        />

        <div class="mt-4 grid gap-2 sm:grid-cols-2">
            <button
                v-for="candidate in contest.candidates"
                :id="candidateAnchor(contest.id, candidate.id)"
                :key="candidate.id"
                class="grid min-h-20 scroll-mt-32 grid-cols-[32px_1fr] items-center gap-3 border p-3 text-left disabled:cursor-not-allowed disabled:opacity-45"
                :class="
                    selected(contest.id, candidate.id)
                        ? 'border-blue-800 bg-blue-50'
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
                            : (candidate.ballot_number ?? candidate.ordinal)
                    }}
                </span>
                <span>
                    <strong class="block">{{ candidate.name }}</strong>
                    <span class="text-xs text-stone-600">{{
                        candidate.political_party ||
                        'Independent / no party listed'
                    }}</span>
                </span>
            </button>
        </div>
    </fieldset>

    <BallotReviewSummaryButton
        :emphasized="reviewEmphasized"
        :summary="reviewSummary"
        @review="emit('review')"
    />
</template>
