<script setup>
import { computed, defineAsyncComponent, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle2,
    FilePenLine,
    Info,
    PackagePlus,
    Plus,
    Sparkles,
    Trash2,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { money } from './financeShared.js';

const props = defineProps({
    suppliers: Array,
    categories: Array,
    inventoryItems: Array,
    warehouses: Array,
    baseCurrency: String,
    fxRate: Number,
    currencies: { type: Array, default: () => ['EUR', 'ALL'] },
    currencyRates: { type: Object, default: () => ({}) },
    can: Object,
    aiConfigured: Boolean,
    openAiImport: Boolean,
    bill: { type: Object, default: null },
    readOnly: { type: Boolean, default: false },
});
const { t } = useI18n();
const BillAiImportModal = defineAsyncComponent(() => import('./Components/BillAiImportModal.vue'));
const isEditing = computed(() => Boolean(props.bill?.id));
const stockLocked = computed(() => Boolean(props.bill?.stock_locked));
const formLocked = computed(() => props.readOnly || stockLocked.value);
const pageTitleKey = computed(() => props.readOnly ? 'admin.finance.billCreate.viewTitle' : (isEditing.value ? 'admin.finance.billCreate.editTitle' : 'admin.finance.billCreate.title'));
const breadcrumbKey = computed(() => props.readOnly ? 'admin.finance.billCreate.breadcrumbView' : (isEditing.value ? 'admin.finance.billCreate.breadcrumbEdit' : 'admin.finance.billCreate.breadcrumbNew'));
const statusKey = computed(() => props.readOnly ? 'admin.finance.billCreate.viewStatus' : (isEditing.value ? 'admin.finance.billCreate.editingStatus' : 'admin.finance.billCreate.newStatus'));
const showAiImport = ref(!props.bill && props.openAiImport);
const importSummary = ref(null);

function localDateString(date = new Date()) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

const defaultWarehouseId = computed(() => props.warehouses.find((warehouse) => warehouse.is_default)?.id || props.warehouses[0]?.id || null);

function emptyLine() {
    return {
        inventory_item_id: null,
        warehouse_id: defaultWarehouseId.value,
        quantity: 1,
        unit_cost: null,
        new_item: null,
        suggested_name: '',
    };
}

const form = useForm({
    supplier_id: props.bill?.supplier_id ?? null,
    number: props.bill?.number || '',
    category: props.bill?.category || props.categories[0] || t('admin.finance.billCreate.other'),
    issue_date: props.bill?.issue_date || localDateString(),
    due_date: props.bill?.due_date || null,
    currency: props.bill?.currency || props.baseCurrency,
    fx_rate: props.bill?.fx_rate ?? null,
    total: Number(props.bill?.total || 0),
    notes: props.bill?.notes || '',
    receive_stock: !props.bill,
    items: props.bill
        ? (props.bill.items || []).map((line) => ({ ...line, new_item: null, suggested_name: '' }))
        : (props.inventoryItems.length ? [emptyLine()] : []),
});

const selectedSupplier = computed(() => props.suppliers.find((supplier) => supplier.id === Number(form.supplier_id)));
const lineTotal = (line) => Number(line.quantity || 0) * Number(line.unit_cost || 0);
const invoiceTotal = computed(() => form.items.reduce((total, line) => total + lineTotal(line), 0));
const effectiveTotal = computed(() => form.items.length ? invoiceTotal.value : Number(form.total || 0));
const totalBase = computed(() => {
    if (form.currency === props.baseCurrency) return effectiveTotal.value;
    const rate = Number(form.fx_rate || 0);
    return rate > 0 ? effectiveTotal.value / rate : 0;
});
const stockableLines = computed(() => form.items.filter((line) => lineItemType(line) !== 'service' && (line.inventory_item_id || line.new_item?.name)).length);
const errorMessages = computed(() => [...new Set(Object.values(form.errors))]);

const canSubmit = computed(() => {
    if (!form.supplier_id || !form.issue_date || effectiveTotal.value <= 0) return false;
    if (form.currency !== props.baseCurrency && Number(form.fx_rate) <= 0) return false;
    if (!form.items.length) return true;

    return form.items.every((line) => {
        const item = selectedItem(line);
        const validItem = item || (props.can.manageInventory && line.new_item?.name?.trim());
        return validItem
            && Number(line.quantity) > 0
            && Number(line.unit_cost) >= 0
            && (lineItemType(line) === 'service' || Boolean(line.warehouse_id));
    });
});

watch(invoiceTotal, (total) => {
    if (form.items.length) form.total = Number(total.toFixed(2));
}, { immediate: true });

watch(() => form.supplier_id, () => {
    const supplier = selectedSupplier.value;
    if (!supplier) return;
    if (supplier.category && props.categories.includes(supplier.category)) form.category = supplier.category;
    applyPaymentTerms();
});

watch(() => form.issue_date, applyPaymentTerms);
watch(() => form.currency, (currency) => {
    form.fx_rate = currency === props.baseCurrency ? null : (props.currencyRates[currency] || null);
});

function applyPaymentTerms() {
    const supplier = selectedSupplier.value;
    if (!supplier || !form.issue_date) return;

    const date = new Date(`${form.issue_date}T12:00:00`);
    date.setDate(date.getDate() + Number(supplier.payment_terms_days || 0));
    form.due_date = localDateString(date);
}

function selectedItem(line) {
    return props.inventoryItems.find((item) => item.id === Number(line.inventory_item_id));
}

function lineItemType(line) {
    return selectedItem(line)?.type || line.new_item?.type || 'product';
}

function lineSelectionValue(line) {
    if (line.inventory_item_id) return String(line.inventory_item_id);
    if (line.new_item) return '__new__';
    return '';
}

function changeLineItem(line, event) {
    const value = event.target.value;
    if (value === '__new__') {
        line.inventory_item_id = null;
        line.new_item ||= {
            name: line.suggested_name || '',
            sku: '',
            barcode: '',
            category: form.category,
            type: 'product',
            unit: 'piece',
        };
        line.warehouse_id ||= defaultWarehouseId.value;
        return;
    }

    line.new_item = null;
    line.inventory_item_id = value ? Number(value) : null;
    applyItemDefaults(line);
}

function applyNewItemType(line) {
    line.warehouse_id = line.new_item?.type === 'service' ? null : (line.warehouse_id || defaultWarehouseId.value);
}

function applyItemDefaults(line) {
    const item = selectedItem(line);
    if (!item) return;
    if (line.unit_cost === null || line.unit_cost === '') line.unit_cost = Number(item.average_cost || 0);
    line.warehouse_id = item.type === 'service' ? null : (line.warehouse_id || defaultWarehouseId.value);
}

function addLine() {
    if (!formLocked.value && form.items.length < 50) form.items.push(emptyLine());
}

function removeLine(index) {
    if (!formLocked.value) form.items.splice(index, 1);
}

async function applyAiImport(result) {
    form.supplier_id = result.supplier?.match?.id || null;
    form.number = result.invoice?.number || '';
    form.category = props.categories.includes(result.invoice?.category) ? result.invoice.category : (props.categories[0] || form.category);
    form.issue_date = result.invoice?.issue_date || localDateString();
    form.currency = props.currencies.includes(result.invoice?.currency) ? result.invoice.currency : props.baseCurrency;
    form.items = (result.items || []).map((item) => ({
        inventory_item_id: item.match?.id || null,
        warehouse_id: item.item_type === 'service' ? null : defaultWarehouseId.value,
        quantity: item.quantity,
        unit_cost: item.unit_cost,
        suggested_name: item.description,
        new_item: item.match ? null : {
            name: item.description,
            sku: item.sku || '',
            barcode: item.barcode || '',
            category: item.category || form.category,
            type: item.item_type || 'product',
            unit: item.unit || 'piece',
        },
    }));
    await nextTick();
    form.due_date = result.invoice?.due_date || form.due_date;
    importSummary.value = result.summary;
    showAiImport.value = false;
    form.clearErrors();
}

function submit() {
    if (props.readOnly) return;

    if (isEditing.value) {
        form.put(route('finance.bills.update', props.bill.id), { preserveScroll: true });
        return;
    }

    form.post(route('finance.bills.store'), { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <div class="mx-auto max-w-[1400px] space-y-5 pb-8">
            <PageHeader :title="$t(pageTitleKey)" :breadcrumbs="[{ label: $t('admin.sidebar.finance') }, { label: $t('admin.sidebar.bills'), href: route('finance.bills') }, { label: $t(breadcrumbKey) }]">
                <template #actions>
                    <Link :href="route('finance.bills')" class="inline-flex items-center gap-2 rounded-md border border-neutral-200 bg-white px-4 py-2 text-body-sm font-medium text-neutral-700 no-underline hover:bg-neutral-50">
                        <ArrowLeft class="h-4 w-4" /> {{ $t(readOnly ? 'admin.finance.billCreate.back' : 'admin.finance.billCreate.cancel') }}
                    </Link>
                    <Button v-if="!formLocked" variant="outline" @click="showAiImport = true">
                        <Sparkles class="h-4 w-4" /> {{ $t('admin.finance.billAiImport.button') }}
                    </Button>
                    <Button v-if="!readOnly" :loading="form.processing" :disabled="!canSubmit" @click="submit">
                        <CheckCircle2 class="h-4 w-4" /> {{ $t(isEditing ? 'admin.finance.billCreate.saveChanges' : 'admin.finance.billCreate.save') }}
                    </Button>
                </template>
            </PageHeader>

            <div v-if="errorMessages.length" class="rounded-lg border border-error-200 bg-error-50 px-4 py-3 text-body-sm text-error-700">
                <strong class="block">{{ $t('admin.finance.billCreate.validationTitle') }}</strong>
                <ul class="mt-1 list-disc pl-5">
                    <li v-for="message in errorMessages" :key="message">{{ message }}</li>
                </ul>
            </div>

            <div v-if="stockLocked && !readOnly" class="flex items-start gap-2 rounded-lg border border-warning-200 bg-warning-50 px-4 py-3 text-body-sm text-warning-800">
                <Info class="mt-0.5 h-4 w-4 shrink-0" />
                <span><strong class="block">{{ $t('admin.finance.billCreate.stockLockedTitle') }}</strong><span class="mt-0.5 block text-tiny">{{ $t('admin.finance.billCreate.stockLockedBody') }}</span></span>
            </div>

            <div v-if="importSummary" class="flex flex-col gap-3 rounded-lg border border-accent-200 bg-accent-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <span class="flex items-start gap-2 text-body-sm text-accent-800"><Sparkles class="mt-0.5 h-4 w-4 shrink-0" /><span><b>{{ $t('admin.finance.billAiImport.importedTitle') }}</b><span class="mt-0.5 block text-tiny">{{ $t('admin.finance.billAiImport.importedBody') }}</span></span></span>
                <span class="shrink-0 text-tiny font-bold text-accent-700">{{ importSummary.matched_items }} {{ $t('admin.finance.billAiImport.existing') }} · {{ importSummary.new_items }} {{ $t('admin.finance.billAiImport.new') }}</span>
            </div>

            <Card :padding="false" class="overflow-hidden">
                <section class="border-b border-neutral-200 px-5 py-5 sm:px-7 sm:py-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-tiny font-bold uppercase tracking-[0.16em] text-neutral-400">{{ $t('admin.finance.billCreate.purchaseDocument') }}</p>
                            <h1 class="mt-1 text-h2 font-bold text-primary-900">{{ $t('admin.finance.billCreate.purchaseInvoice') }}</h1>
                        </div>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-info-50 px-3 py-1.5 text-tiny font-bold text-info-700">
                            <FilePenLine class="h-3.5 w-3.5" /> {{ $t(statusKey) }}
                        </span>
                    </div>

                    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.25fr),minmax(460px,.75fr)]">
                        <div>
                            <div class="mb-1 flex items-center justify-between gap-3">
                                <label class="text-body-sm font-semibold text-primary-900">{{ $t('admin.finance.billCreate.supplier') }}</label>
                                <Link v-if="!readOnly && can.manageSuppliers" :href="route('finance.suppliers')" class="inline-flex items-center gap-1 text-tiny font-bold text-accent-700 no-underline hover:text-accent-800">
                                    <Plus class="h-3.5 w-3.5" /> {{ $t('admin.finance.billCreate.newSupplier') }}
                                </Link>
                            </div>
                            <select v-model="form.supplier_id" :disabled="readOnly" class="w-full rounded-lg border-neutral-200 px-3 py-2.5 text-body-sm disabled:bg-neutral-100 disabled:text-neutral-600 focus:border-accent-500 focus:ring-accent-500">
                                <option :value="null" disabled>{{ $t('admin.finance.billCreate.selectSupplier') }}</option>
                                <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}<template v-if="supplier.nipt"> · {{ supplier.nipt }}</template></option>
                            </select>
                            <p v-if="selectedSupplier" class="mt-2 text-tiny text-neutral-400">
                                <template v-if="selectedSupplier.nipt">NIPT {{ selectedSupplier.nipt }} · </template>{{ $t('admin.finance.billCreate.standardTerms', { days: selectedSupplier.payment_terms_days || 0 }) }}
                            </p>
                            <p v-else-if="!suppliers.length" class="mt-2 text-tiny font-semibold text-warning-700">{{ $t('admin.finance.billCreate.missingSupplier') }}</p>
                            <p v-if="form.errors.supplier_id" class="mt-1 text-tiny text-error-600">{{ form.errors.supplier_id }}</p>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 flex items-center justify-between text-body-sm font-semibold text-primary-900"><span>{{ $t('admin.finance.billCreate.invoiceNumberShort') }}</span><small v-if="!readOnly" class="font-normal text-neutral-400">{{ $t('admin.finance.billCreate.autoNumberHint') }}</small></label>
                                <TextInput v-model="form.number" :disabled="readOnly" class="w-full" :placeholder="$t('admin.finance.billCreate.numberPlaceholder')" />
                                <p v-if="form.errors.number" class="mt-1 text-tiny text-error-600">{{ form.errors.number }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.finance.billCreate.issueDate') }}</label>
                                <TextInput v-model="form.issue_date" :disabled="readOnly" type="date" class="w-full" />
                                <p v-if="form.errors.issue_date" class="mt-1 text-tiny text-error-600">{{ form.errors.issue_date }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.finance.billCreate.dueDate') }}</label>
                                <TextInput v-model="form.due_date" :disabled="readOnly" type="date" class="w-full" />
                                <p v-if="form.errors.due_date" class="mt-1 text-tiny text-error-600">{{ form.errors.due_date }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.finance.billCreate.currency') }}</label>
                                <select v-model="form.currency" :disabled="formLocked" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm disabled:bg-neutral-100 disabled:text-neutral-500 focus:border-accent-500 focus:ring-accent-500">
                                    <option v-for="currency in currencies" :key="currency" :value="currency">{{ currency }}</option>
                                </select>
                            </div>
                            <div :class="form.currency === baseCurrency ? 'sm:col-span-2' : ''">
                                <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.finance.billCreate.category') }}</label>
                                <select v-model="form.category" :disabled="readOnly" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm disabled:bg-neutral-100 disabled:text-neutral-500 focus:border-accent-500 focus:ring-accent-500">
                                    <option v-for="category in categories" :key="category" :value="category">{{ category }}</option>
                                </select>
                            </div>
                            <div v-if="form.currency !== baseCurrency">
                                <label class="mb-1 flex items-center justify-between text-body-sm font-semibold text-primary-900"><span>{{ $t('admin.finance.billCreate.exchangeRate') }}</span><small class="font-normal text-neutral-400">{{ $t('admin.finance.billCreate.rateHelp', { base: baseCurrency, currency: form.currency }) }}</small></label>
                                <TextInput v-model="form.fx_rate" :disabled="formLocked" type="number" min="0.000001" step="0.000001" class="w-full" :placeholder="$t('admin.finance.billCreate.ratePlaceholder')" />
                                <p v-if="form.errors.fx_rate" class="mt-1 text-tiny text-error-600">{{ form.errors.fx_rate }}</p>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="border-b border-neutral-200">
                    <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-7">
                        <div>
                            <h2 class="text-label font-bold text-primary-900">{{ $t('admin.finance.billCreate.linesTitle') }}</h2>
                            <p class="mt-0.5 text-tiny text-neutral-400">{{ $t('admin.finance.billCreate.linesSubtitle') }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Link v-if="!formLocked && can.manageInventory && !inventoryItems.length" :href="route('inventory.items')" class="inline-flex items-center gap-1.5 rounded-md border border-neutral-200 bg-white px-3 py-1.5 text-body-sm font-medium text-neutral-700 no-underline hover:bg-neutral-50">
                                {{ $t('admin.finance.billCreate.newItem') }}
                            </Link>
                            <Button v-if="!formLocked" variant="outline" size="sm" :disabled="form.items.length >= 50" @click="addLine"><Plus class="h-4 w-4" /> {{ $t('admin.finance.billCreate.addLine') }}</Button>
                        </div>
                    </div>

                    <div v-if="form.items.length" class="overflow-x-auto">
                        <table class="w-full min-w-[980px] border-collapse">
                            <thead class="border-y border-neutral-100 bg-neutral-50 text-left text-tiny font-bold uppercase tracking-wide text-neutral-400">
                                <tr>
                                    <th class="min-w-[270px] px-7 py-3">{{ $t('admin.finance.billCreate.itemService') }}</th>
                                    <th class="min-w-[190px] px-3 py-3">{{ $t('admin.finance.billCreate.warehouse') }}</th>
                                    <th class="w-28 px-3 py-3">{{ $t('admin.finance.billCreate.quantity') }}</th>
                                    <th class="w-36 px-3 py-3">{{ $t('admin.finance.billCreate.unitCost') }}</th>
                                    <th class="w-36 px-3 py-3 text-right">{{ $t('admin.finance.billCreate.total') }}</th>
                                    <th class="w-16 px-3 py-3"><span class="sr-only">{{ $t('admin.finance.billCreate.actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-100">
                                <tr v-for="(line, index) in form.items" :key="index" class="align-top">
                                    <td class="px-7 py-3.5">
                                        <select :value="lineSelectionValue(line)" :disabled="formLocked" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm disabled:bg-neutral-100 disabled:text-neutral-500 focus:border-accent-500 focus:ring-accent-500" @change="changeLineItem(line, $event)">
                                            <option value="" disabled>{{ $t('admin.finance.billCreate.selectItem') }}</option>
                                            <option v-for="item in inventoryItems" :key="item.id" :value="String(item.id)">{{ item.name }} · {{ item.sku }}</option>
                                            <option v-if="can.manageInventory" value="__new__">＋ {{ $t('admin.finance.billAiImport.createNewItem') }}</option>
                                        </select>
                                        <p v-if="selectedItem(line)" class="mt-1 text-tiny text-neutral-400">{{ selectedItem(line).type === 'service' ? $t('admin.finance.billCreate.service') : $t('admin.finance.billCreate.stockItem') }} · {{ selectedItem(line).unit }}</p>
                                        <div v-else-if="line.new_item" class="mt-2 space-y-2 rounded-lg border border-warning-200 bg-warning-50/60 p-2.5">
                                            <div class="flex items-center justify-between gap-2"><span class="text-tiny font-bold text-warning-800">{{ $t('admin.finance.billAiImport.newItemLabel') }}</span><span class="text-tiny text-warning-700">{{ $t('admin.finance.billAiImport.createdAfterSave') }}</span></div>
                                            <TextInput v-model="line.new_item.name" class="w-full" :placeholder="$t('admin.finance.billAiImport.itemName')" />
                                            <div class="grid grid-cols-2 gap-2">
                                                <TextInput v-model="line.new_item.sku" class="w-full" :placeholder="$t('admin.finance.billAiImport.skuOptional')" />
                                                <select v-model="line.new_item.unit" class="w-full rounded-lg border-neutral-200 px-2 py-2 text-tiny focus:border-accent-500 focus:ring-accent-500">
                                                    <option value="piece">{{ $t('admin.finance.billAiImport.units.piece') }}</option>
                                                    <option value="kg">{{ $t('admin.finance.billAiImport.units.kg') }}</option>
                                                    <option value="liter">{{ $t('admin.finance.billAiImport.units.liter') }}</option>
                                                    <option value="pack">{{ $t('admin.finance.billAiImport.units.pack') }}</option>
                                                </select>
                                            </div>
                                            <select v-model="line.new_item.type" class="w-full rounded-lg border-neutral-200 px-2 py-2 text-tiny focus:border-accent-500 focus:ring-accent-500" @change="applyNewItemType(line)">
                                                <option value="product">{{ $t('admin.finance.billAiImport.types.product') }}</option>
                                                <option value="ingredient">{{ $t('admin.finance.billAiImport.types.ingredient') }}</option>
                                                <option value="consumable">{{ $t('admin.finance.billAiImport.types.consumable') }}</option>
                                                <option value="service">{{ $t('admin.finance.billAiImport.types.service') }}</option>
                                            </select>
                                        </div>
                                        <p v-if="form.errors[`items.${index}.inventory_item_id`]" class="mt-1 text-tiny text-error-600">{{ form.errors[`items.${index}.inventory_item_id`] }}</p>
                                        <p v-if="form.errors[`items.${index}.new_item.name`]" class="mt-1 text-tiny text-error-600">{{ form.errors[`items.${index}.new_item.name`] }}</p>
                                    </td>
                                    <td class="px-3 py-3.5">
                                        <select v-if="lineItemType(line) !== 'service'" v-model="line.warehouse_id" :disabled="formLocked" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm disabled:bg-neutral-100 disabled:text-neutral-500 focus:border-accent-500 focus:ring-accent-500">
                                            <option :value="null" disabled>{{ $t('admin.finance.billCreate.selectWarehouse') }}</option>
                                            <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                                        </select>
                                        <span v-else class="inline-flex rounded-full bg-neutral-100 px-2.5 py-1.5 text-tiny font-semibold text-neutral-500">{{ $t('admin.finance.billCreate.noStockImpact') }}</span>
                                        <p v-if="form.errors[`items.${index}.warehouse_id`]" class="mt-1 text-tiny text-error-600">{{ form.errors[`items.${index}.warehouse_id`] }}</p>
                                    </td>
                                    <td class="px-3 py-3.5">
                                        <TextInput v-model="line.quantity" :disabled="formLocked" type="number" min="0.0001" step="0.0001" class="w-full" />
                                        <p v-if="form.errors[`items.${index}.quantity`]" class="mt-1 text-tiny text-error-600">{{ form.errors[`items.${index}.quantity`] }}</p>
                                    </td>
                                    <td class="px-3 py-3.5">
                                        <TextInput v-model="line.unit_cost" :disabled="formLocked" type="number" min="0" step="0.01" class="w-full" />
                                        <p v-if="form.errors[`items.${index}.unit_cost`]" class="mt-1 text-tiny text-error-600">{{ form.errors[`items.${index}.unit_cost`] }}</p>
                                    </td>
                                    <td class="px-3 py-4 text-right text-body-sm font-bold tabular-nums text-primary-900">{{ money(lineTotal(line), form.currency) }}</td>
                                    <td class="px-3 py-3.5 text-right">
                                        <button v-if="!formLocked" type="button" class="rounded-md p-2 text-neutral-400 hover:bg-error-50 hover:text-error-600" :aria-label="$t('admin.finance.billCreate.removeLine')" @click="removeLine(index)"><Trash2 class="h-4 w-4" /></button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-else class="px-5 py-10 text-center sm:px-7">
                        <span class="mx-auto grid h-11 w-11 place-items-center rounded-full bg-neutral-100 text-neutral-500"><PackagePlus class="h-5 w-5" /></span>
                        <strong class="mt-3 block text-body-sm text-primary-900">{{ $t('admin.finance.billCreate.emptyTitle') }}</strong>
                        <p class="mt-1 text-tiny text-neutral-400">{{ $t(isEditing ? 'admin.finance.billCreate.legacyTotalHint' : 'admin.finance.billCreate.emptyBody') }}</p>
                        <div v-if="isEditing" class="mx-auto mt-4 max-w-xs text-left">
                            <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ $t('admin.finance.billCreate.manualTotal') }}</label>
                            <TextInput v-model="form.total" :disabled="formLocked" type="number" min="0.01" step="0.01" class="w-full" />
                            <p v-if="form.errors.total" class="mt-1 text-tiny text-error-600">{{ form.errors.total }}</p>
                        </div>
                        <Button v-if="!formLocked" class="mt-4" variant="outline" size="sm" @click="addLine"><Plus class="h-4 w-4" /> {{ $t('admin.finance.billCreate.addLine') }}</Button>
                    </div>
                </section>

                <section class="px-5 py-5 sm:px-7 sm:py-6">
                    <div class="grid items-start gap-8 lg:grid-cols-[minmax(0,1fr),360px]">
                        <div>
                            <label class="mb-1 flex items-center justify-between text-body-sm font-semibold text-primary-900"><span>{{ $t('admin.finance.billCreate.notes') }}</span><small class="font-normal text-neutral-400">{{ $t('admin.finance.billCreate.optionalPlural') }}</small></label>
                            <textarea v-model="form.notes" :disabled="readOnly" rows="3" maxlength="500" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm disabled:bg-neutral-100 disabled:text-neutral-600 placeholder:text-neutral-400 focus:border-accent-500 focus:ring-accent-500" :placeholder="$t('admin.finance.billCreate.notesPlaceholder')" />
                            <div class="mt-1 flex justify-between text-tiny text-neutral-400"><span>{{ form.errors.notes }}</span><span>{{ form.notes.length }}/500</span></div>

                            <label v-if="!formLocked" class="mt-5 flex cursor-pointer items-start gap-3 border-t border-neutral-100 pt-4">
                                <input v-model="form.receive_stock" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-accent-600 focus:ring-accent-500" />
                                <span><strong class="block text-body-sm text-primary-900">{{ $t('admin.finance.billCreate.receiveStock') }}</strong><small class="mt-0.5 block text-tiny text-neutral-400">{{ $t('admin.finance.billCreate.receiveStockHint', { count: stockableLines }) }}</small></span>
                            </label>
                        </div>

                        <div class="space-y-3">
                            <div class="flex items-center justify-between gap-4 text-body-sm"><span class="text-neutral-500">{{ $t('admin.finance.billCreate.subtotal') }}</span><strong class="tabular-nums text-primary-900">{{ money(effectiveTotal, form.currency) }}</strong></div>
                            <div class="flex items-center justify-between gap-4 border-t border-neutral-200 pt-3"><span class="font-semibold text-primary-900">{{ $t('admin.finance.billCreate.invoiceTotal') }}</span><strong class="text-h2 tabular-nums text-accent-700">{{ money(effectiveTotal, form.currency) }}</strong></div>
                            <div v-if="form.currency !== baseCurrency" class="flex items-center justify-between gap-4 text-tiny text-neutral-400"><span>{{ $t('admin.finance.billCreate.payableBase', { currency: baseCurrency }) }}</span><strong class="tabular-nums text-neutral-600">{{ money(totalBase, baseCurrency) }}</strong></div>
                            <p class="flex items-start gap-2 pt-2 text-tiny leading-relaxed text-neutral-400"><Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />{{ $t('admin.finance.billCreate.liabilityHint') }}</p>
                        </div>
                    </div>
                </section>
            </Card>
        </div>

        <BillAiImportModal
            v-if="!formLocked"
            :show="showAiImport"
            :ai-configured="aiConfigured"
            :can-create-items="can.manageInventory"
            :base-currency="baseCurrency"
            @close="showAiImport = false"
            @apply="applyAiImport"
        />
    </AppLayout>
</template>
