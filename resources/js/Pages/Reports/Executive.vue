<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { getIntlLocale, translate } from '@/i18n';
import { useCurrency } from '@/composables/useCurrency';
import ReportShell from '@/Components/UI/ReportShell.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import InfoTip from '@/Components/UI/InfoTip.vue';
import { Banknote, BedDouble, ChartNoAxesCombined, CircleAlert, Gauge, LogIn, LogOut, Sparkles, Target, TrendingUp, Users, WalletCards } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    analytics: { type: Object, default: () => ({}) },
    budget: { type: Object, default: () => ({}) },
    forecast: { type: Object, default: () => ({}) },
    outstanding: { type: Object, default: () => ({}) },
    outstandingSplit: { type: Object, default: () => ({}) },
    operations: { type: Object, default: () => ({}) },
    channels: { type: Array, default: () => [] },
    alerts: { type: Array, default: () => [] },
    currency: { type: String, default: '€' },
    pricingCurrency: { type: String, default: '' },
    baseToPricingRate: { type: Number, default: null },
    occupancyAlertPct: { type: Number, default: 85 },
});

const { baseCode, pricingCode, pricingSymbol } = useCurrency();

// The owner reads the selling currency (EUR at Saturn); the backend sends
// base-currency (Lek) figures. Convert for display only when the two differ
// AND a rate arrived — otherwise fall back to base, never to a wrong symbol.
const displayRate = computed(() => {
    const rate = Number(props.baseToPricingRate || 0);
    return rate > 0 && pricingCode.value !== baseCode.value ? rate : null;
});
const displaySymbol = computed(() => (displayRate.value ? pricingSymbol.value : props.currency));

const current = computed(() => props.analytics.current?.kpis || {});
const changes = computed(() => props.analytics.changes || {});
const previousYearKpis = computed(() => props.analytics.previous_year?.kpis || {});
const daily = computed(() => Object.entries(props.analytics.current?.daily || {}).map(([date, value], index) => ({
    date,
    ...value,
    previous: Object.values(props.analytics.previous_period?.daily || {})[index]?.total_revenue || 0,
})));
const maxDailyRevenue = computed(() => Math.max(1, ...daily.value.flatMap((day) => [day.total_revenue, day.previous])));
const forecastKpis = computed(() => props.forecast.kpis || {});
const budgetProgress = computed(() => props.budget.revenue_target
    ? Math.min(100, Math.round(Number(current.value.total_revenue || 0) / Number(props.budget.revenue_target) * 100))
    : null);

const money = (value) => {
    const shown = Number(value ?? 0) * (displayRate.value ?? 1);
    return `${displaySymbol.value}${shown.toLocaleString(getIntlLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};
const pct = (value) => `${Number(value ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
const trendText = (key) => changes.value[key] === null || changes.value[key] === undefined
    ? translate('reports360.noComparison')
    : `${changes.value[key] > 0 ? '+' : ''}${changes.value[key]}${key === 'occupancy' ? ' pp' : '%'}`;
const trend = (key) => changes.value[key] > 0 ? 'up' : changes.value[key] < 0 ? 'down' : 'flat';

// "vs last year" only when last year actually has data — a hotel new to the
// system would otherwise show a noisy €0.00 on every card.
const lastYear = (key, formatter) => {
    const value = Number(previousYearKpis.value[key] || 0);
    return value ? `${translate('reports360.lastYearShort')}: ${formatter(value)}` : null;
};

const kpis = computed(() => [
    { label: translate('reports360.totalRevenue'), help: translate('reports360.help.totalRevenue'), value: money(current.value.total_revenue), tone: 'accent', icon: Banknote, trend: trend('total_revenue'), trendText: trendText('total_revenue'), subtext: lastYear('total_revenue', money) },
    { label: translate('reports360.occupancy'), help: translate('reports360.help.occupancy'), value: pct(current.value.occupancy), tone: 'info', icon: BedDouble, trend: trend('occupancy'), trendText: trendText('occupancy'), subtext: lastYear('occupancy', pct) },
    { label: 'ADR', help: translate('reports360.help.adr'), value: money(current.value.adr), tone: 'neutral', icon: ChartNoAxesCombined, trend: trend('adr'), trendText: trendText('adr'), subtext: lastYear('adr', money) },
    { label: 'RevPAR', help: translate('reports360.help.revpar'), value: money(current.value.revpar), tone: 'success', icon: TrendingUp, trend: trend('revpar'), trendText: trendText('revpar'), subtext: lastYear('revpar', money) },
    { label: 'TRevPAR', help: translate('reports360.help.trevpar'), value: money(current.value.trevpar), tone: 'warning', icon: Gauge, trend: trend('trevpar'), trendText: trendText('trevpar'), subtext: lastYear('trevpar', money) },
]);

const opsItems = computed(() => {
    const arrivals = props.operations.arrivals || {};
    const departures = props.operations.departures || {};
    const inHouse = props.operations.in_house || {};
    const roomsToClean = Number(props.operations.rooms_to_clean ?? 0);
    return [
        { label: translate('reports360.opsArrivals'), value: `${arrivals.count ?? 0}`, tone: 'info', icon: LogIn, detail: translate('reports360.guestsCount', { count: arrivals.pax ?? 0 }), href: route('reports.guestMovements') },
        { label: translate('reports360.opsDepartures'), value: `${departures.count ?? 0}`, tone: departures.open_pos > 0 ? 'warning' : 'neutral', icon: LogOut, detail: departures.open_pos > 0 ? translate('reports360.openPosCount', { count: departures.open_pos }) : translate('reports360.guestsCount', { count: departures.pax ?? 0 }), href: route('reports.guestMovements') },
        { label: translate('reports360.opsInHouse'), value: `${inHouse.count ?? 0}`, tone: 'accent', icon: Users, detail: translate('reports360.guestsCount', { count: inHouse.pax ?? 0 }), href: route('reports.inHouse') },
        { label: translate('reports360.opsRoomsToClean'), value: `${roomsToClean}`, tone: roomsToClean > 0 ? 'warning' : 'success', icon: Sparkles, href: route('housekeeping.index') },
    ];
});

const splitRows = computed(() => [
    { key: 'overdue', variant: 'error', label: translate('reports360.overdueBalances'), data: props.outstandingSplit.overdue },
    { key: 'due_at_checkout', variant: 'warning', label: translate('reports360.dueAtCheckout'), data: props.outstandingSplit.due_at_checkout },
    { key: 'future', variant: 'neutral', label: translate('reports360.futureStays'), data: props.outstandingSplit.future },
].filter((row) => row.data));

const alertMeta = (alert) => ({
    budget: { variant: 'warning', title: translate('reports360.budgetGap'), detail: money(alert.value), href: route('settings.index', { tab: 'pricing' }) },
    outstanding: { variant: 'error', title: translate('reports360.overdueBalances'), detail: `${alert.count} · ${money(alert.value)}`, href: route('reports.outstanding') },
    demand: { variant: 'success', title: translate('reports360.highDemand'), detail: `${alert.date} · ${pct(alert.value)}`, href: route('pricing.index') },
}[alert.kind] || { variant: 'neutral', title: alert.kind, detail: '', href: route('reports.index') });
</script>

<template>
    <ReportShell :title="$t('reports360.executiveDashboard')" route-name="reports.executive" :filters="filters" :description="$t('reports360.executiveShort')">
        <ReportKpiGrid :items="kpis" :columns="5" />
        <p v-if="displayRate" class="mt-2 text-tiny text-neutral-500">{{ $t('reports360.amountsShownIn', { currency: pricingCode }) }}</p>

        <h2 class="mt-5 text-tiny font-semibold uppercase tracking-wider text-neutral-500">{{ $t('reports360.todayOps') }}</h2>
        <div class="mt-2">
            <ReportKpiGrid :items="opsItems" :columns="4" />
        </div>

        <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.7fr)_minmax(280px,0.7fr)]">
            <Card :padding="false">
                <div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4">
                    <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.dailyRevenue') }}</h2>
                    <div class="flex items-center gap-3 text-tiny text-neutral-500">
                        <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-accent-500" />{{ $t('reports360.currentPeriod') }}</span>
                        <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-neutral-300" />{{ $t('reports360.previousPeriod') }}</span>
                    </div>
                </div>
                <div class="h-64 px-5 pb-4 pt-5">
                    <div v-if="daily.length" class="flex h-full items-end gap-1.5 border-b border-neutral-200">
                        <div v-for="day in daily" :key="day.date" class="group relative flex h-full min-w-0 flex-1 items-end justify-center gap-px" :title="`${day.date}: ${money(day.total_revenue)}`">
                            <span class="w-1/3 rounded-t bg-neutral-200" :style="{ height: `${Math.max(2, day.previous / maxDailyRevenue * 100)}%` }" />
                            <span class="w-1/3 rounded-t bg-accent-500 transition group-hover:bg-accent-700" :style="{ height: `${Math.max(2, day.total_revenue / maxDailyRevenue * 100)}%` }" />
                        </div>
                    </div>
                    <div v-else class="flex h-full items-center justify-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
                </div>
                <div class="flex flex-wrap gap-x-8 gap-y-2 border-t border-neutral-200 px-5 py-3 text-tiny text-neutral-500">
                    <span>{{ $t('reports360.roomRevenue') }} <b class="ml-1 text-primary-900">{{ money(current.room_revenue) }}</b></span>
                    <span>{{ $t('reports360.posRevenue') }} <b class="ml-1 text-primary-900">{{ money(current.pos_revenue) }}</b></span>
                    <span>{{ $t('reports360.otherRevenue') }} <b class="ml-1 text-primary-900">{{ money(current.other_revenue) }}</b></span>
                    <span>{{ $t('reports360.netRoomRevenue') }} <b class="ml-1 text-primary-900">{{ money(current.net_room_revenue) }}</b></span>
                </div>
            </Card>

            <div class="grid gap-4">
                <Card>
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="flex items-center gap-1 text-tiny font-semibold uppercase tracking-wider text-neutral-500">
                                {{ $t('reports360.budget') }}
                                <InfoTip :text="$t('reports360.help.budget')" :label="$t('reports360.budget')" />
                            </p>
                            <p class="mt-2 text-h3 text-primary-900">{{ budget.revenue_target ? money(budget.revenue_target) : '—' }}</p>
                        </div>
                        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-accent-50 text-accent-700"><Target class="h-4.5 w-4.5" /></span>
                    </div>
                    <div v-if="budgetProgress !== null" class="mt-4">
                        <div class="h-2 overflow-hidden rounded-full bg-neutral-100"><span class="block h-full rounded-full bg-accent-500" :style="{ width: `${budgetProgress}%` }" /></div>
                        <p class="mt-2 text-tiny text-neutral-500">{{ budgetProgress }}% {{ $t('reports360.completed') }}</p>
                    </div>
                    <p v-else class="mt-3 text-tiny text-neutral-500">{{ $t('reports360.budgetMissing') }}</p>
                    <div v-if="budget.has_budget" class="mt-3 grid grid-cols-3 gap-2 border-t border-neutral-100 pt-3 text-tiny">
                        <span><small class="block text-neutral-500">ADR</small><b class="text-primary-900">{{ budget.adr_target ? money(budget.adr_target) : '—' }}</b></span>
                        <span><small class="block text-neutral-500">{{ $t('reports360.occupancy') }}</small><b class="text-primary-900">{{ budget.occupancy_target ? pct(budget.occupancy_target) : '—' }}</b></span>
                        <span><small class="block text-neutral-500">RevPAR</small><b class="text-primary-900">{{ budget.revpar_target ? money(budget.revpar_target) : '—' }}</b></span>
                    </div>
                </Card>

                <Card>
                    <p class="flex items-center gap-1 text-tiny font-semibold uppercase tracking-wider text-neutral-500">
                        {{ $t('reports360.forecast30') }}
                        <InfoTip :text="$t('reports360.help.forecast30')" :label="$t('reports360.forecast30')" />
                    </p>
                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <div><p class="text-tiny text-neutral-500">{{ $t('reports360.revenue') }}</p><p class="mt-1 text-body font-semibold text-primary-900">{{ money(forecastKpis.total_revenue) }}</p></div>
                        <div><p class="text-tiny text-neutral-500">{{ $t('reports360.occupancy') }}</p><p class="mt-1 text-body font-semibold text-primary-900">{{ pct(forecastKpis.occupancy) }}</p></div>
                    </div>
                </Card>
            </div>
        </div>

        <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.5fr)_minmax(280px,0.8fr)]">
            <Card :padding="false">
                <div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4">
                    <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.channelPerformance') }}</h2>
                    <Link :href="route('reports.channels')" class="text-tiny font-semibold text-accent-700 no-underline">{{ $t('reports360.openReport') }} →</Link>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200">
                        <thead class="bg-neutral-50 text-left text-label text-neutral-600"><tr><th class="px-5 py-3">{{ $t('reports360.channel') }}</th><th class="px-4 py-3 text-right">{{ $t('reports360.nights') }}</th><th class="px-4 py-3 text-right">{{ $t('reports360.revenue') }}</th><th class="px-5 py-3 text-right">{{ $t('reports360.netRevenue') }}</th></tr></thead>
                        <tbody class="divide-y divide-neutral-100"><tr v-for="row in channels.slice(0, 5)" :key="row.channel"><td class="px-5 py-3 text-body-sm font-medium text-primary-900">{{ row.channel }}</td><td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ row.nights }}</td><td class="px-4 py-3 text-right text-body-sm text-neutral-700">{{ money(row.revenue) }}</td><td class="px-5 py-3 text-right text-body-sm font-semibold text-primary-900">{{ money(row.net) }}</td></tr></tbody>
                    </table>
                    <div v-if="!channels.length" class="px-5 py-8 text-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
                </div>
            </Card>

            <Card :padding="false">
                <div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4">
                    <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.alerts') }}</h2>
                    <Badge :variant="alerts.length ? 'warning' : 'success'">{{ alerts.length }}</Badge>
                </div>
                <div v-if="alerts.length" class="divide-y divide-neutral-100">
                    <Link v-for="alert in alerts" :key="alert.kind" :href="alertMeta(alert).href" class="flex items-center gap-3 px-5 py-4 no-underline hover:bg-neutral-50">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-warning-50 text-warning-700"><CircleAlert class="h-4 w-4" /></span>
                        <span class="min-w-0 flex-1"><b class="block text-body-sm text-primary-900">{{ alertMeta(alert).title }}</b><small class="mt-0.5 block text-neutral-500">{{ alertMeta(alert).detail }}</small></span>
                    </Link>
                </div>
                <div v-else class="flex items-center gap-3 px-5 py-8"><WalletCards class="h-5 w-5 text-success-600" /><span class="text-body-sm text-neutral-600">{{ $t('reports360.noAlerts') }}</span></div>
                <div v-if="splitRows.length" class="border-t border-neutral-200 px-5 py-4">
                    <p class="text-tiny font-semibold uppercase tracking-wider text-neutral-500">{{ $t('reports360.unpaidBreakdown') }}</p>
                    <ul class="mt-3 space-y-1 list-none p-0">
                        <li v-for="row in splitRows" :key="row.key">
                            <Link :href="route('reports.outstanding')" class="-mx-2 flex items-center justify-between gap-3 rounded-md px-2 py-1.5 no-underline hover:bg-neutral-50">
                                <span class="flex min-w-0 items-center gap-2">
                                    <Badge :variant="row.variant">{{ row.data.count }}</Badge>
                                    <span class="truncate text-body-sm text-neutral-600">{{ row.label }}</span>
                                </span>
                                <b class="whitespace-nowrap text-body-sm text-primary-900">{{ money(row.data.total) }}</b>
                            </Link>
                        </li>
                    </ul>
                </div>
            </Card>
        </div>
    </ReportShell>
</template>
