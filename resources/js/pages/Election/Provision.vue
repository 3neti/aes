<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import { activate } from '@/routes/election/provision';
import { ebRoleBaseline } from '@/routes/election/provision';

type ElectoralBoardBaseline = {
    exists: boolean;
    artifact?: string;
    run_id?: string | null;
    precinct_id?: string | null;
    baseline_hash?: string | null;
    required_role_count?: number;
    required_roles_present?: number;
    missing_required_role_count?: number;
    passed?: boolean;
    generated_at?: string | null;
    generate_url: string;
};

defineProps<{ snapshot: ElectionSnapshot; electoralBoardBaseline: ElectoralBoardBaseline }>();
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Provision">
        <section class="border border-stone-300 bg-white p-5">
            <h2 class="text-lg font-semibold">Sample Precinct Package</h2>
            <p class="mt-2 text-sm text-stone-700">
                Activate the embedded sample package to derive the local ballot
                mapping.
            </p>
            <Form v-bind="activate.form()" class="mt-5">
                <button class="primary-button" type="submit">
                    Activate Package
                </button>
            </Form>
            <dl v-if="snapshot.configuration.mapping_hash" class="mt-5 text-sm">
                <dt class="font-semibold">Mapping Hash</dt>
                <dd class="break-all text-stone-700">
                    {{ snapshot.configuration.mapping_hash }}
                </dd>
            </dl>
        </section>

        <section class="mt-6 border border-stone-300 bg-white p-5">
            <h2 class="text-lg font-semibold">EB Role Baseline</h2>
            <p class="mt-2 text-sm text-stone-700">
                Record the Electoral Board role roster baseline for this run.
            </p>
            <Form v-bind="ebRoleBaseline.form()" class="mt-4">
                <button class="secondary-button" type="submit">
                    Generate EB Role Baseline
                </button>
            </Form>
            <dl v-if="electoralBoardBaseline.exists" class="mt-5 text-sm">
                <dt class="font-semibold">Baseline Hash</dt>
                <dd class="break-all text-stone-700">
                    {{ electoralBoardBaseline.baseline_hash }}
                </dd>
                <dt class="mt-2 font-semibold">Run</dt>
                <dd class="text-stone-700">{{ electoralBoardBaseline.run_id }}</dd>
                <dt class="mt-2 font-semibold">Required Roles</dt>
                <dd class="text-stone-700">
                    {{ electoralBoardBaseline.required_roles_present }}/{{ electoralBoardBaseline.required_role_count }}
                    present
                </dd>
                <dt class="mt-2 font-semibold">Missing Required Roles</dt>
                <dd class="text-stone-700">
                    {{ electoralBoardBaseline.missing_required_role_count }}
                </dd>
                <dt class="mt-2 font-semibold">Status</dt>
                <dd class="text-stone-700">
                    {{ electoralBoardBaseline.passed ? 'Ready' : 'Not Ready' }}
                </dd>
            </dl>
            <p v-else class="mt-3 text-sm text-stone-600">
                No EB role baseline has been generated in this run yet.
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
</style>
