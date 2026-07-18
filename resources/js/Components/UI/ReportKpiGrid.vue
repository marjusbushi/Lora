<script setup>
import Card from '@/Components/UI/Card.vue';
import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-vue-next';

const props = defineProps({
    items: { type: Array, default: () => [] },
    columns: { type: Number, default: 4 },
});

const gridColumns = {
    3: 'xl:grid-cols-3',
    4: 'xl:grid-cols-4',
    5: 'xl:grid-cols-5',
};

function valueOf(value) {
    return typeof value === 'function' ? value() : value;
}

const toneClasses = {
    accent: { value: 'text-accent-700', icon: 'bg-accent-50 text-accent-700', edge: 'border-l-accent-500' },
    success: { value: 'text-success-700', icon: 'bg-success-50 text-success-700', edge: 'border-l-success-500' },
    warning: { value: 'text-warning-700', icon: 'bg-warning-50 text-warning-700', edge: 'border-l-warning-500' },
    error: { value: 'text-error-700', icon: 'bg-error-50 text-error-700', edge: 'border-l-error-500' },
    info: { value: 'text-info-700', icon: 'bg-info-50 text-info-700', edge: 'border-l-info-500' },
    neutral: { value: 'text-primary-900', icon: 'bg-neutral-100 text-neutral-600', edge: 'border-l-neutral-300' },
};

function tone(item) {
    const selectedTone = valueOf(item.tone) || (item.accent ? 'accent' : 'neutral');
    return toneClasses[selectedTone] || toneClasses.neutral;
}

function trendIcon(trend) {
    if (trend === 'up') return ArrowUpRight;
    if (trend === 'down') return ArrowDownRight;
    return Minus;
}
</script>

<template>
    <div :class="['grid grid-cols-1 gap-3 sm:grid-cols-2', gridColumns[props.columns] || gridColumns[4]]">
        <Card
            v-for="item in items"
            :key="item.label"
            :class="['border-l-4', tone(item).edge]"
        >
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-tiny font-semibold uppercase tracking-wider text-neutral-500">{{ item.label }}</p>
                    <p :class="['mt-2 truncate text-h3', tone(item).value]">{{ valueOf(item.value) }}</p>
                </div>
                <span v-if="item.icon" :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-lg', tone(item).icon]">
                    <component :is="item.icon" class="h-4.5 w-4.5" :stroke-width="1.75" />
                </span>
            </div>
            <div v-if="item.detail || item.trendText" class="mt-2 flex items-center gap-1.5 text-tiny text-neutral-500">
                <component v-if="item.trendText" :is="trendIcon(item.trend)" class="h-3.5 w-3.5" />
                <span>{{ valueOf(item.trendText || item.detail) }}</span>
            </div>
        </Card>
    </div>
</template>
