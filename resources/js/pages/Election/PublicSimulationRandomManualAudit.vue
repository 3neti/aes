<script setup lang="ts">
import { Form, Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import TallyMarks from '@/components/election/TallyMarks.vue';

const props = defineProps<{
    precinct: { code: string; label: string; status: string };
    audit: {
        sample: Array<{
            payload_hash: string;
            paper_ballot_serial: string | null;
        }>;
        sample_hash: string | null;
        source_record_count: number;
        approved_ballots: number;
        discrepancy_ballots: number;
        pending: {
            payload_hash: string;
            paper_ballot_serial: string | null;
            selections: Record<string, string[]>;
        } | null;
        reconciliation: {
            complete: boolean;
            passed: boolean;
            verified_ballots: number;
            discrepancy_ballots: number;
            pending_ballots: number;
        } | null;
        auditTally: {
            accepted_scans: number;
            discrepancy_ballots: number;
            tally: Record<string, Record<string, number>>;
            latest: {
                sequence: number | null;
                payload_hash: string | null;
                paper_ballot_serial: string | null;
                selections: Record<string, string[]>;
                candidate_ids: string[];
            } | null;
            audit_tally_hash: string;
        } | null;
        evidencePackAvailable: boolean;
        watcherPublicationAvailable: boolean;
    };
    feedback: string | null;
    actions: {
        select: string;
        propose: string;
        approve: string;
        discrepancy: string;
        reconcile: string;
        evidencePack: string;
        publish: string;
        download: string;
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
    officer_code: '',
    officer_pin: '',
});
const canCapture = computed(
    () => cameraStatus.value === 'ready' && !cameraForm.processing,
);
const auditTallyRows = computed(() => {
    return Object.entries(props.audit.auditTally?.tally ?? {}).flatMap(
        ([contest, candidates]) =>
            Object.entries(candidates)
                .filter(([, votes]) => Number(votes) > 0)
                .map(([candidateId, votes]) => ({
                    contest,
                    candidateId,
                    votes: Number(votes),
                    latest: Boolean(
                        props.audit.auditTally?.latest?.candidate_ids.includes(
                            candidateId,
                        ),
                    ),
                })),
    );
});

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
            video: { facingMode: { ideal: 'environment' } },
        });

        if (video.value) {
            video.value.srcObject = stream.value;
            await video.value.play();
        }

        cameraStatus.value = 'ready';
        cameraMessage.value =
            'Camera ready. Position the paper ballot QR code within the frame.';
    } catch {
        cameraStatus.value = 'error';
        cameraMessage.value =
            'Camera permission was denied or unavailable. Use the scanner payload field instead.';
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
    cameraForm.post(props.actions.propose, {
        preserveScroll: true,
        onSuccess: () => {
            cameraStatus.value = 'captured';
            cameraMessage.value =
                'Camera capture submitted for paper comparison.';
            cameraForm.reset('payload');
            stopCamera(false);
        },
        onError: () => {
            cameraStatus.value = 'error';
            cameraMessage.value =
                'The camera capture was not accepted. Check the QR code and try again.';
        },
    });
}

onBeforeUnmount(() => stopCamera(false));
</script>

<template>
    <Head :title="`${precinct.code} random manual audit`" />
    <main class="min-h-screen bg-stone-100 p-5 text-stone-950 sm:p-8">
        <section
            class="mx-auto max-w-5xl border border-stone-300 bg-white p-5 sm:p-7"
        >
            <Link href="/election/play" class="text-sm font-bold text-blue-800"
                >All precincts</Link
            >
            <p class="mt-5 text-sm font-bold text-blue-800">
                RANDOM MANUAL AUDIT
            </p>
            <h1 class="mt-1 text-2xl font-bold">{{ precinct.label }}</h1>
            <p
                class="mt-3 border-l-4 border-blue-800 bg-blue-50 p-4 text-sm text-blue-950"
            >
                This room compares a deterministically selected paper ballot QR
                code with its sealed VVDAT record. It records an audit finding
                only. The official tally and Election Return cannot be changed
                here.
            </p>
            <p
                v-if="feedback"
                class="mt-4 border-l-4 border-emerald-700 bg-emerald-50 p-3 text-sm font-bold text-emerald-950"
                role="status"
            >
                {{ feedback }}
            </p>

            <section class="mt-6 grid gap-4 sm:grid-cols-3">
                <div class="border border-stone-300 p-4">
                    <p class="text-xs font-bold text-stone-600">SAMPLE</p>
                    <p class="mt-2 text-2xl font-bold">
                        {{ audit.sample.length }}
                    </p>
                    <p class="text-sm text-stone-600">
                        of {{ audit.source_record_count }} sealed records
                    </p>
                </div>
                <div class="border border-stone-300 p-4">
                    <p class="text-xs font-bold text-stone-600">VERIFIED</p>
                    <p class="mt-2 text-2xl font-bold">
                        {{ audit.approved_ballots }}
                    </p>
                    <p class="text-sm text-stone-600">
                        dual-approved paper comparisons
                    </p>
                </div>
                <div class="border border-stone-300 p-4">
                    <p class="text-xs font-bold text-stone-600">
                        DISCREPANCIES
                    </p>
                    <p class="mt-2 text-2xl font-bold">
                        {{ audit.discrepancy_ballots }}
                    </p>
                    <p class="text-sm text-stone-600">
                        recorded audit findings
                    </p>
                </div>
            </section>

            <section
                v-if="audit.sample.length === 0"
                class="mt-6 border border-stone-300 p-5"
            >
                <h2 class="text-lg font-bold">
                    1. Select the paper-ballot sample
                </h2>
                <p class="mt-2 text-sm text-stone-700">
                    The sample is ranked deterministically from the sealed VVDAT
                    ledger. Record the assigned precinct officer credentials to
                    seal it.
                </p>
                <Form
                    :action="actions.select"
                    method="post"
                    #default="{ errors, processing }"
                    class="mt-4 grid gap-3 sm:grid-cols-3"
                    ><input
                        name="officer_code"
                        placeholder="Assigned officer code"
                        class="min-h-11 border border-stone-400 px-3"
                        required
                    /><input
                        name="officer_pin"
                        inputmode="numeric"
                        maxlength="6"
                        placeholder="Six-digit PIN"
                        class="min-h-11 border border-stone-400 px-3"
                        required
                    /><button
                        type="submit"
                        class="min-h-11 bg-blue-800 px-4 font-bold text-white"
                        :disabled="processing"
                    >
                        {{
                            processing ? 'Selecting...' : 'Select audit sample'
                        }}
                    </button>
                    <p
                        v-if="errors.officer_pin"
                        class="text-sm font-bold text-red-700 sm:col-span-3"
                    >
                        {{ errors.officer_pin }}
                    </p></Form
                >
            </section>

            <template v-else>
                <section class="mt-6 border border-stone-300 p-5">
                    <h2 class="text-lg font-bold">
                        2. Open ballot box and prepare sampled ballots
                    </h2>
                    <p class="mt-2 text-sm text-stone-700">
                        Sample {{ audit.sample_hash?.slice(0, 16) }}. Retrieve
                        the selected paper ballots from the ballot box, arrange
                        them for QR-assisted audit tallying, and keep the
                        official tally unchanged.
                    </p>
                    <ul
                        class="mt-4 divide-y divide-stone-200 border border-stone-200 text-sm"
                    >
                        <li
                            v-for="ballot in audit.sample"
                            :key="ballot.payload_hash"
                            class="flex items-center justify-between gap-3 p-3"
                        >
                            <span
                                >Paper ballot
                                {{
                                    ballot.paper_ballot_serial ?? 'unserialized'
                                }}</span
                            ><code class="text-xs text-stone-600">{{
                                ballot.payload_hash.slice(0, 16)
                            }}</code>
                        </li>
                    </ul>
                </section>

                <section
                    v-if="!audit.pending"
                    class="mt-6 border border-stone-300 p-5"
                >
                    <h2 class="text-lg font-bold">
                        3. Scan each sampled paper ballot QR code
                    </h2>
                    <p class="mt-2 text-sm text-stone-700">
                        Use the device camera for the paper QR code, or use a
                        handheld scanner payload below. The system accepts only
                        QR codes selected for this audit sample.
                    </p>
                    <div class="mt-4 grid gap-6 lg:grid-cols-2">
                        <div>
                            <div
                                class="aspect-[4/3] overflow-hidden border border-stone-300 bg-stone-950"
                            >
                                <video
                                    ref="video"
                                    class="h-full w-full object-cover"
                                    autoplay
                                    muted
                                    playsinline
                                />
                            </div>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <input
                                    v-model="cameraForm.officer_code"
                                    placeholder="Assigned officer code"
                                    class="min-h-11 border border-stone-400 px-3"
                                /><input
                                    v-model="cameraForm.officer_pin"
                                    inputmode="numeric"
                                    maxlength="6"
                                    placeholder="Six-digit PIN"
                                    class="min-h-11 border border-stone-400 px-3"
                                />
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    class="min-h-11 border border-stone-500 px-4 font-bold"
                                    :disabled="cameraStatus === 'starting'"
                                    @click="startCamera"
                                >
                                    Start camera</button
                                ><button
                                    type="button"
                                    class="min-h-11 bg-blue-800 px-4 font-bold text-white"
                                    :disabled="
                                        !canCapture ||
                                        !cameraForm.officer_code ||
                                        !cameraForm.officer_pin
                                    "
                                    @click="captureAndSubmit"
                                >
                                    Capture QR</button
                                ><button
                                    type="button"
                                    class="min-h-11 border border-stone-500 px-4 font-bold"
                                    :disabled="!stream"
                                    @click="stopCamera()"
                                >
                                    Stop camera
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
                            <p
                                v-if="
                                    cameraForm.errors.payload ||
                                    cameraForm.errors.officer_pin
                                "
                                class="mt-3 text-sm font-bold text-red-700"
                            >
                                {{
                                    cameraForm.errors.payload ??
                                    cameraForm.errors.officer_pin
                                }}
                            </p>
                        </div>
                        <Form
                            :action="actions.propose"
                            method="post"
                            #default="{ errors, processing }"
                            class="grid content-start gap-3"
                            ><label class="text-sm font-bold" for="rma-payload"
                                >Scanner payload</label
                            ><textarea
                                id="rma-payload"
                                name="payload"
                                rows="7"
                                placeholder="Scanned paper ballot QR payload"
                                class="border border-stone-400 p-3 font-mono text-xs"
                                required
                            /><input
                                name="officer_code"
                                placeholder="Assigned officer code"
                                class="min-h-11 border border-stone-400 px-3"
                                required
                            /><input
                                name="officer_pin"
                                inputmode="numeric"
                                maxlength="6"
                                placeholder="Six-digit PIN"
                                class="min-h-11 border border-stone-400 px-3"
                                required
                            /><button
                                type="submit"
                                class="min-h-11 bg-blue-800 px-4 font-bold text-white"
                                :disabled="processing"
                            >
                                {{
                                    processing
                                        ? 'Checking...'
                                        : 'Propose paper comparison'
                                }}
                            </button>
                            <p
                                v-if="errors.payload || errors.officer_pin"
                                class="text-sm font-bold text-red-700"
                            >
                                {{ errors.payload ?? errors.officer_pin }}
                            </p></Form
                        >
                    </div>
                </section>

                <section
                    v-else
                    class="mt-6 border border-amber-500 bg-amber-50 p-5"
                >
                    <h2 class="text-lg font-bold">
                        4. Confirm the paper comparison
                    </h2>
                    <p class="mt-2 text-sm text-amber-950">
                        Paper ballot
                        {{
                            audit.pending.paper_ballot_serial ?? 'unserialized'
                        }}
                        is pending an audit finding. Compare the paper marks
                        against these QR-derived selections before recording
                        either result.
                    </p>
                    <dl class="mt-4 grid gap-2 text-sm">
                        <div
                            v-for="(candidateIds, contest) in audit.pending
                                .selections"
                            :key="contest"
                            class="grid grid-cols-[10rem_1fr] gap-3"
                        >
                            <dt class="font-bold">{{ contest }}</dt>
                            <dd>{{ candidateIds.join(', ') }}</dd>
                        </div>
                    </dl>
                    <Form
                        :action="actions.approve"
                        method="post"
                        #default="{ errors, processing }"
                        class="mt-5 grid gap-3"
                        ><input
                            type="hidden"
                            name="payload_hash"
                            :value="audit.pending.payload_hash"
                        />
                        <div class="grid gap-3 sm:grid-cols-2">
                            <input
                                name="officer_code"
                                placeholder="Assigned precinct officer code"
                                class="min-h-11 border border-stone-400 px-3"
                                required
                            /><input
                                name="officer_pin"
                                inputmode="numeric"
                                maxlength="6"
                                placeholder="Assigned officer PIN"
                                class="min-h-11 border border-stone-400 px-3"
                                required
                            /><input
                                name="first_officer_code"
                                placeholder="First board officer code"
                                class="min-h-11 border border-stone-400 px-3"
                                required
                            /><input
                                name="first_officer_pin"
                                inputmode="numeric"
                                maxlength="6"
                                placeholder="First board officer PIN"
                                class="min-h-11 border border-stone-400 px-3"
                                required
                            /><input
                                name="second_officer_code"
                                placeholder="Second board officer code"
                                class="min-h-11 border border-stone-400 px-3"
                                required
                            /><input
                                name="second_officer_pin"
                                inputmode="numeric"
                                maxlength="6"
                                placeholder="Second board officer PIN"
                                class="min-h-11 border border-stone-400 px-3"
                                required
                            />
                        </div>
                        <button
                            type="submit"
                            class="min-h-11 bg-emerald-700 px-4 font-bold text-white"
                            :disabled="processing"
                        >
                            {{
                                processing
                                    ? 'Recording...'
                                    : 'Record dual approval'
                            }}
                        </button>
                        <p
                            v-if="
                                errors.payload_hash ||
                                errors.second_officer_code ||
                                errors.officer_pin
                            "
                            class="text-sm font-bold text-red-700"
                        >
                            {{
                                errors.payload_hash ??
                                errors.second_officer_code ??
                                errors.officer_pin
                            }}
                        </p></Form
                    >
                    <Form
                        :action="actions.discrepancy"
                        method="post"
                        #default="{ errors, processing }"
                        class="mt-6 border-t border-amber-300 pt-5"
                        ><h3 class="font-bold">Record a paper discrepancy</h3>
                        <p class="mt-1 text-sm text-amber-950">
                            Use only when the printed paper ballot does not
                            match the QR-derived selections. This preserves the
                            official VVDAT tally and records an audit exception.
                        </p>
                        <input
                            type="hidden"
                            name="payload_hash"
                            :value="audit.pending.payload_hash"
                        />
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <textarea
                                name="reason"
                                rows="3"
                                placeholder="Observed discrepancy and paper-ballot finding"
                                class="border border-stone-400 p-3 sm:col-span-2"
                                required
                            /><input
                                name="officer_code"
                                placeholder="Assigned precinct officer code"
                                class="min-h-11 border border-stone-400 px-3"
                                required
                            /><input
                                name="officer_pin"
                                inputmode="numeric"
                                maxlength="6"
                                placeholder="Assigned officer PIN"
                                class="min-h-11 border border-stone-400 px-3"
                                required
                            /><input
                                name="first_officer_code"
                                placeholder="First board officer code"
                                class="min-h-11 border border-stone-400 px-3"
                                required
                            /><input
                                name="first_officer_pin"
                                inputmode="numeric"
                                maxlength="6"
                                placeholder="First board officer PIN"
                                class="min-h-11 border border-stone-400 px-3"
                                required
                            /><input
                                name="second_officer_code"
                                placeholder="Second board officer code"
                                class="min-h-11 border border-stone-400 px-3"
                                required
                            /><input
                                name="second_officer_pin"
                                inputmode="numeric"
                                maxlength="6"
                                placeholder="Second board officer PIN"
                                class="min-h-11 border border-stone-400 px-3"
                                required
                            />
                        </div>
                        <button
                            type="submit"
                            class="mt-3 min-h-11 bg-red-700 px-4 font-bold text-white"
                            :disabled="processing"
                        >
                            {{
                                processing
                                    ? 'Recording...'
                                    : 'Record dual-confirmed discrepancy'
                            }}
                        </button>
                        <p
                            v-if="
                                errors.reason ||
                                errors.payload_hash ||
                                errors.second_officer_code ||
                                errors.officer_pin
                            "
                            class="mt-3 text-sm font-bold text-red-700"
                        >
                            {{
                                errors.reason ??
                                errors.payload_hash ??
                                errors.second_officer_code ??
                                errors.officer_pin
                            }}
                        </p></Form
                    >
                </section>

                <section class="mt-6 border border-stone-300 p-5">
                    <div
                        class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div>
                            <h2 class="text-lg font-bold">
                                Live QR-assisted audit tally
                            </h2>
                            <p class="mt-2 text-sm text-stone-700">
                                Each dual-approved paper comparison updates this
                                audit tally. Rows touched by the latest approved
                                scan are highlighted.
                            </p>
                        </div>
                        <p class="text-sm font-bold text-stone-600">
                            {{ audit.auditTally?.accepted_scans ?? 0 }}
                            accepted scans
                        </p>
                    </div>
                    <div
                        v-if="auditTallyRows.length > 0"
                        class="mt-4 divide-y divide-stone-200 border border-stone-200"
                    >
                        <div
                            v-for="row in auditTallyRows"
                            :key="`${row.contest}-${row.candidateId}`"
                            class="grid gap-2 p-3 sm:grid-cols-[9rem_1fr_6rem]"
                            :class="row.latest ? 'bg-yellow-100' : 'bg-white'"
                        >
                            <p class="text-xs font-bold text-stone-600">
                                {{ row.contest }}
                            </p>
                            <div>
                                <p class="font-mono text-sm font-bold">
                                    {{ row.candidateId }}
                                </p>
                                <TallyMarks :count="row.votes" />
                            </div>
                            <p class="text-right text-sm font-bold">
                                {{ row.votes }}
                            </p>
                        </div>
                    </div>
                    <p
                        v-else
                        class="mt-4 border border-dashed border-stone-300 p-4 text-sm text-stone-600"
                    >
                        No paper ballot QR has been dual-approved for the audit
                        tally yet.
                    </p>
                </section>

                <section
                    v-if="!audit.pending"
                    class="mt-6 border border-stone-300 p-5"
                >
                    <h2 class="text-lg font-bold">
                        5. Reconcile and publish the audit
                    </h2>
                    <p
                        v-if="audit.reconciliation"
                        class="mt-2 text-sm text-stone-700"
                    >
                        {{ audit.reconciliation.verified_ballots }} verified,
                        {{ audit.reconciliation.discrepancy_ballots }}
                        discrepancies,
                        {{ audit.reconciliation.pending_ballots }} pending.
                        <strong>{{
                            audit.reconciliation.passed
                                ? 'The current reconciliation passes.'
                                : 'The current reconciliation requires attention.'
                        }}</strong>
                    </p>
                    <Form
                        :action="actions.reconcile"
                        method="post"
                        #default="{ errors, processing }"
                        class="mt-4 grid gap-3 sm:grid-cols-3"
                        ><input
                            name="officer_code"
                            placeholder="Assigned officer code"
                            class="min-h-11 border border-stone-400 px-3"
                            required
                        /><input
                            name="officer_pin"
                            inputmode="numeric"
                            maxlength="6"
                            placeholder="Six-digit PIN"
                            class="min-h-11 border border-stone-400 px-3"
                            required
                        /><button
                            type="submit"
                            class="min-h-11 bg-blue-800 px-4 font-bold text-white"
                            :disabled="processing"
                        >
                            {{
                                processing
                                    ? 'Reconciling...'
                                    : 'Generate reconciliation'
                            }}
                        </button>
                        <p
                            v-if="errors.officer_pin"
                            class="text-sm font-bold text-red-700 sm:col-span-3"
                        >
                            {{ errors.officer_pin }}
                        </p></Form
                    >
                    <Form
                        v-if="
                            audit.reconciliation?.complete &&
                            !audit.evidencePackAvailable
                        "
                        :action="actions.evidencePack"
                        method="post"
                        #default="{ errors, processing }"
                        class="mt-4 grid gap-3 sm:grid-cols-3"
                        ><input
                            name="officer_code"
                            placeholder="Assigned officer code"
                            class="min-h-11 border border-stone-400 px-3"
                            required
                        /><input
                            name="officer_pin"
                            inputmode="numeric"
                            maxlength="6"
                            placeholder="Six-digit PIN"
                            class="min-h-11 border border-stone-400 px-3"
                            required
                        /><button
                            type="submit"
                            class="min-h-11 bg-emerald-700 px-4 font-bold text-white"
                            :disabled="processing"
                        >
                            {{
                                processing
                                    ? 'Building...'
                                    : 'Build officer evidence pack'
                            }}
                        </button>
                        <p
                            v-if="errors.officer_pin"
                            class="text-sm font-bold text-red-700 sm:col-span-3"
                        >
                            {{ errors.officer_pin }}
                        </p></Form
                    >
                    <a
                        v-if="audit.evidencePackAvailable"
                        :href="actions.download"
                        class="mt-4 inline-flex min-h-11 items-center bg-stone-800 px-4 font-bold text-white"
                        >Download officer audit evidence PDF</a
                    >
                    <Form
                        v-if="
                            audit.evidencePackAvailable &&
                            precinct.status === 'published' &&
                            !audit.watcherPublicationAvailable
                        "
                        :action="actions.publish"
                        method="post"
                        #default="{ errors, processing }"
                        class="mt-4 grid gap-3 sm:grid-cols-3"
                        ><input
                            name="officer_code"
                            placeholder="Assigned officer code"
                            class="min-h-11 border border-stone-400 px-3"
                            required
                        /><input
                            name="officer_pin"
                            inputmode="numeric"
                            maxlength="6"
                            placeholder="Six-digit PIN"
                            class="min-h-11 border border-stone-400 px-3"
                            required
                        /><button
                            type="submit"
                            class="min-h-11 bg-blue-800 px-4 font-bold text-white"
                            :disabled="processing"
                        >
                            {{
                                processing
                                    ? 'Publishing...'
                                    : 'Publish redacted watcher summary'
                            }}
                        </button>
                        <p
                            v-if="
                                errors.audit_publication || errors.officer_pin
                            "
                            class="text-sm font-bold text-red-700 sm:col-span-3"
                        >
                            {{ errors.audit_publication ?? errors.officer_pin }}
                        </p></Form
                    >
                    <p
                        v-else-if="
                            audit.evidencePackAvailable &&
                            precinct.status !== 'published'
                        "
                        class="mt-4 text-sm text-stone-700"
                    >
                        Publish the post-close tally and Election Return first;
                        then this room can publish a separate redacted summary
                        for poll watchers.
                    </p>
                    <p
                        v-else-if="audit.watcherPublicationAvailable"
                        class="mt-4 border-l-4 border-emerald-700 bg-emerald-50 p-3 text-sm font-bold text-emerald-950"
                    >
                        A redacted audit summary is published for poll watchers.
                        It excludes individual ballot and officer evidence.
                    </p>
                </section>
            </template>
        </section>
    </main>
</template>
