<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { getIntlLocale, translate } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import { Crown, RefreshCcw, UserRoundCheck, Users } from 'lucide-vue-next';

const props = defineProps({ analytics: { type: Object, default: () => ({}) }, canViewGuests: Boolean, currency: { type: String, default: '€' } });
const summary = computed(() => props.analytics.summary || {});
const rows = computed(() => props.analytics.guests || []);
const active = computed(() => props.analytics.active_segment || 'all');
const money = (value) => `${props.currency}${Number(value || 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const number = (value, digits = 0) => Number(value || 0).toLocaleString(getIntlLocale(), { maximumFractionDigits: digits });
const fmtDate = (value) => value ? new Date(`${value}T00:00:00`).toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
const tabs = ['all', 'vip', 'loyal', 'returning', 'new', 'dormant'];
const tabHref = (segment) => route('reports.guestSegments', { segment });
const variant = (segment) => ({ vip: 'success', loyal: 'info', returning: 'accent', new: 'neutral', dormant: 'warning' }[segment] || 'neutral');
const kpis = computed(() => [
    { label: translate('reports360.guestSegments.total'), value: number(summary.value.total_guests), tone: 'neutral', icon: Users },
    { label: translate('reports360.guestSegments.active'), value: number(summary.value.active_guests), tone: 'success', icon: UserRoundCheck, detail: translate('reports360.guestSegments.last365') },
    { label: translate('reports360.guestSegments.vip'), value: number(summary.value.vip_guests), tone: 'accent', icon: Crown, detail: `${translate('reports360.guestSegments.threshold')} ${money(summary.value.vip_threshold)}` },
    { label: translate('reports360.guestSegments.dormant'), value: number(summary.value.dormant_guests), tone: Number(summary.value.dormant_guests) ? 'warning' : 'success', icon: RefreshCcw },
]);
const segmentBars = computed(() => (props.analytics.segments || []).map((segment) => ({ key: segment.key, label: translate(`reports360.guestSegments.segments.${segment.key}`), value: segment.guests, display: `${number(segment.guests)} · ${number(segment.value_share, 1)}%`, barClass: segment.key === 'vip' ? 'bg-accent-500' : segment.key === 'dormant' ? 'bg-warning-500' : 'bg-success-500' })));
</script>

<template>
    <ReportShell :title="$t('reports360.guestSegments.title')" :filters="null" :description="$t('reports360.guestSegments.short')" :category="$t('reports360.guestSegments.category')">
        <ReportKpiGrid :items="kpis" />
        <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.3fr)_minmax(300px,0.7fr)]">
            <Card :padding="false"><div class="border-b border-neutral-200 px-5 py-4"><h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.guestSegments.control') }}</h2></div><div class="grid grid-cols-2 gap-px bg-neutral-200 sm:grid-cols-4"><div class="bg-white px-5 py-5"><p class="text-tiny text-neutral-500">{{ $t('reports360.guestSegments.value') }}</p><p class="mt-1 text-title font-semibold text-primary-900">{{ money(summary.segmented_value) }}</p></div><div class="bg-white px-5 py-5"><p class="text-tiny text-neutral-500">VIP</p><p class="mt-1 text-title font-semibold text-accent-700">{{ summary.vip_guests || 0 }}</p></div><div class="bg-white px-5 py-5"><p class="text-tiny text-neutral-500">{{ $t('reports360.guestSegments.active') }}</p><p class="mt-1 text-title font-semibold text-success-700">{{ summary.active_guests || 0 }}</p></div><div class="bg-white px-5 py-5"><p class="text-tiny text-neutral-500">{{ $t('reports360.guestSegments.dormant') }}</p><p class="mt-1 text-title font-semibold text-warning-700">{{ summary.dormant_guests || 0 }}</p></div></div></Card>
            <ReportBarList :title="$t('reports360.guestSegments.mix')" :rows="segmentBars" />
        </div>

        <Card class="mt-4" :padding="false"><div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4"><h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.guestSegments.guestList') }}</h2><div class="flex flex-wrap gap-1 rounded-lg bg-neutral-100 p-1"><Link v-for="tab in tabs" :key="tab" :href="tabHref(tab)" preserve-scroll class="rounded-md px-3 py-1.5 text-tiny font-semibold" :class="active === tab ? 'bg-white text-primary-900 shadow-sm' : 'text-neutral-500'">{{ $t(`reports360.guestSegments.segments.${tab}`) }}</Link></div></div><div class="overflow-x-auto"><table class="min-w-full divide-y divide-neutral-200"><thead class="bg-neutral-50 text-label text-neutral-600"><tr><th class="px-5 py-3 text-left">{{ $t('reports360.guestSegments.guest') }}</th><th class="px-4 py-3 text-left">{{ $t('reports360.guestSegments.segment') }}</th><th class="px-4 py-3 text-right">{{ $t('reports360.guestSegments.stays') }}</th><th class="px-4 py-3 text-right">LTV</th><th class="px-4 py-3 text-right">{{ $t('reports360.guestSegments.lastVisit') }}</th><th class="px-5 py-3 text-left">{{ $t('reports360.guestSegments.action') }}</th></tr></thead><tbody class="divide-y divide-neutral-100"><tr v-for="row in rows" :key="row.id" class="hover:bg-neutral-50"><td class="px-5 py-3"><Link v-if="canViewGuests" :href="route('guests.show', row.id)" class="text-body-sm font-semibold text-primary-900 hover:underline">{{ row.guest }}</Link><p v-else class="text-body-sm font-semibold text-primary-900">{{ row.guest }}</p><p v-if="row.email" class="text-tiny text-neutral-500">{{ row.email }}</p></td><td class="px-4 py-3"><Badge :variant="variant(row.segment_360)">{{ $t(`reports360.guestSegments.segments.${row.segment_360}`) }}</Badge></td><td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ row.stays }}</td><td class="px-4 py-3 text-right text-body-sm font-semibold text-primary-900">{{ money(row.net_value) }}</td><td class="px-4 py-3 text-right"><p class="text-body-sm text-neutral-700">{{ fmtDate(row.last_visit) }}</p><p class="text-tiny text-neutral-500">{{ row.days_since_last }} {{ $t('reports360.guestSegments.days') }}</p></td><td class="px-5 py-3 text-body-sm text-neutral-700">{{ $t(`reports360.guestSegments.actions.${row.next_action}`) }}</td></tr></tbody></table></div><div v-if="!rows.length" class="px-6 py-12 text-center text-body-sm text-neutral-500">{{ $t('reports360.noData') }}</div></Card>
    </ReportShell>
</template>
