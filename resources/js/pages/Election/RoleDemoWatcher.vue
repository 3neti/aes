<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import TallyMarks from '@/components/election/TallyMarks.vue';

const props = defineProps<{
    precinct: {
        code: string;
        label: string;
        status: string;
        accepted_ballots: number;
        rejected_ballots: number;
        tally_hash: string;
        display_tally: Record<string, Record<string, number>>;
    };
    ballot: {
        contests: Array<{
            id: string;
            title: string;
            candidates: Array<{ id: string; name: string }>;
        }>;
    };
    downloads: {
        tally: string;
        return: string;
    };
}>();

usePoll(4000, { only: ['precinct'] }, { keepAlive: true });

function contestTitle(contestId: string): string {
    return (
        props.ballot.contests.find((contest) => contest.id === contestId)
            ?.title ?? contestId
    );
}

function candidateName(contestId: string, candidateId: string): string {
    return (
        props.ballot.contests
            .find((contest) => contest.id === contestId)
            ?.candidates.find((candidate) => candidate.id === candidateId)
            ?.name ?? candidateId
    );
}
</script>

<template>
    <Head :title="`${precinct.code} role demo watcher`" />

    <main class="min-h-screen bg-stone-100 p-5 text-stone-950">
        <section class="mx-auto max-w-6xl border border-stone-300 bg-white p-6">
            <Link href="/election/role-demo" class="text-sm font-bold text-blue-800">
                Role POV room
            </Link>
            <p class="mt-4 text-sm font-bold text-blue-800">
                POLL WATCHER POV
            </p>
            <h1 class="mt-1 text-2xl font-bold">{{ precinct.label }}</h1>
            <p class="mt-3 max-w-3xl text-stone-700">
                This is the live demo tally from printed and deposited VVDAT
                records. It refreshes while the Election Officer accepts voter
                print PINs.
            </p>

            <div class="mt-5 grid gap-3 sm:grid-cols-4">
                <p class="border border-stone-200 bg-stone-50 p-4">
                    <strong class="block text-3xl">{{
                        precinct.accepted_ballots
                    }}</strong>
                    <span class="text-sm text-stone-600">accepted ballots</span>
                </p>
                <p class="border border-stone-200 bg-stone-50 p-4">
                    <strong class="block text-3xl">{{
                        precinct.rejected_ballots
                    }}</strong>
                    <span class="text-sm text-stone-600">rejected ballots</span>
                </p>
                <p class="border border-stone-200 bg-stone-50 p-4 sm:col-span-2">
                    <strong class="block font-mono text-sm">{{
                        precinct.tally_hash
                    }}</strong>
                    <span class="text-sm text-stone-600">current tally hash</span>
                </p>
            </div>

            <div class="mt-5 flex flex-wrap gap-3">
                <a :href="downloads.tally" class="secondary-button">
                    Open interim tally PDF
                </a>
                <a :href="downloads.return" class="secondary-button">
                    Open interim Election Return PDF
                </a>
            </div>

            <section
                v-for="(candidates, contest) in precinct.display_tally"
                :key="contest"
                class="mt-6 border border-stone-300"
            >
                <h2 class="border-b border-stone-200 bg-stone-50 px-4 py-3 font-bold">
                    {{ contestTitle(String(contest)) }}
                </h2>
                <template v-if="Object.keys(candidates).length > 0">
                    <div
                        v-for="(votes, candidate) in candidates"
                        :key="candidate"
                        class="grid grid-cols-[minmax(0,1fr)_minmax(160px,1.2fr)_auto] items-center gap-4 border-b border-stone-100 px-4 py-3 text-sm"
                    >
                        <span>{{ candidateName(String(contest), String(candidate)) }}</span>
                        <TallyMarks :count="Number(votes)" />
                        <strong class="text-sm font-semibold text-stone-600">
                            {{ votes }}
                        </strong>
                    </div>
                </template>
                <p v-else class="px-4 py-5 text-sm font-semibold text-stone-600">
                    No votes recorded for this contest yet.
                </p>
            </section>
        </section>
    </main>
</template>
