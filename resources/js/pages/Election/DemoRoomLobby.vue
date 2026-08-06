<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    round: {
        code: string;
        name: string;
        status: string;
        precincts: Array<{
            code: string;
            label: string;
            city_municipality: string | null;
            province: string | null;
            status: string;
        }>;
    };
}>();

const page = usePage();
const feedback = computed(
    () => page.props.flash?.public_simulation?.officer_feedback as
        | string
        | undefined,
);

function statusTone(status: string): string {
    return status === 'open'
        ? 'border-emerald-600 bg-emerald-50 text-emerald-950'
        : status === 'published'
          ? 'border-blue-700 bg-blue-50 text-blue-950'
          : 'border-amber-500 bg-amber-50 text-amber-950';
}
</script>

<template>
    <Head title="AES Demo Room" />
    <main class="min-h-screen bg-stone-100 text-stone-950">
        <div class="grid h-1.5 grid-cols-3">
            <span class="bg-blue-800" /><span class="bg-yellow-400" /><span
                class="bg-red-700"
            />
        </div>
        <header class="border-b border-stone-300 bg-white">
            <div class="mx-auto max-w-6xl px-5 py-8 sm:px-8">
                <p class="text-sm font-bold text-blue-800 uppercase">
                    COMELEC Review Kit
                </p>
                <h1 class="mt-2 text-3xl font-bold">AES Demo Room</h1>
                <p class="mt-3 max-w-3xl text-stone-700">
                    Select a precinct, then choose a role QR: Election Officer,
                    voter, central print station, poll watcher, or auditor.
                </p>
                <div
                    v-if="feedback"
                    class="mt-5 border-l-8 border-emerald-700 bg-emerald-50 p-4 font-semibold text-emerald-950"
                >
                    {{ feedback }}
                </div>
                <Form
                    action="/election/demo-room/refresh"
                    method="post"
                    #default="{ processing }"
                    class="mt-6"
                >
                    <button
                        type="submit"
                        class="min-h-12 border-2 border-blue-800 bg-white px-5 font-bold text-blue-800 disabled:opacity-50"
                        :disabled="processing"
                    >
                        {{
                            processing
                                ? 'Preparing fresh demo set...'
                                : 'Start fresh demo set'
                        }}
                    </button>
                </Form>
            </div>
        </header>
        <section
            class="mx-auto grid max-w-6xl gap-5 px-5 py-8 sm:grid-cols-3 sm:px-8"
        >
            <Link
                v-for="precinct in round.precincts"
                :key="precinct.code"
                :href="`/election/demo-room/${round.code}/${precinct.code}`"
                class="border border-stone-300 bg-white p-5 transition hover:border-blue-800 hover:shadow-sm"
            >
                <div class="flex items-start justify-between gap-3">
                    <span class="text-xs font-bold text-blue-800"
                        >PRECINCT</span
                    >
                    <span
                        class="border px-2 py-1 text-xs font-bold"
                        :class="statusTone(precinct.status)"
                        >{{ precinct.status }}</span
                    >
                </div>
                <h2 class="mt-4 text-2xl font-bold">{{ precinct.code }}</h2>
                <p class="mt-2 font-semibold">{{ precinct.label }}</p>
                <p class="mt-5 text-sm text-stone-600">
                    {{ precinct.city_municipality }}<br />{{
                        precinct.province
                    }}
                </p>
                <span
                    class="mt-6 inline-flex min-h-11 items-center bg-blue-800 px-4 font-bold text-white"
                    >Open role room</span
                >
            </Link>
        </section>
    </main>
</template>
