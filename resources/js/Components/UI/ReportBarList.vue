<script setup>
import { translate } from '@/i18n';
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Card from '@/Components/UI/Card.vue';

const props = defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
    rows: { type: Array, default: () => [] },
    emptyText: { type: String, default: translate('admin.generated.k_c96d012aeaab') },
});

const maxValue = computed(() => Math.max(0, ...props.rows.map((row) => Number(row.value || 0))));

function width(value) {
    if (!maxValue.value) return 0;
    return Math.max(3, (Number(value || 0) / maxValue.value) * 100);
}
</script>

<template>
    <Card>
        <div class="border-b border-neutral-100 pb-3">
            <h3 class="text-body font-semibold text-primary-900">{{ title }}</h3>
            <p v-if="description" class="mt-1 text-tiny text-neutral-500">{{ description }}</p>
        </div>

        <div v-if="rows.length" class="mt-4 space-y-4">
            <component
                v-for="row in rows"
                :key="row.key || row.label"
                :is="row.href ? Link : 'div'"
                v-bind="row.href ? { href: row.href } : {}"
                :class="row.href ? 'block rounded-md p-1.5 -m-1.5 no-underline transition hover:bg-neutral-50 focus:outline-none focus:ring-2 focus:ring-accent-500' : ''"
            >
                <div class="mb-1.5 flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <p class="truncate text-body-sm font-medium text-primary-900">{{ row.label }}</p>
                        <p v-if="row.detail" class="truncate text-tiny text-neutral-500">{{ row.detail }}</p>
                    </div>
                    <p class="shrink-0 text-body-sm font-semibold text-primary-900">{{ row.display ?? row.value }}</p>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-neutral-100">
                    <div
                        class="h-full rounded-full transition-all"
                        :class="row.barClass || 'bg-accent-500'"
                        :style="{ width: `${width(row.value)}%` }"
                    />
                </div>
            </component>
        </div>
        <p v-else class="py-8 text-center text-body-sm text-neutral-500">{{ emptyText }}</p>
    </Card>
</template>
