<script setup>
import { translate } from '@/i18n';
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import { MapPin, Package, Plus, Trash2 } from 'lucide-vue-next';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
    categories: Array,
    inventoryItems: Array,
    warehouses: Array,
    tree: { type: Array, default: () => [] },
    inventoryEnabled: { type: Boolean, default: false },
    posOutlets: { type: Array, default: () => [] },
    toasts: Object,
});

const { baseSymbol: currencySymbol } = useCurrency();

// Indented label for the tree select: Pije / — Alkoolike / —— Verë.
function treeLabel(node) {
    return `${'—'.repeat(node.depth)}${node.depth ? ' ' : ''}${node.name}`;
}

// Category groups come from the inventory tree — here only the POS-side
// settings (outlet, source warehouse) are edited; names live on the tree.
const showCatModal = ref(false);
const editingCat = ref(null);
const catForm = useForm({ name: '', outlet: '', warehouse_id: null, outlet_ids: [] });

// No saved restriction = visible in EVERY outlet, so the checkboxes open
// all-checked; the server normalizes a full selection back to "everywhere".
function openEditCat(cat) {
    editingCat.value = cat;
    Object.assign(catForm, {
        name: cat.name,
        outlet: cat.outlet || '',
        warehouse_id: cat.warehouse_id || null,
        outlet_ids: cat.outlet_ids?.length ? [...cat.outlet_ids] : props.posOutlets.map((outlet) => outlet.id),
    });
    showCatModal.value = true;
}

function restrictedOutletNames(cat) {
    if (!cat.outlet_ids?.length) return null;
    return props.posOutlets
        .filter((outlet) => cat.outlet_ids.includes(outlet.id))
        .map((outlet) => outlet.is_active ? outlet.name : `${outlet.name} (${translate('settingsPos.outletInactive')})`)
        .join(' · ');
}

function submitCat() {
    catForm.put(route('settings.menu-categories.update', editingCat.value.id), {
        onSuccess: () => { showCatModal.value = false; props.toasts?.success(translate('admin.generated.k_98bda6c4106b')); },
    });
}

// A refused action still completes the Inertia visit (redirect-back with a
// flash error), so onSuccess fires — read the flash before claiming victory,
// or the server's explanation drowns under a false "deleted" toast.
function flashToast(page, successMessage) {
    const flashError = page?.props?.flash?.error;
    if (flashError) props.toasts?.error(flashError);
    else props.toasts?.success(successMessage);
}

function deleteCat(cat) {
    if (!confirm(translate('settingsTabs.menu.deleteCategoryConfirm', { name: cat.name }))) return;
    router.delete(route('settings.menu-categories.destroy', cat.id), {
        preserveScroll: true,
        onSuccess: (page) => flashToast(page, translate('admin.generated.k_4d43fb45068e')),
        onError: () => props.toasts?.error(translate('admin.generated.k_5af41fa6dae3')),
    });
}

// Item
const showItemModal = ref(false);
const editingItem = ref(null);
const itemForm = useForm({ inventory_category_id: null, name: '', price: '', image: null, inventory_components: [] });
const imagePreview = ref(null);
const fileInput = ref(null);

function openCreateItem(cat) {
    editingItem.value = null;
    itemForm.reset();
    itemForm.inventory_category_id = cat?.inventory_category_id || null;
    itemForm.inventory_components = [];
    imagePreview.value = null;
    showItemModal.value = true;
}

function openEditItem(item, cat) {
    editingItem.value = item;
    itemForm.name = item.name;
    itemForm.price = item.price;
    itemForm.inventory_category_id = cat?.inventory_category_id || null;
    itemForm.image = null;
    itemForm.inventory_components = (item.inventory_components || []).map(component => ({
        inventory_item_id: component.inventory_item_id,
        quantity: Number(component.quantity),
    }));
    imagePreview.value = item.image_path ? `/storage/${item.image_path}` : null;
    showItemModal.value = true;
}

function onImageChange(e) {
    const file = e.target.files[0];
    if (!file) return;
    itemForm.image = file;
    const reader = new FileReader();
    reader.onload = (ev) => { imagePreview.value = ev.target.result; };
    reader.readAsDataURL(file);
}

function removeImage() {
    itemForm.image = null;
    imagePreview.value = null;
    if (fileInput.value) fileInput.value.value = '';
}

function addInventoryComponent() {
    itemForm.inventory_components.push({ inventory_item_id: null, quantity: 1 });
}

function removeInventoryComponent(index) {
    itemForm.inventory_components.splice(index, 1);
}

function submitItem() {
    // Use FormData for file upload
    const formData = new FormData();
    formData.append('name', itemForm.name);
    formData.append('price', itemForm.price);
    if (itemForm.image) formData.append('image', itemForm.image);
    if (itemForm.inventory_category_id) formData.append('inventory_category_id', itemForm.inventory_category_id);
    itemForm.inventory_components.forEach((component, index) => {
        formData.append(`inventory_components[${index}][inventory_item_id]`, component.inventory_item_id ?? '');
        formData.append(`inventory_components[${index}][quantity]`, component.quantity ?? '');
    });

    if (editingItem.value) {
        formData.append('_method', 'PUT');
        router.post(route('settings.menu-items.update', editingItem.value.id), formData, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => { showItemModal.value = false; props.toasts?.success(translate('admin.generated.k_147fcb2c4362')); },
        });
    } else {
        router.post(route('settings.menu-items.store'), formData, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => { showItemModal.value = false; itemForm.reset(); imagePreview.value = null; props.toasts?.success(translate('admin.generated.k_6b6864ab7265')); },
        });
    }
}

function toggleItem(item) {
    router.patch(route('settings.menu-items.toggle', item.id), {}, {
        preserveScroll: true,
        onSuccess: () => props.toasts?.info(`${item.name}: ${item.is_available ? translate('settingsTabs.menu.unavailable') : translate('settingsTabs.menu.available')}`),
    });
}

function deleteItem(item) {
    if (!confirm(translate('settingsTabs.menu.deleteItemConfirm', { name: item.name }))) return;
    router.delete(route('settings.menu-items.destroy', item.id), {
        preserveScroll: true,
        onSuccess: (page) => flashToast(page, translate('admin.generated.k_8ee8e0129285')),
    });
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <h3 class="text-h4 text-primary-900">{{ $t('admin.generated.k_78be5e1611cb') }}</h3>
            <Button size="sm" variant="primary" @click="openCreateItem(null)"><Plus class="h-4 w-4" /> {{ $t('settingsTabs.menu.newItem') }}</Button>
        </div>
        <p class="rounded-lg border border-blue-100 bg-blue-50 px-3.5 py-2.5 text-small text-blue-800">
            {{ $t('settingsTabs.menu.groupsInfo') }}
        </p>

        <Card v-for="cat in categories" :key="cat.id">
            <template #header>
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <h4 class="text-label text-primary-900">{{ cat.name }}</h4>
                        <Badge variant="neutral" size="sm">{{ cat.items?.length || 0 }} {{ $t('admin.generated.k_d24c59d8b853') }}</Badge>
                        <Badge v-if="inventoryEnabled && cat.warehouse_id" variant="success" size="sm"><Package class="h-3 w-3" /> {{ warehouses.find(warehouse => warehouse.id === cat.warehouse_id)?.name }}</Badge>
                        <Badge v-if="restrictedOutletNames(cat)" variant="info" size="sm"><MapPin class="h-3 w-3" /> {{ restrictedOutletNames(cat) }}</Badge>
                    </div>
                    <div class="flex gap-1.5">
                        <Button size="sm" variant="ghost" @click="openCreateItem(cat)">{{ $t('admin.generated.k_3acd3ffafdb5') }}</Button>
                        <Button size="sm" variant="ghost" @click="openEditCat(cat)">{{ $t('settingsTabs.menu.settings') }}</Button>
                        <Button v-if="!cat.inventory_category_id" size="sm" variant="ghost" class="text-error-600" @click="deleteCat(cat)">{{ $t('admin.generated.k_94078f0402e2') }}</Button>
                    </div>
                </div>
            </template>

            <div v-if="cat.items?.length" class="divide-y divide-neutral-100 -my-1">
                <div v-for="item in cat.items" :key="item.id" class="flex items-center justify-between py-2.5">
                    <div class="flex items-center gap-3">
                        <!-- Thumbnail -->
                        <div class="h-10 w-10 rounded-md bg-neutral-100 overflow-hidden shrink-0 flex items-center justify-center">
                            <img v-if="item.image_path" :src="`/storage/${item.image_path}`" :alt="item.name" class="h-full w-full object-cover" />
                            <span v-else class="text-neutral-300 text-small">{{ $t('admin.generated.k_67905075c031') }}</span>
                        </div>
                        <div>
                            <span class="text-body-sm text-primary-900 font-medium">{{ item.name }}</span>
                            <span class="text-body-sm text-accent-600 font-medium ml-2">{{ currencySymbol }}{{ item.price }}</span>
                            <span v-if="item.inventory_item_id" class="ml-2 rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-bold text-blue-700">{{ $t('settingsTabs.menu.fromInventory') }}</span>
                            <span v-if="item.inventory_components?.length" class="mt-0.5 block text-tiny text-neutral-400">{{ item.inventory_components.length }} {{ $t('inventory.pos.components') }}</span>
                        </div>
                        <Badge v-if="!item.is_available" variant="error" size="sm">{{ $t('admin.generated.k_0389a69f50e1') }}</Badge>
                    </div>
                    <div class="flex gap-1">
                        <Button size="sm" variant="ghost" @click="toggleItem(item)">
                            {{ item.is_available ? $t('admin.generated.k_fe06b9e8b743') : $t('admin.generated.k_31730ad3e645') }}
                        </Button>
                        <Button v-if="!item.inventory_item_id" size="sm" variant="ghost" @click="openEditItem(item, cat)">{{ $t('admin.generated.k_69b7a8e80aee') }}</Button>
                        <Button v-if="!item.inventory_item_id" size="sm" variant="ghost" class="text-error-600" @click="deleteItem(item)">{{ $t('admin.generated.k_94078f0402e2') }}</Button>
                    </div>
                </div>
            </div>
            <div v-else class="py-4 text-center text-small text-neutral-400">{{ $t('admin.generated.k_d0282acd6842') }}</div>
        </Card>

        <div v-if="!categories?.length" class="py-8 text-center text-body-sm text-neutral-500">{{ $t('admin.generated.k_52da55b20bb5') }}</div>
    </div>

    <!-- Category Modal -->
    <Modal :show="showCatModal" :title="`${$t('settingsTabs.menu.groupSettingsTitle')} · ${editingCat?.name || ''}`" max-width="sm" @close="showCatModal = false">
        <div class="space-y-4">
            <p v-if="editingCat?.inventory_category_id" class="rounded-lg bg-neutral-50 px-3 py-2 text-small text-neutral-600">
                {{ $t('settingsTabs.menu.nameFromInventory', { name: editingCat?.name || '' }) }}
            </p>
            <FormGroup v-else :label="$t('admin.generated.k_588dd1daa42d')" :error="catForm.errors.name" required>
                <TextInput v-model="catForm.name" :placeholder="$t('admin.generated.k_2454362e8872')" :error="catForm.errors.name" />
            </FormGroup>
            <div class="grid gap-4 sm:grid-cols-2">
                <!-- Legacy warehouse-type hint; superseded by real outlets once any exist -->
                <FormGroup v-if="!posOutlets.length" :label="$t('inventory.pos.outlet')">
                    <select v-model="catForm.outlet" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500">
                        <option value="">—</option><option value="bar">Bar</option><option value="restaurant">{{ $t('inventory.warehouseTypes.restaurant') }}</option>
                    </select>
                </FormGroup>
                <FormGroup v-if="inventoryEnabled" :label="$t('inventory.pos.sourceWarehouse')" :error="catForm.errors.warehouse_id">
                    <select v-model="catForm.warehouse_id" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500">
                        <option :value="null">{{ $t('inventory.pos.automaticWarehouse') }}</option><option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                    </select>
                </FormGroup>
            </div>
            <FormGroup v-if="posOutlets.length" :label="$t('settingsTabs.menu.visibleIn')" :error="catForm.errors.outlet_ids">
                <div class="space-y-2 rounded-lg border border-neutral-200 bg-neutral-50 p-3">
                    <label v-for="outlet in posOutlets" :key="outlet.id" class="flex items-center gap-2.5">
                        <input
                            v-model="catForm.outlet_ids"
                            type="checkbox"
                            :value="outlet.id"
                            class="h-4 w-4 rounded border-neutral-300 text-accent-600 focus:ring-accent-500"
                        >
                        <span class="text-body-sm text-primary-900">{{ outlet.name }}</span>
                        <span v-if="!outlet.is_active" class="text-tiny text-neutral-400">({{ $t('settingsPos.outletInactive') }})</span>
                    </label>
                    <p class="text-tiny text-neutral-400">{{ $t('settingsTabs.menu.visibleInHint') }}</p>
                </div>
            </FormGroup>
        </div>
        <template #footer>
            <Button variant="outline" @click="showCatModal = false">{{ $t('admin.generated.k_71826e412580') }}</Button>
            <Button variant="primary" :loading="catForm.processing" @click="submitCat">{{ $t('admin.generated.k_f5ca5b683c10') }}</Button>
        </template>
    </Modal>

    <!-- Item Modal -->
    <Modal :show="showItemModal" :title="editingItem ? $t('admin.generated.k_dc12cbb9ca67') : $t('admin.generated.k_3f2634bc8442')" max-width="md" @close="showItemModal = false">
        <div class="space-y-4">
            <!-- Image upload -->
            <FormGroup :label="$t('admin.generated.k_597b519bb7dc')" :error="itemForm.errors?.image">
                <div class="flex items-start gap-4">
                    <!-- Preview -->
                    <div class="h-24 w-24 rounded-lg bg-neutral-100 overflow-hidden shrink-0 flex items-center justify-center border border-neutral-200">
                        <img v-if="imagePreview" :src="imagePreview" class="h-full w-full object-cover" />
                        <span v-else class="text-neutral-300 text-small">{{ $t('admin.generated.k_cc3b3e50f9f1') }}</span>
                    </div>
                    <div class="flex-1 space-y-2">
                        <input
                            ref="fileInput"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="block w-full text-small text-neutral-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border file:border-neutral-200 file:text-body-sm file:font-medium file:bg-white file:text-neutral-700 hover:file:bg-neutral-50 file:cursor-pointer"
                            @change="onImageChange"
                        />
                        <p class="text-tiny text-neutral-400">{{ $t('admin.generated.k_4095b5a673fa') }}</p>
                        <button v-if="imagePreview" type="button" class="text-small text-error-500 hover:text-error-700" @click="removeImage">{{ $t('admin.generated.k_18511e519313') }}</button>
                    </div>
                </div>
            </FormGroup>

            <div class="grid grid-cols-2 gap-4">
                <FormGroup :label="$t('admin.generated.k_588dd1daa42d')" :error="itemForm.errors?.name" required>
                    <TextInput v-model="itemForm.name" :placeholder="$t('admin.generated.k_f52281cd0a63')" :error="itemForm.errors?.name" />
                </FormGroup>
                <FormGroup :label="`${$t('settingsTabs.menu.price')} (${currencySymbol})`" :error="itemForm.errors?.price" required>
                    <TextInput type="number" v-model="itemForm.price" min="0.01" step="0.01" :error="itemForm.errors?.price" />
                </FormGroup>
            </div>

            <FormGroup :label="$t('settingsTabs.menu.categoryFromInventory')" :error="itemForm.errors?.inventory_category_id" required>
                <select v-model="itemForm.inventory_category_id" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500">
                    <option :value="null" disabled>{{ $t('settingsTabs.menu.chooseCategory') }}</option>
                    <option v-for="node in tree" :key="node.id" :value="node.id">{{ treeLabel(node) }}</option>
                </select>
            </FormGroup>

            <section v-if="inventoryEnabled" class="rounded-lg border border-neutral-200 bg-neutral-50 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div><h4 class="text-body-sm font-bold text-primary-900">{{ $t('inventory.pos.recipe') }}</h4><p class="mt-1 text-tiny text-neutral-500">{{ $t('inventory.pos.recipeHint') }}</p></div>
                    <Button v-if="inventoryItems.length" size="sm" variant="outline" @click="addInventoryComponent"><Plus class="h-4 w-4" /> {{ $t('inventory.pos.addComponent') }}</Button>
                </div>
                <div v-if="itemForm.inventory_components.length" class="mt-3 space-y-2">
                    <div v-for="(component, index) in itemForm.inventory_components" :key="index" class="grid grid-cols-[minmax(0,1fr),110px,36px] gap-2">
                        <select v-model="component.inventory_item_id" class="min-w-0 rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500">
                            <option :value="null" disabled>{{ $t('inventory.bill.item') }}</option><option v-for="inventoryItem in inventoryItems" :key="inventoryItem.id" :value="inventoryItem.id">{{ inventoryItem.name }} · {{ inventoryItem.sku }}</option>
                        </select>
                        <TextInput v-model="component.quantity" type="number" min="0.0001" step="0.0001" class="w-full" :placeholder="$t('inventory.items.quantity')" />
                        <button type="button" class="grid place-items-center rounded-md text-neutral-400 hover:bg-error-50 hover:text-error-600" @click="removeInventoryComponent(index)"><Trash2 class="h-4 w-4" /></button>
                        <p v-if="itemForm.errors[`inventory_components.${index}.inventory_item_id`]" class="col-span-3 text-tiny text-error-600">{{ itemForm.errors[`inventory_components.${index}.inventory_item_id`] }}</p>
                        <p v-if="itemForm.errors[`inventory_components.${index}.quantity`]" class="col-span-3 text-tiny text-error-600">{{ itemForm.errors[`inventory_components.${index}.quantity`] }}</p>
                    </div>
                </div>
                <p v-else class="mt-3 rounded-lg border border-dashed border-neutral-200 bg-white px-3 py-4 text-center text-tiny text-neutral-400">{{ $t('inventory.pos.noRecipe') }}</p>
            </section>
        </div>
        <template #footer>
            <Button variant="outline" @click="showItemModal = false">{{ $t('admin.generated.k_71826e412580') }}</Button>
            <Button variant="primary" @click="submitItem">{{ editingItem ? $t('admin.generated.k_f5ca5b683c10') : $t('admin.generated.k_be09ce96c961') }}</Button>
        </template>
    </Modal>
</template>
