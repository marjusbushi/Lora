<script setup>
import { getIntlLocale, translate } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import { Banknote, CreditCard, Scale, WalletCards } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';
import { useReportDrilldown } from '@/composables/useReportDrilldown';

const props = defineProps({
    filters: Object,
    shifts: { type: Array, default: () => [] },
    totals: Object,
    currency: { type: String, default: '€' },
});
const { can, hasModule } = useReportDrilldown();
const shiftHref = (shift) => can('view_pos_orders') && hasModule('pos') ? route('pos.shifts', { shift: shift.id }) : null;
const shiftsHref = () => can('view_pos_orders') && hasModule('pos') ? route('pos.shifts') : null;

const money = (v) => `${props.currency}${Number(v ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function overShortVariant(v) {
    return Math.abs(Number(v)) < 0.01 ? 'success' : (Number(v) < 0 ? 'error' : 'warning');
}
function overShortLabel(v) {
    const n = Number(v);
    if (Math.abs(n) < 0.01) return translate('admin.generated.k_e5c6990e9dc3');
    return n < 0 ? translate('admin.generated.k_796c1bfab32f', { p0: money(Math.abs(n)) }) : translate('admin.generated.k_82f411863fed', { p0: money(n) });
}

const kpis = [
    { label: translate('admin.generated.k_c415521f6211'), value: () => props.shifts.length, tone: 'neutral', icon: WalletCards, href: shiftsHref() },
    { label: translate('admin.generated.k_e9c1e13432f8'), value: () => money(props.totals?.cash), tone: 'success', icon: Banknote, href: shiftsHref() },
    { label: translate('admin.generated.k_a2daff3a669c'), value: () => money(props.totals?.card), tone: 'info', icon: CreditCard, href: shiftsHref() },
    { label: translate('admin.generated.k_1d57e3177577'), value: () => money(props.totals?.over_short), tone: () => Math.abs(Number(props.totals?.over_short ?? 0)) < 0.01 ? 'success' : 'error', icon: Scale, href: shiftsHref() },
];
</script>

<template>
    <ReportShell :title="$t('admin.generated.k_5041fe965762')" route-name="reports.shifts" :filters="filters">
        <ReportKpiGrid :items="kpis" />
        <Card :padding="false" class="mt-5">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_d0f5ffb41b89') }}</th>
                            <th class="px-4 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_f679ed28809f') }}</th>
                            <th class="px-4 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_e1f3b5bdd562') }}</th>
                            <th class="px-4 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_f2e74daa2bb6') }}</th>
                            <th class="px-4 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_54f0590a062f') }}</th>
                            <th class="px-4 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_14089c0e1e5b') }}</th>
                            <th class="px-4 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_620630b38207') }}</th>
                            <th class="px-4 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_0ec342ed75e4') }}</th>
                            <th class="px-4 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_a912b90a105a') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="s in shifts" :key="s.id" class="hover:bg-neutral-50">
                            <td class="px-4 py-3 text-body-sm text-primary-900 font-medium whitespace-nowrap"><Link v-if="shiftHref(s)" :href="shiftHref(s)" class="hover:underline">{{ s.user || '—' }}</Link><span v-else>{{ s.user || '—' }}</span></td>
                            <td class="px-4 py-3 text-body-sm text-neutral-500 whitespace-nowrap">{{ s.opened_at }} → {{ s.closed_at }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ money(s.opening_float) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-success-700">{{ money(s.cash_sales) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ money(s.card_sales) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ money(s.room_charge_sales) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-700">{{ money(s.expected_cash) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-primary-900 font-medium">{{ money(s.counted_cash) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <Badge v-if="!s.is_consistent" variant="error" size="sm" class="mr-1">{{ $t('reports360.zReport.mismatch') }}</Badge>
                                <Badge :variant="overShortVariant(s.over_short)" size="sm">{{ overShortLabel(s.over_short) }}</Badge>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="shifts.length" class="bg-neutral-50 border-t-2 border-neutral-200">
                        <tr>
                            <td class="px-4 py-3 text-body-sm font-semibold text-primary-900" colspan="3">{{ $t('admin.generated.k_54de5ceae02a') }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold text-success-700">{{ money(totals.cash) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold">{{ money(totals.card) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold">{{ money(totals.room_charge) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold" colspan="2">{{ money(totals.total) }}</td>
                            <td class="px-4 py-3 text-right"><Badge :variant="overShortVariant(totals.over_short)" size="sm">{{ overShortLabel(totals.over_short) }}</Badge></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div v-if="!shifts.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">{{ $t('admin.generated.k_87004f8fd49c') }}</div>
        </Card>
    </ReportShell>
</template>
