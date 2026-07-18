<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { getIntlLocale, translate } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import { ArrowDownToLine, ArrowUpFromLine, CirclePercent, RefreshCcw } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    analytics: { type: Object, default: () => ({}) },
    canViewReservations: { type: Boolean, default: false },
    canViewPos: { type: Boolean, default: false },
    currency: { type: String, default: '€' },
});

const summary = computed(() => props.analytics.summary || {});
const daily = computed(() => props.analytics.daily || []);
const activity = computed(() => props.analytics.activity || []);
const maxFlow = computed(() => Math.max(1, ...daily.value.flatMap((day) => [Number(day.inflow || 0), Number(day.outflow || 0)])));
const money = (value) => `${props.currency}${Number(value ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const fmt = (date) => date ? new Date(`${date}T00:00:00`).toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

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
    { label: translate('reports360.discountCashFlow.discounts'), value: money(summary.value.discounts), tone: 'warning', icon: CirclePercent, detail: `${summary.value.discount_count || 0} ${translate('reports360.discountCashFlow.transactions')}` },
    { label: translate('reports360.discountCashFlow.refunds'), value: money(summary.value.refunds), tone: 'error', icon: RefreshCcw, detail: `${summary.value.refund_count || 0} ${translate('reports360.discountCashFlow.transactions')}` },
    { label: translate('reports360.discountCashFlow.inflow'), value: money(summary.value.inflow), tone: 'success', icon: ArrowDownToLine },
    { label: translate('reports360.discountCashFlow.net'), value: money(summary.value.net_cash_flow), tone: Number(summary.value.net_cash_flow || 0) >= 0 ? 'accent' : 'error', icon: ArrowUpFromLine, detail: `${translate('reports360.discountCashFlow.outflow')}: ${money(summary.value.outflow)}` },
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

        <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.35fr)_minmax(300px,0.65fr)]">
            <Card :padding="false">
                <div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4">
                    <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.discountCashFlow.cashFlow') }}</h2>
                    <span class="text-tiny text-neutral-500">{{ $t('reports360.discountCashFlow.ledgerOnly') }}</span>
                </div>
                <div class="h-56 px-5 pb-4 pt-5">
                    <div v-if="daily.length" class="flex h-full items-end gap-1.5 border-b border-neutral-200">
                        <div v-for="day in daily" :key="day.date" class="group flex h-full min-w-0 flex-1 items-end justify-center gap-px" :title="`${fmt(day.date)} · ${money(day.net)}`">
                            <span class="w-2/5 rounded-t bg-success-500" :style="{ height: `${day.inflow ? Math.max(2, Number(day.inflow) / maxFlow * 100) : 0}%` }" />
                            <span class="w-2/5 rounded-t bg-error-400" :style="{ height: `${day.outflow ? Math.max(2, Number(day.outflow) / maxFlow * 100) : 0}%` }" />
                        </div>
                    </div>
                    <div v-else class="flex h-full items-center justify-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
                </div>
                <div class="flex justify-end gap-4 border-t border-neutral-200 px-5 py-3 text-tiny text-neutral-600">
                    <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-success-500" />{{ $t('reports360.discountCashFlow.inflow') }}</span>
                    <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-error-400" />{{ $t('reports360.discountCashFlow.outflow') }}</span>
                </div>
            </Card>
            <ReportBarList :title="$t('reports360.discountCashFlow.discountSources')" :rows="sourceBars" />
        </div>

        <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(280px,0.6fr)_minmax(0,1.4fr)]">
            <ReportBarList :title="$t('reports360.discountCashFlow.topReasons')" :rows="reasonBars" />
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
