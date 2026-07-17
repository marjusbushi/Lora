<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel();

const props = defineProps({
    type: {
        type: String,
        default: 'text',
    },
    placeholder: {
        type: String,
        default: null,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: null,
    },
    autofocus: {
        type: Boolean,
        default: false,
    },
});

const input = ref(null);

onMounted(() => {
    if (props.autofocus) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        ref="input"
        v-model="model"
        data-ui="text-input"
        :type="type"
        :placeholder="placeholder"
        :disabled="disabled"
        :class="[
            'block w-full rounded-md border px-3 py-2 text-body-sm text-neutral-900 placeholder:text-neutral-400 transition-colors duration-150',
            'focus:outline-none focus:ring-2 focus:ring-offset-0',
            disabled && 'bg-neutral-100 cursor-not-allowed opacity-60',
            error
                ? 'border-error-300 focus:border-error-500 focus:ring-error-500/40'
                : 'border-neutral-200 focus:border-accent-500 focus:ring-accent-500/40',
        ]"
    />
</template>
