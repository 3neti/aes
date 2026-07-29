<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import CeremonyActionPanel from '@/components/election/CeremonyActionPanel.vue';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import { counting, printStation, voter, watchers } from '@/routes/election';
import {
    closePolls,
    openPolls,
    specialPollingIntake as specialPollingIntakeRoute,
} from '@/routes/election/voting';
import { issue as issueVoterAuthorization } from '@/routes/election/voting/voter-authorizations';
import { useElectionReview } from '@/stores/electionReview';

type SpecialPollingIntake = {
    exists?: boolean;
    entry_count?: number;
    total_ballots?: number;
    latest_entry_hash?: string;
    artifact?: string;
    totals_by_type?: Record<string, number>;
};

defineProps<{
    snapshot: ElectionSnapshot;
    specialPollingIntake: SpecialPollingIntake;
    voterAuthorization?: {
        code: string;
        expires_at: string;
    };
    ballotBox: { deposited_ballots: number };
}>();

const { review: electionReview, defaults: reviewDefaults } =
    useElectionReview();

const canOpenPolls = (stage: string): boolean =>
    stage === 'open_precinct' || stage === 'open_polls';
const canFinalize = (stage: string): boolean => stage === 'voting';
const canClosePolls = (stage: string): boolean => stage === 'voting';
const canRecordSpecialPolling = (stage: string): boolean =>
    stage === 'voting' || stage === 'close_polls';

const specialPollingTypes = [
    { value: 'ppp', label: 'PPP / S-PPP' },
    { value: 'pdl', label: 'PDL / PPD' },
    { value: 'ip', label: 'Indigenous Peoples' },
];
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Voting">
        <CeremonyActionPanel
            v-if="canOpenPolls(snapshot.stage)"
            :title="
                snapshot.stage === 'open_polls'
                    ? 'Begin active voting'
                    : 'Open the polls'
            "
            :description="
                snapshot.stage === 'open_polls'
                    ? 'The precinct initialization is recorded. The Chairperson must now authorize active voting.'
                    : 'The Election Board Chairperson must confirm the precinct initialization report before voting begins.'
            "
            eyebrow="Opening ceremony"
            :status="
                snapshot.stage === 'open_polls'
                    ? 'Final authorization required'
                    : 'Officer confirmation required'
            "
            tone="warning"
            recommended
        >
            <Form
                v-bind="openPolls.form()"
                #default="{ errors, processing }"
                class="grid gap-4 sm:grid-cols-2"
            >
                <label class="block">
                    <span class="text-sm font-bold text-stone-700">
                        Chairperson officer ID
                    </span>
                    <input
                        class="mt-1 block min-h-11 w-full border border-stone-300 bg-white px-3 py-2 text-sm"
                        name="officer_code"
                        required
                        type="text"
                        autocomplete="off"
                        :value="reviewDefaults.primary_officer?.code ?? ''"
                    />
                    <span
                        v-if="errors.officer_code"
                        class="mt-1 block text-sm font-semibold text-red-700"
                    >
                        {{ errors.officer_code }}
                    </span>
                </label>
                <label class="block">
                    <span class="text-sm font-bold text-stone-700">
                        Officer PIN
                    </span>
                    <input
                        class="mt-1 block min-h-11 w-full border border-stone-300 bg-white px-3 py-2 text-sm"
                        name="officer_pin"
                        required
                        type="password"
                        inputmode="numeric"
                        autocomplete="off"
                        :value="reviewDefaults.primary_officer?.pin ?? ''"
                    />
                    <span
                        v-if="errors.officer_pin"
                        class="mt-1 block text-sm font-semibold text-red-700"
                    >
                        {{ errors.officer_pin }}
                    </span>
                </label>
                <div
                    class="flex flex-col gap-2 sm:col-span-2 sm:flex-row sm:items-center sm:justify-between"
                >
                    <p class="text-sm text-stone-600">
                        {{
                            snapshot.stage === 'open_polls'
                                ? 'This action records final opening authorization and enables ballot preparation.'
                                : 'This action records the opening officer and prepares the appliance for active voting.'
                        }}
                    </p>
                    <button
                        class="primary-button shrink-0"
                        :class="{
                            'review-next-action-button': electionReview.enabled,
                        }"
                        type="submit"
                        :disabled="processing"
                    >
                        {{
                            processing
                                ? 'Recording authorization...'
                                : snapshot.stage === 'open_polls'
                                  ? 'Begin voting'
                                  : 'Open polls'
                        }}
                    </button>
                </div>
                <p
                    v-if="errors.lifecycle"
                    class="text-sm font-bold text-red-700 sm:col-span-2"
                >
                    {{ errors.lifecycle }}
                </p>
            </Form>
        </CeremonyActionPanel>

        <CeremonyActionPanel
            v-if="snapshot.paperBallots.total_stock > 0"
            title="Paper ballot stock"
            description="Physical stock accounting for this precinct run."
            :status="
                snapshot.paperBallots.balanced
                    ? 'Accounted'
                    : 'Disposition pending'
            "
            :tone="snapshot.paperBallots.balanced ? 'complete' : 'warning'"
        >
            <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-5">
                <div>
                    <dt class="text-stone-500">Issued</dt>
                    <dd class="mt-1 text-xl font-bold">
                        {{ snapshot.paperBallots.issued }}
                    </dd>
                </div>
                <div>
                    <dt class="text-stone-500">Printed</dt>
                    <dd class="mt-1 text-xl font-bold">
                        {{ snapshot.paperBallots.printed }}
                    </dd>
                </div>
                <div>
                    <dt class="text-stone-500">Spoiled</dt>
                    <dd class="mt-1 text-xl font-bold">
                        {{ snapshot.paperBallots.spoiled }}
                    </dd>
                </div>
                <div>
                    <dt class="text-stone-500">Deposited</dt>
                    <dd class="mt-1 text-xl font-bold">
                        {{ snapshot.paperBallots.deposited }}
                    </dd>
                </div>
                <div>
                    <dt class="text-stone-500">Unused</dt>
                    <dd class="mt-1 text-xl font-bold">
                        {{ snapshot.paperBallots.unused }}
                    </dd>
                </div>
            </dl>
        </CeremonyActionPanel>

        <CeremonyActionPanel
            v-if="canFinalize(snapshot.stage)"
            title="Admit the next voter"
            description="After checking the voter against the official roster, issue one anonymous voting code. No voter identity is stored by the appliance."
            eyebrow="Voting session"
            :status="
                voterAuthorization?.code
                    ? 'Code ready'
                    : 'Officer authorization required'
            "
            :tone="voterAuthorization?.code ? 'complete' : 'warning'"
            :recommended="
                !voterAuthorization?.code && ballotBox.deposited_ballots === 0
            "
        >
            <div
                v-if="voterAuthorization?.code"
                class="border-l-4 border-emerald-700 bg-emerald-50 p-4"
            >
                <p class="text-sm font-bold text-emerald-900">
                    One-time voting code
                </p>
                <p class="mt-1 font-mono text-3xl font-bold text-emerald-950">
                    {{ voterAuthorization.code }}
                </p>
                <p class="mt-2 text-sm text-emerald-900">
                    Give this code only to the admitted voter. It can be claimed
                    once.
                </p>
            </div>
            <Form
                v-else
                v-bind="issueVoterAuthorization.form()"
                #default="{ errors, processing }"
                class="grid gap-3 sm:grid-cols-2"
            >
                <label>
                    <span class="text-sm font-bold">Officer ID</span>
                    <input
                        class="mt-1 min-h-11 w-full border border-stone-300 px-3"
                        name="officer_code"
                        required
                        autocomplete="off"
                        :value="reviewDefaults.primary_officer?.code ?? ''"
                    />
                </label>
                <label>
                    <span class="text-sm font-bold">Officer PIN</span>
                    <input
                        class="mt-1 min-h-11 w-full border border-stone-300 px-3"
                        name="officer_pin"
                        type="password"
                        inputmode="numeric"
                        required
                        autocomplete="off"
                        :value="reviewDefaults.primary_officer?.pin ?? ''"
                    />
                </label>
                <p
                    v-if="errors.officer_pin || errors.lifecycle"
                    class="text-sm font-bold text-red-700 sm:col-span-2"
                >
                    {{ errors.officer_pin || errors.lifecycle }}
                </p>
                <button
                    class="primary-button sm:col-span-2"
                    :class="{
                        'review-next-action-button':
                            electionReview.enabled &&
                            ballotBox.deposited_ballots === 0,
                    }"
                    type="submit"
                    :disabled="processing"
                >
                    {{
                        processing
                            ? 'Issuing code...'
                            : 'Issue anonymous voting code'
                    }}
                </button>
            </Form>
            <div class="mt-4 flex flex-wrap gap-3">
                <Link
                    :href="voter.url()"
                    class="secondary-button inline-flex items-center justify-center"
                    >Open voter tablet</Link
                >
                <Link
                    :href="printStation.url()"
                    class="secondary-button inline-flex items-center justify-center"
                    >Open private print station</Link
                >
                <Link
                    :href="watchers.url()"
                    class="secondary-button inline-flex items-center justify-center"
                    >Open watcher view</Link
                >
            </div>
            <p class="mt-4 text-sm text-stone-600">
                Sealed paper ballots deposited:
                <strong>{{ ballotBox.deposited_ballots }}</strong>
            </p>
        </CeremonyActionPanel>

        <div
            v-if="canRecordSpecialPolling(snapshot.stage)"
            class="grid gap-4 lg:grid-cols-2"
        >
            <CeremonyActionPanel
                title="Special polling intake"
                description="Record ballots formally received from an authorized special polling process."
                status="As needed"
                tone="neutral"
            >
                <Form
                    v-bind="specialPollingIntakeRoute.form()"
                    #default="{ errors, processing }"
                    class="space-y-3"
                >
                    <input name="stage" :value="snapshot.stage" type="hidden" />
                    <label class="block">
                        <span class="text-sm font-bold text-stone-700">
                            Polling type
                        </span>
                        <select
                            class="mt-1 block min-h-11 w-full border border-stone-300 px-3 py-2 text-sm"
                            name="intake_type"
                            required
                        >
                            <option value="" disabled selected>
                                Choose polling type
                            </option>
                            <option
                                v-for="type in specialPollingTypes"
                                :key="type.value"
                                :value="type.value"
                            >
                                {{ type.label }}
                            </option>
                        </select>
                        <span
                            v-if="errors.intake_type"
                            class="mt-1 block text-sm font-bold text-red-700"
                        >
                            {{ errors.intake_type }}
                        </span>
                    </label>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-bold text-stone-700">
                                Ballot count
                            </span>
                            <input
                                class="mt-1 block min-h-11 w-full border border-stone-300 px-3 py-2 text-sm"
                                name="ballot_count"
                                required
                                type="number"
                                min="1"
                                max="2000"
                            />
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-stone-700">
                                Received from
                            </span>
                            <input
                                class="mt-1 block min-h-11 w-full border border-stone-300 px-3 py-2 text-sm"
                                name="received_from"
                                required
                                type="text"
                                autocomplete="off"
                            />
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-stone-700">
                                Received by
                            </span>
                            <input
                                class="mt-1 block min-h-11 w-full border border-stone-300 px-3 py-2 text-sm"
                                name="received_by"
                                type="text"
                                autocomplete="off"
                            />
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-stone-700">
                                Notes
                            </span>
                            <input
                                class="mt-1 block min-h-11 w-full border border-stone-300 px-3 py-2 text-sm"
                                name="notes"
                                type="text"
                                autocomplete="off"
                            />
                        </label>
                    </div>
                    <p
                        v-if="
                            errors.ballot_count ||
                            errors.received_from ||
                            errors.stage
                        "
                        class="text-sm font-bold text-red-700"
                    >
                        {{
                            errors.ballot_count ||
                            errors.received_from ||
                            errors.stage
                        }}
                    </p>
                    <button
                        class="secondary-button"
                        type="submit"
                        :disabled="processing"
                    >
                        Record special polling intake
                    </button>
                </Form>
            </CeremonyActionPanel>

            <CeremonyActionPanel
                title="Special polling summary"
                description="Running total of separately received ballots for this precinct."
                :status="specialPollingIntake.exists ? 'Recorded' : 'No intake'"
                :tone="specialPollingIntake.exists ? 'complete' : 'neutral'"
            >
                <dl
                    v-if="specialPollingIntake.exists"
                    class="grid gap-4 text-sm sm:grid-cols-2"
                >
                    <div>
                        <dt class="text-stone-500">Intake entries</dt>
                        <dd class="mt-1 text-2xl font-bold">
                            {{ specialPollingIntake.entry_count }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Special ballots</dt>
                        <dd class="mt-1 text-2xl font-bold">
                            {{ specialPollingIntake.total_ballots }}
                        </dd>
                    </div>
                    <div
                        v-for="(
                            count, type
                        ) in specialPollingIntake.totals_by_type ?? {}"
                        :key="type"
                    >
                        <dt class="text-stone-500">
                            {{ type.toUpperCase() }}
                        </dt>
                        <dd class="mt-1 font-bold">{{ count }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-stone-500">Evidence artifact</dt>
                        <dd class="mt-1 font-mono text-xs break-all">
                            {{ specialPollingIntake.artifact }}
                        </dd>
                    </div>
                </dl>
                <p v-else class="text-sm text-stone-600">
                    No special polling ballots have been received.
                </p>
            </CeremonyActionPanel>
        </div>

        <CeremonyActionPanel
            v-if="canClosePolls(snapshot.stage)"
            title="Close the polls"
            description="Use only after the legally prescribed closing time and after all voters already in line have voted."
            eyebrow="Closing ceremony"
            status="Irreversible stage change"
            tone="danger"
            :recommended="ballotBox.deposited_ballots > 0"
        >
            <Form
                v-bind="closePolls.form()"
                #default="{ errors, processing }"
                class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="max-w-2xl text-sm text-stone-700">
                    Closing polls ends ballot preparation and advances directly
                    to counting. Confirm the physical ballot box and precinct
                    records are ready.
                </p>
                <div class="shrink-0">
                    <button
                        class="danger-button"
                        :class="{
                            'review-next-action-button':
                                electionReview.enabled &&
                                ballotBox.deposited_ballots > 0,
                        }"
                        type="submit"
                        :disabled="processing"
                    >
                        {{ processing ? 'Closing polls...' : 'Close polls' }}
                    </button>
                    <p
                        v-if="errors.lifecycle"
                        class="mt-2 max-w-xs text-sm font-bold text-red-700"
                    >
                        {{ errors.lifecycle }}
                    </p>
                </div>
            </Form>
        </CeremonyActionPanel>

        <div
            v-if="snapshot.stage === 'close_polls'"
            class="border border-blue-300 bg-blue-50 p-5"
        >
            <p class="font-bold text-blue-950">Polls are closed.</p>
            <p class="mt-1 text-sm text-blue-900">
                Continue to the Counting and Tally ceremony with the sealed
                physical ballot box.
            </p>
            <Link
                :href="counting.url()"
                class="mt-4 inline-flex min-h-11 items-center justify-center bg-blue-800 px-5 py-3 text-sm font-bold text-white"
                :class="{
                    'review-next-action-button': electionReview.enabled,
                }"
            >
                Continue to Counting and Tally
            </Link>
        </div>

        <div
            v-if="
                !canOpenPolls(snapshot.stage) &&
                !canFinalize(snapshot.stage) &&
                snapshot.stage !== 'close_polls'
            "
            class="border border-stone-300 bg-white p-5 text-sm text-stone-700"
        >
            Voting actions are unavailable at the current lifecycle stage.
            Follow the highlighted ceremony in the precinct run sequence.
        </div>
    </CeremonyLayout>
</template>

<style scoped>
.primary-button,
.secondary-button,
.danger-button {
    min-height: 2.75rem;
    padding: 0.7rem 1rem;
    font-size: 0.875rem;
    font-weight: 700;
}

.primary-button {
    background: rgb(30 64 175);
    color: white;
}

.secondary-button {
    border: 1px solid rgb(87 83 78);
    background: white;
    color: rgb(28 25 23);
}

.danger-button {
    background: rgb(185 28 28);
    color: white;
}

.primary-button:disabled,
.secondary-button:disabled,
.danger-button:disabled {
    cursor: not-allowed;
    opacity: 0.5;
}
</style>
