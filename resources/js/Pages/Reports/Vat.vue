<script setup>
import { getIntlLocale, translate } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import { Banknote, CirclePercent, HandCoins, ReceiptText } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps({
    filters: Object,
    summary: { type: Object, default: () => ({}) },
    rows: { type: Array, default: () => [] },
    currency: { type: String, default: '€' },
});

const money = (v) => `${props.currency}${Number(v ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const monthNames = ['Janar', 'Shkurt', 'Mars', 'Prill', 'Maj', 'Qershor', 'Korrik', 'Gusht', 'Shtator', 'Tetor', translate('admin.generated.k_796b0cf7ddcf'), 'Dhjetor'];
const monthLabel = (m) => {
    if (!m) return '';
    const [year, mm] = String(m).split('-');
    const idx = Number(mm) - 1;
    return `${monthNames[idx] ?? mm} ${year}`;
};

const rateLabel = computed(() => {
    if (props.summary.vat_status === 'not_registered') return translate('invoicePrint.withoutVat');
    if (props.summary.vat_status !== 'registered') return translate('admin.vatSettings.notConfigured');

    return `${props.summary.room_rate}% / ${props.summary.product_rate}%`;
});

const kpis = [
    { label: translate('admin.generated.k_36dfd2c3064d'), value: () => money(props.summary.gross), tone: 'accent', icon: ReceiptText },
    { label: translate('admin.generated.k_029eabd1b540'), value: () => money(props.summary.vat), tone: 'warning', icon: Banknote, detail: () => rateLabel.value },
    { label: translate('admin.generated.k_4f69532de876'), value: () => money(props.summary.net), tone: 'success', icon: HandCoins },
    { label: translate('admin.generated.k_5fda0d2c419b'), value: () => rateLabel.value, tone: 'neutral', icon: CirclePercent },
];

const showMonthly = () => props.rows.length > 1;
</script>

<template>
    <ReportShell :title="$t('admin.generated.k_afb27e9f5641')" route-name="reports.vat" :filters="filters">
        <ReportKpiGrid :items="kpis" />

        <p class="mt-3 text-body-sm text-neutral-500">
{{ $t('admin.generated.k_360db8f1e23b') }} {{ money(summary.room_revenue) }} {{ $t('admin.generated.k_302523301ace') }} {{ money(summary.pos_revenue) }}.
        </p>

        <div v-if="showMonthly()" class="mt-6">
            <Card :padding="false">
                <div class="px-5 py-4 border-b border-neutral-200">
                    <h3 class="text-label text-neutral-600 uppercase tracking-wider">{{ $t('admin.generated.k_68af43044cdd') }}</h3>
                </div>
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_124eaf2e9d7d') }}</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_fac585a3105a') }}</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_b2d5f6909f48') }}</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_f60a56f16dcf') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in rows" :key="row.month" class="hover:bg-neutral-50">
                            <td class="px-5 py-3 text-body-sm text-neutral-700">{{ monthLabel(row.month) }}</td>
                            <td class="px-5 py-3 text-body-sm text-right text-primary-900">{{ money(row.gross) }}</td>
                            <td class="px-5 py-3 text-body-sm text-right text-neutral-700">{{ money(row.vat) }}</td>
                            <td class="px-5 py-3 text-body-sm text-right text-neutral-700">{{ money(row.net) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-neutral-50 border-t-2 border-neutral-200">
                        <tr class="font-semibold">
                            <td class="px-5 py-3 text-body-sm text-neutral-700">{{ $t('admin.generated.k_4c01fd305356') }}</td>
                            <td class="px-5 py-3 text-body-sm text-right text-primary-900">{{ money(summary.gross) }}</td>
                            <td class="px-5 py-3 text-body-sm text-right text-neutral-700">{{ money(summary.vat) }}</td>
                            <td class="px-5 py-3 text-body-sm text-right text-neutral-700">{{ money(summary.net) }}</td>
                        </tr>
                    </tfoot>
                </table>
                <div v-if="!rows.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">{{ $t('admin.generated.k_181182be2d9a') }}</div>
            </Card>
        </div>

        <div v-else-if="!rows.length" class="mt-6">
            <Card :padding="false">
                <div class="px-6 py-10 text-center text-body-sm text-neutral-500">{{ $t('admin.generated.k_181182be2d9a') }}</div>
            </Card>
        </div>
    </ReportShell>
</template>
