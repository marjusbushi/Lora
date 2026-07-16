<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import {
    AlertCircle,
    Building2,
    CalendarDays,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    CircleDollarSign,
    ExternalLink,
    FileCheck2,
    FileText,
    Hotel,
    Search,
    Store,
    X,
} from 'lucide-vue-next';
import { getIntlLocale, translate } from '@/i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import { money } from './financeShared.js';

const props = defineProps({
    invoices: Object,
    filters: Object,
    summary: Object,
    baseCurrency: String,
});

const localFilters = reactive({
    query: props.filters.query || '',
    source: props.filters.source || '',
    status: props.filters.status || '',
    date_from: props.filters.date_from || '',
    date_to: props.filters.date_to || '',
    per_page: Number(props.filters.per_page || 20),
});
const selectedInvoice = ref(null);
const fiscalizing = ref(null);
let searchTimer;

const statusFilters = computed(() => [
    { value: '', label: translate('admin.finance.salesInvoices.allInvoices'), count: props.summary.status_counts.all },
    { value: 'fiscalized', label: translate('admin.finance.salesInvoices.fiscalized'), count: props.summary.status_counts.fiscalized },
    { value: 'not_fiscalized', label: translate('admin.finance.salesInvoices.notFiscalized'), count: props.summary.status_counts.not_fiscalized },
    { value: 'failed', label: translate('admin.finance.salesInvoices.failed'), count: props.summary.status_counts.failed },
]);

const activeFilterCount = computed(() => [
    localFilters.query,
    localFilters.source,
    localFilters.status,
    localFilters.date_from,
    localFilters.date_to,
].filter(Boolean).length);

function cleanFilters(overrides = {}) {
    const values = { ...localFilters, ...overrides };
    return Object.fromEntries(Object.entries({
        query: values.query?.trim() || undefined,
        source: values.source || undefined,
        status: values.status || undefined,
        date_from: values.date_from || undefined,
        date_to: values.date_to || undefined,
        per_page: Number(values.per_page) === 20 ? undefined : Number(values.per_page),
    }).filter(([, value]) => value !== undefined && value !== ''));
}

function applyFilters(overrides = {}) {
    Object.assign(localFilters, overrides);
    selectedInvoice.value = null;
    router.get(route('finance.invoices'), cleanFilters(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function scheduleSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => applyFilters(), 350);
}

function clearFilters() {
    clearTimeout(searchTimer);
    applyFilters({ query: '', source: '', status: '', date_from: '', date_to: '', per_page: 20 });
}

function goToPage(url) {
    if (!url) return;
    selectedInvoice.value = null;
    router.get(url, {}, { preserveState: true, preserveScroll: true });
}

function openInvoice(invoice) {
    selectedInvoice.value = invoice;
}

function closeInvoice() {
    selectedInvoice.value = null;
}

function fiscalize(invoice) {
    if (!invoice.fiscalize_href || fiscalizing.value) return;
    fiscalizing.value = invoice.key;
    router.post(invoice.fiscalize_href, {}, {
        preserveScroll: true,
        onSuccess: () => {
            router.reload({
                only: ['invoices', 'summary'],
                onSuccess: (page) => {
                    selectedInvoice.value = page.props.invoices.data.find((row) => row.key === invoice.key) || null;
                },
            });
        },
        onFinish: () => { fiscalizing.value = null; },
    });
}

function statusMeta(status) {
    return ({
        fiscalized: {
            label: translate('admin.finance.salesInvoices.fiscalizedStatus'),
            cls: 'bg-accent-50 text-accent-700 ring-accent-200',
            dot: 'bg-accent-500',
        },
        processing: {
            label: translate('admin.finance.salesInvoices.processingStatus'),
            cls: 'bg-info-50 text-info-700 ring-info-200',
            dot: 'bg-info-500',
        },
        failed: {
            label: translate('admin.finance.salesInvoices.failedStatus'),
            cls: 'bg-error-50 text-error-700 ring-error-200',
            dot: 'bg-error-500',
        },
        pending: {
            label: translate('admin.finance.salesInvoices.pendingStatus'),
            cls: 'bg-warning-50 text-warning-700 ring-warning-200',
            dot: 'bg-warning-500',
        },
    })[status] || {
        label: status,
        cls: 'bg-neutral-100 text-neutral-600 ring-neutral-200',
        dot: 'bg-neutral-400',
    };
}

function sourceMeta(source) {
    return source === 'hotel'
        ? { label: translate('admin.finance.salesInvoices.hotelSource'), icon: Hotel, cls: 'bg-info-50 text-info-700' }
        : { label: translate('admin.finance.salesInvoices.posSource'), icon: Store, cls: 'bg-primary-50 text-primary-700' };
}

function paymentLabel(method) {
    return ({
        cash: translate('admin.finance.salesInvoices.cash'),
        banknote: translate('admin.finance.salesInvoices.cash'),
        card: translate('admin.finance.salesInvoices.card'),
        mixed: translate('admin.finance.salesInvoices.mixed'),
    })[method] || method || '—';
}

function formatDate(value, withTime = false) {
    if (!value) return '—';
    return new Intl.DateTimeFormat(getIntlLocale(), withTime
        ? { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }
        : { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(value));
}

function formatQuantity(value) {
    return Number.isInteger(Number(value)) ? Number(value) : Number(value).toLocaleString(getIntlLocale(), { maximumFractionDigits: 3 });
}

function csvCell(value) {
    return `"${String(value ?? '').replaceAll('"', '""')}"`;
}

function exportVisibleInvoices() {
    const header = ['Fatura', 'Data', 'Klienti', 'Burimi', 'Statusi', 'Monedha', 'Totali'];
    const rows = props.invoices.data.map((invoice) => [
        invoice.number,
        formatDate(invoice.issued_at),
        invoice.client,
        sourceMeta(invoice.source).label,
        statusMeta(invoice.status).label,
        invoice.currency,
        invoice.total,
    ]);
    const csv = `\uFEFF${[header, ...rows].map((row) => row.map(csvCell).join(';')).join('\n')}`;
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `faturat-e-shitjes-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(url);
}

function closeOnEscape(event) {
    if (event.key === 'Escape' && selectedInvoice.value) closeInvoice();
}

watch(selectedInvoice, (invoice) => {
    document.body.style.overflow = invoice ? 'hidden' : '';
});

onMounted(() => document.addEventListener('keydown', closeOnEscape));
onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    document.removeEventListener('keydown', closeOnEscape);
    document.body.style.overflow = '';
});
</script>

<template>
    <AppLayout>
        <PageHeader
            :title="$t('admin.finance.salesInvoices.title')"
            :breadcrumbs="[
                { label: $t('admin.sidebar.dashboard'), href: '/dashboard' },
                { label: $t('admin.sidebar.finance'), href: '/pms/finance' },
                { label: $t('admin.finance.salesInvoices.title') },
            ]"
        >
            <template #actions>
                <Button variant="outline" @click="exportVisibleInvoices">
                    <FileText class="h-4 w-4" />
                    CSV
                </Button>
            </template>
        </PageHeader>
        <p class="mt-1 text-body-sm text-neutral-500">{{ $t('admin.finance.salesInvoices.subtitle') }}</p>

        <section class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Card class="overflow-hidden">
                <div class="flex items-center gap-3">
                    <span class="grid h-11 w-11 place-items-center rounded-xl bg-primary-50 text-primary-700"><FileText class="h-5 w-5" /></span>
                    <div class="min-w-0">
                        <p class="text-small text-neutral-500">{{ $t('admin.finance.salesInvoices.allInvoices') }}</p>
                        <p class="mt-0.5 text-h4 font-extrabold tabular-nums text-primary-950">{{ summary.total_count }}</p>
                    </div>
                </div>
            </Card>
            <Card>
                <div class="flex items-center gap-3">
                    <span class="grid h-11 w-11 place-items-center rounded-xl bg-accent-50 text-accent-700"><CircleDollarSign class="h-5 w-5" /></span>
                    <div class="min-w-0">
                        <p class="text-small text-neutral-500">{{ $t('admin.finance.salesInvoices.totalValue') }}</p>
                        <p class="mt-0.5 truncate text-h4 font-extrabold tabular-nums text-primary-950">{{ money(summary.total_value, baseCurrency) }}</p>
                    </div>
                </div>
            </Card>
            <Card>
                <div class="flex items-center gap-3">
                    <span class="grid h-11 w-11 place-items-center rounded-xl bg-accent-50 text-accent-700"><FileCheck2 class="h-5 w-5" /></span>
                    <div class="min-w-0">
                        <p class="text-small text-neutral-500">{{ $t('admin.finance.salesInvoices.fiscalized') }}</p>
                        <p class="mt-0.5 text-h4 font-extrabold tabular-nums text-primary-950">{{ summary.fiscalized_count }}</p>
                    </div>
                </div>
            </Card>
            <Card>
                <div class="flex items-center gap-3">
                    <span class="grid h-11 w-11 place-items-center rounded-xl bg-warning-50 text-warning-700"><AlertCircle class="h-5 w-5" /></span>
                    <div class="min-w-0">
                        <p class="text-small text-neutral-500">{{ $t('admin.finance.salesInvoices.notFiscalized') }}</p>
                        <p class="mt-0.5 text-h4 font-extrabold tabular-nums text-primary-950">{{ summary.not_fiscalized_count }}</p>
                    </div>
                </div>
            </Card>
        </section>

        <Card :padding="false" class="mt-5 overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-4 sm:px-5">
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="filter in statusFilters"
                        :key="filter.value || 'all'"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-body-sm font-semibold transition"
                        :class="localFilters.status === filter.value ? 'border-primary-900 bg-primary-900 text-white' : 'border-neutral-200 bg-white text-neutral-600 hover:border-neutral-300 hover:bg-neutral-50'"
                        @click="applyFilters({ status: filter.value })"
                    >
                        {{ filter.label }}
                        <span v-if="filter.count !== null" class="rounded-full px-1.5 py-0.5 text-tiny" :class="localFilters.status === filter.value ? 'bg-white/15 text-white' : 'bg-neutral-100 text-neutral-500'">{{ filter.count }}</span>
                    </button>
                </div>

                <div class="mt-4 grid gap-2 lg:grid-cols-[minmax(260px,1fr)_180px_150px_150px_auto]">
                    <label class="relative block">
                        <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                        <input v-model="localFilters.query" type="search" :placeholder="$t('admin.finance.salesInvoices.searchPlaceholder')" class="h-10 w-full rounded-md border border-neutral-200 bg-white pl-9 pr-3 text-body-sm text-neutral-800 outline-none transition focus:border-accent-400 focus:ring-2 focus:ring-accent-100" @input="scheduleSearch">
                    </label>
                    <select v-model="localFilters.source" class="h-10 rounded-md border border-neutral-200 bg-white px-3 text-body-sm text-neutral-700 outline-none focus:border-accent-400 focus:ring-2 focus:ring-accent-100" @change="applyFilters()">
                        <option value="">{{ $t('admin.finance.salesInvoices.allSources') }}</option>
                        <option value="hotel">{{ $t('admin.finance.salesInvoices.hotelSource') }}</option>
                        <option value="pos">{{ $t('admin.finance.salesInvoices.posSource') }}</option>
                    </select>
                    <input v-model="localFilters.date_from" type="date" :aria-label="$t('admin.finance.salesInvoices.fromDate')" class="h-10 rounded-md border border-neutral-200 bg-white px-3 text-body-sm text-neutral-700 outline-none focus:border-accent-400 focus:ring-2 focus:ring-accent-100" @change="applyFilters()">
                    <input v-model="localFilters.date_to" type="date" :aria-label="$t('admin.finance.salesInvoices.toDate')" class="h-10 rounded-md border border-neutral-200 bg-white px-3 text-body-sm text-neutral-700 outline-none focus:border-accent-400 focus:ring-2 focus:ring-accent-100" @change="applyFilters()">
                    <Button v-if="activeFilterCount" variant="ghost" @click="clearFilters">
                        <X class="h-4 w-4" />
                        {{ $t('admin.finance.salesInvoices.clearFilters') }}
                    </Button>
                </div>
            </div>

            <div v-if="invoices.data.length" class="overflow-x-auto">
                <table class="w-full border-collapse text-left">
                    <thead class="bg-neutral-50 text-tiny font-bold uppercase tracking-wide text-neutral-400">
                        <tr>
                            <th class="px-5 py-3">{{ $t('admin.finance.salesInvoices.invoice') }}</th>
                            <th class="px-4 py-3">{{ $t('admin.finance.salesInvoices.date') }}</th>
                            <th class="px-4 py-3">{{ $t('admin.finance.salesInvoices.client') }}</th>
                            <th class="px-4 py-3">{{ $t('admin.finance.salesInvoices.source') }}</th>
                            <th class="px-4 py-3">{{ $t('admin.finance.salesInvoices.status') }}</th>
                            <th class="px-5 py-3 text-right">{{ $t('admin.finance.salesInvoices.total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="invoice in invoices.data" :key="invoice.key" class="group cursor-pointer bg-white transition hover:bg-accent-50/35" tabindex="0" @click="openInvoice(invoice)" @keydown.enter="openInvoice(invoice)">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg" :class="sourceMeta(invoice.source).cls"><component :is="sourceMeta(invoice.source).icon" class="h-4 w-4" /></span>
                                    <span class="min-w-0">
                                        <b class="block truncate text-body-sm text-primary-950">{{ invoice.number }}</b>
                                        <small class="block truncate text-tiny text-neutral-400">{{ invoice.reference }}</small>
                                    </span>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3.5 text-body-sm text-neutral-600">{{ formatDate(invoice.issued_at) }}</td>
                            <td class="px-4 py-3.5">
                                <b class="block max-w-[220px] truncate text-body-sm font-semibold text-neutral-800">{{ invoice.client }}</b>
                                <small v-if="invoice.room" class="block max-w-[220px] truncate text-tiny text-neutral-400">{{ invoice.room }}</small>
                            </td>
                            <td class="px-4 py-3.5"><span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-tiny font-bold" :class="sourceMeta(invoice.source).cls"><component :is="sourceMeta(invoice.source).icon" class="h-3.5 w-3.5" />{{ sourceMeta(invoice.source).label }}</span></td>
                            <td class="px-4 py-3.5"><span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-tiny font-bold ring-1 ring-inset" :class="statusMeta(invoice.status).cls"><span class="h-1.5 w-1.5 rounded-full" :class="statusMeta(invoice.status).dot" />{{ statusMeta(invoice.status).label }}</span></td>
                            <td class="whitespace-nowrap px-5 py-3.5 text-right"><b class="text-body font-bold tabular-nums text-primary-950">{{ money(invoice.total, invoice.currency) }}</b><ChevronRight class="ml-2 inline h-4 w-4 text-neutral-300 transition group-hover:translate-x-0.5 group-hover:text-accent-600" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="grid place-items-center px-6 py-16 text-center">
                <span class="grid h-14 w-14 place-items-center rounded-2xl bg-neutral-100 text-neutral-400"><FileText class="h-6 w-6" /></span>
                <h2 class="mt-4 text-h4 font-bold text-primary-950">{{ $t('admin.finance.salesInvoices.emptyTitle') }}</h2>
                <p class="mt-1 max-w-md text-body-sm text-neutral-500">{{ $t('admin.finance.salesInvoices.emptyBody') }}</p>
            </div>

            <div v-if="invoices.total" class="flex flex-col gap-3 border-t border-neutral-200 bg-neutral-50 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-small text-neutral-500">{{ $t('admin.finance.salesInvoices.showing', { from: invoices.from, to: invoices.to, total: invoices.total }) }}</p>
                <div class="flex items-center gap-2">
                    <Button size="sm" variant="outline" :disabled="!invoices.prev_page_url" @click="goToPage(invoices.prev_page_url)"><ChevronLeft class="h-4 w-4" />{{ $t('admin.finance.salesInvoices.previous') }}</Button>
                    <span class="px-2 text-small font-semibold text-neutral-600">{{ invoices.current_page }} / {{ invoices.last_page }}</span>
                    <Button size="sm" variant="outline" :disabled="!invoices.next_page_url" @click="goToPage(invoices.next_page_url)">{{ $t('admin.finance.salesInvoices.next') }}<ChevronRight class="h-4 w-4" /></Button>
                </div>
            </div>
        </Card>

        <Teleport to="body">
            <Transition enter-active-class="duration-200 ease-out" enter-from-class="opacity-0" leave-active-class="duration-150 ease-in" leave-to-class="opacity-0">
                <div v-if="selectedInvoice" class="fixed inset-0 z-50 bg-primary-950/35" @click.self="closeInvoice">
                    <aside class="ml-auto flex h-full w-full max-w-2xl flex-col bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="sales-invoice-title">
                        <header class="flex items-start justify-between gap-4 border-b border-neutral-200 px-5 py-4 sm:px-6">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-tiny font-bold" :class="sourceMeta(selectedInvoice.source).cls"><component :is="sourceMeta(selectedInvoice.source).icon" class="h-3.5 w-3.5" />{{ sourceMeta(selectedInvoice.source).label }}</span>
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-tiny font-bold ring-1 ring-inset" :class="statusMeta(selectedInvoice.status).cls"><span class="h-1.5 w-1.5 rounded-full" :class="statusMeta(selectedInvoice.status).dot" />{{ statusMeta(selectedInvoice.status).label }}</span>
                                </div>
                                <h2 id="sales-invoice-title" class="mt-2 truncate text-h3 font-extrabold text-primary-950">{{ selectedInvoice.number }}</h2>
                                <p class="mt-0.5 text-small text-neutral-500">{{ formatDate(selectedInvoice.issued_at, true) }} · {{ selectedInvoice.reference }}</p>
                            </div>
                            <button type="button" :aria-label="$t('admin.finance.salesInvoices.closeDetails')" class="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" @click="closeInvoice"><X class="h-5 w-5" /></button>
                        </header>

                        <div class="flex-1 overflow-y-auto bg-neutral-50 p-4 sm:p-6">
                            <section class="grid gap-3 sm:grid-cols-2">
                                <div class="rounded-xl border border-neutral-200 bg-white p-4">
                                    <p class="text-tiny font-bold uppercase tracking-wide text-neutral-400">{{ $t('admin.finance.salesInvoices.client') }}</p>
                                    <p class="mt-2 text-body font-bold text-primary-950">{{ selectedInvoice.client }}</p>
                                    <p v-if="selectedInvoice.client_email" class="mt-0.5 text-small text-neutral-500">{{ selectedInvoice.client_email }}</p>
                                    <p v-if="selectedInvoice.room" class="mt-2 inline-flex items-center gap-1.5 text-small text-neutral-600"><Building2 class="h-3.5 w-3.5" />{{ selectedInvoice.room }}</p>
                                </div>
                                <div class="rounded-xl border border-neutral-200 bg-white p-4">
                                    <p class="text-tiny font-bold uppercase tracking-wide text-neutral-400">{{ $t('admin.finance.salesInvoices.payment') }}</p>
                                    <p class="mt-2 text-body font-bold text-primary-950">{{ paymentLabel(selectedInvoice.payment_method) }}</p>
                                    <p v-if="selectedInvoice.stay" class="mt-2 inline-flex items-center gap-1.5 text-small text-neutral-600"><CalendarDays class="h-3.5 w-3.5" />{{ selectedInvoice.stay.check_in }} → {{ selectedInvoice.stay.check_out }} · {{ selectedInvoice.stay.nights }} net</p>
                                    <p v-if="selectedInvoice.operator" class="mt-1 text-small text-neutral-500">{{ $t('admin.finance.salesInvoices.operator') }}: {{ selectedInvoice.operator }}</p>
                                </div>
                            </section>

                            <section class="mt-4 overflow-hidden rounded-xl border border-neutral-200 bg-white">
                                <div class="border-b border-neutral-200 px-4 py-3"><h3 class="text-body-sm font-bold text-primary-950">{{ $t('admin.finance.salesInvoices.details') }}</h3></div>
                                <div class="overflow-x-auto">
                                    <table class="w-full min-w-[560px] text-left">
                                        <thead class="bg-neutral-50 text-tiny font-bold uppercase tracking-wide text-neutral-400"><tr><th class="px-4 py-2.5">{{ $t('admin.finance.salesInvoices.description') }}</th><th class="px-3 py-2.5 text-right">{{ $t('admin.finance.salesInvoices.quantity') }}</th><th class="px-3 py-2.5 text-right">{{ $t('admin.finance.salesInvoices.unitPrice') }}</th><th class="px-3 py-2.5 text-right">{{ $t('admin.finance.salesInvoices.vat') }}</th><th class="px-4 py-2.5 text-right">{{ $t('admin.finance.salesInvoices.total') }}</th></tr></thead>
                                        <tbody class="divide-y divide-neutral-100"><tr v-for="(line, index) in selectedInvoice.lines" :key="index"><td class="px-4 py-3"><b class="text-body-sm font-semibold text-neutral-800">{{ line.name }}</b><small class="block text-tiny text-neutral-400">{{ line.unit }}</small></td><td class="px-3 py-3 text-right text-body-sm tabular-nums text-neutral-600">{{ formatQuantity(line.quantity) }}</td><td class="px-3 py-3 text-right text-body-sm tabular-nums text-neutral-600">{{ money(line.unit_price, selectedInvoice.currency) }}</td><td class="px-3 py-3 text-right text-body-sm tabular-nums text-neutral-600">{{ line.vat_rate }}%</td><td class="px-4 py-3 text-right text-body-sm font-bold tabular-nums text-primary-950">{{ money(line.total, selectedInvoice.currency) }}</td></tr></tbody>
                                    </table>
                                </div>
                                <dl class="ml-auto w-full max-w-sm border-t border-neutral-200 px-4 py-3 text-body-sm">
                                    <div class="flex justify-between py-1.5"><dt class="text-neutral-500">{{ $t('admin.finance.salesInvoices.subtotal') }}</dt><dd class="m-0 font-semibold tabular-nums text-neutral-800">{{ money(selectedInvoice.subtotal, selectedInvoice.currency) }}</dd></div>
                                    <div v-if="selectedInvoice.discount" class="flex justify-between py-1.5"><dt class="text-neutral-500">{{ $t('admin.finance.salesInvoices.discount') }}</dt><dd class="m-0 font-semibold tabular-nums text-error-600">− {{ money(selectedInvoice.discount, selectedInvoice.currency) }}</dd></div>
                                    <div class="flex justify-between py-1.5"><dt class="text-neutral-500">{{ $t('admin.finance.salesInvoices.taxTotal') }}</dt><dd class="m-0 font-semibold tabular-nums text-neutral-800">{{ money(selectedInvoice.tax_total, selectedInvoice.currency) }}</dd></div>
                                    <div class="mt-2 flex justify-between border-t border-neutral-200 pt-3"><dt class="font-bold text-primary-950">{{ $t('admin.finance.salesInvoices.total') }}</dt><dd class="m-0 text-h4 font-extrabold tabular-nums text-primary-950">{{ money(selectedInvoice.total, selectedInvoice.currency) }}</dd></div>
                                </dl>
                            </section>

                            <section v-if="selectedInvoice.fiscal" class="mt-4 rounded-xl border border-neutral-200 bg-white p-4">
                                <div class="flex items-center gap-2"><CheckCircle2 v-if="selectedInvoice.status === 'fiscalized'" class="h-4 w-4 text-accent-600" /><AlertCircle v-else class="h-4 w-4 text-error-600" /><h3 class="text-body-sm font-bold text-primary-950">{{ $t('admin.finance.salesInvoices.fiscalData') }}</h3></div>
                                <dl class="mt-3 divide-y divide-neutral-100 text-small">
                                    <div class="flex justify-between gap-4 py-2"><dt class="text-neutral-500">{{ $t('admin.finance.salesInvoices.fiscalNumber') }}</dt><dd class="m-0 text-right font-semibold text-neutral-800">{{ selectedInvoice.fiscal.number || '—' }}</dd></div>
                                    <div class="flex justify-between gap-4 py-2"><dt class="text-neutral-500">{{ $t('admin.finance.salesInvoices.internalId') }}</dt><dd class="m-0 break-all text-right font-mono text-tiny font-semibold text-neutral-700">{{ selectedInvoice.fiscal.internal_id }}</dd></div>
                                    <div v-if="selectedInvoice.fiscal.iic" class="flex justify-between gap-4 py-2"><dt class="text-neutral-500">IIC</dt><dd class="m-0 break-all text-right font-mono text-tiny text-neutral-700">{{ selectedInvoice.fiscal.iic }}</dd></div>
                                </dl>
                                <div v-if="selectedInvoice.fiscal.last_error" class="mt-3 rounded-lg bg-error-50 p-3 text-small leading-5 text-error-700"><b class="block">{{ $t('admin.finance.salesInvoices.failedStatus') }}</b>{{ selectedInvoice.fiscal.last_error }}</div>
                            </section>
                        </div>

                        <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-neutral-200 bg-white px-5 py-4 sm:px-6">
                            <div class="flex flex-wrap gap-2">
                                <Button v-if="selectedInvoice.fiscal?.verify_url" :href="selectedInvoice.fiscal.verify_url" target="_blank" variant="outline" size="sm"><ExternalLink class="h-4 w-4" />{{ $t('admin.finance.salesInvoices.verify') }}</Button>
                                <Link v-if="selectedInvoice.detail_href" :href="selectedInvoice.detail_href" class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-body-sm font-semibold text-neutral-600 no-underline hover:bg-neutral-100"><ExternalLink class="h-4 w-4" />{{ $t('admin.finance.salesInvoices.openSource') }}</Link>
                            </div>
                            <Button v-if="selectedInvoice.fiscalize_href" :loading="fiscalizing === selectedInvoice.key" @click="fiscalize(selectedInvoice)">
                                {{ selectedInvoice.status === 'failed' ? $t('admin.finance.salesInvoices.retryFiscalization') : $t('admin.finance.salesInvoices.fiscalize') }}
                            </Button>
                        </footer>
                    </aside>
                </div>
            </Transition>
        </Teleport>
    </AppLayout>
</template>
