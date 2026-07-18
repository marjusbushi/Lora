<script setup>
import { getIntlLocale, translate } from '@/i18n';
import { computed } from 'vue';
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import { Banknote, CalendarDays, Clock3, ReceiptText } from 'lucide-vue-next';

const props = defineProps({
    filters: { type: Object, default: null },
    byHour: { type: Array, default: () => [] },
    byWeekday: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    currency: { type: String, default: '€' },
});

const money = (v) => `${props.currency}${Number(v ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const qty = (v) => Number(v ?? 0).toLocaleString(getIntlLocale());
const hourLabel = (h) => `${String(h).padStart(2, '0')}:00`;

// Max revenue per axis for bar-width scaling (guard div-by-zero).
const maxHourRevenue = computed(() => Math.max(0, ...props.byHour.map((r) => Number(r.revenue ?? 0))));
const maxWeekdayRevenue = computed(() => Math.max(0, ...props.byWeekday.map((r) => Number(r.revenue ?? 0))));
const pct = (v, max) => (Number(max) > 0 ? Math.round((Number(v ?? 0) / Number(max)) * 100) : 0);

// Hours with at least one order — keep the bar list readable.
const activeHours = computed(() => props.byHour.filter((r) => Number(r.count ?? 0) > 0 || Math.abs(Number(r.revenue ?? 0)) > 0.009));

const kpis = [
    { label: translate('admin.generated.k_cbac0a0055be'), value: () => money(props.summary.total_revenue), tone: 'accent', icon: Banknote },
    { label: translate('admin.generated.k_f186fa73af89'), value: () => qty(props.summary.order_count), tone: 'info', icon: ReceiptText },
    { label: translate('admin.generated.k_79d298cfb204'), value: () => qty(props.summary.days), tone: 'neutral', icon: CalendarDays },
    { label: translate('admin.generated.k_a16ebc64e8e7'), value: () => qty(activeHours.value.length), tone: 'success', icon: Clock3 },
];
</script>

<template>
    <ReportShell :title="$t('admin.generated.k_c7c5d0818fce')" route-name="reports.posHourly" :filters="filters">
        <ReportKpiGrid :items="kpis" />

        <!-- By hour -->
        <Card :padding="false" class="mt-6">
            <div class="px-5 py-4 border-b border-neutral-200">
                <h3 class="text-h4 text-primary-900">{{ $t('admin.generated.k_eaab80862b87') }}</h3>
            </div>
            <div v-if="activeHours.length" class="p-5 space-y-2.5">
                <div v-for="row in activeHours" :key="row.hour" class="flex items-center gap-3">
                    <span class="w-14 shrink-0 text-body-sm text-neutral-600 tabular-nums">{{ hourLabel(row.hour) }}</span>
                    <div class="flex-1 h-6 rounded bg-neutral-100 overflow-hidden">
                        <div class="h-full rounded bg-accent-500/80" :style="{ width: pct(row.revenue, maxHourRevenue) + '%' }"></div>
                    </div>
                    <span class="w-28 shrink-0 text-right text-body-sm tabular-nums">{{ money(row.revenue) }}</span>
                    <span class="w-20 shrink-0 text-right text-body-sm text-neutral-500 tabular-nums">{{ qty(row.count) }} {{ $t('admin.generated.k_534101d3d667') }}</span>
                </div>
            </div>
            <div v-else class="px-6 py-10 text-center text-body-sm text-neutral-500">{{ $t('admin.generated.k_e82b8023da6d') }}</div>
        </Card>

        <!-- By weekday -->
        <Card :padding="false" class="mt-6">
            <div class="px-5 py-4 border-b border-neutral-200">
                <h3 class="text-h4 text-primary-900">{{ $t('admin.generated.k_89d3e91b2dd1') }}</h3>
            </div>
            <table v-if="summary.order_count || Math.abs(Number(summary.total_revenue ?? 0)) > 0.009" class="min-w-full divide-y divide-neutral-200">
                <thead class="bg-neutral-50">
                    <tr>
                        <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_f92f5c8f29ee') }}</th>
                        <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_71eef5432149') }}</th>
                        <th class="px-5 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_6706c410d402') }}</th>
                        <th class="px-5 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_5b901303bd53') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200">
                    <tr v-for="row in byWeekday" :key="row.weekday">
                        <td class="px-5 py-3 text-body-sm font-medium text-primary-900">{{ row.weekday }}</td>
                        <td class="px-5 py-3 text-body-sm">
                            <div class="h-5 rounded bg-neutral-100 overflow-hidden min-w-[8rem]">
                                <div class="h-full rounded bg-primary-500/70" :style="{ width: pct(row.revenue, maxWeekdayRevenue) + '%' }"></div>
                            </div>
                        </td>
                        <td class="px-5 py-3 text-body-sm text-right tabular-nums">{{ qty(row.count) }}</td>
                        <td class="px-5 py-3 text-body-sm text-right tabular-nums">{{ money(row.revenue) }}</td>
                    </tr>
                </tbody>
                <tfoot class="bg-neutral-50 border-t-2 border-neutral-200">
                    <tr class="font-semibold">
                        <td class="px-5 py-3 text-body-sm" colspan="2">{{ $t('admin.generated.k_2a9b866f6602') }}</td>
                        <td class="px-5 py-3 text-body-sm text-right tabular-nums">{{ qty(summary.order_count) }}</td>
                        <td class="px-5 py-3 text-body-sm text-right tabular-nums">{{ money(summary.total_revenue) }}</td>
                    </tr>
                </tfoot>
            </table>
            <div v-else class="px-6 py-10 text-center text-body-sm text-neutral-500">{{ $t('admin.generated.k_e82b8023da6d') }}</div>
        </Card>
    </ReportShell>
</template>
