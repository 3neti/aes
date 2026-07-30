<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import {
    download as downloadRandomManualAuditEvidencePack,
    print as printRandomManualAuditEvidencePack,
    verify as verifyRandomManualAuditEvidencePack,
} from '@/routes/election/watchers/rma/evidence-pack';
import { download as downloadTallyJson } from '@/routes/election/watchers/tally';
import { download as downloadTallySheet } from '@/routes/election/watchers/tally-sheet';

const props = defineProps<{
    snapshot: ElectionSnapshot;
    operations: { deposited_ballots: number };
    resultsAvailable: boolean;
    tallyAvailable: boolean;
    tally: {
        accepted_ballots?: number;
        tally?: Record<string, Record<string, number>>;
    };
    electionReturn: Record<string, unknown>;
    randomManualAudit: {
        available: boolean;
        sample_selected: boolean;
        sample_size: number | null;
        source_record_count: number | null;
        reconciliation: {
            complete: boolean;
            passed: boolean;
            verified_ballots: number;
            discrepancy_ballots: number;
            pending_ballots: number;
            device_record_issues: number;
            report_hash: string | null;
        };
        evidence_pack_available: boolean;
        evidence_pack_hash: string | null;
    };
    randomManualAuditVerification?: {
        passed: boolean;
        errors: string[];
        evidence_pack_hash: string | null;
        sample_size: number | null;
        verified_ballots: number;
        discrepancy_ballots: number;
    } | null;
}>();

function contestTitle(contestId: string): string {
    return props.snapshot.configuration.contests?.find((contest) => contest.id === contestId)?.title ?? contestId;
}

function candidateName(contestId: string, candidateId: string): string {
    return props.snapshot.configuration.contests?.find((contest) => contest.id === contestId)?.candidates.find((candidate) => candidate.id === candidateId)?.name ?? candidateId;
}
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Poll Watcher View">
        <section class="border border-stone-300 bg-white p-5">
            <p class="text-sm font-bold text-blue-800">Precinct observation</p>
            <h2 class="mt-1 text-xl font-bold">Operational status</h2>
            <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                <div class="border-l-4 border-blue-800 bg-stone-50 p-4">
                    <dt class="text-sm text-stone-600">Lifecycle stage</dt>
                    <dd class="mt-1 text-lg font-bold">{{ snapshot.stage }}</dd>
                </div>
                <div class="border-l-4 border-emerald-700 bg-stone-50 p-4">
                    <dt class="text-sm text-stone-600">
                        Paper ballots deposited
                    </dt>
                    <dd class="mt-1 text-2xl font-bold">
                        {{ operations.deposited_ballots }}
                    </dd>
                </div>
            </dl>
        </section>

        <section class="border border-stone-300 bg-white p-5">
            <template v-if="!tallyAvailable">
                <p class="text-sm font-bold text-amber-800">Tally remains sealed</p>
                <h2 class="mt-1 text-xl font-bold">Candidate totals are not published during voting</h2>
            </template>
            <template v-else>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-bold text-emerald-800">Post-close tally</p>
                        <h2 class="mt-1 text-xl font-bold">Precinct candidate totals</h2>
                        <p class="mt-2 text-stone-700">{{ tally.accepted_ballots ?? 0 }} deposited ballots represented in the published tally.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a :href="downloadTallySheet.url()" class="secondary-button">Download tally sheet PDF</a>
                        <a :href="downloadTallyJson.url()" class="secondary-button">Download tally JSON</a>
                    </div>
                </div>
                <div class="mt-5 space-y-4">
                    <section v-for="(totals, contest) in tally.tally ?? {}" :key="contest" class="border border-stone-300">
                        <h3 class="border-b border-stone-200 bg-stone-50 px-4 py-3 font-bold">{{ contestTitle(String(contest)) }}</h3>
                        <table class="w-full text-sm"><tbody class="divide-y divide-stone-200"><tr v-for="(votes, candidate) in totals" :key="candidate"><td class="px-4 py-2.5">{{ candidateName(String(contest), String(candidate)) }}</td><td class="w-24 px-4 py-2.5 text-right text-base font-bold">{{ votes }}</td></tr></tbody></table>
                    </section>
                </div>
            </template>
        </section>

        <section class="border border-stone-300 bg-white p-5">
            <template v-if="!resultsAvailable">
                <p class="text-sm font-bold text-amber-800">
                    Polls remain active
                </p>
                <h2 class="mt-1 text-xl font-bold">
                    Candidate totals are sealed
                </h2>
                <p class="mt-3 text-stone-700">
                    No candidate tally or individual ballot selections are
                    disclosed while voting or counting is in progress.
                </p>
            </template>
            <template v-else>
                <p class="text-sm font-bold text-emerald-800">
                    Official results available
                </p>
                <h2 class="mt-1 text-xl font-bold">
                    Post-close election evidence
                </h2>
                <p class="mt-3 text-stone-700">
                    The tally and Election Return are now available for watcher
                    comparison after the official ceremony.
                </p>
                <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="bg-stone-50 p-4">
                        <dt class="text-sm text-stone-600">Accepted ballots</dt>
                        <dd class="mt-1 text-2xl font-bold">
                            {{ tally.accepted_ballots ?? 0 }}
                        </dd>
                    </div>
                    <div class="bg-stone-50 p-4">
                        <dt class="text-sm text-stone-600">
                            Election Return status
                        </dt>
                        <dd class="mt-1 text-lg font-bold">
                            {{
                                Object.keys(electionReturn).length > 0
                                    ? 'Generated'
                                    : 'Pending generation'
                            }}
                        </dd>
                    </div>
                </dl>
            </template>
        </section>

        <section class="border border-stone-300 bg-white p-5">
            <template v-if="!randomManualAudit.available">
                <p class="text-sm font-bold text-amber-800">Manual audit not published</p>
                <h2 class="mt-1 text-xl font-bold">Paper-audit evidence remains sealed</h2>
                <p class="mt-3 text-stone-700">
                    Random Manual Audit information becomes available to poll watchers only after polls close.
                </p>
            </template>
            <template v-else>
                <p class="text-sm font-bold text-blue-800">Random manual audit</p>
                <h2 class="mt-1 text-xl font-bold">Published paper-audit status</h2>
                <p class="mt-3 text-stone-700">
                    This is a read-only comparison of selected paper ballots and sealed device records. It does not change the published tally or Election Return.
                </p>

                <div v-if="randomManualAudit.sample_selected" class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="bg-stone-50 p-4">
                        <dt class="text-sm text-stone-600">Audit sample</dt>
                        <dd class="mt-1 text-2xl font-bold">
                            {{ randomManualAudit.sample_size ?? 0 }} of {{ randomManualAudit.source_record_count ?? 0 }}
                        </dd>
                    </div>
                    <div class="bg-stone-50 p-4">
                        <dt class="text-sm text-stone-600">Reconciliation</dt>
                        <dd class="mt-1 text-lg font-bold">
                            {{
                                randomManualAudit.reconciliation.passed
                                    ? 'Verified'
                                    : randomManualAudit.reconciliation.complete
                                      ? 'Requires review'
                                      : 'In progress'
                            }}
                        </dd>
                    </div>
                </div>
                <p v-else class="mt-5 border-l-4 border-stone-400 bg-stone-50 p-4 text-sm text-stone-700">
                    The Election Board has not selected a random manual audit sample for this run.
                </p>

                <div v-if="randomManualAudit.reconciliation.report_hash" class="mt-5 grid gap-3 sm:grid-cols-4">
                    <div class="border border-emerald-300 bg-emerald-50 p-3">
                        <span class="text-xs font-bold uppercase text-emerald-800">Verified</span>
                        <strong class="mt-1 block text-2xl">{{ randomManualAudit.reconciliation.verified_ballots }}</strong>
                    </div>
                    <div class="border border-red-300 bg-red-50 p-3">
                        <span class="text-xs font-bold uppercase text-red-800">Discrepancies</span>
                        <strong class="mt-1 block text-2xl">{{ randomManualAudit.reconciliation.discrepancy_ballots }}</strong>
                    </div>
                    <div class="border border-amber-300 bg-amber-50 p-3">
                        <span class="text-xs font-bold uppercase text-amber-800">Pending</span>
                        <strong class="mt-1 block text-2xl">{{ randomManualAudit.reconciliation.pending_ballots }}</strong>
                    </div>
                    <div class="border border-stone-300 p-3">
                        <span class="text-xs font-bold uppercase text-stone-600">Device issues</span>
                        <strong class="mt-1 block text-2xl">{{ randomManualAudit.reconciliation.device_record_issues }}</strong>
                    </div>
                </div>

                <div v-if="randomManualAudit.evidence_pack_available" class="mt-5 flex flex-col gap-3 border-t border-stone-300 pt-5 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-stone-700">
                        Evidence pack {{ randomManualAudit.evidence_pack_hash?.slice(0, 16) }} is available for independent review.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <a :href="downloadRandomManualAuditEvidencePack.url()" class="secondary-button">Download JSON</a>
                        <a :href="printRandomManualAuditEvidencePack.url()" class="secondary-button">Download print form</a>
                    </div>
                </div>

                <section class="mt-5 border-t border-stone-300 pt-5">
                    <h3 class="text-sm font-bold text-stone-950">Verify a downloaded evidence pack</h3>
                    <p class="mt-1 text-sm text-stone-700">
                        Upload a JSON pack to recompute its embedded hashes and reconciliation counts. This check does not alter precinct evidence.
                    </p>
                    <Form
                        v-bind="verifyRandomManualAuditEvidencePack.form()"
                        #default="{ errors, processing }"
                        class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end"
                        enctype="multipart/form-data"
                    >
                        <label class="block flex-1 text-sm font-bold">
                            Evidence pack JSON
                            <input
                                name="evidence_pack"
                                type="file"
                                accept="application/json,.json"
                                class="mt-1 block min-h-11 w-full border border-stone-300 bg-white px-3 py-2 text-sm font-normal"
                                required
                            />
                        </label>
                        <button class="secondary-button" type="submit" :disabled="processing">
                            {{ processing ? 'Verifying...' : 'Verify evidence pack' }}
                        </button>
                        <p v-if="errors.evidence_pack" class="text-sm font-bold text-red-700">
                            {{ errors.evidence_pack }}
                        </p>
                    </Form>

                    <div
                        v-if="randomManualAuditVerification"
                        class="mt-4 border-l-4 px-4 py-3 text-sm"
                        :class="randomManualAuditVerification.passed ? 'border-emerald-700 bg-emerald-50 text-emerald-950' : 'border-red-700 bg-red-50 text-red-950'"
                        role="status"
                    >
                        <p class="font-bold">
                            {{ randomManualAuditVerification.passed ? 'Evidence pack verified' : 'Evidence pack verification failed' }}
                        </p>
                        <p class="mt-1">
                            {{ randomManualAuditVerification.verified_ballots }} verified comparisons, {{ randomManualAuditVerification.discrepancy_ballots }} discrepancies.
                        </p>
                        <ul v-if="randomManualAuditVerification.errors.length" class="mt-2 list-disc pl-5">
                            <li v-for="error in randomManualAuditVerification.errors" :key="error">{{ error }}</li>
                        </ul>
                    </div>
                </section>
            </template>
        </section>
    </CeremonyLayout>
</template>
