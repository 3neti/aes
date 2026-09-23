<script setup lang="ts">
import TallyBoard from '@/components/election/TallyBoard.vue';
import { computed, ref, watch } from 'vue';

type Tally = Record<string, Record<string, number>>;

type TallyDelta = Record<
    string,
    Record<
        string,
        {
            previousTotal: number;
            addedVotes: number;
            finalTotal: number;
        }
    >
>;

type Contest = {
    id: string;
    title: string;
    max_selections: number;
    candidates: Array<{
        id: string;
        name: string;
    }>;
};

const props = withDefaults(
    defineProps<{
        eyebrow: string;
        title: string;
        acceptedCount: number;
        acceptedLabel: string;
        contests: Contest[];
        tally: Tally;
        view?: string;
        lastScanDelta?: TallyDelta;
        flashKey?: string | number | null;
        revision?: string | number | null;
        lastUpdatedAt?: string | null;
        statusMessage?: string | null;
        publicBoardUrl?: string | null;
        showViewFilters?: boolean;
        enableCandidateSort?: boolean;
    }>(),
    {
        view: 'all',
        flashKey: null,
        revision: null,
        lastUpdatedAt: null,
        statusMessage: null,
        publicBoardUrl: null,
        showViewFilters: true,
        enableCandidateSort: true,
    },
);

const selectedView = ref(props.view);

const viewOptions = [
    { value: 'all', label: 'All' },
    { value: 'national', label: 'National' },
    { value: 'local', label: 'Local' },
    { value: 'president', label: 'President' },
    { value: 'vice-president', label: 'Vice President' },
    { value: 'senator', label: 'Senator' },
    { value: 'mayor', label: 'Mayor' },
    { value: 'councilor', label: 'Councilor' },
    { value: 'party-list', label: 'Party List' },
];

watch(
    () => props.view,
    (view) => {
        selectedView.value = view;
    },
);

const activeViewTokens = computed(() =>
    selectedView.value
        .split(',')
        .map((token) => token.trim().toLowerCase())
        .filter(Boolean),
);

const activeViewLabel = computed(() =>
    activeViewTokens.value.length === 0 ||
    activeViewTokens.value.includes('all')
        ? 'All contests'
        : activeViewTokens.value
              .map(
                  (token) =>
                      viewOptions.find((option) => option.value === token)
                          ?.label ?? token,
              )
              .join(' + '),
);

const filteredContests = computed(() => {
    if (
        activeViewTokens.value.length === 0 ||
        activeViewTokens.value.includes('all')
    ) {
        return props.contests;
    }

    return props.contests.filter((contest) =>
        activeViewTokens.value.some((token) =>
            contestMatchesView(contest, token),
        ),
    );
});

function viewUrl(view: string): string {
    if (!props.publicBoardUrl) {
        return '#';
    }

    const url = new URL(props.publicBoardUrl, window.location.origin);
    url.searchParams.set('view', view);

    return url.toString();
}

function selectView(view: string): void {
    selectedView.value = view;
}

function isActiveView(view: string): boolean {
    return (
        activeViewTokens.value.includes(view) ||
        (view === 'all' &&
            (activeViewTokens.value.length === 0 ||
                activeViewTokens.value.includes('all')))
    );
}

function contestMatchesView(contest: Contest, token: string): boolean {
    const normalizedId = contest.id.replaceAll('_', '-');

    if (token === 'national') {
        return (
            contest.id.includes('philippines') ||
            contest.id.includes('party_list')
        );
    }

    if (token === 'local') {
        return !contestMatchesView(contest, 'national');
    }

    if (token === 'president') {
        return (
            normalizedId === 'president' ||
            normalizedId.startsWith('president-')
        );
    }

    if (token === 'vice-president') {
        return normalizedId.startsWith('vice-president');
    }

    if (['senator', 'mayor', 'councilor', 'party-list'].includes(token)) {
        return normalizedId === token || normalizedId.startsWith(`${token}-`);
    }

    return normalizedId.includes(token);
}
</script>

<template>
    <section class="space-y-3">
        <div
            v-if="showViewFilters"
            class="flex flex-col gap-3 border border-stone-300 bg-white p-3 lg:flex-row lg:items-end lg:justify-between"
        >
            <div>
                <p class="text-xs font-bold text-amber-800 uppercase">
                    Tally view
                </p>
                <p class="mt-1 text-sm text-stone-600">
                    {{ activeViewLabel }}
                    <span v-if="statusMessage">· {{ statusMessage }}</span>
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <template v-if="publicBoardUrl">
                    <a
                        v-for="option in viewOptions"
                        :key="option.value"
                        :href="viewUrl(option.value)"
                        class="border px-3 py-2 text-sm font-bold"
                        :class="
                            isActiveView(option.value)
                                ? 'border-blue-800 bg-blue-800 text-white'
                                : 'border-stone-300 bg-white text-stone-700'
                        "
                    >
                        {{ option.label }}
                    </a>
                </template>
                <template v-else>
                    <button
                        v-for="option in viewOptions"
                        :key="option.value"
                        type="button"
                        class="border px-3 py-2 text-sm font-bold"
                        :class="
                            isActiveView(option.value)
                                ? 'border-blue-800 bg-blue-800 text-white'
                                : 'border-stone-300 bg-white text-stone-700'
                        "
                        @click="selectView(option.value)"
                    >
                        {{ option.label }}
                    </button>
                </template>
            </div>
        </div>

        <TallyBoard
            :eyebrow="eyebrow"
            :title="title"
            :accepted-count="acceptedCount"
            :accepted-label="acceptedLabel"
            :contests="filteredContests"
            :tally="tally"
            :last-scan-delta="lastScanDelta"
            :flash-key="flashKey"
            :enable-candidate-sort="enableCandidateSort"
        >
            <template #stats>
                <p
                    v-if="revision !== null || lastUpdatedAt"
                    class="mt-2 text-xs text-stone-500"
                >
                    <span v-if="revision !== null"
                        >Revision {{ revision }}</span
                    >
                    <span v-if="lastUpdatedAt">
                        <span v-if="revision !== null">· </span>
                        {{ lastUpdatedAt }}
                    </span>
                </p>
            </template>
        </TallyBoard>
    </section>
</template>
