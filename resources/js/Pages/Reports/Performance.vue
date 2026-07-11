<script setup>
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import { computed } from 'vue';
import { Banknote, BedDouble, ChartNoAxesCombined, Percent } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    rows: { type: Array, default: () => [] },
    totals: Object,
    currency: { type: String, default: '€' },
});

const money = (v) => `${props.currency}${Number(v ?? 0).toLocaleString('sq-AL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const pct = (v) => `${Number(v ?? 0).toLocaleString('sq-AL', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;

const kpis = [
    { label: 'ADR', value: () => money(props.totals?.adr), tone: 'accent', icon: ChartNoAxesCombined, detail: 'Çmimi mesatar për natë' },
    { label: 'RevPAR', value: () => money(props.totals?.revpar), tone: 'success', icon: Banknote, detail: 'Për dhomë të disponueshme' },
    { label: 'Mbushja', value: () => pct(props.totals?.occupancy), tone: 'info', icon: Percent, detail: `${props.totals?.nights ?? 0} netë të shitura` },
    { label: 'Të ardhura', value: () => money(props.totals?.revenue), tone: 'neutral', icon: BedDouble, detail: 'Të ardhura nga dhomat' },
];

const occupancyBars = computed(() => props.rows.map((row) => ({
    key: row.type,
    label: row.type,
    value: Number(row.occupancy ?? 0),
    display: pct(row.occupancy),
    detail: `${row.nights ?? 0} netë · ${money(row.revenue)}`,
    barClass: Number(row.occupancy ?? 0) >= 80 ? 'bg-success-500' : Number(row.occupancy ?? 0) >= 50 ? 'bg-accent-500' : 'bg-warning-500',
})));
</script>

<template>
    <ReportShell title="ADR / RevPAR / Mbushja" route-name="reports.performance" :filters="filters">
        <ReportKpiGrid :items="kpis" />

        <div class="mt-5 grid gap-4 xl:grid-cols-[minmax(280px,0.65fr)_1.35fr]">
            <ReportBarList title="Mbushja sipas tipologjisë" description="Krahasimi i inventarit që po performon më mirë." :rows="occupancyBars" />
            <Card :padding="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Tipi</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Dhoma</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Netë</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Të ardhura</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">ADR</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Mbushja %</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">RevPAR</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="r in rows" :key="r.type" class="hover:bg-neutral-50">
                            <td class="px-5 py-3 text-body-sm text-primary-900 font-medium">{{ r.type }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ r.rooms_count }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ r.nights }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-primary-900 font-medium">{{ money(r.revenue) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ money(r.adr) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ pct(r.occupancy) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-primary-900">{{ money(r.revpar) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rows.length" class="bg-neutral-50 border-t-2 border-neutral-200">
                        <tr>
                            <td class="px-5 py-3 text-body-sm font-semibold text-primary-900">Totali</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ totals.rooms_count }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ totals.nights }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ money(totals.revenue) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ money(totals.adr) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ pct(totals.occupancy) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ money(totals.revpar) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div v-if="!rows.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë të dhënë.</div>
            </Card>
        </div>
    </ReportShell>
</template>
