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
import { AlertTriangle, Clock3, ReceiptText, Truck } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';
import { useReportDrilldown } from '@/composables/useReportDrilldown';
import { useReportCurrency } from '@/composables/useReportCurrency';

const props = defineProps({
    filters: Object,
    analytics: { type: Object, default: () => ({}) },
    currency: { type: String, default: '€' },
    pricingCurrency: { type: String, default: null },
    baseToPricingRate: { type: [Number, String], default: null },
});
const { can, hasModule } = useReportDrilldown();
const supplierHref = (row) => can('view_finance') && hasModule('finance') ? route('finance.suppliers', { supplier: row.id }) : null;
const itemHref = (row) => can('view_inventory') && hasModule('finance') ? route('inventory.items', { item: row.id }) : null;

const current = computed(() => props.analytics.current || {});
const summary = computed(() => current.value.summary || {});
const suppliers = computed(() => current.value.suppliers || []);
const categories = computed(() => current.value.categories || []);
const topItems = computed(() => current.value.top_items || []);
const changes = computed(() => props.analytics.changes || {});
const { baseCode, moneyBase, displayRate } = useReportCurrency(props);
const number = (value, digits = 1) => Number(value ?? 0).toLocaleString(getIntlLocale(), { maximumFractionDigits: digits });
const pctChange = (key, suffix = '%') => changes.value[key] == null ? translate('reports360.noComparison') : `${changes.value[key] > 0 ? '+' : ''}${number(changes.value[key])}${suffix}`;
const trend = (value, inverse = false) => {
    const adjusted = inverse ? -Number(value || 0) : Number(value || 0);
    return adjusted > 0 ? 'up' : adjusted < 0 ? 'down' : 'flat';
};
// Bills documented in one non-base currency: original sum under the base figure.
const foreignLine = (foreign) => (foreign
    ? Number(foreign.total ?? 0).toLocaleString(getIntlLocale(), { style: 'currency', currency: foreign.currency, minimumFractionDigits: 2, maximumFractionDigits: 2 })
    : null);
const statusLabel = (status) => translate(`reports360.supplierPerformance.status.${status}`);
const statusVariant = (status) => ({ healthy: 'success', watch: 'warning', risk: 'error' }[status] || 'neutral');

const kpis = computed(() => [
    { label: translate('reports360.supplierPerformance.spend'), help: translate('reports360.help.spSpend'), value: moneyBase(summary.value.total_spend), tone: 'accent', icon: Truck, trend: trend(changes.value.total_spend), trendText: pctChange('total_spend'), href: can('view_finance') && hasModule('finance') ? route('finance.bills') : null },
    { label: translate('reports360.supplierPerformance.avgBill'), value: moneyBase(summary.value.average_bill), tone: 'info', icon: ReceiptText, trend: trend(changes.value.average_bill), trendText: pctChange('average_bill'), detail: `${summary.value.bill_count || 0} ${translate('reports360.supplierPerformance.bills')}`, href: can('view_finance') && hasModule('finance') ? route('finance.bills') : null },
    { label: translate('reports360.supplierPerformance.onTime'), help: translate('reports360.help.spOnTime'), value: `${number(summary.value.on_time_rate)}%`, tone: 'success', icon: Clock3, trend: trend(changes.value.on_time_rate), trendText: pctChange('on_time_rate', ' pp'), href: can('view_finance') && hasModule('finance') ? route('finance.suppliers') : null },
    { label: translate('reports360.supplierPerformance.overdue'), help: translate('reports360.help.spOverdue'), value: moneyBase(summary.value.overdue_exposure), tone: summary.value.overdue_exposure ? 'warning' : 'neutral', icon: AlertTriangle, trend: trend(changes.value.overdue_exposure, true), trendText: pctChange('overdue_exposure'), detail: `${moneyBase(summary.value.outstanding)} ${translate('reports360.supplierPerformance.outstanding').toLocaleLowerCase(getIntlLocale())}`, href: can('view_finance') && hasModule('finance') ? route('finance.bills', { status: 'overdue' }) : null },
]);

const supplierBars = computed(() => suppliers.value.filter((row) => row.spend > 0).slice(0, 8).map((row) => ({
    key: row.id,
    label: row.name,
    value: Number(row.spend || 0),
    display: moneyBase(row.spend),
    detail: `${number(row.spend_share)}%`,
    href: supplierHref(row),
})));

const maxCategorySpend = computed(() => Math.max(1, ...categories.value.filter((row) => row.depth === 0).map((row) => Number(row.spend || 0))));
</script>

<template>
    <ReportShell :title="$t('reports360.supplierPerformance.title')" route-name="reports.supplierPerformance" :filters="filters" :description="$t('reports360.supplierPerformance.short')" :category="$t('reports360.supplierPerformance.category')">
        <ReportKpiGrid :items="kpis" />
        <div v-if="displayRate" class="mt-3 text-right text-tiny text-neutral-500">{{ $t('reports360.amountsShownIn', { currency: baseCode }) }}</div>

        <div class="mt-4 grid gap-4 xl:grid-cols-2">
            <ReportBarList :title="$t('reports360.supplierPerformance.bySupplier')" :rows="supplierBars" />
            <Card :padding="false">
                <div class="border-b border-neutral-200 px-5 py-4">
                    <h2 class="flex items-center gap-1 text-body font-semibold text-primary-900">{{ $t('reports360.supplierPerformance.byCategory') }}<InfoTip :text="$t('reports360.help.spCategories')" :label="$t('reports360.supplierPerformance.byCategory')" /></h2>
                </div>
                <CategoryTree :nodes="categories" :expand-label="$t('reports360.categoryTree.toggle')">
                    <template #default="{ node }">
                        <div class="flex items-center justify-between gap-3">
                            <span class="truncate text-body-sm" :class="node.id === null ? 'italic text-warning-700' : 'font-medium text-primary-900'">{{ node.category }}</span>
                            <span class="shrink-0 text-body-sm text-neutral-600">{{ node.bill_count }} · <b class="tabular-nums text-primary-900">{{ moneyBase(node.spend) }}</b> · {{ number(node.share) }}%</span>
                        </div>
                        <span v-if="node.depth === 0" class="mt-1.5 block h-1.5 overflow-hidden rounded-full bg-neutral-100">
                            <i class="block h-full rounded-full bg-info-500" :style="{ width: `${Math.max(2, Number(node.spend) / maxCategorySpend * 100)}%` }" />
                        </span>
                    </template>
                </CategoryTree>
                <div v-if="!categories.length" class="px-5 py-10 text-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
            </Card>
        </div>

        <Card class="mt-4" :padding="false">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4">
                <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.supplierPerformance.supplierDetail') }}</h2>
                <span class="text-tiny text-neutral-500">{{ suppliers.length }} {{ $t('reports360.supplierPerformance.suppliers') }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50 text-left text-label text-neutral-600">
                        <tr>
                            <th class="px-5 py-3">{{ $t('reports360.supplierPerformance.supplier') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.supplierPerformance.spend') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.supplierPerformance.outstanding') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.supplierPerformance.onTime') }}</th>
                            <th class="px-4 py-3 text-right"><span class="inline-flex items-center gap-1">{{ $t('reports360.supplierPerformance.receiptRate') }}<InfoTip :text="$t('reports360.help.spReceiptRate')" :label="$t('reports360.supplierPerformance.receiptRate')" /></span></th>
                            <th class="px-5 py-3 text-right"><span class="inline-flex items-center gap-1">{{ $t('reports360.supplierPerformance.statusLabel') }}<InfoTip :text="$t('reports360.help.spStatus')" :label="$t('reports360.supplierPerformance.statusLabel')" /></span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in suppliers" :key="row.id" class="hover:bg-neutral-50">
                            <td class="px-5 py-3">
                                <Link v-if="supplierHref(row)" :href="supplierHref(row)" class="text-body-sm font-medium text-primary-900 hover:underline">{{ row.name }}</Link><p v-else class="text-body-sm font-medium text-primary-900">{{ row.name }}</p>
                                <p class="text-tiny text-neutral-500">{{ row.category }} · {{ row.bill_count }} {{ $t('reports360.supplierPerformance.bills') }}</p>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <p class="text-body-sm font-semibold tabular-nums text-primary-900">{{ moneyBase(row.spend) }}</p>
                                <p v-if="row.foreign" class="text-tiny text-neutral-500">{{ foreignLine(row.foreign) }}</p>
                                <p class="text-tiny text-neutral-500">{{ number(row.spend_share) }}%</p>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <p class="text-body-sm tabular-nums text-neutral-700">{{ moneyBase(row.outstanding) }}</p>
                                <p v-if="row.overdue" class="text-tiny text-error-600">{{ moneyBase(row.overdue) }} {{ $t('reports360.supplierPerformance.overdueShort') }}</p>
                            </td>
                            <td class="px-4 py-3 text-right text-body-sm tabular-nums text-neutral-700">
                                {{ number(row.on_time_rate) }}%
                                <p v-if="row.average_payment_days != null" class="text-tiny text-neutral-500">{{ number(row.average_payment_days) }} {{ $t('reports360.supplierPerformance.days') }}</p>
                            </td>
                            <td class="px-4 py-3 text-right text-body-sm tabular-nums text-neutral-700">{{ number(row.receipt_rate) }}%</td>
                            <td class="px-5 py-3 text-right"><Badge :variant="statusVariant(row.status)">{{ statusLabel(row.status) }}</Badge></td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!suppliers.length" class="px-5 py-10 text-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
            </div>
        </Card>

        <Card class="mt-4" :padding="false">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4">
                <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.supplierPerformance.topItems') }}</h2>
                <span class="text-tiny text-neutral-500">{{ topItems.length }} {{ $t('reports360.supplierPerformance.items') }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50 text-left text-label text-neutral-600">
                        <tr>
                            <th class="px-5 py-3">{{ $t('reports360.supplierPerformance.item') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.supplierPerformance.quantity') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.supplierPerformance.suppliers') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.supplierPerformance.unitCost') }}</th>
                            <th class="px-5 py-3 text-right">{{ $t('reports360.supplierPerformance.spend') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in topItems" :key="row.id" class="hover:bg-neutral-50">
                            <td class="px-5 py-3"><Link v-if="itemHref(row)" :href="itemHref(row)" class="text-body-sm font-medium text-primary-900 hover:underline">{{ row.name }}</Link><p v-else class="text-body-sm font-medium text-primary-900">{{ row.name }}</p><p class="text-tiny text-neutral-500">{{ row.sku || '—' }}</p></td>
                            <td class="px-4 py-3 text-right text-body-sm tabular-nums text-neutral-700">{{ number(row.quantity, 4) }} {{ row.unit }}</td>
                            <td class="px-4 py-3 text-right text-body-sm tabular-nums text-neutral-700">{{ row.supplier_count }}</td>
                            <td class="px-4 py-3 text-right text-body-sm tabular-nums text-neutral-700">{{ moneyBase(row.average_unit_cost) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold tabular-nums text-primary-900">{{ moneyBase(row.spend) }}</td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!topItems.length" class="px-5 py-10 text-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
            </div>
        </Card>
    </ReportShell>
</template>
