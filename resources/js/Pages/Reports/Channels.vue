<script setup>
import { computed } from 'vue';
import { getIntlLocale, translate } from '@/i18n';
import { channelMeta } from '@/channels';
import ReportShell from '@/Components/UI/ReportShell.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import { Banknote, ChartNoAxesCombined, HandCoins, Percent } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';
import { useReportDrilldown } from '@/composables/useReportDrilldown';

const props = defineProps({
    filters: Object,
    analytics: { type: Object, default: () => ({}) },
    currency: { type: String, default: '€' },
});
const { can } = useReportDrilldown();
const channelHref = (channel) => can('view_reservations') ? route('reservations.index', { channel, from: props.filters?.from, to: props.filters?.to }) : null;

const current = computed(() => props.analytics.current || {});
const totals = computed(() => current.value.totals || {});
const rows = computed(() => current.value.rows || []);
const changes = computed(() => props.analytics.changes || {});
const daily = computed(() => Object.entries(current.value.daily || {}).map(([date, values]) => ({ date, ...values })));
const maxDaily = computed(() => Math.max(1, ...daily.value.flatMap((day) => [day.direct_net || 0, day.ota_net || 0])));
const otaShare = computed(() => Number(totals.value.gross_revenue || 0) > 0
    ? Math.max(0, 100 - Number(totals.value.direct_share || 0))
    : 0);

const money = (value) => `${props.currency}${Number(value ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const pct = (value) => `${Number(value ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
const trend = (value) => value > 0 ? 'up' : value < 0 ? 'down' : 'flat';
const changeText = (key, suffix = '%') => changes.value[key] == null
    ? translate('reports360.noComparison')
    : `${changes.value[key] > 0 ? '+' : ''}${changes.value[key]}${suffix}`;

const kpis = computed(() => [
    { label: translate('reports360.distribution.netRevenue'), value: money(totals.value.net_revenue), tone: 'success', icon: HandCoins, trend: trend(changes.value.net_revenue), trendText: changeText('net_revenue'), href: can('view_reservations') ? route('reservations.index', props.filters) : null },
    { label: translate('reports360.distribution.directShare'), value: pct(totals.value.direct_share), tone: 'accent', icon: Percent, trend: trend(changes.value.direct_share), trendText: changeText('direct_share', 'pp'), href: channelHref('direct') },
    { label: translate('reports360.distribution.commission'), value: money(totals.value.commission), tone: 'warning', icon: Banknote, detail: pct(totals.value.commission_rate) },
    { label: translate('reports360.distribution.netAdr'), value: money(totals.value.net_adr), tone: 'info', icon: ChartNoAxesCombined, detail: `${totals.value.nights || 0} ${translate('reports360.nights')}` },
]);
</script>

<template>
    <ReportShell
        :title="$t('reports360.distribution.title')"
        route-name="reports.channels"
        :filters="filters"
        :description="$t('reports360.distribution.short')"
        :category="$t('reports360.distribution.category')"
    >
        <ReportKpiGrid :items="kpis" />

        <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.55fr)_minmax(320px,0.75fr)]">
            <Card :padding="false">
                <div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4">
                    <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.distribution.dailyNet') }}</h2>
                    <div class="flex items-center gap-3 text-tiny text-neutral-500">
                        <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-accent-500" />Direct</span>
                        <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-warning-400" />OTA</span>
                    </div>
                </div>
                <div class="h-60 px-5 pb-4 pt-5">
                    <div v-if="daily.length" class="flex h-full items-end gap-1.5 border-b border-neutral-200">
                        <div v-for="day in daily" :key="day.date" class="group flex h-full min-w-0 flex-1 items-end justify-center gap-px" :title="`${day.date} · Direct ${money(day.direct_net)} · OTA ${money(day.ota_net)}`">
                            <span class="w-1/3 rounded-t bg-accent-500 transition group-hover:bg-accent-700" :style="{ height: `${Math.max(2, Number(day.direct_net || 0) / maxDaily * 100)}%` }" />
                            <span class="w-1/3 rounded-t bg-warning-400 transition group-hover:bg-warning-500" :style="{ height: `${Math.max(2, Number(day.ota_net || 0) / maxDaily * 100)}%` }" />
                        </div>
                    </div>
                    <div v-else class="flex h-full items-center justify-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
                </div>
            </Card>

            <Card :padding="false">
                <div class="border-b border-neutral-200 px-5 py-4">
                    <h2 class="text-body font-semibold text-primary-900">Direct vs OTA</h2>
                </div>
                <div class="divide-y divide-neutral-100">
                    <div class="px-5 py-4">
                        <div class="flex items-center justify-between">
                            <span class="text-body-sm font-medium text-primary-900">Direct</span>
                            <Badge variant="success">{{ pct(totals.direct_share) }}</Badge>
                        </div>
                        <p class="mt-2 text-h3 text-accent-700">{{ money(totals.direct_revenue) }}</p>
                    </div>
                    <div class="px-5 py-4">
                        <div class="flex items-center justify-between">
                            <span class="text-body-sm font-medium text-primary-900">OTA</span>
                            <Badge variant="warning">{{ pct(otaShare) }}</Badge>
                        </div>
                        <p class="mt-2 text-h3 text-warning-700">{{ money(totals.ota_revenue) }}</p>
                    </div>
                </div>
                <div class="border-t border-neutral-200 px-5 py-3 text-tiny text-neutral-500">
                    {{ $t('reports360.distribution.grossRevenue') }} <b class="ml-1 text-primary-900">{{ money(totals.gross_revenue) }}</b>
                </div>
            </Card>
        </div>

        <Card class="mt-4" :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4">
                <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.distribution.byChannel') }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50 text-left text-label text-neutral-600">
                        <tr>
                            <th class="px-5 py-3">{{ $t('reports360.channel') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.distribution.share') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.distribution.bookings') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.nights') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.distribution.grossRevenue') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.distribution.commission') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.distribution.netRevenue') }}</th>
                            <th class="px-5 py-3 text-right">{{ $t('reports360.distribution.netAdr') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in rows" :key="row.channel" class="hover:bg-neutral-50">
                            <td class="px-5 py-3">
                                <Link v-if="channelHref(row.channel)" :href="channelHref(row.channel)" class="inline-flex items-center gap-2 text-body-sm font-medium text-primary-900 hover:underline"><i class="h-2 w-2 rounded-full" :style="{ backgroundColor: channelMeta(row.channel).color }" />{{ channelMeta(row.channel).label }}</Link><span v-else class="inline-flex items-center gap-2 text-body-sm font-medium text-primary-900"><i class="h-2 w-2 rounded-full" :style="{ backgroundColor: channelMeta(row.channel).color }" />{{ channelMeta(row.channel).label }}</span>
                            </td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ pct(row.revenue_share) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ row.bookings }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ row.nights }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-primary-900">{{ money(row.gross_revenue) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-error-600">{{ row.commission ? `−${money(row.commission)}` : '—' }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold text-primary-900">{{ money(row.net_revenue) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ money(row.net_adr) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rows.length" class="border-t-2 border-neutral-200 bg-neutral-50">
                        <tr>
                            <td class="px-5 py-3 text-body-sm font-semibold text-primary-900">{{ $t('reports360.revenuePerformance.total') }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold">{{ Number(totals.gross_revenue || 0) > 0 ? '100%' : '0.0%' }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold">{{ totals.bookings }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold">{{ totals.nights }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold">{{ money(totals.gross_revenue) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold text-error-600">{{ totals.commission ? `−${money(totals.commission)}` : '—' }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold">{{ money(totals.net_revenue) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ money(totals.net_adr) }}</td>
                        </tr>
                    </tfoot>
                </table>
                <div v-if="!rows.length" class="px-5 py-10 text-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
            </div>
        </Card>
    </ReportShell>
</template>
