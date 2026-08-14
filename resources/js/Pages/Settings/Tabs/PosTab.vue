<script setup>
import { router, useForm } from '@inertiajs/vue3';
import { Plus, Store, UserRoundCheck } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { translate } from '@/i18n';
import FormGroup from '@/Components/UI/FormGroup.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Checkbox from '@/Components/UI/Checkbox.vue';

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
    staff: { type: Array, default: () => [] },
    accountMode: { type: String, default: 'shared' },
    outlets: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] },
    toasts: Object,
});

// --- Sales outlets (Restorant / Bar / Beach Bar) ---
const showOutletModal = ref(false);
const editingOutlet = ref(null);
const outletForm = useForm({ name: '', warehouse_id: null, is_active: true });

function openCreateOutlet() {
    editingOutlet.value = null;
    outletForm.reset();
    outletForm.clearErrors();
    showOutletModal.value = true;
}

function openEditOutlet(outlet) {
    editingOutlet.value = outlet;
    Object.assign(outletForm, {
        name: outlet.name,
        warehouse_id: outlet.warehouse_id || null,
        is_active: Boolean(outlet.is_active),
    });
    outletForm.clearErrors();
    showOutletModal.value = true;
}

function submitOutlet() {
    const options = {
        preserveScroll: true,
        onSuccess: () => { showOutletModal.value = false; props.toasts?.success(translate('settingsPos.outletSaved')); },
    };
    if (editingOutlet.value) outletForm.put(route('settings.pos.outlets.update', editingOutlet.value.id), options);
    else outletForm.post(route('settings.pos.outlets.store'), options);
}

// A refused delete comes back as a flash error (the outlet had orders and was
// deactivated instead) — surface the server's explanation, not a false "deleted".
function deleteOutlet(outlet) {
    if (!confirm(translate('settingsPos.outletDeleteConfirm', { name: outlet.name }))) return;
    router.delete(route('settings.pos.outlets.destroy', outlet.id), {
        preserveScroll: true,
        onSuccess: (page) => {
            const flashError = page?.props?.flash?.error;
            if (flashError) props.toasts?.error(flashError);
            else props.toasts?.success(page?.props?.flash?.success || translate('settingsPos.outletDeleted'));
        },
    });
}

// An outlet with recorded orders can never be deleted (history must stay
// correct) — the honest action is deactivation, offered as such. The server
// still refuses while open tickets exist.
function deactivateOutlet(outlet) {
    if (!confirm(translate('settingsPos.outletDeactivateConfirm', { name: outlet.name }))) return;
    router.put(route('settings.pos.outlets.update', outlet.id), {
        name: outlet.name,
        warehouse_id: outlet.warehouse_id,
        is_active: false,
    }, {
        preserveScroll: true,
        onSuccess: (page) => {
            const flashError = page?.props?.flash?.error;
            if (flashError) props.toasts?.error(flashError);
            else props.toasts?.success(translate('settingsPos.outletDeactivated'));
        },
    });
}

function outletWarehouseName(outlet) {
    return props.warehouses.find((warehouse) => warehouse.id === outlet.warehouse_id)?.name || null;
}

// Where POS money lands — saved immediately, independent of the main form.
const accountMode = ref(props.accountMode);
watch(() => props.accountMode, (mode) => { accountMode.value = mode; });

function saveAccountMode(mode) {
    if (accountMode.value === mode) return;
    accountMode.value = mode;
    router.put(route('finance.accounts.pos-mode'), { mode }, {
        preserveScroll: true,
        onSuccess: () => props.toasts?.success(translate('settingsPos.accountModeSaved')),
        onError: () => {
            accountMode.value = props.accountMode;
            props.toasts?.error(translate('settingsPos.accountModeNotSaved'));
        },
    });
}

const staffRows = (staff) => staff.map((person) => ({ ...person, pin: '', clear_pin: false }));
const form = useForm({
    service_mode: props.settings.service_mode || 'hybrid',
    opening_view: props.settings.opening_view || 'products',
    salesperson_enabled: props.settings.salesperson_enabled ?? true,
    salesperson_required: props.settings.salesperson_required ?? true,
    staff: staffRows(props.staff),
});
const showCreateModal = ref(false);
const createForm = useForm({ name: '', email: '', password: '', pin: '' });
const enabledCount = computed(() => form.staff.filter((person) => person.enabled).length);

watch(() => props.staff, (staff) => {
    form.staff = staffRows(staff);
}, { deep: true });

function submit() {
    form.put(route('settings.pos'), {
        preserveScroll: true,
        onSuccess: () => props.toasts?.success(translate('settingsPos.settingsSaved')),
    });
}

function closeCreateModal() {
    showCreateModal.value = false;
    createForm.reset();
    createForm.clearErrors();
}

function createSalesperson() {
    createForm.post(route('settings.pos.salespeople.store'), {
        preserveScroll: true,
        onSuccess: () => {
            closeCreateModal();
            props.toasts?.success(translate('settingsPos.waiterCreated'));
        },
    });
}

function digitsOnly(event) {
    createForm.pin = event.target.value.replace(/\D/g, '').slice(0, 4);
}
</script>

<template>
    <Card>
        <template #header>
            <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-h4 text-primary-900">{{ $t('settingsPos.title') }}</h3>
                    <p class="mt-1 text-body-sm text-neutral-500">{{ $t('settingsPos.subtitle') }}</p>
                </div>
                <Button type="button" variant="outline" @click="showCreateModal = true">
                    <Plus class="h-4 w-4" />
                    {{ $t('settingsPos.addWaiter') }}
                </Button>
            </div>
        </template>

        <form class="space-y-6" @submit.prevent="submit">
            <section>
                <h4 class="text-label text-primary-900">{{ $t('settingsPos.serviceModeTitle') }}</h4>
                <p class="mt-1 text-small text-neutral-500">{{ $t('settingsPos.serviceModeHint') }}</p>
                <div class="mt-3 grid gap-3 md:grid-cols-3">
                    <label
                        v-for="option in [{value:'hybrid',title:$t('settingsPos.serviceHybrid'),text:$t('settingsPos.serviceHybridText')},{value:'tables',title:$t('settingsPos.serviceTables'),text:$t('settingsPos.serviceTablesText')},{value:'direct',title:$t('settingsPos.serviceDirect'),text:$t('settingsPos.serviceDirectText')}]"
                        :key="option.value"
                        class="cursor-pointer rounded-xl border p-4"
                        :class="form.service_mode === option.value ? 'border-accent-500 bg-accent-50 ring-2 ring-accent-500/10' : 'border-neutral-200'"
                    >
                        <input v-model="form.service_mode" type="radio" :value="option.value" class="sr-only">
                        <strong class="text-body-sm text-primary-900">{{ option.title }}</strong>
                        <span class="mt-1 block text-tiny text-neutral-500">{{ option.text }}</span>
                    </label>
                </div>
            </section>

            <section class="border-t border-neutral-100 pt-5">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h4 class="text-label text-primary-900">{{ $t('settingsPos.outletsTitle') }}</h4>
                        <p class="mt-1 text-small text-neutral-500">{{ $t('settingsPos.outletsHint') }}</p>
                    </div>
                    <Button v-if="outlets.length" type="button" size="sm" variant="outline" @click="openCreateOutlet">
                        <Plus class="h-4 w-4" /> {{ $t('settingsPos.addOutlet') }}
                    </Button>
                </div>

                <div v-if="outlets.length" class="mt-3 divide-y divide-neutral-100 rounded-xl border border-neutral-200">
                    <div v-for="outlet in outlets" :key="outlet.id" class="flex flex-wrap items-center justify-between gap-3 p-4">
                        <div class="flex items-center gap-3">
                            <span class="grid h-9 w-9 place-items-center rounded-lg bg-accent-50 text-accent-700">
                                <Store class="h-4.5 w-4.5" />
                            </span>
                            <div>
                                <strong class="block text-body-sm text-primary-900">{{ outlet.name }}</strong>
                                <span class="text-tiny text-neutral-500">
                                    {{ outletWarehouseName(outlet)
                                        ? $t('settingsPos.outletWarehouseLine', { name: outletWarehouseName(outlet) })
                                        : $t('settingsPos.outletWarehouseAutoLine') }}
                                </span>
                            </div>
                            <span
                                class="rounded-full px-2.5 py-0.5 text-tiny font-semibold"
                                :class="outlet.is_active ? 'bg-success-50 text-success-700' : 'bg-neutral-100 text-neutral-500'"
                            >
                                {{ outlet.is_active ? $t('settingsPos.outletActive') : $t('settingsPos.outletInactive') }}
                            </span>
                        </div>
                        <div class="flex gap-1.5">
                            <Button type="button" size="sm" variant="ghost" @click="openEditOutlet(outlet)">{{ $t('settingsPos.outletEdit') }}</Button>
                            <Button v-if="!outlet.orders_count" type="button" size="sm" variant="ghost" class="text-error-600" @click="deleteOutlet(outlet)">{{ $t('settingsPos.outletDelete') }}</Button>
                            <Button v-else-if="outlet.is_active" type="button" size="sm" variant="ghost" class="text-warning-700" @click="deactivateOutlet(outlet)">{{ $t('settingsPos.outletDeactivate') }}</Button>
                        </div>
                    </div>
                </div>
                <div v-else class="mt-3 rounded-xl border border-dashed border-neutral-200 bg-neutral-50 px-4 py-6 text-center">
                    <p class="text-body-sm text-neutral-500">{{ $t('settingsPos.outletsEmpty') }}</p>
                    <Button type="button" size="sm" variant="primary" class="mt-3" @click="openCreateOutlet">
                        <Plus class="h-4 w-4" /> {{ $t('settingsPos.addFirstOutlet') }}
                    </Button>
                </div>
            </section>

            <section class="border-t border-neutral-100 pt-5">
                <h4 class="text-label text-primary-900">{{ $t('settingsPos.moneyTitle') }}</h4>
                <p class="mt-1 text-small text-neutral-500">{{ $t('settingsPos.moneyHint') }}</p>
                <div class="mt-3 grid gap-3 md:grid-cols-3">
                    <label
                        v-for="option in [
                            { value: 'shared', title: $t('settingsPos.accountShared'), text: $t('settingsPos.accountSharedText') },
                            { value: 'split_cash', title: $t('settingsPos.accountSplitCash'), text: $t('settingsPos.accountSplitCashText') },
                            { value: 'split_bank', title: $t('settingsPos.accountSplitBank'), text: $t('settingsPos.accountSplitBankText') },
                            { value: 'split_all', title: $t('settingsPos.accountSplitAll'), text: $t('settingsPos.accountSplitAllText') },
                        ]"
                        :key="option.value"
                        class="cursor-pointer rounded-xl border p-4"
                        :class="accountMode === option.value ? 'border-accent-500 bg-accent-50 ring-2 ring-accent-500/10' : 'border-neutral-200'"
                    >
                        <input :checked="accountMode === option.value" type="radio" name="pos-account-mode" :value="option.value" class="sr-only" @change="saveAccountMode(option.value)">
                        <strong class="text-body-sm text-primary-900">{{ option.title }}</strong>
                        <span class="mt-1 block text-tiny text-neutral-500">{{ option.text }}</span>
                    </label>
                </div>
            </section>

            <section class="grid gap-4 border-t border-neutral-100 pt-5 md:grid-cols-2">
                <label>
                    <span class="text-label text-neutral-700">{{ $t('settingsPos.openingView') }}</span>
                    <select v-model="form.opening_view" class="mt-2 w-full rounded-lg border-neutral-200">
                        <option value="tables">{{ $t('settingsPos.openingTables') }}</option>
                        <option value="products">{{ $t('settingsPos.openingProducts') }}</option>
                    </select>
                </label>
                <div class="space-y-3 rounded-xl bg-neutral-50 p-4">
                    <Checkbox v-model="form.salesperson_enabled" :label="$t('settingsPos.enableSalesperson')" />
                    <Checkbox v-model="form.salesperson_required" :disabled="!form.salesperson_enabled" :label="$t('settingsPos.requireSalesperson')" />
                </div>
            </section>

            <section class="border-t border-neutral-100 pt-5">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h4 class="text-label text-primary-900">{{ $t('settingsPos.waitersTitle') }}</h4>
                        <p class="mt-1 text-small text-neutral-500">{{ $t('settingsPos.waitersHint', { count: enabledCount }) }}</p>
                    </div>
                    <span class="inline-flex w-fit items-center gap-1.5 rounded-full bg-success-50 px-2.5 py-1 text-tiny font-semibold text-success-700">
                        <UserRoundCheck class="h-3.5 w-3.5" />
                        {{ $t('settingsPos.pinEncrypted') }}
                    </span>
                </div>

                <div class="mt-3 divide-y divide-neutral-100 rounded-xl border border-neutral-200">
                    <div v-for="person in form.staff" :key="person.id" class="grid gap-3 p-4 sm:grid-cols-[minmax(0,1fr)_120px_110px] sm:items-center">
                        <label class="flex items-center gap-3">
                            <input v-model="person.enabled" type="checkbox" class="h-4 w-4 rounded border-neutral-300 text-accent-600">
                            <span>
                                <strong class="block text-body-sm text-primary-900">{{ person.name }}</strong>
                                <span class="text-tiny text-neutral-500">{{ person.has_pin && !person.clear_pin ? $t('settingsPos.pinConfigured') : $t('settingsPos.pinMissing') }}</span>
                            </span>
                        </label>
                        <input v-model="person.pin" type="password" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" class="w-full rounded-lg border-neutral-200 text-center tracking-[.3em]" :placeholder="$t('settingsPos.pinNewPlaceholder')">
                        <button v-if="person.has_pin && !person.clear_pin" type="button" class="text-small font-semibold text-error-600" @click="person.clear_pin = true; person.pin = ''">{{ $t('settingsPos.removePin') }}</button>
                        <button v-else-if="person.clear_pin" type="button" class="text-small font-semibold text-neutral-500" @click="person.clear_pin = false">{{ $t('settingsPos.cancelRemoval') }}</button>
                    </div>
                    <p v-if="!form.staff.length" class="px-4 py-8 text-center text-body-sm text-neutral-500">{{ $t('settingsPos.noWaiters') }}</p>
                </div>
            </section>

            <div v-if="form.hasErrors" class="rounded-lg bg-error-50 px-3 py-2 text-small text-error-700">
                {{ $t('settingsPos.checkFields') }}
            </div>
            <div class="settings-actions">
                <Button type="submit" variant="primary" :loading="form.processing">{{ $t('settingsPos.saveSettings') }}</Button>
            </div>
        </form>
    </Card>

    <Modal :show="showOutletModal" :title="editingOutlet ? `${$t('settingsPos.outletEditTitle')} · ${editingOutlet.name}` : $t('settingsPos.addOutlet')" max-width="sm" @close="showOutletModal = false">
        <form class="space-y-4" @submit.prevent="submitOutlet">
            <FormGroup :label="$t('settingsPos.outletName')" html-for="outlet-name" :error="outletForm.errors.name" required>
                <TextInput id="outlet-name" v-model="outletForm.name" :placeholder="$t('settingsPos.outletNamePlaceholder')" :error="outletForm.errors.name" autofocus />
            </FormGroup>
            <FormGroup :label="$t('settingsPos.outletWarehouse')" html-for="outlet-warehouse" :error="outletForm.errors.warehouse_id">
                <select id="outlet-warehouse" v-model="outletForm.warehouse_id" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500">
                    <option :value="null">{{ $t('settingsPos.outletWarehouseAuto') }}</option>
                    <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                </select>
                <p class="mt-1 text-tiny text-neutral-400">{{ $t('settingsPos.outletWarehouseHint') }}</p>
            </FormGroup>
            <Checkbox v-if="editingOutlet" v-model="outletForm.is_active" :label="$t('settingsPos.outletActiveLabel')" />
        </form>
        <template #footer>
            <Button type="button" variant="outline" @click="showOutletModal = false">{{ $t('settingsPos.cancel') }}</Button>
            <Button type="button" variant="primary" :loading="outletForm.processing" @click="submitOutlet">{{ editingOutlet ? $t('settingsPos.outletSave') : $t('settingsPos.outletCreate') }}</Button>
        </template>
    </Modal>

    <Modal :show="showCreateModal" :title="$t('settingsPos.addWaiter')" max-width="md" @close="closeCreateModal">
        <form class="space-y-4" @submit.prevent="createSalesperson">
            <div class="rounded-lg border border-accent-100 bg-accent-50 px-3.5 py-3 text-small text-accent-800">
                {{ $t('settingsPos.createInfoPrefix') }} <strong>{{ $t('settingsPos.roleName') }}</strong> {{ $t('settingsPos.createInfoSuffix') }}
            </div>
            <FormGroup :label="$t('settingsPos.nameLabel')" html-for="waiter-name" :error="createForm.errors.name" required>
                <TextInput id="waiter-name" v-model="createForm.name" :placeholder="$t('settingsPos.namePlaceholder')" :error="createForm.errors.name" autofocus />
            </FormGroup>
            <FormGroup :label="$t('settingsPos.emailLabel')" html-for="waiter-email" :error="createForm.errors.email" required>
                <TextInput id="waiter-email" v-model="createForm.email" type="email" :placeholder="$t('settingsPos.emailPlaceholder')" :error="createForm.errors.email" />
            </FormGroup>
            <FormGroup :label="$t('settingsPos.passwordLabel')" html-for="waiter-password" :error="createForm.errors.password" required>
                <TextInput id="waiter-password" v-model="createForm.password" type="password" :placeholder="$t('settingsPos.passwordPlaceholder')" :error="createForm.errors.password" />
            </FormGroup>
            <FormGroup :label="$t('settingsPos.pinLabel')" html-for="waiter-pin" :error="createForm.errors.pin" required>
                <TextInput id="waiter-pin" v-model="createForm.pin" type="password" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" placeholder="••••" :error="createForm.errors.pin" @input="digitsOnly" />
            </FormGroup>
        </form>
        <template #footer>
            <Button variant="outline" @click="closeCreateModal">{{ $t('settingsPos.cancel') }}</Button>
            <Button variant="primary" :loading="createForm.processing" @click="createSalesperson">{{ $t('settingsPos.createWaiter') }}</Button>
        </template>
    </Modal>
</template>
