<script setup>
import { Link } from '@inertiajs/vue3';
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import { Banknote, BedDouble, RefreshCcw, Users } from 'lucide-vue-next';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    currency: { type: String, default: '€' },
});

const money = (v) => `${props.currency}${Number(v ?? 0).toLocaleString('sq-AL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const fmtDate = (d) => {
    if (!d) return '—';
    const [y, m, day] = String(d).split('-');
    return `${day}/${m}/${y}`;
};

const kpis = [
    { label: 'Mysafirë gjithsej', value: () => props.summary.total_guests ?? 0, tone: 'accent', icon: Users },
    { label: 'Mysafirë të përsëritur', value: () => props.summary.repeat_guests ?? 0, tone: 'success', icon: RefreshCcw },
    { label: 'Netë gjithsej', value: () => props.summary.total_nights ?? 0, tone: 'info', icon: BedDouble },
    { label: 'Të ardhura gjithsej', value: () => money(props.summary.total_revenue), tone: 'neutral', icon: Banknote },
];
</script>

<template>
    <ReportShell title="Direktoria e Mysafirëve" route-name="reports.guests" :filters="null">
        <ReportKpiGrid :items="kpis" />

        <div class="mt-6">
            <Card :padding="false">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Mysafiri</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Telefon</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Kombësia</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Qëndrime</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Netë</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Shpenzuar</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Vizita e fundit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in rows" :key="row.id" class="hover:bg-neutral-50">
                            <td class="px-5 py-3 text-body-sm">
                                <Link :href="route('guests.show', row.id)" class="text-primary-900 font-medium hover:underline">{{ row.guest }}</Link>
                                <p v-if="row.email" class="text-tiny text-neutral-500">{{ row.email }}</p>
                            </td>
                            <td class="px-5 py-3 text-body-sm text-neutral-700">{{ row.phone || '—' }}</td>
                            <td class="px-5 py-3 text-body-sm text-neutral-700">{{ row.nationality }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ row.stays }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ row.nights }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-primary-900 font-medium">{{ money(row.total_spent) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ fmtDate(row.last_visit) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rows.length" class="bg-neutral-50 border-t-2 border-neutral-200">
                        <tr class="font-semibold text-neutral-800">
                            <td class="px-5 py-3 text-body-sm" colspan="3">Totali ({{ summary.total_guests ?? rows.length }} mysafirë)</td>
                            <td class="px-5 py-3 text-right text-body-sm">{{ rows.reduce((s, r) => s + (r.stays || 0), 0) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm">{{ summary.total_nights ?? 0 }}</td>
                            <td class="px-5 py-3 text-right text-body-sm">{{ money(summary.total_revenue) }}</td>
                            <td class="px-5 py-3"></td>
                        </tr>
                    </tfoot>
                </table>
                <div v-if="!rows.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë të dhënë.</div>
            </Card>
        </div>
    </ReportShell>
</template>
