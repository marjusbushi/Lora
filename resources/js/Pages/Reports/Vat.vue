<script setup>
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import { Banknote, CirclePercent, HandCoins, ReceiptText } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    summary: { type: Object, default: () => ({}) },
    rows: { type: Array, default: () => [] },
    currency: { type: String, default: '€' },
});

const money = (v) => `${props.currency}${Number(v ?? 0).toLocaleString('sq-AL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const monthNames = ['Janar', 'Shkurt', 'Mars', 'Prill', 'Maj', 'Qershor', 'Korrik', 'Gusht', 'Shtator', 'Tetor', 'Nëntor', 'Dhjetor'];
const monthLabel = (m) => {
    if (!m) return '';
    const [year, mm] = String(m).split('-');
    const idx = Number(mm) - 1;
    return `${monthNames[idx] ?? mm} ${year}`;
};

const kpis = [
    { label: 'Bruto me TVSH', value: () => money(props.summary.gross), tone: 'accent', icon: ReceiptText },
    { label: 'TVSH për deklarim', value: () => money(props.summary.vat), tone: 'warning', icon: Banknote, detail: () => `Norma ${props.summary.rate ?? 20}%` },
    { label: 'Neto pa TVSH', value: () => money(props.summary.net), tone: 'success', icon: HandCoins },
    { label: 'Norma e TVSH-së', value: () => `${props.summary.rate ?? 20}%`, tone: 'neutral', icon: CirclePercent },
];

const showMonthly = () => props.rows.length > 1;
</script>

<template>
    <ReportShell title="Raport TVSH" route-name="reports.vat" :filters="filters">
        <ReportKpiGrid :items="kpis" />

        <p class="mt-3 text-body-sm text-neutral-500">
            TVSH-ja është e përfshirë në çmim (norma shqiptare). Bruto = të ardhura dhomash + bar/restorant.
            Të ardhura dhomash: {{ money(summary.room_revenue) }} · Bar/restorant: {{ money(summary.pos_revenue) }}.
        </p>

        <div v-if="showMonthly()" class="mt-6">
            <Card :padding="false">
                <div class="px-5 py-4 border-b border-neutral-200">
                    <h3 class="text-label text-neutral-600 uppercase tracking-wider">Ndarja sipas muajit</h3>
                </div>
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Muaji</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Bruto (me TVSH)</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">TVSH</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Neto (pa TVSH)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in rows" :key="row.month" class="hover:bg-neutral-50">
                            <td class="px-5 py-3 text-body-sm text-neutral-700">{{ monthLabel(row.month) }}</td>
                            <td class="px-5 py-3 text-body-sm text-right text-primary-900">{{ money(row.gross) }}</td>
                            <td class="px-5 py-3 text-body-sm text-right text-neutral-700">{{ money(row.vat) }}</td>
                            <td class="px-5 py-3 text-body-sm text-right text-neutral-700">{{ money(row.net) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-neutral-50 border-t-2 border-neutral-200">
                        <tr class="font-semibold">
                            <td class="px-5 py-3 text-body-sm text-neutral-700">Totali</td>
                            <td class="px-5 py-3 text-body-sm text-right text-primary-900">{{ money(summary.gross) }}</td>
                            <td class="px-5 py-3 text-body-sm text-right text-neutral-700">{{ money(summary.vat) }}</td>
                            <td class="px-5 py-3 text-body-sm text-right text-neutral-700">{{ money(summary.net) }}</td>
                        </tr>
                    </tfoot>
                </table>
                <div v-if="!rows.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë të dhënë.</div>
            </Card>
        </div>

        <div v-else-if="!rows.length" class="mt-6">
            <Card :padding="false">
                <div class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë të dhënë.</div>
            </Card>
        </div>
    </ReportShell>
</template>
