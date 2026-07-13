<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeftRight,
    ArrowRight,
    Banknote,
    CalendarClock,
    ChevronRight,
    CircleDollarSign,
    Clock3,
    Landmark,
    Minus,
    Plus,
    Scale,
    TrendingDown,
    TrendingUp,
    WalletCards,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import { money, sourceBadge } from './financeShared.js';

const props = defineProps({
    accounts: Array,
    summary: Object,
    receivables: Object,
    payables: Object,
    cashflow: Array,
    alerts: Array,
    latest: Array,
    baseCurrency: String,
    fxRate: Number,
    fxUpdatedAt: String,
    can: Object,
});

const periods = [
    { key: 'today', label: 'Sot' },
    { key: 'month', label: 'Ky muaj' },
    { key: 'year', label: 'Ky vit' },
];

const liquidityTotal = computed(() => props.accounts.reduce((total, account) => total + Number(account.balance_base || 0), 0));

const chartMax = computed(() => Math.max(1, ...props.cashflow.flatMap((day) => [Number(day.in), Number(day.out)])));
const chartBaseline = 176;
const chartTop = 20;
const chartLeft = 46;
const chartRight = 690;

function pointsFor(key) {
    const count = Math.max(1, props.cashflow.length - 1);
    return props.cashflow.map((day, index) => {
        const x = chartLeft + ((chartRight - chartLeft) * index) / count;
        const y = chartBaseline - (Number(day[key]) / chartMax.value) * (chartBaseline - chartTop);
        return { x: Number(x.toFixed(1)), y: Number(y.toFixed(1)) };
    });
}

function linePath(key) {
    return pointsFor(key).map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x} ${point.y}`).join(' ');
}

const incomePath = computed(() => linePath('in'));
const expensePath = computed(() => linePath('out'));
const incomeAreaPath = computed(() => `${incomePath.value} L ${chartRight} ${chartBaseline} L ${chartLeft} ${chartBaseline} Z`);
const chartLevels = computed(() => [1, 0.75, 0.5, 0.25, 0].map((ratio) => ({
    amount: chartMax.value * ratio,
    y: chartBaseline - ratio * (chartBaseline - chartTop),
})));
const chartTicks = computed(() => {
    const wanted = [0, 3, 6, 9, props.cashflow.length - 1];
    return [...new Set(wanted)]
        .filter((index) => index >= 0 && index < props.cashflow.length)
        .map((index) => ({
            index,
            x: pointsFor('in')[index].x,
            label: shortDate(props.cashflow[index].date),
        }));
});

function shortDate(value) {
    return new Intl.DateTimeFormat('sq-AL', { day: 'numeric', month: 'short' }).format(new Date(`${value}T12:00:00`));
}

function compactMoney(value) {
    return `€${new Intl.NumberFormat('sq-AL', { notation: 'compact', maximumFractionDigits: 1 }).format(value)}`;
}

function changeText(value) {
    if (value === null || value === undefined) return 'Pa bazë krahasimi';
    if (Number(value) === 0) return 'Pa ndryshim';
    return `${Number(value) > 0 ? '↑' : '↓'} ${Math.abs(Number(value)).toLocaleString('sq-AL')}%`;
}

function changeClass(value, lowerIsBetter = false) {
    if (value === null || value === undefined || Number(value) === 0) return 'bg-neutral-100 text-neutral-500';
    const good = lowerIsBetter ? Number(value) < 0 : Number(value) > 0;
    return good ? 'bg-accent-50 text-accent-700' : 'bg-error-50 text-error-600';
}

function barWidth(value) {
    return `${Math.max(4, Math.min(100, Number(value) || 0))}%`;
}

function relativeWidth(value, total) {
    if (!Number(total)) return '4%';
    return barWidth((Number(value) / Number(total)) * 100);
}

function formatDateTime(value) {
    const parsed = new Date(value.replace(' ', 'T'));
    return new Intl.DateTimeFormat('sq-AL', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(parsed);
}
</script>

<template>
    <AppLayout>
        <PageHeader title="Financa" :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Financa' }, { label: 'Paneli' }]">
            <template #actions>
                <Link
                    v-if="can.transfers"
                    :href="route('finance.accounts')"
                    class="inline-flex items-center gap-2 rounded-md border border-neutral-200 bg-white px-3.5 py-2 text-body-sm font-semibold text-neutral-700 no-underline shadow-sm hover:border-neutral-300 hover:bg-neutral-50"
                >
                    <ArrowLeftRight class="h-4 w-4" /> Transfertë
                </Link>
                <Link
                    v-if="can.payBills"
                    :href="route('finance.payments', { direction: 'out' })"
                    class="inline-flex items-center gap-2 rounded-md border border-neutral-200 bg-white px-3.5 py-2 text-body-sm font-semibold text-neutral-700 no-underline shadow-sm hover:border-neutral-300 hover:bg-neutral-50"
                >
                    <Minus class="h-4 w-4" /> Regjistro shpenzim
                </Link>
                <Link
                    v-if="can.createPayment"
                    :href="route('finance.payments', { direction: 'in' })"
                    class="inline-flex items-center gap-2 rounded-md bg-accent-600 px-3.5 py-2 text-body-sm font-semibold text-white no-underline shadow-sm hover:bg-accent-700 hover:text-white"
                >
                    <Plus class="h-4 w-4" /> Regjistro pagesë
                </Link>
            </template>
        </PageHeader>

        <p class="mt-1 text-body-sm text-neutral-500">Përmbledhja financiare dhe gjendja aktuale e hotelit.</p>

        <div class="mt-5 space-y-4 pb-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-tiny text-neutral-400">
                    Monedha bazë: <span class="font-bold text-neutral-600">EUR €</span>
                    <template v-if="fxRate"> · 1 € = {{ fxRate }} L</template>
                </p>
                <div class="inline-flex w-fit rounded-lg border border-neutral-200 bg-white p-1 shadow-sm">
                    <Link
                        v-for="period in periods"
                        :key="period.key"
                        :href="route('finance.index', { period: period.key })"
                        replace
                        preserve-scroll
                        class="rounded-md px-3 py-1.5 text-tiny font-semibold no-underline transition"
                        :class="summary.period === period.key ? 'bg-accent-50 text-accent-700' : 'text-neutral-500 hover:bg-neutral-50 hover:text-neutral-700'"
                    >{{ period.label }}</Link>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-lg border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-tiny font-semibold text-neutral-500">Të ardhura · {{ summary.period_label.toLowerCase() }}</p>
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-accent-50 text-accent-700"><TrendingUp class="h-4 w-4" /></span>
                    </div>
                    <p class="mt-2 text-h2 font-bold tabular-nums text-primary-900">{{ money(summary.income) }}</p>
                    <div class="mt-2 flex items-center gap-2 text-tiny text-neutral-400">
                        <span class="rounded px-1.5 py-0.5 font-bold" :class="changeClass(summary.income_change)">{{ changeText(summary.income_change) }}</span>
                        <span v-if="summary.income_change !== null" class="truncate">{{ summary.comparison_label }}</span>
                    </div>
                </article>

                <article class="rounded-lg border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-tiny font-semibold text-neutral-500">Shpenzime · {{ summary.period_label.toLowerCase() }}</p>
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-error-50 text-error-600"><TrendingDown class="h-4 w-4" /></span>
                    </div>
                    <p class="mt-2 text-h2 font-bold tabular-nums text-primary-900">{{ money(summary.expenses) }}</p>
                    <div class="mt-2 flex items-center gap-2 text-tiny text-neutral-400">
                        <span class="rounded px-1.5 py-0.5 font-bold" :class="changeClass(summary.expenses_change, true)">{{ changeText(summary.expenses_change) }}</span>
                        <span v-if="summary.expenses_change !== null" class="truncate">{{ summary.comparison_label }}</span>
                    </div>
                </article>

                <article class="rounded-lg border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-tiny font-semibold text-neutral-500">Cash flow neto</p>
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-info-50 text-info-700"><Scale class="h-4 w-4" /></span>
                    </div>
                    <p class="mt-2 text-h2 font-bold tabular-nums" :class="summary.net < 0 ? 'text-error-600' : 'text-primary-900'">{{ money(summary.net) }}</p>
                    <div class="mt-2 flex items-center gap-2 text-tiny text-neutral-400">
                        <span class="rounded px-1.5 py-0.5 font-bold" :class="changeClass(summary.net_change)">{{ changeText(summary.net_change) }}</span>
                        <span v-if="summary.net_change !== null" class="truncate">{{ summary.comparison_label }}</span>
                    </div>
                </article>

                <article class="rounded-lg border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-tiny font-semibold text-neutral-500">Për t'u arkëtuar</p>
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-warning-50 text-warning-700"><Clock3 class="h-4 w-4" /></span>
                    </div>
                    <p class="mt-2 text-h2 font-bold tabular-nums text-primary-900">{{ money(receivables.total) }}</p>
                    <div class="mt-2 flex items-center gap-2 text-tiny text-neutral-400">
                        <span class="rounded bg-neutral-100 px-1.5 py-0.5 font-bold text-neutral-600">{{ receivables.count }} {{ receivables.count === 1 ? 'faturë' : 'fatura' }}</span>
                        <span v-if="receivables.overdue_count" class="truncate text-error-600">{{ receivables.overdue_count }} me vonesë</span>
                        <span v-else>Asnjë me vonesë</span>
                    </div>
                </article>
            </div>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1.7fr),minmax(280px,.8fr)]">
                <Card :padding="false" class="min-w-0">
                    <template #header>
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-label font-bold text-primary-900">Të ardhura vs. shpenzime</h2>
                                <p class="mt-0.5 text-tiny text-neutral-400">14 ditët e fundit · EUR bazë</p>
                            </div>
                            <Link :href="route('finance.payments')" class="inline-flex items-center gap-1 text-tiny font-bold no-underline">Shiko lëvizjet <ArrowRight class="h-3.5 w-3.5" /></Link>
                        </div>
                    </template>
                    <div class="p-4 sm:p-5">
                        <div class="mb-2 flex gap-5 text-tiny text-neutral-500">
                            <span class="inline-flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-accent-600" />Të ardhura</span>
                            <span class="inline-flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-warning-500" />Shpenzime</span>
                        </div>
                        <svg class="h-52 w-full overflow-visible" viewBox="0 0 710 218" preserveAspectRatio="none" role="img" aria-label="Grafiku i të ardhurave dhe shpenzimeve për 14 ditët e fundit">
                            <defs>
                                <linearGradient id="finance-income-area" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0" stop-color="#40916c" stop-opacity=".22" />
                                    <stop offset="1" stop-color="#40916c" stop-opacity="0" />
                                </linearGradient>
                            </defs>
                            <g v-for="level in chartLevels" :key="level.y">
                                <line :x1="chartLeft" :x2="chartRight" :y1="level.y" :y2="level.y" stroke="#eef0f2" stroke-width="1" />
                                <text x="0" :y="level.y + 4" fill="#9aa1ac" font-size="10">{{ compactMoney(level.amount) }}</text>
                            </g>
                            <path :d="incomeAreaPath" fill="url(#finance-income-area)" />
                            <path :d="incomePath" fill="none" stroke="#2d6a4f" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                            <path :d="expensePath" fill="none" stroke="#b08914" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                            <g v-for="tick in chartTicks" :key="tick.index">
                                <text :x="tick.x" y="207" text-anchor="middle" fill="#9aa1ac" font-size="10">{{ tick.label }}</text>
                            </g>
                        </svg>
                    </div>
                </Card>

                <Card :padding="false" class="min-w-0">
                    <template #header>
                        <div>
                            <h2 class="text-label font-bold text-primary-900">Gjendja financiare</h2>
                            <p class="mt-0.5 text-tiny text-neutral-400">Çfarë kërkon vëmendje</p>
                        </div>
                    </template>
                    <div class="divide-y divide-neutral-100 px-5 py-1">
                        <div class="py-3.5">
                            <div class="flex items-center justify-between gap-3 text-body-sm"><span class="text-neutral-600">Pagesa të arkëtuara</span><strong class="tabular-nums text-primary-900">{{ receivables.collection_rate }}%</strong></div>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-neutral-100"><i class="block h-full rounded-full bg-accent-500" :style="{ width: barWidth(receivables.collection_rate) }" /></div>
                            <p class="mt-1.5 text-tiny text-neutral-400">Nga vlera totale e faturuar</p>
                        </div>
                        <div class="py-3.5">
                            <div class="flex items-center justify-between gap-3 text-body-sm"><span class="text-neutral-600">Bills për 7 ditët e ardhshme</span><strong class="tabular-nums text-warning-700">{{ money(payables.due_soon_total) }}</strong></div>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-neutral-100"><i class="block h-full rounded-full bg-warning-500" :style="{ width: relativeWidth(payables.due_soon_total, payables.total) }" /></div>
                            <p class="mt-1.5 text-tiny text-neutral-400">{{ payables.due_soon_count }} {{ payables.due_soon_count === 1 ? 'detyrim i afërt' : 'detyrime të afërta' }}</p>
                        </div>
                        <div class="py-3.5">
                            <div class="flex items-center justify-between gap-3 text-body-sm"><span class="text-neutral-600">Detyrime me vonesë</span><strong class="tabular-nums" :class="payables.overdue_total > 0 ? 'text-error-600' : 'text-primary-900'">{{ money(payables.overdue_total) }}</strong></div>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-neutral-100"><i class="block h-full rounded-full bg-error-500" :style="{ width: relativeWidth(payables.overdue_total, payables.total) }" /></div>
                            <p class="mt-1.5 text-tiny text-neutral-400">{{ payables.overdue_count }} {{ payables.overdue_count === 1 ? 'bill ka' : 'bills kanë' }} kaluar afatin</p>
                        </div>
                        <div class="py-3.5">
                            <div class="flex items-center justify-between gap-3 text-body-sm"><span class="text-neutral-600">Fatura për t'u arkëtuar</span><strong class="tabular-nums text-primary-900">{{ money(receivables.total) }}</strong></div>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-neutral-100"><i class="block h-full rounded-full bg-info-500" :style="{ width: barWidth(Math.min(receivables.count * 10, 100)) }" /></div>
                            <p class="mt-1.5 text-tiny text-neutral-400">{{ receivables.count }} {{ receivables.count === 1 ? 'faturë e hapur' : 'fatura të hapura' }}</p>
                        </div>
                    </div>
                </Card>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <Link
                    v-for="account in accounts"
                    :key="account.id"
                    :href="route('finance.accounts', { account_id: account.id })"
                    class="flex items-center gap-3 rounded-lg border border-neutral-200 bg-white p-4 text-neutral-700 no-underline shadow-card transition hover:border-accent-300 hover:bg-accent-50/30"
                >
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-accent-50 text-accent-700">
                        <Banknote v-if="account.type === 'cash'" class="h-5 w-5" />
                        <Landmark v-else class="h-5 w-5" />
                    </span>
                    <span class="min-w-0">
                        <span class="block truncate text-tiny font-semibold text-neutral-500">{{ account.name }} · {{ account.currency }}</span>
                        <strong class="mt-0.5 block truncate text-h4 tabular-nums text-primary-900">{{ money(account.balance, account.currency) }}</strong>
                    </span>
                    <ChevronRight class="ml-auto h-4 w-4 shrink-0 text-neutral-300" />
                </Link>

                <div v-if="accounts.length > 1" class="flex items-center gap-3 rounded-lg border border-neutral-200 bg-white p-4 shadow-card">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-info-50 text-info-700"><WalletCards class="h-5 w-5" /></span>
                    <span class="min-w-0">
                        <span class="block text-tiny font-semibold text-neutral-500">Likuiditet total · EUR bazë</span>
                        <strong class="mt-0.5 block truncate text-h4 tabular-nums text-primary-900">{{ money(liquidityTotal) }}</strong>
                    </span>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1.55fr),minmax(280px,.75fr)]">
                <Card :padding="false" class="min-w-0">
                    <template #header>
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-label font-bold text-primary-900">Lëvizjet e fundit</h2>
                                <p class="mt-0.5 text-tiny text-neutral-400">Të gjitha llogaritë e dukshme</p>
                            </div>
                            <Link :href="route('finance.payments')" class="inline-flex items-center gap-1 text-tiny font-bold no-underline">Shiko të gjitha <ArrowRight class="h-3.5 w-3.5" /></Link>
                        </div>
                    </template>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[650px] text-body-sm tabular-nums">
                            <thead>
                                <tr class="bg-neutral-50/70 text-left text-tiny uppercase tracking-wide text-neutral-400">
                                    <th class="px-5 py-2.5">Përshkrimi</th>
                                    <th class="px-4 py-2.5">Llogaria</th>
                                    <th class="px-4 py-2.5">Burimi</th>
                                    <th class="px-5 py-2.5 text-right">Shuma</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="payment in latest" :key="payment.id" class="border-t border-neutral-100">
                                    <td class="px-5 py-3">
                                        <span class="block font-semibold text-primary-900">{{ payment.description }}</span>
                                        <span class="mt-0.5 block text-tiny text-neutral-400">{{ formatDateTime(payment.paid_at) }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-neutral-600">{{ payment.direction === 'transfer' ? payment.account + ' → ' + payment.counter_account : payment.account }}</td>
                                    <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-tiny font-bold" :class="sourceBadge(payment).cls">{{ sourceBadge(payment).text }}</span></td>
                                    <td class="px-5 py-3 text-right font-bold whitespace-nowrap" :class="payment.direction === 'in' ? 'text-accent-600' : payment.direction === 'out' ? 'text-error-600' : 'text-neutral-500'">
                                        {{ payment.direction === 'in' ? '+' : payment.direction === 'out' ? '−' : '' }} {{ money(payment.amount, payment.currency) }}
                                    </td>
                                </tr>
                                <tr v-if="!latest.length"><td colspan="4" class="px-5 py-8 text-center text-neutral-400">Ende pa lëvizje — ato hyjnë vetë nga folio dhe POS.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <Card :padding="false" class="min-w-0">
                    <template #header>
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-label font-bold text-primary-900">Kërkojnë veprim</h2>
                                <p class="mt-0.5 text-tiny text-neutral-400">Prioritet sipas afatit</p>
                            </div>
                            <span v-if="alerts.length" class="rounded-full bg-error-50 px-2 py-0.5 text-tiny font-bold text-error-600">{{ alerts.length }}</span>
                        </div>
                    </template>
                    <div v-if="alerts.length" class="divide-y divide-neutral-100 px-5">
                        <Link v-for="(alert, index) in alerts" :key="index" :href="alert.href" class="flex items-start gap-3 py-3.5 text-neutral-700 no-underline group">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg" :class="alert.severity === 'error' ? 'bg-error-50 text-error-600' : 'bg-warning-50 text-warning-700'">
                                <AlertTriangle v-if="alert.severity === 'error'" class="h-4 w-4" />
                                <CalendarClock v-else class="h-4 w-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <strong class="block text-body-sm text-primary-900 group-hover:text-accent-700">{{ alert.label }}</strong>
                                <span class="mt-1 block text-tiny text-neutral-400">{{ alert.badge }}</span>
                            </span>
                            <span class="shrink-0 text-right text-tiny font-bold tabular-nums" :class="alert.severity === 'error' ? 'text-error-600' : 'text-warning-700'">{{ money(alert.amount) }}</span>
                        </Link>
                    </div>
                    <div v-else class="flex flex-col items-center px-5 py-10 text-center">
                        <span class="grid h-11 w-11 place-items-center rounded-full bg-accent-50 text-accent-700"><CircleDollarSign class="h-5 w-5" /></span>
                        <strong class="mt-3 text-body-sm text-primary-900">Asgjë urgjente</strong>
                        <p class="mt-1 text-tiny text-neutral-400">Financat janë në rregull për momentin.</p>
                    </div>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
