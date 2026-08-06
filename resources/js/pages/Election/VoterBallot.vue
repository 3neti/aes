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

const props = defineProps<{
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

function contestShortLabel(contest: Contest): string {
    const title = `${contest.office ?? ''} ${contest.title}`.toUpperCase();

    if (title.includes('SENATOR')) {
        return 'Sen.';
    }

    if (title.includes('PARTY LIST')) {
        return 'Party List';
    }

    if (title.includes('REPRESENTATIVE') || title.includes('HOUSE')) {
        return 'Rep.';
    }

    if (title.includes('VICE-MAYOR') || title.includes('VICE MAYOR')) {
        return 'Vice-Mayor';
    }

    if (title.includes('MAYOR')) {
        return 'Mayor';
    }

    if (title.includes('COUNCILOR')) {
        return 'Councilors';
    }

    return contest.title.split(/[-(]/)[0]?.trim() || contest.id;
}

function contestAnchor(contestId: string): string {
    return `contest-${stableDomId(contestId)}`;
}

function candidateAnchor(contestId: string, candidateId: string): string {
    return `candidate-${stableDomId(contestId)}-${stableDomId(candidateId)}`;
}

function stableDomId(value: string): string {
    return (
        value
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-|-$/g, '') || 'item'
    );
}

function scrollToElement(elementId: string): void {
    document.getElementById(elementId)?.scrollIntoView({
        behavior: 'smooth',
        block: 'start',
    });
}

function letterIndex(
    contest: Contest,
): Array<{ letter: string; candidateId: string }> {
    if (contest.candidates.length < 20) {
        return [];
    }

    const seen = new Set<string>();

    return contest.candidates.reduce(
        (
            letters: Array<{ letter: string; candidateId: string }>,
            candidate,
        ) => {
            const letter = candidateLetter(contest, candidate);

            if (letter === '' || seen.has(letter)) {
                return letters;
            }

            seen.add(letter);
            letters.push({ letter, candidateId: candidate.id });

            return letters;
        },
        [],
    );
}

function candidateLetter(contest: Contest, candidate: Candidate): string {
    const key = candidateIndexKey(contest, candidate);
    const match = key.match(/[A-Z0-9]/);

    return match?.[0] ?? '';
}

function candidateIndexKey(contest: Contest, candidate: Candidate): string {
    const name = (candidate.name || candidate.full_name || '').trim();

    if (isPartyListContest(contest)) {
        return name.toUpperCase();
    }

    if (name.includes(',')) {
        return name.split(',')[0].trim().toUpperCase();
    }

    const words = name.split(/\s+/).filter(Boolean);

    return (words.at(-1) ?? name).toUpperCase();
}

function isPartyListContest(contest: Contest): boolean {
    return `${contest.office ?? ''} ${contest.title}`
        .toUpperCase()
        .includes('PARTY LIST');
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
                <nav
                    class="sticky top-0 z-20 border border-stone-300 bg-white p-3 shadow-sm"
                    aria-label="Ballot position navigation"
                >
                    <p class="text-xs font-bold text-stone-600 uppercase">
                        Jump to position
                    </p>
                    <div class="mt-2 flex gap-2 overflow-x-auto pb-1">
                        <button
                            v-for="contest in contestNavigation"
                            :key="contest.id"
                            class="shrink-0 border px-3 py-2 text-sm font-bold"
                            :class="
                                contest.selected > 0
                                    ? 'border-blue-800 bg-blue-50 text-blue-900'
                                    : 'border-stone-300 bg-white text-stone-800'
                            "
                            type="button"
                            @click="scrollToElement(contestAnchor(contest.id))"
                        >
                            {{ contest.label }}
                            <span class="font-mono"
                                >{{ contest.selected }}/{{ contest.max }}</span
                            >
                        </button>
                    </div>
                </nav>

                <fieldset
                    v-for="contest in ballot.contests"
                    :key="contest.id"
                    class="scroll-mt-28 border border-stone-300 bg-white p-4 sm:p-5"
                    :id="contestAnchor(contest.id)"
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
                    <div
                        v-if="letterIndex(contest).length > 0"
                        class="mt-4 border-y border-stone-200 py-3"
                    >
                        <p class="text-xs font-bold text-stone-600 uppercase">
                            Jump by candidate name
                        </p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <button
                                v-for="letter in letterIndex(contest)"
                                :key="`${contest.id}-${letter.letter}`"
                                class="flex h-9 min-w-9 items-center justify-center border border-stone-300 bg-stone-50 px-2 text-sm font-bold text-stone-900"
                                type="button"
                                @click="
                                    scrollToElement(
                                        candidateAnchor(
                                            contest.id,
                                            letter.candidateId,
                                        ),
                                    )
                                "
                            >
                                {{ letter.letter }}
                            </button>
                        </div>
                    </div>
                    <div class="mt-4 grid gap-2 sm:grid-cols-2">
                        <button
                            v-for="candidate in contest.candidates"
                            :key="candidate.id"
                            class="grid min-h-20 scroll-mt-32 grid-cols-[32px_1fr] items-center gap-3 border p-3 text-left disabled:cursor-not-allowed disabled:opacity-45"
                            :class="
                                selected(contest.id, candidate.id)
                                    ? 'border-blue-800 bg-blue-50'
                                    : 'border-stone-300 bg-white'
                            "
                            :id="candidateAnchor(contest.id, candidate.id)"
                            :disabled="
                                isAtLimit(contest) &&
                                !selected(contest.id, candidate.id)
                            "
                            :data-testid="`candidate-${contest.id}-${candidate.id}`"
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
                        class="min-h-12 w-full bg-blue-800 px-6 py-3 text-sm leading-snug font-bold text-white sm:text-base"
                        :class="{
                            'review-next-action-button':
                                reviewRoom.enabled && selectionCount > 0,
                        }"
                        type="button"
                        @click="step = 'review'"
                    >
                        Review: {{ reviewSummary }}
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
                                : 'Finalize and get print PIN'
                        }}
                    </button>
                </div>
            </template>
        </Form>
    </main>
</template>
