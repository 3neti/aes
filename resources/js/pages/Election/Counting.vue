<script setup lang="ts">
import { Form, Link, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import ArtifactLinks from '@/components/election/ArtifactLinks.vue';
import CeremonyActionPanel from '@/components/election/CeremonyActionPanel.vue';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import StatusBadge from '@/components/election/StatusBadge.vue';
import TallyMarks from '@/components/election/TallyMarks.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import { returns } from '@/routes/election';
import {
    adjudicate,
    complete,
    physicalCount,
    scan,
} from '@/routes/election/counting';
import {
    approve as approveRandomManualAudit,
    discrepancy as recordRandomManualAuditDiscrepancy,
    propose as proposeRandomManualAudit,
    reconciliationReport as generateRandomManualAuditReconciliationReport,
    selectSample as selectRandomManualAuditSample,
} from '@/routes/election/counting/rma';
import {
    build as buildRandomManualAuditEvidencePack,
    download as downloadRandomManualAuditEvidencePack,
    print as printRandomManualAuditEvidencePack,
} from '@/routes/election/counting/rma/evidence-pack';
import { download as downloadTallySheet } from '@/routes/election/counting/tally-sheet';
import { useElectionReview } from '@/stores/electionReview';

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

type RandomManualAuditFeedback = {
    status:
        | 'sample-selected'
        | 'proposed'
        | 'approved'
        | 'discrepancy-recorded'
        | 'reconciliation-generated'
        | 'evidence-pack-built';
    ballot_id: string | null;
    payload_hash: string;
};

type RandomManualAudit = {
    enabled: boolean;
    proposed_ballots: number;
    approved_ballots: number;
    discrepancy_ballots: number;
    sample_selection: {
        sample_size: number;
        source_record_count: number;
        selected_ballots: Array<{
            payload_hash: string;
            paper_ballot_serial: number | null;
            selection_rank: string;
        }>;
    } | null;
    pending_proposal: {
        ballot_id: string;
        payload_hash: string;
        paper_ballot_serial: number | null;
        selections: Record<string, string[]>;
    } | null;
    reconciliation_report: {
        complete: boolean;
        passed: boolean;
        verified_ballots: number;
        discrepancy_ballots: number;
        pending_ballots: number;
        device_record_issues: number;
        entries: Array<{
            payload_hash: string;
            paper_ballot_serial: number | null;
            status: string;
        }>;
    } | null;
    evidence_pack: {
        evidence_pack_hash: string;
        artifact_count: number;
        reconciliation_report: {
            passed: boolean;
        };
    } | null;
    tally: Record<string, Record<string, number>>;
};

type PrintProfile = {
    value: string;
    label: string;
    width_mm: number;
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
    rmaFeedback?: RandomManualAuditFeedback | null;
    randomManualAudit: RandomManualAudit;
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
    printProfiles: PrintProfile[];
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
const { review: electionReview, defaults: reviewDefaults } =
    useElectionReview();

const canCapture = computed(
    () => cameraStatus.value === 'ready' && !cameraForm.processing,
);
const canCount = computed(() => props.snapshot.stage === 'counting');
const routineScanningEnabled = computed(
    () => props.snapshot.tabulationProfile.routine_scanning_enabled,
);
const totalScans = computed(
    () => props.tally.accepted_ballots + props.tally.rejected_ballots,
);
const pendingAudit = computed(() => props.randomManualAudit.pending_proposal);
const auditSample = computed(() => props.randomManualAudit.sample_selection);
const auditReconciliation = computed(
    () => props.randomManualAudit.reconciliation_report,
);
const auditEvidencePack = computed(() => props.randomManualAudit.evidence_pack);

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
            v-if="routineScanningEnabled"
            title="Scan paper ballots"
            description="Present the QR mark from each physical ballot. Every accepted ballot is appended as a separate evidence file."
            eyebrow="Ballot box count"
            :status="canCount ? 'Scanner ready' : 'Counting unavailable'"
            :tone="canCount ? 'current' : 'neutral'"
            :recommended="canCount && totalScans === 0"
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
                            :class="{
                                'review-next-action-button':
                                    electionReview.enabled &&
                                    totalScans === 0 &&
                                    cameraStatus !== 'ready',
                            }"
                            type="button"
                            :disabled="cameraStatus === 'starting'"
                            @click="startCamera"
                        >
                            Start Camera
                        </button>
                        <button
                            class="primary-button"
                            :class="{
                                'review-next-action-button':
                                    electionReview.enabled &&
                                    totalScans === 0 &&
                                    cameraStatus === 'ready',
                            }"
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
            v-else
            title="Device tabulation record"
            description="Deposited paper ballots are represented by sealed device VVDAT records. Routine QR scanning is retained for a later random manual audit, not this tally."
            eyebrow="Configured tally source"
            :status="canCount ? 'Device record ready' : 'Counting unavailable'"
            :tone="canCount ? 'current' : 'neutral'"
            :recommended="canCount && !reconciliation.physical_count_recorded"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="border border-emerald-300 bg-emerald-50 p-4">
                    <p class="text-sm font-bold text-emerald-950">
                        {{ snapshot.tabulationProfile.label }}
                    </p>
                    <p class="mt-2 text-sm text-emerald-900">
                        Tally source:
                        {{ snapshot.tabulationProfile.tally_source }}.
                    </p>
                </div>
                <div
                    class="border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950"
                >
                    The ballot box remains the paper-audit record. Count the
                    physical ballots removed from the box before completing the
                    tally.
                </div>
            </div>
        </CeremonyActionPanel>

        <CeremonyActionPanel
            v-if="randomManualAudit.enabled"
            title="Random manual audit"
            description="Scan a sampled paper ballot QR code, compare the decoded selections to the paper ballot, then obtain two distinct Election Board approvals. This audit tally is separate from device tabulation and the Election Return."
            eyebrow="Paper audit control"
            :status="`${randomManualAudit.approved_ballots} ballots approved`"
            :tone="pendingAudit ? 'warning' : 'neutral'"
            :recommended="
                canCount &&
                !pendingAudit &&
                randomManualAudit.approved_ballots === 0
            "
        >
            <section
                v-if="rmaFeedback"
                class="border-l-4 border-emerald-700 bg-emerald-50 px-4 py-3 text-sm text-emerald-950"
                role="status"
            >
                <p class="font-bold">
                    {{
                        rmaFeedback.status === 'sample-selected'
                            ? 'Random manual audit sample recorded'
                            : rmaFeedback.status === 'approved'
                              ? 'Random manual audit record approved'
                              : rmaFeedback.status === 'discrepancy-recorded'
                                ? 'Paper discrepancy recorded for review'
                                : rmaFeedback.status ===
                                    'reconciliation-generated'
                                  ? 'Audit reconciliation report generated'
                                  : rmaFeedback.status === 'evidence-pack-built'
                                    ? 'Random manual audit evidence pack built'
                                    : 'Paper comparison ready for dual approval'
                    }}
                </p>
                <p v-if="rmaFeedback.ballot_id" class="mt-1">
                    Ballot {{ rmaFeedback.ballot_id }}
                </p>
            </section>

            <Form
                v-if="canCount && !auditSample"
                v-bind="selectRandomManualAuditSample.form()"
                #default="{ errors, processing }"
                class="mt-5 flex flex-col gap-4 border border-blue-300 bg-blue-50 p-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h3 class="text-sm font-bold text-blue-950">
                        Freeze the audit sample
                    </h3>
                    <p class="mt-1 text-sm text-blue-900">
                        The device ranks sealed VVDAT records with a
                        deterministic SHA-256 seed. The resulting sample and its
                        source count are preserved before any audit scan.
                    </p>
                    <p
                        v-if="errors.sample"
                        class="mt-2 text-sm font-bold text-red-700"
                    >
                        {{ errors.sample }}
                    </p>
                </div>
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
                            ? 'Selecting sample...'
                            : 'Select random audit sample'
                    }}
                </button>
            </Form>

            <section
                v-else-if="auditSample"
                class="mt-5 border border-blue-300 bg-blue-50 p-4"
            >
                <div
                    class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div>
                        <h3 class="text-sm font-bold text-blue-950">
                            Recorded audit sample
                        </h3>
                        <p class="mt-1 text-sm text-blue-900">
                            {{ auditSample.sample_size }} of
                            {{ auditSample.source_record_count }} sealed device
                            records selected.
                        </p>
                    </div>
                    <span class="text-xs font-bold text-blue-800 uppercase"
                        >Sample frozen</span
                    >
                </div>
                <ul
                    class="mt-3 divide-y divide-blue-200 border border-blue-200 bg-white text-sm"
                >
                    <li
                        v-for="ballot in auditSample.selected_ballots"
                        :key="ballot.payload_hash"
                        class="flex items-center justify-between gap-3 px-3 py-2"
                    >
                        <span class="font-bold text-stone-950">
                            Paper ballot
                            {{
                                ballot.paper_ballot_serial ??
                                'serial unavailable'
                            }}
                        </span>
                        <span class="font-mono text-xs text-stone-600">
                            {{ ballot.payload_hash.slice(0, 12) }}
                        </span>
                    </li>
                </ul>
            </section>

            <div
                class="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]"
            >
                <Form
                    v-if="canCount && auditSample && !pendingAudit"
                    v-bind="proposeRandomManualAudit.form()"
                    #default="{ errors, processing }"
                    class="grid content-start gap-3 border border-stone-300 p-4"
                >
                    <div>
                        <h3 class="text-sm font-bold text-stone-950">
                            Scan sampled ballot
                        </h3>
                        <p class="mt-1 text-sm text-stone-600">
                            Use a handheld QR scanner or paste a simulation
                            payload.
                        </p>
                    </div>
                    <label class="block">
                        <span class="sr-only">Sample ballot QR payload</span>
                        <textarea
                            name="payload"
                            class="h-36 w-full border border-stone-300 bg-stone-50 p-3 font-mono text-sm"
                            placeholder="Scan or paste sampled ballot payload"
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
                        class="secondary-button"
                        :class="{
                            'review-next-action-button':
                                electionReview.enabled &&
                                randomManualAudit.approved_ballots === 0,
                        }"
                        type="submit"
                        :disabled="processing"
                    >
                        {{
                            processing
                                ? 'Reading QR...'
                                : 'Propose paper comparison'
                        }}
                    </button>
                </Form>

                <section
                    v-if="pendingAudit"
                    class="border border-amber-400 bg-amber-50 p-4"
                >
                    <header class="border-b border-amber-200 pb-3">
                        <p class="text-xs font-bold text-amber-900 uppercase">
                            Pending dual approval
                        </p>
                        <h3 class="mt-1 font-bold text-amber-950">
                            Paper ballot
                            {{
                                pendingAudit.paper_ballot_serial ??
                                pendingAudit.ballot_id
                            }}
                        </h3>
                        <p class="mt-1 text-sm text-amber-900">
                            Read the printed ballot face. Confirm each selection
                            below matches the paper before both officers
                            approve.
                        </p>
                    </header>

                    <div class="mt-4 space-y-3">
                        <section
                            v-for="(
                                candidateIds, contest
                            ) in pendingAudit.selections"
                            :key="contest"
                            class="border border-amber-200 bg-white"
                        >
                            <h4
                                class="border-b border-amber-100 px-3 py-2 text-sm font-bold text-stone-950"
                            >
                                {{ contestTitle(String(contest)) }}
                            </h4>
                            <ul class="divide-y divide-stone-100">
                                <li
                                    v-for="candidateId in candidateIds"
                                    :key="candidateId"
                                    class="px-3 py-2 text-sm text-stone-800"
                                >
                                    {{
                                        candidateName(
                                            String(contest),
                                            candidateId,
                                        )
                                    }}
                                </li>
                                <li
                                    v-if="candidateIds.length === 0"
                                    class="px-3 py-2 text-sm text-stone-500"
                                >
                                    No selection
                                </li>
                            </ul>
                        </section>
                    </div>

                    <Form
                        v-bind="approveRandomManualAudit.form()"
                        #default="{ errors, processing }"
                        class="mt-5 grid gap-3 border-t border-amber-200 pt-4 sm:grid-cols-2"
                    >
                        <input
                            name="payload_hash"
                            type="hidden"
                            :value="pendingAudit.payload_hash"
                        />
                        <label
                            class="flex gap-2 text-sm font-bold text-stone-900 sm:col-span-2"
                        >
                            <input
                                name="paper_matches_payload"
                                type="checkbox"
                                value="1"
                                required
                            />
                            The printed paper ballot matches these decoded
                            selections.
                        </label>
                        <label class="text-sm font-bold"
                            >First officer code<input
                                name="first_officer_code"
                                class="mt-1 min-h-11 w-full border border-stone-300 px-3"
                                :value="
                                    reviewDefaults.primary_officer?.code ?? ''
                                "
                        /></label>
                        <label class="text-sm font-bold"
                            >First officer PIN<input
                                name="first_officer_pin"
                                type="password"
                                inputmode="numeric"
                                class="mt-1 min-h-11 w-full border border-stone-300 px-3"
                                :value="
                                    reviewDefaults.primary_officer?.pin ?? ''
                                "
                        /></label>
                        <label class="text-sm font-bold"
                            >Second officer code<input
                                name="second_officer_code"
                                class="mt-1 min-h-11 w-full border border-stone-300 px-3"
                                :value="reviewDefaults.poll_clerk?.code ?? ''"
                        /></label>
                        <label class="text-sm font-bold"
                            >Second officer PIN<input
                                name="second_officer_pin"
                                type="password"
                                inputmode="numeric"
                                class="mt-1 min-h-11 w-full border border-stone-300 px-3"
                                :value="reviewDefaults.poll_clerk?.pin ?? ''"
                        /></label>
                        <p
                            v-if="Object.keys(errors).length"
                            class="text-sm font-bold text-red-700 sm:col-span-2"
                        >
                            Check the paper comparison and both officer
                            credentials.
                        </p>
                        <button
                            class="primary-button sm:col-span-2"
                            :class="{
                                'review-next-action-button':
                                    electionReview.enabled,
                            }"
                            type="submit"
                            :disabled="processing"
                        >
                            {{
                                processing
                                    ? 'Recording approval...'
                                    : 'Record dual approval'
                            }}
                        </button>
                    </Form>

                    <details class="mt-4 border border-red-300 bg-red-50 p-4">
                        <summary class="cursor-pointer font-bold text-red-950">
                            Record a paper discrepancy instead
                        </summary>
                        <p class="mt-2 text-sm text-red-900">
                            This records the discrepancy for Electoral Board
                            review. It does not alter the device tally, audit
                            tally, or Election Return.
                        </p>
                        <Form
                            v-bind="recordRandomManualAuditDiscrepancy.form()"
                            #default="{ errors, processing }"
                            class="mt-4 grid gap-3 sm:grid-cols-2"
                        >
                            <input
                                name="payload_hash"
                                type="hidden"
                                :value="pendingAudit.payload_hash"
                            />
                            <label class="text-sm font-bold sm:col-span-2"
                                >Reason<textarea
                                    name="reason"
                                    class="mt-1 h-20 w-full border border-stone-300 bg-white p-3"
                                    required
                                />
                            </label>
                            <label class="text-sm font-bold"
                                >First officer code<input
                                    name="first_officer_code"
                                    class="mt-1 min-h-11 w-full border border-stone-300 px-3"
                                    :value="
                                        reviewDefaults.primary_officer?.code ??
                                        ''
                                    "
                            /></label>
                            <label class="text-sm font-bold"
                                >First officer PIN<input
                                    name="first_officer_pin"
                                    type="password"
                                    inputmode="numeric"
                                    class="mt-1 min-h-11 w-full border border-stone-300 px-3"
                                    :value="
                                        reviewDefaults.primary_officer?.pin ??
                                        ''
                                    "
                            /></label>
                            <label class="text-sm font-bold"
                                >Second officer code<input
                                    name="second_officer_code"
                                    class="mt-1 min-h-11 w-full border border-stone-300 px-3"
                                    :value="
                                        reviewDefaults.poll_clerk?.code ?? ''
                                    "
                            /></label>
                            <label class="text-sm font-bold"
                                >Second officer PIN<input
                                    name="second_officer_pin"
                                    type="password"
                                    inputmode="numeric"
                                    class="mt-1 min-h-11 w-full border border-stone-300 px-3"
                                    :value="
                                        reviewDefaults.poll_clerk?.pin ?? ''
                                    "
                            /></label>
                            <p
                                v-if="Object.keys(errors).length"
                                class="text-sm font-bold text-red-700 sm:col-span-2"
                            >
                                Check the reason and both officer credentials.
                            </p>
                            <button
                                class="secondary-button border-red-700 text-red-900 sm:col-span-2"
                                type="submit"
                                :disabled="processing"
                            >
                                {{
                                    processing
                                        ? 'Recording discrepancy...'
                                        : 'Record paper discrepancy'
                                }}
                            </button>
                        </Form>
                    </details>
                </section>

                <div
                    v-else
                    class="border border-stone-200 bg-stone-50 p-4 text-sm text-stone-600"
                >
                    {{
                        auditSample
                            ? 'Scan a sampled paper ballot to begin a new comparison.'
                            : 'Select the audit sample before scanning a paper ballot.'
                    }}
                </div>
            </div>

            <div v-if="randomManualAudit.approved_ballots > 0" class="mt-6">
                <h3 class="text-sm font-bold text-stone-950">Audit tally</h3>
                <p class="mt-1 text-sm text-stone-600">
                    Totals from dual-approved paper comparisons only. These
                    figures do not replace the device tabulation tally.
                </p>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <section
                        v-for="(totals, contest) in randomManualAudit.tally"
                        :key="contest"
                        class="border border-stone-200"
                    >
                        <h4
                            class="border-b border-stone-200 bg-stone-50 px-3 py-2 text-sm font-bold text-stone-950"
                        >
                            {{ contestTitle(String(contest)) }}
                        </h4>
                        <dl class="divide-y divide-stone-100 text-sm">
                            <div
                                v-for="(votes, candidate) in totals"
                                :key="candidate"
                                class="flex justify-between gap-3 px-3 py-2"
                            >
                                <dt>
                                    {{
                                        candidateName(
                                            String(contest),
                                            String(candidate),
                                        )
                                    }}
                                </dt>
                                <dd class="font-bold">{{ votes }}</dd>
                            </div>
                        </dl>
                    </section>
                </div>
            </div>

            <section
                v-if="auditSample"
                class="mt-6 border-t border-stone-300 pt-5"
            >
                <div
                    class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div>
                        <h3 class="text-sm font-bold text-stone-950">
                            Audit reconciliation report
                        </h3>
                        <p class="mt-1 text-sm text-stone-600">
                            Compares every selected paper-audit result to its
                            sealed device record. The report is evidence only
                            and never changes tabulation.
                        </p>
                    </div>
                    <Form
                        v-bind="
                            generateRandomManualAuditReconciliationReport.form()
                        "
                        #default="{ processing }"
                    >
                        <button
                            class="secondary-button"
                            type="submit"
                            :disabled="processing"
                        >
                            {{
                                processing
                                    ? 'Generating report...'
                                    : 'Generate reconciliation report'
                            }}
                        </button>
                    </Form>
                </div>

                <div v-if="auditReconciliation" class="mt-4">
                    <div class="grid gap-3 sm:grid-cols-4">
                        <div
                            class="border border-emerald-300 bg-emerald-50 p-3"
                        >
                            <span
                                class="text-xs font-bold text-emerald-800 uppercase"
                                >Verified</span
                            >
                            <strong
                                class="mt-1 block text-2xl text-emerald-950"
                                >{{
                                    auditReconciliation.verified_ballots
                                }}</strong
                            >
                        </div>
                        <div class="border border-red-300 bg-red-50 p-3">
                            <span
                                class="text-xs font-bold text-red-800 uppercase"
                                >Discrepancies</span
                            >
                            <strong class="mt-1 block text-2xl text-red-950">{{
                                auditReconciliation.discrepancy_ballots
                            }}</strong>
                        </div>
                        <div class="border border-amber-300 bg-amber-50 p-3">
                            <span
                                class="text-xs font-bold text-amber-800 uppercase"
                                >Pending</span
                            >
                            <strong
                                class="mt-1 block text-2xl text-amber-950"
                                >{{
                                    auditReconciliation.pending_ballots
                                }}</strong
                            >
                        </div>
                        <div class="border border-stone-300 p-3">
                            <span
                                class="text-xs font-bold text-stone-600 uppercase"
                                >Device issues</span
                            >
                            <strong
                                class="mt-1 block text-2xl text-stone-950"
                                >{{
                                    auditReconciliation.device_record_issues
                                }}</strong
                            >
                        </div>
                    </div>
                    <p
                        class="mt-3 text-sm font-bold"
                        :class="
                            auditReconciliation.passed
                                ? 'text-emerald-800'
                                : 'text-amber-800'
                        "
                    >
                        {{
                            auditReconciliation.passed
                                ? 'All sampled paper comparisons verify against the sealed device records.'
                                : 'The audit sample has unresolved, discrepant, or device-record issues.'
                        }}
                    </p>
                    <ul
                        class="mt-3 divide-y divide-stone-200 border border-stone-200 text-sm"
                    >
                        <li
                            v-for="entry in auditReconciliation.entries"
                            :key="entry.payload_hash"
                            class="flex items-center justify-between gap-3 px-3 py-2"
                        >
                            <span
                                >Paper ballot
                                {{
                                    entry.paper_ballot_serial ??
                                    'serial unavailable'
                                }}</span
                            >
                            <span class="font-bold text-stone-800">{{
                                entry.status
                            }}</span>
                        </li>
                    </ul>

                    <div
                        class="mt-5 flex flex-col gap-3 border-t border-stone-300 pt-5 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <h4 class="text-sm font-bold text-stone-950">
                                Evidence pack
                            </h4>
                            <p class="mt-1 text-sm text-stone-600">
                                Portable JSON containing the sample,
                                reconciliation, approvals, and discrepancies,
                                with a companion print form.
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Form
                                v-bind="
                                    buildRandomManualAuditEvidencePack.form()
                                "
                                #default="{ processing }"
                            >
                                <button
                                    class="secondary-button"
                                    type="submit"
                                    :disabled="processing"
                                >
                                    {{
                                        processing
                                            ? 'Building pack...'
                                            : 'Build evidence pack'
                                    }}
                                </button>
                            </Form>
                            <a
                                v-if="auditEvidencePack"
                                :href="
                                    downloadRandomManualAuditEvidencePack.url()
                                "
                                class="secondary-button"
                            >
                                Download JSON
                            </a>
                            <a
                                v-if="auditEvidencePack"
                                :href="printRandomManualAuditEvidencePack.url()"
                                class="secondary-button"
                            >
                                Download print form
                            </a>
                        </div>
                    </div>
                </div>
            </section>
        </CeremonyActionPanel>

        <CeremonyActionPanel
            title="Physical reconciliation"
            :description="
                routineScanningEnabled
                    ? 'Declare the paper ballots removed from the box and resolve every rejected scan in public before completing the tally.'
                    : 'Declare the paper ballots removed from the box and reconcile them to the sealed device tabulation record before completing the tally.'
            "
            eyebrow="Election Board control"
            :status="reconciliation.passed ? 'Reconciled' : 'Action required'"
            :tone="reconciliation.passed ? 'complete' : 'warning'"
            :recommended="
                tally.accepted_ballots > 0 &&
                !reconciliation.physical_count_recorded
            "
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
                            :value="
                                reconciliation.physical_ballots ??
                                tally.accepted_ballots
                            "
                        />
                    </label>
                    <label class="text-sm font-bold"
                        >Officer code<input
                            name="officer_code"
                            class="mt-1 min-h-11 w-full border border-stone-300 px-3"
                            :value="reviewDefaults.primary_officer?.code ?? ''"
                    /></label>
                    <label class="text-sm font-bold"
                        >Officer PIN<input
                            name="officer_pin"
                            type="password"
                            inputmode="numeric"
                            class="mt-1 min-h-11 w-full border border-stone-300 px-3"
                            :value="reviewDefaults.primary_officer?.pin ?? ''"
                    /></label>
                    <p
                        v-if="Object.keys(errors).length"
                        class="text-sm font-bold text-red-700"
                    >
                        Check the physical count and officer credentials.
                    </p>
                    <button
                        class="primary-button"
                        :class="{
                            'review-next-action-button':
                                electionReview.enabled &&
                                totalScans > 0 &&
                                !reconciliation.physical_count_recorded,
                        }"
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
                                <option value="spoiled-ballot-separated">
                                    Spoiled ballot kept outside ballot box
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
                                :value="
                                    reviewDefaults.primary_officer?.code ?? ''
                                "
                        /></label>
                        <label class="text-sm font-bold"
                            >Officer PIN<input
                                name="officer_pin"
                                type="password"
                                inputmode="numeric"
                                class="mt-1 min-h-11 w-full border border-stone-300 px-3"
                                :value="
                                    reviewDefaults.primary_officer?.pin ?? ''
                                "
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
            :description="`Live totals from ${snapshot.tabulationProfile.tally_source}. Reconcile these figures against the physical paper ballots.`"
            eyebrow="Tally sheet"
            :status="`${totalScans} ballots processed`"
            tone="neutral"
            :recommended="canCount && reconciliation.passed"
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
                        <thead>
                            <tr>
                                <th
                                    class="px-4 py-2 text-left text-xs font-semibold text-stone-500"
                                >
                                    Candidate
                                </th>
                                <th
                                    class="px-4 py-2 text-left text-xs font-semibold text-stone-500"
                                >
                                    Tally marks
                                </th>
                                <th
                                    class="w-24 px-4 py-2 text-right text-xs font-semibold text-stone-500"
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
                                <td class="px-4 py-2.5 text-stone-800">
                                    {{
                                        candidateName(
                                            String(contest),
                                            String(candidate),
                                        )
                                    }}
                                </td>
                                <td class="px-4 py-2.5 align-middle">
                                    <TallyMarks :count="Number(votes)" />
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

            <section class="mt-5 border border-stone-300 bg-stone-50 p-4">
                <h3 class="text-sm font-bold text-stone-950">
                    Print-ready tally sheet
                </h3>
                <p class="mt-1 text-sm text-stone-600">
                    Review the same tally in the form factor installed at this
                    precinct.
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <a
                        v-for="profile in printProfiles"
                        :key="profile.value"
                        class="secondary-button"
                        :href="downloadTallySheet.url(profile.value)"
                        target="_blank"
                    >
                        View {{ profile.label }}
                    </a>
                </div>
            </section>

            <div
                class="mt-5 flex flex-col gap-4 border-t border-stone-300 pt-5 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="max-w-2xl text-sm text-stone-600">
                    Complete counting only after the ballot box and configured
                    tabulation record have been reconciled.
                </p>
                <Form
                    v-if="canCount"
                    v-bind="complete.form()"
                    #default="{ errors, processing }"
                >
                    <button
                        class="primary-button"
                        :class="{
                            'review-next-action-button':
                                electionReview.enabled && reconciliation.passed,
                        }"
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
                    :class="{
                        'review-next-action-button': electionReview.enabled,
                    }"
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
