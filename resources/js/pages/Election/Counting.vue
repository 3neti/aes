<script setup lang="ts">
import { Form, Link, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import ArtifactLinks from '@/components/election/ArtifactLinks.vue';
import CeremonyActionPanel from '@/components/election/CeremonyActionPanel.vue';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import StatusBadge from '@/components/election/StatusBadge.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import { returns } from '@/routes/election';
import {
    adjudicate,
    complete,
    physicalCount,
    scan,
} from '@/routes/election/counting';

type ScanFeedback = {
    status: 'accepted' | 'rejected';
    adapter: string;
    sequence: number | null;
    ballot_id: string | null;
    payload_hash: string | null;
    raw_payload_hash: string;
    reason: string | null;
};

type LegalEvidence = {
    exists: boolean;
    run_id: string | null;
    precinct_id: string | null;
    generated_at: string | null;
    evidence_hash: string | null;
    accepted_ballots_before_counting?: number | null;
    rejected_ballots_before_counting?: number | null;
    accepted_ballots?: number | null;
    rejected_ballots?: number | null;
    tally_hash?: string | null;
    artifact: string;
};

const props = defineProps<{
    snapshot: ElectionSnapshot;
    tally: {
        accepted_ballots: number;
        rejected_ballots: number;
        tally: Record<string, Record<string, number>>;
    };
    closePollsLegalEvidence: LegalEvidence;
    countingLegalEvidence: LegalEvidence;
    scanFeedback?: ScanFeedback | null;
    reconciliation: {
        physical_count_recorded: boolean;
        physical_ballots: number | null;
        accepted_ballots: number;
        rejected_scans: number;
        adjudicated_rejections: number;
        excluded_paper_ballots: number;
        represented_paper_ballots: number;
        unresolved_rejections: number;
        difference: number | null;
        passed: boolean;
        rejected_records: Array<{
            sequence: number;
            reason: string;
            raw_payload_hash: string;
        }>;
        adjudications: Array<{ sequence: number }>;
    };
}>();

const video = ref<HTMLVideoElement | null>(null);
const stream = ref<MediaStream | null>(null);
const cameraStatus = ref<'idle' | 'starting' | 'ready' | 'captured' | 'error'>(
    'idle',
);
const cameraMessage = ref('');
const cameraForm = useForm({
    payload: '',
});

const canCapture = computed(
    () => cameraStatus.value === 'ready' && !cameraForm.processing,
);
const canCount = computed(() => props.snapshot.stage === 'counting');
const totalScans = computed(
    () => props.tally.accepted_ballots + props.tally.rejected_ballots,
);

async function startCamera(): Promise<void> {
    if (!navigator.mediaDevices?.getUserMedia) {
        cameraStatus.value = 'error';
        cameraMessage.value =
            'Camera capture is not available in this browser.';

        return;
    }

    cameraStatus.value = 'starting';
    cameraMessage.value = 'Starting camera.';

    try {
        stopCamera(false);

        stream.value = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: {
                facingMode: { ideal: 'environment' },
            },
        });

        if (video.value) {
            video.value.srcObject = stream.value;
            await video.value.play();
        }

        cameraStatus.value = 'ready';
        cameraMessage.value = 'Camera ready.';
    } catch {
        cameraStatus.value = 'error';
        cameraMessage.value = 'Camera permission was denied or unavailable.';
    }
}

function stopCamera(resetStatus = true): void {
    stream.value?.getTracks().forEach((track) => track.stop());
    stream.value = null;

    if (video.value) {
        video.value.srcObject = null;
    }

    if (resetStatus) {
        cameraStatus.value = 'idle';
        cameraMessage.value = '';
    }
}

function captureAndSubmit(): void {
    if (!video.value || video.value.videoWidth === 0) {
        cameraStatus.value = 'error';
        cameraMessage.value = 'No camera frame is ready to capture.';

        return;
    }

    const canvas = document.createElement('canvas');
    canvas.width = video.value.videoWidth;
    canvas.height = video.value.videoHeight;

    const context = canvas.getContext('2d');

    if (!context) {
        cameraStatus.value = 'error';
        cameraMessage.value = 'Unable to capture a camera frame.';

        return;
    }

    context.drawImage(video.value, 0, 0, canvas.width, canvas.height);
    cameraForm.payload = canvas.toDataURL('image/png');
    cameraForm.post(scan.url(), {
        preserveScroll: true,
        onSuccess: () => {
            cameraStatus.value = 'captured';
            cameraMessage.value = 'Camera frame submitted.';
            cameraForm.reset();
        },
        onError: () => {
            cameraStatus.value = 'error';
            cameraMessage.value = 'Camera frame was not accepted.';
        },
    });
}

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
    const candidate = contest?.candidates.find(
        (entry) => entry.id === candidateId,
    );

    return candidate?.name ?? candidateId;
}

onBeforeUnmount(() => stopCamera(false));
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Counting and Tally">
        <section
            v-if="scanFeedback"
            class="border-l-4 px-5 py-4"
            :class="
                scanFeedback.status === 'accepted'
                    ? 'border-emerald-700 bg-emerald-50 text-emerald-950'
                    : 'border-red-700 bg-red-50 text-red-950'
            "
            role="status"
        >
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
            >
                <div>
                    <p class="text-xs font-bold uppercase">
                        {{
                            scanFeedback.status === 'accepted'
                                ? 'Scan Accepted'
                                : 'Scan Rejected'
                        }}
                    </p>
                    <p class="mt-1 text-lg font-bold">
                        {{
                            scanFeedback.status === 'accepted'
                                ? `Ballot ${scanFeedback.ballot_id}`
                                : scanFeedback.reason
                        }}
                    </p>
                </div>
                <div class="text-sm sm:text-right">
                    <p class="font-bold">{{ scanFeedback.adapter }}</p>
                    <p v-if="scanFeedback.sequence">
                        Accepted file {{ scanFeedback.sequence }}
                    </p>
                </div>
            </div>
            <details class="mt-3 text-xs">
                <summary class="cursor-pointer font-bold">
                    Verification hashes
                </summary>
                <dl class="mt-2 grid gap-2 sm:grid-cols-2">
                    <div v-if="scanFeedback.payload_hash">
                        <dt class="font-bold">Payload hash</dt>
                        <dd class="font-mono break-all">
                            {{ scanFeedback.payload_hash }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-bold">Raw input hash</dt>
                        <dd class="font-mono break-all">
                            {{ scanFeedback.raw_payload_hash }}
                        </dd>
                    </div>
                </dl>
            </details>
        </section>

        <CeremonyActionPanel
            title="Scan paper ballots"
            description="Present the QR mark from each physical ballot. Every accepted ballot is appended as a separate evidence file."
            eyebrow="Ballot box count"
            :status="canCount ? 'Scanner ready' : 'Counting unavailable'"
            :tone="canCount ? 'current' : 'neutral'"
        >
            <div
                v-if="canCount"
                class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]"
            >
                <div>
                    <h3 class="text-sm font-bold text-stone-900">
                        Camera Capture
                    </h3>
                    <div
                        class="mt-3 aspect-[4/3] overflow-hidden border border-stone-300 bg-stone-950"
                    >
                        <video
                            ref="video"
                            class="h-full w-full object-cover"
                            autoplay
                            muted
                            playsinline
                        />
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button
                            class="secondary-button"
                            type="button"
                            :disabled="cameraStatus === 'starting'"
                            @click="startCamera"
                        >
                            Start Camera
                        </button>
                        <button
                            class="primary-button"
                            type="button"
                            :disabled="!canCapture"
                            @click="captureAndSubmit"
                        >
                            Capture Scan
                        </button>
                        <button
                            class="secondary-button"
                            type="button"
                            :disabled="!stream"
                            @click="stopCamera()"
                        >
                            Stop Camera
                        </button>
                    </div>
                    <p
                        v-if="cameraMessage"
                        class="mt-3 text-sm font-bold"
                        :class="
                            cameraStatus === 'error'
                                ? 'text-red-700'
                                : 'text-emerald-700'
                        "
                        role="status"
                    >
                        {{ cameraMessage }}
                    </p>
                </div>

                <div>
                    <h3 class="text-sm font-bold text-stone-900">
                        Scanner input
                    </h3>
                    <p class="mt-1 text-sm text-stone-600">
                        Use for a handheld scanner or paste a simulation
                        payload.
                    </p>
                    <Form
                        v-bind="scan.form()"
                        #default="{ errors, processing }"
                        class="mt-3 space-y-3"
                    >
                        <label class="block">
                            <span class="sr-only">Ballot scanner payload</span>
                            <textarea
                                name="payload"
                                class="h-44 w-full border border-stone-300 bg-stone-50 p-3 font-mono text-sm"
                                placeholder="Scan or paste ballot payload"
                                required
                            />
                        </label>
                        <p
                            v-if="errors.payload"
                            class="text-sm font-bold text-red-700"
                        >
                            {{ errors.payload }}
                        </p>
                        <button
                            class="primary-button"
                            type="submit"
                            :disabled="processing"
                        >
                            {{
                                processing
                                    ? 'Checking ballot...'
                                    : 'Submit scanner input'
                            }}
                        </button>
                    </Form>
                    <div
                        class="mt-5 border-l-4 border-amber-500 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                    >
                        Keep rejected paper ballots separate for Electoral Board
                        review. The device does not decide the legal treatment
                        of the paper.
                    </div>
                </div>
            </div>

            <div v-else class="py-6 text-center">
                <p class="font-bold text-stone-900">
                    Scanning is not available at this lifecycle stage.
                </p>
                <p class="mt-2 text-sm text-stone-600">
                    Complete the preceding ceremony or continue to the Election
                    Return if counting is already complete.
                </p>
            </div>
        </CeremonyActionPanel>

        <CeremonyActionPanel
            title="Physical reconciliation"
            description="Declare the paper ballots removed from the box and resolve every rejected scan in public before completing the tally."
            eyebrow="Election Board control"
            :status="reconciliation.passed ? 'Reconciled' : 'Action required'"
            :tone="reconciliation.passed ? 'complete' : 'warning'"
        >
            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                <Form
                    v-bind="physicalCount.form()"
                    #default="{ processing, errors }"
                    class="grid content-start gap-3 border border-stone-300 p-4"
                >
                    <label class="text-sm font-bold">
                        Paper ballots removed from box
                        <input
                            name="physical_count"
                            type="number"
                            min="0"
                            class="mt-1 min-h-11 w-full border border-stone-300 px-3"
                            :value="reconciliation.physical_ballots ?? ''"
                        />
                    </label>
                    <label class="text-sm font-bold"
                        >Officer code<input
                            name="officer_code"
                            class="mt-1 min-h-11 w-full border border-stone-300 px-3"
                            value="SIM-OFFICER-001"
                    /></label>
                    <label class="text-sm font-bold"
                        >Officer PIN<input
                            name="officer_pin"
                            type="password"
                            inputmode="numeric"
                            class="mt-1 min-h-11 w-full border border-stone-300 px-3"
                    /></label>
                    <p
                        v-if="Object.keys(errors).length"
                        class="text-sm font-bold text-red-700"
                    >
                        Check the physical count and officer credentials.
                    </p>
                    <button
                        class="primary-button"
                        type="submit"
                        :disabled="processing"
                    >
                        Record physical control
                    </button>
                </Form>

                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div class="border border-stone-200 p-3">
                            <span class="text-xs text-stone-500">Physical</span
                            ><strong class="block text-2xl">{{
                                reconciliation.physical_ballots ?? '-'
                            }}</strong>
                        </div>
                        <div class="border border-stone-200 p-3">
                            <span class="text-xs text-stone-500"
                                >Represented</span
                            ><strong class="block text-2xl">{{
                                reconciliation.represented_paper_ballots
                            }}</strong>
                        </div>
                        <div class="border border-stone-200 p-3">
                            <span class="text-xs text-stone-500"
                                >Unresolved</span
                            ><strong class="block text-2xl">{{
                                reconciliation.unresolved_rejections
                            }}</strong>
                        </div>
                        <div class="border border-stone-200 p-3">
                            <span class="text-xs text-stone-500"
                                >Difference</span
                            ><strong class="block text-2xl">{{
                                reconciliation.difference ?? '-'
                            }}</strong>
                        </div>
                    </div>

                    <Form
                        v-for="record in reconciliation.rejected_records.filter(
                            (rejected) =>
                                !reconciliation.adjudications.some(
                                    (item) =>
                                        item.sequence === rejected.sequence,
                                ),
                        )"
                        :key="record.sequence"
                        v-bind="adjudicate.form()"
                        #default="{ processing }"
                        class="grid gap-3 border-l-4 border-red-700 bg-red-50 p-4 sm:grid-cols-2"
                    >
                        <input
                            type="hidden"
                            name="sequence"
                            :value="record.sequence"
                        />
                        <div class="sm:col-span-2">
                            <p class="font-bold">
                                Rejected scan {{ record.sequence }}
                            </p>
                            <p class="text-sm text-red-900">
                                {{ record.reason }}
                            </p>
                        </div>
                        <label class="text-sm font-bold"
                            >Disposition<select
                                name="disposition"
                                class="mt-1 min-h-11 w-full border border-stone-300 bg-white px-3"
                            >
                                <option value="excluded-paper-ballot">
                                    Excluded physical ballot
                                </option>
                                <option value="duplicate-scan">
                                    Duplicate scan only
                                </option>
                                <option value="not-a-paper-ballot">
                                    Not a paper ballot
                                </option>
                            </select></label
                        >
                        <label class="text-sm font-bold"
                            >Reason<input
                                name="reason"
                                class="mt-1 min-h-11 w-full border border-stone-300 px-3"
                                required
                        /></label>
                        <label class="text-sm font-bold"
                            >Officer code<input
                                name="officer_code"
                                class="mt-1 min-h-11 w-full border border-stone-300 px-3"
                                value="SIM-OFFICER-001"
                        /></label>
                        <label class="text-sm font-bold"
                            >Officer PIN<input
                                name="officer_pin"
                                type="password"
                                inputmode="numeric"
                                class="mt-1 min-h-11 w-full border border-stone-300 px-3"
                        /></label>
                        <button
                            class="secondary-button sm:col-span-2"
                            type="submit"
                            :disabled="processing"
                        >
                            Record adjudication
                        </button>
                    </Form>
                </div>
            </div>
        </CeremonyActionPanel>

        <CeremonyActionPanel
            title="Precinct tally"
            description="Live totals from accepted ballot evidence files. Reconcile these figures against the physical paper ballots."
            eyebrow="Tally sheet"
            :status="`${totalScans} ballots processed`"
            tone="neutral"
        >
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="border border-stone-200 p-4">
                    <p class="text-xs font-bold text-stone-500 uppercase">
                        Total processed
                    </p>
                    <p class="mt-1 text-3xl font-bold text-stone-950">
                        {{ totalScans }}
                    </p>
                </div>
                <div class="border border-emerald-300 bg-emerald-50 p-4">
                    <p class="text-xs font-bold text-emerald-800 uppercase">
                        Accepted
                    </p>
                    <p class="mt-1 text-3xl font-bold text-emerald-900">
                        {{ tally.accepted_ballots }}
                    </p>
                </div>
                <div class="border border-red-300 bg-red-50 p-4">
                    <p class="text-xs font-bold text-red-800 uppercase">
                        Rejected
                    </p>
                    <p class="mt-1 text-3xl font-bold text-red-900">
                        {{ tally.rejected_ballots }}
                    </p>
                </div>
            </div>

            <div class="mt-5 space-y-4">
                <section
                    v-for="(totals, contest) in tally.tally"
                    :key="contest"
                    class="border border-stone-300"
                >
                    <header
                        class="flex items-center justify-between gap-3 border-b border-stone-200 bg-stone-50 px-4 py-3"
                    >
                        <h3 class="font-bold text-stone-950">
                            {{ contestTitle(String(contest)) }}
                        </h3>
                        <span class="text-xs font-semibold text-stone-500">
                            Candidate votes
                        </span>
                    </header>
                    <table class="w-full table-fixed text-sm">
                        <thead class="sr-only">
                            <tr>
                                <th>Candidate</th>
                                <th>Votes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200">
                            <tr
                                v-for="(votes, candidate) in totals"
                                :key="candidate"
                            >
                                <td class="px-4 py-2.5 text-stone-800">
                                    {{
                                        candidateName(
                                            String(contest),
                                            String(candidate),
                                        )
                                    }}
                                </td>
                                <td
                                    class="w-24 px-4 py-2.5 text-right text-base font-bold text-stone-950"
                                >
                                    {{ votes }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
                <p
                    v-if="Object.keys(tally.tally).length === 0"
                    class="border border-stone-200 bg-stone-50 p-5 text-center text-sm text-stone-600"
                >
                    Tally rows will appear after the first accepted ballot.
                </p>
            </div>

            <div
                class="mt-5 flex flex-col gap-4 border-t border-stone-300 pt-5 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="max-w-2xl text-sm text-stone-600">
                    Complete counting only after the ballot box, accepted files,
                    rejected ballots, and tally have been reconciled.
                </p>
                <Form
                    v-if="canCount"
                    v-bind="complete.form()"
                    #default="{ errors, processing }"
                >
                    <button
                        class="primary-button"
                        type="submit"
                        :disabled="processing"
                    >
                        {{
                            processing
                                ? 'Completing count...'
                                : 'Complete counting and tally'
                        }}
                    </button>
                    <p
                        v-if="errors.lifecycle"
                        class="mt-2 max-w-xs text-sm font-bold text-red-700"
                    >
                        {{ errors.lifecycle }}
                    </p>
                </Form>
                <Link
                    v-else-if="snapshot.stage === 'election_return'"
                    :href="returns.url()"
                    class="inline-flex min-h-11 items-center justify-center bg-blue-800 px-5 py-3 text-sm font-bold text-white"
                >
                    Continue to Election Return
                </Link>
            </div>
        </CeremonyActionPanel>

        <CeremonyActionPanel
            title="Counting evidence"
            description="Hash-linked closing and counting reports preserved for reconciliation."
            :status="
                countingLegalEvidence.exists
                    ? 'Evidence complete'
                    : 'Awaiting completion'
            "
            :tone="countingLegalEvidence.exists ? 'complete' : 'warning'"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="border border-stone-200 p-4">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-sm font-bold">Closing evidence</h3>
                        <StatusBadge
                            :label="
                                closePollsLegalEvidence.exists
                                    ? 'Present'
                                    : 'Missing'
                            "
                            :tone="
                                closePollsLegalEvidence.exists
                                    ? 'complete'
                                    : 'warning'
                            "
                        />
                    </div>
                    <p class="mt-3 font-mono text-xs break-all text-stone-600">
                        {{
                            closePollsLegalEvidence.evidence_hash ||
                            'No evidence hash yet'
                        }}
                    </p>
                </div>
                <div class="border border-stone-200 p-4">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-sm font-bold">Counting evidence</h3>
                        <StatusBadge
                            :label="
                                countingLegalEvidence.exists
                                    ? 'Present'
                                    : 'Missing'
                            "
                            :tone="
                                countingLegalEvidence.exists
                                    ? 'complete'
                                    : 'warning'
                            "
                        />
                    </div>
                    <p class="mt-3 font-mono text-xs break-all text-stone-600">
                        {{
                            countingLegalEvidence.evidence_hash ||
                            'Generated when counting is complete'
                        }}
                    </p>
                </div>
            </div>
            <ArtifactLinks
                class="mt-4"
                :artifacts="[
                    {
                        label: 'Closing of polls evidence',
                        path: closePollsLegalEvidence.artifact,
                    },
                    {
                        label: 'Counting and tally evidence',
                        path: countingLegalEvidence.artifact,
                    },
                ]"
            />
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
