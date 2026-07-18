<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { getIntlLocale, translate } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import { CircleCheckBig, LogIn, LogOut, TriangleAlert, Users, Wrench } from 'lucide-vue-next';

const props = defineProps({ analytics: { type: Object, default: () => ({}) }, permissions: { type: Object, default: () => ({}) }, currency: { type: String, default: '€' } });
const flow = computed(() => props.analytics.flow || {});
const readiness = computed(() => props.analytics.readiness || {});
const maintenance = computed(() => props.analytics.maintenance || {});
const actions = computed(() => props.analytics.actions || []);
const number = (value, digits = 0) => Number(value || 0).toLocaleString(getIntlLocale(), { maximumFractionDigits: digits });
const money = (value) => `${props.currency}${Number(value || 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const updatedAt = computed(() => props.analytics.as_of ? new Date(props.analytics.as_of).toLocaleTimeString(getIntlLocale(), { hour: '2-digit', minute: '2-digit' }) : '—');
const kpis = computed(() => [
    { label: translate('reports360.operationsExecutive.arrivals'), value: `${number(flow.value.arrivals_remaining)} / ${number(flow.value.arrivals_total)}`, tone: Number(flow.value.arrivals_remaining) ? 'warning' : 'success', icon: LogIn, detail: `${number(flow.value.arrivals_completed)} ${translate('reports360.operationsExecutive.completed')}` },
    { label: translate('reports360.operationsExecutive.departures'), value: `${number(flow.value.departures_remaining)} / ${number(flow.value.departures_total)}`, tone: Number(flow.value.departures_remaining) ? 'warning' : 'success', icon: LogOut, detail: `${number(flow.value.departures_completed)} ${translate('reports360.operationsExecutive.completed')}` },
    { label: translate('reports360.operationsExecutive.inHouse'), value: number(flow.value.in_house_stays), tone: 'info', icon: Users, detail: `${number(flow.value.in_house_pax)} pax` },
    { label: translate('reports360.operationsExecutive.ready'), value: `${number(readiness.value.ready_rate, 1)}%`, tone: Number(readiness.value.ready_rate) >= 90 ? 'success' : 'warning', icon: CircleCheckBig, detail: `${number(readiness.value.ready_arrivals)} / ${number(readiness.value.arrivals_remaining)}` },
]);
const readinessBars = computed(() => [
    { key: 'ready', label: translate('reports360.operationsExecutive.readyRooms'), value: readiness.value.ready_arrivals || 0, display: number(readiness.value.ready_arrivals), barClass: 'bg-success-500' },
    { key: 'cleaning', label: translate('reports360.operationsExecutive.cleaning'), value: readiness.value.cleaning || 0, display: number(readiness.value.cleaning), barClass: 'bg-warning-500' },
    { key: 'turnovers', label: translate('reports360.operationsExecutive.turnovers'), value: readiness.value.turnovers || 0, display: number(readiness.value.turnovers), barClass: 'bg-info-500' },
    { key: 'maintenance', label: translate('reports360.operationsExecutive.blocked'), value: readiness.value.maintenance || 0, display: number(readiness.value.maintenance), barClass: 'bg-error-500' },
]);
const actionTitle = (action) => {
    if (action.kind === 'departure') return translate('reports360.operationsExecutive.actions.departure');
    if (action.kind === 'maintenance') return action.title || translate('reports360.operationsExecutive.actions.maintenance');
    return translate(`reports360.operationsExecutive.actions.${action.state}`);
};
const actionDetail = (action) => {
    const parts = [];
    if (action.room) parts.push(`${translate('reports360.operationsExecutive.room')} ${action.room}`);
    if (action.guest) parts.push(action.guest);
    if (action.balance > 0) parts.push(money(action.balance));
    if (action.open_pos_count > 0) parts.push(`${action.open_pos_count} POS`);
    return parts.join(' · ') || '—';
};
const actionHref = (action) => {
    if (action.reservation_id && props.permissions.reservations) return route('reservations.show', action.reservation_id);
    if (action.kind === 'maintenance' && props.permissions.maintenance) return route('maintenance.index', { issue_id: action.maintenance_id });
    if (action.kind === 'readiness') return route('reports.roomReadiness');
    return null;
};
</script>

<template>
    <ReportShell :title="$t('reports360.operationsExecutive.title')" :filters="null" :description="$t('reports360.operationsExecutive.short')" :category="$t('reports360.operationsExecutive.category')">
        <div class="mb-3 flex justify-end text-tiny text-neutral-500">{{ $t('reports360.operationsExecutive.updated') }} {{ updatedAt }}</div>
        <ReportKpiGrid :items="kpis" />

        <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.25fr)_minmax(300px,0.75fr)]">
            <Card :padding="false">
                <div class="border-b border-neutral-200 px-5 py-4"><h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.operationsExecutive.todayControl') }}</h2></div>
                <div class="grid grid-cols-2 gap-px bg-neutral-200 sm:grid-cols-4">
                    <div class="bg-white px-5 py-5"><p class="text-tiny text-neutral-500">{{ $t('reports360.operationsExecutive.attention') }}</p><p class="mt-1 text-title font-semibold text-error-600">{{ readiness.attention || 0 }}</p></div>
                    <div class="bg-white px-5 py-5"><p class="text-tiny text-neutral-500">{{ $t('reports360.operationsExecutive.openPos') }}</p><p class="mt-1 text-title font-semibold text-primary-900">{{ flow.open_pos || 0 }}</p></div>
                    <div class="bg-white px-5 py-5"><p class="text-tiny text-neutral-500">{{ $t('reports360.operationsExecutive.balance') }}</p><p class="mt-1 text-title font-semibold" :class="Number(flow.departure_balance) > 0 ? 'text-error-600' : 'text-success-700'">{{ money(flow.departure_balance) }}</p></div>
                    <div class="bg-white px-5 py-5"><p class="text-tiny text-neutral-500">{{ $t('reports360.operationsExecutive.overdue') }}</p><p class="mt-1 text-title font-semibold" :class="Number(maintenance.overdue) ? 'text-error-600' : 'text-success-700'">{{ maintenance.overdue || 0 }}</p></div>
                </div>
                <div class="flex flex-wrap gap-2 border-t border-neutral-200 px-5 py-4 print:hidden">
                    <Link :href="route('reports.guestMovements')" class="rounded-lg border border-neutral-200 px-3 py-2 text-body-sm font-semibold text-primary-900 hover:bg-neutral-50">{{ $t('reports360.guestMovements.title') }}</Link>
                    <Link :href="route('reports.roomReadiness')" class="rounded-lg border border-neutral-200 px-3 py-2 text-body-sm font-semibold text-primary-900 hover:bg-neutral-50">{{ $t('reports360.roomReadiness.title') }}</Link>
                    <Link :href="route('reports.maintenanceSla')" class="rounded-lg border border-neutral-200 px-3 py-2 text-body-sm font-semibold text-primary-900 hover:bg-neutral-50">{{ $t('reports360.maintenanceSla.title') }}</Link>
                </div>
            </Card>
            <ReportBarList :title="$t('reports360.operationsExecutive.readiness')" :rows="readinessBars" />
        </div>

        <Card class="mt-4" :padding="false">
            <div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4"><h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.operationsExecutive.actionQueue') }}</h2><Badge :variant="actions.length ? 'warning' : 'success'">{{ actions.length }}</Badge></div>
            <div class="divide-y divide-neutral-100">
                <component :is="actionHref(action) ? Link : 'div'" v-for="action in actions" :key="action.key" v-bind="actionHref(action) ? { href: actionHref(action) } : {}" class="flex items-center gap-4 px-5 py-3 hover:bg-neutral-50">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="action.severity === 'error' ? 'bg-error-50 text-error-600' : 'bg-warning-50 text-warning-700'"><Wrench v-if="action.kind === 'maintenance'" class="h-4 w-4" /><TriangleAlert v-else class="h-4 w-4" /></span>
                    <div class="min-w-0 flex-1"><p class="truncate text-body-sm font-semibold text-primary-900">{{ actionTitle(action) }}</p><p class="truncate text-tiny text-neutral-500">{{ actionDetail(action) }}</p></div>
                    <Badge :variant="action.severity">{{ action.severity === 'error' ? $t('reports360.operationsExecutive.urgent') : $t('reports360.operationsExecutive.review') }}</Badge>
                </component>
                <div v-if="!actions.length" class="px-6 py-12 text-center text-body-sm text-neutral-500">{{ $t('reports360.operationsExecutive.noActions') }}</div>
            </div>
        </Card>
    </ReportShell>
</template>
