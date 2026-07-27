<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { index as reviewRoomIndex } from '@/routes/election/review-room';
import type { ElectionReviewRoomContext } from './types';

const page = usePage();
const reviewRoom = computed(
    () => page.props.electionReviewRoom as ElectionReviewRoomContext,
);
</script>

<template>
    <div
        v-if="reviewRoom.enabled && reviewRoom.station"
        class="border-b border-blue-950 bg-blue-900 px-4 py-2 text-white"
    >
        <div
            class="mx-auto flex w-full max-w-[1500px] flex-wrap items-center justify-between gap-2 text-sm"
        >
            <p class="font-bold">
                {{ reviewRoom.station.label }}
                <span class="font-normal text-blue-200">
                    | Room {{ reviewRoom.station.room_code }}
                </span>
            </p>
            <Link
                v-if="reviewRoom.station.role === 'officer'"
                :href="reviewRoomIndex.url()"
                class="font-bold underline decoration-blue-300 underline-offset-4"
            >
                Open pairing console
            </Link>
            <span v-else class="text-blue-100">
                {{ reviewRoom.station.role_label }}
            </span>
        </div>
    </div>
</template>
