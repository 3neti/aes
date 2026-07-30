<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';

defineProps<{
    policy: {
        purpose: string;
        retention_days: number;
        data_practices: string[];
    };
    acceptAction: string;
}>();
</script>

<template>
    <Head title="Public simulation participation" />
    <main class="flex min-h-screen items-center justify-center bg-stone-100 p-5 text-stone-950">
        <section class="w-full max-w-2xl border-t-8 border-blue-800 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-bold text-blue-800">Alternative Election System</p>
            <h1 class="mt-2 text-3xl font-bold">Public election simulation</h1>
            <p class="mt-4 text-stone-700">{{ policy.purpose }}</p>

            <section class="mt-6 border-y border-stone-200 py-5">
                <ul class="space-y-3 text-sm leading-6 text-stone-800">
                    <li v-for="practice in policy.data_practices" :key="practice">{{ practice }}</li>
                </ul>
                <p class="mt-5 text-sm font-semibold text-stone-900">Simulation evidence is scheduled for review for {{ policy.retention_days }} days.</p>
            </section>

            <Form :action="acceptAction" method="post" #default="{ processing }" class="mt-6">
                <button class="min-h-12 w-full bg-blue-800 px-5 font-bold text-white disabled:opacity-50" type="submit" :disabled="processing">
                    {{ processing ? 'Opening voting station...' : 'Continue to voting station' }}
                </button>
            </Form>
        </section>
    </main>
</template>
