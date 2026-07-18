<script setup>
import { computed } from 'vue';
import { getIntlLocale, translate } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import { AlertTriangle, ArrowDownToLine, PackageCheck, Warehouse } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    analytics: { type: Object, default: () => ({}) },
    currency: { type: String, default: '€' },
});

const current = computed(() => props.analytics.current || {});
const summary = computed(() => current.value.summary || {});
const items = computed(() => current.value.items || []);
const warehouses = computed(() => current.value.warehouses || []);
const topConsumption = computed(() => current.value.top_consumption || []);
const changes = computed(() => props.analytics.changes || {});
const money = (value) => `${props.currency}${Number(value ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const number = (value, digits = 2) => Number(value ?? 0).toLocaleString(getIntlLocale(), { maximumFractionDigits: digits });
const pctChange = (key) => changes.value[key] == null ? translate('reports360.noComparison') : `${changes.value[key] > 0 ? '+' : ''}${number(changes.value[key], 1)}%`;
const trend = (value) => value > 0 ? 'up' : value < 0 ? 'down' : 'flat';
const statusLabel = (status) => translate(`reports360.stockValuation.status.${status}`);
const statusVariant = (status) => ({ healthy: 'success', low: 'warning', out: 'error', negative: 'error' }[status] || 'neutral');

const kpis = computed(() => [
    { label: translate('reports360.stockValuation.stockValue'), value: money(summary.value.stock_value), tone: 'accent', icon: Warehouse, trend: trend(changes.value.stock_value), trendText: pctChange('stock_value') },
    { label: translate('reports360.stockValuation.consumedValue'), value: money(summary.value.consumed_value), tone: 'info', icon: ArrowDownToLine, trend: trend(changes.value.consumed_value), trendText: pctChange('consumed_value') },
    { label: translate('reports360.stockValuation.receivedValue'), value: money(summary.value.received_value), tone: 'success', icon: PackageCheck, trend: trend(changes.value.received_value), trendText: pctChange('received_value') },
    { label: translate('reports360.stockValuation.atRisk'), value: summary.value.at_risk_count || 0, tone: summary.value.at_risk_count ? 'warning' : 'neutral', icon: AlertTriangle, detail: `${summary.value.negative_stock_count || 0} ${translate('reports360.stockValuation.negative')}` },
]);

const warehouseBars = computed(() => warehouses.value.map((row) => ({
    key: row.id,
    label: row.name,
    value: Number(row.stock_value || 0),
    display: money(row.stock_value),
    detail: `${row.item_count} ${translate('reports360.stockValuation.items')}`,
})));

const consumptionBars = computed(() => topConsumption.value.map((row) => ({
    key: row.id,
    label: row.name,
    value: Number(row.consumed_value || 0),
    display: money(row.consumed_value),
    detail: `${number(row.consumed_quantity, 4)} ${row.unit}`,
    barClass: 'bg-info-500',
})));
</script>

<template>
    <ReportShell :title="$t('reports360.stockValuation.title')" route-name="reports.stockValuation" :filters="filters" :description="$t('reports360.stockValuation.short')" :category="$t('reports360.stockValuation.category')">
        <ReportKpiGrid :items="kpis" />

        <div class="mt-4 grid gap-4 xl:grid-cols-2">
            <ReportBarList :title="$t('reports360.stockValuation.byWarehouse')" :rows="warehouseBars" />
            <ReportBarList :title="$t('reports360.stockValuation.topConsumption')" :rows="consumptionBars" />
        </div>

        <Card class="mt-4" :padding="false">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4">
                <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.stockValuation.itemDetail') }}</h2>
                <span class="text-tiny text-neutral-500">{{ items.length }} {{ $t('reports360.stockValuation.items') }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50 text-left text-label text-neutral-600">
                        <tr>
                            <th class="px-5 py-3">{{ $t('reports360.stockValuation.item') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.stockValuation.opening') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.stockValuation.received') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.stockValuation.consumed') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.stockValuation.ending') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.stockValuation.unitCost') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.stockValuation.value') }}</th>
                            <th class="px-5 py-3 text-right">{{ $t('reports360.stockValuation.cover') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in items" :key="row.id" class="hover:bg-neutral-50">
                            <td class="px-5 py-3">
                                <p class="text-body-sm font-medium text-primary-900">{{ row.name }}</p>
                                <p class="text-tiny text-neutral-500">{{ row.sku }} · {{ row.category }}</p>
                            </td>
                            <td class="px-4 py-3 text-right text-body-sm tabular-nums text-neutral-600">{{ number(row.opening_quantity, 4) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm tabular-nums text-success-700">{{ number(row.received_quantity, 4) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm tabular-nums text-info-700">{{ number(row.consumed_quantity, 4) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold tabular-nums text-primary-900">{{ number(row.ending_quantity, 4) }} {{ row.unit }}</td>
                            <td class="px-4 py-3 text-right text-body-sm tabular-nums text-neutral-600">{{ money(row.unit_cost) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold tabular-nums text-primary-900">{{ money(row.ending_value) }}</td>
                            <td class="px-5 py-3 text-right">
                                <Badge :variant="statusVariant(row.status)">{{ statusLabel(row.status) }}</Badge>
                                <p v-if="row.days_cover != null" class="mt-1 text-tiny text-neutral-500">{{ number(row.days_cover, 1) }} {{ $t('reports360.stockValuation.days') }}</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!items.length" class="px-5 py-10 text-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
            </div>
        </Card>
    </ReportShell>
</template>
