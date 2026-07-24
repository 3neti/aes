<script setup lang="ts">
defineProps<{
    ballot: {
        ballot_id: string;
        paper_ballot_serial?: string;
    };
}>();
</script>

<template>
    <main class="flex min-h-screen items-center justify-center bg-stone-100 p-5 text-stone-950">
        <section class="w-full max-w-xl border-t-8 border-emerald-700 bg-white p-8 text-center shadow-sm">
            <p class="text-sm font-bold text-emerald-800">Ballot finalized</p>
            <h1 class="mt-2 text-3xl font-bold">Please call the election officer</h1>
            <p class="mt-4 text-base text-stone-700">
                Your paper ballot will be printed for your review. Do not leave until you have checked and deposited the paper ballot.
            </p>
            <dl class="mt-6 border border-stone-300 bg-stone-50 p-4 text-sm">
                <dt class="text-stone-500">Paper stock serial</dt>
                <dd class="mt-1 text-lg font-bold">
                    {{ ballot.paper_ballot_serial }}
                </dd>
            </dl>
        </section>
    </main>
</template>
