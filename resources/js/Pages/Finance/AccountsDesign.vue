<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import {
    ArrowDownLeft,
    ArrowLeftRight,
    ArrowUpRight,
    Banknote,
    CalendarDays,
    Check,
    ChevronRight,
    CircleAlert,
    Download,
    Landmark,
    MoreHorizontal,
    Plus,
    Search,
    SlidersHorizontal,
    WalletCards,
    X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';

const { t, locale } = useI18n();

const accounts = [
    { id: 1, name: 'Arka kryesore', type: 'cash', currency: 'EUR', balance: 8996.49, baseBalance: 8996.49, todayIn: 1016.49, todayOut: 120, active: true },
    { id: 2, name: 'Banka', type: 'bank', currency: 'EUR', balance: 6447.55, baseBalance: 6447.55, todayIn: 880, todayOut: 420, active: true, iban: 'AL47 •••• •••• 8741' },
    { id: 3, name: 'POS Terminal', type: 'pos', currency: 'EUR', balance: 1284.20, baseBalance: 1284.20, todayIn: 316.49, todayOut: 0, active: true },
    { id: 4, name: 'Arka ALL', type: 'cash', currency: 'ALL', balance: 125000, baseBalance: 1250, todayIn: 18500, todayOut: 5200, active: true },
    { id: 5, name: 'Banka e vjetër', type: 'bank', currency: 'EUR', balance: 0, baseBalance: 0, todayIn: 0, todayOut: 0, active: false, iban: 'AL19 •••• •••• 1102' },
];

const ledger = {
    1: [
        { id: 101, date: '2026-07-16T15:42:00', description: 'Folio · Rezervimi #318', source: 'folio', sourceLabel: 'Folio', delta: 316.49, balance: 8996.49, daysAgo: 0, reference: 'PAY-2026-0716-18', method: 'Kartë' },
        { id: 102, date: '2026-07-16T13:18:00', description: 'Blerje urgjente · Hidraulikë', source: 'expense', sourceLabel: 'Shpenzim', delta: -120, balance: 8680, daysAgo: 0, reference: 'EXP-2026-0716-04', method: 'Cash' },
        { id: 103, date: '2026-07-16T10:31:00', description: 'Folio · Rezervimi #225', source: 'folio', sourceLabel: 'Folio', delta: 700, balance: 8800, daysAgo: 0, reference: 'PAY-2026-0716-11', method: 'Cash' },
        { id: 104, date: '2026-07-15T18:06:00', description: 'POS · Turni i restorantit', source: 'pos', sourceLabel: 'POS', delta: 245, balance: 8100, daysAgo: 1, reference: 'POS-2026-0715-02', method: 'Cash' },
        { id: 105, date: '2026-07-14T16:50:00', description: 'Materiale pastrimi', source: 'expense', sourceLabel: 'Shpenzim', delta: -85, balance: 7855, daysAgo: 2, reference: 'EXP-2026-0714-09', method: 'Cash' },
        { id: 106, date: '2026-07-12T12:23:00', description: 'Folio · Rezervimi #301', source: 'folio', sourceLabel: 'Folio', delta: 540, balance: 7940, daysAgo: 4, reference: 'PAY-2026-0712-06', method: 'Cash' },
        { id: 107, date: '2026-07-05T09:12:00', description: 'Transferim drejt Bankës', source: 'transfer', sourceLabel: 'Transferim', delta: -210, balance: 7400, daysAgo: 11, reference: 'TRF-2026-0705-01', method: 'Transferim' },
        { id: 108, date: '2026-06-29T14:08:00', description: 'Folio · Rezervimi #284', source: 'folio', sourceLabel: 'Folio', delta: 390, balance: 7610, daysAgo: 17, reference: 'PAY-2026-0629-03', method: 'Cash' },
    ],
    2: [
        { id: 201, date: '2026-07-16T14:20:00', description: 'Pagesë Booking.com', source: 'folio', sourceLabel: 'OTA', delta: 880, balance: 6447.55, daysAgo: 0, reference: 'BNK-2026-0716-09', method: 'Bankë' },
        { id: 202, date: '2026-07-16T11:05:00', description: 'Pagesë furnitori · Alba Food', source: 'expense', sourceLabel: 'Faturë', delta: -420, balance: 5567.55, daysAgo: 0, reference: 'BILL-2026-0716-02', method: 'Bankë' },
        { id: 203, date: '2026-07-10T09:40:00', description: 'Depozitë rezervimi #307', source: 'folio', sourceLabel: 'Folio', delta: 1200, balance: 5987.55, daysAgo: 6, reference: 'BNK-2026-0710-06', method: 'Bankë' },
    ],
    3: [
        { id: 301, date: '2026-07-16T15:42:00', description: 'Pagesa me kartë · Rezervimi #318', source: 'folio', sourceLabel: 'Folio', delta: 316.49, balance: 1284.20, daysAgo: 0, reference: 'POS-2026-0716-12', method: 'Kartë' },
        { id: 302, date: '2026-07-15T20:11:00', description: 'Mbyllja e turnit · Restorant', source: 'pos', sourceLabel: 'POS', delta: 245, balance: 967.71, daysAgo: 1, reference: 'POS-2026-0715-08', method: 'Kartë' },
        { id: 303, date: '2026-07-12T17:31:00', description: 'Pagesa me kartë · Rezervimi #301', source: 'folio', sourceLabel: 'Folio', delta: 510, balance: 722.71, daysAgo: 4, reference: 'POS-2026-0712-03', method: 'Kartë' },
    ],
    4: [
        { id: 401, date: '2026-07-16T12:42:00', description: 'Folio · Rezervimi #322', source: 'folio', sourceLabel: 'Folio', delta: 18500, balance: 125000, daysAgo: 0, reference: 'ALL-2026-0716-04', method: 'Cash' },
        { id: 402, date: '2026-07-16T09:18:00', description: 'Furnizime lokale', source: 'expense', sourceLabel: 'Shpenzim', delta: -5200, balance: 106500, daysAgo: 0, reference: 'ALL-2026-0716-02', method: 'Cash' },
        { id: 403, date: '2026-07-11T16:02:00', description: 'Arkëtim manual', source: 'manual', sourceLabel: 'Manuale', delta: 23000, balance: 111700, daysAgo: 5, reference: 'ALL-2026-0711-07', method: 'Cash' },
    ],
    5: [],
};

const selectedId = ref(1);
const accountSearch = ref('');
const showInactive = ref(false);
const movementSearch = ref('');
const source = ref('all');
const period = ref('30');
const selectedTransaction = ref(null);
const activeDialog = ref(null);

const selectedAccount = computed(() => accounts.find((account) => account.id === selectedId.value));
const filteredAccounts = computed(() => accounts.filter((account) => {
    const matchesState = showInactive.value || account.active;
    const matchesSearch = account.name.toLocaleLowerCase('sq').includes(accountSearch.value.trim().toLocaleLowerCase('sq'));
    return matchesState && matchesSearch;
}));
const filteredLedger = computed(() => (ledger[selectedId.value] || []).filter((movement) => {
    const matchesSearch = movement.description.toLocaleLowerCase('sq').includes(movementSearch.value.trim().toLocaleLowerCase('sq'))
        || movement.reference.toLocaleLowerCase('sq').includes(movementSearch.value.trim().toLocaleLowerCase('sq'));
    const matchesSource = source.value === 'all' || movement.source === source.value;
    const matchesPeriod = period.value === 'all' || movement.daysAgo <= Number(period.value);
    return matchesSearch && matchesSource && matchesPeriod;
}));
const activeAccounts = computed(() => accounts.filter((account) => account.active));
const totalBalance = computed(() => activeAccounts.value.reduce((sum, account) => sum + account.baseBalance, 0));
const cashBalance = computed(() => activeAccounts.value.filter((account) => account.type === 'cash').reduce((sum, account) => sum + account.baseBalance, 0));
const bankBalance = computed(() => activeAccounts.value.filter((account) => account.type === 'bank').reduce((sum, account) => sum + account.baseBalance, 0));
const todayNet = computed(() => activeAccounts.value.reduce((sum, account) => sum + account.todayIn - account.todayOut, 0));

function money(value, currency = 'EUR') {
    return new Intl.NumberFormat(locale.value === 'en' ? 'en-GB' : 'sq-AL', {
        style: 'currency',
        currency,
        maximumFractionDigits: currency === 'ALL' ? 0 : 2,
    }).format(Number(value || 0));
}

function dateTime(value) {
    return new Intl.DateTimeFormat(locale.value === 'en' ? 'en-GB' : 'sq-AL', {
        day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit',
    }).format(new Date(value));
}

function selectAccount(id) {
    selectedId.value = id;
    selectedTransaction.value = null;
}

function accountIcon(type) {
    return type === 'bank' ? Landmark : type === 'pos' ? WalletCards : Banknote;
}

function sourceClass(value) {
    return {
        folio: 'bg-sky-50 text-sky-700',
        pos: 'bg-violet-50 text-violet-700',
        expense: 'bg-amber-50 text-amber-700',
        transfer: 'bg-neutral-100 text-neutral-700',
        manual: 'bg-emerald-50 text-emerald-700',
    }[value] || 'bg-neutral-100 text-neutral-700';
}
</script>

<template>
    <Head :title="t('financeAccountsDesign.pageTitle')" />
    <AppLayout>
        <div class="mx-auto max-w-[1600px] pb-10">
            <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <nav class="mb-1 flex items-center gap-1.5 text-xs text-neutral-500">
                        <span>{{ t('financeAccountsDesign.finance') }}</span><span>/</span><span>{{ t('financeAccountsDesign.cashAndBank') }}</span>
                    </nav>
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-extrabold tracking-tight text-neutral-950">{{ t('financeAccountsDesign.title') }}</h1>
                        <span class="rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-amber-700">{{ t('financeAccountsDesign.demo') }}</span>
                    </div>
                    <p class="mt-1 text-sm text-neutral-500">{{ t('financeAccountsDesign.subtitle') }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" @click="activeDialog = 'transfer'">
                        <ArrowLeftRight class="h-4 w-4" /> {{ t('financeAccountsDesign.transfer') }}
                    </Button>
                    <Button @click="activeDialog = 'account'">
                        <Plus class="h-4 w-4" /> {{ t('financeAccountsDesign.newAccount') }}
                    </Button>
                </div>
            </header>

            <section class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-2xl border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between"><p class="text-sm font-semibold text-neutral-500">{{ t('financeAccountsDesign.totalLiquidity') }}</p><WalletCards class="h-5 w-5 text-emerald-700" /></div>
                    <p class="mt-2 text-2xl font-extrabold tabular-nums text-neutral-950">{{ money(totalBalance) }}</p>
                    <p class="mt-1 text-xs text-neutral-400">{{ t('financeAccountsDesign.baseCurrency') }}</p>
                </article>
                <article class="rounded-2xl border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between"><p class="text-sm font-semibold text-neutral-500">{{ t('financeAccountsDesign.cash') }}</p><Banknote class="h-5 w-5 text-emerald-700" /></div>
                    <p class="mt-2 text-2xl font-extrabold tabular-nums text-neutral-950">{{ money(cashBalance) }}</p>
                    <p class="mt-1 text-xs text-neutral-400">{{ t('financeAccountsDesign.activeCashAccounts', { count: 2 }) }}</p>
                </article>
                <article class="rounded-2xl border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between"><p class="text-sm font-semibold text-neutral-500">{{ t('financeAccountsDesign.bank') }}</p><Landmark class="h-5 w-5 text-emerald-700" /></div>
                    <p class="mt-2 text-2xl font-extrabold tabular-nums text-neutral-950">{{ money(bankBalance) }}</p>
                    <p class="mt-1 text-xs text-neutral-400">{{ t('financeAccountsDesign.oneActiveBank') }}</p>
                </article>
                <article class="rounded-2xl border border-emerald-200 bg-emerald-50/60 p-4 shadow-card">
                    <div class="flex items-start justify-between"><p class="text-sm font-semibold text-emerald-800">{{ t('financeAccountsDesign.todayNet') }}</p><ArrowUpRight class="h-5 w-5 text-emerald-700" /></div>
                    <p class="mt-2 text-2xl font-extrabold tabular-nums text-emerald-950">+{{ money(todayNet) }}</p>
                    <p class="mt-1 flex items-center gap-1 text-xs font-medium text-emerald-700"><Check class="h-3.5 w-3.5" /> {{ t('financeAccountsDesign.updatedNow') }}</p>
                </article>
            </section>

            <section class="mt-4 grid min-h-[650px] overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-card lg:grid-cols-[320px_minmax(0,1fr)]">
                <aside class="border-b border-neutral-200 bg-neutral-50/70 lg:border-b-0 lg:border-r">
                    <div class="border-b border-neutral-200 p-4">
                        <div class="flex items-center justify-between gap-2">
                            <div><h2 class="font-bold text-neutral-900">{{ t('financeAccountsDesign.accounts') }}</h2><p class="text-xs text-neutral-500">{{ t('financeAccountsDesign.accountCount', { count: accounts.length }) }}</p></div>
                            <button class="grid h-9 w-9 place-items-center rounded-lg border border-neutral-200 bg-white text-neutral-500 hover:text-neutral-900" :aria-label="t('financeAccountsDesign.filters')"><SlidersHorizontal class="h-4 w-4" /></button>
                        </div>
                        <label class="relative mt-3 block">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                            <input v-model="accountSearch" class="h-10 w-full rounded-xl border border-neutral-200 bg-white pl-9 pr-3 text-sm outline-none ring-emerald-600/20 placeholder:text-neutral-400 focus:border-emerald-600 focus:ring-4" :placeholder="t('financeAccountsDesign.searchAccounts')" />
                        </label>
                        <div class="mt-3 grid grid-cols-2 rounded-lg bg-neutral-200/70 p-1 text-xs font-semibold">
                            <button class="rounded-md px-3 py-1.5" :class="!showInactive ? 'bg-white text-neutral-950 shadow-sm' : 'text-neutral-500'" @click="showInactive = false">{{ t('financeAccountsDesign.active') }}</button>
                            <button class="rounded-md px-3 py-1.5" :class="showInactive ? 'bg-white text-neutral-950 shadow-sm' : 'text-neutral-500'" @click="showInactive = true">{{ t('financeAccountsDesign.all') }}</button>
                        </div>
                    </div>

                    <div class="max-h-[525px] space-y-1.5 overflow-y-auto p-2.5">
                        <button
                            v-for="account in filteredAccounts"
                            :key="account.id"
                            class="group flex w-full items-center gap-3 rounded-xl border p-3 text-left transition"
                            :class="selectedId === account.id ? 'border-emerald-700 bg-emerald-50 shadow-sm' : 'border-transparent hover:border-neutral-200 hover:bg-white'"
                            @click="selectAccount(account.id)"
                        >
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl" :class="selectedId === account.id ? 'bg-emerald-800 text-white' : 'bg-white text-neutral-600 shadow-sm ring-1 ring-neutral-200'">
                                <component :is="accountIcon(account.type)" class="h-5 w-5" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-2"><span class="truncate text-sm font-bold text-neutral-900">{{ account.name }}</span><span v-if="!account.active" class="rounded bg-neutral-200 px-1.5 py-0.5 text-[9px] font-bold uppercase text-neutral-500">{{ t('financeAccountsDesign.inactive') }}</span></span>
                                <span class="mt-0.5 block text-xs text-neutral-500">{{ account.currency }} · {{ t(`financeAccountsDesign.types.${account.type}`) }}</span>
                            </span>
                            <span class="text-right">
                                <span class="block text-sm font-extrabold tabular-nums text-neutral-950">{{ money(account.balance, account.currency) }}</span>
                                <span v-if="account.currency !== 'EUR'" class="block text-[10px] text-neutral-400">≈ {{ money(account.baseBalance) }}</span>
                            </span>
                        </button>
                    </div>
                </aside>

                <main class="min-w-0">
                    <div class="border-b border-neutral-200 px-4 py-4 sm:px-5">
                        <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                            <div class="flex items-center gap-3">
                                <span class="grid h-11 w-11 place-items-center rounded-xl bg-emerald-900 text-white"><component :is="accountIcon(selectedAccount.type)" class="h-5 w-5" /></span>
                                <div><div class="flex items-center gap-2"><h2 class="text-lg font-extrabold text-neutral-950">{{ selectedAccount.name }}</h2><span class="h-2 w-2 rounded-full" :class="selectedAccount.active ? 'bg-emerald-500' : 'bg-neutral-300'"></span></div><p class="text-xs text-neutral-500">{{ selectedAccount.iban || `${selectedAccount.currency} · ${t(`financeAccountsDesign.types.${selectedAccount.type}`)}` }}</p></div>
                            </div>
                            <div class="grid grid-cols-3 gap-5 sm:gap-8">
                                <div><p class="text-[10px] font-bold uppercase tracking-wider text-neutral-400">{{ t('financeAccountsDesign.balance') }}</p><p class="mt-1 text-base font-extrabold tabular-nums text-neutral-950">{{ money(selectedAccount.balance, selectedAccount.currency) }}</p></div>
                                <div><p class="text-[10px] font-bold uppercase tracking-wider text-neutral-400">{{ t('financeAccountsDesign.inToday') }}</p><p class="mt-1 text-base font-extrabold tabular-nums text-emerald-700">+{{ money(selectedAccount.todayIn, selectedAccount.currency) }}</p></div>
                                <div><p class="text-[10px] font-bold uppercase tracking-wider text-neutral-400">{{ t('financeAccountsDesign.outToday') }}</p><p class="mt-1 text-base font-extrabold tabular-nums text-rose-600">−{{ money(selectedAccount.todayOut, selectedAccount.currency) }}</p></div>
                            </div>
                            <button class="grid h-9 w-9 place-items-center rounded-lg border border-neutral-200 text-neutral-500 hover:bg-neutral-50"><MoreHorizontal class="h-5 w-5" /></button>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3 border-b border-neutral-200 bg-neutral-50/60 p-3 sm:p-4 xl:flex-row xl:items-center xl:justify-between">
                        <div class="flex items-center gap-1 overflow-x-auto rounded-lg border border-neutral-200 bg-white p-1">
                            <button v-for="item in [{ value: '0', label: 'today' }, { value: '7', label: 'sevenDays' }, { value: '30', label: 'thirtyDays' }, { value: 'all', label: 'allTime' }]" :key="item.value" class="shrink-0 rounded-md px-3 py-1.5 text-xs font-bold" :class="period === item.value ? 'bg-neutral-900 text-white' : 'text-neutral-500 hover:bg-neutral-50'" @click="period = item.value">{{ t(`financeAccountsDesign.${item.label}`) }}</button>
                        </div>
                        <div class="flex flex-1 flex-col gap-2 sm:flex-row xl:max-w-2xl xl:justify-end">
                            <label class="relative flex-1 xl:max-w-xs"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="movementSearch" class="h-10 w-full rounded-xl border border-neutral-200 bg-white pl-9 pr-3 text-sm outline-none focus:border-emerald-600" :placeholder="t('financeAccountsDesign.searchMovements')" /></label>
                            <select v-model="source" class="h-10 rounded-xl border border-neutral-200 bg-white px-3 text-sm font-medium text-neutral-700 outline-none focus:border-emerald-600">
                                <option value="all">{{ t('financeAccountsDesign.allSources') }}</option><option value="folio">{{ t('financeAccountsDesign.folio') }}</option><option value="pos">POS</option><option value="expense">{{ t('financeAccountsDesign.expense') }}</option><option value="transfer">{{ t('financeAccountsDesign.transfer') }}</option><option value="manual">{{ t('financeAccountsDesign.manual') }}</option>
                            </select>
                            <button class="grid h-10 w-10 shrink-0 place-items-center rounded-xl border border-neutral-200 bg-white text-neutral-600 hover:bg-neutral-50" :aria-label="t('financeAccountsDesign.export')"><Download class="h-4 w-4" /></button>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[760px] text-left">
                            <thead><tr class="border-b border-neutral-200 text-[10px] font-bold uppercase tracking-wider text-neutral-400"><th class="px-5 py-3">{{ t('financeAccountsDesign.date') }}</th><th class="px-4 py-3">{{ t('financeAccountsDesign.movement') }}</th><th class="px-4 py-3">{{ t('financeAccountsDesign.source') }}</th><th class="px-4 py-3 text-right">{{ t('financeAccountsDesign.amount') }}</th><th class="px-5 py-3 text-right">{{ t('financeAccountsDesign.resultingBalance') }}</th><th class="w-10"></th></tr></thead>
                            <tbody>
                                <tr v-for="movement in filteredLedger" :key="movement.id" class="cursor-pointer border-b border-neutral-100 transition hover:bg-emerald-50/40" @click="selectedTransaction = movement">
                                    <td class="whitespace-nowrap px-5 py-3.5 text-xs font-medium text-neutral-600">{{ dateTime(movement.date) }}</td>
                                    <td class="px-4 py-3.5"><p class="text-sm font-semibold text-neutral-900">{{ movement.description }}</p><p class="mt-0.5 text-[11px] text-neutral-400">{{ movement.reference }}</p></td>
                                    <td class="px-4 py-3.5"><span class="rounded-full px-2.5 py-1 text-[10px] font-bold" :class="sourceClass(movement.source)">{{ t(`financeAccountsDesign.sourceLabels.${movement.source}`) }}</span></td>
                                    <td class="px-4 py-3.5 text-right text-sm font-extrabold tabular-nums" :class="movement.delta >= 0 ? 'text-emerald-700' : 'text-rose-600'">{{ movement.delta >= 0 ? '+' : '−' }}{{ money(Math.abs(movement.delta), selectedAccount.currency) }}</td>
                                    <td class="px-5 py-3.5 text-right text-sm font-bold tabular-nums text-neutral-800">{{ money(movement.balance, selectedAccount.currency) }}</td>
                                    <td class="pr-3 text-neutral-300"><ChevronRight class="h-4 w-4" /></td>
                                </tr>
                                <tr v-if="!filteredLedger.length"><td colspan="6" class="px-5 py-20 text-center"><CircleAlert class="mx-auto h-7 w-7 text-neutral-300" /><p class="mt-2 text-sm font-semibold text-neutral-600">{{ t('financeAccountsDesign.noMovements') }}</p><p class="mt-1 text-xs text-neutral-400">{{ t('financeAccountsDesign.changeFilters') }}</p></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <footer class="flex items-center justify-between border-t border-neutral-200 px-5 py-3 text-xs text-neutral-500"><span>{{ t('financeAccountsDesign.movementsFound', { count: filteredLedger.length }) }}</span><span>{{ t('financeAccountsDesign.currencyNotice', { currency: selectedAccount.currency }) }}</span></footer>
                </main>
            </section>
        </div>

        <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0" leave-active-class="transition duration-150" leave-to-class="opacity-0">
            <div v-if="selectedTransaction" class="fixed inset-0 z-50 bg-neutral-950/30" @click.self="selectedTransaction = null">
                <aside class="absolute inset-y-0 right-0 w-full max-w-md overflow-y-auto bg-white shadow-2xl">
                    <div class="sticky top-0 flex items-center justify-between border-b border-neutral-200 bg-white px-5 py-4"><div><p class="text-[10px] font-bold uppercase tracking-widest text-emerald-700">{{ t('financeAccountsDesign.transactionDetail') }}</p><h3 class="mt-1 text-lg font-extrabold text-neutral-950">{{ selectedTransaction.reference }}</h3></div><button class="grid h-9 w-9 place-items-center rounded-lg hover:bg-neutral-100" @click="selectedTransaction = null"><X class="h-5 w-5" /></button></div>
                    <div class="p-5">
                        <div class="rounded-2xl p-5" :class="selectedTransaction.delta >= 0 ? 'bg-emerald-50' : 'bg-rose-50'"><component :is="selectedTransaction.delta >= 0 ? ArrowDownLeft : ArrowUpRight" class="h-6 w-6" :class="selectedTransaction.delta >= 0 ? 'text-emerald-700' : 'text-rose-600'" /><p class="mt-4 text-xs font-semibold text-neutral-500">{{ selectedTransaction.description }}</p><p class="mt-1 text-3xl font-extrabold tabular-nums" :class="selectedTransaction.delta >= 0 ? 'text-emerald-800' : 'text-rose-700'">{{ selectedTransaction.delta >= 0 ? '+' : '−' }}{{ money(Math.abs(selectedTransaction.delta), selectedAccount.currency) }}</p></div>
                        <dl class="mt-5 divide-y divide-neutral-100 rounded-2xl border border-neutral-200 px-4"><div class="flex justify-between gap-5 py-3"><dt class="text-sm text-neutral-500">{{ t('financeAccountsDesign.account') }}</dt><dd class="text-sm font-bold text-neutral-900">{{ selectedAccount.name }}</dd></div><div class="flex justify-between gap-5 py-3"><dt class="text-sm text-neutral-500">{{ t('financeAccountsDesign.date') }}</dt><dd class="text-sm font-bold text-neutral-900">{{ dateTime(selectedTransaction.date) }}</dd></div><div class="flex justify-between gap-5 py-3"><dt class="text-sm text-neutral-500">{{ t('financeAccountsDesign.paymentMethod') }}</dt><dd class="text-sm font-bold text-neutral-900">{{ t(`financeAccountsDesign.methods.${selectedTransaction.method}`) }}</dd></div><div class="flex justify-between gap-5 py-3"><dt class="text-sm text-neutral-500">{{ t('financeAccountsDesign.resultingBalance') }}</dt><dd class="text-sm font-bold text-neutral-900">{{ money(selectedTransaction.balance, selectedAccount.currency) }}</dd></div></dl>
                        <p class="mt-5 rounded-xl bg-neutral-50 p-3 text-xs leading-5 text-neutral-500">{{ t('financeAccountsDesign.demoDetailNote') }}</p>
                    </div>
                </aside>
            </div>
        </Transition>

        <div v-if="activeDialog" class="fixed inset-0 z-50 grid place-items-center bg-neutral-950/30 p-4" @click.self="activeDialog = null">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl">
                <div class="flex items-start justify-between"><div><h3 class="text-lg font-extrabold text-neutral-950">{{ activeDialog === 'transfer' ? t('financeAccountsDesign.transferBetween') : t('financeAccountsDesign.createAccount') }}</h3><p class="mt-1 text-sm text-neutral-500">{{ t('financeAccountsDesign.mockOnly') }}</p></div><button class="grid h-9 w-9 place-items-center rounded-lg hover:bg-neutral-100" @click="activeDialog = null"><X class="h-5 w-5" /></button></div>
                <div class="mt-5 rounded-xl border border-dashed border-emerald-300 bg-emerald-50 p-5 text-center"><CalendarDays class="mx-auto h-7 w-7 text-emerald-700" /><p class="mt-2 text-sm font-bold text-emerald-900">{{ t('financeAccountsDesign.previewInteraction') }}</p><p class="mt-1 text-xs text-emerald-700">{{ t('financeAccountsDesign.noRealChanges') }}</p></div>
                <Button class="mt-5 w-full justify-center" @click="activeDialog = null"><Check class="h-4 w-4" /> {{ t('financeAccountsDesign.understood') }}</Button>
            </div>
        </div>
    </AppLayout>
</template>
