<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import {
    manualVerification,
    manualVerificationDownload,
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
    manualReturnTemplate: ManualReturnTemplate;
}>();

const hasCertificationReport =
    Object.keys(props.certificationReport).length > 0;
const hasManualVerificationReport =
    Object.keys(props.manualVerificationReport).length > 0;
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
