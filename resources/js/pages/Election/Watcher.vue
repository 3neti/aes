<script setup lang="ts">
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import type { ElectionSnapshot } from '@/components/election/types';

defineProps<{
    snapshot: ElectionSnapshot;
    operations: { deposited_ballots: number };
    resultsAvailable: boolean;
    tally: Record<string, unknown>;
    electionReturn: Record<string, unknown>;
}>();
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Poll Watcher View">
        <section class="border border-stone-300 bg-white p-5">
            <p class="text-sm font-bold text-blue-800">Precinct observation</p>
            <h2 class="mt-1 text-xl font-bold">Operational status</h2>
            <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                <div class="border-l-4 border-blue-800 bg-stone-50 p-4">
                    <dt class="text-sm text-stone-600">Lifecycle stage</dt>
                    <dd class="mt-1 text-lg font-bold">{{ snapshot.stage }}</dd>
                </div>
                <div class="border-l-4 border-emerald-700 bg-stone-50 p-4">
                    <dt class="text-sm text-stone-600">
                        Paper ballots deposited
                    </dt>
                    <dd class="mt-1 text-2xl font-bold">
                        {{ operations.deposited_ballots }}
                    </dd>
                </div>
            </dl>
        </section>

        <section class="border border-stone-300 bg-white p-5">
            <template v-if="!resultsAvailable">
                <p class="text-sm font-bold text-amber-800">
                    Polls remain active
                </p>
                <h2 class="mt-1 text-xl font-bold">
                    Candidate totals are sealed
                </h2>
                <p class="mt-3 text-stone-700">
                    No candidate tally or individual ballot selections are
                    disclosed while voting or counting is in progress.
                </p>
            </template>
            <template v-else>
                <p class="text-sm font-bold text-emerald-800">
                    Official results available
                </p>
                <h2 class="mt-1 text-xl font-bold">
                    Post-close election evidence
                </h2>
                <p class="mt-3 text-stone-700">
                    The tally and Election Return are now available for watcher
                    comparison after the official ceremony.
                </p>
                <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="bg-stone-50 p-4">
                        <dt class="text-sm text-stone-600">Accepted ballots</dt>
                        <dd class="mt-1 text-2xl font-bold">
                            {{ tally.accepted_ballots ?? 0 }}
                        </dd>
                    </div>
                    <div class="bg-stone-50 p-4">
                        <dt class="text-sm text-stone-600">
                            Election Return status
                        </dt>
                        <dd class="mt-1 text-lg font-bold">
                            {{
                                Object.keys(electionReturn).length > 0
                                    ? 'Generated'
                                    : 'Pending generation'
                            }}
                        </dd>
                    </div>
                </dl>
            </template>
        </section>
    </CeremonyLayout>
</template>
