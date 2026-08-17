<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import WebsiteLayout from '@/Layouts/WebsiteLayout.vue';
import { CheckCircle2, RotateCw } from 'lucide-vue-next';
import { translate } from '@/i18n';

const props = defineProps({
    order: { type: Object, required: true },
});

const money = (value) => `${props.order.currency}${Number(value).toFixed(2)}`;

const statusMeta = computed(() => ({
    open: { label: translate('beach.order.statusOpen'), classes: 'bg-amber-50 text-amber-800' },
    completed: { label: translate('beach.order.statusCompleted'), classes: 'bg-emerald-50 text-emerald-700' },
    cancelled: { label: translate('beach.order.statusCancelled'), classes: 'bg-red-50 text-red-700' },
    refunded: { label: translate('beach.order.statusCancelled'), classes: 'bg-red-50 text-red-700' },
}[props.order.status] || { label: props.order.status, classes: 'bg-bone text-driftwood' }));

function refresh() {
    router.reload({ only: ['order'] });
}
</script>

<template>
    <WebsiteLayout>
        <section class="bg-bone min-h-screen py-10">
            <div class="mx-auto max-w-lg px-4 sm:px-6">
                <div class="rounded-2xl border border-driftwood/15 bg-white p-6 text-center">
                    <CheckCircle2 class="mx-auto h-12 w-12 text-ionian" />
                    <h1 class="mt-3 text-2xl font-semibold text-ink">{{ $t('beach.order.confirmedTitle') }}</h1>
                    <p class="mt-1 text-body-sm text-driftwood">
                        {{ $t('beach.order.confirmedBody', { number: order.unit_number ?? '—' }) }}
                    </p>
                    <span class="mt-3 inline-flex items-center rounded-full px-3 py-1 text-body-sm font-semibold" :class="statusMeta.classes">
                        {{ statusMeta.label }}
                    </span>

                    <div class="mt-5 divide-y divide-driftwood/10 rounded-xl border border-driftwood/10 text-left">
                        <div v-for="(item, index) in order.items" :key="index" class="flex items-center justify-between px-4 py-2.5 text-body-sm">
                            <span class="text-ink">{{ item.quantity }}× {{ item.name }}</span>
                            <span class="tabular-nums text-driftwood">{{ money(item.total_price) }}</span>
                        </div>
                        <div class="flex items-center justify-between px-4 py-3 text-body-sm font-semibold">
                            <span class="text-ink">{{ $t('beach.order.totalLabel') }}</span>
                            <span class="tabular-nums text-ink">{{ money(order.total_amount) }}</span>
                        </div>
                    </div>

                    <p class="mt-4 text-body-sm text-driftwood">{{ $t('beach.order.payOnDelivery') }}</p>

                    <button type="button" class="mt-5 inline-flex h-11 items-center gap-2 rounded-full border border-driftwood/25 px-5 text-body-sm font-medium text-ink active:bg-bone" @click="refresh">
                        <RotateCw class="h-4 w-4" /> {{ $t('beach.order.refresh') }}
                    </button>
                </div>
            </div>
        </section>
    </WebsiteLayout>
</template>
