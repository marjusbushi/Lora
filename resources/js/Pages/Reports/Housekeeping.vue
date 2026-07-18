<script setup>
import { computed } from 'vue';
import { getIntlLocale, translate } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import { CheckCircle2, Clock3, Gauge, Sparkles } from 'lucide-vue-next';

const props = defineProps({ filters: Object, analytics: { type: Object, default: () => ({}) }, currency: String });
const summary = computed(() => props.analytics.summary || {});
const staff = computed(() => props.analytics.staff || []);
const daily = computed(() => props.analytics.daily || []);
const tasks = computed(() => props.analytics.tasks || []);
const maxDaily = computed(() => Math.max(1, ...daily.value.map((row) => Number(row.assigned || 0))));
const pct = (value) => `${Number(value || 0).toLocaleString(getIntlLocale(), { maximumFractionDigits: 1 })}%`;
const minutes = (value) => `${Number(value || 0).toLocaleString(getIntlLocale(), { maximumFractionDigits: 1 })} min`;
const fmt = (value) => value ? new Date(value.replace(' ', 'T')).toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short' }) : '—';
const typeLabel = (type) => translate(`reports360.housekeepingProductivity.types.${type}`);
const statusLabel = (status) => translate(`reports360.housekeepingProductivity.statuses.${status}`);
const staffBars = computed(() => staff.value.map((row) => ({ key: row.staff, label: row.staff, value: row.completed, display: `${row.completed} · ${minutes(row.avg_clean_minutes)}`, barClass: 'bg-accent-500' })));
const kpis = computed(() => [
    { label: translate('reports360.housekeepingProductivity.completed'), value: summary.value.completed || 0, tone: 'success', icon: CheckCircle2, detail: `${summary.value.total || 0} ${translate('reports360.housekeepingProductivity.assigned')}` },
    { label: translate('reports360.housekeepingProductivity.completionRate'), value: pct(summary.value.completion_rate), tone: 'accent', icon: Gauge },
    { label: translate('reports360.housekeepingProductivity.avgClean'), value: minutes(summary.value.avg_clean_minutes), tone: 'info', icon: Sparkles },
    { label: translate('reports360.housekeepingProductivity.avgQueue'), value: minutes(summary.value.avg_queue_minutes), tone: 'warning', icon: Clock3, detail: `${summary.value.issues || 0} ${translate('reports360.housekeepingProductivity.issues')}` },
]);
</script>

<template>
    <ReportShell :title="$t('reports360.housekeepingProductivity.title')" route-name="reports.housekeepingReport" :filters="filters" :description="$t('reports360.housekeepingProductivity.short')" :category="$t('reports360.housekeepingProductivity.category')">
        <ReportKpiGrid :items="kpis" />
        <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.35fr)_minmax(300px,0.65fr)]">
            <Card :padding="false">
                <div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4"><h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.housekeepingProductivity.daily') }}</h2><span class="text-tiny text-neutral-500">{{ $t('reports360.housekeepingProductivity.assignedVsCompleted') }}</span></div>
                <div class="h-56 px-5 pb-4 pt-5"><div class="flex h-full items-end gap-1.5 border-b border-neutral-200"><div v-for="day in daily" :key="day.date" class="flex h-full min-w-0 flex-1 items-end justify-center gap-px" :title="`${day.date}: ${day.completed}/${day.assigned}`"><span class="w-1/3 rounded-t bg-neutral-200" :style="{ height: `${day.assigned / maxDaily * 100}%` }" /><span class="w-1/3 rounded-t bg-accent-500" :style="{ height: `${day.completed / maxDaily * 100}%` }" /></div></div></div>
            </Card>
            <ReportBarList :title="$t('reports360.housekeepingProductivity.byStaff')" :rows="staffBars" />
        </div>

        <Card class="mt-4" :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4"><h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.housekeepingProductivity.staffPerformance') }}</h2></div>
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-neutral-200"><thead class="bg-neutral-50 text-label text-neutral-600"><tr><th class="px-5 py-3 text-left">{{ $t('reports360.housekeepingProductivity.staff') }}</th><th class="px-4 py-3 text-right">{{ $t('reports360.housekeepingProductivity.assigned') }}</th><th class="px-4 py-3 text-right">{{ $t('reports360.housekeepingProductivity.completed') }}</th><th class="px-4 py-3 text-right">{{ $t('reports360.housekeepingProductivity.completionRate') }}</th><th class="px-4 py-3 text-right">{{ $t('reports360.housekeepingProductivity.avgClean') }}</th><th class="px-5 py-3 text-right">{{ $t('reports360.housekeepingProductivity.issues') }}</th></tr></thead>
                <tbody class="divide-y divide-neutral-100"><tr v-for="row in staff" :key="row.staff" class="hover:bg-neutral-50"><td class="px-5 py-3 text-body-sm font-medium text-primary-900">{{ row.staff }}</td><td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ row.assigned }}</td><td class="px-4 py-3 text-right text-body-sm font-semibold text-success-700">{{ row.completed }}</td><td class="px-4 py-3 text-right text-body-sm text-neutral-700">{{ pct(row.completion_rate) }}</td><td class="px-4 py-3 text-right text-body-sm text-neutral-700">{{ minutes(row.avg_clean_minutes) }}</td><td class="px-5 py-3 text-right text-body-sm" :class="row.issues ? 'text-error-700' : 'text-neutral-500'">{{ row.issues }}</td></tr></tbody></table></div>
        </Card>

        <Card class="mt-4" :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4"><h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.housekeepingProductivity.recentTasks') }}</h2></div>
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-neutral-200"><thead class="bg-neutral-50 text-label text-neutral-600"><tr><th class="px-5 py-3 text-left">{{ $t('reports360.housekeepingProductivity.room') }}</th><th class="px-4 py-3 text-left">{{ $t('reports360.housekeepingProductivity.type') }}</th><th class="px-4 py-3 text-left">{{ $t('reports360.housekeepingProductivity.staff') }}</th><th class="px-4 py-3 text-left">{{ $t('reports360.housekeepingProductivity.status') }}</th><th class="px-4 py-3 text-right">{{ $t('reports360.housekeepingProductivity.queue') }}</th><th class="px-4 py-3 text-right">{{ $t('reports360.housekeepingProductivity.cleaning') }}</th><th class="px-5 py-3 text-right">{{ $t('reports360.housekeepingProductivity.date') }}</th></tr></thead>
                <tbody class="divide-y divide-neutral-100"><tr v-for="row in tasks" :key="row.id" class="hover:bg-neutral-50"><td class="px-5 py-3 text-body-sm font-medium text-primary-900">{{ row.room }}</td><td class="px-4 py-3 text-body-sm text-neutral-700">{{ typeLabel(row.type) }}</td><td class="px-4 py-3 text-body-sm text-neutral-700">{{ row.assigned }}</td><td class="px-4 py-3"><Badge :variant="row.status === 'inspected' ? 'accent' : row.status === 'completed' ? 'success' : row.status === 'in_progress' ? 'info' : 'warning'">{{ statusLabel(row.status) }}</Badge></td><td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ row.queue_minutes == null ? '—' : minutes(row.queue_minutes) }}</td><td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ row.clean_minutes == null ? '—' : minutes(row.clean_minutes) }}</td><td class="px-5 py-3 text-right text-body-sm text-neutral-500">{{ fmt(row.created_at) }}</td></tr></tbody></table></div>
            <div v-if="!tasks.length" class="px-6 py-12 text-center text-body-sm text-neutral-500">{{ $t('reports360.noData') }}</div>
        </Card>
    </ReportShell>
</template>
