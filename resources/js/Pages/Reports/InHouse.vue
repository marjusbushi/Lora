<script setup>
import { Link } from '@inertiajs/vue3';
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import { BedDouble, CalendarDays, Users, UserRound } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    rows: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    currency: { type: String, default: '€' },
});

const fmt = (d) => d
    ? new Date(d).toLocaleDateString('sq-AL', { weekday: 'short', day: '2-digit', month: 'short' })
    : '—';

const totalNights = () => props.rows.reduce((sum, row) => sum + Number(row.nights ?? 0), 0);
const kpis = [
    { label: 'Dhoma të zëna', value: () => props.summary?.count ?? 0, tone: 'accent', icon: BedDouble },
    { label: 'Persona gjithsej', value: () => props.summary?.pax ?? 0, tone: 'info', icon: Users },
    { label: 'Mesatarja për dhomë', value: () => props.summary?.count ? (Number(props.summary.pax ?? 0) / Number(props.summary.count)).toLocaleString('sq-AL', { maximumFractionDigits: 1 }) : '0', tone: 'neutral', icon: UserRound },
    { label: 'Netë të rezervuara', value: totalNights, tone: 'success', icon: CalendarDays },
];
</script>

<template>
    <ReportShell title="Mysafirë në Shtëpi" :filters="null">
        <ReportKpiGrid :items="kpis" />

        <Card :padding="false" class="mt-5">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Mysafiri</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Dhoma</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Check-in</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Check-out</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Netë</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Persona</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="r in rows" :key="r.id" class="hover:bg-neutral-50">
                            <td class="px-5 py-3">
                                <Link :href="route('reservations.show', r.id)" class="text-body-sm text-primary-900 font-medium hover:underline">{{ r.guest }}</Link>
                                <p v-if="r.phone" class="text-tiny text-neutral-400">{{ r.phone }}</p>
                            </td>
                            <td class="px-5 py-3">
                                <p class="text-body-sm text-neutral-700">{{ r.room || '—' }}</p>
                                <p v-if="r.room_type" class="text-tiny text-neutral-400">{{ r.room_type }}</p>
                            </td>
                            <td class="px-5 py-3 text-body-sm text-neutral-700 whitespace-nowrap">{{ fmt(r.check_in) }}</td>
                            <td class="px-5 py-3 text-body-sm text-neutral-700 whitespace-nowrap">{{ fmt(r.check_out) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ r.nights }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700 whitespace-nowrap">
                                {{ r.pax }}
                                <span class="text-tiny text-neutral-400">({{ r.adults }}+{{ r.children }})</span>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rows.length" class="bg-neutral-50 border-t-2 border-neutral-200">
                        <tr class="font-semibold">
                            <td class="px-5 py-3 text-body-sm text-neutral-700" colspan="4">Gjithsej ({{ summary?.count ?? 0 }})</td>
                            <td class="px-5 py-3"></td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ summary?.pax ?? 0 }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div v-if="!rows.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë të dhënë.</div>
        </Card>
    </ReportShell>
</template>
