<script setup>
defineProps({
    padding: {
        type: Boolean,
        default: true,
    },
});
</script>

<template>
    <div data-ui="card" class="rounded-lg border border-neutral-200 bg-white shadow-card">
        <div v-if="$slots.header" data-ui="card-header" class="border-b border-neutral-200 px-5 py-4">
            <slot name="header" />
        </div>

        <div data-ui="card-body" :data-padding="padding ? 'true' : 'false'" :class="padding && 'px-5 py-4'">
            <slot />
        </div>

        <div v-if="$slots.footer" data-ui="card-footer" class="border-t border-neutral-200 px-5 py-3 bg-neutral-50">
            <slot name="footer" />
        </div>
    </div>
</template>
