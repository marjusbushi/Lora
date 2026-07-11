<script setup>
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import { channelMeta } from '@/channels';
import { CalendarClock, CalendarDays, Radio, ReceiptText } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    rows: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    currency: { type: String, default: '€' },
});

const num = (v, d = 1) =>
    Number(v ?? 0).toLocaleString('sq-AL', { minimumFractionDigits: d, maximumFractionDigits: d });

const kpis = [
    { label: 'Rezervime', value: () => Number(props.summary.count ?? 0).toLocaleString('sq-AL'), tone: 'accent', icon: ReceiptText },
    { label: 'Lead-time mesatar', value: () => `${num(props.summary.avg_lead)} ditë`, tone: 'info', icon: CalendarClock, detail: 'Nga rezervimi te hyrja' },
    { label: 'Qëndrim mesatar', value: () => `${num(props.summary.avg_los)} netë`, tone: 'success', icon: CalendarDays },
    { label: 'Kanale aktive', value: () => props.rows.length, tone: 'neutral', icon: Radio },
];
</script>

<template>
    <ReportShell title="Sjellja e Rezervimit" route-name="reports.bookingBehavior" :filters="filters">
        <ReportKpiGrid :items="kpis" />

        <!-- Per-channel table -->
        <Card class="mt-6 overflow-hidden !p-0">
            <table v-if="rows.length" class="w-full">
                <thead class="bg-neutral-50">
                    <tr>
                        <th class="px-5 py-3 text-left text-label text-neutral-600">Kanali</th>
                        <th class="px-5 py-3 text-right text-label text-neutral-600">Rezervime</th>
                        <th class="px-5 py-3 text-right text-label text-neutral-600">Lead-time mesatar (ditë)</th>
                        <th class="px-5 py-3 text-right text-label text-neutral-600">Qëndrim mesatar (netë)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    <tr v-for="row in rows" :key="row.channel">
                        <td class="px-5 py-3 text-body-sm">
                            <span class="inline-flex items-center gap-2">
                                <span
                                    class="h-2.5 w-2.5 rounded-full"
                                    :style="{ backgroundColor: channelMeta(row.channel).color }"
                                />
                                {{ channelMeta(row.channel).label }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-body-sm text-right">
                            {{ Number(row.count ?? 0).toLocaleString('sq-AL') }}
                        </td>
                        <td class="px-5 py-3 text-body-sm text-right">{{ num(row.avg_lead) }}</td>
                        <td class="px-5 py-3 text-body-sm text-right">{{ num(row.avg_los) }}</td>
                    </tr>
                </tbody>
                <tfoot class="bg-neutral-50 border-t-2 font-semibold">
                    <tr>
                        <td class="px-5 py-3 text-body-sm">Gjithsej</td>
                        <td class="px-5 py-3 text-body-sm text-right">
                            {{ Number(summary.count ?? 0).toLocaleString('sq-AL') }}
                        </td>
                        <td class="px-5 py-3 text-body-sm text-right">{{ num(summary.avg_lead) }}</td>
                        <td class="px-5 py-3 text-body-sm text-right">{{ num(summary.avg_los) }}</td>
                    </tr>
                </tfoot>
            </table>
            <div v-else class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë të dhënë.</div>
        </Card>
    </ReportShell>
</template>
