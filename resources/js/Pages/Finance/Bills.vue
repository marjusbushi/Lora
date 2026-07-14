<script setup>
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
    Trash2,
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
    suppliers: Array,
    categories: Array,
    accounts: Array,
    byCategory: Object,
    filters: Object,
    summary: Object,
    priorities: Array,
    fxRate: Number,
    can: Object,
    inventoryItems: Array,
    warehouses: Array,
    openCreate: Boolean,
});

const chips = [
    { key: null, label: 'Të gjitha' },
    { key: 'unpaid', label: 'Të papaguara' },
    { key: 'overdue', label: 'Me vonesë' },
    { key: 'paid', label: 'Të paguara' },
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
    visitFilters({ filter });
}

function applySearch() {
    visitFilters({ search: search.value.trim(), category: categoryFilter.value });
}

function clearFilters() {
    search.value = '';
    categoryFilter.value = '';
    visitFilters({ filter: null, category: '', search: '' });
}

const hasFilters = computed(() => Boolean(props.filters.filter || props.filters.category || props.filters.search));

const statusPill = {
    open: { text: 'E papaguar', cls: 'bg-warning-50 text-warning-700' },
    partial: { text: 'Pjesërisht', cls: 'bg-info-50 text-info-700' },
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
    return new Intl.DateTimeFormat('sq-AL', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(date);
}

function dueMeta(bill) {
    if (bill.status === 'paid' || !bill.due_date) return { label: bill.status === 'paid' ? 'E përfunduar' : 'Pa afat', cls: 'text-neutral-400' };

    const due = parseLocalDate(bill.due_date);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const days = Math.round((due - today) / 86400000);

    if (days < 0) return { label: `${Math.abs(days)} ${Math.abs(days) === 1 ? 'ditë' : 'ditë'} vonesë`, cls: 'text-error-600' };
    if (days === 0) return { label: 'Skadon sot', cls: 'text-error-600' };
    if (days === 1) return { label: 'Skadon nesër', cls: 'text-warning-700' };
    if (days <= 7) return { label: `Pas ${days} ditësh`, cls: 'text-warning-700' };
    return { label: `Pas ${days} ditësh`, cls: 'text-neutral-400' };
}

function csvCell(value) {
    return `"${String(value ?? '').replaceAll('"', '""')}"`;
}

function exportVisibleBills() {
    const header = ['Furnitori', 'Nr. faturës', 'Data', 'Afati', 'Kategoria', 'Monedha', 'Totali', 'Mbetja EUR', 'Statusi'];
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

// -- new bill ---------------------------------------------------------------
const showNew = ref(Boolean(props.openCreate));
const todayString = new Date().toISOString().slice(0, 10);
const defaultWarehouseId = computed(() => props.warehouses.find((warehouse) => warehouse.is_default)?.id || props.warehouses[0]?.id || null);
const form = useForm({
    supplier_id: null,
    number: '',
    category: props.categories[0],
    issue_date: todayString,
    due_date: null,
    currency: 'ALL',
    fx_rate: props.fxRate,
    total: null,
    notes: '',
    receive_stock: true,
    items: [],
});

const selectedSupplier = computed(() => props.suppliers.find((supplier) => supplier.id === Number(form.supplier_id)));
const billTotalBase = computed(() => {
    const total = Number(form.total || 0);
    if (form.currency === 'EUR') return total;
    const rate = Number(form.fx_rate || 0);
    return rate > 0 ? total / rate : 0;
});
const inventoryTotal = computed(() => form.items.reduce((total, line) => total + Number(line.quantity || 0) * Number(line.unit_cost || 0), 0));

watch(inventoryTotal, (total) => {
    if (form.items.length) form.total = Number(total.toFixed(2));
});

function addInventoryLine() {
    form.items.push({ inventory_item_id: null, warehouse_id: defaultWarehouseId.value, quantity: 1, unit_cost: null });
}

function removeInventoryLine(index) {
    form.items.splice(index, 1);
    if (!form.items.length) form.total = null;
}

function selectedInventoryItem(line) {
    return props.inventoryItems.find((item) => item.id === Number(line.inventory_item_id));
}

function applyInventoryCost(line) {
    const item = selectedInventoryItem(line);
    if (item && (line.unit_cost === null || line.unit_cost === '')) line.unit_cost = Number(item.average_cost || 0);
    if (item?.type === 'service') line.warehouse_id = null;
    else if (!line.warehouse_id) line.warehouse_id = defaultWarehouseId.value;
}

function resetBillForm() {
    form.reset();
    form.category = props.categories[0];
    form.issue_date = todayString;
    form.currency = 'ALL';
    form.fx_rate = props.fxRate;
    form.receive_stock = true;
    form.items = [];
    form.clearErrors();
}

function closeNew() {
    showNew.value = false;
    form.clearErrors();
}

function submit() {
    form.post(route('finance.bills.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showNew.value = false;
            resetBillForm();
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
    return paying.value.currency === 'EUR' ? amount : amount / Number(paying.value.fx_rate || 1);
});
const remainingAfterPayment = computed(() => Math.max(0, Number(paying.value?.remaining_base || 0) - paymentBase.value));

function openPay(bill) {
    paying.value = bill;
    payForm.account_id = props.accounts[0]?.id;
    payForm.method = 'cash';
    payForm.amount = bill.currency === 'EUR'
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
        <PageHeader title="Faturat e blerjeve" :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Financa' }, { label: 'Blerjet' }]">
            <template #actions>
                <Button variant="outline" :disabled="!bills.data.length" @click="exportVisibleBills">
                    <Download class="h-4 w-4" /> Eksporto CSV
                </Button>
                <Link
                    v-if="can.manageSuppliers"
                    :href="route('finance.suppliers')"
                    class="inline-flex items-center gap-2 rounded-md border border-neutral-200 bg-white px-3.5 py-2 text-body-sm font-semibold text-neutral-700 no-underline shadow-sm hover:border-neutral-300 hover:bg-neutral-50"
                >
                    <Users class="h-4 w-4" /> Furnitorët
                </Link>
                <Button v-if="can.manageBills" @click="showNew = true">
                    <Plus class="h-4 w-4" /> Faturë e re
                </Button>
            </template>
        </PageHeader>

        <p class="mt-1 text-body-sm text-neutral-500">Kontrollo detyrimet, afatet dhe pagesat e furnitorëve.</p>

        <div class="mt-5 space-y-4 pb-6">
            <!-- KPI summary -->
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-lg border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-tiny font-semibold text-neutral-500">Detyrime të hapura</p>
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-accent-50 text-accent-700"><CircleDollarSign class="h-4 w-4" /></span>
                    </div>
                    <p class="mt-2 text-h2 font-bold tabular-nums text-primary-900">{{ money(summary.open_total) }}</p>
                    <p class="mt-2 text-tiny text-neutral-400"><b class="text-accent-700">{{ summary.open_count }} {{ summary.open_count === 1 ? 'faturë' : 'fatura' }}</b> · {{ summary.supplier_count }} {{ summary.supplier_count === 1 ? 'furnitor' : 'furnitorë' }}</p>
                </article>

                <article class="rounded-lg border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-tiny font-semibold text-neutral-500">Me vonesë</p>
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-error-50 text-error-600"><AlertTriangle class="h-4 w-4" /></span>
                    </div>
                    <p class="mt-2 text-h2 font-bold tabular-nums" :class="summary.overdue_total > 0 ? 'text-error-600' : 'text-primary-900'">{{ money(summary.overdue_total) }}</p>
                    <p class="mt-2 text-tiny text-neutral-400"><b :class="summary.overdue_count ? 'text-error-600' : 'text-accent-700'">{{ summary.overdue_count }} {{ summary.overdue_count === 1 ? 'faturë' : 'fatura' }}</b> {{ summary.overdue_count === 1 ? 'ka' : 'kanë' }} kaluar afatin</p>
                </article>

                <article class="rounded-lg border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-tiny font-semibold text-neutral-500">Skadojnë në 7 ditë</p>
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-warning-50 text-warning-700"><Clock3 class="h-4 w-4" /></span>
                    </div>
                    <p class="mt-2 text-h2 font-bold tabular-nums text-primary-900">{{ money(summary.due_soon_total) }}</p>
                    <p class="mt-2 text-tiny text-neutral-400"><b class="text-warning-700">{{ summary.due_soon_count }} {{ summary.due_soon_count === 1 ? 'pagesë' : 'pagesa' }}</b> për t'u planifikuar</p>
                </article>

                <article class="rounded-lg border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-tiny font-semibold text-neutral-500">Paguar këtë muaj</p>
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-accent-50 text-accent-700"><CheckCircle2 class="h-4 w-4" /></span>
                    </div>
                    <p class="mt-2 text-h2 font-bold tabular-nums text-primary-900">{{ money(summary.month_paid_total) }}</p>
                    <p class="mt-2 text-tiny text-neutral-400"><b class="text-accent-700">{{ summary.month_paid_count }} {{ summary.month_paid_count === 1 ? 'faturë' : 'fatura' }}</b> me pagesa të regjistruara</p>
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
                                    <input v-model="search" type="search" class="w-full rounded-lg border-neutral-200 py-2 pl-9 pr-3 text-body-sm placeholder:text-neutral-400 focus:border-accent-500 focus:ring-accent-500" placeholder="Kërko furnitor ose nr. faturë…">
                                </label>
                                <select v-model="categoryFilter" class="rounded-lg border-neutral-200 py-2 pl-3 pr-8 text-body-sm text-neutral-600 focus:border-accent-500 focus:ring-accent-500" @change="applySearch">
                                    <option value="">Të gjitha kategoritë</option>
                                    <option v-for="category in categories" :key="category" :value="category">{{ category }}</option>
                                </select>
                                <Button type="submit" variant="outline" size="sm">Filtro</Button>
                            </form>
                        </div>
                    </div>

                    <!-- bills table -->
                    <Card :padding="false" class="min-w-0 rounded-t-none border-t-0">
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[860px] text-body-sm tabular-nums">
                                <thead>
                                    <tr class="bg-neutral-50/70 text-left text-tiny uppercase tracking-wide text-neutral-400">
                                        <th class="px-5 py-2.5">Furnitori / Fatura</th>
                                        <th class="px-4 py-2.5">Kategoria</th>
                                        <th class="px-4 py-2.5">Afati</th>
                                        <th class="px-4 py-2.5 text-right">Shuma</th>
                                        <th class="px-4 py-2.5 text-right">Mbetja</th>
                                        <th class="px-4 py-2.5">Statusi</th>
                                        <th class="px-5 py-2.5"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="bill in bills.data" :key="bill.id" class="border-t border-neutral-100 transition hover:bg-neutral-50/60">
                                        <td class="px-5 py-3">
                                            <span class="block font-bold text-primary-900">{{ bill.supplier }}</span>
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
                                            <span v-if="bill.currency !== 'EUR'" class="mt-0.5 block text-tiny font-normal text-neutral-400">≈ {{ money(bill.total_base) }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap font-bold" :class="bill.remaining_base > 0 ? 'text-error-600' : 'text-accent-700'">{{ money(bill.remaining_base) }}</td>
                                        <td class="px-4 py-3"><span class="inline-flex items-center gap-1.5 rounded-full px-2 py-1 text-tiny font-bold" :class="statusPill[bill.status]?.cls"><i class="h-1.5 w-1.5 rounded-full bg-current" />{{ statusPill[bill.status]?.text }}</span></td>
                                        <td class="px-5 py-3 text-right"><div class="flex justify-end gap-2">
                                            <Button v-if="can.manageInventory && bill.items_count > bill.received_items_count" size="sm" variant="success" @click="receiveStock(bill)">{{ $t('inventory.bill.receiveNow') }}</Button>
                                            <Button v-if="can.payBills && bill.status !== 'paid'" size="sm" variant="outline" @click="openPay(bill)">{{ $t('admin.generated.k_1be1a3546eed') }}</Button>
                                        </div></td>
                                    </tr>
                                    <tr v-if="!bills.data.length">
                                        <td colspan="7" class="px-5 py-12 text-center">
                                            <span class="mx-auto grid h-11 w-11 place-items-center rounded-full bg-neutral-100 text-neutral-400"><ReceiptText class="h-5 w-5" /></span>
                                            <strong class="mt-3 block text-body-sm text-primary-900">Asnjë faturë e gjetur</strong>
                                            <p class="mt-1 text-tiny text-neutral-400">Ndrysho filtrat ose regjistro një faturë të re.</p>
                                            <button v-if="hasFilters" type="button" class="mt-3 text-tiny font-bold text-accent-700" @click="clearFilters">Pastro filtrat</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="bills.total" class="flex flex-col gap-3 border-t border-neutral-100 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <span class="text-tiny text-neutral-400">{{ bills.from }}–{{ bills.to }} nga {{ bills.total }} fatura</span>
                            <div class="flex items-center gap-1">
                                <Button variant="ghost" size="sm" :disabled="!bills.prev_page_url" @click="router.get(bills.prev_page_url, {}, { preserveState: true, preserveScroll: true })"><ChevronLeft class="h-4 w-4" /> Para</Button>
                                <span class="px-2 text-tiny font-semibold text-neutral-500">{{ bills.current_page }} / {{ bills.last_page }}</span>
                                <Button variant="ghost" size="sm" :disabled="!bills.next_page_url" @click="router.get(bills.next_page_url, {}, { preserveState: true, preserveScroll: true })">Pas <ChevronRight class="h-4 w-4" /></Button>
                            </div>
                        </div>
                    </Card>
                </div>

                <aside class="grid min-w-0 gap-4 md:grid-cols-2 2xl:grid-cols-1">
                    <Card :padding="false" class="min-w-0">
                        <template #header>
                            <div>
                                <h2 class="text-label font-bold text-primary-900">Shpenzimet sipas kategorive</h2>
                                <p class="mt-0.5 text-tiny text-neutral-400">Muaji aktual · EUR</p>
                            </div>
                        </template>
                        <div v-if="categoryEntries.length" class="divide-y divide-neutral-100 px-5 py-1">
                            <div v-for="([category, total]) in categoryEntries" :key="category" class="py-3">
                                <div class="flex items-center justify-between gap-3 text-body-sm"><span class="truncate text-neutral-600">{{ category }}</span><strong class="shrink-0 tabular-nums text-primary-900">{{ money(total) }}</strong></div>
                                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-neutral-100"><i class="block h-full rounded-full bg-accent-500" :style="{ width: categoryWidth(total) }" /></div>
                            </div>
                        </div>
                        <div v-else class="px-5 py-8 text-center text-body-sm text-neutral-400">Ende pa shpenzime këtë muaj.</div>
                    </Card>

                    <Card :padding="false" class="min-w-0">
                        <template #header>
                            <div>
                                <h2 class="text-label font-bold text-primary-900">Prioritetet e pagesës</h2>
                                <p class="mt-0.5 text-tiny text-neutral-400">Renditur sipas afatit</p>
                            </div>
                        </template>
                        <div v-if="priorities.length" class="divide-y divide-neutral-100 px-5">
                            <button v-for="bill in priorities" :key="bill.id" type="button" class="flex w-full items-start gap-3 py-3.5 text-left" @click="can.payBills && openPay(bill)">
                                <i class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="bill.due_state === 'overdue' ? 'bg-error-500' : bill.due_state === 'today' ? 'bg-warning-500' : 'bg-accent-500'" />
                                <span class="min-w-0 flex-1">
                                    <strong class="block truncate text-body-sm text-primary-900">{{ bill.supplier }}</strong>
                                    <span class="mt-1 block text-tiny" :class="dueMeta(bill).cls">{{ dueMeta(bill).label }} · {{ bill.number || '#' + bill.id }}</span>
                                </span>
                                <strong class="shrink-0 text-tiny tabular-nums" :class="bill.due_state === 'overdue' ? 'text-error-600' : 'text-warning-700'">{{ money(bill.remaining_base) }}</strong>
                            </button>
                        </div>
                        <div v-else class="flex flex-col items-center px-5 py-9 text-center">
                            <span class="grid h-11 w-11 place-items-center rounded-full bg-accent-50 text-accent-700"><CheckCircle2 class="h-5 w-5" /></span>
                            <strong class="mt-3 text-body-sm text-primary-900">Asnjë pagesë urgjente</strong>
                            <p class="mt-1 text-tiny text-neutral-400">Të gjitha detyrimet janë nën kontroll.</p>
                        </div>
                    </Card>
                </aside>
            </div>
        </div>

        <!-- New bill modal -->
        <Modal :show="showNew" title="Faturë e re blerjeje" max-width="2xl" @close="closeNew">
            <div class="-mx-5 -my-4 grid lg:grid-cols-[minmax(0,1.65fr),minmax(230px,.75fr)]">
                <div class="space-y-5 p-5">
                    <section>
                        <h4 class="mb-3 text-tiny font-bold uppercase tracking-wide text-neutral-400">1 · Të dhënat e faturës</h4>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-body-sm font-semibold text-primary-900">Furnitori</label>
                                <select v-model="form.supplier_id" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500">
                                    <option :value="null" disabled>Zgjidh furnitorin…</option>
                                    <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option>
                                </select>
                                <p v-if="form.errors.supplier_id" class="mt-1 text-tiny text-error-600">{{ form.errors.supplier_id }}</p>
                            </div>
                            <div>
                                <label class="mb-1 flex items-center justify-between text-body-sm font-semibold text-primary-900"><span>Numri i faturës</span><small class="font-normal text-neutral-400">Opsional</small></label>
                                <TextInput v-model="form.number" class="w-full" placeholder="p.sh. 2026/145" />
                            </div>
                            <div>
                                <label class="mb-1 block text-body-sm font-semibold text-primary-900">Kategoria</label>
                                <select v-model="form.category" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500">
                                    <option v-for="category in categories" :key="category" :value="category">{{ category }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-body-sm font-semibold text-primary-900">Monedha</label>
                                <select v-model="form.currency" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500">
                                    <option value="ALL">ALL · Lek</option>
                                    <option value="EUR">EUR · Euro</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-body-sm font-semibold text-primary-900">Data e faturës</label>
                                <TextInput v-model="form.issue_date" type="date" class="w-full" />
                                <p v-if="form.errors.issue_date" class="mt-1 text-tiny text-error-600">{{ form.errors.issue_date }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-body-sm font-semibold text-primary-900">Afati i pagesës</label>
                                <TextInput v-model="form.due_date" type="date" class="w-full" />
                                <p v-if="form.errors.due_date" class="mt-1 text-tiny text-error-600">{{ form.errors.due_date }}</p>
                            </div>
                        </div>
                    </section>

                    <section class="border-t border-neutral-100 pt-5">
                        <div class="mb-3 flex items-start justify-between gap-3">
                            <div>
                                <h4 class="text-tiny font-bold uppercase tracking-wide text-neutral-400">{{ $t('inventory.bill.linesTitle') }}</h4>
                                <p class="mt-1 text-tiny text-neutral-500">{{ $t('inventory.bill.linesSubtitle') }}</p>
                            </div>
                            <Button v-if="inventoryItems.length" variant="outline" size="sm" @click="addInventoryLine"><PackagePlus class="h-4 w-4" /> {{ $t('inventory.bill.addLine') }}</Button>
                        </div>
                        <div v-if="form.items.length" class="space-y-3">
                            <div v-for="(line, index) in form.items" :key="index" class="rounded-lg border border-neutral-200 bg-neutral-50 p-3">
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div class="sm:col-span-2">
                                        <label class="mb-1 block text-tiny font-semibold text-primary-900">{{ $t('inventory.bill.item') }}</label>
                                        <select v-model="line.inventory_item_id" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500" @change="applyInventoryCost(line)">
                                            <option :value="null" disabled>—</option>
                                            <option v-for="item in inventoryItems" :key="item.id" :value="item.id">{{ item.name }} · {{ item.sku }}</option>
                                        </select>
                                        <p v-if="form.errors[`items.${index}.inventory_item_id`]" class="mt-1 text-tiny text-error-600">{{ form.errors[`items.${index}.inventory_item_id`] }}</p>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-tiny font-semibold text-primary-900">{{ $t('inventory.bill.quantity') }} <span v-if="selectedInventoryItem(line)" class="font-normal text-neutral-400">({{ selectedInventoryItem(line).unit }})</span></label>
                                        <TextInput v-model="line.quantity" type="number" min="0.0001" step="0.0001" class="w-full" />
                                        <p v-if="form.errors[`items.${index}.quantity`]" class="mt-1 text-tiny text-error-600">{{ form.errors[`items.${index}.quantity`] }}</p>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-tiny font-semibold text-primary-900">{{ $t('inventory.bill.unitCost') }} ({{ form.currency }})</label>
                                        <TextInput v-model="line.unit_cost" type="number" min="0" step="0.01" class="w-full" />
                                        <p v-if="form.errors[`items.${index}.unit_cost`]" class="mt-1 text-tiny text-error-600">{{ form.errors[`items.${index}.unit_cost`] }}</p>
                                    </div>
                                    <div v-if="selectedInventoryItem(line)?.type !== 'service'">
                                        <label class="mb-1 block text-tiny font-semibold text-primary-900">{{ $t('inventory.bill.warehouse') }}</label>
                                        <select v-model="line.warehouse_id" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500">
                                            <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                                        </select>
                                        <p v-if="form.errors[`items.${index}.warehouse_id`]" class="mt-1 text-tiny text-error-600">{{ form.errors[`items.${index}.warehouse_id`] }}</p>
                                    </div>
                                    <div class="flex items-end justify-between gap-3" :class="selectedInventoryItem(line)?.type === 'service' && 'sm:col-span-2'">
                                        <div><span class="block text-tiny text-neutral-400">{{ $t('inventory.bill.lineTotal') }}</span><strong class="text-body-sm text-primary-900">{{ money(Number(line.quantity || 0) * Number(line.unit_cost || 0), form.currency) }}</strong></div>
                                        <button type="button" class="rounded-md p-2 text-neutral-400 hover:bg-error-50 hover:text-error-600" @click="removeInventoryLine(index)"><Trash2 class="h-4 w-4" /></button>
                                    </div>
                                </div>
                            </div>
                            <label class="flex items-start gap-3 rounded-lg border border-accent-200 bg-accent-50/60 p-3">
                                <input v-model="form.receive_stock" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-accent-600 focus:ring-accent-500" />
                                <span><strong class="block text-body-sm text-accent-900">{{ $t('inventory.bill.receiveStock') }}</strong><small class="mt-0.5 block text-tiny text-accent-700">{{ $t('inventory.bill.receiveHint') }}</small></span>
                            </label>
                        </div>
                        <div v-else class="rounded-lg border border-dashed border-neutral-200 px-4 py-6 text-center text-body-sm text-neutral-400">
                            {{ $t('inventory.bill.empty') }}
                        </div>
                    </section>

                    <section class="border-t border-neutral-100 pt-5">
                        <h4 class="mb-3 text-tiny font-bold uppercase tracking-wide text-neutral-400">{{ $t('admin.generated.k_5c2ce112243e') }}</h4>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_fe7e86c8fe2c') }}{{ form.currency }})</label>
                                <TextInput v-model="form.total" type="number" min="0.01" step="0.01" class="w-full" placeholder="0.00" :disabled="form.items.length > 0" />
                                <p v-if="form.items.length" class="mt-1 text-tiny text-neutral-400">{{ $t('inventory.bill.calculated') }}</p>
                                <p v-if="form.errors.total" class="mt-1 text-tiny text-error-600">{{ form.errors.total }}</p>
                            </div>
                            <div :class="form.currency === 'EUR' && 'opacity-45'">
                                <label class="mb-1 flex items-center justify-between text-body-sm font-semibold text-primary-900"><span>Kursi</span><small class="font-normal text-neutral-400">L për 1 €</small></label>
                                <TextInput v-model="form.fx_rate" type="number" min="1" step="0.0001" class="w-full" :disabled="form.currency === 'EUR'" />
                                <p v-if="form.currency === 'ALL'" class="mt-1 text-tiny text-neutral-400">Kursi i ditës ruhet përgjithmonë në faturë.</p>
                                <p v-if="form.errors.fx_rate" class="mt-1 text-tiny text-error-600">{{ form.errors.fx_rate }}</p>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="mb-1 flex items-center justify-between text-body-sm font-semibold text-primary-900"><span>Shënime</span><small class="font-normal text-neutral-400">Opsionale</small></label>
                                <textarea v-model="form.notes" rows="3" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm placeholder:text-neutral-400 focus:border-accent-500 focus:ring-accent-500" placeholder="p.sh. furnizim jave 29" />
                                <p v-if="form.errors.notes" class="mt-1 text-tiny text-error-600">{{ form.errors.notes }}</p>
                            </div>
                        </div>
                    </section>
                </div>

                <aside class="border-t border-neutral-200 bg-neutral-50 p-5 lg:border-l lg:border-t-0">
                    <h4 class="text-body-sm font-bold text-primary-900">Përmbledhja</h4>
                    <div class="mt-3 divide-y divide-neutral-100 rounded-lg border border-neutral-200 bg-white px-3">
                        <div class="flex items-center justify-between gap-3 py-2.5 text-tiny"><span class="text-neutral-400">{{ $t('admin.generated.k_3265a32a5fd6') }}</span><b class="text-right text-primary-900">{{ selectedSupplier?.name || '—' }}</b></div>
                        <div class="flex items-center justify-between gap-3 py-2.5 text-tiny"><span class="text-neutral-400">{{ $t('admin.generated.k_7af9506ad5a9') }}</span><b class="text-right text-primary-900">{{ form.number || $t('admin.generated.k_96b10100b8c8') }}</b></div>
                        <div class="flex items-center justify-between gap-3 py-2.5 text-tiny"><span class="text-neutral-400">{{ $t('admin.generated.k_3150b7f0ee0d') }}</span><b class="text-right text-primary-900">{{ formatDate(form.due_date) }}</b></div>
                        <div class="flex items-center justify-between gap-3 py-2.5 text-tiny"><span class="text-neutral-400">{{ $t('admin.generated.k_e63b3779056d') }}</span><b class="text-right text-primary-900">{{ form.currency === 'ALL' ? (form.fx_rate || '—') : $t('admin.generated.k_bef58060168d') }}</b></div>
                        <div class="flex items-center justify-between gap-3 py-2.5 text-tiny"><span class="text-neutral-400">{{ $t('inventory.bill.itemsCount') }}</span><b class="text-right text-primary-900">{{ form.items.length }}</b></div>
                    </div>
                    <div class="mt-3 rounded-lg bg-accent-50 p-3">
                        <span class="text-tiny text-accent-800">Detyrimi në EUR</span>
                        <strong class="mt-1 block text-h2 tabular-nums text-accent-700">{{ money(billTotalBase) }}</strong>
                    </div>
                    <p class="mt-3 rounded-lg border border-warning-200 bg-warning-50 p-3 text-tiny leading-relaxed text-warning-800">Fatura krijon detyrim ndaj furnitorit. Gjendja e arkës ose bankës ndryshon vetëm kur regjistrohet pagesa.</p>
                </aside>
            </div>
            <template #footer>
                <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-center">
                    <p class="mr-auto text-tiny text-neutral-400">Fushat pa shenjën “opsionale” janë të detyrueshme.</p>
                    <div class="flex gap-2">
                        <Button variant="ghost" @click="closeNew">Anulo</Button>
                        <Button :loading="form.processing" :disabled="!form.supplier_id || !form.total || !form.issue_date" @click="submit">Ruaj faturën</Button>
                    </div>
                </div>
            </template>
        </Modal>

        <!-- Pay modal -->
        <Modal :show="!!paying" title="Regjistro pagesën" max-width="xl" @close="closePay">
            <div v-if="paying" class="space-y-4">
                <div class="flex flex-col gap-3 rounded-lg border border-neutral-200 bg-neutral-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <strong class="text-body-sm text-primary-900">{{ paying.supplier }} · {{ paying.number || '#' + paying.id }}</strong>
                        <p class="mt-1 text-tiny text-neutral-400">Afati {{ formatDate(paying.due_date) }}<template v-if="paying.currency !== 'EUR'"> · kursi i ngrirë {{ paying.fx_rate }}</template></p>
                    </div>
                    <strong class="text-h3 tabular-nums text-error-600">{{ money(paying.remaining_base) }}</strong>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-body-sm font-semibold text-primary-900">Nga llogaria</label>
                        <select v-model="payForm.account_id" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500">
                            <option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.name }} ({{ money(account.balance, account.currency) }})</option>
                        </select>
                        <p v-if="payForm.errors.account_id" class="mt-1 text-tiny text-error-600">{{ payForm.errors.account_id }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-body-sm font-semibold text-primary-900">Shuma ({{ paying.currency }})</label>
                        <TextInput v-model="payForm.amount" type="number" min="0.01" step="0.01" class="w-full" />
                        <p v-if="payForm.errors.amount" class="mt-1 text-tiny text-error-600">{{ payForm.errors.amount }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-body-sm font-semibold text-primary-900">Metoda</label>
                        <select v-model="payForm.method" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500">
                            <option value="cash">Cash</option>
                            <option value="card">Kartë</option>
                            <option value="bank">Transfertë bankare</option>
                        </select>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-3 rounded-lg bg-accent-50 px-3 py-2.5 text-body-sm text-accent-800">
                    <span>Mbetja pas kësaj pagese</span>
                    <b class="tabular-nums">{{ money(remainingAfterPayment) }}<template v-if="remainingAfterPayment <= 0.005"> · Fatura mbyllet</template></b>
                </div>
            </div>
            <template #footer>
                <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-center">
                    <p class="mr-auto text-tiny text-neutral-400"><template v-if="selectedAccount">Gjendja e {{ selectedAccount.name }} do të ulet me {{ money(payForm.amount, paying?.currency) }}.</template></p>
                    <div class="flex gap-2">
                        <Button variant="ghost" @click="closePay">Anulo</Button>
                        <Button :loading="payForm.processing" :disabled="!payForm.amount || !payForm.account_id" @click="submitPay">Konfirmo pagesën</Button>
                    </div>
                </div>
            </template>
        </Modal>
    </AppLayout>
</template>
