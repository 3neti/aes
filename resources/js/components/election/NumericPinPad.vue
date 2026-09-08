<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';

type DisplayTheme = 'secure-blue' | 'paper';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        digits: number;
        label: string;
        name?: string;
        submitLabel: string;
        processingLabel?: string;
        processing?: boolean;
        error?: string;
        autofocus?: boolean;
        allowIncompleteSubmit?: boolean;
        displayTheme?: DisplayTheme;
    }>(),
    {
        name: 'code',
        processingLabel: 'Checking...',
        processing: false,
        error: undefined,
        autofocus: false,
        allowIncompleteSubmit: false,
        displayTheme: 'secure-blue',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
    submit: [];
}>();

const keypad = ref<HTMLElement | null>(null);
const normalizedDigits = computed(() => Math.max(1, props.digits));
const enteredDigits = computed(() => props.modelValue.replace(/\D/g, ''));
const digitCells = computed(() =>
    Array.from({ length: normalizedDigits.value }, (_, index) =>
        enteredDigits.value[index] ?? '',
    ),
);
const canSubmit = computed(
    () =>
        props.allowIncompleteSubmit ||
        enteredDigits.value.length === normalizedDigits.value,
);
const displayClasses = computed(() =>
    props.displayTheme === 'secure-blue'
        ? 'border-blue-950 bg-blue-950 text-white shadow-inner'
        : 'border-stone-300 bg-white text-stone-950',
);
const labelClasses = computed(() =>
    props.displayTheme === 'secure-blue'
        ? 'text-blue-100'
        : 'text-stone-700',
);
const counterClasses = computed(() =>
    props.displayTheme === 'secure-blue'
        ? 'text-yellow-200'
        : 'text-stone-500',
);
const digitCellClasses = computed(() =>
    props.displayTheme === 'secure-blue'
        ? 'border-blue-700 bg-blue-900/80 text-blue-200'
        : 'border-stone-300 bg-white text-stone-400',
);
const filledDigitCellClasses = computed(() =>
    props.displayTheme === 'secure-blue'
        ? 'border-yellow-300 bg-blue-900 text-yellow-300 shadow-[0_0_0_1px_rgba(253,224,71,0.35)]'
        : 'border-blue-800 bg-white text-stone-950',
);
const keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9'];

function appendDigit(digit: string): void {
    if (props.processing || enteredDigits.value.length >= normalizedDigits.value) {
        return;
    }

    emit('update:modelValue', enteredDigits.value + digit);
    focus();
}

function deleteDigit(): void {
    if (props.processing || enteredDigits.value.length === 0) {
        return;
    }

    emit('update:modelValue', enteredDigits.value.slice(0, -1));
    focus();
}

function clearDigits(): void {
    if (props.processing || enteredDigits.value.length === 0) {
        return;
    }

    emit('update:modelValue', '');
    focus();
}

function submit(): void {
    if (props.processing || !canSubmit.value) {
        return;
    }

    const form = keypad.value?.closest('form');

    if (form instanceof HTMLFormElement) {
        form.requestSubmit();

        return;
    }

    emit('submit');
}

function handleKeydown(event: KeyboardEvent): void {
    if (/^[0-9]$/.test(event.key)) {
        event.preventDefault();
        appendDigit(event.key);

        return;
    }

    if (event.key === 'Backspace' || event.key === 'Delete') {
        event.preventDefault();
        deleteDigit();

        return;
    }

    if (event.key === 'Escape') {
        event.preventDefault();
        clearDigits();

        return;
    }

    if (event.key === 'Enter') {
        event.preventDefault();
        submit();
    }
}

function focus(): void {
    void nextTick(() => keypad.value?.focus());
}

onMounted(() => {
    if (props.autofocus) {
        focus();
    }
});

defineExpose({ focus });
</script>

<template>
    <section
        ref="keypad"
        class="rounded-sm border-2 border-stone-300 bg-stone-50 p-3 outline-none focus:border-blue-700 focus:ring-4 focus:ring-blue-100 sm:p-4"
        tabindex="0"
        role="group"
        :aria-label="label"
        @keydown="handleKeydown"
    >
        <input
            class="sr-only"
            type="text"
            :name="name"
            :value="enteredDigits"
            autocomplete="off"
            inputmode="none"
            readonly
            tabindex="-1"
            aria-hidden="true"
        />

        <div
            class="rounded-sm border-2 p-3 transition-colors"
            :class="displayClasses"
        >
            <div class="flex items-center justify-between gap-3">
                <span class="text-sm font-bold" :class="labelClasses">{{
                    label
                }}</span>
                <span
                    class="text-xs font-bold tracking-wide"
                    :class="counterClasses"
                >
                    {{ enteredDigits.length }} / {{ normalizedDigits }}
                </span>
            </div>

            <div
                class="mt-3 grid gap-2"
                :style="{
                    gridTemplateColumns: `repeat(${normalizedDigits}, minmax(0, 1fr))`,
                }"
                aria-hidden="true"
            >
                <span
                    v-for="(digit, index) in digitCells"
                    :key="index"
                    class="flex aspect-square min-h-12 items-center justify-center border-2 font-mono text-4xl font-black tabular-nums transition-colors sm:min-h-14"
                    :class="
                        digit ? filledDigitCellClasses : digitCellClasses
                    "
                >
                    {{ digit || '-' }}
                </span>
            </div>
        </div>

        <p v-if="error" class="mt-3 font-bold text-red-700">
            {{ error }}
        </p>

        <div class="mt-4 grid grid-cols-3 gap-2">
            <button
                v-for="digit in keys"
                :key="digit"
                class="keypad-button"
                type="button"
                :disabled="processing"
                @click="appendDigit(digit)"
            >
                {{ digit }}
            </button>
            <button
                class="keypad-button keypad-button-muted text-sm"
                type="button"
                :disabled="processing || enteredDigits.length === 0"
                @click="clearDigits"
            >
                Clear
            </button>
            <button
                class="keypad-button"
                type="button"
                :disabled="processing"
                @click="appendDigit('0')"
            >
                0
            </button>
            <button
                class="keypad-button keypad-button-muted text-sm"
                type="button"
                :disabled="processing || enteredDigits.length === 0"
                @click="deleteDigit"
            >
                Delete
            </button>
        </div>

        <button
            class="mt-3 min-h-14 w-full bg-blue-800 px-5 py-3 text-lg font-bold text-white disabled:opacity-50"
            type="submit"
            :disabled="processing || !canSubmit"
        >
            {{ processing ? processingLabel : submitLabel }}
        </button>
    </section>
</template>

<style scoped>
.keypad-button {
    min-height: 4rem;
    border-width: 2px;
    border-color: rgb(120 113 108);
    background: white;
    font-size: 1.625rem;
    font-weight: 800;
    color: rgb(28 25 23);
}

.keypad-button-muted {
    background: rgb(245 245 244);
    color: rgb(87 83 78);
}

.keypad-button:active:not(:disabled) {
    transform: translateY(1px);
    background: rgb(219 234 254);
    border-color: rgb(30 64 175);
}

.keypad-button:disabled {
    cursor: not-allowed;
    opacity: 0.45;
}
</style>
