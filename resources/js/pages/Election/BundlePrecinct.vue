<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { index } from '@/routes/election/bundle';
import { show as showReview } from '@/routes/election/bundle/review';

const props = defineProps<{
    overview: {
        bundle: {
            id: string;
            readiness: string;
            coverage: string;
            pop_sha256: string;
        };
    };
    precinct: {
        clustered_precinct: string;
        region: string;
        province: string;
        city_municipality: string;
        barangay: string;
        polling_place: string;
        precinct_cluster: string;
        registered_voters: string;
        source_row: string;
        legislative_district: string;
        status: string;
        evidence_id: string;
        source_url: string;
    };
    review: {
        reviewer: boolean;
        run: { id: string } | null;
    };
}>();

const explorerUrl = index.url({
    query: {
        region: props.precinct.region,
        province:
            props.precinct.region === 'NCR'
                ? undefined
                : props.precinct.province,
        city_municipality: props.precinct.city_municipality,
        barangay: props.precinct.barangay,
    },
});
</script>

<template>
    <Head
        :title="`Precinct ${precinct.clustered_precinct} · Election Bundle`"
    />
    <main class="min-h-screen bg-stone-100 text-stone-950">
        <div class="grid h-1.5 grid-cols-3">
            <span class="bg-blue-800" /><span class="bg-yellow-400" /><span
                class="bg-red-700"
            />
        </div>
        <div class="mx-auto max-w-5xl space-y-5 px-5 py-7 sm:px-8">
            <Link
                :href="explorerUrl"
                class="text-sm font-bold text-blue-800 underline"
                >← Back to precinct explorer</Link
            >
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p
                        class="text-xs font-black tracking-[0.18em] text-blue-800 uppercase"
                    >
                        2025 national election · precinct evidence
                    </p>
                    <h1 class="mt-2 text-3xl font-black">
                        Cluster {{ precinct.clustered_precinct }}
                    </h1>
                    <p class="mt-2 text-sm text-stone-600">
                        {{ precinct.barangay }} ·
                        {{ precinct.city_municipality }} · {{ precinct.region }}
                    </p>
                </div>
                <span
                    class="bg-amber-100 px-3 py-2 text-xs font-black text-amber-900 uppercase"
                    >{{ overview.bundle.readiness }} bundle</span
                >
            </header>
            <Link
                v-if="review.reviewer && review.run"
                :href="
                    showReview.url(review.run.id, {
                        query: {
                            case_type: 'precinct',
                            case_id: precinct.clustered_precinct,
                        },
                    })
                "
                class="inline-block bg-blue-800 px-4 py-2 text-sm font-bold text-white"
                >Review this precinct assignment</Link
            >

            <section class="border border-amber-300 bg-amber-50 p-5">
                <h2 class="font-bold text-amber-950">Research assignment</h2>
                <p class="mt-1 text-sm text-amber-900">
                    {{
                        precinct.status === 'unresolved'
                            ? 'No legislative district has been assigned from reviewed evidence.'
                            : 'This district is source supported and still awaits review.'
                    }}
                    Ballot style compilation and PSGC reconciliation remain
                    pending.
                </p>
            </section>

            <section class="grid gap-4 md:grid-cols-2">
                <div class="border border-stone-300 bg-white p-5">
                    <h2 class="text-xl font-bold">POP record</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div>
                            <dt class="text-stone-500">Region</dt>
                            <dd class="font-semibold">{{ precinct.region }}</dd>
                        </div>
                        <div>
                            <dt class="text-stone-500">POP province label</dt>
                            <dd class="font-semibold">
                                {{ precinct.province }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-stone-500">
                                Administrative province
                            </dt>
                            <dd class="font-semibold">
                                {{
                                    precinct.region === 'NCR'
                                        ? 'None (NCR)'
                                        : 'Pending PSGC reconciliation'
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-stone-500">City or municipality</dt>
                            <dd class="font-semibold">
                                {{ precinct.city_municipality }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-stone-500">Barangay</dt>
                            <dd class="font-semibold">
                                {{ precinct.barangay }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-stone-500">Polling place</dt>
                            <dd class="font-semibold">
                                {{ precinct.polling_place || 'Not available' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-stone-500">Component precincts</dt>
                            <dd>
                                {{
                                    precinct.precinct_cluster || 'Not available'
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-stone-500">Registered voters</dt>
                            <dd>
                                {{
                                    precinct.registered_voters ||
                                    'Not available'
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-stone-500">Workbook row</dt>
                            <dd>
                                {{ precinct.source_row || 'Not available' }}
                            </dd>
                        </div>
                    </dl>
                </div>
                <div class="border border-stone-300 bg-white p-5">
                    <h2 class="text-xl font-bold">District evidence</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div>
                            <dt class="text-stone-500">Legislative district</dt>
                            <dd class="text-lg font-bold">
                                {{
                                    precinct.legislative_district ||
                                    'Unresolved'
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-stone-500">Assignment status</dt>
                            <dd class="font-semibold">
                                {{ precinct.status.replaceAll('_', ' ') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-stone-500">Evidence ID</dt>
                            <dd class="font-mono text-xs break-all">
                                {{
                                    precinct.evidence_id ||
                                    'No assignment evidence'
                                }}
                            </dd>
                        </div>
                        <div v-if="precinct.source_url">
                            <dt class="text-stone-500">Source</dt>
                            <dd>
                                <a
                                    :href="precinct.source_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="font-semibold text-blue-800 underline"
                                    >Open source ↗</a
                                >
                            </dd>
                        </div>
                    </dl>
                    <div
                        class="mt-6 border-t border-stone-200 pt-4 text-xs text-stone-600"
                    >
                        <p>
                            Bundle {{ overview.bundle.id }} ·
                            {{ overview.bundle.coverage }}
                        </p>
                        <p class="mt-2 font-mono break-all">
                            POP SHA-256: {{ overview.bundle.pop_sha256 }}
                        </p>
                    </div>
                </div>
            </section>
        </div>
    </main>
</template>
