<script setup lang="ts">
import type { ElectionSnapshot } from './types';

defineProps<{
    counts: ElectionSnapshot['counts'];
}>();
</script>

<template>
    <section class="border border-stone-300 bg-white">
        <div class="border-b border-stone-200 px-4 py-3">
            <h2 class="text-sm font-bold text-stone-900">
                Evidence at a glance
            </h2>
        </div>
        <dl class="grid grid-cols-2">
            <div class="border-r border-b border-stone-200 p-3">
                <dt class="text-xs text-stone-600">Ballot files</dt>
                <dd class="mt-1 text-xl font-bold text-stone-950">
                    {{ counts.ballots }}
                </dd>
            </div>
            <div class="border-b border-stone-200 p-3">
                <dt class="text-xs text-stone-600">Print records</dt>
                <dd class="mt-1 text-xl font-bold text-stone-950">
                    {{ counts.printJobs }}
                </dd>
            </div>
            <div class="border-r border-b border-stone-200 p-3">
                <dt class="text-xs text-stone-600">Accepted scans</dt>
                <dd class="mt-1 text-xl font-bold text-emerald-800">
                    {{ counts.accepted }}
                </dd>
            </div>
            <div class="border-b border-stone-200 p-3">
                <dt class="text-xs text-stone-600">Rejected scans</dt>
                <dd class="mt-1 text-xl font-bold text-red-800">
                    {{ counts.rejected }}
                </dd>
            </div>
            <div class="col-span-2 p-3">
                <dt class="text-xs text-stone-600">Officer attestations</dt>
                <dd class="mt-1 text-xl font-bold text-stone-950">
                    {{ counts.attestations }}
                </dd>
            </div>
        </dl>
    </section>
</template>
