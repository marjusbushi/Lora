<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { getIntlLocale, translate } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import InfoTip from '@/Components/UI/InfoTip.vue';
import { useReportCurrency } from '@/composables/useReportCurrency';
import { BedDouble, ReceiptText, Utensils, Users } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    activeTab: { type: String, default: 'arrivals' },
    analytics: { type: Object, default: () => ({}) },
    currency: { type: String, default: '€' },
    pricingCurrency: { type: String, default: null },
    baseToPricingRate: { type: [Number, String], default: null },
});
const tabs = ['arrivals', 'departures', 'in_house'];
const rows = computed(() => props.analytics[props.activeTab] || []);
const summary = computed(() => props.analytics.summary?.[props.activeTab] || {});
const { money, moneyBase, showBase, displayRate, pricingCode } = useReportCurrency(props);
const fmt = (value) => value ? new Date(`${value}T00:00:00`).toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short' }) : '—';
const tabHref = (tab) => route('reports.guestMovements', { ...props.filters, tab });
const statusVariant = (status) => ({ pending: 'warning', confirmed: 'info', checked_in: 'success', checked_out: 'neutral' }[status] || 'neutral');
const statusLabel = (status) => translate(`reports360.guestMovements.statuses.${status}`);
const kpis = computed(() => [
    { label: translate(`reports360.guestMovements.kpis.${props.activeTab}`), value: summary.value.count || 0, tone: 'accent', icon: BedDouble },
    { label: translate('reports360.guestMovements.kpis.guests'), help: translate('reports360.help.gmPax'), value: summary.value.pax || 0, tone: 'info', icon: Users },
    { label: translate('reports360.guestMovements.kpis.nights'), help: translate('reports360.help.gmNights'), value: summary.value.nights || 0, tone: 'neutral', icon: BedDouble },
    props.activeTab === 'departures'
        ? { label: translate('reports360.guestMovements.kpis.openPos'), help: translate('reports360.help.gmOpenPos'), value: summary.value.open_pos || 0, tone: Number(summary.value.open_pos || 0) ? 'warning' : 'success', icon: Utensils, detail: showBase.value ? `${money(summary.value.balance)} (${moneyBase(summary.value.balance)})` : money(summary.value.balance) }
        : { label: translate('reports360.guestMovements.kpis.balance'), help: translate('reports360.help.gmBalance'), value: money(summary.value.balance), subvalue: showBase.value ? moneyBase(summary.value.balance) : null, tone: Number(summary.value.balance || 0) > 0 ? 'error' : 'success', icon: ReceiptText },
]);
</script>

<template>
    <ReportShell :title="$t('reports360.guestMovements.title')" route-name="reports.guestMovements" :query="{ tab: activeTab }" :filters="filters" :description="$t('reports360.guestMovements.short')" :category="$t('reports360.guestMovements.category')">
        <div class="mb-4 flex w-fit gap-1 rounded-lg bg-neutral-100 p-1 print:hidden"><Link v-for="tab in tabs" :key="tab" :href="tabHref(tab)" preserve-scroll class="rounded-md px-4 py-2 text-body-sm font-semibold no-underline transition" :class="activeTab === tab ? 'bg-white text-primary-900 shadow-sm' : 'text-neutral-500 hover:text-primary-900'">{{ $t(`reports360.guestMovements.tabs.${tab}`) }} <span class="ml-1 text-tiny">{{ analytics.summary?.[tab]?.count || 0 }}</span></Link></div>
        <ReportKpiGrid :items="kpis" />

        <Card class="mt-4" :padding="false"><div class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 px-5 py-4"><h2 class="flex items-center gap-1 text-body font-semibold text-primary-900">{{ $t(`reports360.guestMovements.tabs.${activeTab}`) }}<InfoTip v-if="activeTab === 'in_house'" :text="$t('reports360.help.gmInHouseLive')" :label="$t('reports360.guestMovements.tabs.in_house')" /></h2><span v-if="displayRate" class="text-tiny text-neutral-500">{{ $t('reports360.amountsShownIn', { currency: pricingCode }) }}</span></div><div class="overflow-x-auto"><table class="min-w-full divide-y divide-neutral-200"><thead class="bg-neutral-50 text-label text-neutral-600"><tr><th class="px-5 py-3 text-left">{{ $t('reports360.guestMovements.guest') }}</th><th class="px-4 py-3 text-left">{{ $t('reports360.guestMovements.room') }}</th><th class="px-4 py-3 text-left">{{ $t('reports360.guestMovements.period') }}</th><th class="px-4 py-3 text-left">{{ $t('reports360.guestMovements.status') }}</th><th class="px-4 py-3 text-right">{{ $t('reports360.guestMovements.pax') }}</th><th v-if="activeTab === 'departures'" class="px-4 py-3 text-right">POS</th><th class="px-5 py-3 text-right"><span class="inline-flex items-center gap-1">{{ $t('reports360.guestMovements.balance') }}<InfoTip :text="$t('reports360.help.gmBalance')" :label="$t('reports360.guestMovements.balance')" /></span></th></tr></thead>
            <tbody class="divide-y divide-neutral-100"><tr v-for="row in rows" :key="row.id" class="hover:bg-neutral-50"><td class="px-5 py-3"><Link :href="route('reservations.show', row.id)" class="text-body-sm font-semibold text-primary-900 hover:underline">{{ row.guest }}</Link><p v-if="row.phone" class="text-tiny text-neutral-500">{{ row.phone }}</p></td><td class="px-4 py-3"><p class="text-body-sm text-primary-900">{{ row.room || '—' }}</p><p class="text-tiny text-neutral-500">{{ row.room_type || '—' }}</p></td><td class="px-4 py-3 text-body-sm text-neutral-600"><span>{{ fmt(row.check_in) }}</span><span class="mx-1 text-neutral-300">→</span><span>{{ fmt(row.check_out) }}</span></td><td class="px-4 py-3"><Badge :variant="statusVariant(row.status)">{{ statusLabel(row.status) }}</Badge></td><td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ row.pax }}</td><td v-if="activeTab === 'departures'" class="px-4 py-3 text-right"><Badge v-if="row.open_pos_count" variant="warning">{{ row.open_pos_count }}</Badge><span v-else class="text-neutral-400">—</span></td><td class="px-5 py-3 text-right text-body-sm font-semibold" :class="Number(row.balance) > 0 ? 'text-error-600' : 'text-success-700'">{{ money(row.balance) }}<small v-if="showBase" class="block text-tiny font-normal text-neutral-400">{{ moneyBase(row.balance) }}</small></td></tr></tbody></table></div><div v-if="!rows.length" class="px-6 py-12 text-center text-body-sm text-neutral-500">{{ $t('reports360.noData') }}</div></Card>
    </ReportShell>
</template>
