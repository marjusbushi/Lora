<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { getIntlLocale, translate } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import { AlertTriangle, Clock3, DoorOpen, Repeat2 } from 'lucide-vue-next';

const props = defineProps({ filters: Object, analytics: { type: Object, default: () => ({}) }, canViewMaintenance: Boolean, currency: String });
const summary = computed(() => props.analytics.summary || {});
const daily = computed(() => props.analytics.daily || []);
const groups = computed(() => props.analytics.groups || []);
const maxDaily = computed(() => Math.max(1, ...daily.value.map((row) => row.value)));
const number = (value, digits = 0) => Number(value || 0).toLocaleString(getIntlLocale(), { maximumFractionDigits: digits });
const pct = (value) => `${number(value, 1)}%`;
const days = (value) => `${number(value, 1)} ${translate('reports360.recurringMaintenance.days')}`;
const fmt = (value) => value ? new Date(value.replace(' ', 'T')).toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
const categoryLabel = (key) => translate(`maintenance.categories.${key}`) === `maintenance.categories.${key}` ? key : translate(`maintenance.categories.${key}`);
const categoryBars = computed(() => (props.analytics.categories || []).map((row) => ({ key: row.key, label: categoryLabel(row.key), value: row.value, display: number(row.value), barClass: 'bg-warning-500' })));
const kpis = computed(() => [
    { label: translate('reports360.recurringMaintenance.patterns'), value: number(summary.value.recurring_groups), tone: 'warning', icon: Repeat2, detail: `${number(summary.value.repeat_occurrences)} ${translate('reports360.recurringMaintenance.repeats')}` },
    { label: translate('reports360.recurringMaintenance.repeatRate'), value: pct(summary.value.repeat_rate), tone: Number(summary.value.repeat_rate || 0) > 20 ? 'error' : 'neutral', icon: AlertTriangle },
    { label: translate('reports360.recurringMaintenance.avgInterval'), value: days(summary.value.avg_interval_days), tone: 'info', icon: Clock3 },
    { label: translate('reports360.recurringMaintenance.affectedRooms'), value: number(summary.value.affected_rooms), tone: 'neutral', icon: DoorOpen, detail: `${number(summary.value.open_issues)} ${translate('reports360.recurringMaintenance.open')}` },
]);
const issueHref = (row) => props.canViewMaintenance ? route('maintenance.index', { issue_id: row.latest_issue_id }) : null;
</script>

<template>
    <ReportShell :title="$t('reports360.recurringMaintenance.title')" route-name="reports.recurringMaintenance" :filters="filters" :description="$t('reports360.recurringMaintenance.short')" :category="$t('reports360.recurringMaintenance.category')">
        <ReportKpiGrid :items="kpis" />
        <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.35fr)_minmax(300px,0.65fr)]">
            <Card :padding="false"><div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4"><h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.recurringMaintenance.daily') }}</h2><span class="text-tiny text-neutral-500">{{ $t('reports360.recurringMaintenance.repeatEvents') }}</span></div><div class="h-56 px-5 pb-4 pt-5"><div class="flex h-full items-end gap-1.5 border-b border-neutral-200"><span v-for="day in daily" :key="day.date" class="min-w-0 flex-1 rounded-t bg-warning-500" :style="{ height: `${day.value / maxDaily * 100}%` }" :title="`${day.date}: ${day.value}`" /></div></div></Card>
            <ReportBarList :title="$t('reports360.recurringMaintenance.byCategory')" :rows="categoryBars" />
        </div>

        <Card class="mt-4" :padding="false"><div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4"><h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.recurringMaintenance.patternControl') }}</h2><span class="text-tiny text-neutral-500">{{ $t('reports360.recurringMaintenance.lookback') }}</span></div><div class="overflow-x-auto"><table class="min-w-full divide-y divide-neutral-200"><thead class="bg-neutral-50 text-label text-neutral-600"><tr><th class="px-5 py-3 text-left">{{ $t('reports360.recurringMaintenance.problem') }}</th><th class="px-4 py-3 text-left">{{ $t('reports360.recurringMaintenance.room') }}</th><th class="px-4 py-3 text-left">{{ $t('reports360.recurringMaintenance.categoryLabel') }}</th><th class="px-4 py-3 text-right">{{ $t('reports360.recurringMaintenance.occurrences') }}</th><th class="px-4 py-3 text-right">{{ $t('reports360.recurringMaintenance.interval') }}</th><th class="px-4 py-3 text-right">{{ $t('reports360.recurringMaintenance.openLabel') }}</th><th class="px-5 py-3 text-right">{{ $t('reports360.recurringMaintenance.lastSeen') }}</th></tr></thead>
            <tbody class="divide-y divide-neutral-100"><tr v-for="row in groups" :key="row.key" class="hover:bg-neutral-50"><td class="px-5 py-3"><Link v-if="issueHref(row)" :href="issueHref(row)" class="text-body-sm font-medium text-primary-900 hover:underline">{{ row.label }}</Link><span v-else class="text-body-sm font-medium text-primary-900">{{ row.label }}</span><p v-if="row.asset_code" class="mt-0.5 text-tiny text-neutral-500">{{ row.asset_code }}</p></td><td class="px-4 py-3 text-body-sm text-neutral-600">{{ row.room || '—' }}</td><td class="px-4 py-3 text-body-sm text-neutral-600">{{ categoryLabel(row.category) }}</td><td class="px-4 py-3 text-right text-body-sm font-semibold text-primary-900">{{ row.period_occurrences }} / {{ row.total_occurrences }}</td><td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ days(row.avg_interval_days) }}</td><td class="px-4 py-3 text-right"><Badge :variant="row.open_count ? 'error' : 'success'">{{ row.open_count }}</Badge></td><td class="px-5 py-3 text-right text-body-sm text-neutral-500">{{ fmt(row.last_at) }}</td></tr></tbody></table></div><div v-if="!groups.length" class="px-6 py-12 text-center text-body-sm text-neutral-500">{{ $t('reports360.noData') }}</div></Card>
    </ReportShell>
</template>
