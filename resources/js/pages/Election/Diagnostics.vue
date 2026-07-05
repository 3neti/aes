<script setup lang="ts">
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import type { ElectionSnapshot } from '@/components/election/types';

defineProps<{
    snapshot: ElectionSnapshot;
    diagnostics: Record<string, any>;
}>();
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Diagnostics">
        <section class="border border-stone-300 bg-white p-5">
            <h2 class="text-lg font-semibold">Appliance Diagnostics</h2>
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <div
                    v-for="(value, key) in diagnostics"
                    :key="key"
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
    </CeremonyLayout>
</template>
