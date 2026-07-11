<script setup>
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import { computed } from 'vue';
import { Banknote, BedDouble, ChartNoAxesCombined, Percent } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    summary: Object,
    byStatus: { type: Array, default: () => [] },
    currency: { type: String, default: '€' },
});

const money = (v) => `${props.currency}${Number(v ?? 0).toLocaleString('sq-AL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const statusBadge = {
    pending: { variant: 'warning', label: 'Në pritje' },
    confirmed: { variant: 'info', label: 'Konfirmuar' },
    checked_in: { variant: 'success', label: 'Brenda' },
    checked_out: { variant: 'neutral', label: 'Larguar' },
    cancelled: { variant: 'error', label: 'Anulluar' },
};

const primaryKpis = [
    { label: 'Të ardhura totale', value: () => money(props.summary.total_revenue), tone: 'accent', icon: Banknote, detail: 'Dhoma + bar/restorant' },
    { label: 'Mbushja', value: () => `${props.summary.occupancy}%`, tone: 'info', icon: BedDouble, detail: `${props.summary.nights_sold ?? 0} netë të shitura` },
    { label: 'ADR', value: () => money(props.summary.adr), tone: 'neutral', icon: ChartNoAxesCombined, detail: 'Çmimi mesatar për natë' },
    { label: 'RevPAR', value: () => money(props.summary.revpar), tone: 'success', icon: Percent, detail: 'Të ardhura për dhomë të disponueshme' },
];

const secondaryKpis = [
    { label: 'Rezervime', value: () => props.summary.reservation_count },
    { label: 'Komisioni OTA', value: () => money(props.summary.commission), tone: 'warning' },
    { label: 'Neto pas komisionit', value: () => money(props.summary.net_room_revenue), tone: 'success' },
    { label: 'TVSH e përfshirë', value: () => money(props.summary.vat) },
];

const revenueMix = computed(() => [
    { key: 'rooms', label: 'Dhoma', value: Number(props.summary.room_revenue ?? 0), display: money(props.summary.room_revenue), barClass: 'bg-accent-600' },
    { key: 'pos', label: 'Bar & restorant', value: Number(props.summary.pos_revenue ?? 0), display: money(props.summary.pos_revenue), barClass: 'bg-info-500' },
]);
</script>

<template>
    <ReportShell title="Pasqyra Ekzekutive" route-name="reports.executive" :filters="filters">
        <ReportKpiGrid :items="primaryKpis" />

        <div class="mt-4">
            <ReportKpiGrid :items="secondaryKpis" />
        </div>

        <div class="mt-6 grid gap-4 xl:grid-cols-[minmax(280px,0.7fr)_1.3fr]">
            <ReportBarList
                title="Përbërja e të ardhurave"
                description="Nga cilat operacione vjen xhiroja e periudhës."
                :rows="revenueMix"
            />
            <Card :padding="false">
                <div class="px-5 py-4 border-b border-neutral-200">
                    <h3 class="text-label text-neutral-600 uppercase tracking-wider">Sipas statusit (hyrje në periudhë)</h3>
                </div>
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Statusi</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Rezervime</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Të ardhura</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in byStatus" :key="row.status" class="hover:bg-neutral-50">
                            <td class="px-5 py-3"><Badge :variant="statusBadge[row.status]?.variant || 'neutral'">{{ statusBadge[row.status]?.label || row.status }}</Badge></td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ row.count }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-primary-900">{{ money(row.revenue) }}</td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!byStatus.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë rezervim në këtë periudhë.</div>
            </Card>
        </div>
    </ReportShell>
</template>
