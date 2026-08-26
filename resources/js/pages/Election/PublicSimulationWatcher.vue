<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BallotPagination from '@/components/election/BallotPagination.vue';
import TallyMarks from '@/components/election/TallyMarks.vue';

type Tally = Record<string, Record<string, number>>;

type ReviewBallot = {
    sequence: number;
    ballot_id: string;
    paper_ballot_serial: string | null;
    payload_hash: string;
    record_hash: string;
    deposited_at: string | null;
    qr_decode_status: string;
    qr_payload_hash: string | null;
    selected_candidates: Array<{
        contest_id: string;
        contest_title: string;
        candidates: Array<{ id: string; name: string }>;
    }>;
    this_ballot_tally: Tally;
    cumulative_tally: Tally;
    pdf_available: boolean;
    pdf_url: string | null;
};

const props = defineProps<{
    precinct: {
        label: string;
        code: string;
        status: string;
        accepted_ballots: number | null;
        tally: Tally;
        display_tally: Tally;
    };
    ballot: {
        contests: Array<{
            id: string;
            title: string;
            candidates: Array<{ id: string; name: string }>;
        }>;
    };
    published: boolean;
    demoTransparencyMode: boolean;
    ballotReview: {
        enabled: boolean;
        allowed: boolean;
        download_enabled: boolean;
        qr_audit_tally_enabled: boolean;
        record_count: number;
        ballots: ReviewBallot[];
        qr_audit_tally: Tally;
    };
    auditExportAvailable: boolean;
    randomManualAudit:
        | {
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
          }
        | Record<string, never>;
    publication: { manifest_hash: string | null; ledger_root: string | null };
    downloads: {
        tally: string;
        return: string;
        vvdat_audit_export: string;
        random_manual_audit: string;
    };
}>();

usePoll(5000, {
    only: ['precinct', 'published', 'demoTransparencyMode', 'ballotReview'],
});

const selectedIndex = ref(0);

const selectedBallot = computed<ReviewBallot | null>(() => {
    if (props.ballotReview.ballots.length === 0) {
        return null;
    }

    return (
        props.ballotReview.ballots[
            Math.min(selectedIndex.value, props.ballotReview.ballots.length - 1)
        ] ?? null
    );
});

const aggregateTally = computed<Tally>(() =>
    props.published
        ? props.precinct.display_tally
        : props.ballotReview.qr_audit_tally,
);

function rmaPublished(): boolean {
    return Object.keys(props.randomManualAudit).length > 0;
}

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

function selectBallot(index: number): void {
    selectedIndex.value = Math.min(
        Math.max(index, 0),
        Math.max(props.ballotReview.ballots.length - 1, 0),
    );
}
</script>

<template>
    <main class="min-h-screen bg-stone-100 p-5 text-stone-950">
        <Head :title="`${precinct.code} watcher view`" />
        <section class="mx-auto max-w-5xl border border-stone-300 bg-white p-6">
            <Link href="/election/play" class="text-sm font-bold text-blue-800"
                >All precincts</Link
            >
            <p class="mt-4 text-sm font-bold text-blue-800">
                POLL WATCHER VIEW
            </p>
            <h1 class="mt-1 text-2xl font-bold">{{ precinct.label }}</h1>
            <template v-if="published">
                <p class="mt-3 text-stone-700">
                    {{ precinct.accepted_ballots }} sealed VVDAT records have
                    been tabulated after precinct close.
                </p>
                <p class="mt-2 text-xs text-stone-600">
                    Publication {{ publication.manifest_hash?.slice(0, 16) }} ·
                    VVDAT ledger {{ publication.ledger_root?.slice(0, 16) }}
                </p>
                <div class="mt-5 flex flex-wrap gap-3">
                    <a :href="downloads.tally" class="secondary-button"
                        >Download tally sheet PDF</a
                    ><a :href="downloads.return" class="secondary-button"
                        >Download Election Return PDF</a
                    ><a
                        v-if="auditExportAvailable"
                        :href="downloads.vvdat_audit_export"
                        class="secondary-button"
                        >Download anonymized VVDAT export</a
                    ><a
                        v-if="rmaPublished()"
                        :href="downloads.random_manual_audit"
                        class="secondary-button"
                        >Download RMA summary PDF</a
                    >
                </div>
                <p
                    v-if="!auditExportAvailable"
                    class="mt-3 text-sm text-stone-600"
                >
                    The anonymized VVDAT export remains withheld under this
                    simulation's publication policy.
                </p>
                <section
                    v-if="rmaPublished()"
                    class="mt-6 border border-stone-300"
                >
                    <h2
                        class="border-b border-stone-200 bg-stone-50 px-4 py-3 font-bold"
                    >
                        Published Random Manual Audit
                    </h2>
                    <div class="grid gap-3 p-4 text-sm sm:grid-cols-3">
                        <p>
                            <strong>Sample</strong><br />{{
                                randomManualAudit.sample_size
                            }}
                            of {{ randomManualAudit.source_record_count }}
                        </p>
                        <p>
                            <strong>Verified</strong><br />{{
                                randomManualAudit.verified_ballots
                            }}
                        </p>
                        <p>
                            <strong>Discrepancies</strong><br />{{
                                randomManualAudit.discrepancy_ballots
                            }}
                        </p>
                        <p class="sm:col-span-3">
                            <strong>{{
                                randomManualAudit.passed
                                    ? 'Verified'
                                    : 'Attention required'
                            }}</strong>
                            · {{ randomManualAudit.privacy_notice }}
                        </p>
                        <p
                            class="font-mono text-xs text-stone-600 sm:col-span-3"
                        >
                            Audit summary
                            {{ randomManualAudit.summary_hash.slice(0, 16) }}
                        </p>
                    </div>
                </section>
            </template>
            <template v-else>
                <p
                    class="mt-5 border-l-4 border-amber-500 bg-amber-50 p-4 text-amber-950"
                >
                    This precinct has not yet published post-close results.
                    Totals remain sealed until the Election Officer approves the
                    watcher package.
                </p>
            </template>

            <section
                v-if="demoTransparencyMode"
                class="mt-5 border-l-4 border-blue-500 bg-blue-50 p-4 text-sm text-blue-950"
            >
                <strong>Demo Transparency Mode.</strong> Individual ballot
                review is enabled before precinct close for demonstration only.
                For election posture, this viewer is locked until after close.
            </section>

            <section class="mt-6 border border-stone-300">
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 bg-stone-50 px-4 py-3"
                >
                    <div>
                        <h2 class="font-bold">Ballot review viewer</h2>
                        <p class="text-sm text-stone-600">
                            QR-derived audit trail of deposited ballot
                            artifacts.
                        </p>
                    </div>
                    <strong class="text-sm text-stone-700"
                        >{{ ballotReview.record_count }} ballots</strong
                    >
                </div>

                <div
                    v-if="!ballotReview.enabled"
                    class="p-5 text-sm text-stone-600"
                >
                    Ballot review viewer is disabled by precinct policy.
                </div>
                <div
                    v-else-if="!ballotReview.allowed"
                    class="p-5 text-sm text-stone-600"
                >
                    Individual ballot review is locked until the precinct is
                    closed.
                </div>
                <div
                    v-else-if="!selectedBallot"
                    class="p-5 text-sm text-stone-600"
                >
                    No deposited ballot artifacts are available yet.
                </div>
                <div v-else class="grid gap-0 lg:grid-cols-[minmax(0,1.4fr)_420px]">
                    <div class="border-b border-stone-200 lg:border-r lg:border-b-0">
                        <div
                            class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-4 py-3"
                        >
                            <div>
                                <p class="text-xs font-bold text-blue-800">
                                    BALLOT
                                    {{ selectedBallot.sequence }} OF
                                    {{ ballotReview.record_count }}
                                </p>
                                <h3 class="font-bold">
                                    {{
                                        selectedBallot.paper_ballot_serial ??
                                        selectedBallot.ballot_id
                                    }}
                                </h3>
                            </div>
                        </div>
                        <iframe
                            v-if="selectedBallot.pdf_url"
                            :src="selectedBallot.pdf_url"
                            title="Rendered ballot artifact"
                            class="h-[720px] w-full bg-stone-200"
                        ></iframe>
                        <div v-else class="p-6 text-sm text-stone-600">
                            Rendered ballot PDF is not available for this
                            deposited record.
                        </div>
                        <BallotPagination
                            :selected-index="selectedIndex"
                            :total="ballotReview.ballots.length"
                            @update:selected-index="selectBallot"
                        />
                    </div>

                    <aside class="p-4">
                        <div class="rounded border border-stone-300 p-3 text-xs">
                            <p>
                                <strong>QR decode</strong>:
                                {{ selectedBallot.qr_decode_status }}
                            </p>
                            <p class="mt-1 font-mono text-stone-600">
                                Payload
                                {{
                                    selectedBallot.qr_payload_hash?.slice(
                                        0,
                                        16,
                                    ) ?? selectedBallot.payload_hash.slice(0, 16)
                                }}
                            </p>
                            <p class="mt-1 font-mono text-stone-600">
                                Record {{ selectedBallot.record_hash.slice(0, 16) }}
                            </p>
                        </div>

                        <h3 class="mt-5 font-bold">This ballot added</h3>
                        <div
                            v-for="contest in selectedBallot.selected_candidates"
                            :key="contest.contest_id"
                            class="mt-3 border border-stone-200"
                        >
                            <p class="bg-stone-50 px-3 py-2 text-sm font-bold">
                                {{ contest.contest_title }}
                            </p>
                            <ul class="divide-y divide-stone-100 text-sm">
                                <li
                                    v-for="candidate in contest.candidates"
                                    :key="candidate.id"
                                    class="px-3 py-2"
                                >
                                    {{ candidate.name }}
                                </li>
                            </ul>
                        </div>

                        <h3 class="mt-6 font-bold">
                            Running QR audit tally at this ballot
                        </h3>
                        <section
                            v-for="(candidates, contest) in selectedBallot.cumulative_tally"
                            :key="contest"
                            class="mt-3 border border-stone-200"
                        >
                            <h4 class="bg-stone-50 px-3 py-2 text-sm font-bold">
                                {{ contestTitle(String(contest)) }}
                            </h4>
                            <div
                                v-for="(votes, candidate) in candidates"
                                :key="candidate"
                                class="grid grid-cols-[minmax(0,1fr)_120px_auto] items-center gap-3 border-t border-stone-100 px-3 py-2 text-xs"
                            >
                                <span>{{
                                    candidateName(
                                        String(contest),
                                        String(candidate),
                                    )
                                }}</span>
                                <TallyMarks :count="Number(votes)" />
                                <strong>{{ votes }}</strong>
                            </div>
                        </section>
                    </aside>
                </div>
            </section>

            <template v-if="Object.keys(aggregateTally).length > 0">
                <section
                    v-for="(candidates, contest) in aggregateTally"
                    :key="contest"
                    class="mt-6 border border-stone-300"
                >
                    <h2
                        class="border-b border-stone-200 bg-stone-50 px-4 py-3 font-bold"
                    >
                        {{ contestTitle(String(contest)) }}
                    </h2>
                    <template v-if="Object.keys(candidates).length > 0">
                        <div
                            v-for="(votes, candidate) in candidates"
                            :key="candidate"
                            class="grid grid-cols-[minmax(0,1fr)_minmax(180px,1.3fr)_auto] items-center gap-4 border-b border-stone-100 px-4 py-3 text-sm"
                        >
                            <span>{{
                                candidateName(
                                    String(contest),
                                    String(candidate),
                                )
                            }}</span
                            ><TallyMarks :count="Number(votes)" /><strong
                                class="text-sm font-semibold text-stone-600"
                                >{{ votes }}</strong
                            >
                        </div>
                    </template>
                    <p
                        v-else
                        class="px-4 py-5 text-sm font-semibold text-stone-600"
                    >
                        No votes recorded for this contest.
                    </p>
                </section>
            </template>
        </section>
    </main>
</template>
