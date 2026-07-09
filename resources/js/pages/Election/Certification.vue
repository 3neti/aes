<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import {
    manualVerification,
    manualVerificationDownload,
    zeroOut,
    zeroOutReportDownload,
    runSealing,
    sealingReportDownload,
    discrepancy,
    discrepancyReportDownload,
    run,
} from '@/routes/election/certification';

type CertificationReport = {
    schema_version?: string | null;
    run_id?: string | null;
    precinct_id?: string | null;
    expected_ballots?: number;
    actual_ballots?: number;
    accepted_ballots?: number;
    rejected_ballots?: number;
    passed?: boolean;
    report_hash?: string | null;
    generated_at?: string | null;
    actual_tally?: Record<string, Record<string, number>>;
};

type CheckResult = {
    name: string;
    passed: boolean;
    message: string;
};

type ManualVerificationReport = {
    schema_version?: string | null;
    run_id?: string | null;
    passed?: boolean;
    report_hash?: string | null;
    generated_at?: string | null;
    checks?: CheckResult[];
    comparison_summary?: {
        totals?: {
            machine?: {
                accepted_ballots?: number;
                rejected_ballots?: number;
            };
            manual?: {
                accepted_ballots?: number;
                rejected_ballots?: number;
            };
        };
    };
    manual_return_path?: string | null;
    manual_accepted_ballots?: number;
    manual_rejected_ballots?: number;
    machine_accepted_ballots?: number;
    machine_rejected_ballots?: number;
};

type DiscrepancyReport = {
    schema_version?: string | null;
    run_id?: string | null;
    precinct_id?: string | null;
    discrepancy_detected?: boolean;
    status?: string | null;
    passed?: boolean;
    report_hash?: string | null;
    generated_at?: string | null;
    official_minutes_hash?: string | null;
    manual_verification_report_hash?: string | null;
    certification_report_hash?: string | null;
    notes?: {
        note?: string;
        next_action?: string;
    };
    remediation?: {
        action: string;
        requirements: string[];
    };
};

type ZeroOutReport = {
    schema_version?: string | null;
    run_id?: string | null;
    precinct_id?: string | null;
    passed?: boolean;
    report_hash?: string | null;
    report_profile?: string | null;
    counts_before?: {
        accepted_ballots?: number;
        rejected_ballots?: number;
        spoiled_ballots?: number;
    };
    counts_after?: {
        accepted_ballots?: number;
        rejected_ballots?: number;
        spoiled_ballots?: number;
    };
    cleared_artifacts?: Array<{
        artifact?: string | null;
        size?: number;
    }>;
    generated_at?: string | null;
};

type SealingReport = {
    schema_version?: string | null;
    run_id?: string | null;
    precinct_id?: string | null;
    passed?: boolean;
    status?: string | null;
    report_hash?: string | null;
    checks?: Array<{
        name: string;
        passed: boolean;
        message?: string;
    }>;
    certification_report_hash?: string | null;
    manual_verification_report_hash?: string | null;
    discrepancy_report_hash?: string | null;
    zero_out_report_hash?: string | null;
    initialization_report_hash?: string | null;
    generated_at?: string | null;
};

type ManualReturnTemplate = {
    schema_version: string;
    precinct_id: string | null;
    accepted_ballots: number;
    rejected_ballots: number;
    tally: Record<string, Record<string, number>>;
};

const props = defineProps<{
    snapshot: ElectionSnapshot;
    certificationReport: CertificationReport;
    manualVerificationReport: ManualVerificationReport;
    discrepancyReport: DiscrepancyReport;
    zeroOutReport: ZeroOutReport;
    sealingReport: SealingReport;
    manualReturnTemplate: ManualReturnTemplate;
}>();

const hasCertificationReport =
    Object.keys(props.certificationReport).length > 0;
const hasManualVerificationReport =
    Object.keys(props.manualVerificationReport).length > 0;
const hasDiscrepancyReport = Object.keys(props.discrepancyReport).length > 0;
const hasZeroOutReport = Object.keys(props.zeroOutReport).length > 0;
const hasSealingReport = Object.keys(props.sealingReport).length > 0;
const manualReturnTemplateJson = JSON.stringify(
    props.manualReturnTemplate,
    null,
    2,
);
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Certification">
        <section class="border border-stone-300 bg-white p-5">
            <h2 class="text-lg font-semibold">Friday Certification</h2>
            <p class="mt-2 text-sm text-stone-700">
                Run known certification ballots and compare the generated tally
                with the expected result.
            </p>
            <Form v-bind="run.form()" class="mt-5">
                <button class="primary-button" type="submit">
                    Run Certification
                </button>
            </Form>
            <dl
                v-if="hasCertificationReport"
                class="mt-5 grid gap-2 text-xs sm:grid-cols-2"
            >
                <div>
                    <dt class="font-semibold">Certification Report</dt>
                    <dd class="mt-1 break-all text-stone-700">
                        Hash:
                        {{ certificationReport.report_hash }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold">Passed</dt>
                    <dd
                        class="mt-1"
                        :class="[
                            certificationReport.passed
                                ? 'text-emerald-700'
                                : 'text-red-700',
                        ]"
                    >
                        {{ certificationReport.passed ? 'PASS' : 'FAIL' }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold">Expected / Actual Ballots</dt>
                    <dd class="mt-1 text-stone-700">
                        {{ certificationReport.expected_ballots }} /
                        {{ certificationReport.actual_ballots }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold">Accepted / Rejected</dt>
                    <dd class="mt-1 text-stone-700">
                        {{ certificationReport.accepted_ballots }} /
                        {{ certificationReport.rejected_ballots }}
                    </dd>
                </div>
            </dl>
            <p v-else class="mt-3 text-sm text-stone-600">
                Certification has not been run in the current run yet.
            </p>
        </section>

        <section class="mt-6 border border-stone-300 bg-white p-5">
            <h2 class="text-lg font-semibold">Manual Verification</h2>
            <p class="mt-2 text-sm text-stone-700">
                Paste the official manual return summary and compare against the
                machine certification tally.
            </p>
            <Form v-bind="manualVerification.form()" class="mt-5">
                <label class="mb-2 block text-sm font-semibold text-stone-700">
                    Manual Return JSON
                </label>
                <textarea
                    required
                    class="h-44 w-full border border-stone-300 p-2 text-xs"
                    name="manual_return"
                    :value="manualReturnTemplateJson"
                ></textarea>
                <button class="secondary-button mt-3" type="submit">
                    Run Manual Verification
                </button>
            </Form>

            <div class="mt-4 flex flex-wrap gap-2">
                <a
                    v-if="hasManualVerificationReport"
                    class="artifact-link"
                    :href="manualVerificationDownload.url()"
                >
                    Download Manual Verification Report
                </a>
            </div>

            <dl
                v-if="hasManualVerificationReport"
                class="mt-4 grid gap-2 text-xs sm:grid-cols-2"
            >
                <div>
                    <dt class="font-semibold">Manual Verification</dt>
                    <dd
                        class="mt-1"
                        :class="[
                            manualVerificationReport.passed
                                ? 'text-emerald-700'
                                : 'text-red-700',
                        ]"
                    >
                        {{ manualVerificationReport.passed ? 'PASS' : 'FAIL' }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold">Report Hash</dt>
                    <dd class="mt-1 break-all text-stone-700">
                        {{ manualVerificationReport.report_hash }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold">Accepted</dt>
                    <dd class="mt-1 text-stone-700">
                        Machine:
                        {{ manualVerificationReport.machine_accepted_ballots }}
                        / Manual:
                        {{ manualVerificationReport.manual_accepted_ballots }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold">Rejected</dt>
                    <dd class="mt-1 text-stone-700">
                        Machine:
                        {{ manualVerificationReport.machine_rejected_ballots }}
                        / Manual:
                        {{ manualVerificationReport.manual_rejected_ballots }}
                    </dd>
                </div>
                <div v-if="manualVerificationReport.checks?.length">
                    <dt class="font-semibold">Checks</dt>
                    <dd class="mt-1 list-disc pl-5">
                        <ul>
                            <li
                                v-for="check in manualVerificationReport.checks"
                                :key="check.name"
                            >
                                <span
                                    :class="
                                        check.passed
                                            ? 'text-emerald-700'
                                            : 'text-red-700'
                                    "
                                >
                                    {{ check.name }}
                                </span>
                                : {{ check.message }}
                            </li>
                        </ul>
                    </dd>
                </div>
            </dl>
            <p v-else class="mt-3 text-sm text-stone-600">
                Manual verification has not been run yet.
            </p>
        </section>

        <section class="mt-6 border border-stone-300 bg-white p-5">
            <h2 class="text-lg font-semibold">Discrepancy Analysis</h2>
            <p class="mt-2 text-sm text-stone-700">
                Compare manual verification with machine certification and
                generate a legal discrepancy record.
            </p>
            <Form v-bind="discrepancy.form()" class="mt-5">
                <button class="secondary-button" type="submit">
                    Run Discrepancy Analysis
                </button>
            </Form>

            <div class="mt-4 flex flex-wrap gap-2">
                <a
                    v-if="hasDiscrepancyReport"
                    class="artifact-link"
                    :href="discrepancyReportDownload.url()"
                >
                    Download Discrepancy Report
                </a>
            </div>

            <dl
                v-if="hasDiscrepancyReport"
                class="mt-4 grid gap-2 text-xs sm:grid-cols-2"
            >
                <div>
                    <dt class="font-semibold">Discrepancy Status</dt>
                    <dd
                        class="mt-1"
                        :class="[
                            discrepancyReport.passed
                                ? 'text-emerald-700'
                                : 'text-red-700',
                        ]"
                    >
                        {{ discrepancyReport.discrepancy_detected ? 'YES' : 'NO' }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold">Report Hash</dt>
                    <dd class="mt-1 break-all text-stone-700">
                        {{ discrepancyReport.report_hash }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold">Official Minutes Hash</dt>
                    <dd class="mt-1 break-all text-stone-700">
                        {{ discrepancyReport.official_minutes_hash }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold">Recommended Next Action</dt>
                    <dd class="mt-1 text-stone-700">
                        {{ discrepancyReport.notes?.next_action || 'None' }}
                    </dd>
                </div>
                <div v-if="discrepancyReport.remediation" class="sm:col-span-2">
                    <dt class="font-semibold">Remediation</dt>
                    <dd class="mt-1 text-stone-700">
                        {{ discrepancyReport.remediation.action }}
                    </dd>
                </div>
                <div v-if="discrepancyReport.remediation" class="sm:col-span-2">
                    <dt class="font-semibold">Steps</dt>
                    <dd class="mt-1 list-disc pl-5 text-stone-700">
                        <ul>
                            <li
                                v-for="step in discrepancyReport.remediation.requirements"
                                :key="step"
                            >
                                {{ step }}
                            </li>
                        </ul>
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="font-semibold">Notes</dt>
                    <dd class="mt-1 text-stone-700">
                        {{ discrepancyReport.notes?.note || 'No notes.' }}
                    </dd>
                </div>
            </dl>
            <p v-else class="mt-3 text-sm text-stone-600">
                Run discrepancy analysis to document whether official and manual
                counts align.
            </p>
        </section>

        <section class="mt-6 border border-stone-300 bg-white p-5">
            <h2 class="text-lg font-semibold">Zero-Out</h2>
            <p class="mt-2 text-sm text-stone-700">
                Remove FTS counting artifacts and produce a signed zero-out
                artifact.
            </p>
            <Form v-bind="zeroOut.form()" class="mt-5">
                <button class="secondary-button" type="submit">
                    Run Zero-Out
                </button>
            </Form>

            <div class="mt-4 flex flex-wrap gap-2">
                <a
                    v-if="hasZeroOutReport"
                    class="artifact-link"
                    :href="zeroOutReportDownload.url()"
                >
                    Download Zero-Out Report
                </a>
            </div>

            <dl
                v-if="hasZeroOutReport"
                class="mt-4 grid gap-2 text-xs sm:grid-cols-2"
            >
                <div>
                    <dt class="font-semibold">Zero-Out Status</dt>
                    <dd
                        class="mt-1"
                        :class="[
                            hasZeroOutReport && props.zeroOutReport.passed
                                ? 'text-emerald-700'
                                : 'text-red-700',
                        ]"
                    >
                        {{ props.zeroOutReport.passed ? 'PASS' : 'PENDING' }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold">Report Hash</dt>
                    <dd class="mt-1 break-all text-stone-700">
                        {{ props.zeroOutReport.report_hash }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold">Counts Before</dt>
                    <dd class="mt-1 text-stone-700">
                        {{
                            props.zeroOutReport.counts_before?.accepted_ballots ?? 0
                        }}
                        accepted /
                        {{
                            props.zeroOutReport.counts_before?.rejected_ballots ?? 0
                        }}
                        rejected /
                        {{
                            props.zeroOutReport.counts_before?.spoiled_ballots ?? 0
                        }}
                        spoiled
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold">Counts After</dt>
                    <dd class="mt-1 text-stone-700">
                        {{ props.zeroOutReport.counts_after?.accepted_ballots ?? 0 }}
                        accepted /
                        {{ props.zeroOutReport.counts_after?.rejected_ballots ?? 0 }}
                        rejected /
                        {{ props.zeroOutReport.counts_after?.spoiled_ballots ?? 0 }}
                        spoiled
                    </dd>
                </div>
            </dl>
            <p v-else class="mt-3 text-sm text-stone-600">
                Run zero-out to clear ephemeral tally files after successful FTS.
            </p>
        </section>

        <section class="mt-6 border border-stone-300 bg-white p-5">
            <h2 class="text-lg font-semibold">Sealing Evidence</h2>
            <p class="mt-2 text-sm text-stone-700">
                Seal certification evidence for legal continuity and custody handoff.
            </p>
            <Form v-bind="runSealing.form()" class="mt-5">
                <button class="secondary-button" type="submit">
                    Record Sealing
                </button>
            </Form>

            <div class="mt-4 flex flex-wrap gap-2">
                <a
                    v-if="hasSealingReport"
                    class="artifact-link"
                    :href="sealingReportDownload.url()"
                >
                    Download Sealing Report
                </a>
            </div>

            <dl
                v-if="hasSealingReport"
                class="mt-4 grid gap-2 text-xs sm:grid-cols-2"
            >
                <div>
                    <dt class="font-semibold">Seal Status</dt>
                    <dd
                        class="mt-1"
                        :class="[
                            hasSealingReport && props.sealingReport.passed
                                ? 'text-emerald-700'
                                : 'text-red-700',
                        ]"
                    >
                        {{ props.sealingReport.status || 'UNKNOWN' }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold">Report Hash</dt>
                    <dd class="mt-1 break-all text-stone-700">
                        {{ props.sealingReport.report_hash }}
                    </dd>
                </div>
                <div v-if="props.sealingReport.checks?.length">
                    <dt class="font-semibold">Checks</dt>
                    <dd class="mt-1 list-disc pl-5 text-stone-700">
                        <ul>
                            <li
                                v-for="check in props.sealingReport.checks"
                                :key="check.name"
                            >
                                <span
                                    :class="
                                        check.passed
                                            ? 'text-emerald-700'
                                            : 'text-red-700'
                                    "
                                >
                                    {{ check.name }}
                                </span>
                                : {{ check.message }}
                            </li>
                        </ul>
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="font-semibold">Evidence</dt>
                    <dd class="mt-1 text-stone-700">
                        Certification:
                        {{ props.sealingReport.certification_report_hash ? 'present' : 'missing'
                        }} · Manual:
                        {{ props.sealingReport.manual_verification_report_hash ? 'present' : 'missing'
                        }} · Discrepancy:
                        {{ props.sealingReport.discrepancy_report_hash ? 'present' : 'missing'
                        }} · Zero-Out:
                        {{ props.sealingReport.zero_out_report_hash ? 'present' : 'missing' }}
                    </dd>
                </div>
            </dl>
            <p v-else class="mt-3 text-sm text-stone-600">
                Run sealing to generate the certification sealing summary report.
            </p>
        </section>
    </CeremonyLayout>
</template>

<style scoped>
.primary-button {
    background: rgb(4 120 87);
    color: white;
    padding: 0.7rem 1rem;
    font-weight: 700;
}
</style>
