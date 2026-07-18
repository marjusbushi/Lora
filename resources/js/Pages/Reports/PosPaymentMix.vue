<script setup>
import { computed } from 'vue';
import { getIntlLocale, translate } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import { Ban, CreditCard, ReceiptText, RotateCcw } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';
import { useReportDrilldown } from '@/composables/useReportDrilldown';

const props = defineProps({
    filters: Object,
    analytics: { type: Object, default: () => ({}) },
    currency: { type: String, default: '€' },
});
const { can, hasModule } = useReportDrilldown();
const posHref = (orderId = null) => can('view_pos_orders') && hasModule('pos') ? route('pos.orders', orderId ? { order_id: orderId } : { from: props.filters?.from, to: props.filters?.to }) : null;

const current = computed(() => props.analytics.current || {});
const summary = computed(() => current.value.summary || {});
const methods = computed(() => current.value.methods || []);
const voids = computed(() => current.value.voids || []);
const refunds = computed(() => current.value.refunds || []);
const operators = computed(() => current.value.operators || []);
const changes = computed(() => props.analytics.changes || {});
const money = (v) => `${props.currency}${Number(v ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const pct = (v) => `${Number(v ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
const trend = (value) => value > 0 ? 'up' : value < 0 ? 'down' : 'flat';
const changeText = (key, suffix = '%') => changes.value[key] == null ? translate('reports360.noComparison') : `${changes.value[key] > 0 ? '+' : ''}${Number(changes.value[key]).toLocaleString(getIntlLocale())}${suffix}`;
const methodLabel = (method) => ({ cash: translate('reports360.posControls.cash'), card: translate('reports360.posControls.card'), room_charge: translate('reports360.posControls.roomCharge'), '?': translate('reports360.posControls.unknown') }[method] || method);

const kpis = computed(() => [
    { label: translate('reports360.posControls.netCollected'), value: money(summary.value.net_collected), tone: 'accent', icon: CreditCard, trend: trend(changes.value.net_collected), trendText: changeText('net_collected'), href: posHref() },
    { label: translate('reports360.posControls.refunds'), value: money(summary.value.refund_total), tone: 'warning', icon: RotateCcw, trend: trend(changes.value.refund_total), trendText: changeText('refund_total'), href: posHref() },
    { label: translate('reports360.posControls.voids'), value: summary.value.void_count || 0, tone: 'error', icon: Ban, detail: money(summary.value.void_value), href: posHref() },
    { label: translate('reports360.posControls.exceptionRate'), value: pct(summary.value.exception_rate), tone: 'neutral', icon: ReceiptText, trend: trend(changes.value.exception_rate), trendText: changeText('exception_rate', ' pp') },
]);

const methodBars = computed(() => methods.value.map((row) => ({
    key: row.method,
    label: methodLabel(row.method),
    value: Number(row.gross || 0),
    display: `${money(row.gross)} · ${pct(row.share)}`,
    detail: `${row.orders} ${translate('reports360.posControls.orders')} · ${translate('reports360.posControls.net')} ${money(row.net)}`,
    barClass: row.method === 'cash' ? 'bg-success-500' : row.method === 'card' ? 'bg-info-500' : 'bg-accent-500',
    href: posHref(),
})));
</script>

<template>
    <ReportShell :title="$t('reports360.posControls.title')" route-name="reports.posPaymentMix" :filters="filters" :description="$t('reports360.posControls.short')" :category="$t('reports360.posControls.category')">
        <ReportKpiGrid :items="kpis" />

        <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.1fr)_minmax(320px,.9fr)]">
            <ReportBarList :title="$t('reports360.posControls.paymentMix')" :rows="methodBars" />
            <Card :padding="false">
                <div class="border-b border-neutral-200 px-5 py-4"><h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.posControls.controlSummary') }}</h2></div>
                <div class="divide-y divide-neutral-100">
                    <div class="flex justify-between px-5 py-3 text-body-sm"><span class="text-neutral-600">{{ $t('reports360.posControls.grossCollected') }}</span><b>{{ money(summary.gross_collected) }}</b></div>
                    <div class="flex justify-between px-5 py-3 text-body-sm"><span class="text-neutral-600">{{ $t('reports360.posControls.missingReasons') }}</span><Badge :variant="summary.missing_reason_count ? 'error' : 'success'">{{ summary.missing_reason_count || 0 }}</Badge></div>
                    <div class="flex justify-between px-5 py-3 text-body-sm"><span class="text-neutral-600">{{ $t('reports360.posControls.ordersAudited') }}</span><b>{{ summary.order_population || 0 }}</b></div>
                </div>
            </Card>
        </div>

        <Card class="mt-4" :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4"><h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.posControls.operatorAudit') }}</h2></div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50 text-left text-label text-neutral-600"><tr><th class="px-5 py-3">{{ $t('reports360.posControls.operator') }}</th><th class="px-4 py-3 text-right">{{ $t('reports360.posControls.voids') }}</th><th class="px-4 py-3 text-right">{{ $t('reports360.posControls.refunds') }}</th><th class="px-5 py-3 text-right">{{ $t('reports360.posControls.totalExceptionValue') }}</th></tr></thead>
                    <tbody class="divide-y divide-neutral-100"><tr v-for="row in operators" :key="row.operator"><td class="px-5 py-3 text-body-sm font-medium text-primary-900">{{ row.operator }}</td><td class="px-4 py-3 text-right text-body-sm">{{ row.voids }} · {{ money(row.void_value) }}</td><td class="px-4 py-3 text-right text-body-sm">{{ row.refunds }} · {{ money(row.refund_value) }}</td><td class="px-5 py-3 text-right text-body-sm font-semibold text-error-700">{{ money(row.void_value + row.refund_value) }}</td></tr></tbody>
                </table>
                <div v-if="!operators.length" class="px-5 py-10 text-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
            </div>
        </Card>

        <div class="mt-4 grid gap-4 xl:grid-cols-2">
            <Card :padding="false">
                <div class="border-b border-neutral-200 px-5 py-4"><h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.posControls.voidDetails') }}</h2></div>
                <div class="divide-y divide-neutral-100"><div v-for="row in voids" :key="row.id" class="grid grid-cols-[1fr_auto] gap-3 px-5 py-3"><div><Link v-if="posHref(row.id)" :href="posHref(row.id)" class="text-body-sm font-bold text-primary-900 hover:underline">#{{ row.id }} · {{ row.operator }}</Link><b v-else class="text-body-sm text-primary-900">#{{ row.id }} · {{ row.operator }}</b><p class="mt-0.5 text-tiny text-neutral-500">{{ row.occurred_at }} · {{ row.reason || $t('reports360.posControls.noReason') }}</p></div><span class="text-body-sm font-semibold text-error-700">{{ money(row.amount) }}</span></div></div>
                <div v-if="!voids.length" class="px-5 py-10 text-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
            </Card>
            <Card :padding="false">
                <div class="border-b border-neutral-200 px-5 py-4"><h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.posControls.refundDetails') }}</h2></div>
                <div class="divide-y divide-neutral-100"><div v-for="row in refunds" :key="row.id" class="grid grid-cols-[1fr_auto] gap-3 px-5 py-3"><div><Link v-if="posHref(row.pos_order_id)" :href="posHref(row.pos_order_id)" class="text-body-sm font-bold text-primary-900 hover:underline">#{{ row.pos_order_id }} · {{ row.operator }}</Link><b v-else class="text-body-sm text-primary-900">#{{ row.pos_order_id }} · {{ row.operator }}</b><p class="mt-0.5 text-tiny text-neutral-500">{{ row.paid_at }} · {{ methodLabel(row.method) }}</p></div><span class="text-body-sm font-semibold text-warning-700">{{ money(row.amount) }}</span></div></div>
                <div v-if="!refunds.length" class="px-5 py-10 text-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
            </Card>
        </div>
    </ReportShell>
</template>
