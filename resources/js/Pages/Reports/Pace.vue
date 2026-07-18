<script setup>
import { computed } from 'vue';
import { getIntlLocale, translate } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import { Banknote, BedDouble, CalendarClock, TrendingUp } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    analytics: { type: Object, default: () => ({}) },
    currency: { type: String, default: '€' },
});

const current = computed(() => props.analytics.current || {});
const horizons = computed(() => props.analytics.horizons || []);
const daily = computed(() => props.analytics.daily || []);
const pickup7 = computed(() => horizons.value.find((item) => item.days === 7));
const pickup30 = computed(() => horizons.value.find((item) => item.days === 30));
const availableCount = computed(() => horizons.value.filter((item) => item.available).length);
const maxNights = computed(() => Math.max(1, ...daily.value.flatMap((day) => [day.current_nights || 0, day.reference_nights || 0])));

const money = (value) => `${props.currency}${Number(value ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const signed = (value, formatter = (item) => item) => value == null ? '—' : `${value > 0 ? '+' : ''}${formatter(value)}`;
const shortDate = (value) => new Date(`${value}T00:00:00`).toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short' });
const tone = (value) => value == null ? 'neutral' : value >= 0 ? 'success' : 'error';

const kpis = computed(() => [
    { label: translate('reports360.pickupPace.onBooksNights'), value: current.value.nights || 0, tone: 'info', icon: BedDouble },
    { label: translate('reports360.pickupPace.onBooksRevenue'), value: money(current.value.revenue), tone: 'accent', icon: Banknote },
    { label: translate('reports360.pickupPace.pickup7'), value: signed(pickup7.value?.pickup_nights), tone: tone(pickup7.value?.pickup_nights), icon: TrendingUp, detail: translate('reports360.nights') },
    { label: translate('reports360.pickupPace.pickup30Revenue'), value: pickup30.value?.revenue_available ? signed(pickup30.value.pickup_revenue, money) : '—', tone: tone(pickup30.value?.pickup_revenue), icon: CalendarClock },
]);
</script>

<template>
    <ReportShell
        :title="$t('reports360.pickupPace.title')"
        route-name="reports.pace"
        :filters="filters"
        :description="$t('reports360.pickupPace.short')"
        :category="$t('reports360.pickupPace.category')"
        preset-mode="future"
    >
        <ReportKpiGrid :items="kpis" />

        <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.65fr)_minmax(320px,0.7fr)]">
            <Card :padding="false">
                <div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4">
                    <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.pickupPace.dailyPace') }}</h2>
                    <div class="flex items-center gap-3 text-tiny text-neutral-500">
                        <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-accent-500" />{{ $t('reports360.pickupPace.today') }}</span>
                        <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-neutral-300" />{{ $t('reports360.pickupPace.reference', { days: analytics.baseline_days || '—' }) }}</span>
                    </div>
                </div>
                <div class="h-60 px-5 pb-4 pt-5">
                    <div v-if="daily.length && analytics.baseline_days" class="flex h-full items-end gap-1.5 border-b border-neutral-200">
                        <div v-for="day in daily" :key="day.date" class="group relative flex h-full min-w-0 flex-1 items-end justify-center gap-px" :title="`${shortDate(day.date)} · ${signed(day.pickup_nights)} ${$t('reports360.nights')}`">
                            <span class="w-1/3 rounded-t bg-neutral-200" :style="{ height: `${Math.max(2, Number(day.reference_nights || 0) / maxNights * 100)}%` }" />
                            <span class="w-1/3 rounded-t bg-accent-500 transition group-hover:bg-accent-700" :style="{ height: `${Math.max(2, Number(day.current_nights || 0) / maxNights * 100)}%` }" />
                        </div>
                    </div>
                    <div v-else class="flex h-full items-center justify-center text-body-sm text-neutral-400">{{ $t('reports360.pickupPace.buildingHistory') }}</div>
                </div>
            </Card>

            <Card :padding="false">
                <div class="border-b border-neutral-200 px-5 py-4">
                    <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.pickupPace.history') }}</h2>
                </div>
                <div class="divide-y divide-neutral-100">
                    <div class="flex items-center justify-between px-5 py-3">
                        <span class="text-body-sm text-neutral-600">{{ $t('reports360.pickupPace.availableSnapshots') }}</span>
                        <Badge :variant="availableCount ? 'success' : 'warning'">{{ availableCount }} / 5</Badge>
                    </div>
                    <div class="flex items-center justify-between px-5 py-3">
                        <span class="text-body-sm text-neutral-600">{{ $t('reports360.pickupPace.historySince') }}</span>
                        <b class="text-body-sm text-primary-900">{{ analytics.history_started_at ? shortDate(analytics.history_started_at) : '—' }}</b>
                    </div>
                    <div class="flex items-center justify-between px-5 py-3">
                        <span class="text-body-sm text-neutral-600">{{ $t('reports360.pickupPace.defaultBaseline') }}</span>
                        <b class="text-body-sm text-primary-900">{{ analytics.baseline_days ? `${analytics.baseline_days}d` : '—' }}</b>
                    </div>
                </div>
                <p v-if="availableCount < 5" class="border-t border-neutral-200 px-5 py-3 text-tiny text-neutral-500">{{ $t('reports360.pickupPace.historyNote') }}</p>
            </Card>
        </div>

        <Card class="mt-4" :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4">
                <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.pickupPace.byHorizon') }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50 text-left text-label text-neutral-600">
                        <tr>
                            <th class="px-5 py-3">{{ $t('reports360.pickupPace.horizon') }}</th>
                            <th class="px-4 py-3">{{ $t('reports360.pickupPace.snapshot') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.pickupPace.referenceNights') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.pickupPace.onBooksNights') }}</th>
                            <th class="px-4 py-3 text-right">Pickup</th>
                            <th class="px-5 py-3 text-right">{{ $t('reports360.revenue') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in horizons" :key="row.days" class="hover:bg-neutral-50">
                            <td class="px-5 py-3 text-body-sm font-medium text-primary-900">{{ row.days }}d</td>
                            <td class="px-4 py-3 text-body-sm text-neutral-600">{{ row.snapshot_date ? shortDate(row.snapshot_date) : '—' }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ row.available ? row.reference_nights : '—' }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-700">{{ row.current_nights }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold" :class="row.pickup_nights == null ? 'text-neutral-400' : row.pickup_nights >= 0 ? 'text-success-700' : 'text-error-700'">{{ signed(row.pickup_nights) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold" :class="row.pickup_revenue == null ? 'text-neutral-400' : row.pickup_revenue >= 0 ? 'text-success-700' : 'text-error-700'">{{ row.revenue_available ? signed(row.pickup_revenue, money) : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </ReportShell>
</template>
