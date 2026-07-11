<script setup>
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

const money = (v) => `${props.currency}${Number(v ?? 0).toLocaleString('sq-AL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const qty = (v) => Number(v ?? 0).toLocaleString('sq-AL');
const hourLabel = (h) => `${String(h).padStart(2, '0')}:00`;

// Max revenue per axis for bar-width scaling (guard div-by-zero).
const maxHourRevenue = computed(() => Math.max(0, ...props.byHour.map((r) => Number(r.revenue ?? 0))));
const maxWeekdayRevenue = computed(() => Math.max(0, ...props.byWeekday.map((r) => Number(r.revenue ?? 0))));
const pct = (v, max) => (Number(max) > 0 ? Math.round((Number(v ?? 0) / Number(max)) * 100) : 0);

// Hours with at least one order — keep the bar list readable.
const activeHours = computed(() => props.byHour.filter((r) => Number(r.count ?? 0) > 0));

const kpis = [
    { label: 'Të ardhura', value: () => money(props.summary.total_revenue), tone: 'accent', icon: Banknote },
    { label: 'Porosi', value: () => qty(props.summary.order_count), tone: 'info', icon: ReceiptText },
    { label: 'Ditë aktive', value: () => qty(props.summary.days), tone: 'neutral', icon: CalendarDays },
    { label: 'Orë aktive', value: () => qty(activeHours.value.length), tone: 'success', icon: Clock3 },
];
</script>

<template>
    <ReportShell title="Shitjet POS sipas Orës & Ditës" route-name="reports.posHourly" :filters="filters">
        <ReportKpiGrid :items="kpis" />

        <!-- By hour -->
        <Card :padding="false" class="mt-6">
            <div class="px-5 py-4 border-b border-neutral-200">
                <h3 class="text-h4 text-primary-900">Sipas orës së ditës</h3>
            </div>
            <div v-if="activeHours.length" class="p-5 space-y-2.5">
                <div v-for="row in activeHours" :key="row.hour" class="flex items-center gap-3">
                    <span class="w-14 shrink-0 text-body-sm text-neutral-600 tabular-nums">{{ hourLabel(row.hour) }}</span>
                    <div class="flex-1 h-6 rounded bg-neutral-100 overflow-hidden">
                        <div class="h-full rounded bg-accent-500/80" :style="{ width: pct(row.revenue, maxHourRevenue) + '%' }"></div>
                    </div>
                    <span class="w-28 shrink-0 text-right text-body-sm tabular-nums">{{ money(row.revenue) }}</span>
                    <span class="w-20 shrink-0 text-right text-body-sm text-neutral-500 tabular-nums">{{ qty(row.count) }} por.</span>
                </div>
            </div>
            <div v-else class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë të dhënë.</div>
        </Card>

        <!-- By weekday -->
        <Card :padding="false" class="mt-6">
            <div class="px-5 py-4 border-b border-neutral-200">
                <h3 class="text-h4 text-primary-900">Sipas ditës së javës</h3>
            </div>
            <table v-if="summary.order_count" class="min-w-full divide-y divide-neutral-200">
                <thead class="bg-neutral-50">
                    <tr>
                        <th class="px-5 py-3 text-left text-label text-neutral-600">Dita</th>
                        <th class="px-5 py-3 text-left text-label text-neutral-600">Të ardhura</th>
                        <th class="px-5 py-3 text-right text-label text-neutral-600">Porosi</th>
                        <th class="px-5 py-3 text-right text-label text-neutral-600">Total</th>
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
                        <td class="px-5 py-3 text-body-sm" colspan="2">Totali</td>
                        <td class="px-5 py-3 text-body-sm text-right tabular-nums">{{ qty(summary.order_count) }}</td>
                        <td class="px-5 py-3 text-body-sm text-right tabular-nums">{{ money(summary.total_revenue) }}</td>
                    </tr>
                </tfoot>
            </table>
            <div v-else class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë të dhënë.</div>
        </Card>
    </ReportShell>
</template>
