<script setup>
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import { Ban, ReceiptText } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    rows: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    currency: { type: String, default: '€' },
});

const money = (v) =>
    `${props.currency}${Number(v ?? 0).toLocaleString('sq-AL', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;

const kpis = [
    { label: 'Porosi të anuluara', value: () => props.summary.count ?? 0, tone: 'error', icon: Ban },
    { label: 'Vlera e anuluar', value: () => money(props.summary.total), tone: 'warning', icon: ReceiptText, detail: 'Shitje të humbura' },
];
</script>

<template>
    <ReportShell title="Anulime & Voids POS" route-name="reports.posVoids" :filters="filters">
        <ReportKpiGrid :items="kpis" />

        <Card class="mt-5">
            <div v-if="rows.length" class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Tavolina</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Vlera</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Data</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Nga</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-t border-neutral-100">
                            <td class="px-5 py-3 text-body-sm">
                                <Badge v-if="row.table_number">Tavolina {{ row.table_number }}</Badge>
                                <span v-else class="text-neutral-400">—</span>
                            </td>
                            <td class="px-5 py-3 text-body-sm text-right font-medium text-rose-600">{{ money(row.total_amount) }}</td>
                            <td class="px-5 py-3 text-body-sm">{{ row.created_at }}</td>
                            <td class="px-5 py-3 text-body-sm">{{ row.created_by }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-neutral-50 border-t-2 font-semibold">
                        <tr>
                            <td class="px-5 py-3 text-body-sm">Totali ({{ summary.count ?? 0 }})</td>
                            <td class="px-5 py-3 text-body-sm text-right text-rose-600">{{ money(summary.total) }}</td>
                            <td class="px-5 py-3"></td>
                            <td class="px-5 py-3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div v-else class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë të dhënë.</div>
        </Card>
    </ReportShell>
</template>
