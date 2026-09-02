<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

const props = defineProps<{
    title: string;
    documentLabel: string;
    pdfUrl: string;
    backUrl: string;
    autoPrint: boolean;
    instructions: string;
}>();

const frame = ref<HTMLIFrameElement | null>(null);
const printAttempted = ref(false);

function printDocument(): void {
    printAttempted.value = true;

    try {
        frame.value?.contentWindow?.focus();
        frame.value?.contentWindow?.print();
    } catch {
        window.print();
    }
}

onMounted(() => {
    if (!props.autoPrint) {
        return;
    }

    window.setTimeout(printDocument, 900);
});
</script>

<template>
    <Head :title="title" />

    <main class="min-h-screen bg-stone-100 text-stone-950">
        <section class="border-b border-stone-300 bg-white px-5 py-4">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wide text-blue-800">
                        Printing station
                    </p>
                    <h1 class="text-2xl font-bold">{{ documentLabel }}</h1>
                    <p class="mt-1 max-w-2xl text-sm text-stone-700">
                        {{ instructions }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button
                        class="min-h-11 border border-blue-700 bg-blue-700 px-4 py-2 text-sm font-bold text-white"
                        type="button"
                        @click="printDocument"
                    >
                        Print again
                    </button>
                    <a
                        :href="pdfUrl"
                        class="min-h-11 border border-stone-400 bg-white px-4 py-2 text-sm font-bold text-stone-900"
                        target="_blank"
                    >
                        Open PDF
                    </a>
                    <Link
                        :href="backUrl"
                        class="min-h-11 border border-stone-400 bg-white px-4 py-2 text-sm font-bold text-stone-900"
                    >
                        Back to officer
                    </Link>
                </div>
            </div>
        </section>

        <section class="mx-auto max-w-6xl px-5 py-4">
            <div
                v-if="printAttempted"
                class="mb-4 border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-900"
            >
                Print dialog requested. If it did not appear, use Print again or
                Open PDF.
            </div>

            <iframe
                ref="frame"
                :src="pdfUrl"
                class="h-[calc(100vh-180px)] min-h-[620px] w-full border border-stone-300 bg-white"
                title="Printable election document"
            />
        </section>
    </main>
</template>
