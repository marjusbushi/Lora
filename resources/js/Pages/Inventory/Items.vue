<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { useCurrency } from '@/composables/useCurrency';
import { AlertTriangle, BedDouble, FolderTree, ImagePlus, Package, PackageMinus, Pencil, Plus, Search, ShoppingBasket, Trash2 } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { money } from '@/Pages/Finance/financeShared.js';
import { translate } from '@/i18n';

const { symbol: currencySymbol } = useCurrency();

const props = defineProps({ items: Array, warehouses: Array, categories: { type: Array, default: () => [] }, filters: Object, can: Object });
const search = ref(props.filters.search || '');
const status = ref(props.filters.status || 'active');
const categoryFilter = ref(props.filters.category_id || '');
const editing = ref(null);
const imagePreview = ref(null);
const fileInput = ref(null);
const unitLabels = { piece: translate('inventory.units.piece'), kg: translate('inventory.units.kg'), liter: translate('inventory.units.liter'), pack: translate('inventory.units.pack') };
const typeLabels = { product: translate('inventory.types.product'), ingredient: translate('inventory.types.ingredient'), consumable: translate('inventory.types.consumable'), service: translate('inventory.types.service') };

watch(() => props.filters, value => { search.value = value.search || ''; status.value = value.status || 'active'; categoryFilter.value = value.category_id || ''; }, { deep: true });
function filter() {
    router.get(route('inventory.items'), { search: search.value || undefined, status: status.value, category_id: categoryFilter.value || undefined }, { preserveState: true, preserveScroll: true, replace: true });
}

// Indented label for the hierarchical selects: Pije / — Alkoolike / —— Verë.
function categoryLabel(category) {
    return `${'—'.repeat(category.depth)}${category.depth ? ' ' : ''}${category.name}`;
}

const defaultWarehouse = computed(() => props.warehouses[0]?.id || null);
const form = useForm({
    name: '', sku: '', barcode: '', category_id: null, type: 'product', unit: 'piece', average_cost: 0,
    image: null, remove_image: false, selling_price: null, minimum_stock: 0,
    sell_in_pos: false, pos_warehouse_id: null,
    sell_in_rooms: false, room_selling_price: null, room_warehouse_id: null,
    initial_quantity: 0, initial_warehouse_id: null, is_active: true,
});
watch(() => form.type, (type) => {
    if (type !== 'product') {
        form.sell_in_pos = false;
        form.sell_in_rooms = false;
    }
});

function openNew() {
    form.reset();
    Object.assign(form, {
        category_id: null,
        type: 'product', unit: 'piece', average_cost: 0, image: null, remove_image: false,
        selling_price: null, minimum_stock: 0, sell_in_pos: false,
        pos_warehouse_id: defaultWarehouse.value,
        sell_in_rooms: false, room_selling_price: null, room_warehouse_id: defaultWarehouse.value,
        initial_quantity: 0, initial_warehouse_id: defaultWarehouse.value, is_active: true,
    });
    imagePreview.value = null;
    form.clearErrors();
    editing.value = 'new';
}

function openEdit(item) {
    Object.assign(form, {
        name: item.name, sku: item.sku, barcode: item.barcode || '', category_id: item.category_id || null, type: item.type,
        unit: item.unit, average_cost: item.average_cost, selling_price: item.selling_price, minimum_stock: item.minimum_stock,
        image: null, remove_image: false, sell_in_pos: item.sell_in_pos,
        pos_warehouse_id: item.pos_warehouse_id || defaultWarehouse.value,
        sell_in_rooms: item.sell_in_rooms, room_selling_price: item.room_selling_price,
        room_warehouse_id: item.room_warehouse_id || defaultWarehouse.value,
        initial_quantity: 0, initial_warehouse_id: defaultWarehouse.value, is_active: item.is_active,
    });
    imagePreview.value = item.image_path ? `/storage/${item.image_path}` : null;
    form.clearErrors();
    editing.value = item;
}

function closeModal() { editing.value = null; imagePreview.value = null; form.clearErrors(); }
function selectImage(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    form.image = file;
    form.remove_image = false;
    imagePreview.value = URL.createObjectURL(file);
}
function removeImage() {
    form.image = null;
    form.remove_image = true;
    imagePreview.value = null;
    if (fileInput.value) fileInput.value.value = '';
}
function submit() {
    const options = { preserveScroll: true, forceFormData: true, onSuccess: closeModal };
    if (editing.value === 'new') form.transform(data => data).post(route('inventory.items.store'), options);
    else form.transform(data => ({ ...data, _method: 'put' })).post(route('inventory.items.update', editing.value.id), options);
}

// Write-off: audited stock exit for damaged/lost/expired goods.
const writingOff = ref(null);
const writeOffReasons = { damaged: 'Dëmtuar', lost: 'Humbur', expired: 'Skaduar', other: 'Tjetër' };
const writeOffForm = useForm({ inventory_item_id: null, warehouse_id: null, quantity: null, reason: 'damaged', notes: '' });
const writeOffStock = computed(() => Number((writingOff.value?.warehouses || []).find(stock => stock.id === writeOffForm.warehouse_id)?.quantity || 0));

function openWriteOff(item) {
    writeOffForm.reset();
    writeOffForm.clearErrors();
    writeOffForm.inventory_item_id = item.id;
    writeOffForm.warehouse_id = item.warehouses[0]?.id || null;
    writeOffForm.reason = 'damaged';
    writingOff.value = item;
}

function closeWriteOff() {
    writingOff.value = null;
    writeOffForm.clearErrors();
}

function submitWriteOff() {
    writeOffForm.post(route('inventory.write-offs.store'), { preserveScroll: true, onSuccess: closeWriteOff });
}

// Category management: add (parent capped at two levels), rename, delete-when-empty.
const showCategories = ref(false);
const categoryForm = useForm({ name: '', parent_id: null });
const parentOptions = computed(() => props.categories.filter(category => category.depth < 2));
const renaming = ref(null);
const renameForm = useForm({ name: '' });

function submitCategory() {
    categoryForm.post(route('inventory.categories.store'), {
        preserveScroll: true,
        onSuccess: () => { categoryForm.reset(); categoryForm.clearErrors(); },
    });
}

function startRename(category) {
    renaming.value = category.id;
    renameForm.name = category.name;
    renameForm.clearErrors();
}

function submitRename(category) {
    renameForm.put(route('inventory.categories.update', category.id), {
        preserveScroll: true,
        onSuccess: () => { renaming.value = null; },
    });
}

function deleteCategory(category) {
    if (!confirm(`Fshi kategorinë "${category.name}"?`)) return;
    router.delete(route('inventory.categories.destroy', category.id), { preserveScroll: true });
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
                    <select v-model="categoryFilter" class="rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500" @change="filter">
                        <option value="">{{ $t('inventory.categories.all') }}</option>
                        <option v-for="category in categories" :key="category.id" :value="category.id">{{ categoryLabel(category) }}</option>
                    </select>
                    <select v-model="status" class="rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500" @change="filter">
                        <option value="active">{{ $t('inventory.status.active') }}</option><option value="low">{{ $t('inventory.status.low') }}</option><option value="inactive">{{ $t('inventory.status.inactive') }}</option>
                    </select>
                    <Button variant="outline" @click="filter">{{ $t('inventory.actions.filter') }}</Button>
                    <Button v-if="can.manageInventory" variant="outline" @click="showCategories = true"><FolderTree class="h-4 w-4" /> {{ $t('inventory.categories.manage') }}</Button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[850px] text-left">
                        <thead class="bg-neutral-50 text-tiny uppercase tracking-wide text-neutral-400"><tr><th class="px-5 py-3">{{ $t('inventory.items.item') }}</th><th class="px-5 py-3">{{ $t('inventory.items.type') }}</th><th class="px-5 py-3">{{ $t('inventory.items.warehouseStock') }}</th><th class="px-5 py-3 text-right">{{ $t('inventory.items.stock') }}</th><th class="px-5 py-3 text-right">{{ $t('inventory.items.cost') }}</th><th class="px-5 py-3">{{ $t('inventory.items.status') }}</th><th class="px-5 py-3"></th></tr></thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="item in items" :key="item.id" class="hover:bg-neutral-50/70">
                                <td class="px-5 py-3.5"><div class="flex items-center gap-3"><span class="grid h-11 w-11 shrink-0 place-items-center overflow-hidden rounded-lg border border-neutral-200 bg-accent-50 text-accent-700"><img v-if="item.image_path" :src="`/storage/${item.image_path}`" :alt="item.name" class="h-full w-full object-cover" /><Package v-else class="h-4 w-4" /></span><div><strong class="block text-body-sm text-primary-900">{{ item.name }}</strong><span class="text-tiny text-neutral-400">{{ item.sku }}<template v-if="item.category"> · {{ item.category }}</template></span></div></div></td>
                                <td class="px-5 py-3.5"><span class="text-body-sm text-neutral-600">{{ typeLabels[item.type] }}</span><div v-if="item.sell_in_pos || item.sell_in_rooms" class="mt-1 flex gap-1"><span v-if="item.sell_in_pos" class="rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-bold text-blue-700">POS</span><span v-if="item.sell_in_rooms" class="rounded-full bg-violet-50 px-2 py-0.5 text-[10px] font-bold text-violet-700">DHOMA</span></div></td>
                                <td class="px-5 py-3.5"><div class="flex max-w-[240px] flex-wrap gap-1"><span v-for="stock in item.warehouses" :key="stock.id" class="rounded-full bg-neutral-100 px-2 py-1 text-tiny text-neutral-600">{{ stock.name }}: {{ stock.quantity }}</span><span v-if="!item.warehouses.length" class="text-tiny text-neutral-400">—</span></div></td>
                                <td class="px-5 py-3.5 text-right"><strong class="text-body-sm tabular-nums" :class="item.is_low ? 'text-warning-700' : 'text-primary-900'">{{ item.stock }} {{ unitLabels[item.unit] }}</strong><span v-if="item.is_low" class="mt-1 flex items-center justify-end gap-1 text-tiny text-warning-700"><AlertTriangle class="h-3 w-3" /> {{ $t('inventory.items.minimum', { value: item.minimum_stock }) }}</span></td>
                                <td class="px-5 py-3.5 text-right"><strong class="text-body-sm text-primary-900">{{ money(item.average_cost) }}</strong><span class="block text-tiny text-neutral-400">{{ money(item.stock_value) }}</span></td>
                                <td class="px-5 py-3.5"><span class="rounded-full px-2 py-1 text-tiny font-bold" :class="item.is_active ? 'bg-accent-50 text-accent-700' : 'bg-neutral-100 text-neutral-500'">{{ item.is_active ? $t('inventory.status.active') : $t('inventory.status.inactive') }}</span></td>
                                <td class="px-5 py-3.5 text-right"><div class="flex items-center justify-end gap-1"><button v-if="can.writeOffs && item.type !== 'service' && item.warehouses.length" class="rounded-md p-2 text-neutral-400 hover:bg-warning-50 hover:text-warning-700" :title="$t('inventory.writeOff.action')" @click="openWriteOff(item)"><PackageMinus class="h-4 w-4" /></button><button v-if="can.manageInventory" class="rounded-md p-2 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" @click="openEdit(item)"><Pencil class="h-4 w-4" /></button></div></td>
                            </tr>
                            <tr v-if="!items.length"><td colspan="7" class="px-5 py-14 text-center text-body-sm text-neutral-400">{{ $t('inventory.items.empty') }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </div>

        <Modal :show="!!editing" :title="editing === 'new' ? 'Produkt i ri' : 'Ndrysho produktin'" max-width="4xl" @close="closeModal">
            <div class="space-y-5">
                <section class="rounded-xl border border-neutral-200 p-4">
                    <div class="mb-4 flex items-center gap-2"><Package class="h-5 w-5 text-accent-700" /><div><h4 class="font-bold text-primary-900">Të dhënat e produktit</h4><p class="text-tiny text-neutral-500">Fotoja përdoret në katalogun e POS-it dhe të dhomave.</p></div></div>
                    <div class="grid gap-5 md:grid-cols-[180px_1fr]">
                        <div>
                            <button type="button" class="group relative grid aspect-square w-full place-items-center overflow-hidden rounded-xl border-2 border-dashed border-neutral-200 bg-neutral-50 hover:border-accent-400" @click="fileInput?.click()">
                                <img v-if="imagePreview" :src="imagePreview" class="h-full w-full object-cover" alt="Pamja e produktit" />
                                <span v-else class="flex flex-col items-center gap-2 text-center text-neutral-400"><ImagePlus class="h-7 w-7" /><span class="text-tiny font-semibold">Ngarko foto<br />JPG, PNG ose WEBP</span></span>
                            </button>
                            <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="selectImage" />
                            <button v-if="imagePreview" type="button" class="mt-2 flex w-full items-center justify-center gap-1 text-tiny font-semibold text-error-600" @click="removeImage"><Trash2 class="h-3.5 w-3.5" /> Hiq foton</button>
                            <p v-if="form.errors.image" class="mt-1 text-tiny text-error-600">{{ form.errors.image }}</p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <div class="sm:col-span-2"><label class="mb-1 block text-body-sm font-semibold">Emri i produktit</label><TextInput v-model="form.name" class="w-full" placeholder="p.sh. Coca-Cola 330ml" /><p v-if="form.errors.name" class="mt-1 text-tiny text-error-600">{{ form.errors.name }}</p></div>
                            <div><label class="mb-1 block text-body-sm font-semibold">SKU</label><TextInput v-model="form.sku" class="w-full" /><p v-if="form.errors.sku" class="mt-1 text-tiny text-error-600">{{ form.errors.sku }}</p></div>
                            <div><label class="mb-1 block text-body-sm font-semibold">Barcode</label><TextInput v-model="form.barcode" class="w-full" /></div>
                            <div><label class="mb-1 block text-body-sm font-semibold">Kategoria</label><select v-model="form.category_id" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm"><option :value="null">{{ $t('inventory.categories.none') }}</option><option v-for="category in categories" :key="category.id" :value="category.id">{{ categoryLabel(category) }}</option></select><p v-if="form.errors.category_id" class="mt-1 text-tiny text-error-600">{{ form.errors.category_id }}</p></div>
                            <div><label class="mb-1 block text-body-sm font-semibold">Lloji</label><select v-model="form.type" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm"><option v-for="(label, key) in typeLabels" :key="key" :value="key">{{ label }}</option></select></div>
                            <div v-if="form.type !== 'service'"><label class="mb-1 block text-body-sm font-semibold">Njësia</label><select v-model="form.unit" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm"><option v-for="(label, key) in unitLabels" :key="key" :value="key">{{ label }}</option></select></div>
                            <div v-if="form.type !== 'service'"><label class="mb-1 block text-body-sm font-semibold">Stoku minimal</label><TextInput v-model="form.minimum_stock" type="number" min="0" step="0.0001" class="w-full" /></div>
                            <label v-if="editing !== 'new'" class="flex items-center gap-2 self-end pb-2 text-body-sm font-semibold"><input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-accent-600 focus:ring-accent-500" /> Produkt aktiv</label>
                        </div>
                    </div>
                </section>

                <section v-if="form.type === 'product'" class="grid gap-4 md:grid-cols-2">
                    <div class="rounded-xl border p-4" :class="form.sell_in_pos ? 'border-blue-200 bg-blue-50/40' : 'border-neutral-200'">
                        <label class="flex cursor-pointer items-start justify-between gap-4"><span class="flex gap-3"><span class="grid h-10 w-10 place-items-center rounded-lg bg-blue-100 text-blue-700"><ShoppingBasket class="h-5 w-5" /></span><span><strong class="block text-primary-900">Shitet në POS</strong><small class="text-neutral-500">Shfaqe si produkt në bar/restorant.</small></span></span><input v-model="form.sell_in_pos" type="checkbox" class="mt-2 rounded border-neutral-300 text-accent-600 focus:ring-accent-500" /></label>
                        <div v-if="form.sell_in_pos" class="mt-4 grid gap-3 sm:grid-cols-2">
                            <div><label class="mb-1 block text-tiny font-bold">Magazina POS</label><select v-model="form.pos_warehouse_id" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm"><option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option></select><p v-if="form.errors.pos_warehouse_id" class="mt-1 text-tiny text-error-600">{{ form.errors.pos_warehouse_id }}</p></div>
                            <div><label class="mb-1 block text-tiny font-bold">Çmimi në POS ({{ currencySymbol }})</label><TextInput v-model="form.selling_price" type="number" min="0.01" step="0.01" class="w-full" /><p v-if="form.errors.selling_price" class="mt-1 text-tiny text-error-600">{{ form.errors.selling_price }}</p></div>
                            <p class="sm:col-span-2 rounded-lg bg-blue-50 px-3 py-2 text-tiny text-blue-800">Në POS artikulli shfaqet nën kategorinë e vet të inventarit ("Kategoria" sipër).</p>
                        </div>
                    </div>
                    <div class="rounded-xl border p-4" :class="form.sell_in_rooms ? 'border-violet-200 bg-violet-50/40' : 'border-neutral-200'">
                        <label class="flex cursor-pointer items-start justify-between gap-4"><span class="flex gap-3"><span class="grid h-10 w-10 place-items-center rounded-lg bg-violet-100 text-violet-700"><BedDouble class="h-5 w-5" /></span><span><strong class="block text-primary-900">Shitet në dhoma</strong><small class="text-neutral-500">Shfaqe në minibar dhe folio.</small></span></span><input v-model="form.sell_in_rooms" type="checkbox" class="mt-2 rounded border-neutral-300 text-accent-600 focus:ring-accent-500" /></label>
                        <div v-if="form.sell_in_rooms" class="mt-4 grid gap-3 sm:grid-cols-2">
                            <div><label class="mb-1 block text-tiny font-bold">Magazina e dhomave</label><select v-model="form.room_warehouse_id" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm"><option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option></select><p v-if="form.errors.room_warehouse_id" class="mt-1 text-tiny text-error-600">{{ form.errors.room_warehouse_id }}</p></div>
                            <div><label class="mb-1 block text-tiny font-bold">Çmimi në dhomë ({{ currencySymbol }})</label><TextInput v-model="form.room_selling_price" type="number" min="0.01" step="0.01" class="w-full" /><p v-if="form.errors.room_selling_price" class="mt-1 text-tiny text-error-600">{{ form.errors.room_selling_price }}</p></div>
                        </div>
                    </div>
                </section>

                <section v-if="editing === 'new' && form.type !== 'service'" class="rounded-xl border border-accent-200 bg-accent-50/50 p-4"><h4 class="text-body-sm font-bold text-primary-900">Gjendja fillestare</h4><p class="mt-1 text-tiny text-neutral-500">Opsionale. Blerjet e ardhshme regjistrohen nga faturat e blerjes.</p><div class="mt-3 grid gap-3 sm:grid-cols-3"><div><label class="mb-1 block text-tiny font-semibold">Sasia</label><TextInput v-model="form.initial_quantity" type="number" min="0" step="0.0001" class="w-full" /></div><div><label class="mb-1 block text-tiny font-semibold">Kosto / njësi ({{ currencySymbol }})</label><TextInput v-model="form.average_cost" type="number" min="0" step="0.01" class="w-full" /></div><div><label class="mb-1 block text-tiny font-semibold">Magazina</label><select v-model="form.initial_warehouse_id" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm"><option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option></select></div></div></section>
            </div>
            <template #footer><Button variant="ghost" @click="closeModal">{{ $t('inventory.actions.cancel') }}</Button><Button :loading="form.processing" :disabled="!form.name || !form.sku" @click="submit">{{ $t('inventory.actions.save') }}</Button></template>
        </Modal>

        <Modal :show="!!writingOff" :title="`${$t('inventory.writeOff.action')} · ${writingOff?.name || ''}`" max-width="md" @close="closeWriteOff">
            <div class="space-y-4">
                <p class="rounded-lg border border-warning-200 bg-warning-50 px-3 py-2.5 text-small text-warning-800">{{ $t('inventory.writeOff.hint') }}</p>
                <div>
                    <label class="mb-1 block text-body-sm font-semibold">{{ $t('inventory.writeOff.warehouse') }}</label>
                    <select v-model="writeOffForm.warehouse_id" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm">
                        <option v-for="stock in writingOff?.warehouses || []" :key="stock.id" :value="stock.id">{{ stock.name }} ({{ stock.quantity }} {{ unitLabels[writingOff?.unit] }})</option>
                    </select>
                    <p v-if="writeOffForm.errors.warehouse_id" class="mt-1 text-tiny text-error-600">{{ writeOffForm.errors.warehouse_id }}</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-body-sm font-semibold">{{ $t('inventory.writeOff.quantity') }}</label>
                        <TextInput v-model="writeOffForm.quantity" type="number" min="0.0001" :max="writeOffStock" step="0.0001" class="w-full" placeholder="0" />
                        <p class="mt-1 text-tiny text-neutral-400">{{ $t('inventory.writeOff.available', { value: writeOffStock }) }}</p>
                        <p v-if="writeOffForm.errors.quantity" class="mt-1 text-tiny text-error-600">{{ writeOffForm.errors.quantity }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-body-sm font-semibold">{{ $t('inventory.writeOff.reason') }}</label>
                        <select v-model="writeOffForm.reason" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm">
                            <option v-for="(label, key) in writeOffReasons" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <p v-if="writeOffForm.errors.reason" class="mt-1 text-tiny text-error-600">{{ writeOffForm.errors.reason }}</p>
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-body-sm font-semibold">{{ $t('inventory.writeOff.notes') }}</label>
                    <TextInput v-model="writeOffForm.notes" class="w-full" :placeholder="$t('inventory.writeOff.notesPlaceholder')" maxlength="300" />
                    <p v-if="writeOffForm.errors.notes" class="mt-1 text-tiny text-error-600">{{ writeOffForm.errors.notes }}</p>
                </div>
            </div>
            <template #footer>
                <Button variant="ghost" @click="closeWriteOff">{{ $t('inventory.actions.cancel') }}</Button>
                <Button variant="danger" :loading="writeOffForm.processing" :disabled="!writeOffForm.quantity || !writeOffForm.warehouse_id" @click="submitWriteOff"><PackageMinus class="h-4 w-4" /> {{ $t('inventory.writeOff.submit') }}</Button>
            </template>
        </Modal>

        <Modal :show="showCategories" :title="$t('inventory.categories.manage')" max-width="lg" @close="showCategories = false">
            <div class="space-y-4">
                <form class="flex flex-col gap-2 rounded-xl border border-neutral-200 bg-neutral-50 p-3 sm:flex-row" @submit.prevent="submitCategory">
                    <div class="min-w-0 flex-1">
                        <TextInput v-model="categoryForm.name" class="w-full" :placeholder="$t('inventory.categories.namePlaceholder')" maxlength="80" />
                        <p v-if="categoryForm.errors.name" class="mt-1 text-tiny text-error-600">{{ categoryForm.errors.name }}</p>
                    </div>
                    <div class="sm:w-56">
                        <select v-model="categoryForm.parent_id" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm">
                            <option :value="null">{{ $t('inventory.categories.rootLevel') }}</option>
                            <option v-for="category in parentOptions" :key="category.id" :value="category.id">{{ categoryLabel(category) }}</option>
                        </select>
                        <p v-if="categoryForm.errors.parent_id" class="mt-1 text-tiny text-error-600">{{ categoryForm.errors.parent_id }}</p>
                    </div>
                    <Button type="submit" :loading="categoryForm.processing" :disabled="!categoryForm.name.trim()"><Plus class="h-4 w-4" /> {{ $t('inventory.categories.add') }}</Button>
                </form>
                <p class="text-tiny text-neutral-500">{{ $t('inventory.categories.depthHint') }}</p>

                <div class="max-h-[380px] divide-y divide-neutral-100 overflow-y-auto rounded-xl border border-neutral-200">
                    <div v-for="category in categories" :key="category.id" class="flex items-center gap-2 px-3 py-2.5" :style="{ paddingLeft: `${12 + category.depth * 22}px` }">
                        <template v-if="renaming === category.id">
                            <TextInput v-model="renameForm.name" class="h-8 flex-1" maxlength="80" @keyup.enter="submitRename(category)" />
                            <Button size="sm" :loading="renameForm.processing" @click="submitRename(category)">{{ $t('inventory.actions.save') }}</Button>
                            <Button size="sm" variant="ghost" @click="renaming = null">{{ $t('inventory.actions.cancel') }}</Button>
                        </template>
                        <template v-else>
                            <FolderTree class="h-4 w-4 shrink-0 text-neutral-300" />
                            <span class="min-w-0 flex-1 truncate text-body-sm font-semibold text-primary-900">{{ category.name }}</span>
                            <span v-if="category.items_count" class="rounded-full bg-neutral-100 px-2 py-0.5 text-tiny text-neutral-500">{{ $t('inventory.categories.itemCount', { count: category.items_count }) }}</span>
                            <button class="rounded-md p-1.5 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" :title="$t('inventory.categories.rename')" @click="startRename(category)"><Pencil class="h-3.5 w-3.5" /></button>
                            <button class="rounded-md p-1.5 disabled:cursor-not-allowed disabled:opacity-30" :class="'text-neutral-400 hover:bg-error-50 hover:text-error-600'" :disabled="category.items_count > 0 || category.children_count > 0" :title="category.items_count || category.children_count ? $t('inventory.categories.deleteBlocked') : $t('inventory.categories.delete')" @click="deleteCategory(category)"><Trash2 class="h-3.5 w-3.5" /></button>
                        </template>
                    </div>
                    <p v-if="renameForm.errors.name && renaming" class="px-3 py-2 text-tiny text-error-600">{{ renameForm.errors.name }}</p>
                    <p v-if="!categories.length" class="px-4 py-10 text-center text-body-sm text-neutral-400">{{ $t('inventory.categories.empty') }}</p>
                </div>
            </div>
            <template #footer>
                <Button variant="ghost" @click="showCategories = false">{{ $t('inventory.actions.cancel') }}</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
