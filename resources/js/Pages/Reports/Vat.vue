<script setup>
import { getIntlLocale, translate } from '@/i18n';
import { Link } from '@inertiajs/vue3';
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import { BadgeCheck, CircleAlert, FileCheckCorner, ReceiptText } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps({
    filters: Object,
    analytics: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
    rows: { type: Array, default: () => [] },
    canViewReservations: Boolean,
    canViewPos: Boolean,
    currency: { type: String, default: '€' },
});

const t = (key) => translate(`reports360.fiscalVat.${key}`);
const money = (value) => `${props.currency}${Number(value ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const pct = (value) => `${Number(value ?? 0).toLocaleString(getIntlLocale(), { maximumFractionDigits: 1 })}%`;
const fmt = (date) => date ? new Date(`${date}T12:00:00`).toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
const summary = computed(() => props.analytics.summary || props.summary || {});
const statuses = computed(() => props.analytics.statuses || []);
const sources = computed(() => props.analytics.sources || []);
const rates = computed(() => props.analytics.rates || []);

const statusLabel = (status) => ({ fiscalized: t('fiscalized'), failed: t('failed'), processing: t('processing'), missing: t('missingStatus') }[status] || status);
const statusClass = (status) => ({
    fiscalized: 'bg-success-50 text-success-700 ring-success-200',
    failed: 'bg-error-50 text-error-700 ring-error-200',
    processing: 'bg-warning-50 text-warning-700 ring-warning-200',
    missing: 'bg-neutral-100 text-neutral-600 ring-neutral-200',
}[status] || 'bg-neutral-100 text-neutral-600 ring-neutral-200');
const sourceLabel = (source) => source === 'pms' ? t('pms') : t('pos');
const sourceHref = (row) => row.source === 'pms' && props.canViewReservations
    ? route('reservations.show', row.source_id)
    : row.source === 'pos' && props.canViewPos ? route('pos.index', { order: row.source_id }) : null;

const kpis = computed(() => [
    { label: t('gross'), value: money(summary.value.gross), tone: 'accent', icon: ReceiptText, detail: `${summary.value.fiscalized || 0} ${t('documents')}` },
    { label: t('vat'), value: money(summary.value.vat), tone: 'warning', icon: FileCheckCorner, detail: `${t('net')}: ${money(summary.value.net)}` },
    { label: t('coverage'), value: pct(summary.value.coverage_rate), tone: Number(summary.value.coverage_rate) >= 100 ? 'success' : 'warning', icon: BadgeCheck, detail: `${summary.value.fiscalized || 0}/${summary.value.documents || 0}` },
    { label: t('missing'), value: summary.value.missing || 0, tone: Number(summary.value.missing) > 0 ? 'error' : 'success', icon: CircleAlert, detail: `${summary.value.failed || 0} ${t('failed').toLocaleLowerCase(getIntlLocale())}` },
]);
</script>

<template>
    <ReportShell :title="t('title')" :description="t('short')" :category="t('category')" route-name="reports.vat" :filters="filters">
        <ReportKpiGrid :items="kpis" />

        <div class="mt-5 grid gap-4 xl:grid-cols-3">
            <Card :padding="false">
                <div class="border-b border-neutral-200 px-4 py-3"><h3 class="text-body-sm font-semibold text-primary-900">{{ t('statusControl') }}</h3></div>
                <div class="divide-y divide-neutral-100 px-4">
                    <div v-for="item in statuses" :key="item.status" class="flex items-center justify-between gap-3 py-3 text-body-sm">
                        <span class="text-neutral-600">{{ statusLabel(item.status) }}</span>
                        <span class="font-semibold text-primary-900">{{ item.count }}</span>
                    </div>
                </div>
            </Card>

            <Card :padding="false">
                <div class="border-b border-neutral-200 px-4 py-3"><h3 class="text-body-sm font-semibold text-primary-900">{{ t('bySource') }}</h3></div>
                <div class="divide-y divide-neutral-100 px-4">
                    <div v-for="item in sources" :key="item.source" class="grid grid-cols-[1fr_auto] gap-2 py-3 text-body-sm">
                        <span class="font-medium text-primary-900">{{ sourceLabel(item.source) }}</span>
                        <span class="text-right font-semibold text-primary-900">{{ money(item.gross) }}</span>
                        <span class="text-tiny text-neutral-500">{{ item.fiscalized }}/{{ item.documents }} {{ t('fiscalized').toLocaleLowerCase(getIntlLocale()) }}</span>
                        <span class="text-right text-tiny text-neutral-500">TVSH {{ money(item.vat) }}</span>
                    </div>
                </div>
            </Card>

            <Card :padding="false">
                <div class="border-b border-neutral-200 px-4 py-3"><h3 class="text-body-sm font-semibold text-primary-900">{{ t('byRate') }}</h3></div>
                <div class="divide-y divide-neutral-100 px-4">
                    <div v-for="item in rates" :key="item.rate" class="grid grid-cols-[1fr_auto] gap-2 py-3 text-body-sm">
                        <span class="font-medium text-primary-900">{{ item.rate }}%</span>
                        <span class="font-semibold text-primary-900">{{ money(item.vat) }}</span>
                        <span class="text-tiny text-neutral-500">{{ money(item.gross) }} {{ t('gross').toLocaleLowerCase(getIntlLocale()) }}</span>
                        <span class="text-right text-tiny text-neutral-500">{{ money(item.net) }} {{ t('net').toLocaleLowerCase(getIntlLocale()) }}</span>
                    </div>
                    <div v-if="!rates.length" class="py-8 text-center text-body-sm text-neutral-500">—</div>
                </div>
            </Card>
        </div>

        <Card :padding="false" class="mt-5">
            <div class="border-b border-neutral-200 px-4 py-3"><h3 class="text-body-sm font-semibold text-primary-900">{{ t('documentList') }}</h3></div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-label text-neutral-600">{{ t('date') }}</th>
                            <th class="px-4 py-3 text-left text-label text-neutral-600">{{ t('reference') }}</th>
                            <th class="px-4 py-3 text-left text-label text-neutral-600">{{ t('number') }}</th>
                            <th class="px-4 py-3 text-left text-label text-neutral-600">{{ t('status') }}</th>
                            <th class="px-4 py-3 text-right text-label text-neutral-600">{{ t('gross') }}</th>
                            <th class="px-4 py-3 text-right text-label text-neutral-600">TVSH</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in rows" :key="`${row.source}-${row.source_id}`" class="hover:bg-neutral-50">
                            <td class="whitespace-nowrap px-4 py-3 text-body-sm text-neutral-500">{{ fmt(row.date) }}</td>
                            <td class="px-4 py-3 text-body-sm">
                                <Link v-if="sourceHref(row)" :href="sourceHref(row)" class="font-medium text-primary-900 hover:text-accent-700">{{ sourceLabel(row.source) }} #{{ row.source_id }}</Link>
                                <span v-else class="font-medium text-primary-900">{{ sourceLabel(row.source) }} #{{ row.source_id }}</span>
                                <p v-if="row.guest || row.room" class="mt-0.5 text-tiny text-neutral-500">{{ row.guest }}<span v-if="row.room"> · {{ row.room }}</span></p>
                            </td>
                            <td class="px-4 py-3 text-body-sm text-neutral-600">
                                <a v-if="row.verify_url" :href="row.verify_url" target="_blank" rel="noopener" class="hover:text-accent-700 hover:underline">{{ row.fiscal_number || t('verify') }}</a>
                                <span v-else>{{ row.fiscal_number || '—' }}</span>
                            </td>
                            <td class="px-4 py-3"><span :class="['inline-flex rounded-full px-2 py-1 text-tiny font-semibold ring-1 ring-inset', statusClass(row.status)]">{{ statusLabel(row.status) }}</span></td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-body-sm font-medium text-primary-900">{{ money(row.gross) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-body-sm text-neutral-600">{{ money(row.vat) }}</td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!rows.length" class="px-6 py-12 text-center text-body-sm text-neutral-500">—</div>
            </div>
        </Card>
    </ReportShell>
</template>
