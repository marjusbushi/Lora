<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { getIntlLocale, translate } from '@/i18n';
import { channelMeta } from '@/channels';
import { useReportCurrency } from '@/composables/useReportCurrency';
import ReportShell from '@/Components/UI/ReportShell.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import InfoTip from '@/Components/UI/InfoTip.vue';
import { AlertTriangle, CalendarClock, CircleDollarSign, Gauge, RotateCcw } from 'lucide-vue-next';

const props = defineProps({
    analytics: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    canViewReservations: { type: Boolean, default: false },
    currency: { type: String, default: '€' },
    pricingCurrency: { type: String, default: '' },
    baseToPricingRate: { type: Number, default: null },
});

const summary = computed(() => props.analytics.summary || {});
const allRows = computed(() => props.analytics.rows || []);

const asOf = ref(props.filters?.as_of || '');
const arrivalFrom = ref(props.filters?.arrival_from || '');
const arrivalTo = ref(props.filters?.arrival_to || '');
const page = usePage();
const filterErrors = computed(() => {
    const errors = page.props.errors || {};
    return [errors.as_of, errors.arrival_from, errors.arrival_to].filter(Boolean);
});
const isHistorical = computed(() => Boolean(props.filters?.as_of));
const applying = ref(false);

function applyFilters() {
    applying.value = true;
    const params = {};
    if (asOf.value) params.as_of = asOf.value;
    if (arrivalFrom.value) params.arrival_from = arrivalFrom.value;
    if (arrivalTo.value) params.arrival_to = arrivalTo.value;
    router.get(route('reports.outstanding'), params, {
        preserveState: true, preserveScroll: true, replace: true,
        onFinish: () => { applying.value = false; },
    });
}

function resetFilters() {
    asOf.value = '';
    arrivalFrom.value = '';
    arrivalTo.value = '';
    applyFilters();
}

const { pricingCode, displayRate, showBase, money, moneyBase } = useReportCurrency(props);
const baseLine = (value) => (showBase.value ? moneyBase(value) : null);
const pct = (value) => `${Number(value ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
const fmt = (date) => date ? new Date(`${date}T00:00:00`).toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

const t9 = (key, params) => translate(`reports360.outstandingAging.${key}`, params);

// Table filter: one control for both state chips and aging bars.
const activeFilter = ref({ type: 'all', key: 'all' });
const dueRows = computed(() => allRows.value.filter((row) => row.state === 'due'));
const exposureRows = computed(() => allRows.value.filter((row) => row.state === 'future_arrival' || row.state === 'arriving_today'));
const filteredRows = computed(() => {
    const f = activeFilter.value;
    if (f.type === 'state') return allRows.value.filter((row) => row.state === f.key);
    if (f.type === 'bucket') {
        return dueRows.value.filter((row) => f.key === 'due_today' ? row.days_overdue === 0 : row.bucket === f.key);
    }
    return allRows.value;
});
function setFilter(type, key) {
    activeFilter.value = activeFilter.value.type === type && activeFilter.value.key === key
        ? { type: 'all', key: 'all' }
        : { type, key };
}
const isActive = (type, key) => activeFilter.value.type === type && activeFilter.value.key === key;

const stateChips = computed(() => [
    { key: 'all', type: 'all', label: t9('all'), count: allRows.value.length },
    { key: 'future_arrival', type: 'state', label: t9('stateFuture'), count: allRows.value.filter((r) => r.state === 'future_arrival').length },
    { key: 'arriving_today', type: 'state', label: t9('stateToday'), count: allRows.value.filter((r) => r.state === 'arriving_today').length },
    { key: 'in_house', type: 'state', label: t9('stateInHouse'), count: allRows.value.filter((r) => r.state === 'in_house').length },
    { key: 'due', type: 'state', label: t9('stateDue'), count: dueRows.value.length },
]);

// Aging bars over REAL due rows only — a future arrival has no age.
const agingBars = computed(() => {
    const defs = [
        { key: 'due_today', label: t9('dueToday'), match: (r) => r.days_overdue === 0, barClass: 'bg-warning-400' },
        { key: '1_7', label: t9('days1to7'), match: (r) => r.bucket === '1_7', barClass: 'bg-warning-400' },
        { key: '8_30', label: t9('days8to30'), match: (r) => r.bucket === '8_30', barClass: 'bg-warning-400' },
        { key: '31_60', label: t9('days31to60'), match: (r) => r.bucket === '31_60', barClass: 'bg-error-500' },
        { key: '61_plus', label: t9('days61plus'), match: (r) => r.bucket === '61_plus', barClass: 'bg-error-500' },
    ];
    const total = dueRows.value.reduce((sum, r) => sum + Number(r.balance || 0), 0);
    return defs.map((def) => {
        const rows = dueRows.value.filter(def.match);
        const amount = rows.reduce((sum, r) => sum + Number(r.balance || 0), 0);
        return { ...def, count: rows.length, amount, share: total > 0 ? amount / total * 100 : 0 };
    });
});

// Exposure grouped by how soon the guest arrives (days from as-of).
const arrivalGroups = computed(() => {
    const ref = props.filters?.as_of ? new Date(`${props.filters.as_of}T00:00:00`) : new Date(new Date().toDateString());
    const daysUntil = (row) => Math.round((new Date(`${row.check_in}T00:00:00`) - ref) / 86400000);
    const defs = [
        { key: 'a0_7', label: t9('arrWithin7') },
        { key: 'a8_30', label: t9('arr8to30') },
        { key: 'a31', label: t9('arr31plus') },
    ];
    const pick = (row) => { const d = daysUntil(row); return d <= 7 ? 'a0_7' : d <= 30 ? 'a8_30' : 'a31'; };
    return defs.map((def) => {
        const rows = exposureRows.value.filter((row) => pick(row) === def.key);
        return { ...def, count: rows.length, amount: rows.reduce((sum, r) => sum + Number(r.balance || 0), 0) };
    });
});

const statePill = {
    future_arrival: { variant: 'info', label: () => t9('stateFuture') },
    arriving_today: { variant: 'warning', label: () => t9('stateToday') },
    in_house: { variant: 'success', label: () => t9('stateInHouse') },
};

const statusBadge = {
    confirmed: { variant: 'info', label: translate('reports360.outstandingAging.status.confirmed') },
    checked_in: { variant: 'success', label: translate('reports360.outstandingAging.status.checked_in') },
    checked_out: { variant: 'neutral', label: translate('reports360.outstandingAging.status.checked_out') },
};

const kpis = computed(() => [
    {
        label: t9('realDebt'),
        help: translate('reports360.help.oaRealDebt'),
        value: money(summary.value.real_total),
        subvalue: baseLine(summary.value.real_total),
        tone: summary.value.real_total ? 'error' : 'success',
        icon: CircleDollarSign,
        detail: summary.value.overdue_total
            ? `${t9('overdue')}: ${money(summary.value.overdue_total)}`
            : `${summary.value.real_count || 0} ${t9('accounts')}`,
    },
    {
        label: t9('exposure'),
        help: translate('reports360.help.oaExposure'),
        value: money(summary.value.exposure_total),
        subvalue: baseLine(summary.value.exposure_total),
        tone: 'info',
        icon: CalendarClock,
        detail: `${summary.value.exposure_count || 0} ${t9('reservationsWord')}`,
    },
    {
        label: t9('collectionRate'),
        help: translate('reports360.help.oaCollectionRate'),
        value: pct(summary.value.collection_rate),
        tone: summary.value.collection_rate >= 80 ? 'success' : 'warning',
        icon: Gauge,
        detail: `${money(summary.value.paid)} / ${money(summary.value.gross)}`,
    },
    {
        label: t9('critical'),
        help: translate('reports360.help.oaCritical'),
        value: money(summary.value.critical_total),
        subvalue: baseLine(summary.value.critical_total),
        tone: summary.value.critical_total ? 'error' : 'success',
        icon: AlertTriangle,
        detail: `${summary.value.critical_count || 0} ${t9('accounts')}`,
    },
]);
</script>

<template>
    <ReportShell
        :title="$t('reports360.outstandingAging.title')"
        :description="$t('reports360.outstandingAging.short')"
        :category="$t('reports360.outstandingAging.category')"
    >
        <Card class="mb-4 print:hidden">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-[170px] flex-1 sm:flex-none">
                    <label class="mb-1.5 flex items-center gap-1 text-label text-neutral-600">{{ t9('asOfLabel') }}<InfoTip :text="$t('reports360.help.oaAsOf')" :label="t9('asOfLabel')" /></label>
                    <DatePicker v-model="asOf" />
                </div>
                <div class="min-w-[170px] flex-1 sm:flex-none">
                    <label class="mb-1.5 flex items-center gap-1 text-label text-neutral-600">{{ t9('arrivalFromLabel') }}<InfoTip :text="$t('reports360.help.oaArrivalWindow')" :label="t9('arrivalFromLabel')" /></label>
                    <DatePicker v-model="arrivalFrom" />
                </div>
                <div class="min-w-[170px] flex-1 sm:flex-none">
                    <label class="mb-1.5 block text-label text-neutral-600">{{ t9('arrivalToLabel') }}</label>
                    <DatePicker v-model="arrivalTo" />
                </div>
                <Button variant="primary" :disabled="applying" @click="applyFilters">{{ t9('applyFilters') }}</Button>
                <Button variant="ghost" :disabled="applying" @click="resetFilters">
                    <RotateCcw class="h-4 w-4" />
{{ t9('resetFilters') }} </Button>
            </div>
            <p v-for="error in filterErrors" :key="error" class="mt-2 text-tiny text-error-700">{{ error }}</p>
            <p v-if="isHistorical" class="mt-3 text-tiny text-warning-700">{{ t9('asOfNote', { date: fmt(filters.as_of) }) }}</p>
        </Card>

        <ReportKpiGrid :items="kpis" />
        <p v-if="displayRate" class="mt-2 text-tiny text-neutral-500">{{ $t('reports360.amountsShownIn', { currency: pricingCode }) }}</p>

        <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.55fr)_minmax(300px,0.65fr)]">
            <Card :padding="false">
                <div class="border-b border-neutral-200 px-5 py-4">
                    <h2 class="flex items-center gap-1 text-body font-semibold text-primary-900">{{ $t('reports360.outstandingAging.aging') }}<InfoTip :text="$t('reports360.help.oaAging')" :label="$t('reports360.outstandingAging.aging')" /></h2>
                    <p class="mt-0.5 text-tiny text-neutral-500">{{ t9('agingDueOnly') }}</p>
                </div>
                <div class="space-y-4 px-5 py-5">
                    <button
                        v-for="bar in agingBars"
                        :key="bar.key"
                        type="button"
                        class="block w-full text-left"
                        @click="setFilter('bucket', bar.key)"
                    >
                        <span class="mb-1.5 flex items-center justify-between gap-3 text-body-sm">
                            <span :class="isActive('bucket', bar.key) ? 'font-semibold text-accent-700' : 'font-medium text-primary-900'">{{ bar.label }}</span>
                            <span class="text-neutral-600">{{ bar.count }} · <b class="text-primary-900">{{ money(bar.amount) }}</b><small v-if="showBase" class="ml-1 text-neutral-400">({{ moneyBase(bar.amount) }})</small></span>
                        </span>
                        <span class="block h-2 overflow-hidden rounded-full bg-neutral-100">
                            <i class="block h-full rounded-full transition-all" :class="bar.barClass" :style="{ width: `${Math.max(bar.amount ? 2 : 0, bar.share || 0)}%` }" />
                        </span>
                    </button>
                    <p v-if="!dueRows.length" class="pt-1 text-center text-body-sm text-neutral-400">{{ t9('noDueDebt') }}</p>
                </div>
            </Card>

            <Card :padding="false">
                <div class="border-b border-neutral-200 px-5 py-4">
                    <h2 class="flex items-center gap-1 text-body font-semibold text-primary-900">{{ t9('arrivalProximity') }}<InfoTip :text="$t('reports360.help.oaExposure')" :label="t9('arrivalProximity')" /></h2>
                </div>
                <div class="divide-y divide-neutral-100">
                    <div v-for="group in arrivalGroups" :key="group.key" class="flex items-center justify-between gap-3 px-5 py-3.5">
                        <span class="flex items-center gap-2">
                            <span class="text-body-sm text-neutral-700">{{ group.label }}</span>
                            <span class="text-tiny text-neutral-500">{{ group.count }}</span>
                        </span>
                        <b class="text-right text-body-sm text-primary-900">{{ money(group.amount) }}<small v-if="showBase" class="block font-normal text-tiny text-neutral-400">{{ moneyBase(group.amount) }}</small></b>
                    </div>
                    <div v-if="!exposureRows.length" class="px-5 py-10 text-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
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
                        v-for="chip in stateChips"
                        :key="chip.key"
                        type="button"
                        class="rounded-md border px-2.5 py-1.5 text-tiny font-semibold transition"
                        :class="(chip.type === 'all' ? activeFilter.type === 'all' : isActive(chip.type, chip.key)) ? 'border-accent-600 bg-accent-50 text-accent-700' : 'border-neutral-200 bg-white text-neutral-600 hover:bg-neutral-50'"
                        @click="chip.type === 'all' ? activeFilter = { type: 'all', key: 'all' } : setFilter(chip.type, chip.key)"
                    >
                        {{ chip.label }} · {{ chip.count }}
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
                            <th class="px-4 py-3">{{ t9('stateColumn') }}</th>
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
                                <Badge v-if="row.state !== 'due'" :variant="statePill[row.state]?.variant || 'neutral'" size="sm">{{ statePill[row.state]?.label() || row.state }}</Badge>
                                <Badge v-else :variant="row.days_overdue > 30 ? 'error' : 'warning'" size="sm">
                                    {{ row.days_overdue ? `${row.days_overdue} ${$t('reports360.outstandingAging.days')}` : t9('dueToday') }}
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
