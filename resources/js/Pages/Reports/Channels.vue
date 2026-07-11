<script setup>
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import { channelMeta } from '@/channels';
import { computed } from 'vue';
import { Banknote, CalendarCheck, HandCoins, Percent } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    rows: { type: Array, default: () => [] },
    totals: Object,
    currency: { type: String, default: '€' },
});

const money = (v) => `${props.currency}${Number(v ?? 0).toLocaleString('sq-AL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const commissionRate = () => Number(props.totals?.revenue ?? 0) > 0
    ? `${((Number(props.totals?.commission ?? 0) / Number(props.totals.revenue)) * 100).toLocaleString('sq-AL', { maximumFractionDigits: 1 })}%`
    : '0%';

const kpis = [
    { label: 'Rezervime', value: () => props.totals?.count ?? 0, tone: 'info', icon: CalendarCheck, detail: `${props.totals?.nights ?? 0} netë` },
    { label: 'Të ardhura bruto', value: () => money(props.totals?.revenue), tone: 'accent', icon: Banknote },
    { label: 'Komision', value: () => money(props.totals?.commission), tone: 'warning', icon: Percent, detail: () => commissionRate() },
    { label: 'Të ardhura neto', value: () => money(props.totals?.net), tone: 'success', icon: HandCoins },
];

const channelBars = computed(() => props.rows.map((row) => ({
    key: row.channel,
    label: channelMeta(row.channel).label,
    value: Number(row.revenue ?? 0),
    display: money(row.revenue),
    detail: `${row.count ?? 0} rezervime · neto ${money(row.net)}`,
})));
</script>

<template>
    <ReportShell title="Prodhimi sipas Kanaleve" route-name="reports.channels" :filters="filters">
        <ReportKpiGrid :items="kpis" />
        <div class="mt-5 grid gap-4 xl:grid-cols-[minmax(280px,0.65fr)_1.35fr]">
            <ReportBarList title="Kontributi sipas kanalit" description="Të ardhurat bruto që sjell çdo burim rezervimi." :rows="channelBars" />
            <Card :padding="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Kanali</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Rezervime</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Netë</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Të ardhura</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Komision</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Neto</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="r in rows" :key="r.channel" class="hover:bg-neutral-50">
                            <td class="px-5 py-3">
                                <span class="inline-flex items-center gap-2 text-body-sm text-primary-900">
                                    <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: channelMeta(r.channel).color }" />
                                    {{ channelMeta(r.channel).label }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ r.count }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ r.nights }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-primary-900 font-medium">{{ money(r.revenue) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-error-600">{{ r.commission ? '−' + money(r.commission) : '—' }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-primary-900">{{ money(r.net) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rows.length" class="bg-neutral-50 border-t-2 border-neutral-200">
                        <tr>
                            <td class="px-5 py-3 text-body-sm font-semibold text-primary-900">Totali</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ totals.count }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ totals.nights }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ money(totals.revenue) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold text-error-600">{{ totals.commission ? '−' + money(totals.commission) : '—' }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ money(totals.net) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div v-if="!rows.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë rezervim në këtë periudhë.</div>
            </Card>
        </div>
    </ReportShell>
</template>
