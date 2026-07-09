<script setup lang="ts">
import { Form, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import { complete, scan } from '@/routes/election/counting';

type ScanFeedback = {
    status: 'accepted' | 'rejected';
    adapter: string;
    sequence: number | null;
    ballot_id: string | null;
    payload_hash: string | null;
    raw_payload_hash: string;
    reason: string | null;
};

defineProps<{
    snapshot: ElectionSnapshot;
    tally: {
        accepted_ballots: number;
        rejected_ballots: number;
        tally: Record<string, Record<string, number>>;
    };
    closePollsLegalEvidence: {
        exists: boolean;
        run_id: string | null;
        precinct_id: string | null;
        generated_at: string | null;
        evidence_hash: string | null;
        accepted_ballots_before_counting: number | null;
        rejected_ballots_before_counting: number | null;
        artifact: string;
    };
    countingLegalEvidence: {
        exists: boolean;
        run_id: string | null;
        precinct_id: string | null;
        generated_at: string | null;
        evidence_hash: string | null;
        accepted_ballots: number | null;
        rejected_ballots: number | null;
        tally_hash: string | null;
        artifact: string;
    };
    scanFeedback?: ScanFeedback | null;
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

onBeforeUnmount(() => stopCamera(false));
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Counting">
        <section class="border border-stone-300 bg-white p-5">
            <div class="grid gap-5 lg:grid-cols-2">
                <div>
                    <h2 class="text-lg font-semibold">Scan Ballot Payload</h2>
                    <Form v-bind="scan.form()" class="mt-4 space-y-3">
                        <textarea
                            name="payload"
                            class="h-32 w-full border border-stone-300 p-3 text-sm"
                            required
                        />
                        <button class="primary-button" type="submit">
                            Accept Scan
                        </button>
                    </Form>
                </div>

                <div>
                    <h2 class="text-lg font-semibold">Camera Capture</h2>
                    <div class="mt-4 space-y-3">
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
                        <div class="flex flex-wrap gap-2">
                            <button
                                class="secondary-button"
                                type="button"
                                :disabled="cameraStatus === 'starting'"
                                @click="startCamera"
                            >
                                Start Camera
                            </button>
                            <button
                                class="primary-button disabled:opacity-60"
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
                            class="text-sm font-semibold"
                            :class="
                                cameraStatus === 'error'
                                    ? 'text-red-700'
                                    : 'text-emerald-700'
                            "
                        >
                            {{ cameraMessage }}
                        </p>
                    </div>
                </div>
            </div>

            <div
                v-if="scanFeedback"
                class="mt-5 border p-4"
                :class="
                    scanFeedback.status === 'accepted'
                        ? 'border-emerald-700 bg-emerald-50 text-emerald-950'
                        : 'border-red-700 bg-red-50 text-red-950'
                "
            >
                <div
                    class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div>
                        <p
                            class="text-sm font-semibold tracking-wide uppercase"
                        >
                            {{
                                scanFeedback.status === 'accepted'
                                    ? 'Scan Accepted'
                                    : 'Scan Rejected'
                            }}
                        </p>
                        <p class="mt-1 text-lg font-semibold">
                            {{
                                scanFeedback.status === 'accepted'
                                    ? scanFeedback.ballot_id
                                    : scanFeedback.reason
                            }}
                        </p>
                    </div>
                    <div class="text-sm sm:text-right">
                        <p class="font-semibold">
                            {{ scanFeedback.adapter }}
                        </p>
                        <p v-if="scanFeedback.sequence">
                            Sequence {{ scanFeedback.sequence }}
                        </p>
                    </div>
                </div>
                <dl class="mt-3 grid gap-2 text-xs sm:grid-cols-2">
                    <div v-if="scanFeedback.payload_hash">
                        <dt class="font-semibold">Payload Hash</dt>
                        <dd class="break-all">
                            {{ scanFeedback.payload_hash }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-semibold">Raw Input Hash</dt>
                        <dd class="break-all">
                            {{ scanFeedback.raw_payload_hash }}
                        </dd>
                    </div>
                </dl>
            </div>
        </section>

        <section class="border border-stone-300 bg-white p-5">
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
            >
                <div>
                    <h2 class="text-lg font-semibold">Current Tally</h2>
                    <p class="mt-1 text-sm text-stone-700">
                        Accepted {{ tally.accepted_ballots }} · Rejected
                        {{ tally.rejected_ballots }}
                    </p>
                </div>
                <Form v-bind="complete.form()" #default="{ errors }">
                    <button class="secondary-button" type="submit">
                        Complete Counting
                    </button>
                    <p
                        v-if="errors.lifecycle"
                        class="mt-2 text-sm font-semibold text-rose-700"
                    >
                        {{ errors.lifecycle }}
                    </p>
                </Form>
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div
                    v-for="(totals, contest) in tally.tally"
                    :key="contest"
                    class="border border-stone-200 p-3"
                >
                    <h3 class="font-semibold">{{ contest }}</h3>
                    <dl class="mt-2 text-sm">
                        <div
                            v-for="(votes, candidate) in totals"
                            :key="candidate"
                            class="flex justify-between gap-3"
                        >
                            <dt>{{ candidate }}</dt>
                            <dd class="font-semibold">{{ votes }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </section>

        <section class="border border-stone-300 bg-white p-5">
            <h2 class="text-lg font-semibold">Legal Evidence</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <dl class="grid gap-2 text-xs">
                    <dt class="font-semibold">Close Polls Legal Evidence</dt>
                    <dd
                        v-if="closePollsLegalEvidence.exists"
                        class="text-stone-700"
                    >
                        <p>{{ closePollsLegalEvidence.evidence_hash }}</p>
                        <p class="mt-1">
                            Before-counting ballots:
                            {{ closePollsLegalEvidence.accepted_ballots_before_counting }} /
                            rejected {{ closePollsLegalEvidence.rejected_ballots_before_counting }}
                        </p>
                    </dd>
                    <dd v-else class="text-stone-500">Not available yet.</dd>
                </dl>

                <dl class="grid gap-2 text-xs">
                    <dt class="font-semibold">Counting Legal Evidence</dt>
                    <dd
                        v-if="countingLegalEvidence.exists"
                        class="text-stone-700"
                    >
                        <p>{{ countingLegalEvidence.evidence_hash }}</p>
                        <p class="mt-1">
                            Tally:
                            {{ countingLegalEvidence.accepted_ballots }} /
                            {{ countingLegalEvidence.rejected_ballots }}
                        </p>
                    </dd>
                    <dd v-else class="text-stone-500">Not available yet.</dd>
                </dl>
            </div>
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

.secondary-button {
    border: 1px solid rgb(120 113 108);
    padding: 0.65rem 0.9rem;
    font-weight: 700;
}
</style>
