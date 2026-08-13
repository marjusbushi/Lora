<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { getIntlLocale, translate } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import InfoTip from '@/Components/UI/InfoTip.vue';
import { AlertTriangle, BrushCleaning, CircleCheckBig, Wrench } from 'lucide-vue-next';

const props = defineProps({ analytics: { type: Object, default: () => ({}) }, permissions: { type: Object, default: () => ({}) }, currency: String });
const summary = computed(() => props.analytics.summary || {});
const rows = computed(() => props.analytics.rooms || []);
const active = ref('all');
const attentionStates = ['unassigned', 'maintenance', 'cleaning_for_arrival', 'turnover', 'occupied'];
const filteredRows = computed(() => rows.value.filter((row) => {
    if (active.value === 'arrivals') return Boolean(row.arrival);
    if (active.value === 'attention') return attentionStates.includes(row.state);
    return true;
}));
const number = (value, digits = 0) => Number(value || 0).toLocaleString(getIntlLocale(), { maximumFractionDigits: digits });
const pct = (value) => `${number(value, 1)}%`;
const label = (state) => translate(`reports360.roomReadiness.states.${state}`);
const stateVariant = (state) => ({ ready: 'success', available: 'success', checked_in: 'info', cleaning: 'warning', cleaning_for_arrival: 'warning', turnover: 'warning', departure_pending: 'warning', maintenance: 'error', occupied: 'error', unassigned: 'error' }[state] || 'neutral');
const stateBars = computed(() => (props.analytics.states || []).map((row) => ({ key: row.key, label: label(row.key), value: row.value, display: number(row.value), barClass: stateVariant(row.key) === 'error' ? 'bg-error-500' : stateVariant(row.key) === 'warning' ? 'bg-warning-500' : stateVariant(row.key) === 'success' ? 'bg-success-500' : 'bg-info-500' })));
const kpis = computed(() => [
    { label: translate('reports360.roomReadiness.readyArrivals'), help: translate('reports360.help.rrReadyArrivals'), value: `${number(summary.value.ready_arrivals)} / ${number(summary.value.arrivals_remaining)}`, tone: Number(summary.value.ready_rate || 0) >= 90 ? 'success' : 'warning', icon: CircleCheckBig, detail: pct(summary.value.ready_rate) },
    { label: translate('reports360.roomReadiness.attention'), help: translate('reports360.help.rrAttention'), value: number(summary.value.attention), tone: Number(summary.value.attention || 0) ? 'error' : 'success', icon: AlertTriangle },
    { label: translate('reports360.roomReadiness.cleaning'), help: translate('reports360.help.rrCleaning'), value: number(summary.value.cleaning), tone: 'warning', icon: BrushCleaning, detail: `${number(summary.value.turnovers)} ${translate('reports360.roomReadiness.turnovers')}` },
    { label: translate('reports360.roomReadiness.maintenance'), help: translate('reports360.help.rrMaintenanceBlocked'), value: number(summary.value.maintenance), tone: 'error', icon: Wrench },
]);
const movementHref = (movement) => props.permissions.reservations && movement ? route('reservations.show', movement.id) : null;
const cleaningHref = (task) => props.permissions.housekeeping && task ? route('housekeeping.clean', task.id) : null;
const time = (value) => value || '—';
const updatedAt = computed(() => props.analytics.as_of ? new Date(props.analytics.as_of).toLocaleTimeString(getIntlLocale(), { hour: '2-digit', minute: '2-digit' }) : '—');
</script>

<template>
    <ReportShell :title="$t('reports360.roomReadiness.title')" :filters="null" :description="$t('reports360.roomReadiness.short')" :category="$t('reports360.roomReadiness.category')">
        <ReportKpiGrid :items="kpis" />
        <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.35fr)_minmax(300px,0.65fr)]">
            <Card :padding="false"><div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4"><h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.roomReadiness.control') }}</h2><span class="text-tiny text-neutral-500">{{ $t('reports360.roomReadiness.updated') }} {{ updatedAt }}</span></div><div class="grid grid-cols-2 gap-px bg-neutral-200 sm:grid-cols-4"><div class="bg-white px-5 py-5"><p class="text-tiny text-neutral-500">{{ $t('reports360.roomReadiness.totalRooms') }}</p><p class="mt-1 text-title font-semibold text-primary-900">{{ summary.total_rooms || 0 }}</p></div><div class="bg-white px-5 py-5"><p class="text-tiny text-neutral-500">{{ $t('reports360.roomReadiness.arrivals') }}</p><p class="mt-1 text-title font-semibold text-primary-900">{{ summary.arrivals_remaining || 0 }}</p></div><div class="bg-white px-5 py-5"><p class="text-tiny text-neutral-500">{{ $t('reports360.roomReadiness.turnoversLabel') }}</p><p class="mt-1 text-title font-semibold text-primary-900">{{ summary.turnovers || 0 }}</p></div><div class="bg-white px-5 py-5"><p class="text-tiny text-neutral-500">{{ $t('reports360.roomReadiness.readyRate') }}</p><p class="mt-1 text-title font-semibold text-primary-900">{{ pct(summary.ready_rate) }}</p></div></div></Card>
            <ReportBarList :title="$t('reports360.roomReadiness.byState')" :rows="stateBars" />
        </div>

        <Card class="mt-4" :padding="false"><div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4"><h2 class="flex items-center gap-1 text-body font-semibold text-primary-900">{{ $t('reports360.roomReadiness.roomBoard') }}<InfoTip :text="$t('reports360.help.rrBoard')" :label="$t('reports360.roomReadiness.roomBoard')" /></h2><div class="flex gap-1 rounded-lg bg-neutral-100 p-1"><button v-for="tab in ['all', 'arrivals', 'attention']" :key="tab" type="button" class="rounded-md px-3 py-1.5 text-tiny font-semibold" :class="active === tab ? 'bg-white text-primary-900 shadow-sm' : 'text-neutral-500'" @click="active = tab">{{ $t(`reports360.roomReadiness.tabs.${tab}`) }}</button></div></div><div class="overflow-x-auto"><table class="min-w-full divide-y divide-neutral-200"><thead class="bg-neutral-50 text-label text-neutral-600"><tr><th class="px-5 py-3 text-left">{{ $t('reports360.roomReadiness.room') }}</th><th class="px-4 py-3 text-left">{{ $t('reports360.roomReadiness.readiness') }}</th><th class="px-4 py-3 text-left">{{ $t('reports360.roomReadiness.arrival') }}</th><th class="px-4 py-3 text-left">{{ $t('reports360.roomReadiness.departure') }}</th><th class="px-5 py-3 text-left">{{ $t('reports360.roomReadiness.task') }}</th></tr></thead>
            <tbody class="divide-y divide-neutral-100"><tr v-for="row in filteredRows" :key="row.key" class="hover:bg-neutral-50"><td class="px-5 py-3"><p class="text-body-sm font-semibold text-primary-900">{{ row.room_number || $t('reports360.roomReadiness.unassignedRoom') }}</p><p class="text-tiny text-neutral-500">{{ row.room_type || '—' }}</p></td><td class="px-4 py-3"><Badge :variant="stateVariant(row.state)">{{ label(row.state) }}</Badge></td><td class="px-4 py-3"><template v-if="row.arrival"><Link v-if="movementHref(row.arrival)" :href="movementHref(row.arrival)" class="text-body-sm font-medium text-primary-900 hover:underline">{{ row.arrival.guest || `#${row.arrival.id}` }}</Link><p v-else class="text-body-sm text-primary-900">#{{ row.arrival.id }}</p><p class="text-tiny text-neutral-500">{{ time(row.arrival.time) }}</p></template><span v-else class="text-body-sm text-neutral-400">—</span></td><td class="px-4 py-3"><template v-if="row.departure"><Link v-if="movementHref(row.departure)" :href="movementHref(row.departure)" class="text-body-sm font-medium text-primary-900 hover:underline">{{ row.departure.guest || `#${row.departure.id}` }}</Link><p v-else class="text-body-sm text-primary-900">#{{ row.departure.id }}</p><p class="text-tiny text-neutral-500">{{ time(row.departure.time) }}</p></template><span v-else class="text-body-sm text-neutral-400">—</span></td><td class="px-5 py-3"><template v-if="row.cleaning"><Link v-if="cleaningHref(row.cleaning)" :href="cleaningHref(row.cleaning)" class="text-body-sm font-medium text-primary-900 hover:underline">{{ row.cleaning.assignee || $t('reports360.roomReadiness.unassignedStaff') }}</Link><p v-else class="text-body-sm text-primary-900">{{ row.cleaning.assignee || $t('reports360.roomReadiness.cleaning') }}</p><p class="text-tiny text-neutral-500">{{ row.cleaning.status }}</p></template><span v-else class="text-body-sm text-neutral-400">—</span></td></tr></tbody></table></div><div v-if="!filteredRows.length" class="px-6 py-12 text-center text-body-sm text-neutral-500">{{ $t('reports360.noData') }}</div></Card>
    </ReportShell>
</template>
