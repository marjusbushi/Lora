<script setup>
import { getIntlLocale, translate } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import { computed } from 'vue';
import { Banknote, ChartNoAxesCombined, ReceiptText, ShoppingBasket } from 'lucide-vue-next';

const props = defineProps({
    filters: { type: Object, default: null },
    analytics: { type: Object, default: () => ({}) },
    byCategory: { type: Array, default: () => [] },
    topItems: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    currency: { type: String, default: '€' },
});

const money = (v) => `${props.currency}${Number(v ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const qty = (v) => Number(v ?? 0).toLocaleString(getIntlLocale());
const pct = (v) => `${Number(v ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
const current = computed(() => props.analytics.current || {});
const changes = computed(() => props.analytics.changes || {});
const hours = computed(() => (current.value.hours || []).filter((row) => Number(row.orders) > 0));
const maxHourRevenue = computed(() => Math.max(1, ...hours.value.map((row) => Number(row.revenue || 0))));
const trend = (value) => value > 0 ? 'up' : value < 0 ? 'down' : 'flat';
const changeText = (key, suffix = '%') => changes.value[key] == null ? translate('reports360.noComparison') : `${changes.value[key] > 0 ? '+' : ''}${Number(changes.value[key]).toLocaleString(getIntlLocale())}${suffix}`;

const catTotalQty = () => props.byCategory.reduce((s, r) => s + Number(r.qty ?? 0), 0);
const catTotalRevenue = () => props.byCategory.reduce((s, r) => s + Number(r.revenue ?? 0), 0);

const kpis = computed(() => [
    { label: translate('reports360.posPerformance.revenue'), value: money(props.summary.total_revenue), tone: 'accent', icon: Banknote, trend: trend(changes.value.revenue), trendText: changeText('revenue') },
    { label: translate('reports360.posPerformance.orders'), value: qty(props.summary.order_count), tone: 'info', icon: ReceiptText, trend: trend(changes.value.orders), trendText: changeText('orders') },
    { label: translate('reports360.posPerformance.avgTicket'), value: money(props.summary.avg_ticket), tone: 'success', icon: ShoppingBasket, trend: trend(changes.value.avg_ticket), trendText: changeText('avg_ticket') },
    { label: translate('reports360.posPerformance.grossMargin'), value: pct(props.summary.gross_margin), tone: 'neutral', icon: ChartNoAxesCombined, detail: money(props.summary.gross_profit), trend: trend(changes.value.gross_margin), trendText: changeText('gross_margin', ' pp') },
]);

const categoryBars = computed(() => props.byCategory.map((row) => ({
    key: row.category,
    label: row.name,
    value: Number(row.revenue ?? 0),
    display: money(row.revenue),
    detail: `${qty(row.qty)} ${translate('reports360.posPerformance.items')} · ${pct(row.gross_margin)}`,
})));
</script>

<template>
    <ReportShell :title="$t('reports360.posPerformance.title')" route-name="reports.posSales" :filters="filters" :description="$t('reports360.posPerformance.short')" :category="$t('reports360.posPerformance.category')">
        <ReportKpiGrid :items="kpis" />

        <div class="mt-4 grid gap-4 xl:grid-cols-2">
            <ReportBarList :title="$t('reports360.posPerformance.byCategory')" :rows="categoryBars" />
            <Card :padding="false">
                <div class="border-b border-neutral-200 px-5 py-4"><h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.posPerformance.hourly') }}</h2></div>
                <div v-if="hours.length" class="space-y-2 px-5 py-4">
                    <div v-for="row in hours" :key="row.hour" class="grid grid-cols-[48px_1fr_92px] items-center gap-3">
                        <span class="text-body-sm tabular-nums text-neutral-600">{{ String(row.hour).padStart(2, '0') }}:00</span>
                        <div class="h-2 overflow-hidden rounded-full bg-neutral-100"><div class="h-full rounded-full bg-accent-500" :style="{ width: `${Math.max(3, Number(row.revenue) / maxHourRevenue * 100)}%` }" /></div>
                        <span class="text-right text-body-sm font-medium tabular-nums text-primary-900">{{ money(row.revenue) }}</span>
                    </div>
                </div>
                <div v-else class="px-5 py-10 text-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
            </Card>
        </div>

        <!-- By category -->
        <Card :padding="false" class="mt-6">
            <div class="px-5 py-4 border-b border-neutral-200">
                <h3 class="text-h4 text-primary-900">{{ $t('admin.generated.k_36b4a963aaba') }}</h3>
            </div>
            <table v-if="byCategory.length" class="min-w-full divide-y divide-neutral-200">
                <thead class="bg-neutral-50">
                    <tr>
                        <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_48449125494d') }}</th>
                        <th class="px-5 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_80d6516576b5') }}</th>
                        <th class="px-5 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_ac1339756b5c') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200">
                    <tr v-for="row in byCategory" :key="row.name">
                        <td class="px-5 py-3 text-body-sm">{{ row.name }}</td>
                        <td class="px-5 py-3 text-body-sm text-right">{{ qty(row.qty) }}</td>
                        <td class="px-5 py-3 text-body-sm text-right">{{ money(row.revenue) }}</td>
                    </tr>
                </tbody>
                <tfoot class="bg-neutral-50 border-t-2 border-neutral-200">
                    <tr class="font-semibold">
                        <td class="px-5 py-3 text-body-sm">{{ $t('admin.generated.k_797d3c41869c') }}</td>
                        <td class="px-5 py-3 text-body-sm text-right">{{ qty(catTotalQty()) }}</td>
                        <td class="px-5 py-3 text-body-sm text-right">{{ money(catTotalRevenue()) }}</td>
                    </tr>
                </tfoot>
            </table>
            <div v-else class="px-6 py-10 text-center text-body-sm text-neutral-500">{{ $t('admin.generated.k_691be38c1767') }}</div>
        </Card>

        <!-- Top items -->
        <Card :padding="false" class="mt-6">
            <div class="px-5 py-4 border-b border-neutral-200">
                <h3 class="text-h4 text-primary-900">{{ $t('admin.generated.k_401fbbbfd15d') }}</h3>
            </div>
            <table v-if="topItems.length" class="min-w-full divide-y divide-neutral-200">
                <thead class="bg-neutral-50">
                    <tr>
                        <th class="px-5 py-3 text-left text-label text-neutral-600">#</th>
                        <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_4877e3f9d0f5') }}</th>
                        <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_48449125494d') }}</th>
                        <th class="px-5 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_80d6516576b5') }}</th>
                        <th class="px-5 py-3 text-right text-label text-neutral-600">{{ $t('reports360.posPerformance.cost') }}</th>
                        <th class="px-5 py-3 text-right text-label text-neutral-600">{{ $t('reports360.posPerformance.margin') }}</th>
                        <th class="px-5 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_ac1339756b5c') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200">
                    <tr v-for="(row, i) in topItems" :key="row.name + i">
                        <td class="px-5 py-3 text-body-sm text-neutral-500">{{ i + 1 }}</td>
                        <td class="px-5 py-3 text-body-sm">{{ row.name }}</td>
                        <td class="px-5 py-3 text-body-sm text-neutral-600">{{ row.category }}</td>
                        <td class="px-5 py-3 text-body-sm text-right">{{ qty(row.qty) }}</td>
                        <td class="px-5 py-3 text-body-sm text-right text-neutral-600">{{ money(row.cost) }}</td>
                        <td class="px-5 py-3 text-body-sm text-right" :class="row.gross_margin >= 60 ? 'text-success-700' : 'text-warning-700'">{{ pct(row.gross_margin) }}</td>
                        <td class="px-5 py-3 text-body-sm text-right">{{ money(row.revenue) }}</td>
                    </tr>
                </tbody>
            </table>
            <div v-else class="px-6 py-10 text-center text-body-sm text-neutral-500">{{ $t('admin.generated.k_691be38c1767') }}</div>
        </Card>
    </ReportShell>
</template>
