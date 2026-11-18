<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { home } from '@/routes';
import { index } from '@/routes/election/bundle';
import precinct from '@/routes/election/bundle/precinct';
import {
    login,
    logout,
    show as showReview,
    store as startReview,
} from '@/routes/election/bundle/review';

type Filters = {
    q?: string;
    region?: string;
    province?: string;
    city_municipality?: string;
    barangay?: string;
    status?: string;
    legislative_district?: string;
    review_decision?: string;
    page?: number;
    view?: string;
};

type Precinct = {
    clustered_precinct: string;
    region: string;
    province: string;
    city_municipality: string;
    barangay: string;
    polling_place: string;
    legislative_district: string;
    status: string;
    evidence_id: string;
    review_decision: string;
};

const props = defineProps<{
    overview: {
        available: boolean;
        reason?: string;
        bundle?: {
            id: string;
            election_id: string;
            coverage: string;
            readiness: string;
            archive_sha256: string;
            pop_sha256: string;
        };
        report?: {
            precinct_count: number;
            source_supported_pending_review_count: number;
            unresolved_precinct_count: number;
            unresolved_location_count: number;
            unreviewed_correction_count: number;
            national_coverage_ready: boolean;
        };
        corrections?: {
            id: string;
            category: string;
            scope: string;
            source_url: string;
            source_reference: string;
            review_state: string;
            applied: boolean;
        }[];
    };
    filters: Filters;
    review: {
        reviewer: boolean;
        can_start: boolean;
        run: { id: string; stage: string; decision_count: number } | null;
    };
    browse: {
        group_level: string;
        groups: {
            name: string;
            precinct_count: number;
            supported_count: number;
            unresolved_count: number;
        }[];
        precincts: Precinct[];
        total: number;
        page: number;
        last_page: number;
    } | null;
}>();

const search = ref(props.filters.q ?? '');
const status = ref(props.filters.status ?? '');
const district = ref(props.filters.legislative_district ?? '');
const reviewDecision = ref(props.filters.review_decision ?? '');
const isCorrections = computed(() => props.filters.view === 'corrections');
const number = (value: number) => new Intl.NumberFormat('en-PH').format(value);

function url(overrides: Filters = {}): string {
    return index.url({ query: { ...props.filters, ...overrides } });
}

function drill(level: string, name: string): string {
    const next: Filters = {
        ...props.filters,
        page: 1,
    };
    if (level === 'region') {
        next.region = name;
        next.province = undefined;
        next.city_municipality = undefined;
        next.barangay = undefined;
    } else if (level === 'province') {
        next.province = name;
        next.city_municipality = undefined;
        next.barangay = undefined;
    } else if (level === 'city_municipality') {
        next.city_municipality = name;
        next.barangay = undefined;
    } else if (level === 'barangay') {
        next.barangay = name;
    }
    return index.url({ query: next });
}

function applyFilters(): void {
    router.get(
        index.url({
            query: {
                ...props.filters,
                q: search.value || undefined,
                status: status.value || undefined,
                legislative_district: district.value || undefined,
                review_decision: reviewDecision.value || undefined,
                page: 1,
                view: 'precincts',
            },
        }),
        {},
        { preserveState: true },
    );
}
</script>

<template>
    <Head title="Election Bundle Workbench" />
    <main class="min-h-screen bg-stone-100 text-stone-950">
        <div class="grid h-1.5 grid-cols-3">
            <span class="bg-blue-800" /><span class="bg-yellow-400" /><span
                class="bg-red-700"
            />
        </div>
        <div class="mx-auto max-w-7xl space-y-6 px-5 py-7 sm:px-8">
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p
                        class="text-xs font-black tracking-[0.18em] text-blue-800 uppercase"
                    >
                        2025 national election · source research
                    </p>
                    <h1 class="mt-2 text-3xl font-black sm:text-4xl">
                        Election Bundle Workbench
                    </h1>
                    <p class="mt-2 max-w-3xl text-sm text-stone-600">
                        Explore the signed source snapshot, precinct district
                        evidence, and correction notices.
                    </p>
                </div>
                <Link
                    :href="home.url()"
                    class="border border-stone-300 bg-white px-4 py-2 text-sm font-semibold hover:bg-stone-50"
                >
                    Back to AES
                </Link>
            </header>

            <section
                v-if="!overview.available"
                class="border border-amber-300 bg-amber-50 p-6"
            >
                <h2 class="text-xl font-bold text-amber-950">
                    Research data unavailable
                </h2>
                <p class="mt-2 text-sm text-amber-900">{{ overview.reason }}</p>
            </section>

            <template v-else>
                <section
                    class="flex flex-wrap items-center justify-between gap-4 border border-blue-300 bg-blue-50 p-5"
                >
                    <div>
                        <h2 class="text-lg font-bold text-blue-950">
                            Research ceremony
                        </h2>
                        <p class="mt-1 text-sm text-blue-900">
                            {{
                                review.run
                                    ? `Review run ${review.run.stage} · ${review.run.decision_count} decisions`
                                    : 'A signed research assignment can start a bundle-bound review run.'
                            }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <Link
                            v-if="review.run && review.reviewer"
                            :href="showReview.url(review.run.id)"
                            class="bg-blue-800 px-4 py-2 text-sm font-bold text-white"
                            >Resume review</Link
                        >
                        <Form
                            v-else-if="review.reviewer && review.can_start"
                            v-bind="startReview.form()"
                            #default="{ errors, processing }"
                        >
                            <button
                                type="submit"
                                :disabled="processing"
                                class="bg-blue-800 px-4 py-2 text-sm font-bold text-white disabled:opacity-50"
                            >
                                Start research review
                            </button>
                            <span
                                v-if="errors.research"
                                class="ml-2 text-sm text-red-700"
                                >{{ errors.research }}</span
                            >
                        </Form>
                        <span
                            v-else-if="review.reviewer && !review.run"
                            class="text-sm font-semibold text-amber-800"
                            >Signed assignment or research profile pending</span
                        >
                        <Link
                            v-if="!review.reviewer"
                            :href="login.url()"
                            class="border border-blue-800 px-4 py-2 text-sm font-bold text-blue-900"
                            >Reviewer sign in</Link
                        >
                        <Form v-else v-bind="logout.form()"
                            ><button
                                type="submit"
                                class="text-sm font-bold text-blue-900 underline"
                            >
                                Sign out
                            </button></Form
                        >
                    </div>
                </section>
                <section class="grid gap-4 lg:grid-cols-[1.45fr_1fr]">
                    <div class="border border-stone-300 bg-white p-5">
                        <div class="flex flex-wrap items-center gap-3">
                            <span
                                class="bg-amber-100 px-3 py-1 text-xs font-black tracking-wide text-amber-900 uppercase"
                            >
                                {{ overview.bundle?.readiness }} only
                            </span>
                            <span class="text-xs font-semibold text-stone-500"
                                >Signed bundle verified · reconciliation hashes
                                checked</span
                            >
                        </div>
                        <h2 class="mt-4 text-xl font-bold">Source snapshot</h2>
                        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-stone-500">Bundle ID</dt>
                                <dd class="font-mono text-xs break-all">
                                    {{ overview.bundle?.id }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-stone-500">Coverage</dt>
                                <dd class="font-semibold">
                                    {{ overview.bundle?.coverage }}
                                </dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt class="text-stone-500">Archive SHA-256</dt>
                                <dd class="font-mono text-xs break-all">
                                    {{ overview.bundle?.archive_sha256 }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                    <div class="border border-amber-300 bg-amber-50 p-5">
                        <h2 class="text-xl font-bold text-amber-950">
                            Coverage gate: open
                        </h2>
                        <p class="mt-2 text-sm text-amber-900">
                            The district assignments are source supported and
                            await review. Ballot styles and election-day
                            coverage are not complete.
                        </p>
                        <p class="mt-4 text-xs text-amber-900">
                            Administrative province and PSGC joins are pending.
                            “Province” in the explorer is the original POP
                            label.
                        </p>
                    </div>
                </section>

                <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="border border-stone-300 bg-white p-4">
                        <p class="text-xs font-bold text-stone-500 uppercase">
                            POP precincts
                        </p>
                        <p class="mt-2 text-3xl font-black">
                            {{ number(overview.report!.precinct_count) }}
                        </p>
                    </div>
                    <div class="border border-blue-300 bg-blue-50 p-4">
                        <p class="text-xs font-bold text-blue-800 uppercase">
                            Source supported
                        </p>
                        <p class="mt-2 text-3xl font-black text-blue-950">
                            {{
                                number(
                                    overview.report!
                                        .source_supported_pending_review_count,
                                )
                            }}
                        </p>
                    </div>
                    <div class="border border-amber-300 bg-amber-50 p-4">
                        <p class="text-xs font-bold text-amber-800 uppercase">
                            Unresolved
                        </p>
                        <p class="mt-2 text-3xl font-black text-amber-950">
                            {{
                                number(
                                    overview.report!.unresolved_precinct_count,
                                )
                            }}
                        </p>
                    </div>
                    <div class="border border-stone-300 bg-white p-4">
                        <p class="text-xs font-bold text-stone-500 uppercase">
                            Correction notices
                        </p>
                        <p class="mt-2 text-3xl font-black">
                            {{
                                number(
                                    overview.report!
                                        .unreviewed_correction_count,
                                )
                            }}
                        </p>
                    </div>
                </section>

                <nav
                    aria-label="Workbench views"
                    class="flex gap-2 border-b border-stone-300"
                >
                    <Link
                        :href="url({ view: 'precincts', page: 1 })"
                        class="px-4 py-3 text-sm font-bold"
                        :class="
                            !isCorrections
                                ? 'border-b-4 border-blue-800 text-blue-900'
                                : 'text-stone-600 hover:text-stone-950'
                        "
                        >Precinct explorer</Link
                    >
                    <Link
                        :href="url({ view: 'corrections', page: 1 })"
                        class="px-4 py-3 text-sm font-bold"
                        :class="
                            isCorrections
                                ? 'border-b-4 border-blue-800 text-blue-900'
                                : 'text-stone-600 hover:text-stone-950'
                        "
                        >Correction ledger</Link
                    >
                </nav>

                <section
                    v-if="isCorrections"
                    class="border border-stone-300 bg-white"
                >
                    <div class="border-b border-stone-200 p-5">
                        <h2 class="text-xl font-bold">
                            Identified official notices
                        </h2>
                        <p class="mt-1 text-sm text-stone-600">
                            These notices have not been applied to the
                            crosswalk.
                        </p>
                    </div>
                    <div class="divide-y divide-stone-200">
                        <article
                            v-for="item in overview.corrections"
                            :key="item.id"
                            class="grid gap-3 p-5 md:grid-cols-[1fr_auto]"
                        >
                            <div>
                                <p
                                    class="text-xs font-bold text-stone-500 uppercase"
                                >
                                    {{ item.category.replaceAll('_', ' ') }} ·
                                    {{ item.scope }}
                                </p>
                                <h3 class="mt-1 font-bold">
                                    {{ item.source_reference }}
                                </h3>
                                <a
                                    :href="item.source_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="mt-2 inline-block text-sm font-semibold text-blue-800 underline"
                                    >Open official source ↗</a
                                >
                                <Link
                                    v-if="review.run && review.reviewer"
                                    :href="
                                        showReview.url(review.run.id, {
                                            query: {
                                                case_type: 'correction',
                                                case_id: item.id,
                                            },
                                        })
                                    "
                                    class="ml-4 text-sm font-semibold text-blue-800 underline"
                                    >Review notice</Link
                                >
                            </div>
                            <span
                                class="h-fit bg-amber-100 px-3 py-1 text-xs font-bold text-amber-900"
                                >{{
                                    item.review_state.replaceAll('_', ' ')
                                }}</span
                            >
                        </article>
                    </div>
                </section>

                <template v-else-if="browse">
                    <section class="border border-stone-300 bg-white p-5">
                        <form
                            class="grid gap-3 md:grid-cols-[minmax(0,1fr)_12rem_10rem_10rem_auto]"
                            @submit.prevent="applyFilters"
                        >
                            <label class="text-xs font-bold text-stone-600"
                                >Search precinct, place, or evidence
                                <input
                                    v-model="search"
                                    type="search"
                                    class="mt-1 w-full border border-stone-300 bg-white px-3 py-2 text-sm font-normal"
                                    placeholder="Precinct ID or place"
                                />
                            </label>
                            <label class="text-xs font-bold text-stone-600"
                                >Assignment status
                                <select
                                    v-model="status"
                                    class="mt-1 w-full border border-stone-300 bg-white px-3 py-2 text-sm font-normal"
                                >
                                    <option value="">All statuses</option>
                                    <option value="unresolved">
                                        Unresolved
                                    </option>
                                    <option
                                        value="source_supported_pending_review"
                                    >
                                        Source supported
                                    </option>
                                </select>
                            </label>
                            <label class="text-xs font-bold text-stone-600"
                                >Legislative district
                                <input
                                    v-model="district"
                                    type="text"
                                    class="mt-1 w-full border border-stone-300 bg-white px-3 py-2 text-sm font-normal"
                                    placeholder="e.g. 1 or LONE"
                                />
                            </label>
                            <label
                                v-if="review.run"
                                class="text-xs font-bold text-stone-600"
                                >Review decision
                                <select
                                    v-model="reviewDecision"
                                    class="mt-1 w-full border border-stone-300 bg-white px-3 py-2 text-sm font-normal"
                                >
                                    <option value="">All decisions</option>
                                    <option value="undecided">
                                        Undecided queue
                                    </option>
                                    <option value="approve">Approved</option>
                                    <option value="reject">Rejected</option>
                                    <option value="defer">Deferred</option>
                                </select>
                            </label>
                            <button
                                type="submit"
                                class="self-end bg-blue-800 px-5 py-2 text-sm font-bold text-white hover:bg-blue-900"
                            >
                                Apply
                            </button>
                        </form>
                        <div
                            class="mt-4 flex flex-wrap items-center gap-2 text-sm"
                        >
                            <Link
                                :href="index.url()"
                                class="font-semibold text-blue-800 underline"
                                >Nationwide</Link
                            >
                            <template v-if="filters.region"
                                ><span class="text-stone-400">/</span
                                ><Link
                                    :href="
                                        index.url({
                                            query: { region: filters.region },
                                        })
                                    "
                                    class="font-semibold text-blue-800 underline"
                                    >{{ filters.region }}</Link
                                ></template
                            >
                            <template v-if="filters.province"
                                ><span class="text-stone-400">/</span
                                ><Link
                                    :href="
                                        index.url({
                                            query: {
                                                region: filters.region,
                                                province: filters.province,
                                            },
                                        })
                                    "
                                    class="font-semibold text-blue-800 underline"
                                    >{{ filters.province }}
                                    <span class="font-normal text-stone-500"
                                        >(POP label)</span
                                    ></Link
                                ></template
                            >
                            <template v-if="filters.city_municipality"
                                ><span class="text-stone-400">/</span
                                ><Link
                                    :href="
                                        index.url({
                                            query: {
                                                region: filters.region,
                                                province: filters.province,
                                                city_municipality:
                                                    filters.city_municipality,
                                            },
                                        })
                                    "
                                    class="font-semibold text-blue-800 underline"
                                    >{{ filters.city_municipality }}</Link
                                ></template
                            >
                            <template v-if="filters.barangay"
                                ><span class="text-stone-400">/</span
                                ><span class="font-bold">{{
                                    filters.barangay
                                }}</span></template
                            >
                        </div>
                        <p class="mt-3 text-sm text-stone-600">
                            {{ number(browse.total) }} matching precincts
                        </p>
                    </section>

                    <section
                        v-if="browse.groups.length"
                        class="border border-stone-300 bg-white"
                    >
                        <div class="border-b border-stone-200 p-5">
                            <h2 class="text-xl font-bold">
                                Drill into
                                {{
                                    browse.group_level === 'province'
                                        ? 'POP province label'
                                        : browse.group_level.replaceAll(
                                              '_',
                                              ' ',
                                          )
                                }}
                            </h2>
                        </div>
                        <div
                            class="grid divide-y divide-stone-200 sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-3"
                        >
                            <Link
                                v-for="group in browse.groups"
                                :key="group.name"
                                :href="drill(browse.group_level, group.name)"
                                class="border-b border-stone-200 p-4 hover:bg-blue-50"
                            >
                                <span class="font-bold text-blue-900">{{
                                    group.name || 'Unlabelled'
                                }}</span>
                                <span class="mt-1 block text-xs text-stone-600"
                                    >{{ number(group.precinct_count) }}
                                    precincts ·
                                    {{ number(group.unresolved_count) }}
                                    unresolved</span
                                >
                            </Link>
                        </div>
                    </section>

                    <section
                        class="overflow-x-auto border border-stone-300 bg-white"
                    >
                        <div class="border-b border-stone-200 p-5">
                            <h2 class="text-xl font-bold">Precincts</h2>
                            <p class="mt-1 text-sm text-stone-500">
                                Showing up to 25 per page
                            </p>
                        </div>
                        <table class="w-full min-w-[760px] text-left text-sm">
                            <thead
                                class="bg-stone-100 text-xs text-stone-600 uppercase"
                            >
                                <tr>
                                    <th class="px-4 py-3">Cluster</th>
                                    <th class="px-4 py-3">Location</th>
                                    <th class="px-4 py-3">District</th>
                                    <th class="px-4 py-3">Evidence</th>
                                    <th v-if="review.run" class="px-4 py-3">
                                        Review
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-200">
                                <tr
                                    v-for="row in browse.precincts"
                                    :key="row.clustered_precinct"
                                    class="hover:bg-stone-50"
                                >
                                    <td class="px-4 py-3">
                                        <Link
                                            :href="
                                                precinct.show.url(
                                                    row.clustered_precinct,
                                                )
                                            "
                                            class="font-mono font-bold text-blue-800 underline"
                                            >{{ row.clustered_precinct }}</Link
                                        >
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="font-semibold">{{
                                            row.barangay
                                        }}</span
                                        ><span
                                            class="block text-xs text-stone-500"
                                            >{{ row.city_municipality }} ·
                                            {{ row.region }}</span
                                        >
                                    </td>
                                    <td class="px-4 py-3">
                                        {{
                                            row.legislative_district ||
                                            'Unresolved'
                                        }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span
                                            :class="
                                                row.status === 'unresolved'
                                                    ? 'text-amber-800'
                                                    : 'text-blue-800'
                                            "
                                            class="font-semibold"
                                            >{{
                                                row.status === 'unresolved'
                                                    ? 'Unresolved'
                                                    : 'Source supported'
                                            }}</span
                                        >
                                    </td>
                                    <td
                                        v-if="review.run"
                                        class="px-4 py-3 font-semibold capitalize"
                                    >
                                        {{ row.review_decision }}
                                    </td>
                                </tr>
                                <tr v-if="!browse.precincts.length">
                                    <td
                                        :colspan="review.run ? 5 : 4"
                                        class="px-4 py-8 text-center text-stone-500"
                                    >
                                        No precincts match these filters.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div
                            class="flex items-center justify-between border-t border-stone-200 p-4 text-sm"
                        >
                            <Link
                                v-if="browse.page > 1"
                                :href="url({ page: browse.page - 1 })"
                                class="font-bold text-blue-800 underline"
                                >Previous</Link
                            ><span v-else />
                            <span
                                >Page {{ browse.page }} of
                                {{ browse.last_page }}</span
                            >
                            <Link
                                v-if="browse.page < browse.last_page"
                                :href="url({ page: browse.page + 1 })"
                                class="font-bold text-blue-800 underline"
                                >Next</Link
                            ><span v-else />
                        </div>
                    </section>
                </template>
            </template>
        </div>
    </main>
</template>
