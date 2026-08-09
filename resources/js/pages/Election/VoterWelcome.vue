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
    joinQueueAction?: string;
    admissionQueue?: {
        enabled: boolean;
        status: string;
        ticket_number?: string;
        position?: number | null;
        expires_at?: string;
    };
    publicSimulation?: boolean;
    initialControlNumber?: string | null;
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

            <section
                v-if="publicSimulation && admissionQueue?.enabled"
                class="mt-6 border border-blue-200 bg-blue-50 p-4 text-left"
            >
                <template v-if="admissionQueue.status === 'paused'">
                    <p class="font-bold text-blue-950">
                        Admission line temporarily paused
                    </p>
                    <p class="mt-1 text-sm text-blue-950">
                        The Election Officer has temporarily paused new waiting
                        tickets. A previously issued control number remains
                        valid.
                    </p>
                </template>
                <template
                    v-else-if="
                        ['not_joined', 'expired', 'missing'].includes(
                            admissionQueue.status,
                        )
                    "
                >
                    <p class="font-bold text-blue-950">
                        Need to wait for admission?
                    </p>
                    <p class="mt-1 text-sm text-blue-950">
                        Take an anonymous waiting ticket. The Election Officer
                        still verifies and admits voters in person before
                        issuing a control number.
                    </p>
                    <Form
                        :action="joinQueueAction"
                        method="post"
                        #default="{ errors, processing }"
                        class="mt-3"
                    >
                        <button
                            class="min-h-11 bg-blue-800 px-4 font-bold text-white disabled:opacity-50"
                            type="submit"
                            :disabled="processing"
                        >
                            {{
                                processing
                                    ? 'Joining...'
                                    : 'Take waiting ticket'
                            }}
                        </button>
                        <p
                            v-if="errors.queue"
                            class="mt-2 font-bold text-red-700"
                        >
                            {{ errors.queue }}
                        </p>
                    </Form>
                </template>
                <template v-else-if="admissionQueue.status === 'waiting'">
                    <p class="font-bold text-blue-950">
                        Waiting ticket {{ admissionQueue.ticket_number }}
                    </p>
                    <p class="mt-1 text-sm text-blue-950">
                        {{
                            admissionQueue.position === 1
                                ? 'You are next for Election Officer admission.'
                                : `Position ${admissionQueue.position} in the waiting line.`
                        }}
                        Keep this tablet with you and wait for the officer's
                        instruction.
                    </p>
                </template>
                <template v-else-if="admissionQueue.status === 'released'">
                    <p class="font-bold text-emerald-900">
                        Waiting ticket {{ admissionQueue.ticket_number }} has
                        been released.
                    </p>
                    <p class="mt-1 text-sm text-emerald-900">
                        Present yourself to the Election Officer for the
                        four-digit control number. This page never displays the
                        control number.
                    </p>
                </template>
            </section>

            <Form
                v-bind="
                    claimAction
                        ? { action: claimAction, method: 'post' }
                        : claim.form()
                "
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
                        :value="initialControlNumber ?? ''"
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
