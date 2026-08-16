<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { getIntlLocale, translate } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import ReportBarList from '@/Components/UI/ReportBarList.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import InfoTip from '@/Components/UI/InfoTip.vue';
import { useReportCurrency } from '@/composables/useReportCurrency';
import { Banknote, CircleCheckBig, CreditCard, WalletCards } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    analytics: { type: Object, default: () => ({}) },
    rows: { type: Array, default: () => [] },
    byMethod: { type: Array, default: () => [] },
    totals: Object,
    canViewReservations: { type: Boolean, default: false },
    canViewPos: { type: Boolean, default: false },
    currency: { type: String, default: '€' },
    pricingCurrency: { type: String, default: null },
    baseToPricingRate: { type: [Number, String], default: null },
});

const summary = computed(() => props.analytics.summary || props.totals || {});
const methods = computed(() => props.analytics.methods || props.byMethod);
const sources = computed(() => props.analytics.sources || []);
const daily = computed(() => props.analytics.daily || props.rows);
const issues = computed(() => props.analytics.issues || []);
const maxDaily = computed(() => Math.max(1, ...daily.value.map((day) => Number(day.total || 0))));

const { money, moneyBase, showBase, displayRate, pricingCode } = useReportCurrency(props);
const pct = (value) => `${Number(value ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
const fmt = (date) => date ? new Date(`${date}T00:00:00`).toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short' }) : '—';

const methodBars = computed(() => methods.value.map((method) => ({
    key: method.method,
    label: method.method === 'cash' ? translate('reports360.paymentReconciliation.cash') : translate('reports360.paymentReconciliation.card'),
    value: Number(method.amount || 0),
    display: `${money(method.amount)} · ${pct(method.share)}`,
    barClass: method.method === 'cash' ? 'bg-success-500' : 'bg-info-500',
})));

const kpis = computed(() => [
    {
        label: translate('reports360.paymentReconciliation.collected'),
        help: translate('reports360.help.prCollected'),
        value: money(summary.value.collected),
        subvalue: showBase.value ? moneyBase(summary.value.collected) : null,
        tone: 'accent',
        icon: WalletCards,
        detail: `${summary.value.transaction_count || 0} ${translate('reports360.paymentReconciliation.transactions')}`,
    },
    {
        label: translate('reports360.paymentReconciliation.cash'),
        value: money(summary.value.cash),
        subvalue: showBase.value ? moneyBase(summary.value.cash) : null,
        tone: 'success',
        icon: Banknote,
    },
    {
        label: translate('reports360.paymentReconciliation.card'),
        value: money(summary.value.card),
        subvalue: showBase.value ? moneyBase(summary.value.card) : null,
        tone: 'info',
        icon: CreditCard,
    },
    {
        label: translate('reports360.paymentReconciliation.reconciliationRate'),
        help: translate('reports360.help.prReconciliationRate'),
        value: pct(summary.value.reconciliation_rate),
        tone: summary.value.issues_count ? 'warning' : 'success',
        icon: CircleCheckBig,
        detail: `${summary.value.issues_count || 0} ${translate('reports360.paymentReconciliation.issues')}`,
    },
]);

const issueLabel = (type) => translate(`reports360.paymentReconciliation.issueTypes.${type}`);
const issueHref = (issue) => {
    if (issue.link_kind === 'reservation' && issue.link_id && props.canViewReservations) return route('reservations.show', issue.link_id);
    if (issue.link_kind === 'pos' && props.canViewPos) return route('pos.index', issue.link_id ? { order_id: issue.link_id } : {});
    return null;
};
</script>

<template>
    <ReportShell
        :title="$t('reports360.paymentReconciliation.title')"
        route-name="reports.payments"
        :filters="filters"
        :description="$t('reports360.paymentReconciliation.short')"
        :category="$t('reports360.paymentReconciliation.category')"
    >
        <ReportKpiGrid :items="kpis" />
        <div v-if="displayRate" class="mt-3 text-right text-tiny text-neutral-500">{{ $t('reports360.amountsShownIn', { currency: pricingCode }) }}</div>

        <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(300px,0.65fr)_minmax(0,1.35fr)]">
            <ReportBarList
                :title="$t('reports360.paymentReconciliation.byMethod')"
                :description="$t('reports360.paymentReconciliation.realCollections')"
                :rows="methodBars"
            />

            <Card :padding="false">
                <div class="border-b border-neutral-200 px-5 py-4">
                    <h2 class="flex items-center gap-1 text-body font-semibold text-primary-900">{{ $t('reports360.paymentReconciliation.bySource') }}<InfoTip :text="$t('reports360.help.prSources')" :label="$t('reports360.paymentReconciliation.bySource')" /></h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200">
                        <thead class="bg-neutral-50 text-left text-label text-neutral-600">
                            <tr>
                                <th class="px-5 py-3">{{ $t('reports360.paymentReconciliation.source') }}</th>
                                <th class="px-4 py-3 text-right">{{ $t('reports360.paymentReconciliation.transactions') }}</th>
                                <th class="px-4 py-3 text-right">{{ $t('reports360.paymentReconciliation.cash') }}</th>
                                <th class="px-4 py-3 text-right">{{ $t('reports360.paymentReconciliation.card') }}</th>
                                <th class="px-5 py-3 text-right">{{ $t('reports360.paymentReconciliation.total') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="row in sources" :key="row.source" class="hover:bg-neutral-50">
                                <td class="px-5 py-3 text-body-sm font-medium text-primary-900">{{ row.source === 'pms' ? 'PMS / Folio' : 'POS' }}</td>
                                <td class="px-4 py-3 text-right text-body-sm text-neutral-600">{{ row.count }}</td>
                                <td class="px-4 py-3 text-right text-body-sm text-success-700">{{ money(row.cash) }}</td>
                                <td class="px-4 py-3 text-right text-body-sm text-info-700">{{ money(row.card) }}</td>
                                <td class="px-5 py-3 text-right text-body-sm font-semibold text-primary-900">{{ money(row.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="grid grid-cols-3 border-t border-neutral-200 text-center">
                    <div class="px-3 py-3">
                        <p class="text-tiny text-neutral-500">{{ $t('reports360.paymentReconciliation.roomCharge') }}</p>
                        <b class="mt-1 block text-body-sm text-primary-900">{{ money(summary.room_charge) }}</b>
                    </div>
                    <div class="border-x border-neutral-200 px-3 py-3">
                        <p class="text-tiny text-neutral-500">{{ $t('reports360.paymentReconciliation.refunds') }}</p>
                        <b class="mt-1 block text-body-sm text-error-700">{{ money(summary.refunds) }}</b>
                    </div>
                    <div class="px-3 py-3">
                        <p class="text-tiny text-neutral-500">{{ $t('reports360.paymentReconciliation.voided') }}</p>
                        <b class="mt-1 block text-body-sm text-neutral-700">{{ money(summary.voided) }}</b>
                    </div>
                </div>
            </Card>
        </div>

        <Card class="mt-4" :padding="false">
            <div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4">
                <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.paymentReconciliation.daily') }}</h2>
                <span class="text-tiny text-neutral-500">{{ $t('reports360.paymentReconciliation.realCollections') }}</span>
            </div>
            <div class="h-56 px-5 pb-4 pt-5">
                <div v-if="daily.length" class="flex h-full items-end gap-1.5 border-b border-neutral-200">
                    <div
                        v-for="day in daily"
                        :key="day.date"
                        class="group flex h-full min-w-0 flex-1 items-end justify-center"
                        :title="`${fmt(day.date)} · ${money(day.total)}`"
                    >
                        <span class="w-3/5 rounded-t bg-accent-500 transition group-hover:bg-accent-700" :style="{ height: `${day.total ? Math.max(2, Number(day.total) / maxDaily * 100) : 0}%` }" />
                    </div>
                </div>
                <div v-else class="flex h-full items-center justify-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
            </div>
        </Card>

        <Card class="mt-4" :padding="false">
            <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4">
                <div>
                    <h2 class="flex items-center gap-1 text-body font-semibold text-primary-900">{{ $t('reports360.paymentReconciliation.control') }}<InfoTip :text="$t('reports360.help.prControl')" :label="$t('reports360.paymentReconciliation.control')" /></h2>
                    <p class="mt-0.5 text-tiny text-neutral-500">{{ summary.matched_sources || 0 }} / {{ summary.expected_sources || 0 }} {{ $t('reports360.paymentReconciliation.matched') }}</p>
                </div>
                <div class="text-right">
                    <Badge :variant="issues.length ? 'warning' : 'success'">{{ issues.length }} {{ $t('reports360.paymentReconciliation.issues') }}</Badge>
                    <p v-if="summary.unposted_total" class="mt-1 inline-flex items-center gap-1 text-tiny font-semibold text-error-700">{{ money(summary.unposted_total) }}<InfoTip :text="$t('reports360.help.prUnposted')" :label="$t('reports360.paymentReconciliation.issues')" /></p>
                </div>
            </div>

            <div v-if="summary.cash_variance_count" class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-100 px-5 py-3 text-body-sm text-neutral-600">
                <span class="inline-flex items-center gap-1">
                    {{ $t('reports360.paymentReconciliation.shiftVariances', { count: summary.cash_variance_count }) }}
                    <b :class="Math.abs(summary.cash_variance) >= 0.01 ? 'text-primary-900' : 'text-neutral-500'">{{ money(summary.cash_variance) }}</b>
                    <small v-if="showBase" class="text-neutral-400">({{ moneyBase(summary.cash_variance) }})</small>
                    <InfoTip :text="$t('reports360.help.prShiftVariances')" :label="$t('reports360.paymentReconciliation.control')" />
                </span>
                <Link :href="route('reports.shifts')" class="text-tiny font-semibold text-accent-700 hover:underline">{{ $t('reports360.paymentReconciliation.seeShifts') }}</Link>
            </div>

            <div v-if="issues.length" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50 text-left text-label text-neutral-600">
                        <tr>
                            <th class="px-5 py-3">{{ $t('reports360.paymentReconciliation.issue') }}</th>
                            <th class="px-4 py-3">{{ $t('reports360.paymentReconciliation.reference') }}</th>
                            <th class="px-4 py-3">{{ $t('reports360.paymentReconciliation.date') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.paymentReconciliation.expected') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.paymentReconciliation.actual') }}</th>
                            <th class="px-5 py-3 text-right">{{ $t('reports360.paymentReconciliation.difference') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="issue in issues" :key="`${issue.type}-${issue.reference}`" class="hover:bg-neutral-50">
                            <td class="px-5 py-3"><Badge :variant="issue.severity === 'error' ? 'error' : 'warning'">{{ issueLabel(issue.type) }}</Badge></td>
                            <td class="px-4 py-3 text-body-sm font-medium text-primary-900">
                                <Link v-if="issueHref(issue)" :href="issueHref(issue)" class="hover:underline">{{ issue.reference }}</Link>
                                <span v-else>{{ issue.reference }}</span>
                            </td>
                            <td class="px-4 py-3 text-body-sm text-neutral-600">{{ fmt(issue.date) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-700">{{ money(issue.expected) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-700">{{ money(issue.actual) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold" :class="issue.difference ? 'text-error-700' : 'text-neutral-500'">{{ money(issue.difference) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="flex items-center justify-center gap-2 px-5 py-10 text-body-sm text-success-700">
                <CircleCheckBig class="h-5 w-5" />
                {{ $t('reports360.paymentReconciliation.clean') }}
            </div>
        </Card>
    </ReportShell>
</template>
