<script setup>
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';

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

const kpis = [
    { label: 'Të ardhura totale', value: () => money(props.summary.total_revenue), accent: true },
    { label: 'Të ardhura dhomash', value: () => money(props.summary.room_revenue) },
    { label: 'Të ardhura bar/restorant', value: () => money(props.summary.pos_revenue) },
    { label: 'Mbushja', value: () => `${props.summary.occupancy}%` },
    { label: 'ADR (çmimi mesatar/natë)', value: () => money(props.summary.adr) },
    { label: 'RevPAR', value: () => money(props.summary.revpar) },
    { label: 'Netë të shitura', value: () => props.summary.nights_sold },
    { label: 'Rezervime', value: () => props.summary.reservation_count },
    { label: 'Komisioni OTA', value: () => money(props.summary.commission) },
    { label: 'Neto pas komisionit', value: () => money(props.summary.net_room_revenue) },
    { label: 'TVSH (e përfshirë)', value: () => money(props.summary.vat) },
    { label: 'Neto pa TVSH', value: () => money(props.summary.net_revenue) },
];
</script>

<template>
    <ReportShell title="Pasqyra Ekzekutive" route-name="reports.executive" :filters="filters">
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-3">
            <Card v-for="k in kpis" :key="k.label">
                <div class="text-center">
                    <p :class="['text-h3 truncate', k.accent ? 'text-accent-600' : 'text-primary-900']">{{ k.value() }}</p>
                    <p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">{{ k.label }}</p>
                </div>
            </Card>
        </div>

        <div class="mt-6">
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
