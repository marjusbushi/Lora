<script setup>
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import { computed } from 'vue';
import { CreditCard, ReceiptText } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    rows: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    currency: { type: String, default: '€' },
});

const money = (v) => `${props.currency}${Number(v ?? 0).toLocaleString('sq-AL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const pct = (v) => `${Number(v ?? 0).toLocaleString('sq-AL', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;

const methodColor = (m) => (m === 'cash' ? 'text-success-700' : 'text-neutral-700');

const kpis = [
    { label: 'Shuma totale', value: () => money(props.summary?.grand_total), tone: 'accent', icon: CreditCard },
    { label: 'Porosi', value: () => Number(props.summary?.order_count ?? 0).toLocaleString('sq-AL'), tone: 'neutral', icon: ReceiptText },
];

const methodBars = computed(() => props.rows.map((row) => ({
    key: row.method,
    label: row.label,
    value: Number(row.total ?? 0),
    display: `${money(row.total)} · ${pct(row.pct)}`,
    detail: `${Number(row.count ?? 0).toLocaleString('sq-AL')} porosi`,
    barClass: row.method === 'cash' ? 'bg-success-500' : row.method === 'card' ? 'bg-info-500' : 'bg-accent-500',
})));
</script>

<template>
    <ReportShell title="Mix i Pagesave POS" route-name="reports.posPaymentMix" :filters="filters">
        <ReportKpiGrid :items="kpis" />

        <p class="text-body-sm text-neutral-500 mt-2 mb-4">
            Ndarja e porosive POS të <span class="font-medium text-neutral-700">përfunduara</span> sipas mënyrës së pagesës në periudhë.
        </p>

        <!-- Payment mix table -->
        <div class="grid gap-4 xl:grid-cols-[minmax(280px,0.65fr)_1.35fr]">
            <ReportBarList title="Shpërndarja e pagesave" description="Pesha e çdo metode në shitjet e përfunduara POS." :rows="methodBars" />
            <Card :padding="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Metoda</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Porosi</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Shuma</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">%</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="r in rows" :key="r.method" class="hover:bg-neutral-50">
                            <td class="px-5 py-3 text-body-sm text-primary-900 font-medium">{{ r.label }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ Number(r.count ?? 0).toLocaleString('sq-AL') }}</td>
                            <td class="px-5 py-3 text-right text-body-sm" :class="methodColor(r.method)">{{ money(r.total) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ pct(r.pct) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rows.length" class="bg-neutral-50 border-t-2 border-neutral-200">
                        <tr>
                            <td class="px-5 py-3 text-body-sm font-semibold text-primary-900">Totali</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold text-primary-900">{{ Number(summary?.order_count ?? 0).toLocaleString('sq-AL') }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold text-primary-900">{{ money(summary?.grand_total) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold text-primary-900">{{ pct(100) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div v-if="!rows.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë të dhënë.</div>
            </Card>
        </div>
    </ReportShell>
</template>
