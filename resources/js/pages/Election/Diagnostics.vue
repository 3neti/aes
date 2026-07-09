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

type EvidenceManifest = {
    exists: boolean;
    artifact?: string;
    manifest_hash?: string | null;
    generated_at?: string | null;
    categories?: Record<string, number>;
    generate_url: string;
    download_url: string;
};

type EvidenceReferenceBaseline = {
    exists: boolean;
    artifact?: string;
    run_id?: string | null;
    precinct_id?: string | null;
    generated_at?: string | null;
    baseline_hash?: string | null;
    artifact_reference_count?: number;
    required_reference_count?: number;
    missing_required_reference_count?: number;
    generate_url: string;
    download_url: string;
};

type OfficialMinutesBaseline = {
    exists: boolean;
    artifact?: string;
    run_id?: string | null;
    precinct_id?: string | null;
    generated_at?: string | null;
    official_minute_hash?: string | null;
    minute_count?: number;
    source_journal_event_count?: number;
    source_attestation_count?: number;
    generate_url: string;
    download_url: string;
};

type EvidenceBundleArchive = {
    exists: boolean;
    build_url: string;
    download_url: string;
    verify_url?: string;
    archive_id?: string | null;
    archive_artifact?: string | null;
    archive_bytes?: number;
    archive_sha256?: string | null;
    built_at?: string | null;
    entry_count?: number;
    manifest_hash?: string | null;
    archive_report_hash?: string | null;
};

type RemovableMediaExport = {
    exists: boolean;
    target_root: string;
    export_url: string;
    export_id?: string;
    exported_at?: string | null;
    target_path?: string;
    manifest_hash?: string | null;
    export_hash?: string | null;
    artifact_count?: number;
};

type ReadinessCheck = {
    name: string;
    passed: boolean;
    message: string;
};

type RemovableMediaReadiness = {
    exists: boolean;
    check_url: string;
    artifact?: string;
    checked_at?: string | null;
    configured?: boolean;
    ready?: boolean;
    status?: string;
    status_label?: string;
    target_path: string;
    readiness_hash?: string | null;
    checks?: ReadinessCheck[];
};

type VerificationMismatch = {
    type: string;
    message: string;
    path: string;
    expected?: unknown;
    actual?: unknown;
};

type EvidenceExportVerification = {
    exists: boolean;
    verify_url: string;
    artifact?: string;
    verified_at?: string | null;
    export_id?: string | null;
    export_path?: string | null;
    passed?: boolean;
    checked_files?: number;
    verification_hash?: string | null;
    mismatch_count?: number;
    mismatches?: VerificationMismatch[];
};

type EvidenceBundleArchiveVerification = {
    exists: boolean;
    verify_url: string;
    upload_verify_url: string;
    archive_id?: string | null;
    archive_path?: string | null;
    archive_source?: string | null;
    archive_sha256?: string | null;
    checked_files?: number;
    mismatch_count?: number;
    mismatches?: VerificationMismatch[];
    passed?: boolean;
    uploaded_archive_artifact?: string | null;
    uploaded_archive_original_name?: string | null;
    uploaded_archive_sha256?: string | null;
    uploaded_at?: string | null;
    verification_hash?: string | null;
    verified_at?: string | null;
};

defineProps<{
    snapshot: ElectionSnapshot;
    diagnostics: {
        attestation_artifacts?: AttestationArtifact[];
        evidence_manifest?: EvidenceManifest;
        evidence_reference_baseline?: EvidenceReferenceBaseline;
        official_minutes_baseline?: OfficialMinutesBaseline;
        evidence_bundle_archive?: EvidenceBundleArchive;
        evidence_bundle_archive_verification?: EvidenceBundleArchiveVerification;
        removable_media_export?: RemovableMediaExport;
        removable_media_readiness?: RemovableMediaReadiness;
        evidence_export_verification?: EvidenceExportVerification;
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
                    v-show="
                        key !== 'attestation_artifacts' &&
                        key !== 'evidence_manifest' &&
                        key !== 'evidence_reference_baseline' &&
                        key !== 'evidence_bundle_archive' &&
                        key !== 'evidence_bundle_archive_verification' &&
                        key !== 'removable_media_export' &&
                        key !== 'removable_media_readiness' &&
                        key !== 'evidence_export_verification' &&
                        key !== 'official_minutes_baseline'
                    "
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

        <section
            v-if="diagnostics.evidence_manifest"
            class="border border-stone-300 bg-white p-5"
        >
            <div class="flex flex-col gap-4 sm:flex-row sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold">
                        Precinct Evidence Manifest
                    </h2>
                    <p class="mt-1 text-sm text-stone-700">
                        {{
                            diagnostics.evidence_manifest.exists
                                ? `Generated ${diagnostics.evidence_manifest.generated_at}`
                                : 'No manifest generated yet.'
                        }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Form
                        :action="diagnostics.evidence_manifest.generate_url"
                        method="post"
                    >
                        <button class="secondary-button" type="submit">
                            Generate Manifest
                        </button>
                    </Form>
                    <a
                        class="artifact-link"
                        :href="diagnostics.evidence_manifest.download_url"
                    >
                        Download Manifest
                    </a>
                </div>
            </div>
            <dl
                v-if="diagnostics.evidence_manifest.exists"
                class="mt-4 grid gap-3 text-xs sm:grid-cols-2"
            >
                <div>
                    <dt class="font-semibold text-stone-700">Manifest Hash</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{ diagnostics.evidence_manifest.manifest_hash }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Artifact</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{ diagnostics.evidence_manifest.artifact }}
                    </dd>
                </div>
            </dl>
            <dl
                v-if="diagnostics.evidence_manifest.categories"
                class="mt-4 grid gap-2 text-xs sm:grid-cols-3"
            >
                <div
                    v-for="(count, category) in diagnostics.evidence_manifest
                        .categories"
                    :key="category"
                    class="border border-stone-200 p-2"
                >
                    <dt class="font-semibold text-stone-700">
                        {{ category }}
                    </dt>
                    <dd class="mt-1 text-stone-600">{{ count }} files</dd>
                </div>
            </dl>
        </section>

        <section
            v-if="diagnostics.evidence_reference_baseline"
            class="border border-stone-300 bg-white p-5"
        >
            <div class="flex flex-col gap-4 sm:flex-row sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold">
                        Evidence Reference Baseline
                    </h2>
                    <p class="mt-1 text-sm text-stone-700">
                        {{
                            diagnostics.evidence_reference_baseline.exists
                                ? `Generated ${diagnostics.evidence_reference_baseline.generated_at}`
                                : 'No baseline has been generated yet.'
                        }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Form
                        :action="
                            diagnostics.evidence_reference_baseline.generate_url
                        "
                        method="post"
                    >
                        <button class="secondary-button" type="submit">
                            Generate Baseline
                        </button>
                    </Form>
                    <a
                        class="artifact-link"
                        :href="
                            diagnostics.evidence_reference_baseline.download_url
                        "
                    >
                        Download Baseline
                    </a>
                </div>
            </div>
            <dl
                v-if="diagnostics.evidence_reference_baseline.exists"
                class="mt-4 grid gap-3 text-xs sm:grid-cols-2"
            >
                <div>
                    <dt class="font-semibold text-stone-700">Run</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{ diagnostics.evidence_reference_baseline.run_id }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Precinct</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.evidence_reference_baseline.precinct_id
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Artifact</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{ diagnostics.evidence_reference_baseline.artifact }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">
                        Artifact References
                    </dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.evidence_reference_baseline
                                .artifact_reference_count
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">
                        Missing Required
                    </dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.evidence_reference_baseline
                                .missing_required_reference_count
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Baseline Hash</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.evidence_reference_baseline
                                .baseline_hash
                        }}
                    </dd>
                </div>
            </dl>
            <p v-else class="mt-4 text-sm text-stone-700">
                Generate the baseline after you have key legal artifacts in
                place.
            </p>
        </section>

        <section
            v-if="diagnostics.official_minutes_baseline"
            class="border border-stone-300 bg-white p-5"
        >
            <div class="flex flex-col gap-4 sm:flex-row sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold">
                        Official Minutes Baseline
                    </h2>
                    <p class="mt-1 text-sm text-stone-700">
                        {{
                            diagnostics.official_minutes_baseline.exists
                                ? `Generated ${diagnostics.official_minutes_baseline.generated_at}`
                                : 'No official minutes baseline has been generated yet.'
                        }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Form
                        :action="
                            diagnostics.official_minutes_baseline.generate_url
                        "
                        method="post"
                    >
                        <button class="secondary-button" type="submit">
                            Generate Official Minutes
                        </button>
                    </Form>
                    <a
                        class="artifact-link"
                        :href="
                            diagnostics.official_minutes_baseline.download_url
                        "
                    >
                        Download Official Minutes
                    </a>
                </div>
            </div>
            <dl
                v-if="diagnostics.official_minutes_baseline.exists"
                class="mt-4 grid gap-3 text-xs sm:grid-cols-2"
            >
                <div>
                    <dt class="font-semibold text-stone-700">Run</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{ diagnostics.official_minutes_baseline.run_id }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Precinct</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{ diagnostics.official_minutes_baseline.precinct_id }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Artifact</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{ diagnostics.official_minutes_baseline.artifact }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">
                        Minutes Entries
                    </dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{ diagnostics.official_minutes_baseline.minute_count }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">
                        Source Journal Events
                    </dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.official_minutes_baseline
                                .source_journal_event_count
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">
                        Source Attestations
                    </dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.official_minutes_baseline
                                .source_attestation_count
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Baseline Hash</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.official_minutes_baseline
                                .official_minute_hash
                        }}
                    </dd>
                </div>
            </dl>
            <p v-else class="mt-4 text-sm text-stone-700">
                Generate the official minutes baseline from the current run
                records.
            </p>
        </section>

        <section
            v-if="diagnostics.evidence_bundle_archive"
            class="border border-stone-300 bg-white p-5"
        >
            <div class="flex flex-col gap-4 sm:flex-row sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold">
                        Evidence Bundle Archive
                    </h2>
                    <p class="mt-1 text-sm text-stone-700">
                        {{
                            diagnostics.evidence_bundle_archive.exists
                                ? `Built ${diagnostics.evidence_bundle_archive.built_at}`
                                : 'No downloadable archive has been built yet.'
                        }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Form
                        :action="diagnostics.evidence_bundle_archive.build_url"
                        method="post"
                        #default="{ processing }"
                    >
                        <button
                            class="secondary-button"
                            type="submit"
                            :disabled="processing"
                        >
                            {{
                                processing
                                    ? 'Building...'
                                    : 'Build Download Archive'
                            }}
                        </button>
                    </Form>
                    <a
                        class="artifact-link"
                        :href="diagnostics.evidence_bundle_archive.download_url"
                    >
                        Download Archive
                    </a>
                </div>
            </div>

            <dl
                v-if="diagnostics.evidence_bundle_archive.exists"
                class="mt-4 grid gap-3 text-xs sm:grid-cols-2"
            >
                <div>
                    <dt class="font-semibold text-stone-700">Archive ID</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{ diagnostics.evidence_bundle_archive.archive_id }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Artifact</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.evidence_bundle_archive.archive_artifact
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Entries</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{ diagnostics.evidence_bundle_archive.entry_count }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Bytes</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{ diagnostics.evidence_bundle_archive.archive_bytes }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Archive Hash</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{ diagnostics.evidence_bundle_archive.archive_sha256 }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Report Hash</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.evidence_bundle_archive
                                .archive_report_hash
                        }}
                    </dd>
                </div>
            </dl>
        </section>

        <section
            v-if="diagnostics.evidence_bundle_archive_verification"
            class="border border-stone-300 bg-white p-5"
        >
            <div class="flex flex-col gap-4 lg:flex-row lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold">Archive Verification</h2>
                    <p class="mt-1 text-sm text-stone-700">
                        {{
                            diagnostics.evidence_bundle_archive_verification
                                .exists
                                ? diagnostics
                                      .evidence_bundle_archive_verification
                                      .passed
                                    ? 'Latest archive verification passed.'
                                    : 'Latest archive verification found mismatches.'
                                : 'No archive verification has been run yet.'
                        }}
                    </p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 lg:max-w-3xl">
                    <Form
                        :action="
                            diagnostics.evidence_bundle_archive_verification
                                .verify_url
                        "
                        method="post"
                        #default="{ processing, wasSuccessful }"
                    >
                        <div class="flex flex-col items-start gap-2">
                            <button
                                class="secondary-button"
                                type="submit"
                                :disabled="processing"
                            >
                                {{
                                    processing
                                        ? 'Verifying...'
                                        : 'Verify Built Archive'
                                }}
                            </button>
                            <p
                                v-if="wasSuccessful"
                                class="text-xs text-stone-600"
                            >
                                Archive verification complete.
                            </p>
                        </div>
                    </Form>
                    <Form
                        :action="
                            diagnostics.evidence_bundle_archive_verification
                                .upload_verify_url
                        "
                        method="post"
                        enctype="multipart/form-data"
                        #default="{ errors, processing, wasSuccessful }"
                    >
                        <div class="flex flex-col items-start gap-2">
                            <input
                                class="w-full border border-stone-300 bg-white px-3 py-2 text-sm"
                                name="archive"
                                type="file"
                                accept=".tar,application/x-tar"
                            />
                            <button
                                class="secondary-button"
                                type="submit"
                                :disabled="processing"
                            >
                                {{
                                    processing
                                        ? 'Verifying Upload...'
                                        : 'Upload Returned TAR'
                                }}
                            </button>
                            <p
                                v-if="errors.archive"
                                class="text-xs text-red-700"
                            >
                                {{ errors.archive }}
                            </p>
                            <p
                                v-if="wasSuccessful"
                                class="text-xs text-stone-600"
                            >
                                Uploaded archive verification complete.
                            </p>
                        </div>
                    </Form>
                </div>
            </div>

            <dl
                v-if="diagnostics.evidence_bundle_archive_verification.exists"
                class="mt-4 grid gap-3 text-xs sm:grid-cols-2"
            >
                <div>
                    <dt class="font-semibold text-stone-700">Status</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.evidence_bundle_archive_verification
                                .passed
                                ? 'Passed'
                                : 'Failed'
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Source</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.evidence_bundle_archive_verification
                                .archive_source
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Verified At</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.evidence_bundle_archive_verification
                                .verified_at
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Archive ID</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.evidence_bundle_archive_verification
                                .archive_id
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Checked Files</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.evidence_bundle_archive_verification
                                .checked_files
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Mismatch Count</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.evidence_bundle_archive_verification
                                .mismatch_count
                        }}
                    </dd>
                </div>
                <div
                    v-if="
                        diagnostics.evidence_bundle_archive_verification
                            .uploaded_archive_artifact
                    "
                >
                    <dt class="font-semibold text-stone-700">
                        Uploaded Artifact
                    </dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.evidence_bundle_archive_verification
                                .uploaded_archive_artifact
                        }}
                    </dd>
                </div>
                <div
                    v-if="
                        diagnostics.evidence_bundle_archive_verification
                            .uploaded_archive_original_name
                    "
                >
                    <dt class="font-semibold text-stone-700">Uploaded Name</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.evidence_bundle_archive_verification
                                .uploaded_archive_original_name
                        }}
                    </dd>
                </div>
                <div
                    v-if="
                        diagnostics.evidence_bundle_archive_verification
                            .uploaded_at
                    "
                >
                    <dt class="font-semibold text-stone-700">Uploaded At</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.evidence_bundle_archive_verification
                                .uploaded_at
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Archive Hash</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.evidence_bundle_archive_verification
                                .archive_sha256
                        }}
                    </dd>
                </div>
            </dl>

            <div
                v-if="
                    diagnostics.evidence_bundle_archive_verification.mismatches
                        ?.length
                "
                class="mt-4 space-y-3"
            >
                <article
                    v-for="mismatch in diagnostics
                        .evidence_bundle_archive_verification.mismatches"
                    :key="`${mismatch.type}-${mismatch.path}`"
                    class="border border-stone-200 p-3 text-xs"
                >
                    <div class="font-semibold text-stone-800">
                        {{ mismatch.type }}
                    </div>
                    <div class="mt-1 break-all text-stone-700">
                        {{ mismatch.path }}
                    </div>
                    <div class="mt-1 text-stone-600">
                        {{ mismatch.message }}
                    </div>
                </article>
            </div>
        </section>

        <section
            v-if="diagnostics.removable_media_readiness"
            class="border border-stone-300 bg-white p-5"
        >
            <div class="flex flex-col gap-4 sm:flex-row sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold">
                        Removable Media Readiness
                    </h2>
                    <p class="mt-1 text-sm break-all text-stone-700">
                        {{ diagnostics.removable_media_readiness.target_path }}
                    </p>
                </div>
                <Form
                    :action="diagnostics.removable_media_readiness.check_url"
                    method="post"
                    #default="{ processing, wasSuccessful }"
                >
                    <div class="flex flex-col items-start gap-2">
                        <button
                            class="secondary-button"
                            type="submit"
                            :disabled="processing"
                        >
                            {{ processing ? 'Checking...' : 'Check Readiness' }}
                        </button>
                        <p v-if="wasSuccessful" class="text-xs text-stone-600">
                            Readiness checked.
                        </p>
                    </div>
                </Form>
            </div>

            <dl
                v-if="diagnostics.removable_media_readiness.exists"
                class="mt-4 grid gap-3 text-xs sm:grid-cols-2"
            >
                <div>
                    <dt class="font-semibold text-stone-700">Status</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.removable_media_readiness
                                .status_label ??
                            (diagnostics.removable_media_readiness.ready
                                ? 'Ready'
                                : 'Not Ready')
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Checked At</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{ diagnostics.removable_media_readiness.checked_at }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Mode</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.removable_media_readiness.configured
                                ? 'Configured target'
                                : 'Simulated local target'
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Readiness Hash</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.removable_media_readiness.readiness_hash
                        }}
                    </dd>
                </div>
            </dl>

            <div
                v-if="diagnostics.removable_media_readiness.checks?.length"
                class="mt-4 grid gap-2 text-xs sm:grid-cols-2"
            >
                <article
                    v-for="check in diagnostics.removable_media_readiness
                        .checks"
                    :key="check.name"
                    class="border border-stone-200 p-3"
                >
                    <div class="font-semibold text-stone-800">
                        {{ check.name }}
                    </div>
                    <div class="mt-1 text-stone-700">
                        {{ check.passed ? 'Passed' : 'Failed' }}
                    </div>
                    <div class="mt-1 text-stone-600">
                        {{ check.message }}
                    </div>
                </article>
            </div>
            <p v-else class="mt-4 text-sm text-stone-700">
                No readiness check has been run yet.
            </p>
        </section>

        <section
            v-if="diagnostics.removable_media_export"
            class="border border-stone-300 bg-white p-5"
        >
            <div class="flex flex-col gap-4 sm:flex-row sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold">
                        Removable Media Export
                    </h2>
                    <p class="mt-1 text-sm break-all text-stone-700">
                        {{ diagnostics.removable_media_export.target_root }}
                    </p>
                </div>
                <Form
                    :action="diagnostics.removable_media_export.export_url"
                    method="post"
                    #default="{ processing, wasSuccessful }"
                >
                    <div class="flex flex-col items-start gap-2">
                        <button
                            class="secondary-button"
                            type="submit"
                            :disabled="processing"
                        >
                            {{
                                processing
                                    ? 'Exporting...'
                                    : 'Stage Media Export'
                            }}
                        </button>
                        <p v-if="wasSuccessful" class="text-xs text-stone-600">
                            Export staged.
                        </p>
                    </div>
                </Form>
            </div>

            <dl
                v-if="diagnostics.removable_media_export.exists"
                class="mt-4 grid gap-3 text-xs sm:grid-cols-2"
            >
                <div>
                    <dt class="font-semibold text-stone-700">Export ID</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{ diagnostics.removable_media_export.export_id }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Exported At</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{ diagnostics.removable_media_export.exported_at }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Target Path</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{ diagnostics.removable_media_export.target_path }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Artifact Count</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{ diagnostics.removable_media_export.artifact_count }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Manifest Hash</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{ diagnostics.removable_media_export.manifest_hash }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Export Hash</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{ diagnostics.removable_media_export.export_hash }}
                    </dd>
                </div>
            </dl>
            <p v-else class="mt-4 text-sm text-stone-700">
                No removable media export has been staged yet.
            </p>
        </section>

        <section
            v-if="diagnostics.evidence_export_verification"
            class="border border-stone-300 bg-white p-5"
        >
            <div class="flex flex-col gap-4 sm:flex-row sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold">
                        Evidence Export Verification
                    </h2>
                    <p class="mt-1 text-sm text-stone-700">
                        {{
                            diagnostics.evidence_export_verification.exists
                                ? diagnostics.evidence_export_verification
                                      .passed
                                    ? 'Latest verification passed.'
                                    : 'Latest verification found mismatches.'
                                : 'No verification report has been run yet.'
                        }}
                    </p>
                </div>
                <Form
                    :action="
                        diagnostics.evidence_export_verification.verify_url
                    "
                    method="post"
                    #default="{ processing, wasSuccessful }"
                >
                    <div class="flex flex-col items-start gap-2">
                        <button
                            class="secondary-button"
                            type="submit"
                            :disabled="processing"
                        >
                            {{
                                processing ? 'Verifying...' : 'Run Verification'
                            }}
                        </button>
                        <p v-if="wasSuccessful" class="text-xs text-stone-600">
                            Verification complete.
                        </p>
                    </div>
                </Form>
            </div>

            <dl
                v-if="diagnostics.evidence_export_verification.exists"
                class="mt-4 grid gap-3 text-xs sm:grid-cols-2"
            >
                <div>
                    <dt class="font-semibold text-stone-700">Status</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.evidence_export_verification.passed
                                ? 'Passed'
                                : 'Failed'
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Verified At</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.evidence_export_verification.verified_at
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Export ID</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{ diagnostics.evidence_export_verification.export_id }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Checked Files</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.evidence_export_verification
                                .checked_files
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">Mismatch Count</dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.evidence_export_verification
                                .mismatch_count
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-stone-700">
                        Verification Hash
                    </dt>
                    <dd class="mt-1 break-all text-stone-600">
                        {{
                            diagnostics.evidence_export_verification
                                .verification_hash
                        }}
                    </dd>
                </div>
            </dl>

            <div
                v-if="
                    diagnostics.evidence_export_verification.mismatches?.length
                "
                class="mt-4 space-y-3"
            >
                <article
                    v-for="mismatch in diagnostics.evidence_export_verification
                        .mismatches"
                    :key="`${mismatch.type}-${mismatch.path}`"
                    class="border border-stone-200 p-3 text-xs"
                >
                    <div class="font-semibold text-stone-800">
                        {{ mismatch.type }}
                    </div>
                    <div class="mt-1 break-all text-stone-700">
                        {{ mismatch.path }}
                    </div>
                    <div class="mt-1 text-stone-600">
                        {{ mismatch.message }}
                    </div>
                    <dl class="mt-2 grid gap-2 sm:grid-cols-2">
                        <div v-if="mismatch.expected !== null">
                            <dt class="font-semibold text-stone-700">
                                Expected
                            </dt>
                            <dd class="mt-1 break-all text-stone-600">
                                {{ mismatch.expected }}
                            </dd>
                        </div>
                        <div v-if="mismatch.actual !== null">
                            <dt class="font-semibold text-stone-700">Actual</dt>
                            <dd class="mt-1 break-all text-stone-600">
                                {{ mismatch.actual }}
                            </dd>
                        </div>
                    </dl>
                </article>
            </div>
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

.secondary-button {
    border: 1px solid rgb(120 113 108);
    padding: 0.4rem 0.6rem;
    font-weight: 700;
}
</style>
