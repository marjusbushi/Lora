<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

// Pill switcher between the two pricing surfaces: the smart calendar (daily work)
// and the base seasons/rates (rare configuration). When the smart_pricing module
// is not active, the calendar pill renders locked and leads to activation.
const props = defineProps({
    active: { type: String, required: true }, // 'calendar' | 'seasons'
    smartPriceCents: { type: Number, default: 0 },
});

const smartEnabled = computed(() => usePage().props.modules?.smart_pricing === true);

const priceLabel = computed(() => {
    const cents = Number(props.smartPriceCents);
    if (!Number.isFinite(cents) || cents <= 0) return '';
    const euros = cents / 100;
    return Number.isInteger(euros) ? String(euros) : euros.toFixed(2);
});

const baseClass = 'inline-flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-body-sm font-semibold no-underline transition';
const activeClass = 'bg-white text-primary-900 shadow-sm';
const idleClass = 'text-neutral-500 hover:text-primary-900';
</script>

<template>
    <nav class="inline-flex items-center gap-0.5 rounded-xl border border-neutral-200 bg-neutral-100 p-1" :aria-label="$t('pricingTabs.ariaLabel')">
        <Link
            v-if="smartEnabled"
            :href="route('pricing.smart.index')"
            :class="[baseClass, active === 'calendar' ? activeClass : idleClass]"
            :aria-current="active === 'calendar' ? 'page' : undefined"
        >
            <span aria-hidden="true">✨</span>{{ $t('pricingTabs.calendar') }}
        </Link>
        <span
            v-else
            :class="[baseClass, 'cursor-help text-neutral-400']"
            :title="priceLabel ? $t('pricingTabs.lockedTitle', { price: priceLabel }) : $t('pricingTabs.lockedTitleNoPrice')"
        >
            <span aria-hidden="true">🔒</span>{{ $t('pricingTabs.calendar') }}
            <span v-if="priceLabel" class="rounded-full border border-neutral-200 bg-white px-2 py-0.5 text-tiny font-bold text-primary-900">
                {{ $t('pricingTabs.lockedPrice', { price: priceLabel }) }}
            </span>
        </span>
        <Link
            :href="route('pricing.index')"
            :class="[baseClass, active === 'seasons' ? activeClass : idleClass]"
            :aria-current="active === 'seasons' ? 'page' : undefined"
        >
            <span aria-hidden="true">⚙️</span>{{ $t('pricingTabs.seasons') }}
        </Link>
    </nav>
</template>
