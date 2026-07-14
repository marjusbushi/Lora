<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { ArrowLeftRight, Pencil, Plus, Warehouse } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { money } from '@/Pages/Finance/financeShared.js';
import { translate } from '@/i18n';

const props = defineProps({ warehouses: Array, items: Array, recentTransfers: Array, can: Object });
const editing = ref(null);
const showTransfer = ref(false);
const typeLabels = { central: translate('inventory.warehouseTypes.central'), bar: 'Bar', restaurant: translate('inventory.warehouseTypes.restaurant'), rooms: translate('inventory.warehouseTypes.rooms'), housekeeping: translate('inventory.warehouseTypes.housekeeping'), other: translate('inventory.warehouseTypes.other') };
const unitLabels = { piece: translate('inventory.units.piece'), kg: translate('inventory.units.kg'), liter: translate('inventory.units.liter'), pack: translate('inventory.units.pack') };

const form = useForm({ name: '', type: 'central', description: '', is_default: false, is_active: true });
const transferForm = useForm({ inventory_item_id: null, from_warehouse_id: null, to_warehouse_id: null, quantity: null, notes: '' });
const activeWarehouses = computed(() => props.warehouses.filter(warehouse => warehouse.is_active));
const selectedTransferItem = computed(() => props.items.find(item => item.id === Number(transferForm.inventory_item_id)));
const availableStock = computed(() => Number(selectedTransferItem.value?.warehouse_stock?.[String(transferForm.from_warehouse_id)] || 0));

function openNew() { form.reset(); Object.assign(form, { type: 'central', is_default: !props.warehouses.length, is_active: true }); form.clearErrors(); editing.value = 'new'; }
function openEdit(warehouse) { Object.assign(form, { name: warehouse.name, type: warehouse.type, description: warehouse.description || '', is_default: warehouse.is_default, is_active: warehouse.is_active }); form.clearErrors(); editing.value = warehouse; }
function closeWarehouse() { editing.value = null; form.clearErrors(); }
function saveWarehouse() { const options = { preserveScroll: true, onSuccess: closeWarehouse }; editing.value === 'new' ? form.post(route('inventory.warehouses.store'), options) : form.put(route('inventory.warehouses.update', editing.value.id), options); }
function openTransfer() { transferForm.reset(); Object.assign(transferForm, { inventory_item_id: props.items[0]?.id || null, from_warehouse_id: activeWarehouses.value[0]?.id || null, to_warehouse_id: activeWarehouses.value[1]?.id || null }); transferForm.clearErrors(); showTransfer.value = true; }
function saveTransfer() { transferForm.post(route('inventory.transfers.store'), { preserveScroll: true, onSuccess: () => { showTransfer.value = false; transferForm.reset(); } }); }
function formatDate(value) { return value ? new Intl.DateTimeFormat('sq-AL', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : '—'; }
</script>

<template>
    <AppLayout>
        <div class="mx-auto max-w-[1500px] space-y-6">
            <div>
                <PageHeader :title="$t('inventory.warehouses.title')" :breadcrumbs="[{ label: $t('inventory.title'), href: route('inventory.index') }, { label: $t('inventory.warehouses.title') }]">
                    <template #actions><Button v-if="can.manageInventory && activeWarehouses.length > 1 && items.length" variant="outline" @click="openTransfer"><ArrowLeftRight class="h-4 w-4" /> {{ $t('inventory.actions.transfer') }}</Button><Button v-if="can.manageInventory" @click="openNew"><Plus class="h-4 w-4" /> {{ $t('inventory.warehouses.new') }}</Button></template>
                </PageHeader>
                <p class="mt-1 text-body-sm text-neutral-500">{{ $t('inventory.warehouses.subtitle') }}</p>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Card v-for="warehouse in warehouses" :key="warehouse.id">
                    <div class="flex items-start justify-between gap-3"><span class="grid h-10 w-10 place-items-center rounded-lg bg-accent-50 text-accent-700"><Warehouse class="h-5 w-5" /></span><div class="flex items-center gap-2"><span v-if="warehouse.is_default" class="rounded-full bg-accent-50 px-2 py-1 text-tiny font-bold text-accent-700">{{ $t('inventory.warehouses.default') }}</span><button v-if="can.manageInventory" class="rounded-md p-2 text-neutral-400 hover:bg-neutral-100" @click="openEdit(warehouse)"><Pencil class="h-4 w-4" /></button></div></div>
                    <strong class="mt-4 block text-label text-primary-900">{{ warehouse.name }}</strong><p class="mt-1 text-tiny text-neutral-400">{{ typeLabels[warehouse.type] }}<template v-if="warehouse.description"> · {{ warehouse.description }}</template></p>
                    <div class="mt-4 grid grid-cols-3 divide-x divide-neutral-100 rounded-lg bg-neutral-50 py-3 text-center"><div><strong class="block text-body-sm text-primary-900">{{ warehouse.items_count }}</strong><span class="text-tiny text-neutral-400">{{ $t('inventory.warehouses.items') }}</span></div><div><strong class="block text-body-sm text-primary-900">{{ money(warehouse.stock_value) }}</strong><span class="text-tiny text-neutral-400">{{ $t('inventory.warehouses.value') }}</span></div><div><strong class="block text-body-sm" :class="warehouse.low_stock_count ? 'text-warning-700' : 'text-primary-900'">{{ warehouse.low_stock_count }}</strong><span class="text-tiny text-neutral-400">{{ $t('inventory.warehouses.lowStock') }}</span></div></div>
                </Card>
            </div>

            <Card :padding="false">
                <template #header><div><h2 class="text-label font-bold text-primary-900">{{ $t('inventory.transfers.title') }}</h2><p class="mt-0.5 text-tiny text-neutral-400">{{ $t('inventory.transfers.subtitle') }}</p></div></template>
                <div v-if="recentTransfers.length" class="divide-y divide-neutral-100">
                    <div v-for="transfer in recentTransfers" :key="transfer.id" class="flex flex-col gap-2 px-5 py-3.5 sm:flex-row sm:items-center"><strong class="min-w-0 flex-1 text-body-sm text-primary-900">{{ transfer.item }}</strong><span class="text-body-sm text-neutral-500">{{ transfer.from }} → {{ transfer.to }}</span><strong class="sm:w-28 sm:text-right text-body-sm text-primary-900">{{ transfer.quantity }} {{ unitLabels[transfer.unit] }}</strong><span class="text-tiny text-neutral-400 sm:w-36 sm:text-right">{{ formatDate(transfer.transferred_at) }}</span></div>
                </div>
                <div v-else class="px-5 py-12 text-center text-body-sm text-neutral-400">{{ $t('inventory.transfers.empty') }}</div>
            </Card>
        </div>

        <Modal :show="!!editing" :title="editing === 'new' ? $t('inventory.warehouses.new') : $t('inventory.warehouses.edit')" @close="closeWarehouse">
            <div class="space-y-4"><div><label class="mb-1 block text-body-sm font-semibold">{{ $t('inventory.warehouses.name') }}</label><TextInput v-model="form.name" class="w-full" /><p v-if="form.errors.name" class="mt-1 text-tiny text-error-600">{{ form.errors.name }}</p></div><div><label class="mb-1 block text-body-sm font-semibold">{{ $t('inventory.warehouses.type') }}</label><select v-model="form.type" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm"><option v-for="(label, key) in typeLabels" :key="key" :value="key">{{ label }}</option></select></div><div><label class="mb-1 block text-body-sm font-semibold">{{ $t('inventory.warehouses.description') }}</label><textarea v-model="form.description" rows="3" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm" /></div><div class="flex flex-col gap-2"><label class="flex items-center gap-2 text-body-sm"><input v-model="form.is_default" type="checkbox" class="rounded border-neutral-300 text-accent-600" /> {{ $t('inventory.warehouses.makeDefault') }}</label><label v-if="editing !== 'new'" class="flex items-center gap-2 text-body-sm"><input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-accent-600" /> {{ $t('inventory.status.active') }}</label></div></div>
            <template #footer><Button variant="ghost" @click="closeWarehouse">{{ $t('inventory.actions.cancel') }}</Button><Button :loading="form.processing" :disabled="!form.name" @click="saveWarehouse">{{ $t('inventory.actions.save') }}</Button></template>
        </Modal>

        <Modal :show="showTransfer" :title="$t('inventory.transfers.new')" max-width="xl" @close="showTransfer = false">
            <div class="grid gap-4 sm:grid-cols-2"><div class="sm:col-span-2"><label class="mb-1 block text-body-sm font-semibold">{{ $t('inventory.items.item') }}</label><select v-model="transferForm.inventory_item_id" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm"><option v-for="item in items" :key="item.id" :value="item.id">{{ item.name }} · {{ item.stock }} {{ unitLabels[item.unit] }}</option></select></div><div><label class="mb-1 block text-body-sm font-semibold">{{ $t('inventory.transfers.from') }}</label><select v-model="transferForm.from_warehouse_id" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm"><option v-for="warehouse in activeWarehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option></select><p class="mt-1 text-tiny text-neutral-400">{{ $t('inventory.availableLabel', { value: `${availableStock} ${unitLabels[selectedTransferItem?.unit]}` }) }}</p></div><div><label class="mb-1 block text-body-sm font-semibold">{{ $t('inventory.transfers.to') }}</label><select v-model="transferForm.to_warehouse_id" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm"><option v-for="warehouse in activeWarehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option></select></div><div><label class="mb-1 block text-body-sm font-semibold">{{ $t('inventory.items.quantity') }}</label><TextInput v-model="transferForm.quantity" type="number" min="0.0001" :max="availableStock" step="0.0001" class="w-full" /><p v-if="transferForm.errors.quantity" class="mt-1 text-tiny text-error-600">{{ transferForm.errors.quantity }}</p></div><div><label class="mb-1 block text-body-sm font-semibold">{{ $t('inventory.transfers.notes') }}</label><TextInput v-model="transferForm.notes" class="w-full" /></div></div>
            <template #footer><Button variant="ghost" @click="showTransfer = false">{{ $t('inventory.actions.cancel') }}</Button><Button :loading="transferForm.processing" :disabled="!transferForm.inventory_item_id || !transferForm.quantity || Number(transferForm.quantity) > availableStock || transferForm.from_warehouse_id === transferForm.to_warehouse_id" @click="saveTransfer">{{ $t('inventory.actions.transfer') }}</Button></template>
        </Modal>
    </AppLayout>
</template>
