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

type SelectionTarget = 'circle' | 'circle_with_label' | 'row';

const props = defineProps<{
    contests: Contest[];
    contestNavigation: BallotNavigationContest[];
    selections: BallotSelections;
    activeLetters: Record<string, string>;
    reviewSummary: string;
    reviewEmphasized: boolean;
    selectionTarget: SelectionTarget;
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

function candidateDisabled(contest: Contest, candidate: Candidate): boolean {
    return isAtLimit(contest) && !selected(contest.id, candidate.id);
}

function toggleCandidate(contest: Contest, candidate: Candidate): void {
    if (candidateDisabled(contest, candidate)) {
        return;
    }

    emit('toggle', contest, candidate);
}

function toggleCandidateFromRow(contest: Contest, candidate: Candidate): void {
    if (props.selectionTarget !== 'row') {
        return;
    }

    toggleCandidate(contest, candidate);
}

function candidateNumber(candidate: Candidate): number {
    return candidate.ballot_number ?? candidate.ordinal;
}

function candidateColumns(contest: Contest): Candidate[][] {
    const columnCount = contestColumnCount(contest);
    const columnSize = Math.ceil(contest.candidates.length / columnCount);

    return Array.from({ length: columnCount }, (_, index) =>
        contest.candidates.slice(index * columnSize, (index + 1) * columnSize),
    ).filter((candidates) => candidates.length > 0);
}

function contestColumnCount(contest: Contest): number {
    if (contest.candidates.length >= 40) {
        return 4;
    }

    if (contest.candidates.length >= 6) {
        return 2;
    }

    return 1;
}

function contestColumnGridClass(contest: Contest): string {
    const columnCount = contestColumnCount(contest);

    if (columnCount === 4) {
        return 'grid-cols-1 md:grid-cols-2 xl:grid-cols-4';
    }

    if (columnCount === 2) {
        return 'grid-cols-1 sm:grid-cols-2';
    }

    return 'grid-cols-1';
}

function contestColumnBorderClass(
    contest: Contest,
    columnIndex: number,
): string {
    const columnCount = contestColumnCount(contest);

    if (columnCount === 4) {
        return columnIndex === 0 || columnIndex === 2
            ? 'md:border-r-2 md:border-stone-900'
            : '';
    }

    if (columnCount === 2 && columnIndex === 0) {
        return 'sm:border-r-2 sm:border-stone-900';
    }

    return '';
}

function columnRangeLabel(candidates: Candidate[]): string {
    const first = candidates.at(0);
    const last = candidates.at(-1);

    if (!first || !last) {
        return 'Candidates';
    }

    return `Candidates ${candidateNumber(first)} to ${candidateNumber(last)}`;
}

function markerLabel(contest: Contest, candidate: Candidate): string {
    const action = selected(contest.id, candidate.id) ? 'Remove' : 'Select';

    return `${action} candidate ${candidateNumber(candidate)} ${candidate.name}`;
}
</script>

<template>
    <BallotPositionNavigation
        :contests="contestNavigation"
        @jump="emit('jumpToContest', $event)"
    />

    <section
        class="border-2 border-stone-950 bg-white p-3 shadow-sm sm:p-5"
        aria-label="Paper ballot facsimile"
    >
        <div class="border-b-2 border-stone-950 pb-3">
            <div
                class="grid gap-3 text-center uppercase sm:grid-cols-[1fr_auto_1fr] sm:items-center"
            >
                <p class="text-xs font-bold tracking-widest text-stone-700">
                    Official ballot facsimile
                </p>
                <div>
                    <p class="text-sm font-black tracking-widest">
                        Alternative Election System
                    </p>
                    <p class="text-xs font-bold text-stone-600">
                        Tablet marking interface
                    </p>
                </div>
                <p class="text-xs font-bold tracking-widest text-stone-700">
                    Preserve ballot secrecy
                </p>
            </div>
        </div>

        <div
            class="mt-3 grid gap-2 border-b border-stone-400 pb-3 text-xs font-semibold text-stone-700 sm:grid-cols-3"
        >
            <p>Tap the circle beside each chosen candidate.</p>
            <p>Selections may be changed before review.</p>
            <p>The paper printout remains the official ballot.</p>
        </div>

        <div class="mt-4 space-y-4">
            <section
                v-for="(contest, contestIndex) in contests"
                :id="contestAnchor(contest.id)"
                :key="contest.id"
                class="mb-4 inline-block w-full scroll-mt-28 break-inside-avoid border-2 border-stone-900 bg-white"
            >
                <header
                    class="grid grid-cols-[42px_1fr_auto] items-stretch border-b-2 border-stone-900 bg-stone-100 text-stone-950"
                >
                    <div
                        class="flex items-center justify-center border-r-2 border-stone-900 font-mono text-sm font-black"
                    >
                        {{ contestIndex + 1 }}
                    </div>
                    <div class="px-3 py-2">
                        <p
                            class="text-[10px] font-black tracking-widest uppercase"
                        >
                            Position
                        </p>
                        <h2
                            class="text-base leading-tight font-black uppercase"
                        >
                            {{ contest.title }}
                        </h2>
                    </div>
                    <div
                        class="border-l-2 border-stone-900 px-3 py-2 text-right"
                    >
                        <p
                            class="text-[10px] font-black tracking-widest uppercase"
                        >
                            Vote for
                        </p>
                        <p class="font-mono text-lg font-black">
                            {{ contest.max_selections }}
                        </p>
                    </div>
                </header>

                <div
                    class="flex items-center justify-between gap-3 border-b border-stone-400 px-3 py-2"
                >
                    <p class="text-xs font-bold text-stone-700 uppercase">
                        Marked:
                        <span class="font-mono text-stone-950">
                            {{ (selections[contest.id] ?? []).length }} /
                            {{ contest.max_selections }}
                        </span>
                    </p>
                    <p class="text-[11px] font-semibold text-stone-600">
                        Review before printing
                    </p>
                </div>

                <div class="px-3">
                    <BallotAlphabetNavigation
                        :active-letter="activeLetters[contest.id]"
                        :label="letterNavigationLabel(contest)"
                        :letters="letterIndex(contest)"
                        @jump="emit('jumpToLetter', contest, $event)"
                    />
                </div>

                <div class="grid" :class="contestColumnGridClass(contest)">
                    <div
                        v-for="(column, columnIndex) in candidateColumns(
                            contest,
                        )"
                        :key="`${contest.id}-column-${columnIndex}`"
                        :class="contestColumnBorderClass(contest, columnIndex)"
                    >
                        <div
                            class="border-b border-stone-900 bg-stone-50 px-2 py-1 text-center text-[10px] font-black tracking-wide text-stone-600 uppercase"
                        >
                            {{ columnRangeLabel(column) }}
                        </div>
                        <div class="divide-y divide-stone-300">
                            <div
                                v-for="candidate in column"
                                :id="candidateAnchor(contest.id, candidate.id)"
                                :key="candidate.id"
                                class="grid min-h-11 w-full scroll-mt-32 grid-cols-[40px_34px_1fr] items-center text-left"
                                :class="[
                                    selected(contest.id, candidate.id)
                                        ? 'bg-blue-50'
                                        : 'bg-white odd:bg-stone-50/50',
                                    candidateDisabled(contest, candidate)
                                        ? 'opacity-45'
                                        : '',
                                    selectionTarget === 'row' &&
                                    !candidateDisabled(contest, candidate)
                                        ? 'cursor-pointer focus-within:ring-2 focus-within:ring-blue-700 hover:bg-blue-50'
                                        : '',
                                ]"
                                :data-testid="`candidate-${contest.id}-${candidate.id}`"
                                @click="
                                    toggleCandidateFromRow(contest, candidate)
                                "
                            >
                                <button
                                    v-if="
                                        selectionTarget === 'circle_with_label'
                                    "
                                    class="col-span-2 grid h-full min-h-11 grid-cols-[40px_34px] items-center disabled:cursor-not-allowed disabled:opacity-45"
                                    :aria-label="
                                        markerLabel(contest, candidate)
                                    "
                                    :data-testid="`candidate-marker-${contest.id}-${candidate.id}`"
                                    :disabled="
                                        candidateDisabled(contest, candidate)
                                    "
                                    type="button"
                                    @click.stop="
                                        toggleCandidate(contest, candidate)
                                    "
                                >
                                    <span
                                        class="flex h-full items-center justify-center border-r border-stone-300 font-mono text-xs font-bold text-stone-700"
                                    >
                                        {{ candidateNumber(candidate) }}
                                    </span>
                                    <span
                                        class="flex h-full items-center justify-center"
                                    >
                                        <span
                                            class="flex h-5 w-5 items-center justify-center rounded-full border-2 text-[10px] font-black"
                                            :class="
                                                selected(
                                                    contest.id,
                                                    candidate.id,
                                                )
                                                    ? 'border-blue-900 bg-blue-900 text-white'
                                                    : 'border-stone-800 bg-white text-transparent'
                                            "
                                            aria-hidden="true"
                                        >
                                            X
                                        </span>
                                    </span>
                                </button>
                                <template v-else>
                                    <span
                                        class="flex h-full items-center justify-center border-r border-stone-300 font-mono text-xs font-bold text-stone-700"
                                    >
                                        {{ candidateNumber(candidate) }}
                                    </span>
                                    <button
                                        class="flex h-full min-h-11 items-center justify-center disabled:cursor-not-allowed disabled:opacity-45"
                                        :aria-label="
                                            markerLabel(contest, candidate)
                                        "
                                        :data-testid="`candidate-marker-${contest.id}-${candidate.id}`"
                                        :disabled="
                                            candidateDisabled(
                                                contest,
                                                candidate,
                                            )
                                        "
                                        type="button"
                                        @click.stop="
                                            toggleCandidate(contest, candidate)
                                        "
                                    >
                                        <span
                                            class="flex h-5 w-5 items-center justify-center rounded-full border-2 text-[10px] font-black"
                                            :class="
                                                selected(
                                                    contest.id,
                                                    candidate.id,
                                                )
                                                    ? 'border-blue-900 bg-blue-900 text-white'
                                                    : 'border-stone-800 bg-white text-transparent'
                                            "
                                            aria-hidden="true"
                                        >
                                            X
                                        </span>
                                    </button>
                                </template>
                                <span class="min-w-0 px-2 py-1.5">
                                    <strong
                                        class="block truncate text-[12px] leading-tight font-black uppercase"
                                    >
                                        {{ candidate.name }}
                                    </strong>
                                    <span
                                        class="block truncate text-[10px] font-semibold text-stone-600"
                                        >{{
                                            candidate.political_party ||
                                            'Independent / no party listed'
                                        }}</span
                                    >
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </section>

    <BallotReviewSummaryButton
        :emphasized="reviewEmphasized"
        :summary="reviewSummary"
        @review="emit('review')"
    />
</template>
