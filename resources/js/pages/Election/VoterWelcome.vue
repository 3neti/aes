<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ReviewStationBar from '@/components/election/ReviewStationBar.vue';
import type { ElectionReviewRoomContext } from '@/components/election/types';
import { claim } from '@/routes/election/voter';

defineProps<{
    precinct: {
        election_id: string;
        precinct_id: string;
    };
    claimAction?: string;
    publicSimulation?: boolean;
}>();

const page = usePage();
const reviewRoom = computed(
    () => page.props.electionReviewRoom as ElectionReviewRoomContext,
);
</script>

<template>
    <main
        class="flex min-h-screen items-center justify-center bg-stone-100 p-5 text-stone-950"
    >
        <section
            class="w-full max-w-lg border-t-8 border-blue-800 bg-white p-6 shadow-sm sm:p-8"
        >
            <ReviewStationBar />
            <p class="text-sm font-bold text-blue-800">
                Official voting station
            </p>
            <h1 class="mt-2 text-3xl font-bold">
                Enter your Voter Control Number
            </h1>
            <p class="mt-3 text-stone-700">
                The Election Board gives one four-digit control number to each
                admitted voter. No voter name or identity is entered here.
            </p>

            <Form
                v-bind="claimAction ? { action: claimAction, method: 'post' } : claim.form()"
                #default="{ errors, processing }"
                class="mt-7 space-y-4"
                reset-on-success
            >
                <label class="block">
                    <span class="text-sm font-bold text-stone-700"
                        >Voter Control Number</span
                    >
                    <input
                        class="mt-1 min-h-14 w-full border-2 border-stone-400 px-4 text-center font-mono text-3xl font-bold"
                        name="code"
                        type="text"
                        required
                        autocomplete="off"
                        autofocus
                        inputmode="numeric"
                        maxlength="4"
                        pattern="[0-9]{4}"
                        placeholder="0000"
                    />
                </label>
                <p v-if="errors.code" class="font-bold text-red-700">
                    {{ errors.code }}
                </p>
                <button
                    class="min-h-14 w-full bg-blue-800 px-5 py-3 text-lg font-bold text-white disabled:opacity-50"
                    :class="{
                        'review-next-action-button': reviewRoom.enabled,
                    }"
                    type="submit"
                    :disabled="processing"
                >
                    {{
                        processing
                            ? 'Checking control number...'
                            : 'Begin voting'
                    }}
                </button>
            </Form>

            <p
                class="mt-6 border-t border-stone-200 pt-4 text-sm text-stone-600"
            >
                Precinct {{ precinct.precinct_id }} | {{ precinct.election_id }}
            </p>
        </section>
    </main>
</template>
