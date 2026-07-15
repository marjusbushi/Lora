<script setup>
import { getIntlLocale, translate } from '@/i18n';
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    CircleDollarSign,
    Clock3,
    Download,
    Plus,
    PackagePlus,
    ReceiptText,
    Search,
    Users,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { money } from './financeShared.js';

const props = defineProps({
    bills: Object,
    categories: Array,
    accounts: Array,
    byCategory: Object,
    filters: Object,
    summary: Object,
    priorities: Array,
    baseCurrency: String,
    can: Object,
});

const chips = [
    { key: null, label: translate('admin.generated.k_52579e29831d') },
    { key: 'unpaid', label: translate('admin.generated.k_cef47cbd08e9') },
    { key: 'overdue', label: translate('admin.generated.k_7f37be9037e8') },
    { key: 'paid', label: translate('admin.generated.k_a785db5f52c1') },
];

const search = ref(props.filters.search || '');
const categoryFilter = ref(props.filters.category || '');

watch(() => props.filters, (filters) => {
    search.value = filters.search || '';
    categoryFilter.value = filters.category || '';
}, { deep: true });

function visitFilters(overrides = {}) {
    const params = {
        filter: overrides.filter !== undefined ? overrides.filter : (props.filters.filter || null),
        category: overrides.category !== undefined ? overrides.category : categoryFilter.value,
        search: overrides.search !== undefined ? overrides.search : search.value.trim(),
        bill_id: overrides.bill_id !== undefined ? overrides.bill_id : (props.filters.bill_id || null),
    };

    Object.keys(params).forEach((key) => {
        if (params[key] === null || params[key] === '') delete params[key];
    });

    router.get(route('finance.bills'), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function selectFilter(filter) {
    visitFilters({ filter, bill_id: null });
}

function applySearch() {
    visitFilters({ search: search.value.trim(), category: categoryFilter.value, bill_id: null });
}

function clearFilters() {
    search.value = '';
    categoryFilter.value = '';
    visitFilters({ filter: null, category: '', search: '', bill_id: null });
}

const hasFilters = computed(() => Boolean(props.filters.filter || props.filters.category || props.filters.search || props.filters.bill_id));

const statusPill = {
    open: { text: 'E papaguar', cls: 'bg-warning-50 text-warning-700' },
    partial: { text: translate('admin.generated.k_be645a3cf5ef'), cls: 'bg-info-50 text-info-700' },
    paid: { text: 'E paguar', cls: 'bg-accent-50 text-accent-700' },
};

const categoryEntries = computed(() => Object.entries(props.byCategory || {}));
const maxCategory = computed(() => Math.max(1, ...categoryEntries.value.map(([, total]) => Number(total))));

function categoryWidth(total) {
    return `${Math.max(4, Math.round((Number(total) / maxCategory.value) * 100))}%`;
}

function parseLocalDate(value) {
    return value ? new Date(`${value}T00:00:00`) : null;
}

function formatDate(value) {
    const date = parseLocalDate(value);
    if (!date) return '—';
    return new Intl.DateTimeFormat(getIntlLocale(), { day: '2-digit', month: '2-digit', year: 'numeric' }).format(date);
}

function dueMeta(bill) {
    if (bill.status === 'paid' || !bill.due_date) return { label: bill.status === 'paid' ? translate('admin.generated.k_307267b27ec7') : translate('admin.generated.k_ea8e92d3cf8c'), cls: 'text-neutral-400' };

    const due = parseLocalDate(bill.due_date);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const days = Math.round((due - today) / 86400000);

    if (days < 0) return { label: translate('admin.generated.k_a4a97c9cf210', { p0: Math.abs(days), p1: Math.abs(days) === 1 ? translate('admin.generated.k_679ad34eb9ee') : translate('admin.generated.k_679ad34eb9ee') }), cls: 'text-error-600' };
    if (days === 0) return { label: translate('admin.generated.k_471eb6636c75'), cls: 'text-error-600' };
    if (days === 1) return { label: translate('admin.generated.k_c19abaa478d9'), cls: 'text-warning-700' };
    if (days <= 7) return { label: translate('admin.generated.k_10230b715e64', { p0: days }), cls: 'text-warning-700' };
    return { label: translate('admin.generated.k_10230b715e64', { p0: days }), cls: 'text-neutral-400' };
}

function csvCell(value) {
    return `"${String(value ?? '').replaceAll('"', '""')}"`;
}

function exportVisibleBills() {
    const header = ['Furnitori', translate('admin.generated.k_7676d472d044'), 'Data', 'Afati', 'Kategoria', 'Monedha', 'Totali', `Mbetja ${props.baseCurrency}`, 'Statusi'];
    const rows = props.bills.data.map((bill) => [
        bill.supplier,
        bill.number || `#${bill.id}`,
        bill.issue_date,
        bill.due_date,
        bill.category,
        bill.currency,
        bill.total,
        bill.remaining_base,
        statusPill[bill.status]?.text || bill.status,
    ]);
    const csv = `\uFEFF${[header, ...rows].map((row) => row.map(csvCell).join(';')).join('\n')}`;
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `faturat-e-blerjeve-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(url);
}

function receiveStock(bill) {
    router.post(route('finance.bills.receive', bill.id), {}, { preserveScroll: true });
}

function openSupplierCreate() {
    supplierForm.reset();
    supplierForm.category = form.category || '';
    supplierForm.payment_terms_days = 0;
    supplierForm.is_active = true;
    supplierForm.clearErrors();
    showSupplierCreate.value = true;
    showCategoryCreate.value = false;
}

function submitSupplier() {
    const supplierName = supplierForm.name.trim();
    supplierForm.post(route('finance.suppliers.store'), {
        preserveScroll: true,
        onSuccess: (page) => {
            const created = (page.props.suppliers || []).find((supplier) => supplier.name.toLocaleLowerCase('sq') === supplierName.toLocaleLowerCase('sq'));
            if (created) form.supplier_id = created.id;
            showSupplierCreate.value = false;
            supplierForm.reset();
        },
    });
}

function openCategoryCreate() {
    categoryForm.reset();
    categoryForm.clearErrors();
    showCategoryCreate.value = true;
    showSupplierCreate.value = false;
}

function submitCategory() {
    const categoryName = categoryForm.name.trim();
    categoryForm.post(route('finance.bill-categories.store'), {
        preserveScroll: true,
        onSuccess: (page) => {
            const created = (page.props.categories || []).find((category) => category.toLocaleLowerCase('sq') === categoryName.toLocaleLowerCase('sq'));
            form.category = created || categoryName;
            showCategoryCreate.value = false;
            categoryForm.reset();
        },
    });
}

// -- pay bill ---------------------------------------------------------------
const paying = ref(null);
const payForm = useForm({ account_id: props.accounts[0]?.id, amount: null, method: 'cash' });
const selectedAccount = computed(() => props.accounts.find((account) => account.id === Number(payForm.account_id)));
const paymentBase = computed(() => {
    if (!paying.value) return 0;
    const amount = Number(payForm.amount || 0);
    return paying.value.currency === props.baseCurrency ? amount : amount / Number(paying.value.fx_rate || 1);
});
const remainingAfterPayment = computed(() => Math.max(0, Number(paying.value?.remaining_base || 0) - paymentBase.value));

function openPay(bill) {
    paying.value = bill;
    payForm.account_id = props.accounts[0]?.id;
    payForm.method = 'cash';
    payForm.amount = bill.currency === props.baseCurrency
        ? bill.remaining_base
        : Math.round(bill.remaining_base * (bill.fx_rate || 1) * 100) / 100;
    payForm.clearErrors();
}

function closePay() {
    paying.value = null;
    payForm.clearErrors();
}

function submitPay() {
    payForm.post(route('finance.bills.pay', paying.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            paying.value = null;
            payForm.reset('amount');
        },
    });
}
</script>

<template>
    <AppLayout>
        <PageHeader :title="$t('admin.generated.k_71e1629640dd')" :breadcrumbs="[{ label: $t('admin.generated.k_9b18c65fca37'), href: '/dashboard' }, { label: $t('admin.generated.k_b23265e6aa57') }, { label: $t('admin.generated.k_6a187b2cb9ce') }]">
            <template #actions>
                <Button variant="outline" :disabled="!bills.data.length" @click="exportVisibleBills">
                    <Download class="h-4 w-4" /> {{ $t('admin.generated.k_0730bd6360c0') }} </Button>
                <Link
                    v-if="can.manageSuppliers"
                    :href="route('finance.suppliers')"
                    class="inline-flex items-center gap-2 rounded-md border border-neutral-200 bg-white px-3.5 py-2 text-body-sm font-semibold text-neutral-700 no-underline shadow-sm hover:border-neutral-300 hover:bg-neutral-50"
                >
                    <Users class="h-4 w-4" /> {{ $t('admin.sidebar.suppliers') }}
                </Link>
                <Link
                    v-if="can.manageBills"
                    :href="route('finance.bills.create')"
                    class="inline-flex items-center gap-2 rounded-md bg-accent-600 px-4 py-2 text-body-sm font-medium text-white no-underline shadow-sm transition-colors hover:bg-accent-700"
                >
                    <Plus class="h-4 w-4" /> {{ $t('admin.finance.billCreate.breadcrumbNew') }}
                </Link>
            </template>
        </PageHeader>

        <p class="mt-1 text-body-sm text-neutral-500">{{ $t('admin.generated.k_4c528203f425') }}</p>

        <div class="mt-5 space-y-4 pb-6">
            <!-- KPI summary -->
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-lg border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-tiny font-semibold text-neutral-500">{{ $t('admin.generated.k_be4ec1e6b6ea') }}</p>
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-accent-50 text-accent-700"><CircleDollarSign class="h-4 w-4" /></span>
                    </div>
                    <p class="mt-2 text-h2 font-bold tabular-nums text-primary-900">{{ money(summary.open_total, baseCurrency) }}</p>
                    <p class="mt-2 text-tiny text-neutral-400"><b class="text-accent-700">{{ summary.open_count }} {{ summary.open_count === 1 ? $t('admin.generated.k_67477e1e9ade') : $t('admin.generated.k_6877acf33608') }}</b> · {{ summary.supplier_count }} {{ summary.supplier_count === 1 ? $t('admin.generated.k_372c52096dd9') : $t('admin.generated.k_403e3c4713bc') }}</p>
                </article>

                <article class="rounded-lg border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-tiny font-semibold text-neutral-500">{{ $t('admin.generated.k_a30602e01511') }}</p>
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-error-50 text-error-600"><AlertTriangle class="h-4 w-4" /></span>
                    </div>
                    <p class="mt-2 text-h2 font-bold tabular-nums" :class="summary.overdue_total > 0 ? 'text-error-600' : 'text-primary-900'">{{ money(summary.overdue_total, baseCurrency) }}</p>
                    <p class="mt-2 text-tiny text-neutral-400"><b :class="summary.overdue_count ? 'text-error-600' : 'text-accent-700'">{{ summary.overdue_count }} {{ summary.overdue_count === 1 ? $t('admin.generated.k_67477e1e9ade') : $t('admin.generated.k_6877acf33608') }}</b> {{ summary.overdue_count === 1 ? $t('admin.generated.k_6e1ca2236d1b') : $t('admin.generated.k_cae9a8822038') }} {{ $t('admin.generated.k_f9451d8834a2') }}</p>
                </article>

                <article class="rounded-lg border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-tiny font-semibold text-neutral-500">{{ $t('admin.generated.k_de6ece7be713') }}</p>
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-warning-50 text-warning-700"><Clock3 class="h-4 w-4" /></span>
                    </div>
                    <p class="mt-2 text-h2 font-bold tabular-nums text-primary-900">{{ money(summary.due_soon_total, baseCurrency) }}</p>
                    <p class="mt-2 text-tiny text-neutral-400"><b class="text-warning-700">{{ summary.due_soon_count }} {{ summary.due_soon_count === 1 ? $t('admin.generated.k_4edd93df3350') : $t('admin.generated.k_cfdec98e9055') }}</b> {{ $t('admin.generated.k_3de61d2766aa') }}</p>
                </article>

                <article class="rounded-lg border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-tiny font-semibold text-neutral-500">{{ $t('admin.generated.k_cb2cf7aa976c') }}</p>
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-accent-50 text-accent-700"><CheckCircle2 class="h-4 w-4" /></span>
                    </div>
                    <p class="mt-2 text-h2 font-bold tabular-nums text-primary-900">{{ money(summary.month_paid_total, baseCurrency) }}</p>
                    <p class="mt-2 text-tiny text-neutral-400"><b class="text-accent-700">{{ summary.month_paid_count }} {{ summary.month_paid_count === 1 ? $t('admin.generated.k_67477e1e9ade') : $t('admin.generated.k_6877acf33608') }}</b> {{ $t('admin.generated.k_c94651e259b1') }}</p>
                </article>
            </div>

            <div class="grid items-start gap-4 2xl:grid-cols-[minmax(0,1.7fr),minmax(280px,.65fr)]">
                <div class="min-w-0">
                    <!-- filters -->
                    <div class="rounded-t-lg border border-neutral-200 bg-white p-3 shadow-card">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div class="inline-flex w-fit max-w-full overflow-x-auto rounded-lg border border-neutral-200 bg-neutral-50 p-1">
                                <button
                                    v-for="chip in chips"
                                    :key="chip.label"
                                    type="button"
                                    class="whitespace-nowrap rounded-md px-3 py-1.5 text-tiny font-semibold transition"
                                    :class="(filters.filter || null) === chip.key ? 'bg-white text-primary-900 shadow-sm' : 'text-neutral-500 hover:text-neutral-700'"
                                    @click="selectFilter(chip.key)"
                                >{{ chip.label }}</button>
                            </div>

                            <form class="flex min-w-0 flex-col gap-2 sm:flex-row" @submit.prevent="applySearch">
                                <label class="relative min-w-0 flex-1 sm:w-64">
                                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                                    <input v-model="search" type="search" class="w-full rounded-lg border-neutral-200 py-2 pl-9 pr-3 text-body-sm placeholder:text-neutral-400 focus:border-accent-500 focus:ring-accent-500" :placeholder="$t('admin.generated.k_89c56edd809a')">
                                </label>
                                <select v-model="categoryFilter" class="rounded-lg border-neutral-200 py-2 pl-3 pr-8 text-body-sm text-neutral-600 focus:border-accent-500 focus:ring-accent-500" @change="applySearch">
                                    <option value="">{{ $t('admin.generated.k_a0baf6a07b80') }}</option>
                                    <option v-for="category in categories" :key="category" :value="category">{{ category }}</option>
                                </select>
                                <Button type="submit" variant="outline" size="sm">{{ $t('admin.generated.k_77b0e58a5f8b') }}</Button>
                            </form>
                        </div>
                    </div>

                    <!-- bills table -->
                    <Card :padding="false" class="min-w-0 rounded-t-none border-t-0">
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[860px] text-body-sm tabular-nums">
                                <thead>
                                    <tr class="bg-neutral-50/70 text-left text-tiny uppercase tracking-wide text-neutral-400">
                                        <th class="px-5 py-2.5">{{ $t('admin.generated.k_6fafe9b70099') }}</th>
                                        <th class="px-4 py-2.5">{{ $t('admin.generated.k_577ba88f4a88') }}</th>
                                        <th class="px-4 py-2.5">{{ $t('admin.generated.k_3150b7f0ee0d') }}</th>
                                        <th class="px-4 py-2.5 text-right">{{ $t('admin.generated.k_8eeba29bc4cb') }}</th>
                                        <th class="px-4 py-2.5 text-right">{{ $t('admin.generated.k_df45d076bee7') }}</th>
                                        <th class="px-4 py-2.5">{{ $t('admin.generated.k_0bda2dacf1f2') }}</th>
                                        <th class="px-5 py-2.5"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="bill in bills.data" :key="bill.id" class="border-t border-neutral-100 transition hover:bg-neutral-50/60">
                                        <td class="px-5 py-3">
                                            <Link :href="route('finance.suppliers', { supplier_id: bill.supplier_id })" class="block font-bold text-primary-900 no-underline hover:text-accent-700 hover:underline">{{ bill.supplier }}</Link>
                                            <span class="mt-0.5 block text-tiny text-neutral-400">{{ bill.number || '#' + bill.id }} · {{ formatDate(bill.issue_date) }}</span>
                                            <span v-if="bill.items_count" class="mt-1 inline-flex items-center gap-1 rounded-full bg-info-50 px-2 py-0.5 text-tiny font-semibold text-info-700"><PackagePlus class="h-3 w-3" /> {{ bill.received_items_count }}/{{ bill.items_count }} stok</span>
                                        </td>
                                        <td class="px-4 py-3"><span class="rounded-md bg-neutral-100 px-2 py-1 text-tiny font-bold text-neutral-500">{{ bill.category }}</span></td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="block font-semibold" :class="dueMeta(bill).cls">{{ formatDate(bill.due_date) }}</span>
                                            <span class="mt-0.5 block text-tiny" :class="dueMeta(bill).cls">{{ dueMeta(bill).label }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap font-bold text-primary-900">
                                            {{ money(bill.total, bill.currency) }}
                                            <span v-if="bill.currency !== baseCurrency" class="mt-0.5 block text-tiny font-normal text-neutral-400">≈ {{ money(bill.total_base, baseCurrency) }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap font-bold" :class="bill.remaining_base > 0 ? 'text-error-600' : 'text-accent-700'">{{ money(bill.remaining_base, baseCurrency) }}</td>
                                        <td class="px-4 py-3"><span class="inline-flex items-center gap-1.5 rounded-full px-2 py-1 text-tiny font-bold" :class="statusPill[bill.status]?.cls"><i class="h-1.5 w-1.5 rounded-full bg-current" />{{ statusPill[bill.status]?.text }}</span></td>
                                        <td class="px-5 py-3 text-right"><div class="flex justify-end gap-2">
                                            <Button v-if="can.manageInventory && bill.items_count > bill.received_items_count" size="sm" variant="success" @click="receiveStock(bill)">{{ $t('inventory.bill.receiveNow') }}</Button>
                                            <Button v-if="can.payBills && bill.status !== 'paid'" size="sm" variant="outline" @click="openPay(bill)">{{ $t('admin.generated.k_1be1a3546eed') }}</Button>
                                        </div></td>
                                    </tr>
                                    <tr v-if="!bills.data.length">
                                        <td colspan="7" class="px-5 py-12 text-center">
                                            <span class="mx-auto grid h-11 w-11 place-items-center rounded-full bg-neutral-100 text-neutral-400"><ReceiptText class="h-5 w-5" /></span>
                                            <strong class="mt-3 block text-body-sm text-primary-900">{{ $t('admin.generated.k_2e11f392d212') }}</strong>
                                            <p class="mt-1 text-tiny text-neutral-400">{{ $t('admin.generated.k_5c1bbdccde83') }}</p>
                                            <button v-if="hasFilters" type="button" class="mt-3 text-tiny font-bold text-accent-700" @click="clearFilters">{{ $t('admin.generated.k_5bc875764299') }}</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="bills.total" class="flex flex-col gap-3 border-t border-neutral-100 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <span class="text-tiny text-neutral-400">{{ bills.from }}–{{ bills.to }} {{ $t('admin.generated.k_d68a2215331d') }} {{ bills.total }} {{ $t('admin.generated.k_9a1cd6eff455') }}</span>
                            <div class="flex items-center gap-1">
                                <Button variant="ghost" size="sm" :disabled="!bills.prev_page_url" @click="router.get(bills.prev_page_url, {}, { preserveState: true, preserveScroll: true })"><ChevronLeft class="h-4 w-4" /> {{ $t('admin.generated.k_69b292a4d038') }}</Button>
                                <span class="px-2 text-tiny font-semibold text-neutral-500">{{ bills.current_page }} / {{ bills.last_page }}</span>
                                <Button variant="ghost" size="sm" :disabled="!bills.next_page_url" @click="router.get(bills.next_page_url, {}, { preserveState: true, preserveScroll: true })">{{ $t('admin.generated.k_5ea23786fdc4') }} <ChevronRight class="h-4 w-4" /></Button>
                            </div>
                        </div>
                    </Card>
                </div>

                <aside class="grid min-w-0 gap-4 md:grid-cols-2 2xl:grid-cols-1">
                    <Card :padding="false" class="min-w-0">
                        <template #header>
                            <div>
                                <h2 class="text-label font-bold text-primary-900">{{ $t('admin.generated.k_3b555a070b11') }}</h2>
                                <p class="mt-0.5 text-tiny text-neutral-400">{{ $t('admin.generated.k_0ec97118bb4e') }}</p>
                            </div>
                        </template>
                        <div v-if="categoryEntries.length" class="divide-y divide-neutral-100 px-5 py-1">
                            <div v-for="([category, total]) in categoryEntries" :key="category" class="py-3">
                                <div class="flex items-center justify-between gap-3 text-body-sm"><span class="truncate text-neutral-600">{{ category }}</span><strong class="shrink-0 tabular-nums text-primary-900">{{ money(total, baseCurrency) }}</strong></div>
                                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-neutral-100"><i class="block h-full rounded-full bg-accent-500" :style="{ width: categoryWidth(total) }" /></div>
                            </div>
                        </div>
                        <div v-else class="px-5 py-8 text-center text-body-sm text-neutral-400">{{ $t('admin.generated.k_5e3bbe14d098') }}</div>
                    </Card>

                    <Card :padding="false" class="min-w-0">
                        <template #header>
                            <div>
                                <h2 class="text-label font-bold text-primary-900">{{ $t('admin.generated.k_244855a444d7') }}</h2>
                                <p class="mt-0.5 text-tiny text-neutral-400">{{ $t('admin.generated.k_e1d32c706b28') }}</p>
                            </div>
                        </template>
                        <div v-if="priorities.length" class="divide-y divide-neutral-100 px-5">
                            <button v-for="bill in priorities" :key="bill.id" type="button" class="flex w-full items-start gap-3 py-3.5 text-left" @click="can.payBills && openPay(bill)">
                                <i class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="bill.due_state === 'overdue' ? 'bg-error-500' : bill.due_state === 'today' ? 'bg-warning-500' : 'bg-accent-500'" />
                                <span class="min-w-0 flex-1">
                                    <strong class="block truncate text-body-sm text-primary-900">{{ bill.supplier }}</strong>
                                    <span class="mt-1 block text-tiny" :class="dueMeta(bill).cls">{{ dueMeta(bill).label }} · {{ bill.number || '#' + bill.id }}</span>
                                </span>
                                <strong class="shrink-0 text-tiny tabular-nums" :class="bill.due_state === 'overdue' ? 'text-error-600' : 'text-warning-700'">{{ money(bill.remaining_base, baseCurrency) }}</strong>
                            </button>
                        </div>
                        <div v-else class="flex flex-col items-center px-5 py-9 text-center">
                            <span class="grid h-11 w-11 place-items-center rounded-full bg-accent-50 text-accent-700"><CheckCircle2 class="h-5 w-5" /></span>
                            <strong class="mt-3 text-body-sm text-primary-900">{{ $t('admin.generated.k_cb56fa5b67b8') }}</strong>
                            <p class="mt-1 text-tiny text-neutral-400">{{ $t('admin.generated.k_1fe1a5b3a31f') }}</p>
                        </div>
                    </Card>
                </aside>
            </div>
        </div>

        <!-- Pay modal -->
        <Modal :show="!!paying" :title="$t('admin.generated.k_f8dbe9cde071')" max-width="xl" @close="closePay">
            <div v-if="paying" class="space-y-4">
                <div class="flex flex-col gap-3 rounded-lg border border-neutral-200 bg-neutral-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <strong class="text-body-sm text-primary-900">{{ paying.supplier }} · {{ paying.number || '#' + paying.id }}</strong>
                        <p class="mt-1 text-tiny text-neutral-400">{{ $t('admin.generated.k_3150b7f0ee0d') }} {{ formatDate(paying.due_date) }}<template v-if="paying.currency !== baseCurrency"> · 1 {{ baseCurrency }} = {{ paying.fx_rate }} {{ paying.currency }}</template></p>
                    </div>
                    <strong class="text-h3 tabular-nums text-error-600">{{ money(paying.remaining_base, baseCurrency) }}</strong>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_675c0067b309') }}</label>
                        <select v-model="payForm.account_id" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500">
                            <option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.name }} ({{ money(account.balance, account.currency) }})</option>
                        </select>
                        <p v-if="payForm.errors.account_id" class="mt-1 text-tiny text-error-600">{{ payForm.errors.account_id }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_fe7e86c8fe2c') }}{{ paying.currency }})</label>
                        <TextInput v-model="payForm.amount" type="number" min="0.01" step="0.01" class="w-full" />
                        <p v-if="payForm.errors.amount" class="mt-1 text-tiny text-error-600">{{ payForm.errors.amount }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_613832f29495') }}</label>
                        <select v-model="payForm.method" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500">
                            <option value="cash">{{ $t('admin.generated.k_56f4be7230f1') }}</option>
                            <option value="card">{{ $t('admin.generated.k_0d5e7a956d27') }}</option>
                            <option value="bank">{{ $t('admin.generated.k_6c3e8257258d') }}</option>
                        </select>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-3 rounded-lg bg-accent-50 px-3 py-2.5 text-body-sm text-accent-800">
                    <span>{{ $t('admin.generated.k_bd9990dc8f2f') }}</span>
                    <b class="tabular-nums">{{ money(remainingAfterPayment, baseCurrency) }}<template v-if="remainingAfterPayment <= 0.005"> {{ $t('admin.generated.k_fa56c9ad434a') }}</template></b>
                </div>
            </div>
            <template #footer>
                <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-center">
                    <p class="mr-auto text-tiny text-neutral-400"><template v-if="selectedAccount">{{ $t('admin.generated.k_274c670ee85a') }} {{ selectedAccount.name }} {{ $t('admin.generated.k_fcf472bc2a61') }} {{ money(payForm.amount, paying?.currency) }}.</template></p>
                    <div class="flex gap-2">
                        <Button variant="ghost" @click="closePay">{{ $t('admin.generated.k_a6d5ed67d888') }}</Button>
                        <Button :loading="payForm.processing" :disabled="!payForm.amount || !payForm.account_id" @click="submitPay">{{ $t('admin.generated.k_abafd754deb3') }}</Button>
                    </div>
                </div>
            </template>
        </Modal>
    </AppLayout>
</template>
