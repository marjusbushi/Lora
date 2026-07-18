<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { getIntlLocale, translate } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import { AlarmClock, CircleCheckBig, Clock3, Wrench } from 'lucide-vue-next';

const props = defineProps({ filters: Object, analytics: { type: Object, default: () => ({}) }, canViewMaintenance: Boolean, currency: String });
const summary = computed(() => props.analytics.summary || {});
const daily = computed(() => props.analytics.daily || []);
const issues = computed(() => props.analytics.issues || []);
const maxDaily = computed(() => Math.max(1, ...daily.value.flatMap((row) => [row.reported, row.resolved])));
const pct = (value) => `${Number(value || 0).toLocaleString(getIntlLocale(), { maximumFractionDigits: 1 })}%`;
const hours = (value) => `${Number(value || 0).toLocaleString(getIntlLocale(), { maximumFractionDigits: 1 })} h`;
const fmt = (value) => value ? new Date(value.replace(' ', 'T')).toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short' }) : '—';
const label = (group, key) => translate(`reports360.maintenanceSla.${group}.${key}`);
const priorityBars = computed(() => (props.analytics.priorities || []).map((row) => ({ key: row.key, label: label('priorities', row.key), value: row.reported, display: `${row.reported} · ${pct(row.sla_rate)}`, barClass: row.key === 'critical' ? 'bg-error-500' : row.key === 'high' ? 'bg-warning-500' : 'bg-accent-500' })));
const kpis = computed(() => [
    { label: translate('reports360.maintenanceSla.reported'), value: summary.value.reported || 0, tone: 'neutral', icon: Wrench, detail: `${summary.value.open || 0} ${translate('reports360.maintenanceSla.open')}` },
    { label: translate('reports360.maintenanceSla.slaRate'), value: pct(summary.value.sla_rate), tone: Number(summary.value.sla_rate || 0) >= 90 ? 'success' : 'warning', icon: CircleCheckBig },
    { label: translate('reports360.maintenanceSla.resolutionTime'), value: hours(summary.value.avg_resolution_hours), tone: 'info', icon: Clock3, detail: `${summary.value.overdue || 0} ${translate('reports360.maintenanceSla.overdue')}` },
    { label: translate('reports360.maintenanceSla.downtime'), value: hours(summary.value.downtime_hours), tone: 'error', icon: AlarmClock, detail: `${summary.value.affected_rooms || 0} ${translate('reports360.maintenanceSla.rooms')}` },
]);
const issueHref = (row) => props.canViewMaintenance ? route('maintenance.index', { issue_id: row.id }) : null;
</script>

<template>
    <ReportShell :title="$t('reports360.maintenanceSla.title')" route-name="reports.maintenanceSla" :filters="filters" :description="$t('reports360.maintenanceSla.short')" :category="$t('reports360.maintenanceSla.category')">
        <ReportKpiGrid :items="kpis" />
        <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.35fr)_minmax(300px,0.65fr)]">
            <Card :padding="false"><div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4"><h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.maintenanceSla.daily') }}</h2><span class="text-tiny text-neutral-500">{{ $t('reports360.maintenanceSla.openedVsResolved') }}</span></div><div class="h-56 px-5 pb-4 pt-5"><div class="flex h-full items-end gap-1.5 border-b border-neutral-200"><div v-for="day in daily" :key="day.date" class="flex h-full min-w-0 flex-1 items-end justify-center gap-px" :title="day.date"><span class="w-1/3 rounded-t bg-warning-400" :style="{ height: `${day.reported / maxDaily * 100}%` }" /><span class="w-1/3 rounded-t bg-success-500" :style="{ height: `${day.resolved / maxDaily * 100}%` }" /></div></div></div></Card>
            <ReportBarList :title="$t('reports360.maintenanceSla.byPriority')" :rows="priorityBars" />
        </div>

        <Card class="mt-4" :padding="false"><div class="border-b border-neutral-200 px-5 py-4"><h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.maintenanceSla.issueControl') }}</h2></div><div class="overflow-x-auto"><table class="min-w-full divide-y divide-neutral-200"><thead class="bg-neutral-50 text-label text-neutral-600"><tr><th class="px-5 py-3 text-left">{{ $t('reports360.maintenanceSla.issue') }}</th><th class="px-4 py-3 text-left">{{ $t('reports360.maintenanceSla.room') }}</th><th class="px-4 py-3 text-left">{{ $t('reports360.maintenanceSla.priority') }}</th><th class="px-4 py-3 text-left">SLA</th><th class="px-4 py-3 text-right">{{ $t('reports360.maintenanceSla.responseTime') }}</th><th class="px-4 py-3 text-right">{{ $t('reports360.maintenanceSla.resolutionTime') }}</th><th class="px-5 py-3 text-right">{{ $t('reports360.maintenanceSla.date') }}</th></tr></thead>
            <tbody class="divide-y divide-neutral-100"><tr v-for="row in issues" :key="row.id" class="hover:bg-neutral-50"><td class="px-5 py-3 text-body-sm font-medium text-primary-900"><Link v-if="issueHref(row)" :href="issueHref(row)" class="hover:underline">{{ row.title }}</Link><span v-else>{{ row.title }}</span></td><td class="px-4 py-3 text-body-sm text-neutral-600">{{ row.room || '—' }}</td><td class="px-4 py-3"><Badge :variant="row.priority === 'critical' ? 'error' : row.priority === 'high' ? 'warning' : 'neutral'">{{ label('priorities', row.priority) }}</Badge></td><td class="px-4 py-3"><Badge :variant="row.sla === 'met' ? 'success' : row.sla === 'breached' || row.sla === 'overdue' ? 'error' : 'neutral'">{{ label('sla', row.sla) }}</Badge></td><td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ row.response_hours == null ? '—' : hours(row.response_hours) }}</td><td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ row.resolution_hours == null ? '—' : hours(row.resolution_hours) }}</td><td class="px-5 py-3 text-right text-body-sm text-neutral-500">{{ fmt(row.created_at) }}</td></tr></tbody></table></div><div v-if="!issues.length" class="px-6 py-12 text-center text-body-sm text-neutral-500">{{ $t('reports360.noData') }}</div></Card>
    </ReportShell>
</template>
