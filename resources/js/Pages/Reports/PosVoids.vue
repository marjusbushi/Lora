<script setup>
import { getIntlLocale, translate } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import { Ban, ReceiptText } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    rows: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    currency: { type: String, default: '€' },
});

const money = (v) =>
    `${props.currency}${Number(v ?? 0).toLocaleString(getIntlLocale(), {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;

const kpis = [
    { label: translate('admin.generated.k_b9f39164a683'), value: () => props.summary.count ?? 0, tone: 'error', icon: Ban },
    { label: translate('admin.generated.k_c6f77aa3204f'), value: () => money(props.summary.total), tone: 'warning', icon: ReceiptText, detail: translate('admin.generated.k_95398597f408') },
];
</script>

<template>
    <ReportShell :title="$t('admin.generated.k_68f7bbdc7884')" route-name="reports.posVoids" :filters="filters">
        <ReportKpiGrid :items="kpis" />

        <Card class="mt-5">
            <div v-if="rows.length" class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_f822da246b96') }}</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_b2565e917a28') }}</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Arsyeja</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_8ba5dc0142f7') }}</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_0949c49c0228') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-t border-neutral-100">
                            <td class="px-5 py-3 text-body-sm">
                                <Badge v-if="row.table_number">{{ $t('admin.generated.k_f822da246b96') }} {{ row.table_number }}</Badge>
                                <span v-else class="text-neutral-400">—</span>
                            </td>
                            <td class="px-5 py-3 text-body-sm text-right font-medium text-rose-600">{{ money(row.total_amount) }}</td>
                            <td class="px-5 py-3 text-body-sm text-neutral-600">{{ row.reason || '—' }}</td>
                            <td class="px-5 py-3 text-body-sm">{{ row.created_at }}</td>
                            <td class="px-5 py-3 text-body-sm">{{ row.created_by }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-neutral-50 border-t-2 font-semibold">
                        <tr>
                            <td class="px-5 py-3 text-body-sm">{{ $t('admin.generated.k_4ff93dc2032a') }}{{ summary.count ?? 0 }})</td>
                            <td class="px-5 py-3 text-body-sm text-right text-rose-600">{{ money(summary.total) }}</td>
                            <td class="px-5 py-3"></td>
                            <td class="px-5 py-3"></td>
                            <td class="px-5 py-3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div v-else class="px-6 py-10 text-center text-body-sm text-neutral-500">{{ $t('admin.generated.k_b0d23a549f76') }}</div>
        </Card>
    </ReportShell>
</template>
