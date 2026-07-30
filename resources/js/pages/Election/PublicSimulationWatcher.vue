<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import TallyMarks from '@/components/election/TallyMarks.vue';

const props = defineProps<{
    precinct: { label: string; code: string; status: string; accepted_ballots: number | null; tally: Record<string, Record<string, number>> };
    ballot: { contests: Array<{ id: string; title: string; candidates: Array<{ id: string; name: string }> }> };
    published: boolean;
    auditExportAvailable: boolean;
    randomManualAudit: {
        sample_hash: string;
        sample_size: number;
        source_record_count: number;
        verified_ballots: number;
        discrepancy_ballots: number;
        pending_ballots: number;
        device_record_issues: number;
        complete: boolean;
        passed: boolean;
        outcome: string;
        summary_hash: string;
        privacy_notice: string;
    } | Record<string, never>;
    publication: { manifest_hash: string | null; ledger_root: string | null };
    downloads: { tally: string; return: string; vvdat_audit_export: string; random_manual_audit: string };
}>();

function rmaPublished(): boolean {
    return Object.keys(props.randomManualAudit).length > 0;
}

function contestTitle(contestId: string): string {
    return props.ballot.contests.find((contest) => contest.id === contestId)?.title ?? contestId;
}

function candidateName(contestId: string, candidateId: string): string {
    return props.ballot.contests.find((contest) => contest.id === contestId)?.candidates.find((candidate) => candidate.id === candidateId)?.name ?? candidateId;
}
</script>

<template><main class="min-h-screen bg-stone-100 p-5 text-stone-950"><Head :title="`${precinct.code} watcher view`" /><section class="mx-auto max-w-5xl border border-stone-300 bg-white p-6"><Link href="/election/play" class="text-sm font-bold text-blue-800">All precincts</Link><p class="mt-4 text-sm font-bold text-blue-800">POLL WATCHER VIEW</p><h1 class="mt-1 text-2xl font-bold">{{ precinct.label }}</h1><template v-if="published"><p class="mt-3 text-stone-700">{{ precinct.accepted_ballots }} sealed VVDAT records have been tabulated after precinct close.</p><p class="mt-2 text-xs text-stone-600">Publication {{ publication.manifest_hash?.slice(0, 16) }} · VVDAT ledger {{ publication.ledger_root?.slice(0, 16) }}</p><div class="mt-5 flex flex-wrap gap-3"><a :href="downloads.tally" class="secondary-button">Download tally sheet PDF</a><a :href="downloads.return" class="secondary-button">Download Election Return PDF</a><a v-if="auditExportAvailable" :href="downloads.vvdat_audit_export" class="secondary-button">Download anonymized VVDAT export</a><a v-if="rmaPublished()" :href="downloads.random_manual_audit" class="secondary-button">Download RMA summary PDF</a></div><p v-if="!auditExportAvailable" class="mt-3 text-sm text-stone-600">The anonymized VVDAT export remains withheld under this simulation's publication policy.</p><section v-if="rmaPublished()" class="mt-6 border border-stone-300"><h2 class="border-b border-stone-200 bg-stone-50 px-4 py-3 font-bold">Published Random Manual Audit</h2><div class="grid gap-3 p-4 text-sm sm:grid-cols-3"><p><strong>Sample</strong><br>{{ randomManualAudit.sample_size }} of {{ randomManualAudit.source_record_count }}</p><p><strong>Verified</strong><br>{{ randomManualAudit.verified_ballots }}</p><p><strong>Discrepancies</strong><br>{{ randomManualAudit.discrepancy_ballots }}</p><p class="sm:col-span-3"><strong>{{ randomManualAudit.passed ? 'Verified' : 'Attention required' }}</strong> · {{ randomManualAudit.privacy_notice }}</p><p class="font-mono text-xs text-stone-600 sm:col-span-3">Audit summary {{ randomManualAudit.summary_hash.slice(0, 16) }}</p></div></section><section v-for="(candidates, contest) in precinct.tally" :key="contest" class="mt-6 border border-stone-300"><h2 class="border-b border-stone-200 bg-stone-50 px-4 py-3 font-bold">{{ contestTitle(String(contest)) }}</h2><div v-for="(votes, candidate) in candidates" :key="candidate" class="grid grid-cols-[1fr_auto_auto] items-center gap-4 border-b border-stone-100 px-4 py-3 text-sm"><span>{{ candidateName(String(contest), String(candidate)) }}</span><TallyMarks :count="Number(votes)" /><strong>{{ votes }}</strong></div></section></template><template v-else><p class="mt-5 border-l-4 border-amber-500 bg-amber-50 p-4 text-amber-950">This precinct has not yet published post-close results. Totals remain sealed until the Election Officer approves the watcher package.</p></template></section></main></template>
