<script setup>
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import { computed } from 'vue';
import { Banknote, BedDouble, MapPinned, Users } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    rows: { type: Array, default: () => [] },
    totals: Object,
    currency: { type: String, default: '€' },
});

const money = (v) => `${props.currency}${Number(v ?? 0).toLocaleString('sq-AL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const num = (v) => Number(v ?? 0).toLocaleString('sq-AL', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

const kpis = [
    { label: 'Mysafirë', value: () => props.totals?.guests ?? 0, tone: 'accent', icon: Users },
    { label: 'Tregje', value: () => props.rows.length, tone: 'info', icon: MapPinned, detail: 'Kombësi me aktivitet' },
    { label: 'Netë', value: () => props.totals?.nights ?? 0, tone: 'neutral', icon: BedDouble },
    { label: 'Të ardhura', value: () => money(props.totals?.revenue), tone: 'success', icon: Banknote },
];

const marketBars = computed(() => props.rows.slice(0, 8).map((row) => ({
    key: row.nationality,
    label: row.nationality,
    value: Number(row.nights ?? 0),
    display: `${row.nights ?? 0} netë`,
    detail: `${row.guests ?? 0} mysafirë · ${money(row.revenue)}`,
})));
</script>

<template>
    <ReportShell title="Përbërja sipas Kombësisë" route-name="reports.nationality" :filters="filters">
        <ReportKpiGrid :items="kpis" />
        <div class="mt-5 grid gap-4 xl:grid-cols-[minmax(280px,0.65fr)_1.35fr]">
            <ReportBarList title="Tregjet kryesore" description="Renditur sipas netëve të qëndrimit." :rows="marketBars" />
            <Card :padding="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Kombësia</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Mysafirë</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Qëndrime</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Netë</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Të ardhura</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">ALOS</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="r in rows" :key="r.nationality" class="hover:bg-neutral-50">
                            <td class="px-5 py-3 text-body-sm text-primary-900">{{ r.nationality }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ r.guests }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ r.stays }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ r.nights }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-primary-900 font-medium">{{ money(r.revenue) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ num(r.alos) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rows.length" class="bg-neutral-50 border-t-2 border-neutral-200">
                        <tr>
                            <td class="px-5 py-3 text-body-sm font-semibold text-primary-900">Totali</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ totals.guests }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ totals.stays }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ totals.nights }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ money(totals.revenue) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ num(totals.alos) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div v-if="!rows.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë të dhënë.</div>
            </Card>
        </div>
    </ReportShell>
</template>
