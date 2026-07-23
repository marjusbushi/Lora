<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import {
    ArrowLeftRight,
    ArrowUpRight,
    Banknote,
    Check,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    Download,
    Landmark,
    MoreHorizontal,
    Plus,
    Search,
    WalletCards,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import TransactionDetailsDrawer from './Components/TransactionDetailsDrawer.vue';
import { translate } from '@/i18n';

const { t, locale } = useI18n();

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    selectedId: Number,
    ledger: { type: Array, default: () => [] },
    todayNet: { type: Number, default: 0 },
    baseCurrency: String,
    fxRate: Number,
    currencies: { type: Array, default: () => ['EUR', 'ALL'] },
    can: Object,
});

const activeAccounts = computed(() => props.accounts.filter((account) => account.is_active));
const selectedAccount = computed(() => props.accounts.find((account) => account.id === props.selectedId));
const activeCashCount = computed(() => activeAccounts.value.filter((account) => account.type === 'cash').length);
const activeBankCount = computed(() => activeAccounts.value.filter((account) => account.type === 'bank').length);

function balanceInBase(account) {
    if (account.balance_base !== undefined && account.balance_base !== null) return Number(account.balance_base);
    if (account.currency === props.baseCurrency) return Number(account.balance || 0);
    return props.fxRate ? Number(account.balance || 0) / Number(props.fxRate) : 0;
}

const totalBalance = computed(() => activeAccounts.value.reduce((sum, account) => sum + balanceInBase(account), 0));
const cashBalance = computed(() => activeAccounts.value.filter((account) => account.type === 'cash').reduce((sum, account) => sum + balanceInBase(account), 0));
const bankBalance = computed(() => activeAccounts.value.filter((account) => account.type === 'bank').reduce((sum, account) => sum + balanceInBase(account), 0));

const accountSearch = ref('');
const showInactive = ref(selectedAccount.value?.is_active === false);
const movementSearch = ref('');
const sourceFilter = ref('all');
const period = ref('30');
const pageSize = ref(20);
const currentPage = ref(1);
const selectedPayment = ref(null);

function dateKey(date = new Date()) {
    const pad = (value) => String(value).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

const todayKey = dateKey();

function movementSource(payment) {
    if (payment.direction === 'transfer') return 'transfer';
    const description = (payment.description || '').toLocaleLowerCase('sq');
    if (payment.source === 'auto' && description.includes('folio')) return 'folio';
    if (payment.source === 'auto' && description.includes('pos')) return 'pos';
    if (payment.direction === 'out') return 'expense';
    return 'manual';
}

function sourceClass(source) {
    return {
        folio: 'bg-sky-50 text-sky-700',
        pos: 'bg-violet-50 text-violet-700',
        expense: 'bg-amber-50 text-amber-700',
        transfer: 'bg-neutral-100 text-neutral-700',
        manual: 'bg-emerald-50 text-emerald-700',
    }[source] || 'bg-neutral-100 text-neutral-700';
}

const filteredAccounts = computed(() => {
    const query = accountSearch.value.trim().toLocaleLowerCase('sq');
    return props.accounts.filter((account) => {
        const matchesState = showInactive.value || account.is_active;
        const matchesSearch = !query || account.name.toLocaleLowerCase('sq').includes(query)
            || account.currency.toLocaleLowerCase('sq').includes(query);
        return matchesState && matchesSearch;
    });
});

function periodStartKey(days) {
    const start = new Date();
    start.setDate(start.getDate() - Math.max(0, days - 1));
    return dateKey(start);
}

const filteredLedger = computed(() => {
    const query = movementSearch.value.trim().toLocaleLowerCase('sq');
    const startKey = period.value === 'all' ? null : periodStartKey(Number(period.value) || 1);

    return props.ledger.filter((payment) => {
        const reference = `FIN-${String(payment.id).padStart(6, '0')}`.toLocaleLowerCase('sq');
        const description = (payment.description || '').toLocaleLowerCase('sq');
        const rowDate = (payment.paid_at || '').slice(0, 10);
        const matchesSearch = !query || description.includes(query) || reference.includes(query);
        const matchesSource = sourceFilter.value === 'all' || movementSource(payment) === sourceFilter.value;
        const matchesPeriod = !startKey || rowDate >= startKey;
        return matchesSearch && matchesSource && matchesPeriod;
    });
});

const selectedTodayIn = computed(() => props.ledger
    .filter((payment) => (payment.paid_at || '').slice(0, 10) === todayKey && Number(payment.delta) >= 0)
    .reduce((sum, payment) => sum + Number(payment.delta), 0));
const selectedTodayOut = computed(() => props.ledger
    .filter((payment) => (payment.paid_at || '').slice(0, 10) === todayKey && Number(payment.delta) < 0)
    .reduce((sum, payment) => sum + Math.abs(Number(payment.delta)), 0));

const totalPages = computed(() => Math.max(1, Math.ceil(filteredLedger.value.length / pageSize.value)));
const paginatedLedger = computed(() => {
    const start = (currentPage.value - 1) * pageSize.value;
    return filteredLedger.value.slice(start, start + Number(pageSize.value));
});
const resultStart = computed(() => filteredLedger.value.length ? (currentPage.value - 1) * pageSize.value + 1 : 0);
const resultEnd = computed(() => Math.min(currentPage.value * pageSize.value, filteredLedger.value.length));
const visiblePages = computed(() => {
    if (totalPages.value <= 7) return Array.from({ length: totalPages.value }, (_, index) => index + 1);
    const pages = new Set([1, totalPages.value, currentPage.value - 1, currentPage.value, currentPage.value + 1]);
    const sorted = [...pages].filter((page) => page > 0 && page <= totalPages.value).sort((a, b) => a - b);
    const result = [];
    sorted.forEach((page, index) => {
        if (index && page - sorted[index - 1] > 1) result.push(`gap-${page}`);
        result.push(page);
    });
    return result;
});

watch([movementSearch, sourceFilter, period, pageSize, () => props.selectedId], () => {
    currentPage.value = 1;
    selectedPayment.value = null;
});

watch(() => selectedAccount.value?.is_active, (isActive) => {
    if (isActive === false) showInactive.value = true;
});

function formatMoney(value, currency = props.baseCurrency) {
    return new Intl.NumberFormat(locale.value === 'en' ? 'en-GB' : 'sq-AL', {
        style: 'currency',
        currency: currency || 'EUR',
        minimumFractionDigits: currency === 'ALL' ? 0 : 2,
        maximumFractionDigits: currency === 'ALL' ? 0 : 2,
    }).format(Number(value || 0));
}

function dateTime(value) {
    return new Intl.DateTimeFormat(locale.value === 'en' ? 'en-GB' : 'sq-AL', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(String(value).replace(' ', 'T')));
}

function accountIcon(type) {
    return type === 'bank' ? Landmark : Banknote;
}

function pick(account) {
    router.get(route('finance.accounts'), { account_id: account.id }, { preserveScroll: true, preserveState: true });
}

function csvCell(value) {
    return `"${String(value ?? '').replaceAll('"', '""')}"`;
}

function exportLedger() {
    const header = [
        t('financeAccounts.date'),
        t('financeAccounts.movement'),
        t('financeAccounts.source'),
        t('financeAccounts.inflow'),
        t('financeAccounts.outflow'),
        t('financeAccounts.resultingBalance'),
    ];
    const rows = filteredLedger.value.map((payment) => [
        payment.paid_at,
        payment.description,
        t(`financeAccounts.sourceLabels.${movementSource(payment)}`),
        payment.delta >= 0 ? Number(payment.delta).toFixed(2) : '',
        payment.delta < 0 ? Math.abs(Number(payment.delta)).toFixed(2) : '',
        Number(payment.balance).toFixed(2),
    ]);
    const csv = `\uFEFF${[header, ...rows].map((row) => row.map(csvCell).join(';')).join('\n')}`;
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `ledger-${(selectedAccount.value?.name || 'account').toLocaleLowerCase('sq').replaceAll(' ', '-')}.csv`;
    link.click();
    URL.revokeObjectURL(url);
}

const showTransfer = ref(false);
const transfer = useForm({ from_account_id: props.selectedId, to_account_id: null, amount: null, description: '' });

watch(() => props.selectedId, (selectedId) => {
    transfer.from_account_id = selectedId;
    transfer.to_account_id = null;
});

function submitTransfer() {
    transfer.post(route('finance.transfers.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showTransfer.value = false;
            transfer.reset();
            transfer.from_account_id = props.selectedId;
        },
    });
}

// Capital deposit/withdrawal (admin or the finance role) — same ledger,
// tagged with `movement` so the dedicated report can isolate it.
const showMovement = ref(false);
const movement = useForm({ movement: 'deposit', account_id: props.selectedId, amount: null, currency: props.baseCurrency, fx_rate: null, description: '' });

watch(() => movement.account_id, (id) => {
    const selected = activeAccounts.value.find((item) => item.id === id);
    if (selected) movement.currency = selected.currency;
});

function openMovement() {
    // Default to whichever kind this user is actually allowed to record.
    movement.movement = props.can.deposits ? 'deposit' : 'withdrawal';
    movement.account_id = props.selectedId;
    const selected = activeAccounts.value.find((item) => item.id === props.selectedId);
    movement.currency = selected ? selected.currency : props.baseCurrency;
    showMovement.value = true;
}

function submitMovement() {
    movement.post(route('finance.movements.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showMovement.value = false;
            movement.reset();
        },
    });
}

const showNewAccount = ref(false);
const account = useForm({ name: '', type: 'cash', currency: props.baseCurrency, iban: '' });
const accountPreviewName = computed(() => account.name.trim() || (account.type === 'cash' ? t('financeAccounts.newCashAccount') : t('financeAccounts.newBankAccount')));

watch(() => account.type, (type) => {
    if (type === 'cash') account.iban = '';
});

function closeNewAccount() {
    if (account.processing) return;
    showNewAccount.value = false;
    account.reset();
    account.clearErrors();
}

function submitAccount() {
    account.post(route('finance.accounts.store'), {
        preserveScroll: true,
        onSuccess: closeNewAccount,
    });
}

function toggleAccount(accountToToggle) {
    if (accountToToggle.is_active && !confirm(translate('admin.generated.k_38133105f8f1', { p0: accountToToggle.name }))) return;
    router.put(route('finance.accounts.toggle', accountToToggle.id), {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('financeAccounts.pageTitle')" />
    <AppLayout>
        <div class="mx-auto max-w-[1600px] pb-10">
            <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <nav class="mb-1 flex items-center gap-1.5 text-xs text-neutral-500">
                        <span>{{ t('financeAccounts.finance') }}</span><span>/</span><span>{{ t('financeAccounts.cashAndBank') }}</span>
                    </nav>
                    <h1 class="text-2xl font-extrabold tracking-tight text-neutral-950">{{ t('financeAccounts.title') }}</h1>
                    <p class="mt-1 text-sm text-neutral-500">{{ t('financeAccounts.subtitle') }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button v-if="can.deposits || can.withdrawals" variant="outline" @click="openMovement">
                        <Banknote class="h-4 w-4" /> {{ t('financeAccounts.movementButton') }}
                    </Button>
                    <Button v-if="can.transfers && activeAccounts.length > 1" variant="outline" @click="showTransfer = true">
                        <ArrowLeftRight class="h-4 w-4" /> {{ t('financeAccounts.transfer') }}
                    </Button>
                    <Button v-if="can.manageAccounts" @click="showNewAccount = true">
                        <Plus class="h-4 w-4" /> {{ t('financeAccounts.newAccount') }}
                    </Button>
                </div>
            </header>

            <section class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-2xl border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between"><p class="text-sm font-semibold text-neutral-500">{{ t('financeAccounts.totalLiquidity') }}</p><WalletCards class="h-5 w-5 text-emerald-700" /></div>
                    <p class="mt-2 text-2xl font-extrabold tabular-nums text-neutral-950">{{ formatMoney(totalBalance) }}</p>
                    <p class="mt-1 text-xs text-neutral-400">{{ t('financeAccounts.baseCurrency', { currency: baseCurrency }) }}</p>
                </article>
                <article class="rounded-2xl border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between"><p class="text-sm font-semibold text-neutral-500">{{ t('financeAccounts.cash') }}</p><Banknote class="h-5 w-5 text-emerald-700" /></div>
                    <p class="mt-2 text-2xl font-extrabold tabular-nums text-neutral-950">{{ formatMoney(cashBalance) }}</p>
                    <p class="mt-1 text-xs text-neutral-400">{{ t('financeAccounts.activeCashAccounts', { count: activeCashCount }) }}</p>
                </article>
                <article class="rounded-2xl border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between"><p class="text-sm font-semibold text-neutral-500">{{ t('financeAccounts.bank') }}</p><Landmark class="h-5 w-5 text-emerald-700" /></div>
                    <p class="mt-2 text-2xl font-extrabold tabular-nums text-neutral-950">{{ formatMoney(bankBalance) }}</p>
                    <p class="mt-1 text-xs text-neutral-400">{{ t('financeAccounts.activeBankAccounts', { count: activeBankCount }) }}</p>
                </article>
                <article class="rounded-2xl border p-4 shadow-card" :class="todayNet >= 0 ? 'border-emerald-200 bg-emerald-50/60' : 'border-rose-200 bg-rose-50/60'">
                    <div class="flex items-start justify-between"><p class="text-sm font-semibold" :class="todayNet >= 0 ? 'text-emerald-800' : 'text-rose-700'">{{ t('financeAccounts.todayNet') }}</p><ArrowUpRight class="h-5 w-5" :class="todayNet >= 0 ? 'text-emerald-700' : 'rotate-90 text-rose-600'" /></div>
                    <p class="mt-2 text-2xl font-extrabold tabular-nums" :class="todayNet >= 0 ? 'text-emerald-950' : 'text-rose-800'">{{ todayNet >= 0 ? '+' : '−' }}{{ formatMoney(Math.abs(todayNet)) }}</p>
                    <p class="mt-1 flex items-center gap-1 text-xs font-medium" :class="todayNet >= 0 ? 'text-emerald-700' : 'text-rose-600'"><Check class="h-3.5 w-3.5" /> {{ t('financeAccounts.updatedNow') }}</p>
                </article>
            </section>

            <section class="mt-4 grid min-h-[650px] overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-card lg:grid-cols-[320px_minmax(0,1fr)]">
                <aside class="border-b border-neutral-200 bg-neutral-50/70 lg:border-b-0 lg:border-r">
                    <div class="border-b border-neutral-200 p-4">
                        <div>
                            <h2 class="font-bold text-neutral-900">{{ t('financeAccounts.accounts') }}</h2>
                            <p class="text-xs text-neutral-500">{{ t('financeAccounts.accountCount', { count: accounts.length }) }}</p>
                        </div>
                        <label class="relative mt-3 block">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                            <input v-model="accountSearch" class="h-10 w-full rounded-xl border border-neutral-200 bg-white pl-9 pr-3 text-sm outline-none ring-emerald-600/20 placeholder:text-neutral-400 focus:border-emerald-600 focus:ring-4" :placeholder="t('financeAccounts.searchAccounts')" />
                        </label>
                        <div class="mt-3 grid grid-cols-2 rounded-lg bg-neutral-200/70 p-1 text-xs font-semibold">
                            <button type="button" class="rounded-md px-3 py-1.5" :class="!showInactive ? 'bg-white text-neutral-950 shadow-sm' : 'text-neutral-500'" @click="showInactive = false">{{ t('financeAccounts.active') }}</button>
                            <button type="button" class="rounded-md px-3 py-1.5" :class="showInactive ? 'bg-white text-neutral-950 shadow-sm' : 'text-neutral-500'" @click="showInactive = true">{{ t('financeAccounts.all') }}</button>
                        </div>
                    </div>

                    <div class="max-h-[525px] space-y-1.5 overflow-y-auto p-2.5">
                        <button
                            v-for="accountItem in filteredAccounts"
                            :key="accountItem.id"
                            type="button"
                            class="group flex w-full items-center gap-3 rounded-xl border p-3 text-left transition"
                            :class="selectedId === accountItem.id ? 'border-emerald-700 bg-emerald-50 shadow-sm' : 'border-transparent hover:border-neutral-200 hover:bg-white'"
                            @click="pick(accountItem)"
                        >
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl" :class="selectedId === accountItem.id ? 'bg-emerald-800 text-white' : 'bg-white text-neutral-600 shadow-sm ring-1 ring-neutral-200'">
                                <component :is="accountIcon(accountItem.type)" class="h-5 w-5" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-2"><span class="truncate text-sm font-bold text-neutral-900">{{ accountItem.name }}</span><span v-if="!accountItem.is_active" class="rounded bg-neutral-200 px-1.5 py-0.5 text-[9px] font-bold uppercase text-neutral-500">{{ t('financeAccounts.inactive') }}</span></span>
                                <span class="mt-0.5 block text-xs text-neutral-500">{{ accountItem.currency }} · {{ t(`financeAccounts.types.${accountItem.type}`) }}</span>
                            </span>
                            <span class="text-right">
                                <span class="block text-sm font-extrabold tabular-nums text-neutral-950">{{ formatMoney(accountItem.balance, accountItem.currency) }}</span>
                                <span v-if="accountItem.currency !== baseCurrency && accountItem.balance_base !== null" class="block text-[10px] text-neutral-400">≈ {{ formatMoney(accountItem.balance_base) }}</span>
                            </span>
                        </button>
                        <div v-if="!filteredAccounts.length" class="px-4 py-10 text-center text-xs text-neutral-400">{{ t('financeAccounts.noAccounts') }}</div>
                    </div>
                </aside>

                <main v-if="selectedAccount" class="min-w-0">
                    <div class="border-b border-neutral-200 px-4 py-4 sm:px-5">
                        <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                            <div class="flex items-center gap-3">
                                <span class="grid h-11 w-11 place-items-center rounded-xl bg-emerald-900 text-white"><component :is="accountIcon(selectedAccount.type)" class="h-5 w-5" /></span>
                                <div><div class="flex items-center gap-2"><h2 class="text-lg font-extrabold text-neutral-950">{{ selectedAccount.name }}</h2><span class="h-2 w-2 rounded-full" :class="selectedAccount.is_active ? 'bg-emerald-500' : 'bg-neutral-300'"></span></div><p class="text-xs text-neutral-500">{{ selectedAccount.iban || `${selectedAccount.currency} · ${t(`financeAccounts.types.${selectedAccount.type}`)}` }}</p></div>
                            </div>
                            <div class="grid grid-cols-3 gap-5 sm:gap-8">
                                <div><p class="text-[10px] font-bold uppercase tracking-wider text-neutral-400">{{ t('financeAccounts.balance') }}</p><p class="mt-1 text-base font-extrabold tabular-nums text-neutral-950">{{ formatMoney(selectedAccount.balance, selectedAccount.currency) }}</p></div>
                                <div><p class="text-[10px] font-bold uppercase tracking-wider text-neutral-400">{{ t('financeAccounts.inToday') }}</p><p class="mt-1 text-base font-extrabold tabular-nums text-emerald-700">+{{ formatMoney(selectedTodayIn, selectedAccount.currency) }}</p></div>
                                <div><p class="text-[10px] font-bold uppercase tracking-wider text-neutral-400">{{ t('financeAccounts.outToday') }}</p><p class="mt-1 text-base font-extrabold tabular-nums text-rose-600">−{{ formatMoney(selectedTodayOut, selectedAccount.currency) }}</p></div>
                            </div>
                            <button v-if="can.manageAccounts" type="button" class="grid h-9 w-9 place-items-center rounded-lg border border-neutral-200 text-neutral-500 hover:bg-neutral-50" :title="selectedAccount.is_active ? t('financeAccounts.disableAccount') : t('financeAccounts.enableAccount')" @click="toggleAccount(selectedAccount)"><MoreHorizontal class="h-5 w-5" /></button>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3 border-b border-neutral-200 bg-neutral-50/60 p-3 sm:p-4 xl:flex-row xl:items-center xl:justify-between">
                        <div class="flex items-center gap-1 overflow-x-auto rounded-lg border border-neutral-200 bg-white p-1">
                            <button v-for="item in [{ value: '0', label: 'today' }, { value: '7', label: 'sevenDays' }, { value: '30', label: 'thirtyDays' }, { value: 'all', label: 'allTime' }]" :key="item.value" type="button" class="shrink-0 rounded-md px-3 py-1.5 text-xs font-bold" :class="period === item.value ? 'bg-neutral-900 text-white' : 'text-neutral-500 hover:bg-neutral-50'" @click="period = item.value">{{ t(`financeAccounts.${item.label}`) }}</button>
                        </div>
                        <div class="flex flex-1 flex-col gap-2 sm:flex-row xl:max-w-2xl xl:justify-end">
                            <label class="relative flex-1 xl:max-w-xs"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="movementSearch" class="h-10 w-full rounded-xl border border-neutral-200 bg-white pl-9 pr-3 text-sm outline-none focus:border-emerald-600" :placeholder="t('financeAccounts.searchMovements')" /></label>
                            <select v-model="sourceFilter" class="h-10 rounded-xl border border-neutral-200 bg-white px-3 text-sm font-medium text-neutral-700 outline-none focus:border-emerald-600">
                                <option value="all">{{ t('financeAccounts.allSources') }}</option><option value="folio">{{ t('financeAccounts.folio') }}</option><option value="pos">POS</option><option value="expense">{{ t('financeAccounts.expense') }}</option><option value="transfer">{{ t('financeAccounts.transfer') }}</option><option value="manual">{{ t('financeAccounts.manual') }}</option>
                            </select>
                            <button type="button" class="grid h-10 w-10 shrink-0 place-items-center rounded-xl border border-neutral-200 bg-white text-neutral-600 hover:bg-neutral-50 disabled:opacity-40" :aria-label="t('financeAccounts.export')" :disabled="!filteredLedger.length" @click="exportLedger"><Download class="h-4 w-4" /></button>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[760px] text-left">
                            <thead><tr class="border-b border-neutral-200 text-[10px] font-bold uppercase tracking-wider text-neutral-400"><th class="px-5 py-3">{{ t('financeAccounts.date') }}</th><th class="px-4 py-3">{{ t('financeAccounts.movement') }}</th><th class="px-4 py-3">{{ t('financeAccounts.source') }}</th><th class="px-4 py-3 text-right">{{ t('financeAccounts.amount') }}</th><th class="px-5 py-3 text-right">{{ t('financeAccounts.resultingBalance') }}</th><th class="w-10"></th></tr></thead>
                            <tbody>
                                <tr v-for="payment in paginatedLedger" :key="payment.id" tabindex="0" class="cursor-pointer border-b border-neutral-100 transition hover:bg-emerald-50/40 focus:bg-emerald-50/40 focus:outline-none" @click="selectedPayment = payment" @keydown.enter="selectedPayment = payment">
                                    <td class="whitespace-nowrap px-5 py-3.5 text-xs font-medium text-neutral-600">{{ dateTime(payment.paid_at) }}</td>
                                    <td class="px-4 py-3.5"><p class="text-sm font-semibold text-neutral-900">{{ payment.description }}</p><p class="mt-0.5 text-[11px] text-neutral-400">FIN-{{ String(payment.id).padStart(6, '0') }}</p></td>
                                    <td class="px-4 py-3.5"><span class="rounded-full px-2.5 py-1 text-[10px] font-bold" :class="sourceClass(movementSource(payment))">{{ t(`financeAccounts.sourceLabels.${movementSource(payment)}`) }}</span></td>
                                    <td class="px-4 py-3.5 text-right text-sm font-extrabold tabular-nums" :class="payment.delta >= 0 ? 'text-emerald-700' : 'text-rose-600'">{{ payment.delta >= 0 ? '+' : '−' }}{{ formatMoney(Math.abs(payment.delta), selectedAccount.currency) }}</td>
                                    <td class="px-5 py-3.5 text-right text-sm font-bold tabular-nums text-neutral-800">{{ formatMoney(payment.balance, selectedAccount.currency) }}</td>
                                    <td class="pr-3 text-neutral-300"><ChevronRight class="h-4 w-4" /></td>
                                </tr>
                                <tr v-if="!paginatedLedger.length"><td colspan="6" class="px-5 py-20 text-center"><CircleAlert class="mx-auto h-7 w-7 text-neutral-300" /><p class="mt-2 text-sm font-semibold text-neutral-600">{{ t('financeAccounts.noMovements') }}</p><p class="mt-1 text-xs text-neutral-400">{{ t('financeAccounts.changeFilters') }}</p></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="flex flex-col gap-3 border-t border-neutral-200 px-5 py-3 text-xs text-neutral-500 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-3">
                            <span>{{ t('financeAccounts.movementsFound', { count: filteredLedger.length }) }}</span>
                            <label class="flex items-center gap-1"><select v-model.number="pageSize" class="rounded-md border-neutral-200 py-1 pl-2 pr-7 text-xs"><option :value="10">10</option><option :value="20">20</option><option :value="50">50</option></select></label>
                        </div>
                        <nav v-if="totalPages > 1" class="flex items-center justify-center gap-1" :aria-label="t('financeAccounts.pagination')">
                            <button type="button" class="grid h-8 w-8 place-items-center rounded-md border border-neutral-200 bg-white hover:bg-neutral-50 disabled:opacity-40" :disabled="currentPage === 1" @click="currentPage--"><ChevronLeft class="h-4 w-4" /></button>
                            <template v-for="page in visiblePages" :key="page">
                                <span v-if="typeof page === 'string'" class="grid h-8 w-8 place-items-center">…</span>
                                <button v-else type="button" class="h-8 min-w-8 rounded-md px-2 font-semibold" :class="page === currentPage ? 'bg-neutral-900 text-white' : 'hover:bg-neutral-50'" @click="currentPage = page">{{ page }}</button>
                            </template>
                            <button type="button" class="grid h-8 w-8 place-items-center rounded-md border border-neutral-200 bg-white hover:bg-neutral-50 disabled:opacity-40" :disabled="currentPage === totalPages" @click="currentPage++"><ChevronRight class="h-4 w-4" /></button>
                        </nav>
                        <span>{{ resultStart }}–{{ resultEnd }} · {{ t('financeAccounts.currencyNotice', { currency: selectedAccount.currency }) }}</span>
                    </footer>
                </main>
            </section>
        </div>

        <TransactionDetailsDrawer :payment="selectedPayment" :base-currency="baseCurrency" @close="selectedPayment = null" />

        <Modal :show="showTransfer" :title="t('financeAccounts.transferBetween')" max-width="lg" @close="showTransfer = false">
            <form id="account-transfer-form" class="space-y-4" @submit.prevent="submitTransfer">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_20aee3e2f357') }}</label>
                        <select v-model="transfer.from_account_id" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm">
                            <option v-for="accountItem in activeAccounts" :key="accountItem.id" :value="accountItem.id">{{ accountItem.name }} ({{ formatMoney(accountItem.balance, accountItem.currency) }})</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_da19cc646fda') }}</label>
                        <select v-model="transfer.to_account_id" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm">
                            <option v-for="accountItem in activeAccounts.filter((item) => item.id !== transfer.from_account_id)" :key="accountItem.id" :value="accountItem.id">{{ accountItem.name }}</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_8f4c4f48eb66') }}</label>
                    <TextInput v-model="transfer.amount" type="number" min="0.01" step="0.01" class="w-full" placeholder="0.00" />
                    <p v-if="transfer.errors.amount" class="mt-1 text-tiny text-error-600">{{ transfer.errors.amount }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_8a16159d3a40') }}</label>
                    <TextInput v-model="transfer.description" class="w-full" :placeholder="$t('admin.generated.k_401b48950431')" />
                </div>
            </form>
            <template #footer>
                <Button variant="ghost" type="button" @click="showTransfer = false">{{ $t('admin.generated.k_83fe7c41f4fc') }}</Button>
                <Button form="account-transfer-form" type="submit" :loading="transfer.processing" :disabled="!transfer.to_account_id || !transfer.amount">{{ $t('admin.generated.k_baaf04345068') }}</Button>
            </template>
        </Modal>

        <Modal :show="showMovement" :title="t('financeAccounts.movementTitle')" max-width="lg" @close="showMovement = false">
            <form id="account-movement-form" class="space-y-4" @submit.prevent="submitMovement">
                <div class="flex gap-4">
                    <label v-if="can.deposits" class="flex items-center gap-2 text-body-sm text-primary-900">
                        <input v-model="movement.movement" type="radio" value="deposit" class="h-4 w-4 border-neutral-300 text-primary-700 focus:ring-primary-600">
                        {{ t('financeAccounts.movementDeposit') }}
                    </label>
                    <label v-if="can.withdrawals" class="flex items-center gap-2 text-body-sm text-primary-900">
                        <input v-model="movement.movement" type="radio" value="withdrawal" class="h-4 w-4 border-neutral-300 text-primary-700 focus:ring-primary-600">
                        {{ t('financeAccounts.movementWithdrawal') }}
                    </label>
                </div>
                <div>
                    <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ t('financeAccounts.movementAccount') }}</label>
                    <select v-model="movement.account_id" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm">
                        <option v-for="accountItem in activeAccounts" :key="accountItem.id" :value="accountItem.id">{{ accountItem.name }} ({{ formatMoney(accountItem.balance, accountItem.currency) }})</option>
                    </select>
                </div>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_8f4c4f48eb66') }}</label>
                        <TextInput v-model="movement.amount" type="number" min="0.01" step="0.01" class="w-full" placeholder="0.00" />
                        <p v-if="movement.errors.amount" class="mt-1 text-tiny text-error-600">{{ movement.errors.amount }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ t('financeAccounts.movementCurrency') }}</label>
                        <select v-model="movement.currency" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm">
                            <option v-for="code in currencies" :key="code" :value="code">{{ code }}</option>
                        </select>
                    </div>
                </div>
                <div v-if="movement.currency !== baseCurrency">
                    <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ t('financeAccounts.movementFx', { currency: baseCurrency }) }}</label>
                    <TextInput v-model="movement.fx_rate" type="number" min="0.000001" step="0.000001" class="w-full" />
                    <p v-if="movement.errors.fx_rate" class="mt-1 text-tiny text-error-600">{{ movement.errors.fx_rate }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_8a16159d3a40') }}</label>
                    <TextInput v-model="movement.description" class="w-full" :placeholder="movement.movement === 'deposit' ? t('financeAccounts.movementDeposit') : t('financeAccounts.movementWithdrawal')" />
                </div>
            </form>
            <template #footer>
                <Button variant="ghost" type="button" @click="showMovement = false">{{ $t('admin.generated.k_83fe7c41f4fc') }}</Button>
                <Button form="account-movement-form" type="submit" :loading="movement.processing" :disabled="!movement.account_id || !movement.amount">{{ t('financeAccounts.movementSubmit') }}</Button>
            </template>
        </Modal>

        <Modal :show="showNewAccount" :title="t('financeAccounts.createAccount')" max-width="2xl" @close="closeNewAccount">
            <div class="grid gap-5 md:grid-cols-[minmax(0,1fr)_220px]">
                <form id="new-account-form" class="space-y-5" @submit.prevent="submitAccount">
                    <fieldset>
                        <legend class="mb-2 text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_c6e0d9775fa3') }}</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <button
                                v-for="option in [
                                    { value: 'cash', title: t('financeAccounts.types.cash'), description: t('financeAccounts.cashAccountDescription') },
                                    { value: 'bank', title: t('financeAccounts.types.bank'), description: t('financeAccounts.bankAccountDescription') },
                                ]"
                                :key="option.value"
                                type="button"
                                class="relative flex gap-3 rounded-lg border p-3 text-left transition-colors"
                                :class="account.type === option.value ? 'border-accent-500 bg-accent-50 ring-1 ring-accent-500' : 'border-neutral-200 hover:border-neutral-300 hover:bg-neutral-50'"
                                @click="account.type = option.value"
                            >
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg" :class="account.type === option.value ? 'bg-white text-accent-700' : 'bg-neutral-100 text-neutral-500'">
                                    <Banknote v-if="option.value === 'cash'" class="h-5 w-5" /><Landmark v-else class="h-5 w-5" />
                                </span>
                                <span class="min-w-0 pr-5"><span class="block text-body-sm font-bold text-primary-900">{{ option.title }}</span><span class="mt-0.5 block text-tiny leading-4 text-neutral-500">{{ option.description }}</span></span>
                                <span v-if="account.type === option.value" class="absolute right-2.5 top-2.5 grid h-5 w-5 place-items-center rounded-full bg-accent-600 text-white"><Check class="h-3.5 w-3.5" /></span>
                            </button>
                        </div>
                        <p v-if="account.errors.type" class="mt-1 text-tiny text-error-600">{{ account.errors.type }}</p>
                    </fieldset>

                    <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_150px]">
                        <div>
                            <label for="account-name" class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_669c55512a8c') }}</label>
                            <TextInput id="account-name" v-model="account.name" class="w-full" :placeholder="account.type === 'cash' ? $t('admin.generated.k_24f354ecb0be') : $t('admin.generated.k_febfeb56723b')" maxlength="60" :error="account.errors.name" autofocus />
                            <p v-if="account.errors.name" class="mt-1 text-tiny text-error-600">{{ account.errors.name }}</p>
                        </div>
                        <div>
                            <label for="account-currency" class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_0aa0c9c27de8') }}</label>
                            <select id="account-currency" v-model="account.currency" class="w-full rounded-md border border-neutral-200 px-3 py-2 text-body-sm text-neutral-900 focus:border-accent-500 focus:ring-accent-500">
                                <option v-for="currency in currencies" :key="currency" :value="currency">{{ currency === 'ALL' ? $t('admin.generated.k_6d260cd11fa7') : currency }}</option>
                            </select>
                            <p v-if="account.errors.currency" class="mt-1 text-tiny text-error-600">{{ account.errors.currency }}</p>
                        </div>
                    </div>

                    <div v-if="account.type === 'bank'">
                        <div class="mb-1 flex items-center justify-between gap-3"><label for="account-iban" class="block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_b99e0e1046c2') }}</label><span class="text-tiny text-neutral-400">{{ $t('admin.generated.k_b3bea9355e43') }}</span></div>
                        <TextInput id="account-iban" v-model="account.iban" class="w-full font-mono uppercase" :placeholder="$t('admin.generated.k_e89a4be1b4e0')" maxlength="40" :error="account.errors.iban" />
                        <p v-if="account.errors.iban" class="mt-1 text-tiny text-error-600">{{ account.errors.iban }}</p>
                    </div>
                </form>

                <aside class="rounded-xl border border-neutral-200 bg-neutral-50 p-4">
                    <p class="text-tiny font-bold uppercase tracking-wide text-neutral-400">{{ $t('admin.generated.k_f51eba8cb6ad') }}</p>
                    <div class="mt-4 flex items-center gap-3">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-accent-100 text-accent-700"><Banknote v-if="account.type === 'cash'" class="h-5 w-5" /><Landmark v-else class="h-5 w-5" /></span>
                        <div class="min-w-0"><p class="truncate text-body-sm font-bold text-primary-900">{{ accountPreviewName }}</p><p class="text-tiny text-neutral-500">{{ t(`financeAccounts.types.${account.type}`) }} · {{ account.currency }}</p></div>
                    </div>
                    <div class="mt-5 border-t border-neutral-200 pt-4"><p class="text-tiny text-neutral-500">{{ $t('admin.generated.k_5146299b1072') }}</p><p class="mt-1 text-h3 font-extrabold tabular-nums text-primary-900">{{ formatMoney(0, account.currency) }}</p></div>
                    <p class="mt-4 rounded-lg bg-white p-3 text-tiny leading-5 text-neutral-500">{{ $t('admin.generated.k_5834fb860e2f') }}</p>
                </aside>
            </div>
            <template #footer>
                <Button variant="ghost" :disabled="account.processing" @click="closeNewAccount">{{ $t('admin.generated.k_83fe7c41f4fc') }}</Button>
                <Button type="submit" form="new-account-form" :loading="account.processing" :disabled="!account.name.trim()">{{ $t('admin.generated.k_481f2250c6dd') }}</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
