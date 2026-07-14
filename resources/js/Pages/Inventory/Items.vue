<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Package, Pencil, Plus, Search } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { money } from '@/Pages/Finance/financeShared.js';
import { translate } from '@/i18n';

const props = defineProps({ items: Array, warehouses: Array, filters: Object, can: Object });
const search = ref(props.filters.search || '');
const status = ref(props.filters.status || 'active');
const editing = ref(null);
const unitLabels = { piece: translate('inventory.units.piece'), kg: translate('inventory.units.kg'), liter: translate('inventory.units.liter'), pack: translate('inventory.units.pack') };
const typeLabels = { product: translate('inventory.types.product'), ingredient: translate('inventory.types.ingredient'), consumable: translate('inventory.types.consumable'), service: translate('inventory.types.service') };

watch(() => props.filters, value => { search.value = value.search || ''; status.value = value.status || 'active'; }, { deep: true });

function filter() {
    router.get(route('inventory.items'), { search: search.value || undefined, status: status.value }, { preserveState: true, preserveScroll: true, replace: true });
}

const defaultWarehouse = computed(() => props.warehouses[0]?.id || null);
const form = useForm({
    name: '', sku: '', barcode: '', category: '', type: 'product', unit: 'piece', average_cost: 0,
    selling_price: null, minimum_stock: 0, initial_quantity: 0, initial_warehouse_id: null, is_active: true,
});

function openNew() {
    form.reset();
    Object.assign(form, { type: 'product', unit: 'piece', average_cost: 0, minimum_stock: 0, initial_quantity: 0, initial_warehouse_id: defaultWarehouse.value, is_active: true });
    form.clearErrors();
    editing.value = 'new';
}

function openEdit(item) {
    Object.assign(form, {
        name: item.name, sku: item.sku, barcode: item.barcode || '', category: item.category || '', type: item.type,
        unit: item.unit, average_cost: item.average_cost, selling_price: item.selling_price, minimum_stock: item.minimum_stock,
        initial_quantity: 0, initial_warehouse_id: defaultWarehouse.value, is_active: item.is_active,
    });
    form.clearErrors();
    editing.value = item;
}

function closeModal() { editing.value = null; form.clearErrors(); }
function submit() {
    const options = { preserveScroll: true, onSuccess: closeModal };
    if (editing.value === 'new') form.post(route('inventory.items.store'), options);
    else form.put(route('inventory.items.update', editing.value.id), options);
}
</script>

<template>
    <AppLayout>
        <div class="mx-auto max-w-[1500px] space-y-6">
            <div>
                <PageHeader :title="$t('inventory.items.title')" :breadcrumbs="[{ label: $t('inventory.title'), href: route('inventory.index') }, { label: $t('inventory.items.title') }]">
                    <template #actions><Button v-if="can.manageInventory" @click="openNew"><Plus class="h-4 w-4" /> {{ $t('inventory.items.new') }}</Button></template>
                </PageHeader>
                <p class="mt-1 text-body-sm text-neutral-500">{{ $t('inventory.items.subtitle') }}</p>
            </div>

            <Card :padding="false">
                <div class="flex flex-col gap-3 border-b border-neutral-200 p-4 sm:flex-row sm:items-center">
                    <div class="relative min-w-0 flex-1"><Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><TextInput v-model="search" class="w-full pl-9" :placeholder="$t('inventory.items.search')" @keyup.enter="filter" /></div>
                    <select v-model="status" class="rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500" @change="filter">
                        <option value="active">{{ $t('inventory.status.active') }}</option><option value="low">{{ $t('inventory.status.low') }}</option><option value="inactive">{{ $t('inventory.status.inactive') }}</option>
                    </select>
                    <Button variant="outline" @click="filter">{{ $t('inventory.actions.filter') }}</Button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[850px] text-left">
                        <thead class="bg-neutral-50 text-tiny uppercase tracking-wide text-neutral-400"><tr><th class="px-5 py-3">{{ $t('inventory.items.item') }}</th><th class="px-5 py-3">{{ $t('inventory.items.type') }}</th><th class="px-5 py-3">{{ $t('inventory.items.warehouseStock') }}</th><th class="px-5 py-3 text-right">{{ $t('inventory.items.stock') }}</th><th class="px-5 py-3 text-right">{{ $t('inventory.items.cost') }}</th><th class="px-5 py-3">{{ $t('inventory.items.status') }}</th><th class="px-5 py-3"></th></tr></thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="item in items" :key="item.id" class="hover:bg-neutral-50/70">
                                <td class="px-5 py-3.5"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-accent-50 text-accent-700"><Package class="h-4 w-4" /></span><div><strong class="block text-body-sm text-primary-900">{{ item.name }}</strong><span class="text-tiny text-neutral-400">{{ item.sku }}<template v-if="item.category"> · {{ item.category }}</template></span></div></div></td>
                                <td class="px-5 py-3.5 text-body-sm text-neutral-600">{{ typeLabels[item.type] }}</td>
                                <td class="px-5 py-3.5"><div class="flex max-w-[240px] flex-wrap gap-1"><span v-for="stock in item.warehouses" :key="stock.id" class="rounded-full bg-neutral-100 px-2 py-1 text-tiny text-neutral-600">{{ stock.name }}: {{ stock.quantity }}</span><span v-if="!item.warehouses.length" class="text-tiny text-neutral-400">—</span></div></td>
                                <td class="px-5 py-3.5 text-right"><strong class="text-body-sm tabular-nums" :class="item.is_low ? 'text-warning-700' : 'text-primary-900'">{{ item.stock }} {{ unitLabels[item.unit] }}</strong><span v-if="item.is_low" class="mt-1 flex items-center justify-end gap-1 text-tiny text-warning-700"><AlertTriangle class="h-3 w-3" /> {{ $t('inventory.items.minimum', { value: item.minimum_stock }) }}</span></td>
                                <td class="px-5 py-3.5 text-right"><strong class="text-body-sm text-primary-900">{{ money(item.average_cost) }}</strong><span class="block text-tiny text-neutral-400">{{ money(item.stock_value) }}</span></td>
                                <td class="px-5 py-3.5"><span class="rounded-full px-2 py-1 text-tiny font-bold" :class="item.is_active ? 'bg-accent-50 text-accent-700' : 'bg-neutral-100 text-neutral-500'">{{ item.is_active ? $t('inventory.status.active') : $t('inventory.status.inactive') }}</span></td>
                                <td class="px-5 py-3.5 text-right"><button v-if="can.manageInventory" class="rounded-md p-2 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" @click="openEdit(item)"><Pencil class="h-4 w-4" /></button></td>
                            </tr>
                            <tr v-if="!items.length"><td colspan="7" class="px-5 py-14 text-center text-body-sm text-neutral-400">{{ $t('inventory.items.empty') }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </div>

        <Modal :show="!!editing" :title="editing === 'new' ? $t('inventory.items.new') : $t('inventory.items.edit')" max-width="2xl" @close="closeModal">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><label class="mb-1 block text-body-sm font-semibold">{{ $t('inventory.items.name') }}</label><TextInput v-model="form.name" class="w-full" /><p v-if="form.errors.name" class="mt-1 text-tiny text-error-600">{{ form.errors.name }}</p></div>
                <div><label class="mb-1 block text-body-sm font-semibold">SKU</label><TextInput v-model="form.sku" class="w-full" /><p v-if="form.errors.sku" class="mt-1 text-tiny text-error-600">{{ form.errors.sku }}</p></div>
                <div><label class="mb-1 block text-body-sm font-semibold">Barcode</label><TextInput v-model="form.barcode" class="w-full" /></div>
                <div><label class="mb-1 block text-body-sm font-semibold">{{ $t('inventory.items.category') }}</label><TextInput v-model="form.category" class="w-full" /></div>
                <div><label class="mb-1 block text-body-sm font-semibold">{{ $t('inventory.items.type') }}</label><select v-model="form.type" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm"><option v-for="(label, key) in typeLabels" :key="key" :value="key">{{ label }}</option></select></div>
                <div><label class="mb-1 block text-body-sm font-semibold">{{ $t('inventory.items.unit') }}</label><select v-model="form.unit" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm"><option v-for="(label, key) in unitLabels" :key="key" :value="key">{{ label }}</option></select></div>
                <div><label class="mb-1 block text-body-sm font-semibold">{{ $t('inventory.items.minStock') }}</label><TextInput v-model="form.minimum_stock" type="number" min="0" step="0.0001" class="w-full" /></div>
                <div><label class="mb-1 block text-body-sm font-semibold">{{ $t('inventory.items.salePrice') }} (€)</label><TextInput v-model="form.selling_price" type="number" min="0" step="0.01" class="w-full" /></div>
                <template v-if="editing === 'new' && form.type !== 'service'">
                    <div class="sm:col-span-2 rounded-lg border border-accent-200 bg-accent-50/50 p-4"><h4 class="text-body-sm font-bold text-primary-900">{{ $t('inventory.items.openingTitle') }}</h4><p class="mt-1 text-tiny text-neutral-500">{{ $t('inventory.items.openingHint') }}</p><div class="mt-3 grid gap-3 sm:grid-cols-3"><div><label class="mb-1 block text-tiny font-semibold">{{ $t('inventory.items.quantity') }}</label><TextInput v-model="form.initial_quantity" type="number" min="0" step="0.0001" class="w-full" /></div><div><label class="mb-1 block text-tiny font-semibold">{{ $t('inventory.items.cost') }} (€)</label><TextInput v-model="form.average_cost" type="number" min="0" step="0.01" class="w-full" /></div><div><label class="mb-1 block text-tiny font-semibold">{{ $t('inventory.warehouses.singular') }}</label><select v-model="form.initial_warehouse_id" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm"><option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option></select></div></div></div>
                </template>
                <label v-if="editing !== 'new'" class="flex items-center gap-2 text-body-sm"><input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-accent-600 focus:ring-accent-500" /> {{ $t('inventory.status.active') }}</label>
            </div>
            <template #footer><Button variant="ghost" @click="closeModal">{{ $t('inventory.actions.cancel') }}</Button><Button :loading="form.processing" :disabled="!form.name || !form.sku" @click="submit">{{ $t('inventory.actions.save') }}</Button></template>
        </Modal>
    </AppLayout>
</template>
