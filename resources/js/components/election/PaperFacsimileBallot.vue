<script setup lang="ts">
import BallotAlphabetNavigation from '@/components/election/BallotAlphabetNavigation.vue';
import {
    candidateAnchor,
    contestAnchor,
    letterIndex,
    letterNavigationLabel,
} from '@/components/election/ballotNavigation';
import BallotPositionNavigation from '@/components/election/BallotPositionNavigation.vue';
import BallotReviewSummaryButton from '@/components/election/BallotReviewSummaryButton.vue';
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
    profile?: string;
    maxColumns?: number;
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
    const maximumColumns = Math.min(4, Math.max(1, props.maxColumns ?? 4));

    if (contest.candidates.length >= 40) {
        return Math.min(4, maximumColumns);
    }

    if (contest.candidates.length >= 6) {
        return Math.min(2, maximumColumns);
    }

    return 1;
}

function isComelecFacsimile(): boolean {
    return props.profile === 'comelec_2022_facsimile';
}

function contestColumnGridClass(contest: Contest): string {
    const columnCount = contestColumnCount(contest);

    if (columnCount === 4) {
        return 'grid-cols-1 md:grid-cols-2 xl:grid-cols-4';
    }

    if (columnCount === 2) {
        return 'grid-cols-1 md:grid-cols-2';
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

function contestHeaderClass(contestIndex: number): string {
    if (!isComelecFacsimile()) {
        return 'bg-stone-100 text-stone-950';
    }

    return contestIndex % 2 === 0
        ? 'bg-sky-700 text-white'
        : 'bg-emerald-700 text-white';
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
        class="relative overflow-hidden bg-white shadow-sm"
        :class="
            isComelecFacsimile()
                ? 'border-[10px] border-stone-950 p-2 sm:p-4'
                : 'border-2 border-stone-950 p-3 sm:p-5'
        "
        aria-label="Paper ballot facsimile"
    >
        <div
            v-if="isComelecFacsimile()"
            class="pointer-events-none absolute inset-x-8 top-0 flex justify-between"
            aria-hidden="true"
        >
            <span
                v-for="index in 24"
                :key="`top-timing-${index}`"
                class="h-8 w-3 bg-stone-950"
            />
        </div>
        <div
            v-if="isComelecFacsimile()"
            class="pointer-events-none absolute inset-y-10 left-0 flex flex-col justify-between"
            aria-hidden="true"
        >
            <span
                v-for="index in 34"
                :key="`left-timing-${index}`"
                class="h-3 w-3 bg-stone-950"
            />
        </div>
        <div
            v-if="isComelecFacsimile()"
            class="pointer-events-none absolute inset-y-10 right-0 flex flex-col justify-between"
            aria-hidden="true"
        >
            <span
                v-for="index in 34"
                :key="`right-timing-${index}`"
                class="h-3 w-3 bg-stone-950"
            />
        </div>

        <div
            class="relative border-b-2 border-stone-950 pb-3"
            :class="isComelecFacsimile() ? 'mt-8 px-4' : ''"
        >
            <div
                class="grid gap-3 uppercase sm:grid-cols-[1fr_auto_1fr] sm:items-center"
                :class="isComelecFacsimile() ? 'text-left' : 'text-center'"
            >
                <div>
                    <p
                        class="text-xs font-black tracking-widest text-stone-900"
                    >
                        May 9, 2022 National and Local Elections
                    </p>
                    <p class="mt-1 text-[11px] font-bold text-stone-700">
                        Barangay 147, Tondo, National Capital Region - Manila
                    </p>
                    <p class="text-[11px] font-bold text-stone-700">
                        Type: National and Local
                    </p>
                </div>
                <div>
                    <p
                        class="text-center text-sm font-black tracking-widest"
                    >
                        {{
                            isComelecFacsimile()
                                ? 'Ballot marking facsimile'
                                : 'Alternative Election System'
                        }}
                    </p>
                    <p class="text-center text-xs font-bold text-stone-600">
                        {{
                            isComelecFacsimile()
                                ? 'Tablet selection interface'
                                : 'Tablet marking interface'
                        }}
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-xs font-black text-stone-900">
                        Clustered Precinct ID
                    </p>
                    <p class="font-mono text-sm font-black text-stone-950">
                        39010402
                    </p>
                    <div
                        v-if="isComelecFacsimile()"
                        class="mt-3 ml-auto h-11 w-40 border border-stone-800"
                    >
                        <p
                            class="pt-7 text-center text-[8px] font-semibold text-stone-700"
                        >
                            Signature of Chairman
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div
            class="relative mt-3 grid gap-2 border-b border-stone-400 pb-3 text-xs font-semibold text-stone-700 sm:grid-cols-3"
            :class="isComelecFacsimile() ? 'px-4' : ''"
        >
            <p>Completely mark the circle beside the desired candidate.</p>
            <p>Do not vote for more candidates than the indicated limit.</p>
            <p>Review selections before printing the paper ballot.</p>
        </div>

        <div class="relative mt-4 space-y-3" :class="isComelecFacsimile() ? 'px-4' : ''">
            <section
                v-for="(contest, contestIndex) in contests"
                :id="contestAnchor(contest.id)"
                :key="contest.id"
                class="mb-3 inline-block w-full scroll-mt-28 break-inside-avoid border border-stone-900 bg-white"
            >
                <header
                    class="grid grid-cols-[1fr_auto] items-stretch border-b border-stone-900 text-center"
                    :class="contestHeaderClass(contestIndex)"
                >
                    <div class="px-3 py-1.5">
                        <h2 class="text-sm leading-tight font-black uppercase">
                            {{ contest.office || contest.title }} / Vote for
                            {{ contest.max_selections }}
                        </h2>
                    </div>
                    <div
                        v-if="!isComelecFacsimile()"
                        class="border-l border-stone-900 px-3 py-2 text-right"
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
                    :class="isComelecFacsimile() ? 'bg-stone-50/60' : ''"
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

                <div class="px-3" :class="isComelecFacsimile() ? 'print:hidden' : ''">
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
                            :class="isComelecFacsimile() ? 'hidden' : ''"
                        >
                            {{ columnRangeLabel(column) }}
                        </div>
                        <div class="divide-y divide-stone-300">
                            <div
                                v-for="candidate in column"
                                :id="candidateAnchor(contest.id, candidate.id)"
                                :key="candidate.id"
                                class="grid w-full scroll-mt-32 grid-cols-[34px_30px_1fr] items-center text-left"
                                :class="[
                                    isComelecFacsimile()
                                        ? 'min-h-9'
                                        : 'min-h-11',
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
                                        class="col-span-2 grid h-full min-h-11 grid-cols-[34px_30px] items-center disabled:cursor-not-allowed disabled:opacity-45"
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
                                            class="flex h-4 w-4 items-center justify-center rounded-full border"
                                            :class="
                                                selected(
                                                    contest.id,
                                                    candidate.id,
                                                )
                                                    ? 'border-stone-950 bg-stone-950'
                                                    : 'border-stone-800 bg-white text-transparent'
                                            "
                                            aria-hidden="true"
                                        />
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
                                            class="flex h-4 w-4 items-center justify-center rounded-full border"
                                            :class="
                                                selected(
                                                    contest.id,
                                                    candidate.id,
                                                )
                                                    ? 'border-stone-950 bg-stone-950'
                                                    : 'border-stone-800 bg-white text-transparent'
                                            "
                                            aria-hidden="true"
                                        />
                                    </button>
                                </template>
                                <span class="min-w-0 px-2 py-1.5">
                                    <strong
                                        class="block truncate leading-tight font-black uppercase"
                                        :class="isComelecFacsimile() ? 'text-[10px]' : 'text-[12px]'"
                                    >
                                        {{ candidate.name }}
                                    </strong>
                                    <span
                                        v-if="candidate.political_party"
                                        class="block truncate text-[10px] font-semibold text-stone-600"
                                        >{{
                                            candidate.political_party
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
