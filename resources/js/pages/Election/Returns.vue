<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import ArtifactLinks from '@/components/election/ArtifactLinks.vue';
import CeremonyActionPanel from '@/components/election/CeremonyActionPanel.vue';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import StatusBadge from '@/components/election/StatusBadge.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import { transmission } from '@/routes/election';
import {
    approve,
    close,
    copyDistribution,
    generate,
} from '@/routes/election/returns';
import { useElectionReview } from '@/stores/electionReview';

type ReturnArtifact = {
    return_hash?: string;
    precinct_id?: string;
    accepted_ballots?: number;
    rejected_ballots?: number;
    generated_at?: string;
    tally?: Record<string, Record<string, number>>;
};

type ReturnCopyDistribution = {
    exists?: boolean;
    distribution_hash?: string;
    copy_count?: number;
    required_copy_count?: number;
    artifact?: string;
    posting?: {
        location?: string;
        status?: string;
    };
};

type ReturnLegalEvidence = {
    exists?: boolean;
    evidence_hash?: string;
    counts_match?: boolean;
    artifact?: string;
};

const props = defineProps<{
    snapshot: ElectionSnapshot;
    returnArtifact: ReturnArtifact;
    returnCopyDistribution: ReturnCopyDistribution;
    electionReturnLegalEvidence: ReturnLegalEvidence;
    returnApproval: {
        passed?: boolean;
        approval_hash?: string;
        approvers?: Array<{ name: string; role: string }>;
    };
}>();

const { defaults: reviewDefaults } = useElectionReview();

function contestTitle(contestId: string): string {
    return (
        props.snapshot.configuration.contests?.find(
            (contest) => contest.id === contestId,
        )?.title ?? contestId
    );
}

function candidateName(contestId: string, candidateId: string): string {
    const contest = props.snapshot.configuration.contests?.find(
        (entry) => entry.id === contestId,
    );

    return (
        contest?.candidates.find((candidate) => candidate.id === candidateId)
            ?.name ?? candidateId
    );
}
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Election Return">
        <CeremonyActionPanel
            title="Generate the Election Return"
            description="Create the precinct return from the completed tally and preserve its legal evidence record."
            eyebrow="Official precinct result"
            :status="
                returnArtifact.return_hash
                    ? 'Return generated'
                    : 'Ready to generate'
            "
            :tone="returnArtifact.return_hash ? 'complete' : 'current'"
        >
            <Form
                v-if="returnCopyDistribution.exists && !returnApproval.passed"
                v-bind="approve.form()"
                #default="{ processing, errors }"
                class="mb-5 grid gap-3 border border-stone-300 bg-stone-50 p-4 sm:grid-cols-2"
            >
                <label class="text-sm font-bold"
                    >Chairperson code<input
                        name="chairperson_code"
                        class="mt-1 min-h-11 w-full border border-stone-300 bg-white px-3"
                        :value="reviewDefaults.chairperson?.code ?? ''"
                /></label>
                <label class="text-sm font-bold"
                    >Chairperson PIN<input
                        name="chairperson_pin"
                        type="password"
                        inputmode="numeric"
                        class="mt-1 min-h-11 w-full border border-stone-300 bg-white px-3"
                        :value="reviewDefaults.chairperson?.pin ?? ''"
                /></label>
                <label class="text-sm font-bold"
                    >Poll Clerk code<input
                        name="poll_clerk_code"
                        class="mt-1 min-h-11 w-full border border-stone-300 bg-white px-3"
                        :value="reviewDefaults.poll_clerk?.code ?? ''"
                /></label>
                <label class="text-sm font-bold"
                    >Poll Clerk PIN<input
                        name="poll_clerk_pin"
                        type="password"
                        inputmode="numeric"
                        class="mt-1 min-h-11 w-full border border-stone-300 bg-white px-3"
                        :value="reviewDefaults.poll_clerk?.pin ?? ''"
                /></label>
                <p
                    v-if="Object.keys(errors).length"
                    class="text-sm font-bold text-red-700 sm:col-span-2"
                >
                    Both authorized officers must approve this exact return.
                </p>
                <button
                    class="secondary-button sm:col-span-2"
                    type="submit"
                    :disabled="processing"
                >
                    Approve Election Return
                </button>
            </Form>
            <div
                v-if="returnApproval.passed"
                class="mb-5 border-l-4 border-emerald-700 bg-emerald-50 p-4 text-sm text-emerald-950"
            >
                <p class="font-bold">Dual-control approval recorded</p>
                <p class="mt-1 font-mono text-xs break-all">
                    {{ returnApproval.approval_hash }}
                </p>
            </div>
            <div
                v-if="!returnArtifact.return_hash"
                class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <p class="text-sm font-bold text-stone-900">
                        Counting evidence received
                    </p>
                    <p class="mt-1 text-sm text-stone-600">
                        Generate the Election Return only after the Electoral
                        Board confirms the tally against the paper ballots.
                    </p>
                </div>
                <Form v-bind="generate.form()" #default="{ processing }">
                    <button
                        class="primary-button"
                        type="submit"
                        :disabled="processing"
                    >
                        {{
                            processing
                                ? 'Generating return...'
                                : 'Generate Election Return'
                        }}
                    </button>
                </Form>
            </div>

            <div v-else>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="border border-stone-200 p-4">
                        <p class="text-xs font-bold text-stone-500 uppercase">
                            Accepted ballots
                        </p>
                        <p class="mt-1 text-3xl font-bold">
                            {{ returnArtifact.accepted_ballots ?? 0 }}
                        </p>
                    </div>
                    <div class="border border-stone-200 p-4">
                        <p class="text-xs font-bold text-stone-500 uppercase">
                            Rejected ballots
                        </p>
                        <p class="mt-1 text-3xl font-bold">
                            {{ returnArtifact.rejected_ballots ?? 0 }}
                        </p>
                    </div>
                    <div
                        class="border p-4"
                        :class="
                            electionReturnLegalEvidence.counts_match
                                ? 'border-emerald-300 bg-emerald-50'
                                : 'border-amber-300 bg-amber-50'
                        "
                    >
                        <p class="text-xs font-bold text-stone-600 uppercase">
                            Count reconciliation
                        </p>
                        <p class="mt-2">
                            <StatusBadge
                                :label="
                                    electionReturnLegalEvidence.counts_match
                                        ? 'Counts match'
                                        : 'Review required'
                                "
                                :tone="
                                    electionReturnLegalEvidence.counts_match
                                        ? 'complete'
                                        : 'warning'
                                "
                            />
                        </p>
                    </div>
                </div>

                <dl class="mt-5 grid gap-3 text-sm">
                    <div>
                        <dt class="text-stone-500">Return hash</dt>
                        <dd
                            class="mt-1 font-mono text-xs break-all text-stone-700"
                        >
                            {{ returnArtifact.return_hash }}
                        </dd>
                    </div>
                    <div v-if="electionReturnLegalEvidence.evidence_hash">
                        <dt class="text-stone-500">Legal evidence hash</dt>
                        <dd
                            class="mt-1 font-mono text-xs break-all text-stone-700"
                        >
                            {{ electionReturnLegalEvidence.evidence_hash }}
                        </dd>
                    </div>
                </dl>

                <ArtifactLinks
                    class="mt-5"
                    :artifacts="[
                        {
                            label: 'Printable Election Return',
                            path: `returns/${returnArtifact.precinct_id}-return.pdf`,
                        },
                        {
                            label: 'Election Return record',
                            path: `returns/${returnArtifact.precinct_id}-return.json`,
                        },
                        ...(electionReturnLegalEvidence.artifact
                            ? [
                                  {
                                      label: 'Election Return legal evidence',
                                      path: electionReturnLegalEvidence.artifact,
                                  },
                              ]
                            : []),
                    ]"
                />
            </div>
        </CeremonyActionPanel>

        <CeremonyActionPanel
            v-if="returnArtifact.return_hash"
            title="Review the precinct totals"
            description="Read candidate names and vote totals aloud while the Electoral Board checks the printed Election Return."
            eyebrow="Public canvass"
        >
            <div class="space-y-4">
                <section
                    v-for="(totals, contest) in returnArtifact.tally ?? {}"
                    :key="contest"
                    class="border border-stone-300"
                >
                    <header
                        class="border-b border-stone-200 bg-stone-50 px-4 py-3"
                    >
                        <h3 class="font-bold">
                            {{ contestTitle(String(contest)) }}
                        </h3>
                    </header>
                    <table class="w-full table-fixed text-sm">
                        <thead>
                            <tr
                                class="border-b border-stone-200 text-left text-xs text-stone-500"
                            >
                                <th class="px-4 py-2 font-semibold">
                                    Candidate
                                </th>
                                <th
                                    class="w-24 px-4 py-2 text-right font-semibold"
                                >
                                    Votes
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200">
                            <tr
                                v-for="(votes, candidate) in totals"
                                :key="candidate"
                            >
                                <td class="px-4 py-2.5">
                                    {{
                                        candidateName(
                                            String(contest),
                                            String(candidate),
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right text-base font-bold"
                                >
                                    {{ votes }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
                <p
                    v-if="Object.keys(returnArtifact.tally ?? {}).length === 0"
                    class="border border-stone-200 bg-stone-50 p-4 text-sm text-stone-600"
                >
                    The generated artifact does not expose tally rows in this
                    view. Review the printable Election Return artifact.
                </p>
            </div>
        </CeremonyActionPanel>

        <CeremonyActionPanel
            v-if="returnArtifact.return_hash"
            title="Prepare copies and posting"
            description="Prepare the prescribed Election Return copies and record the public posting copy."
            eyebrow="Distribution"
            :status="
                returnCopyDistribution.exists
                    ? 'Copies prepared'
                    : 'Not yet prepared'
            "
            :tone="returnCopyDistribution.exists ? 'complete' : 'warning'"
        >
            <div
                class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between"
            >
                <dl
                    v-if="returnCopyDistribution.exists"
                    class="grid flex-1 gap-4 text-sm sm:grid-cols-2"
                >
                    <div>
                        <dt class="text-stone-500">Prepared copies</dt>
                        <dd class="mt-1 text-2xl font-bold">
                            {{ returnCopyDistribution.copy_count }}
                            <span class="text-sm font-normal text-stone-500">
                                of
                                {{ returnCopyDistribution.required_copy_count }}
                                required
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Public posting</dt>
                        <dd class="mt-1 font-bold">
                            {{
                                returnCopyDistribution.posting?.location ||
                                'Location pending'
                            }}
                        </dd>
                        <dd class="text-xs text-stone-600">
                            {{
                                returnCopyDistribution.posting?.status ||
                                'Status pending'
                            }}
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-stone-500">Distribution hash</dt>
                        <dd class="mt-1 font-mono text-xs break-all">
                            {{ returnCopyDistribution.distribution_hash }}
                        </dd>
                    </div>
                </dl>
                <p v-else class="max-w-xl text-sm text-stone-600">
                    No copy distribution record has been generated for this
                    Election Return.
                </p>
                <Form
                    v-bind="copyDistribution.form()"
                    #default="{ processing }"
                    class="shrink-0"
                >
                    <button
                        class="secondary-button"
                        type="submit"
                        :disabled="processing"
                    >
                        {{
                            processing
                                ? 'Preparing copies...'
                                : 'Prepare copies and posting'
                        }}
                    </button>
                </Form>
            </div>
        </CeremonyActionPanel>

        <CeremonyActionPanel
            v-if="returnArtifact.return_hash"
            title="Approve the return for official handoff"
            description="Continue only after the Election Return is printed, signed as required, copied, and posted."
            eyebrow="Ceremony completion"
            status="Electoral Board decision"
            tone="warning"
        >
            <div
                v-if="snapshot.stage === 'election_return'"
                class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="max-w-2xl text-sm text-stone-700">
                    This closes the Election Return ceremony and opens the
                    official handoff, final backup, and custody sequence. It
                    does not transmit results automatically.
                </p>
                <Form v-bind="close.form()" #default="{ processing, errors }">
                    <button
                        class="primary-button"
                        type="submit"
                        :disabled="
                            processing ||
                            !returnCopyDistribution.exists ||
                            !returnApproval.passed
                        "
                    >
                        {{
                            processing
                                ? 'Completing ceremony...'
                                : 'Continue to official handoff'
                        }}
                    </button>
                    <p
                        v-if="
                            !returnCopyDistribution.exists ||
                            !returnApproval.passed
                        "
                        class="mt-2 max-w-xs text-xs font-semibold text-amber-800"
                    >
                        Prepare the required copies and record dual approval
                        first.
                    </p>
                    <p
                        v-if="errors.approval"
                        class="mt-2 max-w-xs text-sm font-bold text-red-700"
                    >
                        {{ errors.approval }}
                    </p>
                    <p
                        v-if="errors.lifecycle"
                        class="mt-2 max-w-xs text-sm font-bold text-red-700"
                    >
                        {{ errors.lifecycle }}
                    </p>
                </Form>
            </div>
            <div v-else>
                <p class="text-sm text-stone-700">
                    The Election Return ceremony is complete.
                </p>
                <Link
                    :href="transmission.url()"
                    class="mt-4 inline-flex min-h-11 items-center justify-center bg-blue-800 px-5 py-3 text-sm font-bold text-white"
                >
                    Open official handoff
                </Link>
            </div>
        </CeremonyActionPanel>
    </CeremonyLayout>
</template>

<style scoped>
.primary-button,
.secondary-button {
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

.primary-button:disabled,
.secondary-button:disabled {
    cursor: not-allowed;
    opacity: 0.5;
}
</style>
