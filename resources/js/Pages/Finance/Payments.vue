<script setup>
import { translate } from '@/i18n';
import { computed, reactive, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
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
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import TransactionDetailsDrawer from './Components/TransactionDetailsDrawer.vue';
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
    { key: null, label: translate('admin.generated.k_6fb31d77cd7a') },
    { key: 'in', label: translate('admin.generated.k_32ca1edb0c23') },
    { key: 'out', label: translate('admin.generated.k_dc0b7e2bc98e') },
    { key: 'transfer', label: translate('admin.generated.k_1261bbc1c168') },
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
    return ({ cash: 'Cash', card: translate('admin.generated.k_7978c69d3136'), bank: translate('admin.generated.k_7e9186243681'), ota: 'OTA' })[method] || method;
}

function directionLabel(direction) {
    return ({ in: 'Hyrje', out: 'Dalje', transfer: translate('admin.generated.k_8ced4cb90156') })[direction] || direction;
}

function paymentAccount(payment) {
    return payment.direction === 'transfer'
        ? `${payment.account} → ${payment.counter_account}`
        : payment.account;
}

const selectedPayment = ref(null);

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
        <PageHeader :title="$t('admin.generated.k_1b076f6d5726')" :breadcrumbs="[{ label: $t('admin.generated.k_ebd4ffcbbb57'), href: '/dashboard' }, { label: $t('admin.generated.k_e169478356da') }, { label: $t('admin.generated.k_cdef3886e657') }]">
            <template #actions>
                <Button variant="outline" @click="exportPayments">
                    <Download class="h-4 w-4" />
{{ $t('admin.generated.k_4c46633ecafd') }} </Button>
                <Button v-if="can.createPayment" @click="openNewPayment">
                    <Plus class="h-4 w-4" />
{{ $t('admin.generated.k_a971e174c5f1') }} </Button>
            </template>
        </PageHeader>

        <p class="mt-1 text-body-sm text-neutral-500">{{ $t('admin.generated.k_e4cb996b7e1a') }}</p>

        <div class="mt-5 pb-10 space-y-5">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="flex items-center gap-3 rounded-xl border border-neutral-200 bg-white p-4 shadow-card">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-accent-50 text-accent-700"><ArrowDown class="h-5 w-5" /></span>
                    <div class="min-w-0"><p class="text-body-sm font-medium text-neutral-500">{{ $t('admin.generated.k_97cd26a9596c') }}</p><p class="mt-0.5 truncate text-h3 font-extrabold tabular-nums text-accent-700">{{ money(summary.income) }}</p></div>
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-neutral-200 bg-white p-4 shadow-card">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-error-50 text-error-600"><ArrowUp class="h-5 w-5" /></span>
                    <div class="min-w-0"><p class="text-body-sm font-medium text-neutral-500">{{ $t('admin.generated.k_0dc7e9079945') }}</p><p class="mt-0.5 truncate text-h3 font-extrabold tabular-nums text-error-600">{{ money(summary.expenses) }}</p></div>
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-neutral-200 bg-white p-4 shadow-card">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-accent-50 text-accent-700"><WalletCards class="h-5 w-5" /></span>
                    <div class="min-w-0"><p class="text-body-sm font-medium text-neutral-500">{{ $t('admin.generated.k_580c609c5d3b') }}</p><p class="mt-0.5 truncate text-h3 font-extrabold tabular-nums" :class="summary.net < 0 ? 'text-error-600' : 'text-primary-900'">{{ money(summary.net) }}</p></div>
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-neutral-200 bg-white p-4 shadow-card">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-neutral-100 text-neutral-600"><ArrowLeftRight class="h-5 w-5" /></span>
                    <div class="min-w-0"><p class="text-body-sm font-medium text-neutral-500">{{ $t('admin.generated.k_7d386d0e9e86') }}</p><p class="mt-0.5 text-h3 font-extrabold tabular-nums text-primary-900">{{ summary.transfers }}</p></div>
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
                            <input v-model="localFilters.query" type="search" class="w-full rounded-lg border-neutral-200 py-2 pl-9 pr-3 text-body-sm placeholder:text-neutral-400 focus:border-accent-500 focus:ring-accent-500" :placeholder="$t('admin.generated.k_fb7ab1bd2fdd')" @input="scheduleSearch" />
                        </label>
                        <select v-model="localFilters.account_id" class="rounded-lg border-neutral-200 py-2 pr-8 text-body-sm text-neutral-700 focus:border-accent-500 focus:ring-accent-500" @change="applyFilters()">
                            <option value="">{{ $t('admin.generated.k_10503bf9330e') }}</option>
                            <option v-for="account in accounts" :key="account.id" :value="String(account.id)">{{ account.name }}</option>
                        </select>
                        <select v-model="localFilters.method" class="rounded-lg border-neutral-200 py-2 pr-8 text-body-sm text-neutral-700 focus:border-accent-500 focus:ring-accent-500" @change="applyFilters()">
                            <option value="">{{ $t('admin.generated.k_20b48ac69f69') }}</option><option value="cash">{{ $t('admin.generated.k_7ac455cf0851') }}</option><option value="card">{{ $t('admin.generated.k_c4ba878993a8') }}</option><option value="ota">{{ $t('admin.generated.k_5bbdab586826') }}</option><option value="bank">{{ $t('admin.generated.k_d2b6f19a8bef') }}</option>
                        </select>
                    </div>
                </div>

                <div class="flex flex-wrap items-end gap-3 border-b border-neutral-100 bg-neutral-50/60 px-4 py-3">
                    <label class="text-tiny font-semibold text-neutral-500"><span class="mb-1 block">{{ $t('admin.generated.k_41fe934f47c2') }}</span><input v-model="localFilters.date_from" type="date" class="rounded-lg border-neutral-200 py-1.5 text-body-sm text-neutral-700 focus:border-accent-500 focus:ring-accent-500" @change="applyFilters()" /></label>
                    <label class="text-tiny font-semibold text-neutral-500"><span class="mb-1 block">{{ $t('admin.generated.k_3b9cdc2b0dd0') }}</span><input v-model="localFilters.date_to" type="date" class="rounded-lg border-neutral-200 py-1.5 text-body-sm text-neutral-700 focus:border-accent-500 focus:ring-accent-500" @change="applyFilters()" /></label>
                    <label class="text-tiny font-semibold text-neutral-500">
                        <span class="mb-1 block">{{ $t('admin.generated.k_fc0e8f7c7cf0') }}</span>
                        <span class="relative block"><SlidersHorizontal class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><select v-model="localFilters.source" class="rounded-lg border-neutral-200 py-1.5 pl-9 pr-8 text-body-sm text-neutral-700 focus:border-accent-500 focus:ring-accent-500" @change="applyFilters()"><option value="">{{ $t('admin.generated.k_6d8cb08fb247') }}</option><option value="auto">{{ $t('admin.generated.k_87666132e6df') }}</option><option value="manual">{{ $t('admin.generated.k_ee1dc7d60c27') }}</option></select></span>
                    </label>
                    <button v-if="activeFilterCount" type="button" class="mb-1 text-tiny font-semibold text-accent-700 hover:text-accent-800" @click="clearFilters">{{ $t('admin.generated.k_e5f704258e89') }}</button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[980px] text-body-sm tabular-nums">
                        <thead><tr class="border-b border-neutral-100 text-left text-tiny uppercase tracking-wide text-neutral-400">
                            <th class="px-4 py-3">{{ $t('admin.generated.k_65e6723972cf') }}</th><th class="px-4 py-3">{{ $t('admin.generated.k_1a663c917dea') }}</th><th class="px-4 py-3">{{ $t('admin.generated.k_5ed8e9c8f4d2') }}</th><th class="px-4 py-3">{{ $t('admin.generated.k_344d77ed0b66') }}</th><th class="px-4 py-3">{{ $t('admin.generated.k_1e876dc236b9') }}</th><th class="px-4 py-3">{{ $t('admin.generated.k_fc0e8f7c7cf0') }}</th><th class="px-4 py-3 text-right">{{ $t('admin.generated.k_d2b49f928901') }}</th><th class="w-8"></th>
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
                            <tr v-if="!payments.data.length"><td colspan="8" class="px-4 py-12 text-center text-neutral-400">{{ $t('admin.generated.k_677ebcfd293e') }}</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-col gap-3 border-t border-neutral-200 bg-neutral-50/60 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <label class="flex items-center gap-2 text-body-sm text-neutral-500"><select v-model.number="localFilters.per_page" class="rounded-lg border-neutral-200 py-1.5 pr-8 text-body-sm text-neutral-700 focus:border-accent-500 focus:ring-accent-500" @change="applyFilters()"><option :value="10">10</option><option :value="20">20</option><option :value="30">30</option><option :value="50">50</option></select> {{ $t('admin.generated.k_7d792d0995c4') }}</label>
                    <nav class="flex items-center justify-center gap-1" :aria-label="$t('admin.generated.k_3116cc511f6d')">
                        <button type="button" class="grid h-8 w-8 place-items-center rounded-md border border-neutral-200 bg-white text-neutral-600 hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-40" :disabled="payments.current_page === 1" @click="goToPage(payments.current_page - 1)"><ChevronLeft class="h-4 w-4" /></button>
                        <template v-for="page in visiblePages" :key="page"><span v-if="typeof page === 'string'" class="grid h-8 w-8 place-items-center text-neutral-400">…</span><button v-else type="button" class="h-8 min-w-8 rounded-md px-2 text-body-sm font-semibold" :class="page === payments.current_page ? 'bg-accent-600 text-white' : 'text-neutral-600 hover:bg-neutral-100'" @click="goToPage(page)">{{ page }}</button></template>
                        <button type="button" class="grid h-8 w-8 place-items-center rounded-md border border-neutral-200 bg-white text-neutral-600 hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-40" :disabled="payments.current_page === payments.last_page" @click="goToPage(payments.current_page + 1)"><ChevronRight class="h-4 w-4" /></button>
                    </nav>
                    <p class="text-body-sm tabular-nums text-neutral-500">{{ payments.from || 0 }}–{{ payments.to || 0 }} {{ $t('admin.generated.k_bca51c44dede') }} {{ payments.total }} {{ $t('admin.generated.k_b174154e4179') }}</p>
                </div>
            </section>
        </div>

        <Modal :show="showNew" :title="$t('admin.generated.k_b70188b5cb02')" max-width="xl" @close="closeNewPayment">
            <p class="mb-4 text-tiny text-neutral-500">{{ $t('admin.generated.k_aee4f4796a95') }}</p>
            <form id="manual-payment-form" @submit.prevent="submit">
                <div class="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <button type="button" class="rounded-lg border px-3 py-2.5 text-body-sm font-bold" :class="form.direction === 'in' ? 'border-accent-500 bg-accent-50 text-accent-700' : 'border-neutral-200 text-neutral-500'" @click="form.direction = 'in'"><ArrowDown class="mr-1 inline h-4 w-4" /> {{ $t('admin.generated.k_de6f08ba011b') }}</button>
                    <button v-if="can.payBills" type="button" class="rounded-lg border px-3 py-2.5 text-body-sm font-bold" :class="form.direction === 'out' ? 'border-error-500 bg-error-50 text-error-700' : 'border-neutral-200 text-neutral-500'" @click="form.direction = 'out'"><ArrowUp class="mr-1 inline h-4 w-4" /> {{ $t('admin.generated.k_fc6de4f415f6') }}</button>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_1e876dc236b9') }}</label><select v-model="form.account_id" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm"><option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.name }} · {{ money(account.balance, account.currency) }}</option></select><p class="mt-1 text-tiny text-neutral-400">{{ $t('admin.generated.k_8c91c7682a11') }}</p></div>
                    <div><label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_344d77ed0b66') }}</label><select v-model="form.method" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm"><option value="cash">{{ $t('admin.generated.k_7ac455cf0851') }}</option><option value="card">{{ $t('admin.generated.k_c4ba878993a8') }}</option><option value="bank">{{ $t('admin.generated.k_d2b6f19a8bef') }}</option></select></div>
                    <div><label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_d2b49f928901') }}</label><TextInput v-model="form.amount" type="number" min="0.01" step="0.01" class="w-full" placeholder="0.00" /><p v-if="form.errors.amount" class="mt-1 text-tiny text-error-600">{{ form.errors.amount }}</p></div>
                    <div>
                        <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_b36654705cb7') }}</label><select v-model="form.currency" :disabled="selectedFormAccount?.currency !== 'EUR'" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm disabled:bg-neutral-50"><option value="EUR">{{ $t('admin.generated.k_e89e22ad3b97') }}</option><option value="ALL">{{ $t('admin.generated.k_fd4d287d7420') }}</option></select>
                        <div v-if="form.currency === 'ALL'" class="mt-2 rounded-lg bg-neutral-50 p-2"><label class="mb-1 block text-tiny font-semibold text-neutral-500">{{ $t('admin.generated.k_668919f6c94c') }}</label><TextInput v-model="form.fx_rate" type="number" min="1" step="0.01" class="w-full" /><p class="mt-1 text-tiny text-neutral-400">{{ $t('admin.generated.k_171af558d279') }} {{ fxEquivalent }}</p><p v-if="form.errors.fx_rate" class="mt-1 text-tiny text-error-600">{{ form.errors.fx_rate }}</p></div>
                    </div>
                    <div class="sm:col-span-2"><label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_5ed8e9c8f4d2') }}</label><textarea v-model="form.description" maxlength="300" rows="3" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500" :placeholder="$t('admin.generated.k_859da1bc6b2d')" /><p v-if="form.errors.description" class="mt-1 text-tiny text-error-600">{{ form.errors.description }}</p></div>
                    <div class="sm:col-span-2"><label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_0238235638a7') }}</label><input v-model="form.paid_at" type="datetime-local" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500" /><p v-if="form.errors.paid_at" class="mt-1 text-tiny text-error-600">{{ form.errors.paid_at }}</p></div>
                </div>
            </form>
            <template #footer>
                <Button variant="ghost" type="button" @click="closeNewPayment">{{ $t('admin.generated.k_cfdab4713700') }}</Button>
                <Button form="manual-payment-form" type="submit" :loading="form.processing" :variant="form.direction === 'out' ? 'danger' : 'primary'" :disabled="!form.amount || !form.description">{{ $t('admin.generated.k_ceaf64f3479c') }}</Button>
            </template>
        </Modal>

        <TransactionDetailsDrawer :payment="selectedPayment" @close="selectedPayment = null" />
    </AppLayout>
</template>
