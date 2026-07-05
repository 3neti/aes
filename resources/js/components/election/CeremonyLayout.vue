<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';
import { home } from '@/routes';
import { certification } from '@/routes/election';
import { counting } from '@/routes/election';
import { diagnostics } from '@/routes/election';
import { provision } from '@/routes/election';
import { returns } from '@/routes/election';
import { voting } from '@/routes/election';
import { store as storeAttestation } from '@/routes/election/attestations';
import type { ElectionSnapshot } from './types';

defineProps<{
    snapshot: ElectionSnapshot;
    title: string;
}>();

const signatureCanvas = ref<HTMLCanvasElement | null>(null);
const signatureData = ref('');
const isSigning = ref(false);

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
    if (event) {
        signatureCanvas.value?.releasePointerCapture(event.pointerId);
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
    <main class="min-h-screen bg-stone-50 text-stone-950">
        <div
            class="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-5 sm:px-6 lg:px-8"
        >
            <header class="border-b border-stone-300 pb-4">
                <div
                    class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between"
                >
                    <div>
                        <p
                            class="text-sm font-semibold tracking-wide text-emerald-700 uppercase"
                        >
                            {{ snapshot.appName }}
                        </p>
                        <h1 class="mt-1 text-3xl font-semibold text-stone-950">
                            {{ snapshot.ceremony }}
                        </h1>
                        <p class="mt-2 text-sm text-stone-700">
                            Stage:
                            <span class="font-semibold">{{
                                snapshot.stageLabel
                            }}</span>
                            <span v-if="snapshot.configuration.precinct_id">
                                · Precinct
                                {{ snapshot.configuration.precinct_id }}
                            </span>
                        </p>
                    </div>
                    <nav class="flex flex-wrap gap-2 text-sm">
                        <Link class="nav-link" :href="home.url()">Home</Link>
                        <Link class="nav-link" :href="provision.url()"
                            >Provision</Link
                        >
                        <Link class="nav-link" :href="certification.url()"
                            >Certification</Link
                        >
                        <Link class="nav-link" :href="voting.url()"
                            >Voting</Link
                        >
                        <Link class="nav-link" :href="counting.url()"
                            >Counting</Link
                        >
                        <Link class="nav-link" :href="returns.url()"
                            >Returns</Link
                        >
                        <Link class="nav-link" :href="diagnostics.url()"
                            >Diagnostics</Link
                        >
                    </nav>
                </div>
            </header>

            <section class="grid gap-4 lg:grid-cols-[1fr_320px]">
                <div class="space-y-4">
                    <div class="border border-stone-300 bg-white p-4">
                        <p class="text-sm font-semibold text-stone-600">
                            Next Required Action
                        </p>
                        <p class="mt-1 text-xl font-semibold">
                            {{ snapshot.nextAction }}
                        </p>
                    </div>
                    <slot />
                </div>

                <aside class="space-y-4">
                    <div class="border border-stone-300 bg-white p-4">
                        <h2
                            class="text-sm font-semibold tracking-wide text-stone-600 uppercase"
                        >
                            Evidence Counts
                        </h2>
                        <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <dt class="text-stone-600">Ballots</dt>
                                <dd class="text-lg font-semibold">
                                    {{ snapshot.counts.ballots }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-stone-600">Print Jobs</dt>
                                <dd class="text-lg font-semibold">
                                    {{ snapshot.counts.printJobs }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-stone-600">Accepted</dt>
                                <dd class="text-lg font-semibold">
                                    {{ snapshot.counts.accepted }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-stone-600">Rejected</dt>
                                <dd class="text-lg font-semibold">
                                    {{ snapshot.counts.rejected }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-stone-600">Attestations</dt>
                                <dd class="text-lg font-semibold">
                                    {{ snapshot.counts.attestations }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <div class="border border-stone-300 bg-white p-4">
                        <h2
                            class="text-sm font-semibold tracking-wide text-stone-600 uppercase"
                        >
                            Officer Attestation
                        </h2>
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
                                <span class="font-semibold text-stone-700"
                                    >Officer ID</span
                                >
                                <input
                                    name="officer_code"
                                    class="mt-1 w-full border border-stone-300 p-2"
                                    autocomplete="off"
                                    required
                                />
                                <span
                                    v-if="errors.officer_code"
                                    class="mt-1 block text-xs text-red-700"
                                >
                                    {{ errors.officer_code }}
                                </span>
                            </label>
                            <label class="block text-sm">
                                <span class="font-semibold text-stone-700"
                                    >Officer PIN</span
                                >
                                <input
                                    name="officer_pin"
                                    type="password"
                                    inputmode="numeric"
                                    class="mt-1 w-full border border-stone-300 p-2"
                                    autocomplete="off"
                                    required
                                />
                                <span
                                    v-if="errors.officer_pin"
                                    class="mt-1 block text-xs text-red-700"
                                >
                                    {{ errors.officer_pin }}
                                </span>
                            </label>
                            <div class="text-sm">
                                <div
                                    class="flex items-center justify-between gap-2"
                                >
                                    <span class="font-semibold text-stone-700"
                                        >Officer Signature</span
                                    >
                                    <button
                                        type="button"
                                        class="text-xs font-semibold text-stone-700 underline"
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
                                    @pointerdown="beginSignature"
                                    @pointermove="drawSignature"
                                    @pointerup="endSignature"
                                    @pointercancel="endSignature"
                                    @pointerleave="endSignature"
                                />
                                <span
                                    v-if="errors.signature_data"
                                    class="mt-1 block text-xs text-red-700"
                                >
                                    {{ errors.signature_data }}
                                </span>
                            </div>
                            <button
                                type="submit"
                                class="w-full bg-stone-950 px-3 py-2 text-sm font-semibold text-white disabled:opacity-60"
                                :disabled="processing || signatureData === ''"
                            >
                                Record Attestation
                            </button>
                            <p
                                v-if="recentlySuccessful"
                                class="text-xs font-semibold text-emerald-700"
                            >
                                Attestation recorded.
                            </p>
                        </Form>
                    </div>

                    <div class="border border-stone-300 bg-white p-4">
                        <h2
                            class="text-sm font-semibold tracking-wide text-stone-600 uppercase"
                        >
                            Timeline
                        </h2>
                        <ol class="mt-3 space-y-3 text-sm">
                            <li
                                v-for="entry in snapshot.journal"
                                :key="entry.sequence"
                                class="border-l-2 border-emerald-700 pl-3"
                            >
                                <p class="font-semibold">
                                    {{ entry.event_type }}
                                </p>
                                <p class="text-xs text-stone-600">
                                    {{ entry.occurred_at }}
                                </p>
                            </li>
                            <li
                                v-if="snapshot.journal.length === 0"
                                class="text-stone-600"
                            >
                                No journal entries yet.
                            </li>
                        </ol>
                    </div>
                </aside>
            </section>
        </div>
    </main>
</template>

<style scoped>
.nav-link {
    border: 1px solid rgb(214 211 209);
    background: white;
    padding: 0.35rem 0.65rem;
}
</style>
