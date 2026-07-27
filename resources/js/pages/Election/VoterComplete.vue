<script setup lang="ts">
import ReviewStationBar from '@/components/election/ReviewStationBar.vue';

defineProps<{
    release: {
        release_id: string;
        release_code: string;
        release_qr_data_uri: string;
        paper_ballot_serial?: string;
        expires_at: string;
    };
}>();
</script>

<template>
    <main
        class="flex min-h-screen items-center justify-center bg-stone-100 p-5 text-stone-950"
    >
        <section
            class="w-full max-w-xl border-t-8 border-emerald-700 bg-white p-6 text-center shadow-sm sm:p-8"
        >
            <ReviewStationBar />
            <p class="text-sm font-bold text-emerald-800">
                Ballot finalized privately
            </p>
            <h1 class="mt-2 text-3xl font-bold">
                Print and verify your paper ballot
            </h1>
            <p class="mt-4 text-stone-700">
                Take this tablet to the private print station. Scan this code or
                enter the number. The print station will not display your
                choices.
            </p>
            <img
                :src="release.release_qr_data_uri"
                alt="Private one-time print release code"
                class="mx-auto mt-5 h-64 w-64 border border-stone-300 bg-white p-2"
            />
            <p class="mt-4 font-mono text-3xl font-bold">
                {{ release.release_code }}
            </p>
            <p class="mt-2 text-sm text-stone-600">
                Paper stock serial {{ release.paper_ballot_serial }}
            </p>
            <div
                class="mt-6 border border-amber-300 bg-amber-50 p-4 text-left text-sm"
            >
                <strong class="block text-amber-900"
                    >Before depositing the paper ballot</strong
                >
                <span class="mt-1 block text-amber-900">
                    Read the printed candidate names in private. If anything is
                    wrong, do not deposit it; call an Election Board member.
                </span>
            </div>
        </section>
    </main>
</template>
