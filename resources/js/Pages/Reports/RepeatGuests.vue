<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { getIntlLocale, translate } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import { CirclePercent, Crown, RefreshCcw, WalletCards } from 'lucide-vue-next';

const props = defineProps({ analytics: { type: Object, default: () => ({}) }, canViewGuests: Boolean, currency: { type: String, default: '€' } });
const summary = computed(() => props.analytics.summary || {});
const rows = computed(() => props.analytics.guests || []);
const money = (value) => `${props.currency}${Number(value || 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const number = (value, digits = 0) => Number(value || 0).toLocaleString(getIntlLocale(), { maximumFractionDigits: digits });
const fmtDate = (value) => value ? new Date(`${value}T00:00:00`).toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
const segmentVariant = (segment) => ({ loyal: 'success', returning: 'info', one_time: 'neutral' }[segment] || 'neutral');
const kpis = computed(() => [
    { label: translate('reports360.guestLtv.netLtv'), value: money(summary.value.net_lifetime_value), tone: 'accent', icon: WalletCards, detail: `${translate('reports360.guestLtv.average')} ${money(summary.value.average_ltv)}` },
    { label: translate('reports360.guestLtv.repeatGuests'), value: number(summary.value.repeat_guests), tone: 'success', icon: RefreshCcw, detail: `${number(summary.value.repeat_rate, 1)}%` },
    { label: translate('reports360.guestLtv.loyalGuests'), value: number(summary.value.loyal_guests), tone: 'info', icon: Crown, detail: translate('reports360.guestLtv.threePlus') },
    { label: translate('reports360.guestLtv.repeatShare'), value: `${number(summary.value.repeat_value_share, 1)}%`, tone: 'success', icon: CirclePercent, detail: `${translate('reports360.guestLtv.upcoming')} ${money(summary.value.upcoming_value)}` },
]);
const segmentBars = computed(() => (props.analytics.segments || []).map((segment) => ({
    key: segment.key,
    label: translate(`reports360.guestLtv.segments.${segment.key}`),
    value: segment.guests,
    display: `${number(segment.guests)} · ${money(segment.net_value)}`,
    barClass: segment.key === 'loyal' ? 'bg-success-500' : segment.key === 'returning' ? 'bg-info-500' : 'bg-neutral-400',
})));
</script>

<template>
    <ReportShell :title="$t('reports360.guestLtv.title')" :filters="null" :description="$t('reports360.guestLtv.short')" :category="$t('reports360.guestLtv.category')">
        <ReportKpiGrid :items="kpis" />

        <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.35fr)_minmax(300px,0.65fr)]">
            <Card :padding="false">
                <div class="border-b border-neutral-200 px-5 py-4"><h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.guestLtv.valueControl') }}</h2></div>
                <div class="grid grid-cols-2 gap-px bg-neutral-200 sm:grid-cols-4">
                    <div class="bg-white px-5 py-5"><p class="text-tiny text-neutral-500">{{ $t('reports360.guestLtv.totalGuests') }}</p><p class="mt-1 text-title font-semibold text-primary-900">{{ number(summary.total_guests) }}</p></div>
                    <div class="bg-white px-5 py-5"><p class="text-tiny text-neutral-500">{{ $t('reports360.guestLtv.repeatRate') }}</p><p class="mt-1 text-title font-semibold text-success-700">{{ number(summary.repeat_rate, 1) }}%</p></div>
                    <div class="bg-white px-5 py-5"><p class="text-tiny text-neutral-500">{{ $t('reports360.guestLtv.average') }}</p><p class="mt-1 text-title font-semibold text-primary-900">{{ money(summary.average_ltv) }}</p></div>
                    <div class="bg-white px-5 py-5"><p class="text-tiny text-neutral-500">{{ $t('reports360.guestLtv.upcomingValue') }}</p><p class="mt-1 text-title font-semibold text-info-700">{{ money(summary.upcoming_value) }}</p></div>
                </div>
            </Card>
            <ReportBarList :title="$t('reports360.guestLtv.segmentMix')" :rows="segmentBars" />
        </div>

        <Card class="mt-4" :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4"><h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.guestLtv.guestValue') }}</h2></div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50 text-label text-neutral-600"><tr><th class="px-5 py-3 text-left">{{ $t('reports360.guestLtv.guest') }}</th><th class="px-4 py-3 text-left">{{ $t('reports360.guestLtv.segment') }}</th><th class="px-4 py-3 text-right">{{ $t('reports360.guestLtv.stays') }}</th><th class="px-4 py-3 text-right">{{ $t('reports360.guestLtv.nights') }}</th><th class="px-4 py-3 text-right">{{ $t('reports360.guestLtv.netValue') }}</th><th class="px-4 py-3 text-right">{{ $t('reports360.guestLtv.avgStay') }}</th><th class="px-5 py-3 text-right">{{ $t('reports360.guestLtv.lastVisit') }}</th></tr></thead>
                    <tbody class="divide-y divide-neutral-100"><tr v-for="row in rows" :key="row.id" class="hover:bg-neutral-50"><td class="px-5 py-3"><Link v-if="canViewGuests" :href="route('guests.show', row.id)" class="text-body-sm font-semibold text-primary-900 hover:underline">{{ row.guest }}</Link><p v-else class="text-body-sm font-semibold text-primary-900">{{ row.guest }}</p><p v-if="row.email" class="text-tiny text-neutral-500">{{ row.email }}</p></td><td class="px-4 py-3"><Badge :variant="segmentVariant(row.segment)">{{ $t(`reports360.guestLtv.segments.${row.segment}`) }}</Badge></td><td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ row.stays }}</td><td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ row.nights }}</td><td class="px-4 py-3 text-right text-body-sm font-semibold text-primary-900">{{ money(row.net_value) }}</td><td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ money(row.average_stay_value) }}</td><td class="px-5 py-3 text-right"><p class="text-body-sm text-neutral-700">{{ fmtDate(row.last_visit) }}</p><p v-if="row.upcoming_stays" class="text-tiny text-success-700">{{ row.upcoming_stays }} {{ $t('reports360.guestLtv.upcomingStay') }}</p></td></tr></tbody>
                </table>
            </div>
            <div v-if="!rows.length" class="px-6 py-12 text-center text-body-sm text-neutral-500">{{ $t('reports360.noData') }}</div>
        </Card>
    </ReportShell>
</template>
