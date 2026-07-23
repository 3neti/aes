<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import CeremonyActionPanel from '@/components/election/CeremonyActionPanel.vue';
import CeremonyLayout from '@/components/election/CeremonyLayout.vue';
import StatusBadge from '@/components/election/StatusBadge.vue';
import type { ElectionSnapshot } from '@/components/election/types';
import {
    certification,
    counting,
    diagnostics,
    provision,
    returns,
    transmission,
    voting,
} from '@/routes/election';

const props = defineProps<{ snapshot: ElectionSnapshot }>();

const routeByStage = {
    provision,
    certification,
    open_precinct: voting,
    open_polls: voting,
    voting,
    close_polls: voting,
    counting,
    election_return: returns,
    transmission,
    final_backup: transmission,
    custody: transmission,
    close_precinct: transmission,
    audit: diagnostics,
};

const currentCeremonyUrl = computed(() => {
    const route =
        routeByStage[props.snapshot.stage as keyof typeof routeByStage] ??
        provision;

    return route.url();
});

const completedSteps = computed(() => {
    const activeIndex = props.snapshot.workflow.findIndex((step) =>
        step.stages.includes(props.snapshot.stage),
    );

    return Math.max(activeIndex, 0);
});
</script>

<template>
    <CeremonyLayout :snapshot="snapshot" title="Precinct Run">
        <CeremonyActionPanel
            title="Continue the precinct run"
            :description="snapshot.nextAction"
            eyebrow="Operator handoff"
            status="Action required"
            tone="warning"
        >
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <p class="text-sm text-stone-600">
                        Current lifecycle stage
                    </p>
                    <p class="mt-1 text-xl font-bold text-stone-950">
                        {{ snapshot.stageLabel }}
                    </p>
                </div>
                <Link
                    :href="currentCeremonyUrl"
                    class="inline-flex min-h-11 items-center justify-center bg-blue-800 px-5 py-3 text-sm font-bold text-white hover:bg-blue-900"
                >
                    Open current ceremony
                </Link>
            </div>
        </CeremonyActionPanel>

        <section class="border border-stone-300 bg-white">
            <header class="border-b border-stone-200 px-5 py-4">
                <h2 class="text-lg font-bold text-stone-950">
                    Precinct readiness
                </h2>
                <p class="mt-1 text-sm text-stone-600">
                    This view follows the legal ceremony sequence and the
                    evidence recorded by this appliance.
                </p>
            </header>
            <dl class="grid sm:grid-cols-2">
                <div class="border-b border-stone-200 p-5 sm:border-r">
                    <dt class="text-xs font-bold text-stone-500 uppercase">
                        Precinct package
                    </dt>
                    <dd class="mt-2">
                        <StatusBadge
                            :label="
                                snapshot.configuration.mapping_hash
                                    ? 'Loaded and mapped'
                                    : 'Not loaded'
                            "
                            :tone="
                                snapshot.configuration.mapping_hash
                                    ? 'complete'
                                    : 'warning'
                            "
                        />
                    </dd>
                    <dd class="mt-3 text-sm text-stone-700">
                        {{
                            snapshot.configuration.precinct_id ||
                            'No precinct assigned'
                        }}
                    </dd>
                </div>
                <div class="border-b border-stone-200 p-5">
                    <dt class="text-xs font-bold text-stone-500 uppercase">
                        Ceremony progress
                    </dt>
                    <dd class="mt-2 text-3xl font-bold text-stone-950">
                        {{ completedSteps }}/{{ snapshot.workflow.length }}
                    </dd>
                    <dd class="mt-1 text-sm text-stone-600">
                        completed ceremony groups
                    </dd>
                </div>
                <div
                    class="border-b border-stone-200 p-5 sm:border-r sm:border-b-0"
                >
                    <dt class="text-xs font-bold text-stone-500 uppercase">
                        Paper ballot records
                    </dt>
                    <dd class="mt-2 text-3xl font-bold text-stone-950">
                        {{ snapshot.counts.ballots }}
                    </dd>
                    <dd class="mt-1 text-sm text-stone-600">
                        digital preparation records; paper remains controlling
                    </dd>
                </div>
                <div class="p-5">
                    <dt class="text-xs font-bold text-stone-500 uppercase">
                        Journal checkpoints
                    </dt>
                    <dd class="mt-2 text-3xl font-bold text-stone-950">
                        {{ snapshot.journal.length }}
                    </dd>
                    <dd class="mt-1 text-sm text-stone-600">
                        latest events available for review
                    </dd>
                </div>
            </dl>
        </section>

        <div
            class="border-l-4 border-blue-700 bg-blue-50 px-5 py-4 text-sm text-blue-950"
        >
            <p class="font-bold">Paper is the legal source of truth.</p>
            <p class="mt-1">
                The appliance guides the ceremony, prepares artifacts, and
                records evidence. It does not replace the paper ballots or the
                Electoral Board.
            </p>
        </div>
    </CeremonyLayout>
</template>
