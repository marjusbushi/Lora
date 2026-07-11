<script setup>
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import { computed } from 'vue';
import { Banknote, BedDouble, CalendarClock, ReceiptText } from 'lucide-vue-next';

const props = defineProps({
    horizons: { type: Array, default: () => [] },
    next14: { type: Array, default: () => [] },
    currency: { type: String, default: '€' },
});

const money = (v) => `${props.currency}${Number(v ?? 0).toLocaleString('sq-AL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const fmtDate = (d) => {
    if (!d) return '';
    const date = new Date(d + 'T00:00:00');
    return date.toLocaleDateString('sq-AL', { weekday: 'short', day: '2-digit', month: 'short' });
};

const totals = () => ({
    bookings: props.next14.reduce((s, r) => s + Number(r.rooms ?? 0), 0),
    revenue: props.next14.reduce((s, r) => s + Number(r.revenue ?? 0), 0),
});

const kpis = [
    { label: 'Rezervime · 14 ditë', value: () => totals().bookings, tone: 'accent', icon: ReceiptText },
    { label: 'Të ardhura · 14 ditë', value: () => money(totals().revenue), tone: 'success', icon: Banknote },
    { label: 'Netë · 30 ditë', value: () => props.horizons.find((item) => item.days === 30)?.nights ?? 0, tone: 'info', icon: BedDouble },
    { label: 'ADR · 30 ditë', value: () => money(props.horizons.find((item) => item.days === 30)?.adr), tone: 'neutral', icon: CalendarClock },
];

const horizonBars = computed(() => props.horizons.map((item) => ({
    key: item.days,
    label: `${item.days} ditët e ardhshme`,
    value: Number(item.revenue ?? 0),
    display: money(item.revenue),
    detail: `${item.bookings ?? 0} rezervime · ${item.nights ?? 0} netë`,
})));
</script>

<template>
    <ReportShell title="Tempo & Pickup" route-name="reports.pace" :filters="null">
        <ReportKpiGrid :items="kpis" />
        <ReportBarList class="mt-5" title="Të ardhura në libër sipas horizontit" description="Sa vlerë është rezervuar tashmë për secilën dritare të ardhshme." :rows="horizonBars" />

        <!-- Horizon breakdown table -->
        <div class="mt-6">
            <Card :padding="false">
                <div class="px-5 py-4 border-b border-neutral-200">
                    <h3 class="text-label text-neutral-600 uppercase tracking-wider">Të rezervuara përpara (nga sot)</h3>
                </div>
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Horizonti</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Deri më</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Rezervime</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Netë</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Të ardhura</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">ADR</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="h in horizons" :key="h.days" class="hover:bg-neutral-50">
                            <td class="px-5 py-3 text-body-sm text-primary-900 font-medium">{{ h.days }} ditë</td>
                            <td class="px-5 py-3 text-body-sm text-neutral-600">{{ fmtDate(h.until) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ h.bookings }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ h.nights }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-primary-900">{{ money(h.revenue) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-600">{{ money(h.adr) }}</td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!horizons.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë të dhënë.</div>
            </Card>
        </div>

        <!-- Next 14 days arrivals/occupancy -->
        <div class="mt-6">
            <Card :padding="false">
                <div class="px-5 py-4 border-b border-neutral-200">
                    <h3 class="text-label text-neutral-600 uppercase tracking-wider">14 ditët e ardhshme</h3>
                </div>
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Data</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Dhoma të zëna</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Të ardhura (hyrje)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in next14" :key="row.date" class="hover:bg-neutral-50">
                            <td class="px-5 py-3 text-body-sm text-neutral-700 capitalize">{{ fmtDate(row.date) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ row.rooms }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-primary-900">{{ money(row.revenue) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="next14.length" class="bg-neutral-50 border-t-2 border-neutral-200">
                        <tr class="font-semibold">
                            <td class="px-5 py-3 text-body-sm text-neutral-700">Totali (14 ditë)</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ totals().bookings }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-primary-900">{{ money(totals().revenue) }}</td>
                        </tr>
                    </tfoot>
                </table>
                <div v-if="!next14.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë të dhënë.</div>
            </Card>
        </div>
    </ReportShell>
</template>
