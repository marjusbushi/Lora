<script setup>
import { computed } from 'vue';
import { getIntlLocale, translate } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import { Banknote, BedDouble, ChartNoAxesCombined, Gauge } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    analytics: { type: Object, default: () => ({}) },
    budget: { type: Object, default: () => ({}) },
    currency: { type: String, default: '€' },
});

const current = computed(() => props.analytics.current || {});
const kpiValues = computed(() => current.value.kpis || {});
const changes = computed(() => props.analytics.changes || {});
const rows = computed(() => current.value.rows || []);
const previousRows = computed(() => new Map((props.analytics.previous_period?.rows || []).map((row) => [row.type_id, row])));
const daily = computed(() => Object.entries(current.value.daily || {}).map(([date, values], index) => ({
    date,
    ...values,
    previous: Object.values(props.analytics.previous_period?.daily || {})[index]?.room_revenue || 0,
})));
const maxDailyRevenue = computed(() => Math.max(1, ...daily.value.flatMap((day) => [day.room_revenue, day.previous])));

const money = (value) => `${props.currency}${Number(value ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const pct = (value) => `${Number(value ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
const trendText = (key) => changes.value[key] === null || changes.value[key] === undefined
    ? translate('reports360.noComparison')
    : `${changes.value[key] > 0 ? '+' : ''}${changes.value[key]}${key === 'occupancy' ? ' pp' : '%'}`;
const trend = (key) => changes.value[key] > 0 ? 'up' : changes.value[key] < 0 ? 'down' : 'flat';
const occupancyDelta = (row) => {
    const previous = previousRows.value.get(row.type_id)?.occupancy;
    if (previous === undefined) return null;
    return Math.round((Number(row.occupancy) - Number(previous)) * 10) / 10;
};

const kpis = computed(() => [
    { label: translate('reports360.occupancy'), value: pct(kpiValues.value.occupancy), tone: 'info', icon: BedDouble, trend: trend('occupancy'), trendText: trendText('occupancy') },
    { label: 'ADR', value: money(kpiValues.value.adr), tone: 'accent', icon: ChartNoAxesCombined, trend: trend('adr'), trendText: trendText('adr') },
    { label: 'RevPAR', value: money(kpiValues.value.revpar), tone: 'success', icon: Gauge, trend: trend('revpar'), trendText: trendText('revpar') },
    { label: translate('reports360.roomRevenue'), value: money(kpiValues.value.room_revenue), tone: 'neutral', icon: Banknote, trend: trend('room_revenue'), trendText: trendText('room_revenue') },
]);

const targets = computed(() => [
    { label: translate('reports360.occupancy'), actual: pct(kpiValues.value.occupancy), target: props.budget.occupancy_target == null ? null : pct(props.budget.occupancy_target) },
    { label: 'ADR', actual: money(kpiValues.value.adr), target: props.budget.adr_target == null ? null : money(props.budget.adr_target) },
    { label: 'RevPAR', actual: money(kpiValues.value.revpar), target: props.budget.revpar_target == null ? null : money(props.budget.revpar_target) },
    { label: translate('reports360.roomRevenue'), actual: money(kpiValues.value.room_revenue), target: props.budget.revenue_target == null ? null : money(props.budget.revenue_target) },
]);
</script>

<template>
    <ReportShell
        :title="$t('reports360.revenuePerformance.title')"
        route-name="reports.performance"
        :filters="filters"
        :description="$t('reports360.revenuePerformance.short')"
        :category="$t('reports360.revenuePerformance.category')"
    >
        <ReportKpiGrid :items="kpis" />

        <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.65fr)_minmax(280px,0.65fr)]">
            <Card :padding="false">
                <div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4">
                    <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.dailyRevenue') }}</h2>
                    <div class="flex items-center gap-3 text-tiny text-neutral-500">
                        <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-accent-500" />{{ $t('reports360.currentPeriod') }}</span>
                        <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-neutral-300" />{{ $t('reports360.previousPeriod') }}</span>
                    </div>
                </div>
                <div class="h-60 px-5 pb-4 pt-5">
                    <div v-if="daily.length" class="flex h-full items-end gap-1.5 border-b border-neutral-200">
                        <div v-for="day in daily" :key="day.date" class="group relative flex h-full min-w-0 flex-1 items-end justify-center gap-px" :title="`${day.date}: ${money(day.room_revenue)} · ${pct(day.occupancy)}`">
                            <span class="w-1/3 rounded-t bg-neutral-200" :style="{ height: `${Math.max(2, day.previous / maxDailyRevenue * 100)}%` }" />
                            <span class="w-1/3 rounded-t bg-accent-500 transition group-hover:bg-accent-700" :style="{ height: `${Math.max(2, day.room_revenue / maxDailyRevenue * 100)}%` }" />
                        </div>
                    </div>
                    <div v-else class="flex h-full items-center justify-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
                </div>
                <div class="flex flex-wrap gap-x-8 gap-y-2 border-t border-neutral-200 px-5 py-3 text-tiny text-neutral-500">
                    <span>{{ $t('reports360.revenuePerformance.soldNights') }} <b class="ml-1 text-primary-900">{{ kpiValues.occupied_room_nights || 0 }}</b></span>
                    <span>{{ $t('reports360.revenuePerformance.sellableNights') }} <b class="ml-1 text-primary-900">{{ kpiValues.sellable_room_nights || 0 }}</b></span>
                </div>
            </Card>

            <Card :padding="false">
                <div class="border-b border-neutral-200 px-5 py-4">
                    <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.revenuePerformance.targets') }}</h2>
                </div>
                <div class="divide-y divide-neutral-100">
                    <div v-for="item in targets" :key="item.label" class="grid grid-cols-[1fr_auto_auto] items-center gap-3 px-5 py-3">
                        <span class="text-body-sm text-neutral-600">{{ item.label }}</span>
                        <b class="text-body-sm text-primary-900">{{ item.actual }}</b>
                        <Badge :variant="item.target ? 'neutral' : 'warning'">{{ item.target || '—' }}</Badge>
                    </div>
                </div>
                <p v-if="!budget.has_budget" class="border-t border-neutral-200 px-5 py-3 text-tiny text-neutral-500">{{ $t('reports360.budgetMissing') }}</p>
            </Card>
        </div>

        <Card class="mt-4" :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4">
                <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.revenuePerformance.byRoomType') }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50 text-left text-label text-neutral-600">
                        <tr>
                            <th class="px-5 py-3">{{ $t('reports360.revenuePerformance.roomType') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.revenuePerformance.rooms') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.revenuePerformance.soldNights') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.revenuePerformance.sellableNights') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.occupancy') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.roomRevenue') }}</th>
                            <th class="px-4 py-3 text-right">ADR</th>
                            <th class="px-5 py-3 text-right">RevPAR</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in rows" :key="row.type_id" class="hover:bg-neutral-50">
                            <td class="px-5 py-3 text-body-sm font-medium text-primary-900">{{ row.type }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ row.rooms_count }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ row.occupied_room_nights }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ row.sellable_room_nights }}</td>
                            <td class="px-4 py-3 text-right text-body-sm">
                                <span class="font-semibold text-primary-900">{{ pct(row.occupancy) }}</span>
                                <small v-if="occupancyDelta(row) !== null" :class="['ml-1', occupancyDelta(row) >= 0 ? 'text-success-600' : 'text-error-600']">{{ occupancyDelta(row) > 0 ? '+' : '' }}{{ occupancyDelta(row) }}pp</small>
                            </td>
                            <td class="px-4 py-3 text-right text-body-sm font-medium text-primary-900">{{ money(row.room_revenue) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-700">{{ money(row.adr) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold text-primary-900">{{ money(row.revpar) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rows.length" class="border-t-2 border-neutral-200 bg-neutral-50">
                        <tr>
                            <td class="px-5 py-3 text-body-sm font-semibold text-primary-900">{{ $t('reports360.revenuePerformance.total') }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold">{{ rows.reduce((sum, row) => sum + row.rooms_count, 0) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold">{{ kpiValues.occupied_room_nights }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold">{{ kpiValues.sellable_room_nights }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold">{{ pct(kpiValues.occupancy) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold">{{ money(kpiValues.room_revenue) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold">{{ money(kpiValues.adr) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ money(kpiValues.revpar) }}</td>
                        </tr>
                    </tfoot>
                </table>
                <div v-if="!rows.length" class="px-5 py-10 text-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
            </div>
        </Card>
    </ReportShell>
</template>
