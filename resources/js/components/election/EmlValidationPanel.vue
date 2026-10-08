<script setup lang="ts">
import { ref } from 'vue';

type ValidationReport = {
    valid: boolean;
    message?: string;
    profile?: string;
    message_type?: string;
    artifact_sha256?: string;
    schema_profile_hash?: string;
    signature_status?: string;
    errors?: Array<{ line?: number; message?: string }>;
};

const props = defineProps<{
    action: string;
}>();

const selectedFile = ref<File | null>(null);
const report = ref<ValidationReport | null>(null);
const processing = ref(false);
const failure = ref<string | null>(null);

function csrfToken(): string | null {
    return (
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? null
    );
}

function selectArtifact(event: Event): void {
    selectedFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
    report.value = null;
    failure.value = null;
}

async function validateArtifact(): Promise<void> {
    if (!selectedFile.value || processing.value) {
        return;
    }

    processing.value = true;
    failure.value = null;
    const body = new FormData();
    body.append('artifact', selectedFile.value);

    try {
        const response = await fetch(props.action, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                ...(csrfToken()
                    ? { 'X-CSRF-TOKEN': csrfToken() as string }
                    : {}),
            },
            body,
        });
        const payload = (await response.json()) as ValidationReport & {
            message?: string;
        };
        report.value = payload;

        if (!response.ok && !payload.message) {
            failure.value = 'The EML artifact could not be validated.';
        }
    } catch {
        failure.value = 'The validation request could not be completed.';
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <section class="border-t border-stone-300 pt-5">
        <h3 class="text-base font-black text-stone-950">
            Validate an EML artifact
        </h3>
        <p class="mt-1 text-sm text-stone-700">
            Checks the uploaded XML against the commissioned offline schema set.
            Validation does not import or change election data.
        </p>
        <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_auto]">
            <input
                type="file"
                accept=".xml,application/xml,text/xml"
                class="min-h-11 w-full border-2 border-stone-400 bg-white px-3 py-2 text-sm"
                @change="selectArtifact"
            />
            <button
                type="button"
                class="min-h-11 border border-blue-700 bg-blue-700 px-5 font-bold text-white disabled:opacity-40"
                :disabled="!selectedFile || processing"
                @click="validateArtifact"
            >
                {{ processing ? 'Validating...' : 'Validate EML' }}
            </button>
        </div>

        <div
            v-if="report"
            class="mt-4 border-l-4 p-3 text-sm"
            :class="
                report.valid
                    ? 'border-emerald-700 bg-emerald-50 text-emerald-950'
                    : 'border-red-700 bg-red-50 text-red-950'
            "
        >
            <p class="font-black">
                {{
                    report.valid
                        ? 'Schema validation passed'
                        : 'Validation failed'
                }}
            </p>
            <p v-if="report.message" class="mt-1">{{ report.message }}</p>
            <dl class="mt-3 grid gap-2 text-xs sm:grid-cols-2">
                <div>
                    <dt class="font-bold">Profile</dt>
                    <dd>{{ report.profile ?? 'Unknown' }}</dd>
                </div>
                <div>
                    <dt class="font-bold">Message</dt>
                    <dd>EML {{ report.message_type ?? 'Unknown' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="font-bold">Artifact hash</dt>
                    <dd class="font-mono break-all">
                        {{ report.artifact_sha256 ?? 'Unavailable' }}
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="font-bold">Schema set hash</dt>
                    <dd class="font-mono break-all">
                        {{ report.schema_profile_hash ?? 'Unavailable' }}
                    </dd>
                </div>
            </dl>
            <p v-if="report.errors?.length" class="mt-3 font-mono text-xs">
                Line {{ report.errors[0].line ?? '?' }}:
                {{ report.errors[0].message }}
            </p>
        </div>
        <p v-if="failure" class="mt-3 font-bold text-red-700">{{ failure }}</p>
    </section>
</template>
