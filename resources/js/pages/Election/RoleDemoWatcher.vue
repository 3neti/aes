<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, usePoll } from '@inertiajs/vue3';
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
    pdf_url: string | null;
};

const props = defineProps<{
    precinct: {
        code: string;
        label: string;
        status: string;
        accepted_ballots: number;
        rejected_ballots: number;
        tally_hash: string;
        display_tally: Tally;
    };
    ballot: {
        contests: Array<{
            id: string;
            title: string;
            candidates: Array<{ id: string; name: string }>;
        }>;
    };
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
    downloads: {
        tally: string;
        return: string;
    };
}>();

usePoll(4000, { only: ['precinct', 'ballotReview'] }, { keepAlive: true });

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
    Object.keys(props.ballotReview.qr_audit_tally).length > 0
        ? props.ballotReview.qr_audit_tally
        : props.precinct.display_tally,
);

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

function previousBallot(): void {
    selectBallot(selectedIndex.value - 1);
}

function nextBallot(): void {
    selectBallot(selectedIndex.value + 1);
}
</script>

<template>
    <Head :title="`${precinct.code} role demo watcher`" />

    <main class="min-h-screen bg-stone-100 p-5 text-stone-950">
        <section class="mx-auto max-w-6xl border border-stone-300 bg-white p-6">
            <Link href="/election/role-demo" class="text-sm font-bold text-blue-800">
                Role POV room
            </Link>
            <p class="mt-4 text-sm font-bold text-blue-800">
                POLL WATCHER POV
            </p>
            <h1 class="mt-1 text-2xl font-bold">{{ precinct.label }}</h1>
            <p class="mt-3 max-w-3xl text-stone-700">
                This is the live demo tally from printed and deposited VVDAT
                records. It refreshes while the Election Officer accepts voter
                print PINs.
            </p>

            <div class="mt-5 grid gap-3 sm:grid-cols-4">
                <p class="border border-stone-200 bg-stone-50 p-4">
                    <strong class="block text-3xl">{{
                        precinct.accepted_ballots
                    }}</strong>
                    <span class="text-sm text-stone-600">accepted ballots</span>
                </p>
                <p class="border border-stone-200 bg-stone-50 p-4">
                    <strong class="block text-3xl">{{
                        precinct.rejected_ballots
                    }}</strong>
                    <span class="text-sm text-stone-600">rejected ballots</span>
                </p>
                <p class="border border-stone-200 bg-stone-50 p-4 sm:col-span-2">
                    <strong class="block font-mono text-sm">{{
                        precinct.tally_hash
                    }}</strong>
                    <span class="text-sm text-stone-600">current tally hash</span>
                </p>
            </div>

            <div class="mt-5 flex flex-wrap gap-3">
                <a :href="downloads.tally" class="secondary-button">
                    Open interim tally PDF
                </a>
                <a :href="downloads.return" class="secondary-button">
                    Open interim Election Return PDF
                </a>
            </div>

            <section
                v-if="demoTransparencyMode"
                class="mt-5 border-l-4 border-blue-500 bg-blue-50 p-4 text-sm text-blue-950"
            >
                <strong>Demo Transparency Mode.</strong> Watchers may inspect
                deposited ballot artifacts while the precinct remains open so
                reviewers can understand the flow in real time.
            </section>

            <section class="mt-6 border border-stone-300">
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 bg-stone-50 px-4 py-3"
                >
                    <div>
                        <h2 class="font-bold">Ballot review viewer</h2>
                        <p class="text-sm text-stone-600">
                            Media-style review of printed ballot artifacts with
                            QR-derived running tally.
                        </p>
                    </div>
                    <strong class="text-sm text-stone-700">
                        {{ ballotReview.record_count }} ballots
                    </strong>
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
                <div
                    v-else
                    class="grid gap-0 lg:grid-cols-[minmax(0,1.4fr)_420px]"
                >
                    <div
                        class="border-b border-stone-200 lg:border-r lg:border-b-0"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-4 py-3"
                        >
                            <div>
                                <p class="text-xs font-bold text-blue-800">
                                    BALLOT {{ selectedBallot.sequence }} OF
                                    {{ ballotReview.record_count }}
                                </p>
                                <h3 class="font-bold">
                                    {{
                                        selectedBallot.paper_ballot_serial ??
                                        selectedBallot.ballot_id
                                    }}
                                </h3>
                            </div>
                            <div class="flex gap-2">
                                <button
                                    type="button"
                                    class="secondary-button disabled:opacity-40"
                                    :disabled="selectedIndex === 0"
                                    @click="previousBallot"
                                >
                                    Previous
                                </button>
                                <button
                                    type="button"
                                    class="secondary-button disabled:opacity-40"
                                    :disabled="
                                        selectedIndex >=
                                        ballotReview.ballots.length - 1
                                    "
                                    @click="nextBallot"
                                >
                                    Next
                                </button>
                            </div>
                        </div>
                        <iframe
                            v-if="
                                selectedBallot.pdf_url &&
                                ballotReview.download_enabled
                            "
                            :src="selectedBallot.pdf_url"
                            title="Rendered ballot artifact"
                            class="h-[720px] w-full bg-stone-200"
                        ></iframe>
                        <div v-else class="p-6 text-sm text-stone-600">
                            Rendered ballot PDF is not available for this
                            deposited record.
                        </div>
                        <div
                            class="flex flex-wrap gap-2 border-t border-stone-200 p-3"
                        >
                            <button
                                v-for="(ballotRecord, index) in ballotReview.ballots"
                                :key="ballotRecord.sequence"
                                type="button"
                                class="h-9 min-w-9 border px-3 text-sm font-bold"
                                :class="
                                    index === selectedIndex
                                        ? 'border-blue-700 bg-blue-700 text-white'
                                        : 'border-stone-300 bg-white text-stone-700'
                                "
                                @click="selectBallot(index)"
                            >
                                {{ ballotRecord.sequence }}
                            </button>
                        </div>
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
                                Record
                                {{ selectedBallot.record_hash.slice(0, 16) }}
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
                                <span>
                                    {{
                                        candidateName(
                                            String(contest),
                                            String(candidate),
                                        )
                                    }}
                                </span>
                                <TallyMarks :count="Number(votes)" />
                                <strong>{{ votes }}</strong>
                            </div>
                        </section>
                    </aside>
                </div>
            </section>

            <section
                v-for="(candidates, contest) in aggregateTally"
                :key="contest"
                class="mt-6 border border-stone-300"
            >
                <h2 class="border-b border-stone-200 bg-stone-50 px-4 py-3 font-bold">
                    {{ contestTitle(String(contest)) }}
                </h2>
                <template v-if="Object.keys(candidates).length > 0">
                    <div
                        v-for="(votes, candidate) in candidates"
                        :key="candidate"
                        class="grid grid-cols-[minmax(0,1fr)_minmax(160px,1.2fr)_auto] items-center gap-4 border-b border-stone-100 px-4 py-3 text-sm"
                    >
                        <span>{{ candidateName(String(contest), String(candidate)) }}</span>
                        <TallyMarks :count="Number(votes)" />
                        <strong class="text-sm font-semibold text-stone-600">
                            {{ votes }}
                        </strong>
                    </div>
                </template>
                <p v-else class="px-4 py-5 text-sm font-semibold text-stone-600">
                    No votes recorded for this contest yet.
                </p>
            </section>
        </section>
    </main>
</template>
