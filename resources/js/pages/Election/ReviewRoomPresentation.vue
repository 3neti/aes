<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import type {
    ElectionReviewRoom,
    ElectionSnapshot,
} from '@/components/election/types';

const props = defineProps<{
    room: ElectionReviewRoom;
    snapshot: ElectionSnapshot;
}>();

let refreshTimer: ReturnType<typeof setInterval> | null = null;

const readinessPercent = computed(() => {
    if (props.room.station_count === 0) {
        return 0;
    }

    return Math.round(
        (props.room.connected_station_count / props.room.station_count) * 100,
    );
});

const statusClasses = {
    waiting: 'border-amber-400 bg-amber-50 text-amber-950',
    connected: 'border-emerald-600 bg-emerald-50 text-emerald-950',
    inactive: 'border-stone-400 bg-stone-100 text-stone-700',
};

onMounted(() => {
    refreshTimer = setInterval(() => {
        router.reload({
            only: ['room', 'snapshot'],
        });
    }, 5000);
});

onBeforeUnmount(() => {
    if (refreshTimer) {
        clearInterval(refreshTimer);
    }
});
</script>

<template>
    <Head title="Review Room Presentation" />

    <main class="min-h-screen bg-stone-100 text-stone-950">
        <div class="grid h-2 grid-cols-3" aria-hidden="true">
            <span class="bg-blue-800" />
            <span class="bg-yellow-400" />
            <span class="bg-red-700" />
        </div>

        <header class="border-b border-stone-300 bg-white px-6 py-5">
            <div
                class="mx-auto flex max-w-[1700px] flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"
            >
                <div>
                    <p class="text-sm font-bold text-blue-800 uppercase">
                        {{ room.name }}
                    </p>
                    <h1 class="mt-1 text-3xl font-bold">
                        Precinct {{ room.precinct_id || 'Setup in progress' }}
                    </h1>
                </div>
                <div class="flex items-end gap-8">
                    <div>
                        <p class="text-xs font-bold text-stone-500 uppercase">
                            Room
                        </p>
                        <p class="mt-1 text-xl font-bold">{{ room.code }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-stone-500 uppercase">
                            Station readiness
                        </p>
                        <p class="mt-1 text-xl font-bold">
                            {{ readinessPercent }}%
                        </p>
                    </div>
                </div>
            </div>
        </header>

        <div
            class="mx-auto grid max-w-[1700px] gap-6 px-6 py-6 xl:grid-cols-[minmax(0,1fr)_380px]"
        >
            <div class="space-y-6">
                <section class="border-l-8 border-blue-800 bg-white px-6 py-5">
                    <p class="text-sm font-bold text-blue-800 uppercase">
                        Current ceremony
                    </p>
                    <h2 class="mt-2 text-3xl font-bold">
                        {{ snapshot.ceremony }}
                    </h2>
                    <p class="mt-3 text-xl text-stone-700">
                        {{ snapshot.nextAction }}
                    </p>
                </section>

                <section>
                    <header
                        class="flex items-end justify-between border-b border-stone-300 pb-3"
                    >
                        <h2 class="text-2xl font-bold">Review stations</h2>
                        <p class="font-bold text-stone-600">
                            {{ room.connected_station_count }} of
                            {{ room.station_count }} connected
                        </p>
                    </header>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <article
                            v-for="station in room.stations"
                            :key="station.id"
                            class="min-h-28 border-l-4 p-4"
                            :class="statusClasses[station.status]"
                        >
                            <p class="text-xs font-bold uppercase">
                                {{ station.role_label }}
                            </p>
                            <h3 class="mt-2 text-xl font-bold">
                                {{ station.label }}
                            </h3>
                            <p class="mt-2 text-sm font-bold capitalize">
                                {{ station.status }}
                            </p>
                        </article>
                    </div>
                </section>
            </div>

            <aside class="space-y-5">
                <section class="border border-stone-300 bg-white">
                    <header class="border-b border-stone-200 px-5 py-4">
                        <p class="text-sm font-bold text-blue-800">
                            Public ceremony status
                        </p>
                        <h2 class="mt-1 text-xl font-bold">
                            Precinct operations
                        </h2>
                    </header>
                    <dl class="divide-y divide-stone-200">
                        <div class="px-5 py-4">
                            <dt class="text-sm text-stone-600">
                                Lifecycle stage
                            </dt>
                            <dd class="mt-1 text-lg font-bold">
                                {{ snapshot.stageLabel }}
                            </dd>
                        </div>
                        <div class="px-5 py-4">
                            <dt class="text-sm text-stone-600">
                                Paper ballots issued
                            </dt>
                            <dd class="mt-1 text-3xl font-bold">
                                {{ snapshot.paperBallots.issued }}
                            </dd>
                        </div>
                        <div class="px-5 py-4">
                            <dt class="text-sm text-stone-600">
                                Paper ballots deposited
                            </dt>
                            <dd class="mt-1 text-3xl font-bold">
                                {{ snapshot.paperBallots.deposited }}
                            </dd>
                        </div>
                        <div class="px-5 py-4">
                            <dt class="text-sm text-stone-600">
                                Journal checkpoints
                            </dt>
                            <dd class="mt-1 text-3xl font-bold">
                                {{ snapshot.journal.length }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <div
                    class="border-l-4 border-amber-500 bg-amber-50 px-5 py-4 text-sm text-amber-950"
                >
                    Candidate totals remain sealed until the applicable
                    post-close ceremony.
                </div>
            </aside>
        </div>
    </main>
</template>
