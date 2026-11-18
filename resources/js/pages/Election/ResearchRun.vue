<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { index } from '@/routes/election/bundle';
import { close, decide, show } from '@/routes/election/bundle/review';

const props = defineProps<{
    run: {
        id: string;
        bundle_id: string;
        bundle_hash: string;
        stage: string;
        decision_count: number;
        started_at: string;
        closed_at: string | null;
    };
    decisions: {
        case_type: string;
        case_id: string;
        decision: string;
        reviewer_id: number;
        rationale: string;
        created_at: string;
    }[];
    caseType: string | null;
    caseId: string | null;
    caseRecord: Record<string, string | boolean> | null;
    idempotencyKey: string;
}>();

const selectedType = ref(props.caseType ?? 'precinct');
const selectedId = ref(props.caseId ?? '');
const selectedDecision = ref('defer');
const canApprove = computed(
    () =>
        selectedType.value === 'precinct' &&
        props.caseRecord?.status === 'source_supported_pending_review',
);
</script>

<template>
    <Head title="Election bundle research review" />
    <main class="min-h-screen bg-stone-100 text-stone-950">
        <div class="grid h-1.5 grid-cols-3">
            <span class="bg-blue-800" /><span class="bg-yellow-400" /><span
                class="bg-red-700"
            />
        </div>
        <div class="mx-auto max-w-5xl space-y-5 px-5 py-7 sm:px-8">
            <Link
                :href="index.url()"
                class="text-sm font-bold text-blue-800 underline"
                >← Election Bundle Workbench</Link
            >
            <header class="border border-stone-300 bg-white p-6">
                <p
                    class="text-xs font-black tracking-widest text-blue-800 uppercase"
                >
                    Research ceremony
                </p>
                <h1 class="mt-2 text-3xl font-black">Bundle review run</h1>
                <p class="mt-2 text-sm text-stone-600">
                    Bundle {{ run.bundle_id }} · {{ run.stage }} ·
                    {{ run.decision_count }} recorded decisions
                </p>
                <p class="mt-2 font-mono text-xs break-all text-stone-500">
                    SHA-256 {{ run.bundle_hash }}
                </p>
            </header>
            <Link
                v-if="run.stage === 'reviewing'"
                :href="
                    index.url({
                        query: {
                            status: 'source_supported_pending_review',
                            review_decision: 'undecided',
                        },
                    })
                "
                class="inline-block border border-blue-800 bg-white px-4 py-2 text-sm font-bold text-blue-900"
                >Open undecided precinct queue →</Link
            >
            <section
                class="border border-amber-300 bg-amber-50 p-5 text-sm text-amber-950"
            >
                This is research review. Unresolved precincts and correction
                notices remain unresolved until supported proposals are reviewed
                and a new bundle is signed.
            </section>
            <section
                v-if="run.stage === 'reviewing'"
                class="grid gap-5 lg:grid-cols-[1fr_1fr]"
            >
                <div class="border border-stone-300 bg-white p-5">
                    <h2 class="text-xl font-bold">Review a case</h2>
                    <p class="mt-1 text-sm text-stone-600">
                        Inspect the source record in the Workbench before
                        recording a decision.
                    </p>
                    <Form
                        v-bind="decide.form(run.id)"
                        class="mt-5 space-y-3"
                        #default="{ errors, processing }"
                    >
                        <input
                            name="idempotency_key"
                            type="hidden"
                            :value="idempotencyKey"
                        />
                        <label class="block text-sm font-bold"
                            >Case type
                            <select
                                v-model="selectedType"
                                name="case_type"
                                class="mt-1 w-full border border-stone-300 px-3 py-2 font-normal"
                            >
                                <option value="precinct">
                                    Precinct district assignment
                                </option>
                                <option value="correction">
                                    Correction notice
                                </option>
                            </select>
                        </label>
                        <label class="block text-sm font-bold"
                            >Case ID
                            <input
                                v-model="selectedId"
                                name="case_id"
                                required
                                class="mt-1 w-full border border-stone-300 px-3 py-2 font-normal"
                            />
                        </label>
                        <label class="block text-sm font-bold"
                            >Decision
                            <select
                                v-model="selectedDecision"
                                name="decision"
                                class="mt-1 w-full border border-stone-300 px-3 py-2 font-normal"
                            >
                                <option value="defer">
                                    Defer pending evidence
                                </option>
                                <option value="reject">
                                    Reject current proposal
                                </option>
                                <option v-if="canApprove" value="approve">
                                    Approve source-supported district
                                </option>
                            </select>
                        </label>
                        <label class="block text-sm font-bold"
                            >Rationale
                            <textarea
                                name="rationale"
                                required
                                minlength="10"
                                rows="4"
                                class="mt-1 w-full border border-stone-300 px-3 py-2 font-normal"
                                placeholder="Record why the evidence supports this decision."
                            />
                        </label>
                        <p
                            v-if="
                                errors.research ||
                                errors.rationale ||
                                errors.case_id
                            "
                            class="text-sm text-red-700"
                        >
                            {{
                                errors.research ||
                                errors.rationale ||
                                errors.case_id
                            }}
                        </p>
                        <button
                            type="submit"
                            :disabled="processing"
                            class="bg-blue-800 px-5 py-2 font-bold text-white disabled:opacity-50"
                        >
                            Record decision
                        </button>
                    </Form>
                </div>
                <div class="border border-stone-300 bg-white p-5">
                    <h2 class="text-xl font-bold">Source evidence</h2>
                    <template v-if="caseRecord">
                        <dl class="mt-4 space-y-2 text-sm">
                            <div
                                v-for="(value, key) in caseRecord"
                                :key="key"
                                class="border-b border-stone-100 pb-2"
                            >
                                <dt class="text-xs font-bold text-stone-500">
                                    {{ String(key).replaceAll('_', ' ') }}
                                </dt>
                                <dd class="break-all">{{ value }}</dd>
                            </div>
                        </dl>
                    </template>
                    <p v-else class="mt-4 text-sm text-stone-600">
                        Open a case from the Workbench to see its source
                        evidence here.
                    </p>
                    <p
                        v-if="selectedType === 'correction'"
                        class="mt-4 text-sm text-amber-800"
                    >
                        Correction approval is disabled until a sourced
                        before-and-after proposal exists.
                    </p>
                </div>
            </section>
            <section class="border border-stone-300 bg-white p-5">
                <h2 class="text-xl font-bold">Recent decisions</h2>
                <div
                    v-if="decisions.length"
                    class="mt-3 divide-y divide-stone-200"
                >
                    <Link
                        v-for="item in decisions"
                        :key="item.case_type + item.case_id + item.created_at"
                        :href="
                            show.url(
                                { run: run.id },
                                {
                                    query: {
                                        case_type: item.case_type,
                                        case_id: item.case_id,
                                    },
                                },
                            )
                        "
                        class="block py-3 text-sm hover:bg-stone-50"
                    >
                        <strong class="text-blue-800"
                            >{{ item.case_type }} {{ item.case_id }}</strong
                        >
                        <span class="ml-2">{{ item.decision }}</span>
                        <span class="block text-xs text-stone-500"
                            >Reviewer {{ item.reviewer_id }} ·
                            {{ item.created_at }} · {{ item.rationale }}</span
                        >
                    </Link>
                </div>
                <p v-else class="mt-3 text-sm text-stone-600">
                    No decisions recorded yet.
                </p>
            </section>
            <section
                v-if="run.stage === 'reviewing'"
                class="border border-stone-300 bg-white p-5"
            >
                <h2 class="font-bold">Close this research run</h2>
                <p class="mt-1 text-sm text-stone-600">
                    Closing exports a hashed decision ledger. It does not mark
                    unresolved coverage as ready.
                </p>
                <Form
                    v-bind="close.form(run.id)"
                    class="mt-4"
                    #default="{ errors, processing }"
                >
                    <input
                        name="idempotency_key"
                        type="hidden"
                        :value="idempotencyKey"
                    />
                    <p v-if="errors.research" class="mb-2 text-sm text-red-700">
                        {{ errors.research }}
                    </p>
                    <button
                        type="submit"
                        :disabled="processing"
                        class="border border-stone-900 px-4 py-2 text-sm font-bold disabled:opacity-50"
                    >
                        Close and export research review
                    </button>
                </Form>
            </section>
        </div>
    </main>
</template>
