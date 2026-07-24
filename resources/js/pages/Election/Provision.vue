<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import ArtifactLinks from '@/components/election/ArtifactLinks.vue';
import CeremonyActionPanel from '@/components/election/CeremonyActionPanel.vue';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import StatusBadge from '@/components/election/StatusBadge.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import {
    activate,
    ebRoleBaseline,
    legalScenarioSuite as legalScenarioSuiteAction,
    supplyVerificationBaseline as supplyVerificationBaselineAction,
} from '@/routes/election/provision';

type ElectoralBoardBaseline = {
    exists: boolean;
    artifact?: string;
    run_id?: string | null;
    precinct_id?: string | null;
    baseline_hash?: string | null;
    required_role_count?: number;
    required_roles_present?: number;
    missing_required_role_count?: number;
    passed?: boolean;
    generated_at?: string | null;
    generate_url: string;
};

type LegalScenarioSuite = {
    exists: boolean;
    report_path?: string;
    scenario?: string | null;
    suite?: string | null;
    passed?: boolean;
    run_id?: string | null;
    precinct_id?: string | null;
    sub_scenarios?: string[];
    evidence_reference_baseline?: Record<string, unknown>;
    official_minutes_baseline?: Record<string, unknown>;
    electoral_board_baseline?: Record<string, unknown>;
    harness_stages?: Record<string, string>;
    generated_at?: string | null;
    run_suite_url: string;
};

type SupplyVerificationBaseline = {
    exists: boolean;
    artifact?: string;
    baseline_hash?: string | null;
    required_supply_count?: number;
    required_supplies_present?: number;
    required_supply_missing_count?: number;
    optional_supply_count?: number;
    total_supply_count?: number;
    passed?: boolean;
    generated_at?: string | null;
    supplies?: Array<{
        supply_code: string;
        label: string;
        required: boolean;
        found: boolean;
    }>;
    generate_url: string;
};

type ActivationEvidence = {
    precinct_id?: string;
    district?: string;
    location?: {
        city_municipality?: string;
        barangay?: string;
        polling_place?: string;
    };
    contest_count?: number;
    candidate_count?: number;
    activation_hash?: string;
    pop?: {
        source_filename?: string;
        mapping_profile?: string;
    };
    clc?: {
        source_count?: number;
        needs_review_count?: number;
    };
};

const props = defineProps<{
    snapshot: ElectionSnapshot;
    electoralBoardBaseline: ElectoralBoardBaseline;
    legalScenarioSuite: LegalScenarioSuite;
    supplyVerificationBaseline: SupplyVerificationBaseline;
    activationEvidence: ActivationEvidence;
    configuredPrecinct: {
        clustered_precinct: string;
        district: string;
        pop_filename: string;
        clc_source: string;
    };
}>();

const readinessItems = [
    {
        label: 'Precinct package and ballot mapping',
        ready: () => Boolean(props.snapshot.configuration.mapping_hash),
    },
    {
        label: 'Electoral Board role roster',
        ready: () =>
            props.electoralBoardBaseline.exists &&
            Boolean(props.electoralBoardBaseline.passed),
    },
    {
        label: 'Required election supplies',
        ready: () =>
            props.supplyVerificationBaseline.exists &&
            Boolean(props.supplyVerificationBaseline.passed),
    },
    {
        label: 'Legal lifecycle scenario',
        ready: () =>
            props.legalScenarioSuite.exists &&
            Boolean(props.legalScenarioSuite.passed),
    },
];
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Precinct Setup">
        <CeremonyActionPanel
            title="Load this precinct"
            description="Import the configured Project of Precincts workbook and Certified List of Candidates PDFs, then derive the ballot assigned to this appliance."
            eyebrow="Step 1"
            :status="
                snapshot.configuration.mapping_hash
                    ? 'Package active'
                    : 'Not yet loaded'
            "
            :tone="snapshot.configuration.mapping_hash ? 'complete' : 'warning'"
        >
            <div class="grid gap-5 lg:grid-cols-[1fr_auto] lg:items-end">
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-stone-500">Clustered precinct</dt>
                        <dd class="mt-1 font-bold text-stone-950">
                            {{
                                snapshot.configuration.precinct_id ||
                                configuredPrecinct.clustered_precinct
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">District</dt>
                        <dd class="mt-1 font-bold text-stone-950">
                            {{ activationEvidence.district || configuredPrecinct.district }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Source files</dt>
                        <dd class="mt-1 font-bold text-stone-950">
                            {{ configuredPrecinct.pop_filename }} + {{ configuredPrecinct.clc_source }}
                        </dd>
                    </div>
                    <div v-if="activationEvidence.location?.polling_place" class="sm:col-span-2">
                        <dt class="text-stone-500">Polling place</dt>
                        <dd class="mt-1 font-bold text-stone-950">
                            {{ activationEvidence.location.polling_place }},
                            {{ activationEvidence.location.barangay }},
                            {{ activationEvidence.location.city_municipality }}
                        </dd>
                    </div>
                    <div v-if="activationEvidence.contest_count">
                        <dt class="text-stone-500">Ballot contests</dt>
                        <dd class="mt-1 font-bold text-stone-950">
                            {{ activationEvidence.contest_count }}
                        </dd>
                    </div>
                    <div v-if="activationEvidence.candidate_count">
                        <dt class="text-stone-500">Candidates</dt>
                        <dd class="mt-1 font-bold text-stone-950">
                            {{ activationEvidence.candidate_count }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Ballot style</dt>
                        <dd class="mt-1 font-bold text-stone-950">
                            {{
                                snapshot.configuration.ballot_style_id ||
                                'Pending activation'
                            }}
                        </dd>
                    </div>
                    <div
                        v-if="snapshot.configuration.mapping_hash"
                        class="sm:col-span-2"
                    >
                        <dt class="text-stone-500">
                            Deterministic mapping hash
                        </dt>
                        <dd
                            class="mt-1 font-mono text-xs break-all text-stone-700"
                        >
                            {{ snapshot.configuration.mapping_hash }}
                        </dd>
                    </div>
                </dl>
                <Form v-bind="activate.form()" #default="{ processing }">
                    <button
                        class="min-h-11 bg-blue-800 px-5 py-3 text-sm font-bold text-white disabled:opacity-50"
                        type="submit"
                        :disabled="processing"
                    >
                        {{
                            processing
                                ? 'Loading precinct...'
                                : snapshot.configuration.mapping_hash
                                  ? 'Verify precinct package again'
                                  : 'Import and activate precinct'
                        }}
                    </button>
                </Form>
            </div>
        </CeremonyActionPanel>

        <CeremonyActionPanel
            title="Opening readiness checklist"
            description="Complete and preserve each baseline before final testing and sealing."
            eyebrow="Step 2"
        >
            <ol class="divide-y divide-stone-200 border border-stone-200">
                <li
                    v-for="(item, index) in readinessItems"
                    :key="item.label"
                    class="flex items-center justify-between gap-4 px-4 py-3"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <span
                            class="flex h-7 w-7 shrink-0 items-center justify-center border border-stone-300 bg-stone-50 text-xs font-bold"
                        >
                            {{ index + 1 }}
                        </span>
                        <span class="text-sm font-semibold text-stone-900">
                            {{ item.label }}
                        </span>
                    </div>
                    <StatusBadge
                        :label="item.ready() ? 'Ready' : 'Pending'"
                        :tone="item.ready() ? 'complete' : 'warning'"
                    />
                </li>
            </ol>
        </CeremonyActionPanel>

        <div class="grid gap-4 lg:grid-cols-2">
            <CeremonyActionPanel
                title="Electoral Board roster"
                description="Record the required Chairperson, Poll Clerk, and Third Member roles."
                :status="
                    electoralBoardBaseline.exists
                        ? electoralBoardBaseline.passed
                            ? 'Ready'
                            : 'Incomplete'
                        : 'Pending'
                "
                :tone="electoralBoardBaseline.passed ? 'complete' : 'warning'"
            >
                <Form v-bind="ebRoleBaseline.form()" #default="{ processing }">
                    <button
                        class="secondary-button"
                        type="submit"
                        :disabled="processing"
                    >
                        Record EB roster baseline
                    </button>
                </Form>
                <dl
                    v-if="electoralBoardBaseline.exists"
                    class="mt-4 grid grid-cols-2 gap-3 text-sm"
                >
                    <div>
                        <dt class="text-stone-500">Required roles</dt>
                        <dd class="mt-1 font-bold">
                            {{
                                electoralBoardBaseline.required_roles_present
                            }}/{{ electoralBoardBaseline.required_role_count }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Missing</dt>
                        <dd class="mt-1 font-bold">
                            {{
                                electoralBoardBaseline.missing_required_role_count
                            }}
                        </dd>
                    </div>
                </dl>
            </CeremonyActionPanel>

            <CeremonyActionPanel
                title="Election supplies"
                description="Check that the required physical and supporting supplies are present."
                :status="
                    supplyVerificationBaseline.exists
                        ? supplyVerificationBaseline.passed
                            ? 'Ready'
                            : 'Incomplete'
                        : 'Pending'
                "
                :tone="
                    supplyVerificationBaseline.passed ? 'complete' : 'warning'
                "
            >
                <Form
                    v-bind="supplyVerificationBaselineAction.form()"
                    #default="{ processing }"
                >
                    <button
                        class="secondary-button"
                        type="submit"
                        :disabled="processing"
                    >
                        Verify election supplies
                    </button>
                </Form>
                <dl
                    v-if="supplyVerificationBaseline.exists"
                    class="mt-4 grid grid-cols-2 gap-3 text-sm"
                >
                    <div>
                        <dt class="text-stone-500">Required present</dt>
                        <dd class="mt-1 font-bold">
                            {{
                                supplyVerificationBaseline.required_supplies_present
                            }}/{{
                                supplyVerificationBaseline.required_supply_count
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Missing</dt>
                        <dd class="mt-1 font-bold">
                            {{
                                supplyVerificationBaseline.required_supply_missing_count
                            }}
                        </dd>
                    </div>
                </dl>
            </CeremonyActionPanel>
        </div>

        <CeremonyActionPanel
            title="Lifecycle rehearsal"
            description="Run the deterministic legal scenario suite and preserve its report before operational use."
            :status="
                legalScenarioSuite.exists
                    ? legalScenarioSuite.passed
                        ? 'Passed'
                        : 'Needs review'
                    : 'Not run'
            "
            :tone="legalScenarioSuite.passed ? 'complete' : 'neutral'"
        >
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="text-sm text-stone-700">
                    <p v-if="legalScenarioSuite.exists">
                        Run {{ legalScenarioSuite.run_id }} completed
                        {{ legalScenarioSuite.sub_scenarios?.length ?? 0 }}
                        sub-scenarios.
                    </p>
                    <p v-else>
                        No precinct lifecycle rehearsal has been recorded.
                    </p>
                </div>
                <Form
                    v-bind="legalScenarioSuiteAction.form()"
                    #default="{ processing }"
                >
                    <button
                        class="secondary-button"
                        type="submit"
                        :disabled="processing"
                    >
                        Run lifecycle rehearsal
                    </button>
                </Form>
            </div>
            <ArtifactLinks
                v-if="legalScenarioSuite.report_path"
                class="mt-4"
                :artifacts="[
                    {
                        label: 'Lifecycle rehearsal report',
                        path: legalScenarioSuite.report_path,
                        detail: legalScenarioSuite.generated_at ?? undefined,
                    },
                ]"
            />
        </CeremonyActionPanel>
    </CeremonyLayout>
</template>

<style scoped>
.secondary-button {
    min-height: 2.75rem;
    border: 1px solid rgb(87 83 78);
    background: white;
    padding: 0.7rem 1rem;
    color: rgb(28 25 23);
    font-size: 0.875rem;
    font-weight: 700;
}

.secondary-button:disabled {
    cursor: not-allowed;
    opacity: 0.5;
}
</style>
