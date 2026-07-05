<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import { certifyDevices } from '@/routes/election/diagnostics';

type AttestationArtifact = {
    attestation_id: string;
    attested_at: string | null;
    ceremony: string | null;
    stage: string | null;
    officer_name: string | null;
    officer_role: string | null;
    attestation_hash: string | null;
    attestation_artifact: string;
    attestation_url: string;
    attestation_download_url: string;
    signature_artifact_hash: string | null;
    signature_artifact: string | null;
    signature_url: string | null;
    signature_download_url: string | null;
};

defineProps<{
    snapshot: ElectionSnapshot;
    diagnostics: {
        attestation_artifacts?: AttestationArtifact[];
        [key: string]: unknown;
    };
}>();
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Diagnostics">
        <section class="border border-stone-300 bg-white p-5">
            <h2 class="text-lg font-semibold">Appliance Diagnostics</h2>
            <Form v-bind="certifyDevices.form()" class="mt-4">
                <button class="primary-button" type="submit">
                    Certify Device Adapters
                </button>
            </Form>
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <div
                    v-for="(value, key) in diagnostics"
                    :key="key"
                    v-show="key !== 'attestation_artifacts'"
                    class="border border-stone-200 p-3"
                >
                    <dt class="font-semibold">{{ key }}</dt>
                    <dd class="mt-1 break-all text-stone-700">
                        {{
                            typeof value === 'object'
                                ? JSON.stringify(value)
                                : value
                        }}
                    </dd>
                </div>
            </dl>
        </section>

        <section class="border border-stone-300 bg-white p-5">
            <div class="flex flex-col gap-2 sm:flex-row sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold">
                        Attestation Evidence Bundle
                    </h2>
                    <p class="mt-1 text-sm text-stone-700">
                        {{ diagnostics.attestation_artifacts?.length ?? 0 }}
                        attestation records
                    </p>
                </div>
            </div>

            <div
                v-if="diagnostics.attestation_artifacts?.length"
                class="mt-4 space-y-4"
            >
                <article
                    v-for="artifact in diagnostics.attestation_artifacts"
                    :key="artifact.attestation_id"
                    class="border border-stone-200 p-4"
                >
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div>
                            <h3 class="font-semibold">
                                {{ artifact.attestation_id }}
                            </h3>
                            <p class="mt-1 text-sm text-stone-700">
                                {{ artifact.ceremony }} ·
                                {{ artifact.officer_name }}
                            </p>
                            <p class="mt-1 text-xs text-stone-600">
                                {{ artifact.attested_at }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2 text-sm">
                            <a
                                class="artifact-link"
                                :href="artifact.attestation_url"
                                target="_blank"
                                rel="noreferrer"
                            >
                                View JSON
                            </a>
                            <a
                                class="artifact-link"
                                :href="artifact.attestation_download_url"
                            >
                                Download JSON
                            </a>
                            <a
                                v-if="artifact.signature_url"
                                class="artifact-link"
                                :href="artifact.signature_url"
                                target="_blank"
                                rel="noreferrer"
                            >
                                View Signature
                            </a>
                            <a
                                v-if="artifact.signature_download_url"
                                class="artifact-link"
                                :href="artifact.signature_download_url"
                            >
                                Download Signature
                            </a>
                        </div>
                    </div>

                    <dl class="mt-4 grid gap-3 text-xs sm:grid-cols-2">
                        <div>
                            <dt class="font-semibold text-stone-700">
                                Attestation Artifact
                            </dt>
                            <dd class="mt-1 break-all text-stone-600">
                                {{ artifact.attestation_artifact }}
                            </dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-stone-700">
                                Signature Artifact
                            </dt>
                            <dd class="mt-1 break-all text-stone-600">
                                {{ artifact.signature_artifact ?? 'Missing' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-stone-700">
                                Attestation Hash
                            </dt>
                            <dd class="mt-1 break-all text-stone-600">
                                {{ artifact.attestation_hash }}
                            </dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-stone-700">
                                Signature Hash
                            </dt>
                            <dd class="mt-1 break-all text-stone-600">
                                {{ artifact.signature_artifact_hash }}
                            </dd>
                        </div>
                    </dl>
                </article>
            </div>
            <p v-else class="mt-4 text-sm text-stone-700">
                No attestation artifacts have been recorded.
            </p>
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

.artifact-link {
    border: 1px solid rgb(120 113 108);
    padding: 0.4rem 0.6rem;
    font-weight: 700;
}
</style>
