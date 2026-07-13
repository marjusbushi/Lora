<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowLeftRight,
    ArrowUp,
    ChevronLeft,
    ChevronRight,
    Download,
    Landmark,
    Plus,
    Search,
    SlidersHorizontal,
    WalletCards,
    X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { money, sourceBadge } from './financeShared.js';

const props = defineProps({
    payments: Object,
    accounts: Array,
    filters: Object,
    summary: Object,
    baseCurrency: String,
    fxRate: Number,
    can: Object,
});

const chips = [
    { key: null, label: 'Të gjitha' },
    { key: 'in', label: 'Hyrje' },
    { key: 'out', label: 'Dalje' },
    { key: 'transfer', label: 'Transferta' },
];

const localFilters = reactive({
    direction: props.filters.direction || '',
    query: props.filters.query || '',
    account_id: props.filters.account_id ? String(props.filters.account_id) : '',
    method: props.filters.method || '',
    source: props.filters.source || '',
    date_from: props.filters.date_from || '',
    date_to: props.filters.date_to || '',
    per_page: Number(props.filters.per_page || 20),
});

function cleanFilters(overrides = {}) {
    const values = { ...localFilters, ...overrides };
    const data = {
        direction: values.direction || undefined,
        query: values.query?.trim() || undefined,
        account_id: values.account_id || undefined,
        method: values.method || undefined,
        source: values.source || undefined,
        date_from: values.date_from || undefined,
        date_to: values.date_to || undefined,
        per_page: Number(values.per_page) === 20 ? undefined : Number(values.per_page),
        all_dates: !values.date_from && !values.date_to ? 1 : undefined,
    };

    return Object.fromEntries(Object.entries(data).filter(([, value]) => value !== undefined && value !== ''));
}

function applyFilters(overrides = {}) {
    Object.assign(localFilters, overrides);
    router.get(route('finance.payments'), cleanFilters(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

let searchTimer;
function scheduleSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => applyFilters(), 350);
}

function clearFilters() {
    clearTimeout(searchTimer);
    applyFilters({
        direction: '', query: '', account_id: '', method: '', source: '', date_from: '', date_to: '', per_page: 20,
    });
}

const activeFilterCount = computed(() => [
    localFilters.query,
    localFilters.account_id,
    localFilters.method,
    localFilters.source,
    localFilters.date_from,
    localFilters.date_to,
].filter(Boolean).length);

const visiblePages = computed(() => {
    const current = props.payments.current_page;
    const last = props.payments.last_page;
    if (last <= 7) return Array.from({ length: last }, (_, index) => index + 1);
    const pages = new Set([1, last, current - 1, current, current + 1]);
    const sorted = [...pages].filter((page) => page > 0 && page <= last).sort((a, b) => a - b);
    const result = [];
    sorted.forEach((page, index) => {
        if (index && page - sorted[index - 1] > 1) result.push(`gap-${page}`);
        result.push(page);
    });
    return result;
});

function goToPage(page) {
    if (page < 1 || page > props.payments.last_page || page === props.payments.current_page) return;
    router.get(route('finance.payments'), { ...cleanFilters(), page }, { preserveState: true, preserveScroll: true });
}

function exportPayments() {
    const link = document.createElement('a');
    link.href = route('finance.payments.export', cleanFilters());
    link.click();
}

function methodLabel(method) {
    return ({ cash: 'Cash', card: 'Kartë', bank: 'Bankë', ota: 'OTA' })[method] || method;
}

function directionLabel(direction) {
    return ({ in: 'Hyrje', out: 'Dalje', transfer: 'Transfertë' })[direction] || direction;
}

function paymentAccount(payment) {
    return payment.direction === 'transfer'
        ? `${payment.account} → ${payment.counter_account}`
        : payment.account;
}

const selectedPayment = ref(null);
const selectedReservationId = computed(() => {
    const match = selectedPayment.value?.description?.match(/rezervimi\s+#(\d+)/i);
    return match?.[1] || null;
});

function localDateTime() {
    const now = new Date();
    const local = new Date(now.getTime() - now.getTimezoneOffset() * 60000);
    return local.toISOString().slice(0, 16);
}

const showNew = ref(false);
const form = useForm({
    direction: 'in',
    account_id: props.accounts[0]?.id,
    amount: null,
    currency: props.accounts[0]?.currency || 'EUR',
    fx_rate: props.fxRate,
    method: props.accounts[0]?.type === 'bank' ? 'card' : 'cash',
    description: '',
    paid_at: localDateTime(),
});
const selectedFormAccount = computed(() => props.accounts.find((account) => account.id === Number(form.account_id)));
const fxEquivalent = computed(() => form.currency === 'ALL' && form.fx_rate
    ? money(Number(form.amount || 0) / Number(form.fx_rate))
    : null);

function syncAccountDefaults() {
    const account = selectedFormAccount.value;
    if (!account) return;
    form.currency = account.currency;
    form.method = account.type === 'bank' ? 'card' : 'cash';
}

watch(() => form.account_id, syncAccountDefaults);

function openNewPayment() {
    syncAccountDefaults();
    showNew.value = true;
}

function closeNewPayment() {
    showNew.value = false;
    form.clearErrors();
}

function submit() {
    form.post(route('finance.payments.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showNew.value = false;
            form.reset('amount', 'description');
            form.paid_at = localDateTime();
        },
    });
}
</script>

<template>
    <AppLayout>
        <PageHeader title="Pagesat" :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Financa' }, { label: 'Pagesat' }]">
            <template #actions>
                <Button variant="outline" @click="exportPayments">
                    <Download class="h-4 w-4" />
                    Eksporto
                </Button>
                <Button v-if="can.createPayment" @click="openNewPayment">
                    <Plus class="h-4 w-4" />
                    Pagesë e re
                </Button>
            </template>
        </PageHeader>

        <p class="mt-1 text-body-sm text-neutral-500">Monitoro hyrjet, daljet dhe transfertat nga një vend.</p>

        <div class="mt-5 pb-10 space-y-5">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="flex items-center gap-3 rounded-xl border border-neutral-200 bg-white p-4 shadow-card">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-accent-50 text-accent-700"><ArrowDown class="h-5 w-5" /></span>
                    <div class="min-w-0"><p class="text-body-sm font-medium text-neutral-500">Hyrje në periudhë</p><p class="mt-0.5 truncate text-h3 font-extrabold tabular-nums text-accent-700">{{ money(summary.income) }}</p></div>
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-neutral-200 bg-white p-4 shadow-card">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-error-50 text-error-600"><ArrowUp class="h-5 w-5" /></span>
                    <div class="min-w-0"><p class="text-body-sm font-medium text-neutral-500">Dalje në periudhë</p><p class="mt-0.5 truncate text-h3 font-extrabold tabular-nums text-error-600">{{ money(summary.expenses) }}</p></div>
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-neutral-200 bg-white p-4 shadow-card">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-accent-50 text-accent-700"><WalletCards class="h-5 w-5" /></span>
                    <div class="min-w-0"><p class="text-body-sm font-medium text-neutral-500">Neto</p><p class="mt-0.5 truncate text-h3 font-extrabold tabular-nums" :class="summary.net < 0 ? 'text-error-600' : 'text-primary-900'">{{ money(summary.net) }}</p></div>
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-neutral-200 bg-white p-4 shadow-card">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-neutral-100 text-neutral-600"><ArrowLeftRight class="h-5 w-5" /></span>
                    <div class="min-w-0"><p class="text-body-sm font-medium text-neutral-500">Transferta</p><p class="mt-0.5 text-h3 font-extrabold tabular-nums text-primary-900">{{ summary.transfers }}</p></div>
                </div>
            </div>

            <section class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-card">
                <div class="flex flex-col gap-3 border-b border-neutral-200 p-4 xl:flex-row xl:items-center xl:justify-between">
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="chip in chips"
                            :key="chip.label"
                            type="button"
                            class="inline-flex h-9 items-center gap-1.5 rounded-full border px-3.5 text-tiny font-semibold transition"
                            :class="(localFilters.direction || null) === chip.key ? 'border-primary-900 bg-primary-900 text-white' : 'border-neutral-200 bg-white text-neutral-500 hover:border-neutral-300 hover:text-neutral-700'"
                            @click="applyFilters({ direction: chip.key || '' })"
                        >
                            <ArrowDown v-if="chip.key === 'in'" class="h-3.5 w-3.5" />
                            <ArrowUp v-else-if="chip.key === 'out'" class="h-3.5 w-3.5" />
                            <ArrowLeftRight v-else-if="chip.key === 'transfer'" class="h-3.5 w-3.5" />
                            {{ chip.label }}
                        </button>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <label class="relative min-w-[220px] flex-1 xl:w-64 xl:flex-none">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                            <input v-model="localFilters.query" type="search" class="w-full rounded-lg border-neutral-200 py-2 pl-9 pr-3 text-body-sm placeholder:text-neutral-400 focus:border-accent-500 focus:ring-accent-500" placeholder="Kërko pagesë…" @input="scheduleSearch" />
                        </label>
                        <select v-model="localFilters.account_id" class="rounded-lg border-neutral-200 py-2 pr-8 text-body-sm text-neutral-700 focus:border-accent-500 focus:ring-accent-500" @change="applyFilters()">
                            <option value="">Të gjitha llogaritë</option>
                            <option v-for="account in accounts" :key="account.id" :value="String(account.id)">{{ account.name }}</option>
                        </select>
                        <select v-model="localFilters.method" class="rounded-lg border-neutral-200 py-2 pr-8 text-body-sm text-neutral-700 focus:border-accent-500 focus:ring-accent-500" @change="applyFilters()">
                            <option value="">Të gjitha metodat</option><option value="cash">Cash</option><option value="card">Kartë</option><option value="ota">OTA</option><option value="bank">Bankë</option>
                        </select>
                    </div>
                </div>

                <div class="flex flex-wrap items-end gap-3 border-b border-neutral-100 bg-neutral-50/60 px-4 py-3">
                    <label class="text-tiny font-semibold text-neutral-500"><span class="mb-1 block">Nga data</span><input v-model="localFilters.date_from" type="date" class="rounded-lg border-neutral-200 py-1.5 text-body-sm text-neutral-700 focus:border-accent-500 focus:ring-accent-500" @change="applyFilters()" /></label>
                    <label class="text-tiny font-semibold text-neutral-500"><span class="mb-1 block">Deri më</span><input v-model="localFilters.date_to" type="date" class="rounded-lg border-neutral-200 py-1.5 text-body-sm text-neutral-700 focus:border-accent-500 focus:ring-accent-500" @change="applyFilters()" /></label>
                    <label class="text-tiny font-semibold text-neutral-500">
                        <span class="mb-1 block">Burimi</span>
                        <span class="relative block"><SlidersHorizontal class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><select v-model="localFilters.source" class="rounded-lg border-neutral-200 py-1.5 pl-9 pr-8 text-body-sm text-neutral-700 focus:border-accent-500 focus:ring-accent-500" @change="applyFilters()"><option value="">Të gjitha burimet</option><option value="auto">Automatike</option><option value="manual">Manuale</option></select></span>
                    </label>
                    <button v-if="activeFilterCount" type="button" class="mb-1 text-tiny font-semibold text-accent-700 hover:text-accent-800" @click="clearFilters">Pastro filtrat</button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[980px] text-body-sm tabular-nums">
                        <thead><tr class="border-b border-neutral-100 text-left text-tiny uppercase tracking-wide text-neutral-400">
                            <th class="px-4 py-3">Lloji</th><th class="px-4 py-3">Data</th><th class="px-4 py-3">Përshkrimi</th><th class="px-4 py-3">Metoda</th><th class="px-4 py-3">Llogaria</th><th class="px-4 py-3">Burimi</th><th class="px-4 py-3 text-right">Shuma</th><th class="w-8"></th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="payment in payments.data" :key="payment.id" tabindex="0" class="cursor-pointer border-b border-neutral-100 last:border-0 hover:bg-neutral-50/60 focus:bg-neutral-50 focus:outline-none" @click="selectedPayment = payment" @keydown.enter="selectedPayment = payment">
                                <td class="px-4 py-3"><span class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-tiny font-bold" :class="payment.direction === 'in' ? 'bg-accent-50 text-accent-700' : payment.direction === 'out' ? 'bg-error-50 text-error-600' : 'bg-neutral-100 text-neutral-600'"><ArrowDown v-if="payment.direction === 'in'" class="h-3 w-3" /><ArrowUp v-else-if="payment.direction === 'out'" class="h-3 w-3" /><ArrowLeftRight v-else class="h-3 w-3" />{{ directionLabel(payment.direction) }}</span></td>
                                <td class="whitespace-nowrap px-4 py-3 text-neutral-500">{{ payment.paid_at.slice(0, 16) }}</td>
                                <td class="px-4 py-3 font-medium text-primary-900">{{ payment.description }}</td>
                                <td class="px-4 py-3"><span class="rounded-full bg-neutral-100 px-2 py-1 text-tiny font-semibold text-neutral-600">{{ methodLabel(payment.method) }}</span></td>
                                <td class="whitespace-nowrap px-4 py-3 text-neutral-600">{{ paymentAccount(payment) }}</td>
                                <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-tiny font-bold" :class="sourceBadge(payment).cls">{{ sourceBadge(payment).text }}</span></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold" :class="payment.direction === 'in' ? 'text-accent-700' : payment.direction === 'out' ? 'text-error-600' : 'text-neutral-600'">
                                    {{ payment.direction === 'in' ? '+' : payment.direction === 'out' ? '−' : '' }} {{ money(payment.amount, payment.currency) }}
                                    <span v-if="payment.currency !== 'EUR'" class="block text-tiny font-normal text-neutral-400">≈ {{ money(payment.amount_base) }}</span>
                                </td>
                                <td class="pr-3 text-neutral-300"><ChevronRight class="h-4 w-4" /></td>
                            </tr>
                            <tr v-if="!payments.data.length"><td colspan="8" class="px-4 py-12 text-center text-neutral-400">Asnjë pagesë me këto filtra.</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-col gap-3 border-t border-neutral-200 bg-neutral-50/60 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <label class="flex items-center gap-2 text-body-sm text-neutral-500"><select v-model.number="localFilters.per_page" class="rounded-lg border-neutral-200 py-1.5 pr-8 text-body-sm text-neutral-700 focus:border-accent-500 focus:ring-accent-500" @change="applyFilters()"><option :value="10">10</option><option :value="20">20</option><option :value="30">30</option><option :value="50">50</option></select> për faqe</label>
                    <nav class="flex items-center justify-center gap-1" aria-label="Faqet e pagesave">
                        <button type="button" class="grid h-8 w-8 place-items-center rounded-md border border-neutral-200 bg-white text-neutral-600 hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-40" :disabled="payments.current_page === 1" @click="goToPage(payments.current_page - 1)"><ChevronLeft class="h-4 w-4" /></button>
                        <template v-for="page in visiblePages" :key="page"><span v-if="typeof page === 'string'" class="grid h-8 w-8 place-items-center text-neutral-400">…</span><button v-else type="button" class="h-8 min-w-8 rounded-md px-2 text-body-sm font-semibold" :class="page === payments.current_page ? 'bg-accent-600 text-white' : 'text-neutral-600 hover:bg-neutral-100'" @click="goToPage(page)">{{ page }}</button></template>
                        <button type="button" class="grid h-8 w-8 place-items-center rounded-md border border-neutral-200 bg-white text-neutral-600 hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-40" :disabled="payments.current_page === payments.last_page" @click="goToPage(payments.current_page + 1)"><ChevronRight class="h-4 w-4" /></button>
                    </nav>
                    <p class="text-body-sm tabular-nums text-neutral-500">{{ payments.from || 0 }}–{{ payments.to || 0 }} nga {{ payments.total }} rezultate</p>
                </div>
            </section>
        </div>

        <Modal :show="showNew" @close="closeNewPayment">
            <form class="p-5" @submit.prevent="submit">
                <div class="mb-4 flex items-start justify-between gap-4"><div><h3 class="text-h4 font-bold text-primary-900">Pagesë manuale</h3><p class="mt-1 text-tiny text-neutral-500">Regjistro një arkëtim ose shpenzim jashtë folios/POS-it.</p></div><button type="button" class="grid h-8 w-8 place-items-center rounded-md text-neutral-400 hover:bg-neutral-100" @click="closeNewPayment"><X class="h-4 w-4" /></button></div>
                <div class="mb-4 grid grid-cols-2 gap-2">
                    <button type="button" class="rounded-lg border px-3 py-2.5 text-body-sm font-bold" :class="form.direction === 'in' ? 'border-accent-500 bg-accent-50 text-accent-700' : 'border-neutral-200 text-neutral-500'" @click="form.direction = 'in'"><ArrowDown class="mr-1 inline h-4 w-4" /> Hyrje · Arkëtim</button>
                    <button v-if="can.payBills" type="button" class="rounded-lg border px-3 py-2.5 text-body-sm font-bold" :class="form.direction === 'out' ? 'border-error-500 bg-error-50 text-error-700' : 'border-neutral-200 text-neutral-500'" @click="form.direction = 'out'"><ArrowUp class="mr-1 inline h-4 w-4" /> Dalje · Shpenzim</button>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="mb-1 block text-body-sm font-semibold text-primary-900">Llogaria</label><select v-model="form.account_id" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm"><option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.name }} · {{ money(account.balance, account.currency) }}</option></select><p class="mt-1 text-tiny text-neutral-400">Zgjidh ku do të hyjnë ose dalin fondet.</p></div>
                    <div><label class="mb-1 block text-body-sm font-semibold text-primary-900">Metoda</label><select v-model="form.method" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm"><option value="cash">Cash</option><option value="card">Kartë</option><option value="bank">Bankë</option></select></div>
                    <div><label class="mb-1 block text-body-sm font-semibold text-primary-900">Shuma</label><TextInput v-model="form.amount" type="number" min="0.01" step="0.01" class="w-full" placeholder="0.00" /><p v-if="form.errors.amount" class="mt-1 text-tiny text-error-600">{{ form.errors.amount }}</p></div>
                    <div>
                        <label class="mb-1 block text-body-sm font-semibold text-primary-900">Monedha</label><select v-model="form.currency" :disabled="selectedFormAccount?.currency !== 'EUR'" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm disabled:bg-neutral-50"><option value="EUR">EUR €</option><option value="ALL">ALL Lek</option></select>
                        <div v-if="form.currency === 'ALL'" class="mt-2 rounded-lg bg-neutral-50 p-2"><label class="mb-1 block text-tiny font-semibold text-neutral-500">Kursi · L për 1 €</label><TextInput v-model="form.fx_rate" type="number" min="1" step="0.01" class="w-full" /><p class="mt-1 text-tiny text-neutral-400">Vlera bazë: {{ fxEquivalent }}</p><p v-if="form.errors.fx_rate" class="mt-1 text-tiny text-error-600">{{ form.errors.fx_rate }}</p></div>
                    </div>
                    <div class="sm:col-span-2"><label class="mb-1 block text-body-sm font-semibold text-primary-900">Përshkrimi</label><textarea v-model="form.description" maxlength="300" rows="3" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500" placeholder="p.sh. blerje dekori për recepsionin" /><p v-if="form.errors.description" class="mt-1 text-tiny text-error-600">{{ form.errors.description }}</p></div>
                    <div class="sm:col-span-2"><label class="mb-1 block text-body-sm font-semibold text-primary-900">Data dhe ora</label><input v-model="form.paid_at" type="datetime-local" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500" /><p v-if="form.errors.paid_at" class="mt-1 text-tiny text-error-600">{{ form.errors.paid_at }}</p></div>
                </div>
                <div class="mt-5 flex items-center justify-end gap-2 border-t border-neutral-100 pt-4"><Button variant="ghost" type="button" @click="closeNewPayment">Anulo</Button><Button type="submit" :loading="form.processing" :variant="form.direction === 'out' ? 'danger' : 'primary'" :disabled="!form.amount || !form.description">Ruaj pagesën</Button></div>
            </form>
        </Modal>

        <Transition enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0">
            <div v-if="selectedPayment" class="fixed inset-0 z-50 bg-primary-950/30" @click.self="selectedPayment = null">
                <aside class="ml-auto flex h-full w-full max-w-md flex-col bg-white shadow-xl">
                    <div class="flex items-start justify-between border-b border-neutral-100 p-5"><div><p class="text-tiny font-bold uppercase tracking-wide text-neutral-400">Detajet</p><h2 class="mt-1 text-h4 font-bold text-primary-900">Pagesa #{{ selectedPayment.id }}</h2></div><button type="button" class="grid h-8 w-8 place-items-center rounded-md text-neutral-400 hover:bg-neutral-100" @click="selectedPayment = null"><X class="h-4 w-4" /></button></div>
                    <div class="flex-1 overflow-y-auto p-5">
                        <div class="rounded-xl p-5 text-center" :class="selectedPayment.direction === 'in' ? 'bg-accent-50 text-accent-700' : selectedPayment.direction === 'out' ? 'bg-error-50 text-error-600' : 'bg-neutral-100 text-neutral-700'"><p class="text-body-sm font-semibold">{{ directionLabel(selectedPayment.direction) }}</p><p class="mt-1 text-h2 font-extrabold tabular-nums">{{ selectedPayment.direction === 'in' ? '+' : selectedPayment.direction === 'out' ? '−' : '' }} {{ money(selectedPayment.amount, selectedPayment.currency) }}</p><p v-if="selectedPayment.currency !== 'EUR'" class="mt-1 text-tiny opacity-70">≈ {{ money(selectedPayment.amount_base) }}</p></div>
                        <dl class="mt-5 divide-y divide-neutral-100 text-body-sm"><div class="flex justify-between gap-5 py-3"><dt class="text-neutral-500">Përshkrimi</dt><dd class="m-0 text-right font-semibold text-primary-900">{{ selectedPayment.description }}</dd></div><div class="flex justify-between gap-5 py-3"><dt class="text-neutral-500">Data dhe ora</dt><dd class="m-0 text-right font-semibold text-primary-900">{{ selectedPayment.paid_at.slice(0, 16) }}</dd></div><div class="flex justify-between gap-5 py-3"><dt class="text-neutral-500">Llogaria</dt><dd class="m-0 text-right font-semibold text-primary-900">{{ paymentAccount(selectedPayment) }}</dd></div><div class="flex justify-between gap-5 py-3"><dt class="text-neutral-500">Metoda</dt><dd class="m-0 text-right font-semibold text-primary-900">{{ methodLabel(selectedPayment.method) }}</dd></div><div class="flex justify-between gap-5 py-3"><dt class="text-neutral-500">Burimi</dt><dd class="m-0 text-right"><span class="rounded-full px-2 py-0.5 text-tiny font-bold" :class="sourceBadge(selectedPayment).cls">{{ sourceBadge(selectedPayment).text }}</span></dd></div><div v-if="selectedReservationId" class="flex justify-between gap-5 py-3"><dt class="text-neutral-500">Rezervimi</dt><dd class="m-0 text-right"><Link class="font-semibold text-accent-700 hover:text-accent-800" :href="`/pms/reservations/${selectedReservationId}`">Hap rezervimin #{{ selectedReservationId }} ↗</Link></dd></div></dl>
                        <div class="mt-5 rounded-lg bg-neutral-50 p-3 text-tiny leading-relaxed text-neutral-500">{{ selectedPayment.source === 'auto' ? 'Krijuar automatikisht nga folio/POS. Nuk mund të modifikohet manualisht.' : 'Kjo lëvizje është regjistruar manualisht.' }}<br />ID transaksioni: PAY-{{ String(selectedPayment.id).padStart(6, '0') }}</div>
                    </div>
                </aside>
            </div>
        </Transition>
    </AppLayout>
</template>
