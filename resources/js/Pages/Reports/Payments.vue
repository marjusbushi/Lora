<script setup>
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import { computed } from 'vue';
import { Banknote, CreditCard, Hotel, WalletCards } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    rows: { type: Array, default: () => [] },
    byMethod: { type: Array, default: () => [] },
    totals: Object,
    currency: { type: String, default: '€' },
});

const money = (v) => `${props.currency}${Number(v ?? 0).toLocaleString('sq-AL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function fmtDate(d) {
    if (!d) return '—';
    const [y, m, day] = String(d).split('-');
    return `${day}/${m}/${y}`;
}

const kpis = [
    { label: 'Total i arkëtuar', value: () => money(props.totals?.total), tone: 'accent', icon: WalletCards, detail: 'Cash-flow real i periudhës' },
    { label: 'Kesh', value: () => money(props.totals?.cash), tone: 'success', icon: Banknote },
    { label: 'Kartë', value: () => money(props.totals?.card), tone: 'info', icon: CreditCard },
    { label: 'Faturë dhome', value: () => money(props.totals?.room_charge), tone: 'neutral', icon: Hotel },
];

const methodBars = computed(() => props.byMethod.map((method) => ({
    key: method.method,
    label: method.label,
    value: Number(method.amount ?? 0),
    display: money(method.amount),
    barClass: method.method === 'cash' ? 'bg-success-500' : method.method === 'card' ? 'bg-info-500' : 'bg-accent-500',
})));
</script>

<template>
    <ReportShell title="Arkëtime & Cash" route-name="reports.payments" :filters="filters">
        <ReportKpiGrid :items="kpis" />

        <p class="text-body-sm text-neutral-500 mt-2 mb-4">
            Ky raport tregon paratë e <span class="font-medium text-neutral-700">arkëtuara realisht</span> (cash-flow) në periudhë — i ndryshëm nga të ardhurat e faturuara.
        </p>

        <!-- Collected by method -->
        <div class="mb-4 grid gap-4 xl:grid-cols-[minmax(280px,0.65fr)_1.35fr]">
            <ReportBarList title="Mix i arkëtimeve" description="Shpërndarja e cash-flow sipas mënyrës së pagesës." :rows="methodBars" />
            <Card :padding="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Mënyra e arkëtimit</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Shuma</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="m in byMethod" :key="m.method" class="hover:bg-neutral-50">
                            <td class="px-5 py-3 text-body-sm text-primary-900 font-medium">{{ m.label }}</td>
                            <td class="px-5 py-3 text-right text-body-sm" :class="m.method === 'cash' ? 'text-success-700' : 'text-neutral-700'">{{ money(m.amount) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="byMethod.length" class="bg-neutral-50 border-t-2 border-neutral-200">
                        <tr>
                            <td class="px-5 py-3 text-body-sm font-semibold text-primary-900">Total i arkëtuar</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold text-primary-900">{{ money(totals?.total) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div v-if="!byMethod.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë të dhënë.</div>
            </Card>
        </div>

        <!-- Per-day breakdown -->
        <Card :padding="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Data</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Pagesa kesh</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Pagesa kartë</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">POS (total)</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Total dita</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="r in rows" :key="r.date" class="hover:bg-neutral-50">
                            <td class="px-5 py-3 text-body-sm text-primary-900 font-medium whitespace-nowrap">{{ fmtDate(r.date) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-success-700">{{ money(r.payments_cash) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ money(r.payments_card) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ money(r.pos_total) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-primary-900 font-medium">{{ money(r.total) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rows.length" class="bg-neutral-50 border-t-2 border-neutral-200">
                        <tr>
                            <td class="px-5 py-3 text-body-sm font-semibold text-primary-900">Totali</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold text-success-700">{{ money(totals?.payments_cash) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ money(totals?.payments_card) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ money(totals?.pos_total) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold text-primary-900">{{ money(totals?.total) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div v-if="!rows.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë të dhënë.</div>
        </Card>
    </ReportShell>
</template>
