<script setup>
import { getIntlLocale, translate } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import { computed } from 'vue';
import { Banknote, BedDouble, MapPinned, Users } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';
import { useReportDrilldown } from '@/composables/useReportDrilldown';

const props = defineProps({
    filters: Object,
    rows: { type: Array, default: () => [] },
    totals: Object,
    currency: { type: String, default: '€' },
});
const { can } = useReportDrilldown();
const guestHref = (nationality) => can('view_guests') ? route('guests.index', { nationality }) : null;

const money = (v) => `${props.currency}${Number(v ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const num = (v) => Number(v ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 });

const kpis = [
    { label: translate('admin.generated.k_494b215e7e76'), value: () => props.totals?.guests ?? 0, tone: 'accent', icon: Users, href: can('view_guests') ? route('guests.index') : null },
    { label: translate('admin.generated.k_0a150337bfe3'), value: () => props.rows.length, tone: 'info', icon: MapPinned, detail: translate('admin.generated.k_d93f3311ca4e'), href: can('view_guests') ? route('guests.index') : null },
    { label: translate('admin.generated.k_19e67e02aa38'), value: () => props.totals?.nights ?? 0, tone: 'neutral', icon: BedDouble },
    { label: translate('admin.generated.k_07a533b191f6'), value: () => money(props.totals?.revenue), tone: 'success', icon: Banknote },
];

const marketBars = computed(() => props.rows.slice(0, 8).map((row) => ({
    key: row.nationality,
    label: row.nationality,
    value: Number(row.nights ?? 0),
    display: translate('admin.generated.k_3ff80292950c', { p0: row.nights ?? 0 }),
    detail: translate('admin.generated.k_359b56560c0b', { p0: row.guests ?? 0, p1: money(row.revenue) }),
    href: guestHref(row.nationality),
})));
</script>

<template>
    <ReportShell :title="$t('admin.generated.k_b5c2b7cfa242')" route-name="reports.nationality" :filters="filters">
        <ReportKpiGrid :items="kpis" />
        <div class="mt-5 grid gap-4 xl:grid-cols-[minmax(280px,0.65fr)_1.35fr]">
            <ReportBarList :title="$t('admin.generated.k_424b3aac3598')" :description="$t('admin.generated.k_5078906a6359')" :rows="marketBars" />
            <Card :padding="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_553eca5aea7a') }}</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_5f33a722d8d5') }}</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_fc659792117c') }}</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_643971074459') }}</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_5c48c46a01d4') }}</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_cd3d7a7c5d7c') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="r in rows" :key="r.nationality" class="hover:bg-neutral-50">
                            <td class="px-5 py-3 text-body-sm text-primary-900"><Link v-if="guestHref(r.nationality)" :href="guestHref(r.nationality)" class="hover:underline">{{ r.nationality }}</Link><span v-else>{{ r.nationality }}</span></td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ r.guests }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ r.stays }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ r.nights }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-primary-900 font-medium">{{ money(r.revenue) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ num(r.alos) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rows.length" class="bg-neutral-50 border-t-2 border-neutral-200">
                        <tr>
                            <td class="px-5 py-3 text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_ff79d58a2b1d') }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ totals.guests }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ totals.stays }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ totals.nights }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ money(totals.revenue) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold">{{ num(totals.alos) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div v-if="!rows.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">{{ $t('admin.generated.k_679939ae2cfa') }}</div>
            </Card>
        </div>
    </ReportShell>
</template>
