<script setup>
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import { CheckCircle2, ClipboardList, Clock3, Gauge } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    byStaff: { type: Array, default: () => [] },
    recent: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    currency: { type: String, default: '€' },
});

const fmtDate = (d) => {
    if (!d) return '—';
    const [y, m, day] = String(d).split('-');
    return `${day}/${m}/${y}`;
};

const statusMeta = (s) => ({
    pending: { label: 'Në pritje', variant: 'warning' },
    in_progress: { label: 'Në proces', variant: 'info' },
    completed: { label: 'Përfunduar', variant: 'success' },
    inspected: { label: 'Inspektuar', variant: 'accent' },
}[s] || { label: s || '—', variant: 'neutral' });

const typeLabel = (t) => ({
    checkout: 'Largim',
    daily: 'Ditore',
    deep: 'I thellë',
    maintenance: 'Mirëmbajtje',
    turndown: 'Mbrëmje',
}[t] || t || '—');

const kpis = [
    { label: 'Detyra gjithsej', value: () => props.summary.total ?? 0, tone: 'accent', icon: ClipboardList },
    { label: 'Përfunduar', value: () => props.summary.completed ?? 0, tone: 'success', icon: CheckCircle2 },
    { label: 'Në pritje', value: () => props.summary.pending ?? 0, tone: 'warning', icon: Clock3 },
    { label: 'Norma e përfundimit', value: () => props.summary.total ? `${Math.round((Number(props.summary.completed ?? 0) / Number(props.summary.total)) * 100)}%` : '0%', tone: 'info', icon: Gauge },
];
</script>

<template>
    <ReportShell title="Raporti i Pastrimit" route-name="reports.housekeepingReport" :filters="filters">
        <ReportKpiGrid :items="kpis" />

        <div class="mt-6">
            <Card :padding="false">
                <div class="px-5 py-4 border-b border-neutral-200">
                    <h3 class="text-body font-semibold text-primary-900">Produktiviteti i Stafit</h3>
                </div>
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Stafi</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Gjithsej</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Përfunduar</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Në pritje</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in byStaff" :key="row.staff" class="hover:bg-neutral-50">
                            <td class="px-5 py-3 text-body-sm text-primary-900 font-medium">{{ row.staff }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ row.total }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-success-700 font-medium">{{ row.completed }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ row.pending }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="byStaff.length" class="bg-neutral-50 border-t-2 border-neutral-200">
                        <tr class="font-semibold text-neutral-800">
                            <td class="px-5 py-3 text-body-sm">Totali</td>
                            <td class="px-5 py-3 text-right text-body-sm">{{ summary.total ?? byStaff.reduce((s, r) => s + (r.total || 0), 0) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm">{{ summary.completed ?? byStaff.reduce((s, r) => s + (r.completed || 0), 0) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm">{{ summary.pending ?? byStaff.reduce((s, r) => s + (r.pending || 0), 0) }}</td>
                        </tr>
                    </tfoot>
                </table>
                <div v-if="!byStaff.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë të dhënë.</div>
            </Card>
        </div>

        <div class="mt-6">
            <Card :padding="false">
                <div class="px-5 py-4 border-b border-neutral-200">
                    <h3 class="text-body font-semibold text-primary-900">Detyrat e Fundit</h3>
                </div>
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Dhoma</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Lloji</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Statusi</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Prioriteti</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Caktuar</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Krijuar</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in recent" :key="row.id" class="hover:bg-neutral-50">
                            <td class="px-5 py-3 text-body-sm text-primary-900 font-medium">{{ row.room }}</td>
                            <td class="px-5 py-3 text-body-sm text-neutral-700">{{ typeLabel(row.type) }}</td>
                            <td class="px-5 py-3 text-body-sm">
                                <Badge :variant="statusMeta(row.status).variant">{{ statusMeta(row.status).label }}</Badge>
                            </td>
                            <td class="px-5 py-3 text-body-sm">
                                <Badge v-if="row.priority === 'urgent'" variant="error">Urgjent</Badge>
                                <span v-else class="text-neutral-500">Normal</span>
                            </td>
                            <td class="px-5 py-3 text-body-sm text-neutral-700">{{ row.assigned }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ fmtDate(row.created) }}</td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!recent.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë të dhënë.</div>
            </Card>
        </div>
    </ReportShell>
</template>
