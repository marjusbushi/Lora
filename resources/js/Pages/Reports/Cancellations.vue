<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { getIntlLocale, translate } from '@/i18n';
import { channelMeta } from '@/channels';
import { useReportCurrency } from '@/composables/useReportCurrency';
import ReportShell from '@/Components/UI/ReportShell.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import InfoTip from '@/Components/UI/InfoTip.vue';
import { AlertTriangle, Ban, CirclePercent, WalletCards } from 'lucide-vue-next';

const props = defineProps({
    filters: Object,
    analytics: { type: Object, default: () => ({}) },
    noShowFees: { type: Object, default: () => ({ rows: [], summary: {} }) },
    canViewReservations: { type: Boolean, default: false },
    currency: { type: String, default: '€' },
    pricingCurrency: { type: String, default: '' },
    baseToPricingRate: { type: Number, default: null },
});

const current = computed(() => props.analytics.current || {});
const summary = computed(() => current.value.summary || {});
const daily = computed(() => current.value.daily || []);
const channels = computed(() => current.value.channels || []);
const losses = computed(() => current.value.losses || []);
const atRisk = computed(() => current.value.at_risk || []);
const riskLevels = computed(() => current.value.risk_levels || {});
const changes = computed(() => props.analytics.changes || {});
const maxDailyLoss = computed(() => Math.max(1, ...daily.value.flatMap((day) => [day.cancelled_value || 0, day.no_show_value || 0])));

const feeSummary = computed(() => props.noShowFees?.summary || {});
const feeRows = computed(() => props.noShowFees?.rows || []);

const { pricingCode, displayRate, showBase, money, moneyBase } = useReportCurrency(props);
const baseLine = (value) => (showBase.value ? moneyBase(value) : null);
const num = (value) => Number(value ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 });
const pct = (value) => `${num(value)}%`;
const trend = (value) => value > 0 ? 'up' : value < 0 ? 'down' : 'flat';
const changeText = (key, suffix = '%') => changes.value[key] == null
    ? translate('reports360.noComparison')
    : `${changes.value[key] > 0 ? '+' : ''}${num(changes.value[key])}${suffix}`;
const riskVariant = (level) => ({ critical: 'error', high: 'warning', medium: 'info' }[level] || 'neutral');

const kpis = computed(() => [
    {
        label: translate('reports360.cancellationRisk.cancellationRate'),
        help: translate('reports360.help.cancellationRate'),
        value: pct(summary.value.cancellation_rate),
        tone: 'error',
        icon: CirclePercent,
        trend: trend(changes.value.cancellation_rate),
        trendText: changeText('cancellation_rate', 'pp'),
    },
    {
        label: translate('reports360.cancellationRisk.lostValue'),
        help: translate('reports360.help.lostValue'),
        value: money(summary.value.lost_value),
        subvalue: baseLine(summary.value.lost_value),
        tone: 'warning',
        icon: WalletCards,
        trend: trend(changes.value.lost_value),
        trendText: changeText('lost_value'),
    },
    {
        label: translate('reports360.cancellationRisk.noShowRate'),
        help: translate('reports360.help.noShow'),
        value: pct(summary.value.no_show_rate),
        tone: 'warning',
        icon: Ban,
        trend: trend(changes.value.no_show_rate),
        trendText: changeText('no_show_rate', 'pp'),
    },
    {
        label: translate('reports360.cancellationRisk.atRisk'),
        help: translate('reports360.help.atRisk'),
        value: summary.value.at_risk_count || 0,
        tone: summary.value.at_risk_count ? 'error' : 'success',
        icon: AlertTriangle,
        detail: money(summary.value.at_risk_value),
    },
]);
</script>

<template>
    <ReportShell
        :title="$t('reports360.cancellationRisk.title')"
        route-name="reports.cancellations"
        :filters="filters"
        :description="$t('reports360.cancellationRisk.short')"
        :category="$t('reports360.cancellationRisk.category')"
    >
        <ReportKpiGrid :items="kpis" />
        <p v-if="displayRate" class="mt-2 text-tiny text-neutral-500">{{ $t('reports360.amountsShownIn', { currency: pricingCode }) }}</p>

        <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.55fr)_minmax(320px,0.75fr)]">
            <Card :padding="false">
                <div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4">
                    <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.cancellationRisk.dailyLoss') }}</h2>
                    <div class="flex items-center gap-3 text-tiny text-neutral-500">
                        <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-error-500" />{{ $t('reports360.cancellationRisk.cancelled') }}</span>
                        <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-warning-400" />No-show</span>
                    </div>
                </div>
                <div class="h-60 px-5 pb-4 pt-5">
                    <div v-if="daily.length" class="flex h-full items-end gap-1.5 border-b border-neutral-200">
                        <div
                            v-for="day in daily"
                            :key="day.date"
                            class="group flex h-full min-w-0 flex-1 items-end justify-center gap-px"
                            :title="`${day.date} · ${$t('reports360.cancellationRisk.cancelled')} ${money(day.cancelled_value)} · No-show ${money(day.no_show_value)}`"
                        >
                            <span class="w-1/3 rounded-t bg-error-500 transition group-hover:bg-error-700" :style="{ height: `${day.cancelled_value ? Math.max(2, Number(day.cancelled_value) / maxDailyLoss * 100) : 0}%` }" />
                            <span class="w-1/3 rounded-t bg-warning-400 transition group-hover:bg-warning-500" :style="{ height: `${day.no_show_value ? Math.max(2, Number(day.no_show_value) / maxDailyLoss * 100) : 0}%` }" />
                        </div>
                    </div>
                    <div v-else class="flex h-full items-center justify-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
                </div>
            </Card>

            <Card :padding="false">
                <div class="border-b border-neutral-200 px-5 py-4">
                    <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.cancellationRisk.breakdown') }}</h2>
                </div>
                <div class="divide-y divide-neutral-100">
                    <div class="flex items-center justify-between px-5 py-3">
                        <span class="text-body-sm text-neutral-600">{{ $t('reports360.cancellationRisk.totalBookings') }}</span>
                        <b class="text-body-sm text-primary-900">{{ summary.total_count || 0 }}</b>
                    </div>
                    <div class="flex items-center justify-between px-5 py-3">
                        <span class="text-body-sm text-neutral-600">{{ $t('reports360.cancellationRisk.cancelled') }}</span>
                        <Badge variant="error">{{ summary.cancelled_count || 0 }}</Badge>
                    </div>
                    <div class="flex items-center justify-between px-5 py-3">
                        <span class="text-body-sm text-neutral-600">No-show</span>
                        <Badge variant="warning">{{ summary.no_show_count || 0 }}</Badge>
                    </div>
                    <div class="flex items-center justify-between px-5 py-3">
                        <span class="text-body-sm text-neutral-600">{{ $t('reports360.cancellationRisk.needsAction') }}</span>
                        <Badge :variant="summary.at_risk_count ? 'error' : 'success'">{{ summary.at_risk_count || 0 }}</Badge>
                    </div>
                    <div class="grid grid-cols-2 gap-3 px-5 py-3">
                        <div class="rounded-lg bg-error-50 px-3 py-2">
                            <span class="block text-tiny text-error-700">{{ $t('reports360.cancellationRisk.critical') }}</span>
                            <b class="text-body text-error-800">{{ riskLevels.critical || 0 }}</b>
                        </div>
                        <div class="rounded-lg bg-warning-50 px-3 py-2">
                            <span class="block text-tiny text-warning-700">{{ $t('reports360.cancellationRisk.high') }}</span>
                            <b class="text-body text-warning-800">{{ riskLevels.high || 0 }}</b>
                        </div>
                    </div>
                </div>
                <div class="border-t border-neutral-200 px-5 py-3 text-tiny text-neutral-500">
                    {{ $t('reports360.cancellationRisk.cancelledValue') }} <b class="ml-1 text-primary-900">{{ money(summary.cancelled_value) }}</b><small v-if="showBase" class="ml-1 text-neutral-400">({{ moneyBase(summary.cancelled_value) }})</small>
                    <span class="mx-2">·</span>
                    No-show <b class="ml-1 text-primary-900">{{ money(summary.no_show_value) }}</b><small v-if="showBase" class="ml-1 text-neutral-400">({{ moneyBase(summary.no_show_value) }})</small>
                </div>
            </Card>
        </div>

        <Card class="mt-4" :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4">
                <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.cancellationRisk.byChannel') }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50 text-left text-label text-neutral-600">
                        <tr>
                            <th class="px-5 py-3">{{ $t('reports360.channel') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.cancellationRisk.totalBookings') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.cancellationRisk.cancelled') }}</th>
                            <th class="px-4 py-3 text-right"><span class="inline-flex items-center gap-1">{{ $t('reports360.cancellationRisk.cancellationRate') }}<InfoTip :text="$t('reports360.help.cancellationRate')" :label="$t('reports360.cancellationRisk.cancellationRate')" /></span></th>
                            <th class="px-4 py-3 text-right"><span class="inline-flex items-center gap-1">No-show<InfoTip :text="$t('reports360.help.noShow')" label="No-show" /></span></th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.cancellationRisk.noShowRate') }}</th>
                            <th class="px-4 py-3 text-right"><span class="inline-flex items-center gap-1">{{ $t('reports360.cancellationRisk.atRisk') }}<InfoTip :text="$t('reports360.help.atRisk')" :label="$t('reports360.cancellationRisk.atRisk')" /></span></th>
                            <th class="px-5 py-3 text-right"><span class="inline-flex items-center gap-1">{{ $t('reports360.cancellationRisk.lostValue') }}<InfoTip :text="$t('reports360.help.lostValue')" :label="$t('reports360.cancellationRisk.lostValue')" /></span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in channels" :key="row.channel" class="hover:bg-neutral-50">
                            <td class="px-5 py-3">
                                <span class="inline-flex items-center gap-2 text-body-sm font-medium text-primary-900">
                                    <i class="h-2 w-2 rounded-full" :style="{ backgroundColor: channelMeta(row.channel).color }" />
                                    {{ channelMeta(row.channel).label }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-700">{{ row.bookings }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-700">{{ row.cancelled }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-error-600">{{ pct(row.cancellation_rate) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-neutral-700">{{ row.no_shows }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-warning-700">{{ pct(row.no_show_rate) }}</td>
                            <td class="px-4 py-3 text-right text-body-sm" :class="row.at_risk ? 'font-semibold text-error-700' : 'text-neutral-500'">{{ row.at_risk }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold text-primary-900">{{ money(row.lost_value) }}<small v-if="showBase" class="block font-normal text-tiny text-neutral-400">{{ moneyBase(row.lost_value) }}</small></td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!channels.length" class="px-5 py-10 text-center text-body-sm text-neutral-400">{{ $t('reports360.noData') }}</div>
            </div>
        </Card>

        <Card v-if="atRisk.length" class="mt-4" :padding="false">
            <div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4">
                <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.cancellationRisk.needsAction') }}</h2>
                <Badge variant="error">{{ atRisk.length }}</Badge>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50 text-left text-label text-neutral-600">
                        <tr>
                            <th class="px-5 py-3">{{ $t('reports360.cancellationRisk.guest') }}</th>
                            <th class="px-4 py-3">{{ $t('reports360.cancellationRisk.room') }}</th>
                            <th class="px-4 py-3">Check-in</th>
                            <th class="px-4 py-3">{{ $t('reports360.channel') }}</th>
                            <th class="px-4 py-3"><span class="inline-flex items-center gap-1">{{ $t('reports360.cancellationRisk.riskScore') }}<InfoTip :text="$t('reports360.help.riskScore')" :label="$t('reports360.cancellationRisk.riskScore')" /></span></th>
                            <th class="px-4 py-3">{{ $t('reports360.cancellationRisk.action') }}</th>
                            <th class="px-5 py-3 text-right"><span class="inline-flex items-center gap-1">{{ $t('reports360.cancellationRisk.exposure') }}<InfoTip :text="$t('reports360.help.exposure')" :label="$t('reports360.cancellationRisk.exposure')" /></span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in atRisk" :key="row.id" class="hover:bg-neutral-50">
                            <td class="px-5 py-3">
                                <Link v-if="canViewReservations" :href="route('reservations.show', row.id)" class="text-body-sm font-medium text-primary-900 hover:underline">{{ row.guest }}</Link>
                                <span v-else class="text-body-sm font-medium text-primary-900">{{ row.guest }}</span>
                            </td>
                            <td class="px-4 py-3 text-body-sm text-neutral-600">{{ row.room || '—' }}</td>
                            <td class="px-4 py-3 text-body-sm text-neutral-600">{{ row.check_in }}</td>
                            <td class="px-4 py-3 text-body-sm text-neutral-600">{{ channelMeta(row.channel).label }}</td>
                            <td class="px-4 py-3">
                                <Badge :variant="riskVariant(row.risk_level)">{{ row.risk_score }} · {{ $t(`reports360.cancellationRisk.levels.${row.risk_level}`) }}</Badge>
                                <div class="mt-1 max-w-56 text-tiny text-neutral-500">{{ row.risk_drivers.map((driver) => $t(`reports360.cancellationRisk.drivers.${driver}`)).join(' · ') }}</div>
                            </td>
                            <td class="px-4 py-3 text-body-sm font-medium text-primary-900">{{ $t(`reports360.cancellationRisk.actions.${row.recommended_action}`) }}</td>
                            <td class="px-5 py-3 text-right">
                                <b class="block text-body-sm text-error-700">{{ money(row.balance) }}</b>
                                <small v-if="showBase" class="block text-tiny text-neutral-400">{{ moneyBase(row.balance) }}</small>
                                <span class="text-tiny text-neutral-500">{{ $t('reports360.cancellationRisk.bookingValue') }} {{ money(row.value) }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <Card v-if="losses.length" class="mt-4" :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4">
                <h2 class="text-body font-semibold text-primary-900">{{ $t('reports360.cancellationRisk.lossDetails') }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50 text-left text-label text-neutral-600">
                        <tr>
                            <th class="px-5 py-3">{{ $t('reports360.cancellationRisk.guest') }}</th>
                            <th class="px-4 py-3">{{ $t('reports360.cancellationRisk.type') }}</th>
                            <th class="px-4 py-3">Check-in</th>
                            <th class="px-4 py-3">{{ $t('reports360.channel') }}</th>
                            <th class="px-5 py-3 text-right">{{ $t('reports360.cancellationRisk.value') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in losses" :key="`${row.type}-${row.id}`" class="hover:bg-neutral-50">
                            <td class="px-5 py-3">
                                <Link v-if="canViewReservations" :href="route('reservations.show', row.id)" class="text-body-sm font-medium text-primary-900 hover:underline">{{ row.guest }}</Link>
                                <span v-else class="text-body-sm font-medium text-primary-900">{{ row.guest }}</span>
                            </td>
                            <td class="px-4 py-3"><Badge :variant="row.type === 'cancelled' ? 'error' : 'warning'">{{ row.type === 'cancelled' ? $t('reports360.cancellationRisk.cancelled') : 'No-show' }}</Badge></td>
                            <td class="px-4 py-3 text-body-sm text-neutral-600">{{ row.check_in }}</td>
                            <td class="px-4 py-3 text-body-sm text-neutral-600">{{ channelMeta(row.channel).label }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold text-primary-900">{{ money(row.value) }}<small v-if="showBase" class="block font-normal text-tiny text-neutral-400">{{ moneyBase(row.value) }}</small></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <Card class="mt-4" :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4">
                <h2 class="flex items-center gap-1 text-body font-semibold text-primary-900">{{ $t('reports360.cancellationRisk.noShowFeesTitle') }}<InfoTip :text="$t('reports360.help.noShowFees')" :label="$t('reports360.cancellationRisk.noShowFeesTitle')" /></h2>
            </div>
            <div class="grid gap-3 px-5 py-4 sm:grid-cols-3">
                <div class="rounded-lg bg-neutral-50 px-3 py-2">
                    <span class="block text-tiny text-neutral-600">{{ $t('reports360.cancellationRisk.noShowFeesTotal') }}</span>
                    <b class="text-body text-primary-900">{{ feeSummary.total_count || 0 }}</b>
                </div>
                <div class="rounded-lg bg-success-50 px-3 py-2">
                    <span class="block text-tiny text-success-700">{{ $t('reports360.cancellationRisk.noShowFeesPaid') }}</span>
                    <b class="text-body text-success-800">{{ feeSummary.paid_count || 0 }} · {{ money(feeSummary.collected_value) }}</b>
                    <small v-if="showBase" class="block text-tiny text-neutral-400">{{ moneyBase(feeSummary.collected_value) }}</small>
                </div>
                <div class="rounded-lg bg-warning-50 px-3 py-2">
                    <span class="block text-tiny text-warning-700">{{ $t('reports360.cancellationRisk.noShowFeesOutstanding') }}</span>
                    <b class="text-body text-warning-800">{{ feeSummary.outstanding_count || 0 }} · {{ money(feeSummary.outstanding_value) }}</b>
                    <small v-if="showBase" class="block text-tiny text-neutral-400">{{ moneyBase(feeSummary.outstanding_value) }}</small>
                </div>
            </div>
            <div v-if="feeRows.length" class="overflow-x-auto border-t border-neutral-200">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50 text-left text-label text-neutral-600">
                        <tr>
                            <th class="px-5 py-3">{{ $t('reports360.cancellationRisk.guest') }}</th>
                            <th class="px-4 py-3">{{ $t('reports360.cancellationRisk.room') }}</th>
                            <th class="px-4 py-3">Check-in</th>
                            <th class="px-4 py-3">{{ $t('reports360.cancellationRisk.noShowFeesMarkedOn') }}</th>
                            <th class="px-4 py-3">{{ $t('reports360.channel') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.cancellationRisk.value') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('reports360.cancellationRisk.noShowFeesPaid') }}</th>
                            <th class="px-5 py-3 text-right">{{ $t('reports360.cancellationRisk.noShowFeesOutstanding') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in feeRows" :key="row.id" class="hover:bg-neutral-50">
                            <td class="px-5 py-3">
                                <Link v-if="canViewReservations" :href="route('reservations.show', row.id)" class="text-body-sm font-medium text-primary-900 hover:underline">{{ row.guest }}</Link>
                                <span v-else class="text-body-sm font-medium text-primary-900">{{ row.guest }}</span>
                            </td>
                            <td class="px-4 py-3 text-body-sm text-neutral-600">{{ row.room || '—' }}</td>
                            <td class="px-4 py-3 text-body-sm text-neutral-600">{{ row.check_in_date }}</td>
                            <td class="px-4 py-3 text-body-sm text-neutral-600">{{ row.no_show_at }}</td>
                            <td class="px-4 py-3 text-body-sm text-neutral-600">{{ channelMeta(row.channel).label }}</td>
                            <td class="px-4 py-3 text-right text-body-sm text-primary-900">{{ money(row.gross) }}<small v-if="showBase" class="block text-tiny font-normal text-neutral-400">{{ moneyBase(row.gross) }}</small></td>
                            <td class="px-4 py-3 text-right text-body-sm text-success-700">{{ money(row.paid) }}</td>
                            <td class="px-5 py-3 text-right">
                                <Badge :variant="row.settled ? 'success' : 'warning'">{{ row.settled ? $t('reports360.cancellationRisk.noShowFeesSettled') : money(row.outstanding) }}</Badge>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="border-t border-neutral-200 px-5 py-6 text-center text-body-sm text-neutral-400">{{ $t('reports360.cancellationRisk.noShowFeesEmpty') }}</div>
        </Card>
    </ReportShell>
</template>
