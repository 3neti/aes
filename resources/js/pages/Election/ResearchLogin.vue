<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { index } from '@/routes/election/bundle';
import { authenticate } from '@/routes/election/bundle/review';
</script>

<template>
    <Head title="Research reviewer login" />
    <main class="min-h-screen bg-stone-100 px-5 py-12 text-stone-950">
        <div class="mx-auto max-w-md border border-stone-300 bg-white p-7">
            <p
                class="text-xs font-black tracking-widest text-blue-800 uppercase"
            >
                Election bundle research
            </p>
            <h1 class="mt-3 text-2xl font-black">Reviewer sign in</h1>
            <p class="mt-2 text-sm text-stone-600">
                Use your named AES research reviewer account to record
                decisions.
            </p>
            <Form
                v-bind="authenticate.form()"
                class="mt-6 space-y-4"
                #default="{ errors, processing }"
            >
                <label class="block text-sm font-bold"
                    >Email
                    <input
                        name="email"
                        type="email"
                        autocomplete="username"
                        required
                        class="mt-1 w-full border border-stone-300 px-3 py-2 font-normal"
                    />
                </label>
                <label class="block text-sm font-bold"
                    >Password
                    <input
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        required
                        class="mt-1 w-full border border-stone-300 px-3 py-2 font-normal"
                    />
                </label>
                <p v-if="errors.email" class="text-sm text-red-700">
                    {{ errors.email }}
                </p>
                <button
                    type="submit"
                    :disabled="processing"
                    class="w-full bg-blue-800 px-4 py-2 font-bold text-white disabled:opacity-50"
                >
                    Sign in
                </button>
            </Form>
            <Link
                :href="index.url()"
                class="mt-5 inline-block text-sm font-semibold text-blue-800 underline"
                >Back to Workbench</Link
            >
        </div>
    </main>
</template>
