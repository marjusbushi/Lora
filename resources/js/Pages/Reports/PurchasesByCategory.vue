<script setup>
import { computed } from 'vue';
import { getIntlLocale, translate } from '@/i18n';
import { Link } from '@inertiajs/vue3';
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import CategoryTree from '@/Components/UI/CategoryTree.vue';
import InfoTip from '@/Components/UI/InfoTip.vue';
import { CircleAlert, Layers, ReceiptText, ShoppingCart } from 'lucide-vue-next';
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
const billsHref = () => can('view_finance') && hasModule('finance') ? route('finance.bills') : null;

const current = computed(() => props.analytics.current || {});
const summary = computed(() => current.value.summary || {});
const categories = computed(() => current.value.categories || []);
const topItems = computed(() => current.value.top_items || []);
const changes = computed(() => props.analytics.changes || {});

const { money, moneyBase, showBase, displayRate, pricingCode } = useReportCurrency(props);
const number = (value, digits = 1) => Number(value ?? 0).toLocaleString(getIntlLocale(), { maximumFractionDigits: digits });
const pctChange = (value) => value == null ? translate('reports360.noComparison') : `${value > 0 ? '+' : ''}${number(value)}%`;
const trend = (value) => Number(value || 0) > 0 ? 'up' : Number(value || 0) < 0 ? 'down' : 'flat';
const t9 = (key) => translate(`reports360.purchasesByCategory.${key}`);

const kpis = computed(() => [
    { label: t9('totalSpend'), help: translate('reports360.help.pcTotal'), value: money(summary.value.total_spend), subvalue: showBase.value ? moneyBase(summary.value.total_spend) : null, tone: 'accent', icon: ShoppingCart, trend: trend(changes.value.total_spend), trendText: pctChange(changes.value.total_spend), href: billsHref() },
    { label: t9('bills'), value: summary.value.bill_count || 0, tone: 'info', icon: ReceiptText, trend: trend(changes.value.bill_count), trendText: pctChange(changes.value.bill_count), detail: `${summary.value.line_count || 0} ${t9('lines')}`, href: billsHref() },
    { label: t9('categoriesTouched'), help: translate('reports360.help.pcCategories'), value: categories.value.filter((node) => node.id !== null).length, tone: 'neutral', icon: Layers },
    { label: t9('uncategorized'), help: translate('reports360.help.pcUncategorized'), value: money(summary.value.uncategorized_spend), subvalue: showBase.value ? moneyBase(summary.value.uncategorized_spend) : null, tone: Number(summary.value.uncategorized_spend || 0) > 0 ? 'warning' : 'success', icon: CircleAlert },
]);

const maxRootSpend = computed(() => Math.max(1, ...categories.value.filter((node) => node.depth === 0).map((node) => Number(node.spend || 0))));
</script>

<template>
    <ReportShell :title="t9('title')" route-name="reports.purchasesByCategory" :filters="filters" :description="t9('short')" :category="t9('category')">
        <ReportKpiGrid :items="kpis" />
        <div v-if="displayRate" class="mt-3 text-right text-tiny text-neutral-500">{{ $t('reports360.amountsShownIn', { currency: pricingCode }) }}</div>

        <Card class="mt-4" :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4">
                <h2 class="flex items-center gap-1 text-body font-semibold text-primary-900">{{ t9('treeTitle') }}<InfoTip :text="$t('reports360.help.pcTree')" :label="t9('treeTitle')" /></h2>
                <p class="mt-0.5 text-tiny text-neutral-500">{{ t9('treeHint') }}</p>
            </div>
            <CategoryTree :nodes="categories" :expand-label="$t('reports360.categoryTree.toggle')">
                <template #default="{ node, isExpanded }">
                    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-0.5">
                        <span class="truncate text-body-sm" :class="node.id === null ? 'italic text-warning-700' : 'font-medium text-primary-900'">{{ node.category }}</span>
                        <span class="shrink-0 text-body-sm text-neutral-600">
                            <b class="tabular-nums text-primary-900">{{ money(node.spend) }}</b>
                            · {{ number(node.share) }}%
                            <span :class="Number(node.change || 0) > 0 ? 'text-error-700' : 'text-success-700'">{{ pctChange(node.change) }}</span>
                        </span>
                    </div>
                    <span v-if="node.depth === 0" class="mt-1.5 block h-1.5 overflow-hidden rounded-full bg-neutral-100">
                        <i class="block h-full rounded-full bg-accent-500" :style="{ width: `${Math.max(2, Number(node.spend) / maxRootSpend * 100)}%` }" />
                    </span>
                    <ul v-if="isExpanded && node.top_items.length" class="mt-2 space-y-1">
                        <li v-for="item in node.top_items" :key="item.id ?? item.name" class="flex items-center justify-between gap-3 text-tiny text-neutral-600">
                            <span class="truncate">{{ item.name }}</span>
                            <span class="shrink-0 tabular-nums">{{ number(item.quantity, 4) }} {{ item.unit || '' }} · {{ money(item.spend) }}</span>
                        </li>
                        <li v-if="node.top_items_truncated" class="text-tiny text-neutral-400">+{{ node.top_items_truncated }} {{ t9('moreItems') }}</li>
                    </ul>
                </template>
            </CategoryTree>
            <div v-if="!categories.length" class="px-6 py-12 text-center">
                <p class="text-body-sm font-medium text-neutral-700">{{ t9('emptyTitle') }}</p>
                <p class="mx-auto mt-1 max-w-md text-tiny text-neutral-500">{{ t9('emptyHint') }}</p>
                <Link v-if="billsHref()" :href="billsHref()" class="mt-3 inline-flex items-center gap-1 text-body-sm font-semibold text-accent-700 hover:underline">{{ t9('emptyCta') }} →</Link>
            </div>
        </Card>

        <Card class="mt-4" :padding="false">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4">
                <h2 class="text-body font-semibold text-primary-900">{{ t9('topItems') }}</h2>
                <span class="text-tiny text-neutral-500">{{ topItems.length }} {{ t9('items') }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50 text-left text-label text-neutral-600">
                        <tr>
                            <th class="px-5 py-3">{{ t9('item') }}</th>
                            <th class="px-4 py-3 text-right">{{ t9('quantity') }}</th>
                            <th class="px-5 py-3 text-right">{{ t9('spend') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in topItems" :key="row.id ?? row.name" class="hover:bg-neutral-50">
                            <td class="px-5 py-3"><p class="text-body-sm font-medium text-primary-900">{{ row.name }}</p><p class="text-tiny text-neutral-500">{{ row.sku || '—' }}</p></td>
                            <td class="px-4 py-3 text-right text-body-sm tabular-nums text-neutral-700">{{ number(row.quantity, 4) }} {{ row.unit || '' }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold tabular-nums text-primary-900">{{ money(row.spend) }}</td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!topItems.length" class="px-5 py-10 text-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
            </div>
        </Card>
    </ReportShell>
</template>
