<script setup>
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import { BedDouble, BrushCleaning, CircleCheck, Wrench } from 'lucide-vue-next';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    counts: { type: Object, default: () => ({}) },
    currency: { type: String, default: '€' },
});

const statusMeta = {
    available: { label: 'Të lirë', variant: 'success', color: 'text-success-600' },
    occupied: { label: 'Zënë', variant: 'info', color: 'text-info-600' },
    cleaning: { label: 'Pastrim', variant: 'warning', color: 'text-warning-600' },
    maintenance: { label: 'Mirëmbajtje', variant: 'error', color: 'text-error-600' },
};

const meta = (s) => statusMeta[s] ?? { label: s, variant: 'neutral', color: 'text-neutral-600' };

const tiles = [
    { key: 'available', label: 'Të lira', tone: 'success', icon: CircleCheck },
    { key: 'occupied', label: 'Të zëna', tone: 'info', icon: BedDouble },
    { key: 'cleaning', label: 'Në pastrim', tone: 'warning', icon: BrushCleaning },
    { key: 'maintenance', label: 'Mirëmbajtje', tone: 'error', icon: Wrench },
];

const kpis = tiles.map((tile) => ({
    label: tile.label,
    value: () => props.counts[tile.key] ?? 0,
    tone: tile.tone,
    icon: tile.icon,
}));
</script>

<template>
    <ReportShell title="Statusi i Dhomave" :filters="null">
        <ReportKpiGrid :items="kpis" />

        <div class="mt-6">
            <Card :padding="false">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Dhoma</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Kati</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Tipi</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Statusi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in rows" :key="row.id" class="hover:bg-neutral-50">
                            <td class="px-5 py-3 text-body-sm text-primary-900 font-medium">{{ row.room_number }}</td>
                            <td class="px-5 py-3 text-body-sm text-neutral-700">{{ row.floor ?? '—' }}</td>
                            <td class="px-5 py-3 text-body-sm text-neutral-700">{{ row.room_type }}</td>
                            <td class="px-5 py-3 text-body-sm">
                                <Badge :variant="meta(row.status).variant">{{ meta(row.status).label }}</Badge>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rows.length" class="bg-neutral-50 border-t-2 border-neutral-200">
                        <tr class="font-semibold text-neutral-800">
                            <td class="px-5 py-3 text-body-sm" colspan="4">Totali: {{ counts.total ?? rows.length }} dhoma</td>
                        </tr>
                    </tfoot>
                </table>
                <div v-if="!rows.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë të dhënë.</div>
            </Card>
        </div>
    </ReportShell>
</template>
