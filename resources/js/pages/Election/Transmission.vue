<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import { custody, preparePackage, send } from '@/routes/election/transmission';

defineProps<{
    snapshot: ElectionSnapshot;
    transmission: Record<string, any>;
    deliveryPackage: Record<string, any>;
    custody: Record<string, any>;
}>();
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Official Handoff">
        <section class="border border-stone-300 bg-white p-5">
            <h2 class="text-lg font-semibold">Official Handoff</h2>

            <div class="mt-4 flex flex-wrap gap-3">
                <Form v-bind="preparePackage.form()">
                    <button class="primary-button" type="submit">
                        Prepare Delivery Package
                    </button>
                </Form>
                <Form v-bind="send.form()">
                    <button class="secondary-button" type="submit">
                        Prepare Transmission Report
                    </button>
                </Form>
                <Form v-bind="custody.form()">
                    <button class="secondary-button" type="submit">
                        Record Custody
                    </button>
                </Form>
            </div>

            <div class="mt-6 grid gap-4 xl:grid-cols-3">
                <article class="rounded border border-stone-200 p-4">
                    <h3 class="text-sm font-semibold text-stone-700">
                        Delivery Package
                    </h3>
                    <dl class="mt-3 space-y-2 text-sm">
                        <template v-if="deliveryPackage.exists">
                            <div>
                                <dt class="text-stone-600">Package</dt>
                                <dd>
                                    {{ deliveryPackage.package_id }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-stone-600">Package Hash</dt>
                                <dd class="break-all text-stone-700">
                                    {{ deliveryPackage.package_hash }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-stone-600">Artifacts</dt>
                                <dd>
                                    {{ deliveryPackage.artifact_count }} required artifacts complete:
                                    {{ deliveryPackage.required_artifacts_present ? 'Yes' : 'No' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-stone-600">Artifact File</dt>
                                <dd class="break-all text-stone-700">
                                    {{ deliveryPackage.artifact }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-stone-600">Generated</dt>
                                <dd>{{ deliveryPackage.generated_at }}</dd>
                            </div>
                        </template>
                        <template v-else>
                            <p class="text-stone-600">No package prepared yet.</p>
                        </template>
                    </dl>
                </article>

                <article class="rounded border border-stone-200 p-4">
                    <h3 class="text-sm font-semibold text-stone-700">Transmission Report</h3>
                    <dl class="mt-3 space-y-2 text-sm">
                        <template v-if="transmission.transmission_id">
                            <div>
                                <dt class="text-stone-600">Transmission ID</dt>
                                <dd>
                                    {{ transmission.transmission_id }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-stone-600">Passed</dt>
                                <dd>
                                    {{ transmission.passed ? 'Yes' : 'No' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-stone-600">Transmission Hash</dt>
                                <dd class="break-all text-stone-700">
                                    {{ transmission.transmission_hash }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-stone-600">Attempts</dt>
                                <dd>{{ transmission.attempt_count || 0 }}</dd>
                            </div>
                        </template>
                        <template v-else>
                            <p class="text-stone-600">No transmission report prepared yet.</p>
                        </template>
                    </dl>
                </article>

                <article class="rounded border border-stone-200 p-4">
                    <h3 class="text-sm font-semibold text-stone-700">Custody Record</h3>
                    <dl class="mt-3 space-y-2 text-sm">
                        <template v-if="custody.custody_id">
                            <div>
                                <dt class="text-stone-600">Custody ID</dt>
                                <dd>{{ custody.custody_id }}</dd>
                            </div>
                            <div>
                                <dt class="text-stone-600">Custody Hash</dt>
                                <dd class="break-all text-stone-700">
                                    {{ custody.custody_hash }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-stone-600">Status</dt>
                                <dd>{{ custody.status }}</dd>
                            </div>
                        </template>
                        <template v-else>
                            <p class="text-stone-600">Custody not recorded yet.</p>
                        </template>
                    </dl>
                </article>
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
