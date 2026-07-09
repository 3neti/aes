<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import {
    custody,
    receipt,
    finalBackup,
    officerVerification,
    preparePackage,
    recipientVerification,
    send,
} from '@/routes/election/transmission';

defineProps<{
    snapshot: ElectionSnapshot;
    transmission: Record<string, any>;
    deliveryPackage: Record<string, any>;
    custody: Record<string, any>;
    deliveryReceipt: Record<string, any>;
    finalBackup: Record<string, any>;
    manualOfficerVerification: Record<string, any>;
    manualRecipientVerification: Record<string, any>;
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
                <Form v-bind="receipt.form()">
                    <input
                        type="hidden"
                        name="stage"
                        :value="snapshot.stage"
                    />
                    <input
                        type="hidden"
                        name="delivery_note"
                        value="Manual handoff custody transition"
                    />
                    <button class="secondary-button" type="submit">
                        Generate Delivery Receipt
                    </button>
                </Form>
                <Form v-bind="finalBackup.form()">
                    <input
                        type="hidden"
                        name="stage"
                        :value="snapshot.stage"
                    />
                    <input
                        type="hidden"
                        name="backup_type"
                        value="local-storage"
                    />
                    <input
                        type="hidden"
                        name="backup_media"
                        value="local-storage"
                    />
                    <input
                        type="hidden"
                        name="backup_note"
                        value="Manual operator final backup completion"
                    />
                    <button class="secondary-button" type="submit">
                        Record Final Backup
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
                                    {{
                                        deliveryPackage.artifact_count
                                    }}
                                    required artifacts complete:
                                    {{
                                        deliveryPackage.required_artifacts_present
                                            ? 'Yes'
                                            : 'No'
                                    }}
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
                            <p class="text-stone-600">
                                No package prepared yet.
                            </p>
                        </template>
                    </dl>
                </article>

                <article class="rounded border border-stone-200 p-4">
                    <h3 class="text-sm font-semibold text-stone-700">
                        Transmission Report
                    </h3>
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
                                <dt class="text-stone-600">
                                    Transmission Hash
                                </dt>
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
                            <p class="text-stone-600">
                                No transmission report prepared yet.
                            </p>
                        </template>
                    </dl>
                </article>

                <article class="rounded border border-stone-200 p-4">
                    <h3 class="text-sm font-semibold text-stone-700">
                        Delivery Receipt
                    </h3>
                    <dl class="mt-3 space-y-2 text-sm">
                        <template v-if="deliveryReceipt.exists">
                            <div>
                                <dt class="text-stone-600">Receipt ID</dt>
                                <dd>
                                    {{ deliveryReceipt.delivery_receipt_id }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-stone-600">Recipient</dt>
                                <dd>
                                    {{ deliveryReceipt.recipient }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-stone-600">Driver</dt>
                                <dd>{{ deliveryReceipt.delivery_driver }}</dd>
                            </div>
                            <div>
                                <dt class="text-stone-600">Status</dt>
                                <dd>{{ deliveryReceipt.status }}</dd>
                            </div>
                            <div>
                                <dt class="text-stone-600">Receipt Hash</dt>
                                <dd class="break-all text-stone-700">
                                    {{ deliveryReceipt.delivery_receipt_hash }}
                                </dd>
                            </div>
                        </template>
                        <template v-else>
                            <p class="text-stone-600">
                                No delivery receipt recorded yet.
                            </p>
                        </template>
                    </dl>
                </article>

                <article class="rounded border border-stone-200 p-4">
                    <h3 class="text-sm font-semibold text-stone-700">
                        Custody Record
                    </h3>
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
                            <p class="text-stone-600">
                                Custody not recorded yet.
                            </p>
                        </template>
                    </dl>
                </article>

                <article
                    class="rounded border border-stone-200 p-4 xl:col-span-3"
                >
                    <h3 class="text-sm font-semibold text-stone-700">
                        Officer Verification
                    </h3>
                    <Form
                        v-bind="officerVerification.form()"
                        class="mt-4 grid gap-3 sm:grid-cols-3"
                    >
                        <input
                            type="hidden"
                            name="stage"
                            :value="snapshot.stage"
                        />
                        <label class="text-xs text-stone-700">
                            Officer Code
                            <input
                                required
                                name="officer_code"
                                class="mt-1 w-full border border-stone-300 p-2"
                                placeholder="SIM-OFFICER-001"
                            />
                        </label>
                        <label class="text-xs text-stone-700">
                            Officer PIN
                            <input
                                required
                                name="officer_pin"
                                class="mt-1 w-full border border-stone-300 p-2"
                                type="password"
                                placeholder="123456"
                            />
                        </label>
                        <label class="text-xs text-stone-700 sm:col-span-3">
                            Note
                            <input
                                name="verification_note"
                                class="mt-1 w-full border border-stone-300 p-2"
                                placeholder="Verified by election board officer"
                            />
                        </label>
                        <div class="sm:col-span-3">
                            <button class="primary-button" type="submit">
                                Record Officer Verification
                            </button>
                        </div>
                    </Form>
                    <dl class="mt-4 text-xs">
                        <template v-if="manualOfficerVerification.verified">
                            <div>
                                <dt>Officer</dt>
                                <dd>
                                    {{ manualOfficerVerification.officer_name }}
                                </dd>
                            </div>
                            <div>
                                <dt>Role</dt>
                                <dd>
                                    {{ manualOfficerVerification.officer_role }}
                                </dd>
                            </div>
                            <div>
                                <dt>Result</dt>
                                <dd>{{ manualOfficerVerification.status }}</dd>
                            </div>
                            <div>
                                <dt>Hash</dt>
                                <dd class="break-all text-stone-700">
                                    {{
                                        manualOfficerVerification.verification_hash
                                    }}
                                </dd>
                            </div>
                        </template>
                        <template v-else>
                            <p class="text-stone-600">
                                Officer verification not yet recorded.
                            </p>
                        </template>
                    </dl>
                </article>

                <article
                    class="rounded border border-stone-200 p-4 xl:col-span-3"
                >
                    <h3 class="text-sm font-semibold text-stone-700">
                        Final Backup
                    </h3>
                    <dl class="mt-4 text-xs">
                        <template v-if="finalBackup.exists">
                            <div>
                                <dt>Backup ID</dt>
                                <dd>{{ finalBackup.backup_id }}</dd>
                            </div>
                            <div>
                                <dt>Backup Hash</dt>
                                <dd class="break-all text-stone-700">
                                    {{ finalBackup.backup_hash }}
                                </dd>
                            </div>
                            <div>
                                <dt>Backup Type</dt>
                                <dd>
                                    {{ finalBackup.backup_type }}
                                </dd>
                            </div>
                            <div>
                                <dt>Backup Media</dt>
                                <dd>
                                    {{ finalBackup.backup_media }}
                                </dd>
                            </div>
                            <div>
                                <dt>Recorded At</dt>
                                <dd>{{ finalBackup.recorded_at }}</dd>
                            </div>
                        </template>
                        <template v-else>
                            <p class="text-stone-600">
                                Final backup not recorded yet.
                            </p>
                        </template>
                    </dl>
                </article>

                <article
                    class="rounded border border-stone-200 p-4 xl:col-span-3"
                >
                    <h3 class="text-sm font-semibold text-stone-700">
                        Recipient Verification
                    </h3>
                    <Form
                        v-bind="recipientVerification.form()"
                        class="mt-4 grid gap-3 sm:grid-cols-3"
                    >
                        <input
                            type="hidden"
                            name="stage"
                            :value="snapshot.stage"
                        />
                        <label class="text-xs text-stone-700">
                            Recipient
                            <input
                                required
                                name="recipient"
                                class="mt-1 w-full border border-stone-300 p-2"
                                placeholder="Election Board Officer"
                            />
                        </label>
                        <label class="text-xs text-stone-700">
                            Recipient Role
                            <input
                                required
                                name="recipient_role"
                                class="mt-1 w-full border border-stone-300 p-2"
                                placeholder="Chairperson"
                            />
                        </label>
                        <label class="text-xs text-stone-700">
                            Delivery Method
                            <select
                                required
                                name="delivery_method"
                                class="mt-1 w-full border border-stone-300 p-2"
                            >
                                <option value="manual">Manual Handoff</option>
                                <option value="sd-card">SD Card</option>
                                <option value="usb">USB Storage</option>
                            </select>
                        </label>
                        <label class="text-xs text-stone-700">
                            Date
                            <input
                                required
                                name="handoff_date"
                                class="mt-1 w-full border border-stone-300 p-2"
                                type="date"
                                :value="new Date().toISOString().slice(0, 10)"
                            />
                        </label>
                        <label class="text-xs text-stone-700">
                            Time
                            <input
                                required
                                name="handoff_time"
                                class="mt-1 w-full border border-stone-300 p-2"
                                type="time"
                                :value="new Date().toTimeString().slice(0, 5)"
                            />
                        </label>
                        <label class="text-xs text-stone-700">
                            <span class="inline-flex items-center gap-2">
                                <input
                                    name="acknowledged"
                                    type="checkbox"
                                    value="1"
                                    checked
                                />
                                Recipient acknowledged
                            </span>
                        </label>
                        <label class="text-xs text-stone-700 sm:col-span-3">
                            Acknowledgement Note
                            <input
                                name="acknowledgement_note"
                                class="mt-1 w-full border border-stone-300 p-2"
                                placeholder="Recipient accepted custody"
                            />
                        </label>
                        <div class="sm:col-span-3">
                            <button class="secondary-button" type="submit">
                                Record Recipient Verification
                            </button>
                        </div>
                    </Form>
                    <dl class="mt-4 text-xs">
                        <template v-if="manualRecipientVerification.verified">
                            <div>
                                <dt>Recipient</dt>
                                <dd>
                                    {{ manualRecipientVerification.recipient }}
                                </dd>
                            </div>
                            <div>
                                <dt>Role</dt>
                                <dd>
                                    {{
                                        manualRecipientVerification.recipient_role
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt>Delivery Method</dt>
                                <dd>
                                    {{
                                        manualRecipientVerification.delivery_method
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt>Recognized On</dt>
                                <dd>
                                    {{
                                        manualRecipientVerification.recipient_handoff_at
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt>Acknowledged</dt>
                                <dd>
                                    {{
                                        manualRecipientVerification.acknowledged
                                            ? 'Yes'
                                            : 'No'
                                    }}
                                </dd>
                            </div>
                        </template>
                        <template v-else>
                            <p class="text-stone-600">
                                Recipient verification not yet recorded.
                            </p>
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
