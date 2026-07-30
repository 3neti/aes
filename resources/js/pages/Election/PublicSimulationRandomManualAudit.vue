<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';

defineProps<{
    precinct: { code: string; label: string; status: string };
    audit: {
        sample: Array<{ payload_hash: string; paper_ballot_serial: number | null }>;
        sample_hash: string | null;
        source_record_count: number;
        approved_ballots: number;
        discrepancy_ballots: number;
        pending: { payload_hash: string; paper_ballot_serial: number | null; selections: Record<string, string[]> } | null;
        reconciliation: { complete: boolean; passed: boolean; verified_ballots: number; discrepancy_ballots: number; pending_ballots: number } | null;
        evidencePackAvailable: boolean;
    };
    actions: { select: string; propose: string; approve: string; reconcile: string; evidencePack: string; download: string };
}>();
</script>

<template>
    <Head :title="`${precinct.code} random manual audit`" />
    <main class="min-h-screen bg-stone-100 p-5 text-stone-950 sm:p-8">
        <section class="mx-auto max-w-5xl border border-stone-300 bg-white p-5 sm:p-7">
            <Link href="/election/play" class="text-sm font-bold text-blue-800">All precincts</Link>
            <p class="mt-5 text-sm font-bold text-blue-800">RANDOM MANUAL AUDIT</p>
            <h1 class="mt-1 text-2xl font-bold">{{ precinct.label }}</h1>
            <p class="mt-3 border-l-4 border-blue-800 bg-blue-50 p-4 text-sm text-blue-950">This room compares a deterministically selected paper ballot QR code with its sealed VVDAT record. It records an audit finding only. The official tally and Election Return cannot be changed here.</p>

            <section class="mt-6 grid gap-4 sm:grid-cols-3">
                <div class="border border-stone-300 p-4"><p class="text-xs font-bold text-stone-600">SAMPLE</p><p class="mt-2 text-2xl font-bold">{{ audit.sample.length }}</p><p class="text-sm text-stone-600">of {{ audit.source_record_count }} sealed records</p></div>
                <div class="border border-stone-300 p-4"><p class="text-xs font-bold text-stone-600">VERIFIED</p><p class="mt-2 text-2xl font-bold">{{ audit.approved_ballots }}</p><p class="text-sm text-stone-600">dual-approved paper comparisons</p></div>
                <div class="border border-stone-300 p-4"><p class="text-xs font-bold text-stone-600">DISCREPANCIES</p><p class="mt-2 text-2xl font-bold">{{ audit.discrepancy_ballots }}</p><p class="text-sm text-stone-600">recorded audit findings</p></div>
            </section>

            <section v-if="audit.sample.length === 0" class="mt-6 border border-stone-300 p-5">
                <h2 class="text-lg font-bold">1. Select the paper-ballot sample</h2>
                <p class="mt-2 text-sm text-stone-700">The sample is ranked deterministically from the sealed VVDAT ledger. Record the assigned precinct officer credentials to seal it.</p>
                <Form :action="actions.select" method="post" #default="{ errors, processing }" class="mt-4 grid gap-3 sm:grid-cols-3"><input name="officer_code" placeholder="Assigned officer code" class="min-h-11 border border-stone-400 px-3" required /><input name="officer_pin" inputmode="numeric" maxlength="6" placeholder="Six-digit PIN" class="min-h-11 border border-stone-400 px-3" required /><button type="submit" class="min-h-11 bg-blue-800 px-4 font-bold text-white" :disabled="processing">{{ processing ? 'Selecting...' : 'Select audit sample' }}</button><p v-if="errors.officer_pin" class="text-sm font-bold text-red-700 sm:col-span-3">{{ errors.officer_pin }}</p></Form>
            </section>

            <template v-else>
                <section class="mt-6 border border-stone-300 p-5">
                    <h2 class="text-lg font-bold">Selected paper ballots</h2>
                    <p class="mt-2 text-sm text-stone-700">Sample {{ audit.sample_hash?.slice(0, 16) }}. Retrieve only these paper ballots from the ballot box for comparison.</p>
                    <ul class="mt-4 divide-y divide-stone-200 border border-stone-200 text-sm"><li v-for="ballot in audit.sample" :key="ballot.payload_hash" class="flex items-center justify-between gap-3 p-3"><span>Paper ballot {{ ballot.paper_ballot_serial ?? 'unserialized' }}</span><code class="text-xs text-stone-600">{{ ballot.payload_hash.slice(0, 16) }}</code></li></ul>
                </section>

                <section v-if="!audit.pending" class="mt-6 border border-stone-300 p-5">
                    <h2 class="text-lg font-bold">2. Scan a sampled ballot QR code</h2>
                    <p class="mt-2 text-sm text-stone-700">Paste a scanner payload or scan through the connected scanner. The system rejects paper QR codes that were not selected for this sample.</p>
                    <Form :action="actions.propose" method="post" #default="{ errors, processing }" class="mt-4 grid gap-3"><textarea name="payload" rows="4" placeholder="Scanned paper ballot QR payload" class="border border-stone-400 p-3 font-mono text-xs" required /><div class="grid gap-3 sm:grid-cols-3"><input name="officer_code" placeholder="Assigned officer code" class="min-h-11 border border-stone-400 px-3" required /><input name="officer_pin" inputmode="numeric" maxlength="6" placeholder="Six-digit PIN" class="min-h-11 border border-stone-400 px-3" required /><button type="submit" class="min-h-11 bg-blue-800 px-4 font-bold text-white" :disabled="processing">{{ processing ? 'Checking...' : 'Propose paper comparison' }}</button></div><p v-if="errors.payload || errors.officer_pin" class="text-sm font-bold text-red-700">{{ errors.payload ?? errors.officer_pin }}</p></Form>
                </section>

                <section v-else class="mt-6 border border-amber-500 bg-amber-50 p-5">
                    <h2 class="text-lg font-bold">3. Confirm the paper comparison</h2>
                    <p class="mt-2 text-sm text-amber-950">Paper ballot {{ audit.pending.paper_ballot_serial ?? 'unserialized' }} is pending dual approval. Compare the paper marks against these QR-derived selections before recording approval.</p>
                    <dl class="mt-4 grid gap-2 text-sm"><div v-for="(candidateIds, contest) in audit.pending.selections" :key="contest" class="grid grid-cols-[10rem_1fr] gap-3"><dt class="font-bold">{{ contest }}</dt><dd>{{ candidateIds.join(', ') }}</dd></div></dl>
                    <Form :action="actions.approve" method="post" #default="{ errors, processing }" class="mt-5 grid gap-3"><input type="hidden" name="payload_hash" :value="audit.pending.payload_hash" /><div class="grid gap-3 sm:grid-cols-2"><input name="officer_code" placeholder="Assigned precinct officer code" class="min-h-11 border border-stone-400 px-3" required /><input name="officer_pin" inputmode="numeric" maxlength="6" placeholder="Assigned officer PIN" class="min-h-11 border border-stone-400 px-3" required /><input name="first_officer_code" placeholder="First board officer code" class="min-h-11 border border-stone-400 px-3" required /><input name="first_officer_pin" inputmode="numeric" maxlength="6" placeholder="First board officer PIN" class="min-h-11 border border-stone-400 px-3" required /><input name="second_officer_code" placeholder="Second board officer code" class="min-h-11 border border-stone-400 px-3" required /><input name="second_officer_pin" inputmode="numeric" maxlength="6" placeholder="Second board officer PIN" class="min-h-11 border border-stone-400 px-3" required /></div><button type="submit" class="min-h-11 bg-emerald-700 px-4 font-bold text-white" :disabled="processing">{{ processing ? 'Recording...' : 'Record dual approval' }}</button><p v-if="errors.payload_hash || errors.second_officer_code || errors.officer_pin" class="text-sm font-bold text-red-700">{{ errors.payload_hash ?? errors.second_officer_code ?? errors.officer_pin }}</p></Form>
                </section>

                <section v-if="!audit.pending" class="mt-6 border border-stone-300 p-5">
                    <h2 class="text-lg font-bold">4. Reconcile the audit sample</h2>
                    <p v-if="audit.reconciliation" class="mt-2 text-sm text-stone-700">{{ audit.reconciliation.verified_ballots }} verified, {{ audit.reconciliation.discrepancy_ballots }} discrepancies, {{ audit.reconciliation.pending_ballots }} pending. <strong>{{ audit.reconciliation.passed ? 'The current reconciliation passes.' : 'The current reconciliation does not yet pass.' }}</strong></p>
                    <Form :action="actions.reconcile" method="post" #default="{ errors, processing }" class="mt-4 grid gap-3 sm:grid-cols-3"><input name="officer_code" placeholder="Assigned officer code" class="min-h-11 border border-stone-400 px-3" required /><input name="officer_pin" inputmode="numeric" maxlength="6" placeholder="Six-digit PIN" class="min-h-11 border border-stone-400 px-3" required /><button type="submit" class="min-h-11 bg-blue-800 px-4 font-bold text-white" :disabled="processing">{{ processing ? 'Reconciling...' : 'Generate reconciliation' }}</button><p v-if="errors.officer_pin" class="text-sm font-bold text-red-700 sm:col-span-3">{{ errors.officer_pin }}</p></Form>
                    <Form v-if="audit.reconciliation?.complete" :action="actions.evidencePack" method="post" #default="{ errors, processing }" class="mt-4 grid gap-3 sm:grid-cols-3"><input name="officer_code" placeholder="Assigned officer code" class="min-h-11 border border-stone-400 px-3" required /><input name="officer_pin" inputmode="numeric" maxlength="6" placeholder="Six-digit PIN" class="min-h-11 border border-stone-400 px-3" required /><button type="submit" class="min-h-11 bg-emerald-700 px-4 font-bold text-white" :disabled="processing">{{ processing ? 'Building...' : 'Build audit evidence pack' }}</button><p v-if="errors.officer_pin" class="text-sm font-bold text-red-700 sm:col-span-3">{{ errors.officer_pin }}</p></Form>
                    <a v-if="audit.evidencePackAvailable" :href="actions.download" class="mt-4 inline-flex min-h-11 items-center bg-stone-800 px-4 font-bold text-white">Download audit evidence PDF</a>
                </section>
            </template>
        </section>
    </main>
</template>
