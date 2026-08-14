<script setup>
import { computed } from 'vue';
import { getIntlLocale, translate } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import CategoryTree from '@/Components/UI/CategoryTree.vue';
import InfoTip from '@/Components/UI/InfoTip.vue';
import { AlertTriangle, ArrowDownToLine, PackageCheck, Warehouse } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';
import { useReportDrilldown } from '@/composables/useReportDrilldown';
import { useReportCurrency } from '@/composables/useReportCurrency';
import { ref } from 'vue';

const props = defineProps({
    filters: Object,
    analytics: { type: Object, default: () => ({}) },
    currency: { type: String, default: '€' },
    pricingCurrency: { type: String, default: null },
    baseToPricingRate: { type: [Number, String], default: null },
});
const { can, hasModule } = useReportDrilldown();
const canOpenInventory = () => can('view_inventory') && hasModule('finance');
const itemHref = (row) => canOpenInventory() ? route('inventory.items', { item: row.id }) : null;
const warehouseHref = (row) => canOpenInventory() ? route('inventory.warehouses', { warehouse: row.id }) : null;

const current = computed(() => props.analytics.current || {});
const summary = computed(() => current.value.summary || {});
const items = computed(() => current.value.items || []);
const warehouses = computed(() => current.value.warehouses || []);
const topConsumption = computed(() => current.value.top_consumption || []);
const changes = computed(() => props.analytics.changes || {});
const { money, moneyBase, showBase, displayRate, pricingCode } = useReportCurrency(props);
const categories = computed(() => current.value.categories || []);
const categoryFilter = ref('all');
const filteredItems = computed(() => categoryFilter.value === 'all'
    ? items.value
    : items.value.filter((row) => String(row.category_id ?? 'none') === categoryFilter.value));
const categoryOptions = computed(() => {
    const seen = new Map();
    for (const row of items.value) {
        const key = String(row.category_id ?? 'none');
        if (!seen.has(key)) seen.set(key, row.category);
    }
    return [...seen.entries()].map(([value, label]) => ({ value, label }));
});
const number = (value, digits = 2) => Number(value ?? 0).toLocaleString(getIntlLocale(), { maximumFractionDigits: digits });
const pctChange = (key) => changes.value[key] == null ? translate('reports360.noComparison') : `${changes.value[key] > 0 ? '+' : ''}${number(changes.value[key], 1)}%`;
const trend = (value) => value > 0 ? 'up' : value < 0 ? 'down' : 'flat';
const statusLabel = (status) => translate(`reports360.stockValuation.status.${status}`);
const statusVariant = (status) => ({ healthy: 'success', low: 'warning', out: 'error', negative: 'error' }[status] || 'neutral');

const kpis = computed(() => [
    { label: translate('reports360.stockValuation.stockValue'), help: translate('reports360.help.svStockValue'), value: money(summary.value.stock_value), subvalue: showBase.value ? moneyBase(summary.value.stock_value) : null, tone: 'accent', icon: Warehouse, trend: trend(changes.value.stock_value), trendText: pctChange('stock_value'), href: canOpenInventory() ? route('inventory.index') : null },
    { label: translate('reports360.stockValuation.consumedValue'), help: translate('reports360.help.svConsumed'), value: money(summary.value.consumed_value), subvalue: showBase.value ? moneyBase(summary.value.consumed_value) : null, tone: 'info', icon: ArrowDownToLine, trend: trend(changes.value.consumed_value), trendText: pctChange('consumed_value'), href: canOpenInventory() ? route('inventory.items') : null },
    { label: translate('reports360.stockValuation.receivedValue'), value: money(summary.value.received_value), subvalue: showBase.value ? moneyBase(summary.value.received_value) : null, tone: 'success', icon: PackageCheck, trend: trend(changes.value.received_value), trendText: pctChange('received_value'), href: canOpenInventory() ? route('inventory.items') : null },
    { label: translate('reports360.stockValuation.atRisk'), help: translate('reports360.help.svAtRisk'), value: summary.value.at_risk_count || 0, tone: summary.value.at_risk_count ? 'warning' : 'neutral', icon: AlertTriangle, detail: `${summary.value.negative_stock_count || 0} ${translate('reports360.stockValuation.negative')}`, href: canOpenInventory() ? route('inventory.items', { status: 'low' }) : null },
]);

const warehouseBars = computed(() => warehouses.value.map((row) => ({
    key: row.id,
    label: row.name,
    value: Number(row.stock_value || 0),
    display: money(row.stock_value),
    detail: `${row.item_count} ${translate('reports360.stockValuation.items')}`,
    href: warehouseHref(row),
})));

const consumptionBars = computed(() => topConsumption.value.map((row) => ({
    key: row.id,
    label: row.name,
    value: Number(row.consumed_value || 0),
    display: money(row.consumed_value),
    detail: `${number(row.consumed_quantity, 4)} ${row.unit}`,
    barClass: 'bg-info-500',
    href: itemHref(row),
})));
</script>

<template>
    <ReportShell :title="$t('reports360.stockValuation.title')" route-name="reports.stockValuation" :filters="filters" :description="$t('reports360.stockValuation.short')" :category="$t('reports360.stockValuation.category')">
        <ReportKpiGrid :items="kpis" />
        <div v-if="displayRate" class="mt-3 text-right text-tiny text-neutral-500">{{ $t('reports360.amountsShownIn', { currency: pricingCode }) }}</div>

        <div class="mt-4 grid gap-4 xl:grid-cols-2">
            <ReportBarList :title="$t('reports360.stockValuation.byWarehouse')" :rows="warehouseBars" />
            <ReportBarList :title="$t('reports360.stockValuation.topConsumption')" :rows="consumptionBars" />
        </div>

        <Card class="mt-4" :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4">
                <h2 class="flex items-center gap-1 text-body font-semibold text-primary-900">{{ $t('reports360.stockValuation.byCategory') }}<InfoTip :text="$t('reports360.help.svCategories')" :label="$t('reports360.stockValuation.byCategory')" /></h2>
            </div>
            <div class="hidden gap-3 border-b border-neutral-100 bg-neutral-50 px-5 py-2 text-label text-neutral-600 sm:grid sm:grid-cols-[minmax(0,1fr)_repeat(3,7rem)_4rem]">
                <span>{{ $t('reports360.stockValuation.categoryColumn') }}</span>
                <span class="text-right">{{ $t('reports360.stockValuation.stockValue') }}</span>
                <span class="text-right">{{ $t('reports360.stockValuation.receivedValue') }}</span>
                <span class="text-right">{{ $t('reports360.stockValuation.consumedValue') }}</span>
                <span class="text-right">{{ $t('reports360.stockValuation.items') }}</span>
            </div>
            <CategoryTree :nodes="categories" :expand-label="$t('reports360.categoryTree.toggle')">
                <template #default="{ node }">
                    <div class="grid grid-cols-2 items-center gap-x-3 gap-y-0.5 sm:grid-cols-[minmax(0,1fr)_repeat(3,7rem)_4rem]">
                        <span class="col-span-2 truncate text-body-sm sm:col-span-1" :class="node.id === null ? 'italic text-warning-700' : 'font-medium text-primary-900'">{{ node.category }}</span>
                        <span class="text-right text-body-sm font-semibold tabular-nums text-primary-900">{{ money(node.stock_value) }}</span>
                        <span class="text-right text-body-sm tabular-nums text-success-700">{{ money(node.received_value) }}</span>
                        <span class="text-right text-body-sm tabular-nums text-neutral-700">{{ money(node.consumed_value) }}</span>
                        <span class="text-right text-tiny text-neutral-500">{{ node.item_count }}</span>
                    </div>
                </template>
            </CategoryTree>
            <div v-if="!categories.length" class="px-5 py-10 text-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
        </Card>

        <Card class="mt-4" :padding="false">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4">
                <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.stockValuation.itemDetail') }}</h2>
                <div class="flex items-center gap-3">
                    <label class="flex items-center gap-2 text-tiny text-neutral-600">
                        {{ $t('reports360.stockValuation.categoryColumn') }}
                        <select v-model="categoryFilter" class="h-9 rounded-lg border-neutral-300 px-2 text-body-sm focus:border-accent-500 focus:ring-accent-500">
                            <option value="all">{{ $t('reports360.stockValuation.allCategories') }}</option>
                            <option v-for="option in categoryOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                    </label>
                    <span class="text-tiny text-neutral-500">{{ filteredItems.length }} {{ $t('reports360.stockValuation.items') }}</span>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50 text-left text-label text-neutral-600">
                        <tr>
                            <th class="px-5 py-3">{{ $t('reports360.stockValuation.item') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.stockValuation.opening') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.stockValuation.received') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.stockValuation.consumed') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.stockValuation.writtenOff') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.stockValuation.ending') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.stockValuation.unitCost') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.stockValuation.value') }}</th>
                            <th class="px-5 py-3 text-right"><span class="inline-flex items-center gap-1">{{ $t('reports360.stockValuation.cover') }}<InfoTip :text="$t('reports360.help.svCover')" :label="$t('reports360.stockValuation.cover')" /></span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in filteredItems" :key="row.id" class="hover:bg-neutral-50">
                            <td class="px-5 py-3">
                                <Link v-if="itemHref(row)" :href="itemHref(row)" class="text-body-sm font-medium text-primary-900 hover:underline">{{ row.name }}</Link><p v-else class="text-body-sm font-medium text-primary-900">{{ row.name }}</p>
                                <p class="text-tiny text-neutral-500">{{ row.sku }} · {{ row.category }}</p>
                            </td>
                            <td class="px-4 py-3 text-right text-body-sm tabular-nums text-neutral-600">{{ number(row.opening_quantity, 4) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm tabular-nums text-success-700">{{ number(row.received_quantity, 4) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm tabular-nums text-info-700">{{ number(row.consumed_quantity, 4) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm tabular-nums" :class="row.written_off_quantity > 0 ? 'text-warning-700' : 'text-neutral-400'">{{ number(row.written_off_quantity, 4) }}</td>
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
                <div v-if="!filteredItems.length" class="px-5 py-10 text-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
            </div>
        </Card>
    </ReportShell>
</template>
