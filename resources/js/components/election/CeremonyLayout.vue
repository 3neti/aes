<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import { home } from '@/routes';
import { diagnostics } from '@/routes/election';
import { store as storeAttestation } from '@/routes/election/attestations';
import CeremonyStepper from './CeremonyStepper.vue';
import EvidenceSummary from './EvidenceSummary.vue';
import JournalTimeline from './JournalTimeline.vue';
import StatusBadge from './StatusBadge.vue';
import type { ElectionSnapshot } from './types';

const props = defineProps<{
    snapshot: ElectionSnapshot;
    title: string;
}>();

const signatureCanvas = ref<HTMLCanvasElement | null>(null);
const signatureData = ref('');
const isSigning = ref(false);

const precinctLabel = computed(
    () => props.snapshot.configuration.precinct_id || 'Not yet assigned',
);

const configurationStatus = computed(() =>
    props.snapshot.configuration.mapping_hash
        ? 'Configuration loaded'
        : 'Setup required',
);

function signaturePoint(event: PointerEvent): { x: number; y: number } {
    const canvas = signatureCanvas.value;

    if (!canvas) {
        return { x: 0, y: 0 };
    }

    const rect = canvas.getBoundingClientRect();

    return {
        x: ((event.clientX - rect.left) / rect.width) * canvas.width,
        y: ((event.clientY - rect.top) / rect.height) * canvas.height,
    };
}

function signatureContext(): CanvasRenderingContext2D | null {
    const context = signatureCanvas.value?.getContext('2d') ?? null;

    if (!context) {
        return null;
    }

    context.lineCap = 'round';
    context.lineJoin = 'round';
    context.lineWidth = 3;
    context.strokeStyle = '#1c1917';

    return context;
}

function beginSignature(event: PointerEvent): void {
    const context = signatureContext();

    if (!context) {
        return;
    }

    signatureCanvas.value?.setPointerCapture(event.pointerId);
    const point = signaturePoint(event);
    context.beginPath();
    context.moveTo(point.x, point.y);
    isSigning.value = true;
}

function drawSignature(event: PointerEvent): void {
    if (!isSigning.value) {
        return;
    }

    const context = signatureContext();

    if (!context) {
        return;
    }

    const point = signaturePoint(event);
    context.lineTo(point.x, point.y);
    context.stroke();
    signatureData.value = signatureCanvas.value?.toDataURL('image/png') ?? '';
}

function endSignature(event?: PointerEvent): void {
    if (event && signatureCanvas.value?.hasPointerCapture(event.pointerId)) {
        signatureCanvas.value.releasePointerCapture(event.pointerId);
    }

    isSigning.value = false;
    signatureData.value = signatureCanvas.value?.toDataURL('image/png') ?? '';
}

function clearSignature(): void {
    const canvas = signatureCanvas.value;
    const context = canvas?.getContext('2d');

    if (!canvas || !context) {
        return;
    }

    context.clearRect(0, 0, canvas.width, canvas.height);
    signatureData.value = '';
}

onBeforeUnmount(() => {
    isSigning.value = false;
});
</script>

<template>
    <Head :title="title" />

    <div class="min-h-screen bg-stone-100 text-stone-950">
        <div class="grid h-1.5 grid-cols-3" aria-hidden="true">
            <span class="bg-blue-800" />
            <span class="bg-yellow-400" />
            <span class="bg-red-700" />
        </div>

        <header class="border-b border-stone-300 bg-white">
            <div
                class="mx-auto flex w-full max-w-[1500px] flex-col gap-4 px-4 py-4 sm:px-6 lg:px-8"
            >
                <div
                    class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"
                >
                    <Link
                        :href="home.url()"
                        class="group flex items-center gap-3 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-blue-700"
                    >
                        <span
                            class="flex h-11 w-11 shrink-0 items-center justify-center border-2 border-blue-800 bg-blue-50 text-sm font-black text-blue-900"
                            aria-hidden="true"
                        >
                            AES
                        </span>
                        <span>
                            <span
                                class="block text-xs font-bold text-blue-800 uppercase"
                            >
                                {{ snapshot.operatorLabel }}
                            </span>
                            <span
                                class="block text-lg font-bold text-stone-950"
                            >
                                {{ snapshot.appName }}
                            </span>
                        </span>
                    </Link>

                    <div
                        class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:flex sm:items-center"
                    >
                        <div>
                            <p class="text-xs text-stone-500">
                                Clustered precinct
                            </p>
                            <p class="font-bold text-stone-950">
                                {{ precinctLabel }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-stone-500">Ballot style</p>
                            <p class="font-bold text-stone-950">
                                {{
                                    snapshot.configuration.ballot_style_id ||
                                    'Pending setup'
                                }}
                            </p>
                        </div>
                        <StatusBadge
                            :label="configurationStatus"
                            :tone="
                                snapshot.configuration.mapping_hash
                                    ? 'complete'
                                    : 'warning'
                            "
                        />
                    </div>
                </div>
            </div>
        </header>

        <main
            class="mx-auto flex w-full max-w-[1500px] flex-col gap-4 px-4 py-5 sm:px-6 lg:px-8"
        >
            <div
                class="flex flex-col gap-3 border border-stone-300 bg-white px-5 py-4 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <StatusBadge label="Current ceremony" tone="current" />
                        <span class="text-sm font-semibold text-stone-600">
                            {{ snapshot.stageLabel }}
                        </span>
                    </div>
                    <h1
                        class="mt-2 text-2xl font-bold text-stone-950 sm:text-3xl"
                    >
                        {{ snapshot.ceremony }}
                    </h1>
                </div>
                <Link
                    :href="diagnostics.url()"
                    class="text-sm font-bold text-blue-800 underline decoration-blue-300 underline-offset-4"
                >
                    Review evidence and diagnostics
                </Link>
            </div>

            <section
                class="grid gap-3 border-l-4 border-amber-500 bg-amber-50 px-5 py-4 sm:grid-cols-[1fr_auto] sm:items-center"
                aria-label="Next required action"
            >
                <div>
                    <p class="text-xs font-bold text-amber-900 uppercase">
                        Next required action
                    </p>
                    <p class="mt-1 text-lg font-bold text-stone-950">
                        {{ snapshot.nextAction }}
                    </p>
                </div>
                <span class="text-xs font-semibold text-amber-900">
                    Complete this before advancing
                </span>
            </section>

            <div
                class="grid min-w-0 gap-5 xl:grid-cols-[230px_minmax(0,1fr)_300px]"
            >
                <aside class="min-w-0">
                    <CeremonyStepper
                        :current-stage="snapshot.stage"
                        :workflow="snapshot.workflow"
                    />
                </aside>

                <div class="min-w-0 space-y-4">
                    <slot />
                </div>

                <aside class="min-w-0 space-y-4">
                    <EvidenceSummary :counts="snapshot.counts" />

                    <details class="border border-stone-300 bg-white">
                        <summary
                            class="cursor-pointer px-4 py-3 text-sm font-bold text-stone-900 marker:text-blue-700"
                        >
                            Officer sign-off
                        </summary>
                        <div class="border-t border-stone-200 p-4">
                            <p class="text-xs text-stone-600">
                                Record a signed checkpoint after the Electoral
                                Board reviews this ceremony.
                            </p>
                            <Form
                                v-bind="storeAttestation.form()"
                                class="mt-3 space-y-3"
                                reset-on-success
                                #default="{
                                    errors,
                                    processing,
                                    recentlySuccessful,
                                }"
                                @success="clearSignature"
                            >
                                <input
                                    type="hidden"
                                    name="ceremony"
                                    :value="snapshot.ceremony"
                                />
                                <input
                                    type="hidden"
                                    name="stage"
                                    :value="snapshot.stage"
                                />
                                <input
                                    type="hidden"
                                    name="statement"
                                    :value="`${snapshot.ceremony} checkpoint reviewed.`"
                                />
                                <input
                                    type="hidden"
                                    name="signature_data"
                                    :value="signatureData"
                                />
                                <label class="block text-sm">
                                    <span class="font-semibold text-stone-700">
                                        Officer ID
                                    </span>
                                    <input
                                        name="officer_code"
                                        class="mt-1 w-full border border-stone-300 bg-white px-3 py-2.5"
                                        autocomplete="off"
                                        required
                                    />
                                    <span
                                        v-if="errors.officer_code"
                                        class="mt-1 block text-xs font-semibold text-red-700"
                                    >
                                        {{ errors.officer_code }}
                                    </span>
                                </label>
                                <label class="block text-sm">
                                    <span class="font-semibold text-stone-700">
                                        Officer PIN
                                    </span>
                                    <input
                                        name="officer_pin"
                                        type="password"
                                        inputmode="numeric"
                                        class="mt-1 w-full border border-stone-300 bg-white px-3 py-2.5"
                                        autocomplete="off"
                                        required
                                    />
                                    <span
                                        v-if="errors.officer_pin"
                                        class="mt-1 block text-xs font-semibold text-red-700"
                                    >
                                        {{ errors.officer_pin }}
                                    </span>
                                </label>
                                <div class="text-sm">
                                    <div
                                        class="flex items-center justify-between gap-2"
                                    >
                                        <span
                                            class="font-semibold text-stone-700"
                                        >
                                            Signature
                                        </span>
                                        <button
                                            type="button"
                                            class="text-xs font-bold text-blue-800 underline"
                                            @click="clearSignature"
                                        >
                                            Clear
                                        </button>
                                    </div>
                                    <canvas
                                        ref="signatureCanvas"
                                        class="mt-1 h-28 w-full touch-none border border-stone-300 bg-white"
                                        width="560"
                                        height="220"
                                        aria-label="Officer signature pad"
                                        @pointerdown="beginSignature"
                                        @pointermove="drawSignature"
                                        @pointerup="endSignature"
                                        @pointercancel="endSignature"
                                        @pointerleave="endSignature"
                                    />
                                    <span
                                        v-if="errors.signature_data"
                                        class="mt-1 block text-xs font-semibold text-red-700"
                                    >
                                        {{ errors.signature_data }}
                                    </span>
                                </div>
                                <button
                                    type="submit"
                                    class="w-full bg-stone-950 px-3 py-2.5 text-sm font-bold text-white disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="
                                        processing || signatureData === ''
                                    "
                                >
                                    {{
                                        processing
                                            ? 'Recording...'
                                            : 'Record signed checkpoint'
                                    }}
                                </button>
                                <p
                                    v-if="recentlySuccessful"
                                    class="text-xs font-bold text-emerald-700"
                                >
                                    Signed checkpoint recorded.
                                </p>
                            </Form>
                        </div>
                    </details>

                    <JournalTimeline :entries="snapshot.journal" />
                </aside>
            </div>
        </main>
    </div>
</template>
