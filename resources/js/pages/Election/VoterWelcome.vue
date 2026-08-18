<script setup lang="ts">
import { Form, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import ReviewStationBar from '@/components/election/ReviewStationBar.vue';
import type { ElectionReviewRoomContext } from '@/components/election/types';
import { claim } from '@/routes/election/voter';

const props = defineProps<{
    precinct: {
        election_id: string;
        precinct_id: string;
    };
    claimAction?: string;
    demoControlNumberAction?: string;
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
const fallbackClaimForm = claim.form();
const claimUrl = computed(() => props.claimAction ?? fallbackClaimForm.action);
const errors = computed(() => page.props.errors as Record<string, string>);
const controlNumber = ref(props.initialControlNumber ?? '');
const controlInput = ref<HTMLInputElement | null>(null);
const submitting = ref(false);
const generating = ref(false);
const generationError = ref<string | null>(null);
const generatedControlNumber = ref<{
    code: string;
    expires_at: string;
} | null>(null);
const showGeneratedControlNumber = ref(false);

const shouldGenerateDemoControlNumber = computed(() => {
    const value = controlNumber.value.trim();

    return (
        Boolean(props.demoControlNumberAction) &&
        (value === '' || !/^[0-9]{4}$/.test(value) || /^0+$/.test(value))
    );
});

function csrfToken(): string | null {
    return document
        .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.getAttribute('content') ?? null;
}

async function generateDemoControlNumber(): Promise<void> {
    if (!props.demoControlNumberAction || generating.value) {
        return;
    }

    generating.value = true;
    generationError.value = null;

    try {
        const token = csrfToken();
        const response = await fetch(props.demoControlNumberAction, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-CSRF-TOKEN': token } : {}),
            },
            body: JSON.stringify({}),
        });
        const payload = await response.json();

        if (!response.ok) {
            const message =
                payload?.errors?.control_number?.[0] ??
                payload?.message ??
                'Unable to generate a voter control number.';

            throw new Error(message);
        }

        generatedControlNumber.value = {
            code: payload.code,
            expires_at: payload.expires_at,
        };
        showGeneratedControlNumber.value = true;
    } catch (error) {
        generationError.value =
            error instanceof Error
                ? error.message
                : 'Unable to generate a voter control number.';
    } finally {
        generating.value = false;
    }
}

function submitControlNumber(): void {
    if (shouldGenerateDemoControlNumber.value) {
        void generateDemoControlNumber();

        return;
    }

    submitting.value = true;
    router.post(
        claimUrl.value,
        { code: controlNumber.value.trim() },
        {
            preserveScroll: true,
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

async function useGeneratedControlNumber(): Promise<void> {
    if (!generatedControlNumber.value) {
        return;
    }

    controlNumber.value = generatedControlNumber.value.code;
    showGeneratedControlNumber.value = false;
    await nextTick();
    controlInput.value?.focus();
}
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

            <form class="mt-7 space-y-4" @submit.prevent="submitControlNumber">
                <label class="block">
                    <span class="text-sm font-bold text-stone-700"
                        >Voter Control Number</span
                    >
                    <input
                        ref="controlInput"
                        v-model="controlNumber"
                        class="mt-1 min-h-14 w-full border-2 border-stone-400 px-4 text-center font-mono text-3xl font-bold"
                        name="code"
                        type="text"
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
                <p
                    v-if="generationError"
                    class="border border-red-200 bg-red-50 p-3 font-bold text-red-700"
                >
                    {{ generationError }}
                </p>
                <button
                    class="min-h-14 w-full bg-blue-800 px-5 py-3 text-lg font-bold text-white disabled:opacity-50"
                    :class="{
                        'review-next-action-button': reviewRoom.enabled,
                    }"
                    type="submit"
                    :disabled="submitting || generating"
                >
                    {{
                        generating
                            ? 'Generating voter control number...'
                            : submitting
                              ? 'Checking control number...'
                              : 'Begin voting'
                    }}
                </button>
            </form>

            <p
                class="mt-6 border-t border-stone-200 pt-4 text-sm text-stone-600"
            >
                Precinct {{ precinct.precinct_id }} | {{ precinct.election_id }}
            </p>
        </section>

        <section
            v-if="showGeneratedControlNumber && generatedControlNumber"
            class="fixed inset-0 z-50 flex items-center justify-center bg-stone-950/60 p-5"
            role="dialog"
            aria-modal="true"
            aria-labelledby="generated-control-number-title"
        >
            <div class="w-full max-w-md bg-white p-6 shadow-xl">
                <p class="text-sm font-bold text-blue-800">Voter demo helper</p>
                <h2
                    id="generated-control-number-title"
                    class="mt-2 text-2xl font-bold text-stone-950"
                >
                    Use this voter control number
                </h2>
                <p class="mt-3 text-stone-700">
                    This number was created for this demo tablet. In the actual
                    precinct flow, the Election Officer still issues the number
                    after physical voter verification.
                </p>
                <p
                    class="mt-5 border-2 border-blue-800 bg-blue-50 px-4 py-5 text-center font-mono text-5xl font-bold tracking-widest text-blue-950"
                >
                    {{ generatedControlNumber.code }}
                </p>
                <p class="mt-3 text-sm text-stone-600">
                    Expires {{ generatedControlNumber.expires_at }}.
                </p>
                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                    <button
                        class="min-h-12 bg-blue-800 px-4 font-bold text-white"
                        data-testid="use-generated-control-number"
                        type="button"
                        @click="useGeneratedControlNumber"
                    >
                        Use this voter control number
                    </button>
                    <button
                        class="min-h-12 border border-stone-300 px-4 font-bold text-stone-800"
                        type="button"
                        :disabled="generating"
                        @click="generateDemoControlNumber"
                    >
                        Generate another number
                    </button>
                </div>
            </div>
        </section>
    </main>
</template>
