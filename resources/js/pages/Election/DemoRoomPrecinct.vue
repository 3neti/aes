<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    round: { code: string; name: string };
    precinct: {
        code: string;
        label: string;
        clustered_precinct: string;
        city_municipality: string | null;
        province: string | null;
        status: string;
        officer_name: string;
    };
    roles: Array<{
        label: string;
        description: string;
        url: string;
        qr: string;
    }>;
    officerDefaults: { officer_code: string; officer_pin: string };
}>();
</script>

<template>
    <Head :title="`${precinct.code} demo room`" />
    <main class="min-h-screen bg-stone-100 text-stone-950">
        <div class="grid h-1.5 grid-cols-3">
            <span class="bg-blue-800" /><span class="bg-yellow-400" /><span
                class="bg-red-700"
            />
        </div>
        <header class="border-b border-stone-300 bg-white">
            <div class="mx-auto max-w-6xl px-5 py-6 sm:px-8">
                <Link
                    href="/election/demo-room"
                    class="text-sm font-bold text-blue-800"
                    >All demo precincts</Link
                >
                <h1 class="mt-2 text-3xl font-bold">{{ precinct.label }}</h1>
                <p class="mt-2 text-stone-700">
                    {{ precinct.city_municipality }} ·
                    {{ precinct.province }} · Cluster
                    {{ precinct.clustered_precinct }}
                </p>
                <div
                    class="mt-4 border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950"
                >
                    Demo officer defaults:
                    <strong>{{ officerDefaults.officer_code }}</strong> /
                    <strong>{{ officerDefaults.officer_pin }}</strong>. These
                    are prefilled on officer screens and can be edited.
                </div>
            </div>
        </header>
        <section
            class="mx-auto grid max-w-6xl gap-5 px-5 py-8 sm:grid-cols-2 lg:grid-cols-3 sm:px-8"
        >
            <article
                v-for="role in roles"
                :key="role.label"
                class="border border-stone-300 bg-white p-5"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold text-blue-800 uppercase">
                            Role QR
                        </p>
                        <h2 class="mt-1 text-xl font-bold">
                            {{ role.label }}
                        </h2>
                    </div>
                    <img
                        :src="role.qr"
                        :alt="`${role.label} QR code`"
                        class="h-28 w-28 border border-stone-300 bg-white p-2"
                    />
                </div>
                <p class="mt-4 min-h-12 text-sm text-stone-700">
                    {{ role.description }}
                </p>
                <a
                    :href="role.url"
                    class="mt-5 inline-flex min-h-11 items-center bg-blue-800 px-4 font-bold text-white"
                    >Open {{ role.label }}</a
                >
            </article>
        </section>
    </main>
</template>
