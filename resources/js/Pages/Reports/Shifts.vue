<script setup>
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import { Banknote, CreditCard, Scale, WalletCards } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    shifts: { type: Array, default: () => [] },
    totals: Object,
    currency: { type: String, default: '€' },
});

const money = (v) => `${props.currency}${Number(v ?? 0).toLocaleString('sq-AL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function overShortVariant(v) {
    return Math.abs(Number(v)) < 0.01 ? 'success' : (Number(v) < 0 ? 'error' : 'warning');
}
function overShortLabel(v) {
    const n = Number(v);
    if (Math.abs(n) < 0.01) return 'Përputhet';
    return n < 0 ? `Mungesë ${money(Math.abs(n))}` : `Tepricë ${money(n)}`;
}

const kpis = [
    { label: 'Turne të mbyllura', value: () => props.shifts.length, tone: 'neutral', icon: WalletCards },
    { label: 'Kesh', value: () => money(props.totals?.cash), tone: 'success', icon: Banknote },
    { label: 'Kartë', value: () => money(props.totals?.card), tone: 'info', icon: CreditCard },
    { label: 'Diferenca', value: () => money(props.totals?.over_short), tone: () => Math.abs(Number(props.totals?.over_short ?? 0)) < 0.01 ? 'success' : 'error', icon: Scale },
];
</script>

<template>
    <ReportShell title="Z-Report / Mbyllje Turni" route-name="reports.shifts" :filters="filters">
        <ReportKpiGrid :items="kpis" />
        <Card :padding="false" class="mt-5">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-label text-neutral-600">Përdoruesi</th>
                            <th class="px-4 py-3 text-left text-label text-neutral-600">Hapur → Mbyllur</th>
                            <th class="px-4 py-3 text-right text-label text-neutral-600">Fondi</th>
                            <th class="px-4 py-3 text-right text-label text-neutral-600">Kesh</th>
                            <th class="px-4 py-3 text-right text-label text-neutral-600">Kartë</th>
                            <th class="px-4 py-3 text-right text-label text-neutral-600">Folio</th>
                            <th class="px-4 py-3 text-right text-label text-neutral-600">Pritur</th>
                            <th class="px-4 py-3 text-right text-label text-neutral-600">Numëruar</th>
                            <th class="px-4 py-3 text-right text-label text-neutral-600">Diferenca</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="s in shifts" :key="s.id" class="hover:bg-neutral-50">
                            <td class="px-4 py-3 text-body-sm text-primary-900 font-medium whitespace-nowrap">{{ s.user || '—' }}</td>
                            <td class="px-4 py-3 text-body-sm text-neutral-500 whitespace-nowrap">{{ s.opened_at }} → {{ s.closed_at }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ money(s.opening_float) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-success-700">{{ money(s.cash_sales) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ money(s.card_sales) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ money(s.room_charge_sales) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-700">{{ money(s.expected_cash) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-primary-900 font-medium">{{ money(s.counted_cash) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap"><Badge :variant="overShortVariant(s.over_short)" size="sm">{{ overShortLabel(s.over_short) }}</Badge></td>
                        </tr>
                    </tbody>
                    <tfoot v-if="shifts.length" class="bg-neutral-50 border-t-2 border-neutral-200">
                        <tr>
                            <td class="px-4 py-3 text-body-sm font-semibold text-primary-900" colspan="3">Totali</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold text-success-700">{{ money(totals.cash) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold">{{ money(totals.card) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold">{{ money(totals.room_charge) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold" colspan="2">{{ money(totals.total) }}</td>
                            <td class="px-4 py-3 text-right"><Badge :variant="overShortVariant(totals.over_short)" size="sm">{{ overShortLabel(totals.over_short) }}</Badge></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div v-if="!shifts.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë turn i mbyllur në këtë periudhë.</div>
        </Card>
    </ReportShell>
</template>
