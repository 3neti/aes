import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import type {
    ElectionReviewDefaults,
    ElectionReviewMode,
} from '@/components/election/types';

const defaultsLoaded = ref(true);
const lastEnabledState = ref<boolean | null>(null);

export function useElectionReview() {
    const page = usePage();
    const review = computed(
        () => page.props.electionReview as ElectionReviewMode,
    );

    watch(
        () => review.value.enabled,
        (enabled) => {
            if (lastEnabledState.value !== enabled) {
                defaultsLoaded.value = enabled;
                lastEnabledState.value = enabled;
            }
        },
        { immediate: true },
    );

    const loaded = computed(() => review.value.enabled && defaultsLoaded.value);
    const defaults = computed<ElectionReviewDefaults>(() =>
        loaded.value ? review.value.defaults : {},
    );

    return {
        review,
        loaded,
        defaults,
        clearDefaults: () => {
            defaultsLoaded.value = false;
        },
        reloadDefaults: () => {
            defaultsLoaded.value = review.value.enabled;
        },
    };
}
