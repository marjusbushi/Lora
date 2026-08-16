<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { getIntlLocale, translate } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import InfoTip from '@/Components/UI/InfoTip.vue';
import { useReportCurrency } from '@/composables/useReportCurrency';
import { CirclePercent, Gauge, RefreshCcw, Scale } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    analytics: { type: Object, default: () => ({}) },
    canViewReservations: { type: Boolean, default: false },
    canViewPos: { type: Boolean, default: false },
    currency: { type: String, default: '€' },
    pricingCurrency: { type: String, default: null },
    baseToPricingRate: { type: [Number, String], default: null },
});

const summary = computed(() => props.analytics.summary || {});
const activity = computed(() => props.analytics.activity || []);
const { money, moneyBase, showBase, displayRate, pricingCode } = useReportCurrency(props);
const fmt = (date) => date ? new Date(`${date}T00:00:00`).toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
const pct = (value) => value == null ? '—' : `${Number(value).toLocaleString(getIntlLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;

const sourceBars = computed(() => (props.analytics.discount_sources || []).map((row) => ({
    key: row.source,
    label: row.source === 'pms' ? 'PMS / Folio' : 'POS',
    value: Number(row.amount || 0),
    display: `${money(row.amount)} · ${row.count}`,
    barClass: row.source === 'pms' ? 'bg-warning-500' : 'bg-accent-500',
})));
const reasonBars = computed(() => (props.analytics.reasons || []).map((row) => ({
    key: row.reason,
    label: row.reason,
    value: Number(row.amount || 0),
    display: `${money(row.amount)} · ${row.count}`,
    barClass: 'bg-warning-500',
})));
const kpis = computed(() => [
    { label: translate('reports360.discountCashFlow.discounts'), help: translate('reports360.help.dcDiscounts'), value: money(summary.value.discounts), subvalue: showBase.value ? moneyBase(summary.value.discounts) : null, tone: summary.value.discounts ? 'warning' : 'success', icon: CirclePercent, detail: `${summary.value.discount_count || 0} ${translate('reports360.discountCashFlow.transactions')}` },
    { label: translate('reports360.discountCashFlow.refunds'), value: money(summary.value.refunds), subvalue: showBase.value ? moneyBase(summary.value.refunds) : null, tone: summary.value.refunds ? 'error' : 'success', icon: RefreshCcw, detail: `${summary.value.refund_count || 0} ${translate('reports360.discountCashFlow.transactions')}` },
    { label: translate('reports360.discountCashFlow.discountShare'), help: translate('reports360.help.dcDiscountShare'), value: pct(summary.value.discount_share), tone: (summary.value.discount_share || 0) >= 5 ? 'warning' : 'neutral', icon: Gauge, detail: `${translate('reports360.discountCashFlow.revenueNetDetail')}: ${money(summary.value.revenue_net)}` },
    { label: translate('reports360.discountCashFlow.refundShare'), help: translate('reports360.help.dcRefundShare'), value: pct(summary.value.refund_share), tone: (summary.value.refund_share || 0) >= 5 ? 'warning' : 'neutral', icon: Scale, detail: `${translate('reports360.discountCashFlow.collectionsDetail')}: ${money(summary.value.collections)}` },
]);
const href = (row) => {
    if (row.link_kind === 'reservation' && props.canViewReservations) return route('reservations.show', row.link_id);
    if (row.link_kind === 'pos' && props.canViewPos) return route('pos.index', { order_id: row.link_id });
    return null;
};
</script>

<template>
    <ReportShell
        :title="$t('reports360.discountCashFlow.title')"
        route-name="reports.discounts"
        :filters="filters"
        :description="$t('reports360.discountCashFlow.short')"
        :category="$t('reports360.discountCashFlow.category')"
    >
        <ReportKpiGrid :items="kpis" />
        <div v-if="displayRate" class="mt-3 text-right text-tiny text-neutral-500">{{ $t('reports360.amountsShownIn', { currency: pricingCode }) }}</div>

        <div class="mt-4 grid gap-4 xl:grid-cols-2">
            <ReportBarList :title="$t('reports360.discountCashFlow.discountSources')" :rows="sourceBars" />
            <ReportBarList :title="$t('reports360.discountCashFlow.topReasons')" :rows="reasonBars" />
        </div>

        <div class="mt-4">
            <Card :padding="false">
                <div class="border-b border-neutral-200 px-5 py-4">
                    <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.discountCashFlow.activity') }}</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200">
                        <thead class="bg-neutral-50 text-left text-label text-neutral-600"><tr>
                            <th class="px-5 py-3">{{ $t('reports360.discountCashFlow.type') }}</th>
                            <th class="px-4 py-3">{{ $t('reports360.discountCashFlow.reference') }}</th>
                            <th class="px-4 py-3">{{ $t('reports360.discountCashFlow.reason') }}</th>
                            <th class="px-4 py-3">{{ $t('reports360.discountCashFlow.date') }}</th>
                            <th class="px-5 py-3 text-right">{{ $t('reports360.discountCashFlow.amount') }}</th>
                        </tr></thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="row in activity" :key="row.key" class="hover:bg-neutral-50">
                                <td class="px-5 py-3"><Badge :variant="row.kind === 'refund' ? 'error' : 'warning'">{{ $t(`reports360.discountCashFlow.${row.kind}`) }}</Badge></td>
                                <td class="px-4 py-3 text-body-sm font-medium text-primary-900"><Link v-if="href(row)" :href="href(row)" class="hover:underline">{{ row.reference }}</Link><span v-else>{{ row.reference }}</span></td>
                                <td class="max-w-xs truncate px-4 py-3 text-body-sm text-neutral-700">{{ row.reason }}</td>
                                <td class="px-4 py-3 text-body-sm text-neutral-600">{{ fmt(row.date) }}</td>
                                <td class="px-5 py-3 text-right text-body-sm font-semibold text-error-700">−{{ money(row.amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="!activity.length" class="px-6 py-12 text-center text-body-sm text-neutral-500">{{ $t('reports360.noData') }}</div>
            </Card>
        </div>
    </ReportShell>
</template>
