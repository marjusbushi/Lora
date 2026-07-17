<script setup>
const model = defineModel();

defineProps({
    options: {
        type: Array,
        required: true,
        // Each option: { value: string|number, label: string }
    },
    placeholder: {
        type: String,
        default: 'Zgjidh...',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: null,
    },
});
</script>

<template>
    <select
        v-model="model"
        data-ui="select"
        :disabled="disabled"
        :class="[
            'block w-full rounded-md border px-3 py-2 text-body-sm text-neutral-900 transition-colors duration-150',
            'focus:outline-none focus:ring-2 focus:ring-offset-0',
            disabled && 'bg-neutral-100 cursor-not-allowed opacity-60',
            error
                ? 'border-error-300 focus:border-error-500 focus:ring-error-500/40'
                : 'border-neutral-200 focus:border-accent-500 focus:ring-accent-500/40',
        ]"
    >
        <option v-if="placeholder" value="" disabled>
            {{ placeholder }}
        </option>
        <option
            v-for="option in options"
            :key="option.value"
            :value="option.value"
        >
            {{ option.label }}
        </option>
    </select>
</template>
