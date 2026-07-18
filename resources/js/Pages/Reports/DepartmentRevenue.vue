<script setup>
import { computed } from 'vue';
import { getIntlLocale, translate } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import Card from '@/Components/UI/Card.vue';
import { Banknote, BedDouble, Building2, Utensils } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    analytics: { type: Object, default: () => ({}) },
    currency: { type: String, default: '€' },
});

const current = computed(() => props.analytics.current || {});
const summary = computed(() => current.value.summary || {});
const changes = computed(() => props.analytics.changes || {});
const daily = computed(() => current.value.daily || []);
const maxDaily = computed(() => Math.max(1, ...daily.value.map((day) => ['rooms', 'pos', 'other']
    .reduce((sum, key) => sum + Math.max(0, Number(day[key] || 0)), 0))));
const money = (value) => `${props.currency}${Number(value ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const pct = (value) => `${Number(value ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
const trend = (key) => changes.value[key] > 0 ? 'up' : changes.value[key] < 0 ? 'down' : 'flat';
const trendText = (key) => changes.value[key] == null ? translate('reports360.noComparison') : `${changes.value[key] > 0 ? '+' : ''}${changes.value[key]}%`;
const departmentLabel = (key) => translate(`reports360.departmentRevenue.departments.${key}`);
const bars = computed(() => (current.value.departments || []).map((row) => ({
    key: row.department,
    label: departmentLabel(row.department),
    value: Math.max(0, Number(row.amount || 0)),
    display: `${money(row.amount)} · ${pct(row.share)}`,
    barClass: row.department === 'rooms' ? 'bg-accent-500' : row.department === 'pos' ? 'bg-success-500' : 'bg-info-500',
})));
const kpis = computed(() => [
    { label: translate('reports360.departmentRevenue.total'), value: money(summary.value.total), tone: 'accent', icon: Banknote, trend: trend('total'), trendText: trendText('total') },
    { label: departmentLabel('rooms'), value: money(summary.value.rooms), tone: 'info', icon: BedDouble, trend: trend('rooms'), trendText: trendText('rooms') },
    { label: departmentLabel('pos'), value: money(summary.value.pos), tone: 'success', icon: Utensils, trend: trend('pos'), trendText: trendText('pos') },
    { label: departmentLabel('other'), value: money(summary.value.other), tone: 'neutral', icon: Building2, trend: trend('other'), trendText: trendText('other') },
]);
</script>

<template>
    <ReportShell
        :title="$t('reports360.departmentRevenue.title')"
        route-name="reports.departmentRevenue"
        :filters="filters"
        :description="$t('reports360.departmentRevenue.short')"
        :category="$t('reports360.departmentRevenue.category')"
    >
        <ReportKpiGrid :items="kpis" />

        <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.4fr)_minmax(300px,0.6fr)]">
            <Card :padding="false">
                <div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4">
                    <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.departmentRevenue.daily') }}</h2>
                    <span class="text-tiny text-neutral-500">{{ $t('reports360.departmentRevenue.netRecognized') }}</span>
                </div>
                <div class="h-60 px-5 pb-4 pt-5">
                    <div v-if="daily.length" class="flex h-full items-end gap-1.5 border-b border-neutral-200">
                        <div v-for="day in daily" :key="day.date" class="group flex h-full min-w-0 flex-1 flex-col-reverse justify-start" :title="`${day.date} · ${money(day.total)}`">
                            <span class="w-full bg-accent-500" :style="{ height: `${Math.max(0, Number(day.rooms)) / maxDaily * 100}%` }" />
                            <span class="w-full bg-success-500" :style="{ height: `${Math.max(0, Number(day.pos)) / maxDaily * 100}%` }" />
                            <span class="w-full rounded-t bg-info-400" :style="{ height: `${Math.max(0, Number(day.other)) / maxDaily * 100}%` }" />
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-4 border-t border-neutral-200 px-5 py-3 text-tiny text-neutral-600">
                    <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-accent-500" />{{ departmentLabel('rooms') }}</span>
                    <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-success-500" />{{ departmentLabel('pos') }}</span>
                    <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-info-400" />{{ departmentLabel('other') }}</span>
                </div>
            </Card>
            <ReportBarList :title="$t('reports360.departmentRevenue.mix')" :rows="bars" />
        </div>

        <Card class="mt-4" :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4"><h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.departmentRevenue.breakdown') }}</h2></div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50 text-left text-label text-neutral-600"><tr>
                        <th class="px-5 py-3">{{ $t('reports360.departmentRevenue.department') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('reports360.departmentRevenue.current') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('reports360.departmentRevenue.previous') }}</th>
                        <th class="px-4 py-3 text-right">{{ $t('reports360.departmentRevenue.change') }}</th>
                        <th class="px-5 py-3 text-right">{{ $t('reports360.departmentRevenue.share') }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in current.departments" :key="row.department" class="hover:bg-neutral-50">
                            <td class="px-5 py-3 text-body-sm font-medium text-primary-900">{{ departmentLabel(row.department) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold text-primary-900">{{ money(row.amount) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ money(analytics.previous?.summary?.[row.department]) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold" :class="changes[row.department] >= 0 ? 'text-success-700' : 'text-error-700'">{{ trendText(row.department) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ pct(row.share) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </ReportShell>
</template>
