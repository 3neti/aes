<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import { close, generate } from '@/routes/election/returns';
import { copyDistribution } from '@/routes/election/returns';

defineProps<{
    snapshot: ElectionSnapshot;
    returnArtifact: Record<string, any>;
    returnCopyDistribution: Record<string, any>;
    electionReturnLegalEvidence: Record<string, any>;
}>();
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Election Return">
        <section class="border border-stone-300 bg-white p-5">
            <h2 class="text-lg font-semibold">Election Return</h2>
            <div class="mt-4 flex flex-wrap gap-3">
                <Form v-bind="generate.form()">
                    <button class="primary-button" type="submit">
                        Generate Election Return
                    </button>
                </Form>
                <Form v-bind="copyDistribution.form()">
                    <button class="secondary-button" type="submit">
                        Prepare Copy Distribution & Posting
                    </button>
                </Form>
                <Form v-bind="close.form()">
                    <button class="secondary-button" type="submit">
                        Close Precinct
                    </button>
                </Form>
            </div>
            <dl v-if="returnArtifact.return_hash" class="mt-5 text-sm">
                <dt class="font-semibold">Return Hash</dt>
                <dd class="break-all text-stone-700">
                    {{ returnArtifact.return_hash }}
                </dd>
                <dt class="mt-3 font-semibold">Accepted Ballots</dt>
                <dd>{{ returnArtifact.accepted_ballots }}</dd>
                <dt class="mt-3 font-semibold">PDF Artifact</dt>
                <dd class="break-all text-stone-700">
                    returns/{{ returnArtifact.precinct_id }}-return.pdf
                </dd>
                <dt class="mt-3 font-semibold">Legal Return Evidence</dt>
                <dd class="text-stone-700">
                    {{
                        electionReturnLegalEvidence.exists
                            ? 'Generated'
                            : 'Not generated'
                    }}
                </dd>
                <template v-if="electionReturnLegalEvidence.exists">
                    <dt class="mt-3 font-semibold">Evidence Hash</dt>
                    <dd class="break-all text-stone-700">
                        {{ electionReturnLegalEvidence.evidence_hash }}
                    </dd>
                    <dt class="mt-3 font-semibold">Counts Match</dt>
                    <dd>
                        {{
                            electionReturnLegalEvidence.counts_match
                                ? 'Yes'
                                : 'No'
                        }}
                    </dd>
                    <dt class="mt-3 font-semibold">Evidence Artifact</dt>
                    <dd class="break-all text-stone-700">
                        {{ electionReturnLegalEvidence.artifact }}
                    </dd>
                </template>
            </dl>
            <div
                v-if="returnCopyDistribution.exists"
                class="mt-6 border-t border-stone-200 pt-4 text-sm"
            >
                <h3 class="text-base font-semibold">Copy Distribution</h3>
                <dl class="mt-3 grid gap-2 sm:grid-cols-2">
                    <div>
                        <dt class="font-semibold">Distribution Hash</dt>
                        <dd class="break-all text-stone-700">
                            {{ returnCopyDistribution.distribution_hash }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-semibold">Prepared Copies</dt>
                        <dd>
                            {{ returnCopyDistribution.copy_count }} (required:
                            {{ returnCopyDistribution.required_copy_count }})
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="font-semibold">Posting</dt>
                        <dd class="text-stone-700">
                            {{ returnCopyDistribution.posting.location }} —
                            {{ returnCopyDistribution.posting.status }}
                        </dd>
                    </div>
                </dl>
            </div>
        </section>
    </CeremonyLayout>
</template>

<style scoped>
.primary-button {
    background: rgb(4 120 87);
    color: white;
    padding: 0.7rem 1rem;
    font-weight: 700;
}

.secondary-button {
    border: 1px solid rgb(120 113 108);
    padding: 0.65rem 0.9rem;
    font-weight: 700;
}
</style>
