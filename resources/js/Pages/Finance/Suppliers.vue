<script setup>
import { getIntlLocale, translate } from '@/i18n';
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import {
    Building2,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    CircleDollarSign,
    Clock3,
    ExternalLink,
    FileText,
    Mail,
    MapPin,
    MoreHorizontal,
    Pencil,
    Phone,
    Plus,
    ReceiptText,
    Search,
    SlidersHorizontal,
    Tags,
    Trash2,
    Users,
    X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { money } from './financeShared.js';

const props = defineProps({
    suppliers: Array,
    focusSupplierId: Number,
    summary: Object,
    categories: Array,
    categoryUsage: Object,
    can: Object,
});

const search = ref('');
const category = ref('');
const status = ref('all');
const sort = ref('open');
const page = ref(1);
const perPage = 20;
const selectedSupplier = ref(props.suppliers.find((supplier) => supplier.id === Number(props.focusSupplierId)) || null);
const editing = ref(null);
const showCategoryManager = ref(false);
const editingCategory = ref(null);

const statusChips = computed(() => [
    { key: 'all', label: translate('admin.generated.k_13117768e2ef'), count: props.suppliers.length },
    { key: 'due', label: translate('admin.generated.k_ef304d73d4dd'), count: props.suppliers.filter((supplier) => supplier.open_balance > 0).length },
    { key: 'overdue', label: translate('admin.generated.k_c6421836c133'), count: props.suppliers.filter((supplier) => supplier.overdue_balance > 0).length },
    { key: 'inactive', label: translate('admin.generated.k_571422ed1f0e'), count: props.suppliers.filter((supplier) => !supplier.is_active).length },
]);

const filteredSuppliers = computed(() => {
    const query = search.value.trim().toLocaleLowerCase('sq');
    const rows = props.suppliers.filter((supplier) => {
        const searchable = [supplier.name, supplier.nipt, supplier.phone, supplier.email]
            .filter(Boolean)
            .join(' ')
            .toLocaleLowerCase('sq');
        const statusMatch = status.value === 'all'
            || (status.value === 'due' && supplier.open_balance > 0)
            || (status.value === 'overdue' && supplier.overdue_balance > 0)
            || (status.value === 'inactive' && !supplier.is_active);

        return (!query || searchable.includes(query))
            && (!category.value || supplier.category === category.value)
            && statusMatch;
    });

    return [...rows].sort((left, right) => {
        if (sort.value === 'name') return left.name.localeCompare(right.name, 'sq');
        if (sort.value === 'ytd') return right.ytd - left.ytd;
        return right.open_balance - left.open_balance || left.name.localeCompare(right.name, 'sq');
    });
});

const pageCount = computed(() => Math.max(1, Math.ceil(filteredSuppliers.value.length / perPage)));
const pagedSuppliers = computed(() => filteredSuppliers.value.slice((page.value - 1) * perPage, page.value * perPage));
const firstResult = computed(() => filteredSuppliers.value.length ? (page.value - 1) * perPage + 1 : 0);
const lastResult = computed(() => Math.min(page.value * perPage, filteredSuppliers.value.length));

watch([search, category, status, sort], () => { page.value = 1; });
watch(pageCount, (count) => { if (page.value > count) page.value = count; });
watch(() => props.focusSupplierId, (id) => {
    selectedSupplier.value = props.suppliers.find((supplier) => supplier.id === Number(id)) || null;
});

const form = useForm({
    name: '',
    nipt: '',
    category: '',
    phone: '',
    email: '',
    address: '',
    payment_terms_days: 0,
    is_active: true,
});
const categoryCreateForm = useForm({ name: '' });
const categoryEditForm = useForm({ name: '' });

function openNew() {
    form.reset();
    form.clearErrors();
    form.is_active = true;
    showCategoryManager.value = false;
    editingCategory.value = null;
    editing.value = 'new';
}

function openEdit(supplier) {
    Object.assign(form, {
        name: supplier.name,
        nipt: supplier.nipt || '',
        category: supplier.category || '',
        phone: supplier.phone || '',
        email: supplier.email || '',
        address: supplier.address || '',
        payment_terms_days: supplier.payment_terms_days || 0,
        is_active: supplier.is_active,
    });
    form.clearErrors();
    editing.value = supplier;
}

function closeForm() {
    editing.value = null;
    showCategoryManager.value = false;
    editingCategory.value = null;
    form.clearErrors();
    categoryCreateForm.clearErrors();
    categoryEditForm.clearErrors();
}

function createCategory() {
    categoryCreateForm.post(route('finance.bill-categories.store'), {
        preserveScroll: true,
        onSuccess: () => categoryCreateForm.reset(),
    });
}

function startCategoryEdit(categoryName) {
    editingCategory.value = categoryName;
    categoryEditForm.name = categoryName;
    categoryEditForm.clearErrors();
}

function cancelCategoryEdit() {
    editingCategory.value = null;
    categoryEditForm.reset();
    categoryEditForm.clearErrors();
}

function updateCategory() {
    const previousName = editingCategory.value;
    const nextName = categoryEditForm.name.trim();
    categoryEditForm.put(route('finance.bill-categories.update', { category: previousName }), {
        preserveScroll: true,
        onSuccess: () => {
            if (form.category === previousName) form.category = nextName;
            cancelCategoryEdit();
        },
    });
}

function categoryIsUsed(categoryName) {
    const usage = props.categoryUsage?.[categoryName];

    return Number(usage?.suppliers || 0) + Number(usage?.bills || 0) > 0;
}

function destroyCategory(categoryName) {
    if (!confirm(translate('financeSuppliers.categories.deleteConfirm', { name: categoryName }))) return;

    categoryEditForm.delete(route('finance.bill-categories.destroy', { category: categoryName }), {
        preserveScroll: true,
        onSuccess: () => {
            if (form.category === categoryName) form.category = '';
            if (editingCategory.value === categoryName) cancelCategoryEdit();
        },
    });
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
            selectedSupplier.value = null;
        },
    };
    if (editing.value === 'new') form.post(route('finance.suppliers.store'), options);
    else form.put(route('finance.suppliers.update', editing.value.id), options);
}

function destroySupplier() {
    if (!confirm(translate('admin.generated.k_df685e8cf9b7'))) return;
    form.delete(route('finance.suppliers.destroy', editing.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
            selectedSupplier.value = null;
        },
    });
}

function initials(name) {
    return name.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
}

function paymentTerms(days) {
    return Number(days) > 0 ? translate('admin.generated.k_24800e132b41', { p0: days }) : translate('admin.generated.k_d07646f41eab');
}

function formatDate(value) {
    if (!value) return translate('admin.generated.k_b6c0952230cb');
    return new Intl.DateTimeFormat(getIntlLocale(), { day: '2-digit', month: 'short', year: 'numeric' })
        .format(new Date(`${value}T12:00:00`));
}

function clearFilters() {
    search.value = '';
    category.value = '';
    status.value = 'all';
    sort.value = 'open';
}
</script>

<template>
    <AppLayout>
        <PageHeader :title="$t('admin.generated.k_e9a17eaba02b')" :breadcrumbs="[{ label: $t('admin.generated.k_a68bc238bcfd'), href: '/dashboard' }, { label: $t('admin.generated.k_56a07b597090') }, { label: $t('admin.generated.k_d17f7a3007c9') }]">
            <template #actions>
                <Button v-if="can.manageSuppliers" @click="openNew">
                    <Plus class="h-4 w-4" /> {{ $t('admin.generated.k_1f98f423adbd') }} </Button>
            </template>
        </PageHeader>

        <p class="mt-1 text-body-sm text-neutral-500">{{ $t('admin.generated.k_01dabecc9375') }}</p>

        <div class="mt-5 space-y-4 pb-6">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-lg border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-tiny font-semibold text-neutral-500">{{ $t('admin.generated.k_fdc76445c726') }}</p>
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-accent-50 text-accent-700"><Users class="h-4 w-4" /></span>
                    </div>
                    <p class="mt-2 text-h2 font-bold tabular-nums text-primary-900">{{ summary.active_count }}</p>
                    <p class="mt-2 text-tiny text-neutral-400"><b class="text-accent-700">{{ summary.category_count }} {{ $t('admin.generated.k_a90b68e78537') }}</b> {{ $t('admin.generated.k_5d5b4a7696ae') }}</p>
                </article>

                <article class="rounded-lg border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-tiny font-semibold text-neutral-500">{{ $t('admin.generated.k_5abe7d852d0c') }}</p>
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-error-50 text-error-600"><CircleDollarSign class="h-4 w-4" /></span>
                    </div>
                    <p class="mt-2 text-h2 font-bold tabular-nums" :class="summary.open_total > 0 ? 'text-error-600' : 'text-primary-900'">{{ money(summary.open_total) }}</p>
                    <p class="mt-2 text-tiny text-neutral-400"><b class="text-error-600">{{ summary.open_bill_count }} {{ $t('admin.generated.k_81eb776dbf53') }}</b> {{ $t('admin.generated.k_5a4c605ba995') }}</p>
                </article>

                <article class="rounded-lg border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-tiny font-semibold text-neutral-500">{{ $t('admin.generated.k_2d9dc1737b07') }}</p>
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-warning-50 text-warning-700"><Clock3 class="h-4 w-4" /></span>
                    </div>
                    <p class="mt-2 text-h2 font-bold tabular-nums" :class="summary.overdue_total > 0 ? 'text-warning-700' : 'text-primary-900'">{{ money(summary.overdue_total) }}</p>
                    <p class="mt-2 text-tiny text-neutral-400"><b class="text-warning-700">{{ summary.overdue_supplier_count }} {{ $t('admin.generated.k_5215898d9296') }}</b> {{ $t('admin.generated.k_9360bdadcd48') }}</p>
                </article>

                <article class="rounded-lg border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-tiny font-semibold text-neutral-500">{{ $t('admin.generated.k_5e396777f0c3') }}</p>
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-neutral-100 text-neutral-600"><ReceiptText class="h-4 w-4" /></span>
                    </div>
                    <p class="mt-2 text-h2 font-bold tabular-nums text-primary-900">{{ money(summary.ytd_total) }}</p>
                    <p class="mt-2 text-tiny text-neutral-400">{{ $t('admin.generated.k_e734372d0f23') }}</p>
                </article>
            </div>

            <section class="overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-card">
                <div class="flex flex-col gap-3 border-b border-neutral-100 p-3 lg:flex-row lg:items-center">
                    <label class="relative min-w-0 flex-1">
                        <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                        <input v-model="search" type="search" class="w-full rounded-lg border-neutral-200 py-2 pl-9 pr-3 text-body-sm placeholder:text-neutral-400 focus:border-accent-500 focus:ring-accent-500" :placeholder="$t('admin.generated.k_dfd200e50f08')">
                    </label>
                    <label class="relative">
                        <SlidersHorizontal class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                        <select v-model="category" class="w-full rounded-lg border-neutral-200 py-2 pl-9 pr-8 text-body-sm text-neutral-600 focus:border-accent-500 focus:ring-accent-500 sm:w-52">
                            <option value="">{{ $t('admin.generated.k_de68621de6fb') }}</option>
                            <option v-for="item in categories" :key="item" :value="item">{{ item }}</option>
                        </select>
                    </label>
                    <select v-model="sort" class="rounded-lg border-neutral-200 py-2 pl-3 pr-8 text-body-sm text-neutral-600 focus:border-accent-500 focus:ring-accent-500">
                        <option value="open">{{ $t('admin.generated.k_98f204a94db4') }}</option>
                        <option value="name">{{ $t('admin.generated.k_3a54a94fa006') }}</option>
                        <option value="ytd">{{ $t('admin.generated.k_199d88fb70d5') }}</option>
                    </select>
                </div>

                <div class="flex max-w-full gap-1.5 overflow-x-auto border-b border-neutral-100 px-3 pb-3">
                    <button
                        v-for="chip in statusChips"
                        :key="chip.key"
                        type="button"
                        class="whitespace-nowrap rounded-full border px-3 py-1.5 text-tiny font-semibold transition"
                        :class="status === chip.key ? 'border-accent-200 bg-accent-50 text-accent-800' : 'border-neutral-200 bg-neutral-50 text-neutral-500 hover:text-neutral-700'"
                        @click="status = chip.key"
                    >{{ chip.label }} · {{ chip.count }}</button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[940px] text-body-sm tabular-nums">
                        <thead>
                            <tr class="bg-neutral-50/80 text-left text-tiny uppercase tracking-wide text-neutral-400">
                                <th class="px-5 py-2.5">{{ $t('admin.generated.k_b209319292ac') }}</th>
                                <th class="px-4 py-2.5">{{ $t('admin.generated.k_0ea0690ef262') }}</th>
                                <th class="px-4 py-2.5">{{ $t('admin.generated.k_73fc852be7f6') }}</th>
                                <th class="px-4 py-2.5">{{ $t('admin.generated.k_a49c54de2d2e') }}</th>
                                <th class="px-4 py-2.5 text-center">{{ $t('admin.generated.k_12d692d69f43') }}</th>
                                <th class="px-4 py-2.5 text-right">{{ $t('admin.generated.k_5abe7d852d0c') }}</th>
                                <th class="px-4 py-2.5 text-right">{{ $t('admin.generated.k_99eee1ebeda9') }}</th>
                                <th class="w-12 px-4 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="supplier in pagedSuppliers"
                                :key="supplier.id"
                                role="button"
                                tabindex="0"
                                class="border-t border-neutral-100 transition hover:bg-neutral-50/70 focus:bg-neutral-50 focus:outline-none"
                                @click="selectedSupplier = supplier"
                                @keydown.enter="selectedSupplier = supplier"
                            >
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-3">
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-accent-50 text-tiny font-bold text-accent-700">{{ initials(supplier.name) }}</span>
                                        <span class="min-w-0">
                                            <strong class="block truncate text-primary-900">{{ supplier.name }}</strong>
                                            <span class="mt-0.5 block truncate text-tiny text-neutral-400">{{ supplier.nipt ? `NIPT ${supplier.nipt}` : $t('admin.generated.k_1b4e7148de74') }}</span>
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-neutral-600">{{ supplier.category || '—' }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-1 text-tiny font-bold" :class="supplier.is_active ? 'bg-accent-50 text-accent-700' : 'bg-neutral-100 text-neutral-500'">
                                        <i class="h-1.5 w-1.5 rounded-full bg-current" />{{ supplier.is_active ? $t('admin.generated.k_e9e230d2c3ef') : $t('admin.generated.k_0e6b7d78df08') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-neutral-600">{{ paymentTerms(supplier.payment_terms_days) }}</td>
                                <td class="px-4 py-3 text-center font-semibold text-neutral-600">{{ supplier.bills_count }}</td>
                                <td class="px-4 py-3 text-right font-bold" :class="supplier.open_balance > 0 ? 'text-error-600' : 'text-primary-900'">{{ money(supplier.open_balance) }}</td>
                                <td class="px-4 py-3 text-right font-bold text-primary-900">{{ money(supplier.ytd) }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button type="button" :aria-label="$t('admin.generated.k_0885e9be1f0e')" class="rounded-md p-1.5 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" @click.stop="selectedSupplier = supplier"><MoreHorizontal class="h-4 w-4" /></button>
                                </td>
                            </tr>
                            <tr v-if="!pagedSuppliers.length">
                                <td colspan="8" class="px-5 py-12 text-center">
                                    <span class="mx-auto grid h-11 w-11 place-items-center rounded-full bg-neutral-100 text-neutral-400"><Building2 class="h-5 w-5" /></span>
                                    <strong class="mt-3 block text-body-sm text-primary-900">{{ $t('admin.generated.k_f2a1cc0f10b1') }}</strong>
                                    <p class="mt-1 text-tiny text-neutral-400">{{ $t('admin.generated.k_c463144c9d95') }}</p>
                                    <button type="button" class="mt-3 text-tiny font-bold text-accent-700" @click="clearFilters">{{ $t('admin.generated.k_d52d72b8e2ed') }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-col gap-3 border-t border-neutral-100 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <span class="text-tiny text-neutral-400">{{ firstResult }}–{{ lastResult }} {{ $t('admin.generated.k_a42e7c87d529') }} {{ filteredSuppliers.length }} {{ $t('admin.generated.k_5215898d9296') }}</span>
                    <div v-if="pageCount > 1" class="flex items-center gap-1">
                        <Button variant="ghost" size="sm" :disabled="page === 1" @click="page--"><ChevronLeft class="h-4 w-4" /> {{ $t('admin.generated.k_0eb476b48a65') }}</Button>
                        <span class="px-2 text-tiny font-semibold text-neutral-500">{{ page }} / {{ pageCount }}</span>
                        <Button variant="ghost" size="sm" :disabled="page === pageCount" @click="page++">{{ $t('admin.generated.k_e3a909a089a1') }} <ChevronRight class="h-4 w-4" /></Button>
                    </div>
                </div>
            </section>
        </div>

        <Teleport to="body">
            <Transition enter-active-class="duration-200 ease-out" enter-from-class="opacity-0" leave-active-class="duration-150 ease-in" leave-to-class="opacity-0">
                <div v-if="selectedSupplier" class="fixed inset-0 z-50 bg-neutral-900/50" @click.self="selectedSupplier = null">
                    <aside class="ml-auto flex h-full w-full max-w-lg flex-col bg-white shadow-modal">
                        <header class="flex items-start justify-between gap-4 border-b border-neutral-200 px-5 py-4">
                            <div>
                                <h2 class="text-h4 font-bold text-primary-900">{{ selectedSupplier.name }}</h2>
                                <span class="mt-1 inline-flex items-center gap-1.5 rounded-full px-2 py-1 text-tiny font-bold" :class="selectedSupplier.is_active ? 'bg-accent-50 text-accent-700' : 'bg-neutral-100 text-neutral-500'"><i class="h-1.5 w-1.5 rounded-full bg-current" />{{ selectedSupplier.is_active ? $t('admin.generated.k_e9e230d2c3ef') : $t('admin.generated.k_0e6b7d78df08') }}</span>
                            </div>
                            <button type="button" :aria-label="$t('admin.generated.k_879ea67ea727')" class="rounded-md p-1.5 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" @click="selectedSupplier = null"><X class="h-5 w-5" /></button>
                        </header>

                        <div class="flex-1 overflow-y-auto p-5">
                            <div class="flex items-center gap-3">
                                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-accent-50 font-bold text-accent-700">{{ initials(selectedSupplier.name) }}</span>
                                <div class="min-w-0">
                                    <strong class="block truncate text-primary-900">{{ selectedSupplier.name }}</strong>
                                    <p class="mt-0.5 truncate text-tiny text-neutral-400">{{ selectedSupplier.category || $t('admin.generated.k_7486f630959c') }}<template v-if="selectedSupplier.nipt"> {{ $t('admin.generated.k_082dbdf562f1') }} {{ selectedSupplier.nipt }}</template></p>
                                </div>
                            </div>

                            <div class="mt-5 grid grid-cols-2 overflow-hidden rounded-lg border border-neutral-200">
                                <div class="border-b border-r border-neutral-200 p-3"><span class="block text-tiny text-neutral-400">{{ $t('admin.generated.k_5abe7d852d0c') }}</span><b class="mt-1 block tabular-nums text-error-600">{{ money(selectedSupplier.open_balance) }}</b></div>
                                <div class="border-b border-neutral-200 p-3"><span class="block text-tiny text-neutral-400">{{ $t('admin.generated.k_9aab88c0798f') }}</span><b class="mt-1 block tabular-nums" :class="selectedSupplier.overdue_balance > 0 ? 'text-warning-700' : 'text-primary-900'">{{ money(selectedSupplier.overdue_balance) }}</b></div>
                                <div class="border-r border-neutral-200 p-3"><span class="block text-tiny text-neutral-400">{{ $t('admin.generated.k_5e396777f0c3') }}</span><b class="mt-1 block tabular-nums text-primary-900">{{ money(selectedSupplier.ytd) }}</b></div>
                                <div class="p-3"><span class="block text-tiny text-neutral-400">{{ $t('admin.generated.k_a49c54de2d2e') }}</span><b class="mt-1 block text-primary-900">{{ paymentTerms(selectedSupplier.payment_terms_days) }}</b></div>
                            </div>

                            <h3 class="mb-2 mt-6 text-tiny font-bold uppercase tracking-wide text-neutral-400">{{ $t('admin.generated.k_ecaa9e12e4bb') }}</h3>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <a v-if="selectedSupplier.phone" :href="`tel:${selectedSupplier.phone}`" class="flex items-center gap-2 rounded-lg bg-neutral-50 p-3 text-body-sm text-primary-900 no-underline"><Phone class="h-4 w-4 text-neutral-400" /><span class="truncate">{{ selectedSupplier.phone }}</span></a>
                                <a v-if="selectedSupplier.email" :href="`mailto:${selectedSupplier.email}`" class="flex items-center gap-2 rounded-lg bg-neutral-50 p-3 text-body-sm text-primary-900 no-underline"><Mail class="h-4 w-4 text-neutral-400" /><span class="truncate">{{ selectedSupplier.email }}</span></a>
                                <div v-if="selectedSupplier.address" class="flex items-start gap-2 rounded-lg bg-neutral-50 p-3 text-body-sm text-primary-900 sm:col-span-2"><MapPin class="mt-0.5 h-4 w-4 shrink-0 text-neutral-400" /><span>{{ selectedSupplier.address }}</span></div>
                                <p v-if="!selectedSupplier.phone && !selectedSupplier.email && !selectedSupplier.address" class="text-body-sm text-neutral-400 sm:col-span-2">{{ $t('admin.generated.k_5ea5ea029274') }}</p>
                            </div>

                            <div class="mb-2 mt-6 flex items-center justify-between gap-3">
                                <h3 class="text-tiny font-bold uppercase tracking-wide text-neutral-400">{{ $t('admin.generated.k_0745c2902c3c') }}</h3>
                                <Link :href="route('finance.bills', { search: selectedSupplier.name, filter: 'unpaid' })" class="inline-flex items-center gap-1 text-tiny font-bold text-accent-700 no-underline">{{ $t('admin.generated.k_0b2a03f705f2') }} <ExternalLink class="h-3.5 w-3.5" /></Link>
                            </div>
                            <div v-if="selectedSupplier.open_bills.length" class="divide-y divide-neutral-100">
                                <Link v-for="bill in selectedSupplier.open_bills" :key="bill.id" :href="route('finance.bills', { bill_id: bill.id })" class="flex items-start gap-3 py-3 text-neutral-700 no-underline hover:bg-neutral-50">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-neutral-100 text-neutral-500"><FileText class="h-4 w-4" /></span>
                                    <span class="min-w-0 flex-1">
                                        <strong class="block text-body-sm text-primary-900">{{ bill.number || `#${bill.id}` }}</strong>
                                        <span class="mt-0.5 flex items-center gap-1 text-tiny" :class="bill.is_overdue ? 'text-error-600' : 'text-neutral-400'"><CalendarDays class="h-3.5 w-3.5" />{{ formatDate(bill.due_date) }}<template v-if="bill.is_overdue"> · {{ bill.overdue_days }} {{ $t('admin.generated.k_fb379c89ee4f') }}</template></span>
                                    </span>
                                    <strong class="shrink-0 tabular-nums" :class="bill.is_overdue ? 'text-error-600' : 'text-primary-900'">{{ money(bill.remaining_base) }}</strong>
                                </Link>
                            </div>
                            <div v-else class="rounded-lg bg-accent-50 px-4 py-5 text-center"><strong class="block text-body-sm text-accent-800">{{ $t('admin.generated.k_d3634574edb0') }}</strong><p class="mt-1 text-tiny text-accent-700">{{ $t('admin.generated.k_02e5f9e107af') }}</p></div>
                        </div>

                        <footer class="flex items-center justify-between gap-3 border-t border-neutral-200 bg-neutral-50 px-5 py-3">
                            <Button variant="ghost" @click="selectedSupplier = null">{{ $t('admin.generated.k_6221d37c26eb') }}</Button>
                            <Button v-if="can.manageSuppliers" @click="openEdit(selectedSupplier)"><Pencil class="h-4 w-4" /> {{ $t('admin.generated.k_9733ffdb85cb') }}</Button>
                        </footer>
                    </aside>
                </div>
            </Transition>
        </Teleport>

        <Modal :show="!!editing" :title="editing === 'new' ? $t('admin.generated.k_a5193b1ca181') : $t('admin.generated.k_accafa0df83c')" max-width="2xl" @close="closeForm">
            <p class="mb-4 text-body-sm text-neutral-500">{{ $t('admin.generated.k_17886fd91bb3') }}</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_e209dd44bdf1') }}</label>
                    <TextInput v-model="form.name" class="w-full" :placeholder="$t('admin.generated.k_a29590792933')" />
                    <p v-if="form.errors.name" class="mt-1 text-tiny text-error-600">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_40e20db9e794') }} <span class="font-normal text-neutral-400">{{ $t('admin.generated.k_27b17d53056c') }}</span></label>
                    <TextInput v-model="form.nipt" class="w-full" :placeholder="$t('admin.generated.k_e6bc3d7fdb4a')" />
                    <p v-if="form.errors.nipt" class="mt-1 text-tiny text-error-600">{{ form.errors.nipt }}</p>
                </div>
                <div>
                    <div class="mb-1 flex items-center justify-between gap-3">
                        <label class="block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_0ea0690ef262') }}</label>
                        <button
                            type="button"
                            class="text-tiny font-bold text-accent-700 hover:text-accent-800"
                            @click="showCategoryManager = !showCategoryManager"
                        >{{ showCategoryManager ? $t('financeSuppliers.categories.close') : $t('financeSuppliers.categories.manage') }}</button>
                    </div>
                    <select v-model="form.category" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500">
                        <option value="">—</option>
                        <option v-for="item in categories" :key="item" :value="item">{{ item }}</option>
                    </select>
                    <p v-if="form.errors.category" class="mt-1 text-tiny text-error-600">{{ form.errors.category }}</p>
                </div>

                <section v-if="showCategoryManager" class="sm:col-span-2 overflow-hidden rounded-lg border border-neutral-200 bg-neutral-50/70">
                    <div class="flex items-start gap-3 border-b border-neutral-200 px-4 py-3">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-accent-50 text-accent-700"><Tags class="h-4 w-4" /></span>
                        <div>
                            <h4 class="text-body-sm font-bold text-primary-900">{{ $t('financeSuppliers.categories.title') }}</h4>
                            <p class="mt-0.5 text-tiny text-neutral-500">{{ $t('financeSuppliers.categories.description') }}</p>
                        </div>
                    </div>

                    <div class="border-b border-neutral-200 p-3">
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <input
                                v-model="categoryCreateForm.name"
                                type="text"
                                maxlength="60"
                                class="min-w-0 flex-1 rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500"
                                :placeholder="$t('financeSuppliers.categories.addPlaceholder')"
                                @keydown.enter.prevent="createCategory"
                            >
                            <Button size="sm" :loading="categoryCreateForm.processing" :disabled="!categoryCreateForm.name.trim()" @click="createCategory">
                                <Plus class="h-4 w-4" /> {{ $t('financeSuppliers.categories.add') }}
                            </Button>
                        </div>
                        <p v-if="categoryCreateForm.errors.name" class="mt-1 text-tiny text-error-600">{{ categoryCreateForm.errors.name }}</p>
                    </div>

                    <div class="max-h-56 divide-y divide-neutral-200 overflow-y-auto bg-white">
                        <div v-for="item in categories" :key="item" class="flex items-center gap-3 px-4 py-2.5">
                            <template v-if="editingCategory === item">
                                <div class="min-w-0 flex-1">
                                    <input
                                        v-model="categoryEditForm.name"
                                        type="text"
                                        maxlength="60"
                                        class="w-full rounded-lg border-neutral-200 px-3 py-1.5 text-body-sm focus:border-accent-500 focus:ring-accent-500"
                                        @keydown.enter.prevent="updateCategory"
                                        @keydown.esc.prevent="cancelCategoryEdit"
                                    >
                                    <p v-if="categoryEditForm.errors.name" class="mt-1 text-tiny text-error-600">{{ categoryEditForm.errors.name }}</p>
                                </div>
                                <button type="button" class="text-tiny font-bold text-accent-700" :disabled="categoryEditForm.processing || !categoryEditForm.name.trim()" @click="updateCategory">{{ $t('financeSuppliers.categories.save') }}</button>
                                <button type="button" class="text-tiny font-semibold text-neutral-500" :disabled="categoryEditForm.processing" @click="cancelCategoryEdit">{{ $t('financeSuppliers.categories.cancel') }}</button>
                            </template>
                            <template v-else>
                                <div class="min-w-0 flex-1">
                                    <strong class="block truncate text-body-sm text-primary-900">{{ item }}</strong>
                                    <span class="text-tiny text-neutral-400">{{ $t('financeSuppliers.categories.usage', { suppliers: categoryUsage?.[item]?.suppliers || 0, bills: categoryUsage?.[item]?.bills || 0 }) }}</span>
                                </div>
                                <button type="button" :aria-label="$t('financeSuppliers.categories.editLabel', { name: item })" class="rounded-md p-1.5 text-neutral-400 hover:bg-neutral-100 hover:text-accent-700" @click="startCategoryEdit(item)"><Pencil class="h-4 w-4" /></button>
                                <button
                                    type="button"
                                    :aria-label="$t('financeSuppliers.categories.deleteLabel', { name: item })"
                                    :title="categoryIsUsed(item) ? $t('financeSuppliers.categories.inUse') : $t('financeSuppliers.categories.delete')"
                                    class="rounded-md p-1.5 text-neutral-400 hover:bg-error-50 hover:text-error-600 disabled:cursor-not-allowed disabled:opacity-35 disabled:hover:bg-transparent disabled:hover:text-neutral-400"
                                    :disabled="categoryIsUsed(item) || categories.length === 1"
                                    @click="destroyCategory(item)"
                                ><Trash2 class="h-4 w-4" /></button>
                            </template>
                        </div>
                    </div>
                </section>
                <div>
                    <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_4054fedf0da3') }}</label>
                    <TextInput v-model="form.phone" class="w-full" placeholder="+355 69 ..." />
                    <p v-if="form.errors.phone" class="mt-1 text-tiny text-error-600">{{ form.errors.phone }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_45fd65af085f') }}</label>
                    <TextInput v-model="form.email" type="email" class="w-full" :placeholder="$t('admin.generated.k_f186225d31d0')" />
                    <p v-if="form.errors.email" class="mt-1 text-tiny text-error-600">{{ form.errors.email }}</p>
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_19a651fb668e') }}</label>
                    <TextInput v-model="form.address" class="w-full" :placeholder="$t('admin.generated.k_cb1ca2db3ba2')" />
                    <p v-if="form.errors.address" class="mt-1 text-tiny text-error-600">{{ form.errors.address }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_f9493eb0e990') }}</label>
                    <TextInput v-model="form.payment_terms_days" type="number" min="0" max="365" class="w-full" />
                    <p v-if="form.errors.payment_terms_days" class="mt-1 text-tiny text-error-600">{{ form.errors.payment_terms_days }}</p>
                </div>
                <label class="flex items-center justify-between gap-3 rounded-lg bg-neutral-50 px-3 py-2.5">
                    <span><b class="block text-body-sm text-primary-900">{{ $t('admin.generated.k_8c8059035f3d') }}</b><small class="text-tiny text-neutral-400">{{ $t('admin.generated.k_f465ce0f2bf9') }}</small></span>
                    <input v-model="form.is_active" type="checkbox" class="h-5 w-5 rounded border-neutral-300 text-accent-600 focus:ring-accent-500">
                </label>
            </div>

            <template #footer>
                <Button v-if="editing !== 'new'" variant="ghost" class="mr-auto text-error-600" :disabled="form.processing" @click="destroySupplier">{{ $t('admin.generated.k_ba91a1000e52') }}</Button>
                <Button variant="ghost" :disabled="form.processing" @click="closeForm">{{ $t('admin.generated.k_5454379d8842') }}</Button>
                <Button :loading="form.processing" :disabled="!form.name.trim()" @click="submit">{{ $t('admin.generated.k_841dfa6da332') }}</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
