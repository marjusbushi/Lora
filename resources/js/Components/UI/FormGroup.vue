<script setup>
defineProps({
    label: {
        type: String,
        default: null,
    },
    error: {
        type: String,
        default: null,
    },
    required: {
        type: Boolean,
        default: false,
    },
    htmlFor: {
        type: String,
        default: null,
    },
});
</script>

<template>
    <div data-ui="form-group" class="space-y-1.5">
        <label
            v-if="label"
            :for="htmlFor"
            class="block text-label text-neutral-700"
        >
            {{ label }}
            <span v-if="required" class="text-error-500">*</span>
        </label>

        <slot />

        <p v-if="error" class="text-small text-error-600">
            {{ error }}
        </p>
    </div>
</template>
