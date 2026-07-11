<script setup>
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import { channelMeta } from '@/channels';
import { AlertTriangle, Ban, CirclePercent, WalletCards } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    summary: Object,
    cancelled: { type: Array, default: () => [] },
    noShows: { type: Array, default: () => [] },
    currency: { type: String, default: '€' },
});

const money = (v) => `${props.currency}${Number(v ?? 0).toLocaleString('sq-AL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const kpis = [
    { label: 'Anulime', value: () => props.summary.cancelled_count, tone: 'error', icon: Ban, detail: () => `${props.summary.total_count ?? 0} rezervime në bazë` },
    { label: 'Vlera e anuluar', value: () => money(props.summary.cancelled_value), tone: 'warning', icon: WalletCards },
    { label: 'Norma e anulimit', value: () => `${props.summary.cancellation_rate}%`, tone: 'neutral', icon: CirclePercent },
    { label: 'Mundësi no-show', value: () => props.summary.no_show_count, tone: 'warning', icon: AlertTriangle, detail: 'Kërkojnë verifikim' },
];
</script>

<template>
    <ReportShell title="Anulime & No-Show" route-name="reports.cancellations" :filters="filters">
        <ReportKpiGrid :items="kpis" />

        <p class="mt-2 text-tiny text-neutral-500">
            Norma e anulimit = anulime ÷ {{ summary.total_count }} rezervime me hyrje në këtë periudhë.
        </p>

        <!-- Cancelled reservations -->
        <div class="mt-6">
            <Card :padding="false">
                <div class="px-5 py-4 border-b border-neutral-200">
                    <h3 class="text-label text-neutral-600 uppercase tracking-wider">Rezervime të anuluara</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200">
                        <thead class="bg-neutral-50">
                            <tr>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Mysafiri</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Dhoma</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Hyrja</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Dalja</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Kanali</th>
                                <th class="px-5 py-3 text-right text-label text-neutral-600">Vlera</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="r in cancelled" :key="r.id" class="hover:bg-neutral-50">
                                <td class="px-5 py-3 text-body-sm text-primary-900">{{ r.guest }}</td>
                                <td class="px-5 py-3 text-body-sm text-neutral-700">{{ r.room || '—' }}</td>
                                <td class="px-5 py-3 text-body-sm text-neutral-700">{{ r.check_in }}</td>
                                <td class="px-5 py-3 text-body-sm text-neutral-700">{{ r.check_out }}</td>
                                <td class="px-5 py-3 text-body-sm">
                                    <span class="inline-flex items-center gap-2 text-primary-900">
                                        <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: channelMeta(r.channel).color }" />
                                        {{ channelMeta(r.channel).label }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-right text-body-sm text-error-600 font-medium">{{ money(r.value) }}</td>
                            </tr>
                        </tbody>
                        <tfoot v-if="cancelled.length" class="bg-neutral-50 border-t-2 border-neutral-200">
                            <tr>
                                <td class="px-5 py-3 text-body-sm font-semibold text-primary-900" colspan="5">Totali ({{ summary.cancelled_count }})</td>
                                <td class="px-5 py-3 text-right text-body-sm font-semibold text-error-600">{{ money(summary.cancelled_value) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div v-if="!cancelled.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë anulim në këtë periudhë.</div>
            </Card>
        </div>

        <!-- No-show candidates -->
        <div class="mt-6">
            <Card :padding="false">
                <div class="px-5 py-4 border-b border-neutral-200">
                    <h3 class="text-label text-neutral-600 uppercase tracking-wider">Mundësi no-show</h3>
                    <p class="mt-1 text-tiny text-neutral-500">
                        Vlerësim: rezervime «në pritje» ose «të konfirmuara» me hyrje të kaluar — verifikoji para se t'i mbyllësh.
                    </p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200">
                        <thead class="bg-neutral-50">
                            <tr>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Mysafiri</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Dhoma</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Hyrja</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Dalja</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Kanali</th>
                                <th class="px-5 py-3 text-right text-label text-neutral-600">Vlera</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="r in noShows" :key="r.id" class="hover:bg-neutral-50">
                                <td class="px-5 py-3 text-body-sm text-primary-900">{{ r.guest }}</td>
                                <td class="px-5 py-3 text-body-sm text-neutral-700">{{ r.room || '—' }}</td>
                                <td class="px-5 py-3 text-body-sm text-neutral-700">{{ r.check_in }}</td>
                                <td class="px-5 py-3 text-body-sm text-neutral-700">{{ r.check_out }}</td>
                                <td class="px-5 py-3 text-body-sm">
                                    <span class="inline-flex items-center gap-2 text-primary-900">
                                        <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: channelMeta(r.channel).color }" />
                                        {{ channelMeta(r.channel).label }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-right text-body-sm text-primary-900 font-medium">{{ money(r.value) }}</td>
                            </tr>
                        </tbody>
                        <tfoot v-if="noShows.length" class="bg-neutral-50 border-t-2 border-neutral-200">
                            <tr>
                                <td class="px-5 py-3 text-body-sm font-semibold text-primary-900" colspan="5">Totali ({{ summary.no_show_count }})</td>
                                <td class="px-5 py-3 text-right text-body-sm font-semibold text-primary-900">{{ money(summary.no_show_value) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div v-if="!noShows.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë mundësi no-show.</div>
            </Card>
        </div>
    </ReportShell>
</template>
