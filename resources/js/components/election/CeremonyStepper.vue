<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    certification,
    counting,
    diagnostics,
    provision,
    returns,
    transmission,
    voting,
} from '@/routes/election';
import type { WorkflowStep } from './types';

const props = defineProps<{
    currentStage: string;
    workflow: WorkflowStep[];
}>();

const routeByStep = {
    setup: provision,
    certification,
    opening: voting,
    voting,
    closing: voting,
    counting,
    return: returns,
    handoff: transmission,
    audit: diagnostics,
};

const currentWorkflowIndex = computed(() =>
    props.workflow.findIndex((step) =>
        step.stages.includes(props.currentStage),
    ),
);

function stepState(index: number): 'complete' | 'current' | 'upcoming' {
    if (index < currentWorkflowIndex.value) {
        return 'complete';
    }

    if (index === currentWorkflowIndex.value) {
        return 'current';
    }

    return 'upcoming';
}

function stepHref(step: WorkflowStep): string {
    const route = routeByStep[step.id as keyof typeof routeByStep];

    return route ? route.url() : diagnostics.url();
}
</script>

<template>
    <nav aria-label="Election ceremony sequence">
        <div class="mb-3 flex items-center justify-between gap-3 px-1 xl:px-0">
            <h2 class="text-xs font-bold text-stone-600 uppercase">
                Precinct Run
            </h2>
            <span class="text-xs text-stone-500">
                {{ Math.max(currentWorkflowIndex + 1, 1) }}/{{
                    workflow.length
                }}
            </span>
        </div>

        <ol
            class="flex snap-x gap-2 overflow-x-auto pb-2 xl:block xl:space-y-1 xl:overflow-visible xl:pb-0"
        >
            <li
                v-for="(step, index) in workflow"
                :key="step.id"
                class="min-w-56 snap-start xl:min-w-0"
            >
                <Link
                    :href="stepHref(step)"
                    class="group grid min-h-16 grid-cols-[28px_1fr] items-start gap-2 border p-2.5 transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700"
                    :class="{
                        'border-emerald-300 bg-emerald-50/70':
                            stepState(index) === 'complete',
                        'border-blue-700 bg-blue-50':
                            stepState(index) === 'current',
                        'border-stone-200 bg-white hover:border-stone-400':
                            stepState(index) === 'upcoming',
                    }"
                    :aria-current="
                        stepState(index) === 'current' ? 'step' : undefined
                    "
                    prefetch
                >
                    <span
                        class="flex h-7 w-7 items-center justify-center border text-xs font-bold"
                        :class="{
                            'border-emerald-700 bg-emerald-700 text-white':
                                stepState(index) === 'complete',
                            'border-blue-700 bg-blue-700 text-white':
                                stepState(index) === 'current',
                            'border-stone-300 bg-stone-100 text-stone-600':
                                stepState(index) === 'upcoming',
                        }"
                    >
                        {{ stepState(index) === 'complete' ? '✓' : index + 1 }}
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-bold text-stone-900">
                            {{ step.label }}
                        </span>
                        <span
                            v-if="stepState(index) === 'current'"
                            class="mt-0.5 block text-xs font-semibold text-blue-800"
                        >
                            Current ceremony
                        </span>
                    </span>
                </Link>
            </li>
        </ol>
    </nav>
</template>
