<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    title: {
        type: String,
        required: true,
    },
    breadcrumbs: {
        type: Array,
        default: () => [],
        // Each: { label: string, href?: string }
    },
});
</script>

<template>
    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <!-- Breadcrumbs -->
            <nav v-if="breadcrumbs.length > 0" class="flex items-center gap-1.5 text-small text-neutral-500 mb-1">
                <template v-for="(crumb, i) in breadcrumbs" :key="i">
                    <span v-if="i > 0" class="text-neutral-300">/</span>
                    <Link
                        v-if="crumb.href"
                        :href="crumb.href"
                        class="hover:text-neutral-700 no-underline text-neutral-500"
                    >
                        {{ crumb.label }}
                    </Link>
                    <span v-else class="text-neutral-400">{{ crumb.label }}</span>
                </template>
            </nav>

            <h1 class="text-h3 text-neutral-900">{{ title }}</h1>
        </div>

        <!-- Actions slot -->
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2 mt-2 sm:mt-0 sm:justify-end">
            <slot name="actions" />
        </div>
    </div>
</template>
