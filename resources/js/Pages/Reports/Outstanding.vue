<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { getIntlLocale, translate } from '@/i18n';
import { channelMeta } from '@/channels';
import { useReportCurrency } from '@/composables/useReportCurrency';
import ReportShell from '@/Components/UI/ReportShell.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import { AlertTriangle, CircleDollarSign, Gauge, TimerOff } from 'lucide-vue-next';

const props = defineProps({
    analytics: { type: Object, default: () => ({}) },
    canViewReservations: { type: Boolean, default: false },
    currency: { type: String, default: '€' },
    pricingCurrency: { type: String, default: '' },
    baseToPricingRate: { type: Number, default: null },
});

const activeBucket = ref('all');
const summary = computed(() => props.analytics.summary || {});
const buckets = computed(() => props.analytics.buckets || []);
const statuses = computed(() => props.analytics.statuses || []);
const allRows = computed(() => props.analytics.rows || []);
const filteredRows = computed(() => activeBucket.value === 'all'
    ? allRows.value
    : allRows.value.filter((row) => row.bucket === activeBucket.value));

const { pricingCode, displayRate, showBase, money, moneyBase } = useReportCurrency(props);
const baseLine = (value) => (showBase.value ? moneyBase(value) : null);
const pct = (value) => `${Number(value ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
const fmt = (date) => date ? new Date(`${date}T00:00:00`).toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

const bucketLabels = computed(() => ({
    all: translate('reports360.outstandingAging.all'),
    not_due: translate('reports360.outstandingAging.notDue'),
    '1_7': translate('reports360.outstandingAging.days1to7'),
    '8_30': translate('reports360.outstandingAging.days8to30'),
    '31_60': translate('reports360.outstandingAging.days31to60'),
    '61_plus': translate('reports360.outstandingAging.days61plus'),
}));

const statusBadge = {
    confirmed: { variant: 'info', label: translate('reports360.outstandingAging.status.confirmed') },
    checked_in: { variant: 'success', label: translate('reports360.outstandingAging.status.checked_in') },
    checked_out: { variant: 'neutral', label: translate('reports360.outstandingAging.status.checked_out') },
};

const kpis = computed(() => [
    {
        label: translate('reports360.outstandingAging.total'),
        value: money(summary.value.total),
        subvalue: baseLine(summary.value.total),
        tone: summary.value.total ? 'error' : 'success',
        icon: CircleDollarSign,
        detail: `${summary.value.count || 0} ${translate('reports360.outstandingAging.accounts')}`,
    },
    {
        label: translate('reports360.outstandingAging.overdue'),
        value: money(summary.value.overdue_total),
        subvalue: baseLine(summary.value.overdue_total),
        tone: summary.value.overdue_total ? 'warning' : 'success',
        icon: TimerOff,
        detail: `${summary.value.overdue_count || 0} ${translate('reports360.outstandingAging.accounts')}`,
    },
    {
        label: translate('reports360.outstandingAging.collectionRate'),
        value: pct(summary.value.collection_rate),
        tone: summary.value.collection_rate >= 80 ? 'success' : 'warning',
        icon: Gauge,
        detail: `${money(summary.value.paid)} / ${money(summary.value.gross)}`,
    },
    {
        label: translate('reports360.outstandingAging.critical'),
        value: money(summary.value.critical_total),
        subvalue: baseLine(summary.value.critical_total),
        tone: summary.value.critical_total ? 'error' : 'success',
        icon: AlertTriangle,
        detail: `${summary.value.critical_count || 0} ${translate('reports360.outstandingAging.accounts')}`,
    },
]);
</script>

<template>
    <ReportShell
        :title="$t('reports360.outstandingAging.title')"
        :description="$t('reports360.outstandingAging.short')"
        :category="$t('reports360.outstandingAging.category')"
    >
        <ReportKpiGrid :items="kpis" />
        <p v-if="displayRate" class="mt-2 text-tiny text-neutral-500">{{ $t('reports360.amountsShownIn', { currency: pricingCode }) }}</p>
        <p v-if="displayRate" class="mt-2 text-tiny text-neutral-500">{{ $t('reports360.amountsShownIn', { currency: pricingCode }) }}</p>

        <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.55fr)_minmax(300px,0.65fr)]">
            <Card :padding="false">
                <div class="border-b border-neutral-200 px-5 py-4">
                    <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.outstandingAging.aging') }}</h2>
                </div>
                <div class="space-y-4 px-5 py-5">
                    <button
                        v-for="bucket in buckets"
                        :key="bucket.key"
                        type="button"
                        class="block w-full text-left"
                        @click="activeBucket = activeBucket === bucket.key ? 'all' : bucket.key"
                    >
                        <span class="mb-1.5 flex items-center justify-between gap-3 text-body-sm">
                            <span :class="activeBucket === bucket.key ? 'font-semibold text-accent-700' : 'font-medium text-primary-900'">{{ bucketLabels[bucket.key] }}</span>
                            <span class="text-neutral-600">{{ bucket.count }} · <b class="text-primary-900">{{ money(bucket.amount) }}</b><small v-if="showBase" class="ml-1 text-neutral-400">({{ moneyBase(bucket.amount) }})</small></span>
                        </span>
                        <span class="block h-2 overflow-hidden rounded-full bg-neutral-100">
                            <i
                                class="block h-full rounded-full transition-all"
                                :class="bucket.key === '61_plus' || bucket.key === '31_60' ? 'bg-error-500' : bucket.key === 'not_due' ? 'bg-info-400' : 'bg-warning-400'"
                                :style="{ width: `${Math.max(bucket.amount ? 2 : 0, bucket.share || 0)}%` }"
                            />
                        </span>
                    </button>
                </div>
            </Card>

            <Card :padding="false">
                <div class="border-b border-neutral-200 px-5 py-4">
                    <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.outstandingAging.byStatus') }}</h2>
                </div>
                <div class="divide-y divide-neutral-100">
                    <div v-for="row in statuses" :key="row.status" class="flex items-center justify-between gap-3 px-5 py-3.5">
                        <span class="flex items-center gap-2">
                            <Badge :variant="statusBadge[row.status]?.variant || 'neutral'" size="sm">{{ statusBadge[row.status]?.label || row.status }}</Badge>
                            <span class="text-tiny text-neutral-500">{{ row.count }}</span>
                        </span>
                        <b class="text-right text-body-sm text-primary-900">{{ money(row.amount) }}<small v-if="showBase" class="block font-normal text-tiny text-neutral-400">{{ moneyBase(row.amount) }}</small></b>
                    </div>
                    <div v-if="!statuses.length" class="px-5 py-10 text-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
                </div>
                <div class="border-t border-neutral-200 px-5 py-3 text-tiny text-neutral-500">
                    {{ $t('reports360.outstandingAging.average') }} <b class="ml-1 text-primary-900">{{ money(summary.average_balance) }}</b><small v-if="showBase" class="ml-1 text-neutral-400">({{ moneyBase(summary.average_balance) }})</small>
                </div>
            </Card>
        </div>

        <Card class="mt-4" :padding="false">
            <div class="flex flex-col gap-3 border-b border-neutral-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.outstandingAging.collectionList') }}</h2>
                    <p class="mt-0.5 text-tiny text-neutral-500">{{ filteredRows.length }} {{ $t('reports360.outstandingAging.accounts') }}</p>
                </div>
                <div class="flex flex-wrap gap-1.5 print:hidden">
                    <button
                        v-for="key in ['all', 'not_due', '1_7', '8_30', '31_60', '61_plus']"
                        :key="key"
                        type="button"
                        class="rounded-md border px-2.5 py-1.5 text-tiny font-semibold transition"
                        :class="activeBucket === key ? 'border-accent-600 bg-accent-50 text-accent-700' : 'border-neutral-200 bg-white text-neutral-600 hover:bg-neutral-50'"
                        @click="activeBucket = key"
                    >
                        {{ bucketLabels[key] }}
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50 text-left text-label text-neutral-600">
                        <tr>
                            <th class="px-5 py-3">{{ $t('reports360.outstandingAging.guest') }}</th>
                            <th class="px-4 py-3">{{ $t('reports360.outstandingAging.stay') }}</th>
                            <th class="px-4 py-3">{{ $t('reports360.channel') }}</th>
                            <th class="px-4 py-3">{{ $t('reports360.outstandingAging.due') }}</th>
                            <th class="px-4 py-3">{{ $t('reports360.outstandingAging.age') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.outstandingAging.gross') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.outstandingAging.paid') }}</th>
                            <th class="px-5 py-3 text-right">{{ $t('reports360.outstandingAging.balance') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in filteredRows" :key="row.id" class="hover:bg-neutral-50">
                            <td class="px-5 py-3">
                                <Link v-if="canViewReservations" :href="route('reservations.show', row.id)" class="text-body-sm font-medium text-primary-900 hover:underline">{{ row.guest }}</Link>
                                <span v-else class="text-body-sm font-medium text-primary-900">{{ row.guest }}</span>
                                <p v-if="row.phone" class="text-tiny text-neutral-400">{{ row.phone }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <p class="text-body-sm text-neutral-700">{{ row.room || '—' }}</p>
                                <Badge :variant="statusBadge[row.status]?.variant || 'neutral'" size="sm">{{ statusBadge[row.status]?.label || row.status }}</Badge>
                            </td>
                            <td class="px-4 py-3 text-body-sm text-neutral-600">
                                <span class="inline-flex items-center gap-1.5"><i class="h-2 w-2 rounded-full" :style="{ backgroundColor: channelMeta(row.channel).color }" />{{ channelMeta(row.channel).label }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-body-sm text-neutral-600">{{ fmt(row.due_date) }}</td>
                            <td class="px-4 py-3">
                                <Badge :variant="row.days_overdue > 30 ? 'error' : row.days_overdue > 0 ? 'warning' : 'info'" size="sm">
                                    {{ row.days_overdue ? `${row.days_overdue} ${$t('reports360.outstandingAging.days')}` : $t('reports360.outstandingAging.notDue') }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-700">{{ money(row.gross) }}<small v-if="showBase" class="block text-tiny text-neutral-400">{{ moneyBase(row.gross) }}</small></td>
                            <td class="px-4 py-3 text-right text-body-sm text-success-700">{{ money(row.paid) }}<small v-if="showBase" class="block text-tiny text-neutral-400">{{ moneyBase(row.paid) }}</small></td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold text-error-700">{{ money(row.balance) }}<small v-if="showBase" class="block font-normal text-tiny text-neutral-400">{{ moneyBase(row.balance) }}</small></td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!filteredRows.length" class="px-5 py-12 text-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
            </div>
        </Card>
    </ReportShell>
</template>
