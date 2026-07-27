<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import type { ElectionReviewRoom } from '@/components/election/types';
import {
    close as closeReviewRoom,
    store as storeReviewRoom,
} from '@/routes/election/review-room';

const props = defineProps<{
    room: ElectionReviewRoom | null;
    isFacilitator: boolean;
    defaults: {
        name: string;
        voter_stations: number;
        max_voter_stations: number;
    };
}>();

let refreshTimer: ReturnType<typeof setInterval> | null = null;

const connectedLabel = computed(() => {
    if (!props.room) {
        return 'No active room';
    }

    return `${props.room.connected_station_count} of ${props.room.station_count} connected`;
});

const statusClasses = {
    waiting: 'border-amber-300 bg-amber-50 text-amber-900',
    connected: 'border-emerald-300 bg-emerald-50 text-emerald-900',
    inactive: 'border-stone-300 bg-stone-100 text-stone-700',
};

function formatTime(value: string | null): string {
    if (!value) {
        return 'Not yet';
    }

    return new Intl.DateTimeFormat('en-PH', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Manila',
    }).format(new Date(value));
}

onMounted(() => {
    refreshTimer = setInterval(() => {
        if (props.room?.status === 'open') {
            router.reload({
                only: ['room', 'isFacilitator'],
            });
        }
    }, 5000);
});

onBeforeUnmount(() => {
    if (refreshTimer) {
        clearInterval(refreshTimer);
    }
});
</script>

<template>
    <Head title="Multi-Tablet Review Room" />

    <div class="min-h-screen bg-stone-100 text-stone-950">
        <div class="grid h-1.5 grid-cols-3" aria-hidden="true">
            <span class="bg-blue-800" />
            <span class="bg-yellow-400" />
            <span class="bg-red-700" />
        </div>

        <header class="border-b border-stone-300 bg-white">
            <div
                class="mx-auto flex w-full max-w-[1500px] flex-col gap-3 px-4 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-8"
            >
                <div>
                    <p class="text-xs font-bold text-blue-800 uppercase">
                        COMELEC Review Environment
                    </p>
                    <h1 class="mt-1 text-2xl font-bold sm:text-3xl">
                        Multi-Tablet Review Room
                    </h1>
                </div>
                <div
                    v-if="room"
                    class="grid grid-cols-2 gap-x-8 gap-y-1 text-sm"
                >
                    <div>
                        <p class="text-xs text-stone-500">Room code</p>
                        <p class="font-bold">{{ room.code }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-stone-500">Stations</p>
                        <p class="font-bold">{{ connectedLabel }}</p>
                    </div>
                </div>
            </div>
        </header>

        <main
            class="mx-auto flex w-full max-w-[1500px] flex-col gap-5 px-4 py-6 sm:px-6 lg:px-8"
        >
            <section
                v-if="!room || room.status === 'closed'"
                class="border border-stone-300 bg-white"
            >
                <header class="border-b border-stone-200 px-5 py-4">
                    <p class="text-sm font-bold text-blue-800">
                        Facilitator setup
                    </p>
                    <h2 class="mt-1 text-xl font-bold">
                        {{
                            room
                                ? 'Start a new review room'
                                : 'Prepare device stations'
                        }}
                    </h2>
                    <p v-if="room" class="mt-2 text-sm text-stone-600">
                        Room {{ room.code }} closed at
                        {{ formatTime(room.closed_at) }}.
                    </p>
                </header>

                <Form
                    v-bind="storeReviewRoom.form()"
                    #default="{ errors, processing }"
                    class="grid gap-5 p-5 md:grid-cols-[minmax(0,1fr)_220px_auto] md:items-end"
                >
                    <label class="block">
                        <span class="text-sm font-bold text-stone-700">
                            Review name
                        </span>
                        <input
                            name="name"
                            type="text"
                            required
                            maxlength="100"
                            :value="defaults.name"
                            class="mt-1 min-h-11 w-full border border-stone-400 bg-white px-3"
                        />
                        <span
                            v-if="errors.name"
                            class="mt-1 block text-sm font-bold text-red-700"
                        >
                            {{ errors.name }}
                        </span>
                    </label>
                    <label class="block">
                        <span class="text-sm font-bold text-stone-700">
                            Voter tablets
                        </span>
                        <input
                            name="voter_stations"
                            type="number"
                            required
                            min="1"
                            :max="defaults.max_voter_stations"
                            :value="defaults.voter_stations"
                            class="mt-1 min-h-11 w-full border border-stone-400 bg-white px-3"
                        />
                        <span
                            v-if="errors.voter_stations"
                            class="mt-1 block text-sm font-bold text-red-700"
                        >
                            {{ errors.voter_stations }}
                        </span>
                    </label>
                    <button
                        type="submit"
                        :disabled="processing"
                        class="min-h-11 bg-blue-800 px-5 py-3 text-sm font-bold text-white disabled:opacity-50"
                    >
                        {{ processing ? 'Preparing...' : 'Create review room' }}
                    </button>
                    <p
                        v-if="errors.room"
                        class="font-bold text-red-700 md:col-span-3"
                    >
                        {{ errors.room }}
                    </p>
                </Form>
            </section>

            <section
                v-else-if="!isFacilitator"
                class="border-l-4 border-blue-800 bg-white px-6 py-8"
            >
                <p class="text-sm font-bold text-blue-800">Station pairing</p>
                <h2 class="mt-1 text-2xl font-bold">
                    Scan this device's assigned QR
                </h2>
                <p class="mt-3 max-w-2xl text-stone-700">
                    Ask the facilitator to display the QR for this tablet or
                    screen. Each station is paired to one browser session.
                </p>
            </section>

            <template v-else>
                <section
                    class="grid gap-4 border border-stone-300 bg-white px-5 py-4 sm:grid-cols-2 xl:grid-cols-4"
                >
                    <div>
                        <p class="text-xs font-bold text-stone-500 uppercase">
                            Status
                        </p>
                        <p class="mt-1 text-lg font-bold capitalize">
                            {{ room.status }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-stone-500 uppercase">
                            Clustered precinct
                        </p>
                        <p class="mt-1 text-lg font-bold">
                            {{ room.precinct_id || 'Pending setup' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-stone-500 uppercase">
                            Opened
                        </p>
                        <p class="mt-1 font-bold">
                            {{ formatTime(room.opened_at) }}
                        </p>
                    </div>
                    <Form
                        v-bind="closeReviewRoom.form(room.code)"
                        class="flex items-center justify-start sm:justify-end"
                    >
                        <button
                            type="submit"
                            class="min-h-11 border border-red-700 bg-white px-5 py-3 text-sm font-bold text-red-800"
                        >
                            Close review room
                        </button>
                    </Form>
                </section>

                <section>
                    <header
                        class="flex flex-col gap-2 border-b border-stone-300 pb-3 sm:flex-row sm:items-end sm:justify-between"
                    >
                        <div>
                            <p class="text-sm font-bold text-blue-800">
                                Device pairing
                            </p>
                            <h2 class="mt-1 text-xl font-bold">
                                Assigned review stations
                            </h2>
                        </div>
                        <p class="text-sm font-bold text-stone-600">
                            {{ connectedLabel }}
                        </p>
                    </header>

                    <div
                        class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                    >
                        <article
                            v-for="station in room.stations"
                            :key="station.id"
                            class="border border-stone-300 bg-white p-4"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p
                                        class="text-xs font-bold text-blue-800 uppercase"
                                    >
                                        {{ station.role_label }}
                                    </p>
                                    <h3 class="mt-1 text-lg font-bold">
                                        {{ station.label }}
                                    </h3>
                                </div>
                                <span
                                    class="border px-2 py-1 text-xs font-bold capitalize"
                                    :class="statusClasses[station.status]"
                                >
                                    {{ station.status }}
                                </span>
                            </div>

                            <img
                                v-if="station.join_qr"
                                :src="station.join_qr"
                                :alt="`Pair ${station.label}`"
                                class="mx-auto mt-4 aspect-square w-full max-w-52 border border-stone-200 bg-white p-2"
                            />

                            <p class="mt-3 text-xs text-stone-600">
                                Last seen:
                                {{ formatTime(station.last_seen_at) }}
                            </p>
                            <a
                                v-if="station.join_url"
                                :href="station.join_url"
                                class="mt-3 inline-flex min-h-10 w-full items-center justify-center bg-blue-800 px-3 py-2 text-center text-sm font-bold text-white"
                            >
                                Pair this device
                            </a>
                        </article>
                    </div>
                </section>

                <section class="border border-stone-300 bg-white">
                    <header class="border-b border-stone-200 px-5 py-4">
                        <p class="text-sm font-bold text-blue-800">
                            Append-only activity
                        </p>
                        <h2 class="mt-1 text-xl font-bold">
                            Review room evidence chain
                        </h2>
                    </header>
                    <ol class="divide-y divide-stone-200">
                        <li
                            v-for="event in room.events"
                            :key="event.event_hash"
                            class="grid gap-2 px-5 py-4 sm:grid-cols-[70px_minmax(0,1fr)_220px]"
                        >
                            <span class="text-sm font-bold text-blue-800">
                                #{{ event.sequence }}
                            </span>
                            <div>
                                <p class="font-bold">{{ event.event_type }}</p>
                                <p
                                    class="mt-1 truncate font-mono text-xs text-stone-500"
                                >
                                    {{ event.event_hash }}
                                </p>
                            </div>
                            <time class="text-sm text-stone-600 sm:text-right">
                                {{ formatTime(event.occurred_at) }}
                            </time>
                        </li>
                    </ol>
                </section>
            </template>
        </main>
    </div>
</template>
