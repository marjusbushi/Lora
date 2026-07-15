<script setup>
import { getIntlLocale, translate } from '@/i18n';
import { computed, ref } from 'vue';
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
import TransactionDetailsDrawer from './Components/TransactionDetailsDrawer.vue';
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
    { key: 'today', label: translate('admin.generated.k_00114f238556') },
    { key: 'month', label: translate('admin.generated.k_8ca408c0fd91') },
    { key: 'year', label: translate('admin.generated.k_98ca833d8560') },
];

const liquidityTotal = computed(() => props.accounts.reduce((total, account) => total + Number(account.balance_base || 0), 0));
const selectedPayment = ref(null);
const activeChartIndex = ref(null);

const chartMax = computed(() => Math.max(1, ...props.cashflow.flatMap((day) => [Number(day.in), Number(day.out)])));
const chartWidth = 710;
const chartHeight = 218;
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
const incomeAreaPath = computed(() => incomePath.value ? `${incomePath.value} L ${chartRight} ${chartBaseline} L ${chartLeft} ${chartBaseline} Z` : '');
const activeChartDay = computed(() => {
    if (activeChartIndex.value === null || !props.cashflow[activeChartIndex.value]) return null;

    return {
        ...props.cashflow[activeChartIndex.value],
        incomePoint: pointsFor('in')[activeChartIndex.value],
        expensePoint: pointsFor('out')[activeChartIndex.value],
    };
});
const chartTooltipStyle = computed(() => {
    if (!activeChartDay.value) return {};

    const anchorY = Math.min(activeChartDay.value.incomePoint.y, activeChartDay.value.expensePoint.y);
    const opensBelow = anchorY < 84;
    const x = activeChartDay.value.incomePoint.x;

    return {
        left: `${(x / chartWidth) * 100}%`,
        top: `${((opensBelow ? anchorY + 12 : anchorY - 10) / chartHeight) * 100}%`,
        transform: `${x < 150 ? 'translateX(0)' : x > 560 ? 'translateX(-100%)' : 'translateX(-50%)'} ${opensBelow ? '' : 'translateY(-100%)'}`,
    };
});
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
    return new Intl.DateTimeFormat(getIntlLocale(), { day: 'numeric', month: 'short' }).format(new Date(`${value}T12:00:00`));
}

function fullDate(value) {
    return new Intl.DateTimeFormat(getIntlLocale(), {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(`${value}T12:00:00`));
}

function selectChartDayFromPointer(event) {
    if (!props.cashflow.length) return;

    const bounds = event.currentTarget.getBoundingClientRect();
    const viewBoxX = ((event.clientX - bounds.left) / bounds.width) * chartWidth;
    const ratio = (viewBoxX - chartLeft) / (chartRight - chartLeft);
    activeChartIndex.value = Math.max(0, Math.min(props.cashflow.length - 1, Math.round(ratio * (props.cashflow.length - 1))));
}

function moveChartSelection(direction) {
    if (!props.cashflow.length) return;

    const current = activeChartIndex.value ?? (direction > 0 ? -1 : props.cashflow.length);
    activeChartIndex.value = Math.max(0, Math.min(props.cashflow.length - 1, current + direction));
}

function compactMoney(value) {
    return new Intl.NumberFormat(getIntlLocale(), {
        style: 'currency', currency: props.baseCurrency, notation: 'compact', maximumFractionDigits: 1,
    }).format(value);
}

function changeText(value) {
    if (value === null || value === undefined) return translate('admin.generated.k_7ec3d022683c');
    if (Number(value) === 0) return translate('admin.generated.k_d636d1a8f25e');
    return `${Number(value) > 0 ? '↑' : '↓'} ${Math.abs(Number(value)).toLocaleString(getIntlLocale())}%`;
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
    return new Intl.DateTimeFormat(getIntlLocale(), {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(parsed);
}
</script>

<template>
    <AppLayout>
        <PageHeader :title="$t('admin.generated.k_a960522cf6da')" :breadcrumbs="[{ label: $t('admin.generated.k_f1b790c08873'), href: '/dashboard' }, { label: $t('admin.generated.k_334789e97ec4') }, { label: $t('admin.generated.k_7530fa35fdfa') }]">
            <template #actions>
                <Link
                    v-if="can.transfers"
                    :href="route('finance.accounts')"
                    class="inline-flex items-center gap-2 rounded-md border border-neutral-200 bg-white px-3.5 py-2 text-body-sm font-semibold text-neutral-700 no-underline shadow-sm hover:border-neutral-300 hover:bg-neutral-50"
                >
                    <ArrowLeftRight class="h-4 w-4" /> {{ $t('admin.generated.k_1c7a0ed1a4ed') }} </Link>
                <Link
                    v-if="can.payBills"
                    :href="route('finance.payments', { direction: 'out' })"
                    class="inline-flex items-center gap-2 rounded-md border border-neutral-200 bg-white px-3.5 py-2 text-body-sm font-semibold text-neutral-700 no-underline shadow-sm hover:border-neutral-300 hover:bg-neutral-50"
                >
                    <Minus class="h-4 w-4" /> {{ $t('admin.generated.k_1a9564b2e83d') }} </Link>
                <Link
                    v-if="can.createPayment"
                    :href="route('finance.payments', { direction: 'in' })"
                    class="inline-flex items-center gap-2 rounded-md bg-accent-600 px-3.5 py-2 text-body-sm font-semibold text-white no-underline shadow-sm hover:bg-accent-700 hover:text-white"
                >
                    <Plus class="h-4 w-4" /> {{ $t('admin.generated.k_3a6a05f64cca') }} </Link>
            </template>
        </PageHeader>

        <p class="mt-1 text-body-sm text-neutral-500">{{ $t('admin.generated.k_ee8f7109f156') }}</p>

        <div class="mt-5 space-y-4 pb-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-tiny text-neutral-400">
{{ $t('admin.generated.k_69be807b64bc') }} <span class="font-bold text-neutral-600">{{ $t('admin.generated.k_ac6cd901d88d') }}</span>
                    <template v-if="fxRate && baseCurrency !== 'ALL'"> · 1 {{ baseCurrency }} = {{ fxRate }} ALL</template>
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
                        <p class="text-tiny font-semibold text-neutral-500">{{ $t('admin.generated.k_59a879329b1a') }} {{ summary.period_label.toLowerCase() }}</p>
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-accent-50 text-accent-700"><TrendingUp class="h-4 w-4" /></span>
                    </div>
                    <p class="mt-2 text-h2 font-bold tabular-nums text-primary-900">{{ money(summary.income, baseCurrency) }}</p>
                    <div class="mt-2 flex items-center gap-2 text-tiny text-neutral-400">
                        <span class="rounded px-1.5 py-0.5 font-bold" :class="changeClass(summary.income_change)">{{ changeText(summary.income_change) }}</span>
                        <span v-if="summary.income_change !== null" class="truncate">{{ summary.comparison_label }}</span>
                    </div>
                </article>

                <article class="rounded-lg border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-tiny font-semibold text-neutral-500">{{ $t('admin.generated.k_bc6c62d904b9') }} {{ summary.period_label.toLowerCase() }}</p>
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-error-50 text-error-600"><TrendingDown class="h-4 w-4" /></span>
                    </div>
                    <p class="mt-2 text-h2 font-bold tabular-nums text-primary-900">{{ money(summary.expenses, baseCurrency) }}</p>
                    <div class="mt-2 flex items-center gap-2 text-tiny text-neutral-400">
                        <span class="rounded px-1.5 py-0.5 font-bold" :class="changeClass(summary.expenses_change, true)">{{ changeText(summary.expenses_change) }}</span>
                        <span v-if="summary.expenses_change !== null" class="truncate">{{ summary.comparison_label }}</span>
                    </div>
                </article>

                <article class="rounded-lg border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-tiny font-semibold text-neutral-500">{{ $t('admin.generated.k_930589af2e65') }}</p>
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-info-50 text-info-700"><Scale class="h-4 w-4" /></span>
                    </div>
                    <p class="mt-2 text-h2 font-bold tabular-nums" :class="summary.net < 0 ? 'text-error-600' : 'text-primary-900'">{{ money(summary.net, baseCurrency) }}</p>
                    <div class="mt-2 flex items-center gap-2 text-tiny text-neutral-400">
                        <span class="rounded px-1.5 py-0.5 font-bold" :class="changeClass(summary.net_change)">{{ changeText(summary.net_change) }}</span>
                        <span v-if="summary.net_change !== null" class="truncate">{{ summary.comparison_label }}</span>
                    </div>
                </article>

                <article class="rounded-lg border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-tiny font-semibold text-neutral-500">{{ $t('admin.generated.k_63b17bb86e75') }}</p>
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-warning-50 text-warning-700"><Clock3 class="h-4 w-4" /></span>
                    </div>
                    <p class="mt-2 text-h2 font-bold tabular-nums text-primary-900">{{ money(receivables.total, baseCurrency) }}</p>
                    <div class="mt-2 flex items-center gap-2 text-tiny text-neutral-400">
                        <span class="rounded bg-neutral-100 px-1.5 py-0.5 font-bold text-neutral-600">{{ receivables.count }} {{ receivables.count === 1 ? $t('admin.generated.k_da67adba6bf9') : $t('admin.generated.k_d050bac418b8') }}</span>
                        <span v-if="receivables.overdue_count" class="truncate text-error-600">{{ receivables.overdue_count }} {{ $t('admin.generated.k_e046bd02c28a') }}</span>
                        <span v-else>{{ $t('admin.generated.k_cd1acd20bb91') }}</span>
                    </div>
                </article>
            </div>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1.7fr),minmax(280px,.8fr)]">
                <Card :padding="false" class="min-w-0">
                    <template #header>
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-label font-bold text-primary-900">{{ $t('admin.generated.k_30cfbd93b94f') }}</h2>
                                <p class="mt-0.5 text-tiny text-neutral-400">{{ $t('admin.generated.k_b5357420c791') }}</p>
                            </div>
                            <Link :href="route('finance.payments')" class="inline-flex items-center gap-1 text-tiny font-bold no-underline">{{ $t('admin.generated.k_eeef9fb20fab') }} <ArrowRight class="h-3.5 w-3.5" /></Link>
                        </div>
                    </template>
                    <div class="p-4 sm:p-5">
                        <div class="mb-2 flex gap-5 text-tiny text-neutral-500">
                            <span class="inline-flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-accent-600" />{{ $t('admin.generated.k_49836d46cac1') }}</span>
                            <span class="inline-flex items-center gap-1.5"><i class="h-2 w-2 rounded-full bg-warning-500" />{{ $t('admin.generated.k_57e664bad287') }}</span>
                        </div>
                        <div class="relative">
                        <svg
                            class="h-52 w-full overflow-visible outline-none"
                            viewBox="0 0 710 218"
                            preserveAspectRatio="none"
                            role="img"
                            tabindex="0"
                            :aria-label="$t('admin.generated.k_a6e82566e528')"
                            @pointermove="selectChartDayFromPointer"
                            @pointerdown="selectChartDayFromPointer"
                            @pointerleave="activeChartIndex = null"
                            @focus="activeChartIndex ??= Math.max(0, cashflow.length - 1)"
                            @blur="activeChartIndex = null"
                            @keydown.left.prevent="moveChartSelection(-1)"
                            @keydown.right.prevent="moveChartSelection(1)"
                            @keydown.home.prevent="activeChartIndex = cashflow.length ? 0 : null"
                            @keydown.end.prevent="activeChartIndex = cashflow.length ? cashflow.length - 1 : null"
                        >
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
                            <g v-if="activeChartDay">
                                <line :x1="activeChartDay.incomePoint.x" :x2="activeChartDay.incomePoint.x" :y1="chartTop" :y2="chartBaseline" stroke="#aeb4bd" stroke-width="1" stroke-dasharray="3 3" />
                                <circle :cx="activeChartDay.incomePoint.x" :cy="activeChartDay.incomePoint.y" r="4" fill="#fff" stroke="#2d6a4f" stroke-width="2.5" />
                                <circle :cx="activeChartDay.expensePoint.x" :cy="activeChartDay.expensePoint.y" r="4" fill="#fff" stroke="#b08914" stroke-width="2.5" />
                            </g>
                            <g v-for="tick in chartTicks" :key="tick.index">
                                <text :x="tick.x" y="207" text-anchor="middle" fill="#9aa1ac" font-size="10">{{ tick.label }}</text>
                            </g>
                        </svg>
                        <div
                            v-if="activeChartDay"
                            class="pointer-events-none absolute z-10 min-w-44 rounded-lg border border-neutral-200 bg-white px-3 py-2.5 shadow-lg"
                            :style="chartTooltipStyle"
                            aria-live="polite"
                        >
                            <p class="mb-2 text-tiny font-bold text-primary-900">{{ fullDate(activeChartDay.date) }}</p>
                            <div class="flex items-center justify-between gap-5 text-tiny">
                                <span class="inline-flex items-center gap-1.5 text-neutral-500"><i class="h-2 w-2 rounded-full bg-accent-600" />{{ $t('admin.generated.k_49836d46cac1') }}</span>
                                <strong class="tabular-nums text-accent-700">{{ money(activeChartDay.in, baseCurrency) }}</strong>
                            </div>
                            <div class="mt-1.5 flex items-center justify-between gap-5 text-tiny">
                                <span class="inline-flex items-center gap-1.5 text-neutral-500"><i class="h-2 w-2 rounded-full bg-warning-500" />{{ $t('admin.generated.k_57e664bad287') }}</span>
                                <strong class="tabular-nums text-warning-700">{{ money(activeChartDay.out, baseCurrency) }}</strong>
                            </div>
                        </div>
                        </div>
                    </div>
                </Card>

                <Card :padding="false" class="min-w-0">
                    <template #header>
                        <div>
                            <h2 class="text-label font-bold text-primary-900">{{ $t('admin.generated.k_ccf6698b7160') }}</h2>
                            <p class="mt-0.5 text-tiny text-neutral-400">{{ $t('admin.generated.k_8c800a216aba') }}</p>
                        </div>
                    </template>
                    <div class="divide-y divide-neutral-100 px-5 py-1">
                        <div class="py-3.5">
                            <div class="flex items-center justify-between gap-3 text-body-sm"><span class="text-neutral-600">{{ $t('admin.generated.k_da5645b491fc') }}</span><strong class="tabular-nums text-primary-900">{{ receivables.collection_rate }}%</strong></div>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-neutral-100"><i class="block h-full rounded-full bg-accent-500" :style="{ width: barWidth(receivables.collection_rate) }" /></div>
                            <p class="mt-1.5 text-tiny text-neutral-400">{{ $t('admin.generated.k_d15f7aa41687') }}</p>
                        </div>
                        <div class="py-3.5">
                            <div class="flex items-center justify-between gap-3 text-body-sm"><span class="text-neutral-600">{{ $t('admin.generated.k_c3a7afb3142f') }}</span><strong class="tabular-nums text-warning-700">{{ money(payables.due_soon_total, baseCurrency) }}</strong></div>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-neutral-100"><i class="block h-full rounded-full bg-warning-500" :style="{ width: relativeWidth(payables.due_soon_total, payables.total) }" /></div>
                            <p class="mt-1.5 text-tiny text-neutral-400">{{ payables.due_soon_count }} {{ payables.due_soon_count === 1 ? $t('admin.generated.k_680b8ba27719') : $t('admin.generated.k_bfae9a666755') }}</p>
                        </div>
                        <div class="py-3.5">
                            <div class="flex items-center justify-between gap-3 text-body-sm"><span class="text-neutral-600">{{ $t('admin.generated.k_e7b619ac29b3') }}</span><strong class="tabular-nums" :class="payables.overdue_total > 0 ? 'text-error-600' : 'text-primary-900'">{{ money(payables.overdue_total, baseCurrency) }}</strong></div>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-neutral-100"><i class="block h-full rounded-full bg-error-500" :style="{ width: relativeWidth(payables.overdue_total, payables.total) }" /></div>
                            <p class="mt-1.5 text-tiny text-neutral-400">{{ payables.overdue_count }} {{ payables.overdue_count === 1 ? $t('admin.generated.k_30eb62d37512') : $t('admin.generated.k_31a0dd65f9fd') }} {{ $t('admin.generated.k_b9615e5cd814') }}</p>
                        </div>
                        <div class="py-3.5">
                            <div class="flex items-center justify-between gap-3 text-body-sm"><span class="text-neutral-600">{{ $t('admin.generated.k_adf7dfda7566') }}</span><strong class="tabular-nums text-primary-900">{{ money(receivables.total, baseCurrency) }}</strong></div>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-neutral-100"><i class="block h-full rounded-full bg-info-500" :style="{ width: barWidth(Math.min(receivables.count * 10, 100)) }" /></div>
                            <p class="mt-1.5 text-tiny text-neutral-400">{{ receivables.count }} {{ receivables.count === 1 ? $t('admin.generated.k_85d161758696') : $t('admin.generated.k_9babc2140b96') }}</p>
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
                        <span class="block text-tiny font-semibold text-neutral-500">{{ $t('admin.generated.k_62e8beb1f989') }}</span>
                        <strong class="mt-0.5 block truncate text-h4 tabular-nums text-primary-900">{{ money(liquidityTotal, baseCurrency) }}</strong>
                    </span>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1.55fr),minmax(280px,.75fr)]">
                <Card :padding="false" class="min-w-0">
                    <template #header>
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-label font-bold text-primary-900">{{ $t('admin.generated.k_c016af40573e') }}</h2>
                                <p class="mt-0.5 text-tiny text-neutral-400">{{ $t('admin.generated.k_3b61f9d62fc1') }}</p>
                            </div>
                            <Link :href="route('finance.payments')" class="inline-flex items-center gap-1 text-tiny font-bold no-underline">{{ $t('admin.generated.k_9d1525477ea2') }} <ArrowRight class="h-3.5 w-3.5" /></Link>
                        </div>
                    </template>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[650px] text-body-sm tabular-nums">
                            <thead>
                                <tr class="bg-neutral-50/70 text-left text-tiny uppercase tracking-wide text-neutral-400">
                                    <th class="px-5 py-2.5">{{ $t('admin.generated.k_afb58001d392') }}</th>
                                    <th class="px-4 py-2.5">{{ $t('admin.generated.k_f39ead986c3b') }}</th>
                                    <th class="px-4 py-2.5">{{ $t('admin.generated.k_fa56b58957a1') }}</th>
                                    <th class="px-5 py-2.5 text-right">{{ $t('admin.generated.k_4be7a983dfb2') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="payment in latest" :key="payment.id" tabindex="0" class="cursor-pointer border-t border-neutral-100 transition hover:bg-neutral-50/60 focus:bg-neutral-50 focus:outline-none" @click="selectedPayment = payment" @keydown.enter="selectedPayment = payment">
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
                                <tr v-if="!latest.length"><td colspan="4" class="px-5 py-8 text-center text-neutral-400">{{ $t('admin.generated.k_e4bc84bb6ed1') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <Card :padding="false" class="min-w-0">
                    <template #header>
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-label font-bold text-primary-900">{{ $t('admin.generated.k_2667e4ecd34b') }}</h2>
                                <p class="mt-0.5 text-tiny text-neutral-400">{{ $t('admin.generated.k_a3c0ecc39cba') }}</p>
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
                            <span class="shrink-0 text-right text-tiny font-bold tabular-nums" :class="alert.severity === 'error' ? 'text-error-600' : 'text-warning-700'">{{ money(alert.amount, baseCurrency) }}</span>
                        </Link>
                    </div>
                    <div v-else class="flex flex-col items-center px-5 py-10 text-center">
                        <span class="grid h-11 w-11 place-items-center rounded-full bg-accent-50 text-accent-700"><CircleDollarSign class="h-5 w-5" /></span>
                        <strong class="mt-3 text-body-sm text-primary-900">{{ $t('admin.generated.k_4699599b0fa2') }}</strong>
                        <p class="mt-1 text-tiny text-neutral-400">{{ $t('admin.generated.k_3179a16e9600') }}</p>
                    </div>
                </Card>
            </div>
        </div>

        <TransactionDetailsDrawer :payment="selectedPayment" :base-currency="baseCurrency" @close="selectedPayment = null" />
    </AppLayout>
</template>
