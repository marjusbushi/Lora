<script setup>
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, required: true },
});
</script>

<template>
    <header class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="sa-page-title !mt-0">{{ title }}</h1>
            <p class="sa-page-subtitle">{{ subtitle }}</p>
        </div>
        <div v-if="$slots.actions" class="sa-actions flex flex-wrap gap-2">
            <slot name="actions" />
        </div>
    </header>
</template>
