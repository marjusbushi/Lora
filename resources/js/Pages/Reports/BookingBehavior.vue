<script setup>
import { computed } from 'vue';
import { getIntlLocale, translate } from '@/i18n';
import { channelMeta } from '@/channels';
import ReportShell from '@/Components/UI/ReportShell.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import Card from '@/Components/UI/Card.vue';
import { CalendarClock, CalendarDays, Percent, ReceiptText } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    analytics: { type: Object, default: () => ({}) },
});

const current = computed(() => props.analytics.current || {});
const summary = computed(() => current.value.summary || {});
const channels = computed(() => current.value.channels || []);
const changes = computed(() => props.analytics.changes || {});

const num = (value, digits = 1) => Number(value ?? 0).toLocaleString(getIntlLocale(), {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
});
const pct = (value) => `${num(value)}%`;
const trend = (value) => value > 0 ? 'up' : value < 0 ? 'down' : 'flat';
const changeText = (key, suffix) => changes.value[key] == null
    ? translate('reports360.noComparison')
    : `${changes.value[key] > 0 ? '+' : ''}${num(changes.value[key])}${suffix}`;

const leadBuckets = computed(() => (current.value.lead_buckets || []).map((row) => ({
    key: row.key,
    label: translate(`reports360.bookingBehavior.leadBuckets.${row.key}`),
    value: row.count,
    display: `${row.count} · ${pct(row.share)}`,
    barClass: 'bg-info-500',
})));
const losBuckets = computed(() => (current.value.los_buckets || []).map((row) => ({
    key: row.key,
    label: translate(`reports360.bookingBehavior.losBuckets.${row.key}`),
    value: row.count,
    display: `${row.count} · ${pct(row.share)}`,
    barClass: 'bg-accent-500',
})));

const kpis = computed(() => [
    {
        label: translate('reports360.bookingBehavior.bookings'),
        value: Number(summary.value.count || 0).toLocaleString(getIntlLocale()),
        tone: 'accent',
        icon: ReceiptText,
        trend: trend(changes.value.count),
        trendText: changeText('count', '%'),
    },
    {
        label: translate('reports360.bookingBehavior.avgLead'),
        value: `${num(summary.value.avg_lead)} ${translate('reports360.bookingBehavior.days')}`,
        tone: 'info',
        icon: CalendarClock,
        trend: trend(changes.value.avg_lead),
        trendText: changeText('avg_lead', ` ${translate('reports360.bookingBehavior.days')}`),
    },
    {
        label: translate('reports360.bookingBehavior.avgStay'),
        value: `${num(summary.value.avg_los)} ${translate('reports360.nights')}`,
        tone: 'success',
        icon: CalendarDays,
        trend: trend(changes.value.avg_los),
        trendText: changeText('avg_los', ` ${translate('reports360.nights')}`),
    },
    {
        label: translate('reports360.bookingBehavior.sameDayShare'),
        value: pct(summary.value.same_day_share),
        tone: 'warning',
        icon: Percent,
        trend: trend(changes.value.same_day_share),
        trendText: changeText('same_day_share', 'pp'),
    },
]);
</script>

<template>
    <ReportShell
        :title="$t('reports360.bookingBehavior.title')"
        route-name="reports.bookingBehavior"
        :filters="filters"
        :description="$t('reports360.bookingBehavior.short')"
        :category="$t('reports360.bookingBehavior.category')"
    >
        <ReportKpiGrid :items="kpis" />

        <div class="mt-4 grid gap-4 xl:grid-cols-2">
            <ReportBarList
                :title="$t('reports360.bookingBehavior.bookingWindow')"
                :rows="leadBuckets"
            />
            <ReportBarList
                :title="$t('reports360.bookingBehavior.stayLength')"
                :rows="losBuckets"
            />
        </div>

        <Card class="mt-4" :padding="false">
            <div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4">
                <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.bookingBehavior.byChannel') }}</h2>
                <span class="text-tiny text-neutral-500">
                    {{ $t('reports360.bookingBehavior.medianLead') }}: {{ num(summary.median_lead) }} {{ $t('reports360.bookingBehavior.days') }}
                </span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50 text-left text-label text-neutral-600">
                        <tr>
                            <th class="px-5 py-3">{{ $t('reports360.channel') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.bookingBehavior.share') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.bookingBehavior.bookings') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.bookingBehavior.avgLead') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.bookingBehavior.medianLead') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.bookingBehavior.avgStay') }}</th>
                            <th class="px-5 py-3 text-right">{{ $t('reports360.bookingBehavior.longStay') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in channels" :key="row.channel" class="hover:bg-neutral-50">
                            <td class="px-5 py-3">
                                <span class="inline-flex items-center gap-2 text-body-sm font-medium text-primary-900">
                                    <i class="h-2 w-2 rounded-full" :style="{ backgroundColor: channelMeta(row.channel).color }" />
                                    {{ channelMeta(row.channel).label }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ pct(row.share) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-700">{{ row.count }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-700">{{ num(row.avg_lead) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-700">{{ num(row.median_lead) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-700">{{ num(row.avg_los) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold text-primary-900">{{ pct(row.long_stay_share) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="channels.length" class="border-t-2 border-neutral-200 bg-neutral-50">
                        <tr>
                            <td class="px-5 py-3 text-body-sm font-semibold text-primary-900">{{ $t('reports360.revenuePerformance.total') }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold">100%</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold">{{ summary.count }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold">{{ num(summary.avg_lead) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold">{{ num(summary.median_lead) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold">{{ num(summary.avg_los) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ pct(summary.long_stay_share) }}</td>
                        </tr>
                    </tfoot>
                </table>
                <div v-if="!channels.length" class="px-5 py-10 text-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
            </div>
        </Card>
    </ReportShell>
</template>
