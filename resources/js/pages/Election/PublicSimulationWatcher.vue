<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import TallyMarks from '@/components/election/TallyMarks.vue';

const props = defineProps<{
    precinct: { label: string; code: string; status: string; accepted_ballots: number | null; tally: Record<string, Record<string, number>> };
    ballot: { contests: Array<{ id: string; title: string; candidates: Array<{ id: string; name: string }> }> };
    published: boolean;
    publication: { manifest_hash: string | null; ledger_root: string | null };
    downloads: { tally: string; return: string; vvdat_audit_export: string };
}>();

function contestTitle(contestId: string): string {
    return props.ballot.contests.find((contest) => contest.id === contestId)?.title ?? contestId;
}

function candidateName(contestId: string, candidateId: string): string {
    return props.ballot.contests.find((contest) => contest.id === contestId)?.candidates.find((candidate) => candidate.id === candidateId)?.name ?? candidateId;
}
</script>

<template><main class="min-h-screen bg-stone-100 p-5 text-stone-950"><Head :title="`${precinct.code} watcher view`" /><section class="mx-auto max-w-5xl border border-stone-300 bg-white p-6"><Link href="/election/play" class="text-sm font-bold text-blue-800">All precincts</Link><p class="mt-4 text-sm font-bold text-blue-800">POLL WATCHER VIEW</p><h1 class="mt-1 text-2xl font-bold">{{ precinct.label }}</h1><template v-if="published"><p class="mt-3 text-stone-700">{{ precinct.accepted_ballots }} sealed VVDAT records have been tabulated after precinct close.</p><p class="mt-2 text-xs text-stone-600">Publication {{ publication.manifest_hash?.slice(0, 16) }} · VVDAT ledger {{ publication.ledger_root?.slice(0, 16) }}</p><div class="mt-5 flex flex-wrap gap-3"><a :href="downloads.tally" class="secondary-button">Download tally sheet PDF</a><a :href="downloads.return" class="secondary-button">Download Election Return PDF</a><a :href="downloads.vvdat_audit_export" class="secondary-button">Download anonymized VVDAT export</a></div><section v-for="(candidates, contest) in precinct.tally" :key="contest" class="mt-6 border border-stone-300"><h2 class="border-b border-stone-200 bg-stone-50 px-4 py-3 font-bold">{{ contestTitle(String(contest)) }}</h2><div v-for="(votes, candidate) in candidates" :key="candidate" class="grid grid-cols-[1fr_auto_auto] items-center gap-4 border-b border-stone-100 px-4 py-3 text-sm"><span>{{ candidateName(String(contest), String(candidate)) }}</span><TallyMarks :count="Number(votes)" /><strong>{{ votes }}</strong></div></section></template><template v-else><p class="mt-5 border-l-4 border-amber-500 bg-amber-50 p-4 text-amber-950">This precinct has not yet published post-close results. Totals remain sealed until the Election Officer approves the watcher package.</p></template></section></main></template>
