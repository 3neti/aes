<script setup lang="ts">
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import {
    download as downloadRandomManualAuditEvidencePack,
    print as printRandomManualAuditEvidencePack,
} from '@/routes/election/watchers/rma/evidence-pack';

defineProps<{
    snapshot: ElectionSnapshot;
    operations: { deposited_ballots: number };
    resultsAvailable: boolean;
    tally: Record<string, unknown>;
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
}>();
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
            </template>
        </section>
    </CeremonyLayout>
</template>
