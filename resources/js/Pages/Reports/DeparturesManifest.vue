<script setup>
import { Link } from '@inertiajs/vue3';
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import { AlertTriangle, CalendarCheck, ReceiptText, Utensils } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    rows: { type: Array, default: () => [] },
    totals: Object,
    currency: { type: String, default: '€' },
});

const money = (v) => `${props.currency}${Number(v ?? 0).toLocaleString('sq-AL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const statusBadge = {
    checked_in: { variant: 'success', label: 'Brenda' },
    checked_out: { variant: 'neutral', label: 'Larguar' },
};

const fmt = (d) => d ? new Date(d).toLocaleDateString('sq-AL', { day: '2-digit', month: 'short' }) : '—';

const kpis = [
    { label: 'Nisje', value: () => props.totals?.count ?? 0, tone: 'accent', icon: CalendarCheck, detail: 'Dhomat që duhen liruar' },
    { label: 'Bilanc i hapur', value: () => money(props.totals?.outstanding), tone: () => Number(props.totals?.outstanding ?? 0) > 0 ? 'error' : 'success', icon: ReceiptText, detail: 'Për t’u arkëtuar para nisjes' },
    { label: 'Me detyrim', value: () => props.rows.filter((row) => Number(row.balance ?? 0) > 0).length, tone: 'warning', icon: AlertTriangle },
    { label: 'POS të hapura', value: () => props.rows.reduce((sum, row) => sum + Number(row.open_pos_count ?? 0), 0), tone: 'neutral', icon: Utensils },
];
</script>

<template>
    <ReportShell title="Manifesti i Nisjeve" route-name="reports.departuresManifest" :filters="filters">
        <ReportKpiGrid :items="kpis" />

        <Card :padding="false" class="mt-5">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Nisja</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Mysafiri</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Dhoma</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Statusi</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Porosi POS të Hapura</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Mbetet</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="r in rows" :key="r.id" class="hover:bg-neutral-50" :class="r.balance > 0 ? 'bg-error-50/40' : ''">
                            <td class="px-5 py-3 text-body-sm text-neutral-700 whitespace-nowrap">{{ fmt(r.check_out) }}</td>
                            <td class="px-5 py-3">
                                <Link :href="route('reservations.show', r.id)" class="text-body-sm text-primary-900 font-medium hover:underline">{{ r.guest }}</Link>
                                <p v-if="r.phone" class="text-tiny text-neutral-400">{{ r.phone }}</p>
                            </td>
                            <td class="px-5 py-3 text-body-sm text-neutral-700">{{ r.room || '—' }}</td>
                            <td class="px-5 py-3"><Badge :variant="statusBadge[r.status]?.variant || 'neutral'" size="sm">{{ statusBadge[r.status]?.label || r.status }}</Badge></td>
                            <td class="px-5 py-3 text-right">
                                <Badge v-if="r.open_pos_count > 0" variant="warning" size="sm">{{ r.open_pos_count }}</Badge>
                                <span v-else class="text-body-sm text-neutral-400">—</span>
                            </td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold" :class="r.balance > 0 ? 'text-error-600' : 'text-success-700'">{{ money(r.balance) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rows.length" class="bg-neutral-50 border-t-2 border-neutral-200">
                        <tr class="font-semibold">
                            <td class="px-5 py-3 text-body-sm text-neutral-700" colspan="5">{{ totals?.count ?? rows.length }} nisje gjithsej</td>
                            <td class="px-5 py-3 text-right text-body-sm" :class="(totals?.outstanding ?? 0) > 0 ? 'text-error-600' : 'text-success-700'">{{ money(totals?.outstanding) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div v-if="!rows.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë të dhënë.</div>
        </Card>
    </ReportShell>
</template>
