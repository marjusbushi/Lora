<script setup>
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';

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
    { key: 'available' },
    { key: 'occupied' },
    { key: 'cleaning' },
    { key: 'maintenance' },
];
</script>

<template>
    <ReportShell title="Statusi i Dhomave" :filters="null">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <Card v-for="t in tiles" :key="t.key">
                <div class="text-center">
                    <p :class="['text-h3', meta(t.key).color]">{{ counts[t.key] ?? 0 }}</p>
                    <p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">{{ meta(t.key).label }}</p>
                </div>
            </Card>
        </div>

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
