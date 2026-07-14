<script setup>
import { getIntlLocale, translate } from '@/i18n';
import { ref, computed } from 'vue';
import { router, usePage, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import Select from '@/Components/UI/Select.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';
import AuditTimeline from '@/Components/AuditTimeline.vue';
import { channelMeta } from '@/channels';
import { ArrowLeft, PackageOpen, Plus } from 'lucide-vue-next';

const props = defineProps({
    reservation: Object,
    folio: Object,
    payments: Array,
    openPosOrders: Array,
    history: { type: Array, default: () => [] },
    inventoryEnabled: { type: Boolean, default: false },
    inventoryItems: { type: Array, default: () => [] },
    inventoryWarehouses: { type: Array, default: () => [] },
    currency: { type: String, default: '€' },
});

const toasts = ref(null);
const checkingOut = ref(false);
const showLineModal = ref(false);
const showMinibarModal = ref(false);
const showPayModal = ref(false);
const showInvoice = ref(false);
const checkoutMode = ref(false);

const page = usePage();
const perms = page.props.auth.user?.permissions || [];
const canUpdate = perms.includes('update_reservations');
const housekeepingEnabled = computed(() => page.props.modules?.housekeeping === true);

// Front desk asks housekeeping for a daily (stayover) clean while the guest is in-house.
const requestingCleaning = ref(false);
function requestCleaning() {
    requestingCleaning.value = true;
    router.post(route('reservations.request-cleaning', props.reservation.id), {}, {
        preserveScroll: true,
        onSuccess: (page) => {
            const flash = page.props?.flash || {};
            if (flash.error) toasts.value?.error(flash.error);
            else toasts.value?.success(flash.success || 'Pastrimi ditor u kerkua.');
        },
        onFinish: () => (requestingCleaning.value = false),
    });
}

const statusBadge = {
    pending: { variant: 'warning', label: translate('admin.generated.k_581b54c11d72') },
    confirmed: { variant: 'info', label: translate('admin.generated.k_06bf8410988d') },
    checked_in: { variant: 'success', label: translate('admin.generated.k_c07aa3069fcc') },
    checked_out: { variant: 'neutral', label: translate('admin.generated.k_023b466e7e43') },
    cancelled: { variant: 'error', label: translate('admin.generated.k_9c647e2278f4') },
};

const typeLabel = {
    room: translate('admin.generated.k_654e9cd3a2c4'), restaurant: 'Restorant', bar: 'Bar',
    minibar: 'Minibar', extra: translate('admin.generated.k_d65a8958aa77'), tax: 'Taksa', discount: 'Zbritje',
};
const methodLabel = { cash: 'Kesh', card: 'Karte' };

const lineTypeOptions = computed(() => [
    ...(!props.inventoryEnabled ? [{ value: 'minibar', label: 'Minibar' }] : []),
    { value: 'extra', label: translate('reservationShow.hotelService') },
    { value: 'discount', label: 'Zbritje' },
]);
const methodOptions = [
    { value: 'cash', label: translate('admin.generated.k_da508864861c') },
    { value: 'card', label: translate('admin.generated.k_6d64b27daef1') },
];

const hasOpenOrders = computed(() => (props.openPosOrders?.length || 0) > 0);
const unsettled = computed(() => Number(props.folio.outstanding) > 0.005);
const canAddCharge = computed(() => canUpdate && ['pending', 'confirmed', 'checked_in'].includes(props.reservation.status));
const isCheckedIn = computed(() => props.reservation.status === 'checked_in');
const hotelName = usePage().props.settings?.hotel_name || 'Hotel';

// Group folio charges by category for the invoice (room + bar + restaurant + ...).
const invoiceGroups = computed(() => {
    const g = { room: Number(props.folio.roomCharge) || 0, bar: 0, restaurant: 0, minibar: 0, extra: 0, discount: 0 };
    for (const it of (props.folio.items || [])) g[it.type] = (g[it.type] || 0) + Number(it.amount);
    return ['room', 'bar', 'restaurant', 'minibar', 'extra', 'discount']
        .filter((k) => g[k] > 0)
        .map((k) => ({ key: k, label: typeLabel[k], amount: g[k] }));
});

function printInvoice() {
    window.print();
}

const lineForm = useForm({ type: 'extra', description: '', amount: '', charge_date: '' });
const newInventoryReference = () => globalThis.crypto?.randomUUID?.()
    || '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (digit) => (
        Number(digit) ^ Math.floor(Math.random() * 16) >> Number(digit) / 4
    ).toString(16));
const minibarForm = useForm({
    inventory_item_id: '',
    warehouse_id: '',
    quantity: 1,
    inventory_reference: newInventoryReference(),
});
const payForm = useForm({ amount: '', method: 'cash' });

const minibarItemOptions = computed(() => props.inventoryItems.map((item) => ({
    value: item.id,
    label: `${item.name} · ${item.sku}`,
})));
const minibarWarehouseOptions = computed(() => props.inventoryWarehouses.map((warehouse) => ({
    value: warehouse.id,
    label: warehouse.name,
})));
const selectedMinibarItem = computed(() => props.inventoryItems.find(
    (item) => Number(item.id) === Number(minibarForm.inventory_item_id),
));
const minibarAvailable = computed(() => Number(
    selectedMinibarItem.value?.warehouse_stock?.[String(minibarForm.warehouse_id)] ?? 0,
));
const minibarQuantity = computed(() => Number(minibarForm.quantity));
const minibarTotal = computed(() => (
    Number(selectedMinibarItem.value?.selling_price || 0) * Math.max(0, minibarQuantity.value || 0)
));
const minibarCanSubmit = computed(() => (
    minibarForm.inventory_item_id
    && minibarForm.warehouse_id
    && Number.isFinite(minibarQuantity.value)
    && minibarQuantity.value > 0
    && minibarQuantity.value <= minibarAvailable.value + 0.00005
));
function openLineModal() {
    lineForm.reset();
    lineForm.clearErrors();
    showLineModal.value = true;
}
function closeLineModal() {
    showLineModal.value = false;
    lineForm.reset();
    lineForm.clearErrors();
}

function openMinibarModal() {
    minibarForm.clearErrors();
    const warehouse = props.inventoryWarehouses[0];
    minibarForm.warehouse_id = warehouse?.id || '';
    const itemWithStock = props.inventoryItems.find(
        (item) => Number(item.warehouse_stock?.[String(warehouse?.id)] || 0) > 0,
    );
    minibarForm.inventory_item_id = itemWithStock?.id || props.inventoryItems[0]?.id || '';
    minibarForm.quantity = 1;
    minibarForm.inventory_reference = newInventoryReference();
    showMinibarModal.value = true;
}

function closeMinibarModal() {
    if (minibarForm.processing) return;
    showMinibarModal.value = false;
    minibarForm.clearErrors();
}

function submitMinibar() {
    if (!minibarCanSubmit.value || minibarForm.processing) return;
    minibarForm.post(route('reservations.folio.inventory', props.reservation.id), {
        preserveScroll: true,
        onSuccess: () => {
            showMinibarModal.value = false;
            minibarForm.inventory_reference = newInventoryReference();
            toasts.value?.success(translate('reservationShow.minibarSuccess'));
        },
        onError: (errors) => {
            toasts.value?.error(Object.values(errors)[0] || translate('reservationShow.minibarError'));
        },
    });
}
function money(v) {
    return `${props.currency}${Number(v ?? 0).toFixed(2)}`;
}
function formatDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short', year: 'numeric' });
}

function submitLine() {
    const addingDiscount = lineForm.type === 'discount';
    lineForm.post(route('reservations.folio.add', props.reservation.id), {
        preserveScroll: true,
        onSuccess: () => {
            closeLineModal();
            toasts.value?.success(addingDiscount ? translate('admin.generated.k_a21ae2825f65') : translate('admin.generated.k_3bd26a9f9948'));
        },
        onError: () => toasts.value?.error(addingDiscount ? translate('admin.generated.k_b9f48849d2d6') : translate('admin.generated.k_0c499a05caf5')),
    });
}
function submitPay() {
    payForm.post(route('reservations.payment', props.reservation.id), {
        preserveScroll: true,
        onSuccess: () => { showPayModal.value = false; payForm.reset(); toasts.value?.success(translate('admin.generated.k_7a56956c0f26')); },
        onError: () => toasts.value?.error(translate('admin.generated.k_039b4528088a')),
    });
}
// "Faturë" just views/prints the bill. "Check-out" opens the SAME invoice in checkout mode,
// where you settle the outstanding (cash/card) and only THEN does the guest leave.
function openInvoice() {
    checkoutMode.value = false;
    showInvoice.value = true;
}
function openCheckout() {
    if (hasOpenOrders.value) { toasts.value?.error(translate('admin.generated.k_b42ad0dd36d6')); return; }
    checkoutMode.value = true;
    showInvoice.value = true;
}
function settleAndCheckout(method) {
    if (hasOpenOrders.value) { toasts.value?.error(translate('admin.generated.k_b42ad0dd36d6')); return; }
    checkingOut.value = true;
    router.post(
        route('reservations.check-out', props.reservation.id),
        method ? { settle_method: method } : {},
        {
            preserveScroll: true,
            onSuccess: () => {
                showInvoice.value = false;
                checkoutMode.value = false;
                toasts.value?.success(
                    method
                        ? `Pagesa u regjistrua (${methodLabel[method]}) dhe check-out u krye.`
                        : 'Check-out u krye.'
                );
            },
            onError: (errors) => toasts.value?.error(errors.settle_method || 'Check-out deshtoi.'),
            onFinish: () => { checkingOut.value = false; },
        }
    );
}
</script>

<template>
    <AppLayout>
        <Link
            :href="route('reservations.index')"
            class="mb-3 inline-flex items-center gap-1.5 text-body-sm font-medium text-neutral-600 no-underline transition-colors hover:text-accent-700"
        >
            <ArrowLeft class="h-4 w-4" :stroke-width="1.75" />
{{ $t('admin.generated.k_d363cf7a6377') }} </Link>

        <PageHeader
            :title="`Rezervimi #${reservation.id}`"
            :breadcrumbs="[{ label: $t('admin.generated.k_00001da4b7fd'), href: '/dashboard' }, { label: $t('admin.generated.k_5c62abaa3794'), href: route('reservations.index') }, { label: `#${reservation.id}` }]"
        >
            <template #actions>
                <Badge :variant="statusBadge[reservation.status]?.variant" dot>
                    {{ statusBadge[reservation.status]?.label }}
                </Badge>
                <Button v-if="canAddCharge" variant="outline" @click="openLineModal">{{ $t('admin.generated.k_1252ae021860') }}</Button>
                <Button v-if="canUpdate && reservation.status !== 'cancelled' && unsettled" variant="success" @click="showPayModal = true">{{ $t('admin.generated.k_d22b4ace12b9') }}</Button>
                <Button variant="outline" @click="openInvoice">{{ $t('admin.generated.k_bd826ba509ce') }}</Button>
                <Button
                    v-if="canUpdate && housekeepingEnabled && reservation.status === 'checked_in'"
                    variant="outline"
                    :loading="requestingCleaning"
                    @click="requestCleaning"
                >
{{ $t('admin.generated.k_779f68027976') }} </Button>
                <Button
                    v-if="canUpdate && reservation.status === 'checked_in'"
                    variant="primary"
                    :disabled="hasOpenOrders"
                    @click="openCheckout"
                >
{{ $t('admin.generated.k_a1fbe4f93a19') }} </Button>
            </template>
        </PageHeader>

        <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Reservation details -->
            <Card class="lg:col-span-1">
                <h3 class="text-label text-neutral-600 uppercase tracking-wider mb-4">{{ $t('admin.generated.k_6431140c47b8') }}</h3>
                <dl class="space-y-3">
                    <div class="flex justify-between">
                        <dt class="text-body-sm text-neutral-500">{{ $t('admin.generated.k_93eeb8e2c428') }}</dt>
                        <dd class="text-body-sm text-primary-900 font-medium text-right">{{ reservation.guest?.name }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-body-sm text-neutral-500">{{ $t('admin.generated.k_7b00d0ffb62a') }}</dt>
                        <dd class="text-body-sm text-neutral-700 text-right">{{ reservation.guest?.email || '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-body-sm text-neutral-500">{{ $t('admin.generated.k_c1cf7dda1024') }}</dt>
                        <dd class="text-body-sm text-neutral-700 text-right">{{ reservation.guest?.phone || '—' }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-neutral-100 pt-3">
                        <dt class="text-body-sm text-neutral-500">{{ $t('admin.generated.k_7765353fdc9c') }}</dt>
                        <dd class="text-body-sm text-primary-900 text-right">{{ reservation.room?.room_number }} — {{ reservation.room?.room_type }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-body-sm text-neutral-500">{{ $t('admin.generated.k_a2d639c1c1c3') }}</dt>
                        <dd class="text-body-sm text-neutral-700 text-right">{{ formatDate(reservation.check_in_date) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-body-sm text-neutral-500">{{ $t('admin.generated.k_a1fbe4f93a19') }}</dt>
                        <dd class="text-body-sm text-neutral-700 text-right">{{ formatDate(reservation.check_out_date) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-body-sm text-neutral-500">{{ $t('admin.generated.k_d78c3434a988') }}</dt>
                        <dd class="text-body-sm text-neutral-700 text-right">{{ reservation.nights }} ({{ reservation.adults }} {{ $t('admin.generated.k_e3ab3e474137') }}<span v-if="reservation.children">, {{ reservation.children }} {{ $t('admin.generated.k_378a78b79218') }}</span>)</dd>
                    </div>
                    <div class="flex justify-between items-center">
                        <dt class="text-body-sm text-neutral-500">{{ $t('admin.generated.k_f42ad29fcb96') }}</dt>
                        <dd class="text-body-sm text-right">
                            <span class="inline-flex items-center gap-1.5 rounded-md bg-neutral-100 px-2 py-0.5 text-tiny font-medium uppercase tracking-wide text-neutral-700 ring-1 ring-neutral-200/60">
                                <span class="h-1.5 w-1.5 rounded-full" :style="{ backgroundColor: channelMeta(reservation.channel).color }" />
                                {{ channelMeta(reservation.channel).label }}
                            </span>
                            <span v-if="reservation.channel_ref" class="ml-2 text-tiny text-neutral-400">#{{ reservation.channel_ref }}</span>
                        </dd>
                    </div>
                    <div v-if="reservation.payment_collect" class="flex justify-between items-center">
                        <dt class="text-body-sm text-neutral-500">{{ $t('admin.generated.k_6d2bca99facc') }}</dt>
                        <dd class="text-body-sm text-right">
                            <Badge v-if="reservation.payment_collect === 'ota'" variant="success">
{{ $t('admin.generated.k_18e6d591784e') }}{{ channelMeta(reservation.channel).label }})
                            </Badge>
                            <Badge v-else variant="warning">{{ $t('admin.generated.k_8eda58499648') }}</Badge>
                        </dd>
                    </div>
                    <div v-if="reservation.notes" class="border-t border-neutral-100 pt-3">
                        <dt class="text-body-sm text-neutral-500 mb-1">{{ $t('admin.generated.k_90824f450e04') }}</dt>
                        <dd class="text-body-sm text-neutral-700">{{ reservation.notes }}</dd>
                    </div>
                </dl>
            </Card>

            <!-- Folio -->
            <Card class="lg:col-span-2" :padding="false">
                <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4">
                    <h3 class="text-label uppercase tracking-wider text-neutral-600">{{ $t('reservationShow.folioTitle') }}</h3>
                    <div v-if="canAddCharge" class="flex flex-wrap items-center justify-end gap-2">
                        <Button
                            v-if="isCheckedIn && inventoryEnabled && inventoryItems.length"
                            size="sm"
                            variant="primary"
                            @click="openMinibarModal"
                        >
                            <PackageOpen class="h-4 w-4" />{{ $t('reservationShow.addMinibar') }}
                        </Button>
                        <Button size="sm" variant="outline" @click="openLineModal"><Plus class="h-4 w-4" />{{ $t('reservationShow.addCharge') }}</Button>
                    </div>
                </div>

                <!-- Open POS warning -->
                <div v-if="hasOpenOrders" class="mx-5 mt-4 rounded-lg bg-warning-50 border border-warning-200 px-4 py-3">
                    <p class="text-body-sm text-warning-800 font-medium">
                        {{ openPosOrders.length }} {{ $t('admin.generated.k_90c0cac09e08') }} </p>
                    <ul class="mt-1 text-small text-warning-700">
                        <li v-for="o in openPosOrders" :key="o.id">
{{ $t('admin.generated.k_89a49941efba') }}{{ o.id }}<span v-if="o.table_number"> {{ $t('admin.generated.k_dd001d55db66') }} {{ o.table_number }})</span> — {{ money(o.total_amount) }}
                        </li>
                    </ul>
                </div>

                <table class="min-w-full divide-y divide-neutral-200 mt-2">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_aa12398d381b') }}</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_eb57b84ec04c') }}</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_184c1eb85e4a') }}</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_66a4a0389558') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr>
                            <td class="px-5 py-3 text-body-sm text-primary-900 font-medium">{{ $t('admin.generated.k_383951845884') }}</td>
                            <td class="px-5 py-3"><Badge variant="info">{{ typeLabel.room }}</Badge></td>
                            <td class="px-5 py-3 text-body-sm text-neutral-500">{{ formatDate(reservation.check_in_date) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-primary-900">{{ money(folio.roomCharge) }}</td>
                        </tr>
                        <tr v-for="item in folio.items" :key="item.id">
                            <td class="px-5 py-3 text-body-sm text-neutral-700">{{ item.description }}</td>
                            <td class="px-5 py-3"><Badge :variant="item.type === 'discount' ? 'success' : 'neutral'">{{ typeLabel[item.type] || item.type }}</Badge></td>
                            <td class="px-5 py-3 text-body-sm text-neutral-500">{{ formatDate(item.charge_date) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm" :class="item.type === 'discount' ? 'text-success-600' : 'text-neutral-700'">
                                {{ item.type === 'discount' ? '−' : '' }}{{ money(item.amount) }}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Payments -->
                <div v-if="payments.length" class="border-t border-neutral-200">
                    <p class="px-5 pt-3 text-label text-neutral-500 uppercase tracking-wider">{{ $t('admin.generated.k_dfd9fd8d57a7') }}</p>
                    <ul class="px-5 py-2 space-y-1">
                        <li v-for="p in payments" :key="p.id" class="flex justify-between text-body-sm">
                            <span class="text-neutral-600">{{ methodLabel[p.method] || p.method }} · {{ formatDate(p.date) }}</span>
                            <span class="text-success-600">− {{ money(p.amount) }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Summary -->
                <div class="border-t border-neutral-200 px-5 py-4 space-y-2">
                    <div class="flex justify-between text-body-sm text-neutral-500">
                        <span>{{ $t('admin.generated.k_8e7d78994587') }}</span>
                        <span>{{ money(folio.net) }}</span>
                    </div>
                    <div class="flex justify-between text-body-sm text-neutral-500">
                        <span>{{ $t('admin.generated.k_aca304907dc3') }}{{ folio.taxRate }}%)</span>
                        <span>{{ money(folio.taxAmount) }}</span>
                    </div>
                    <div v-if="folio.discounts > 0" class="flex justify-between text-body-sm text-success-600">
                        <span>{{ $t('admin.generated.k_43e94d66754b') }}</span>
                        <span>− {{ money(folio.discounts) }}</span>
                    </div>
                    <div class="flex justify-between text-body-sm text-neutral-700 border-t border-neutral-100 pt-2">
                        <span>{{ $t('admin.generated.k_eb3e69f5ad4a') }}</span>
                        <span>{{ money(folio.gross) }}</span>
                    </div>
                    <div class="flex justify-between text-body-sm text-neutral-500">
                        <span>{{ $t('admin.generated.k_ea1bc96b45a5') }}</span>
                        <span>− {{ money(folio.paid) }}</span>
                    </div>
                    <div v-if="reservation.status !== 'cancelled'" class="flex justify-between border-t border-neutral-200 pt-2">
                        <span class="text-label text-neutral-700">{{ $t('admin.generated.k_224908982d79') }}</span>
                        <span class="text-h4" :class="unsettled ? 'text-error-600' : 'text-success-600'">{{ money(folio.outstanding) }}</span>
                    </div>
                    <div v-else class="flex justify-between border-t border-neutral-200 pt-2">
                        <span class="text-label text-neutral-700">{{ $t('admin.generated.k_68618a4a4a50') }}</span>
                        <span class="text-h4 text-neutral-400">{{ $t('admin.generated.k_060f31a189a6') }}</span>
                    </div>
                </div>
            </Card>
        </div>

        <Card class="mt-6" :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4">
                <h3 class="text-label uppercase tracking-wider text-neutral-600">{{ $t('admin.generated.k_4b669a4a3082') }}</h3>
                <p class="mt-0.5 text-tiny text-neutral-400">{{ $t('admin.generated.k_a5d6ddbd4f1e') }}</p>
            </div>
            <AuditTimeline :entries="history" />
        </Card>

        <!-- Inventory-backed minibar: folio charge and stock movement are one transaction. -->
        <Modal
            :show="showMinibarModal"
            :title="$t('reservationShow.minibarTitle')"
            max-width="md"
            :closeable="!minibarForm.processing"
            @close="closeMinibarModal"
        >
            <form class="space-y-4" @submit.prevent="submitMinibar">
                <div class="rounded-lg border border-accent-100 bg-accent-50/60 px-3 py-2.5 text-small text-accent-900">
                    {{ $t('reservationShow.minibarHint') }}
                </div>
                <FormGroup :label="$t('reservationShow.inventoryItem')" :error="minibarForm.errors.inventory_item_id" required>
                    <Select
                        v-model="minibarForm.inventory_item_id"
                        :options="minibarItemOptions"
                        :error="minibarForm.errors.inventory_item_id"
                    />
                </FormGroup>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormGroup :label="$t('reservationShow.warehouse')" :error="minibarForm.errors.warehouse_id" required>
                        <Select
                            v-model="minibarForm.warehouse_id"
                            :options="minibarWarehouseOptions"
                            :error="minibarForm.errors.warehouse_id"
                        />
                    </FormGroup>
                    <FormGroup :label="$t('reservationShow.quantity')" :error="minibarForm.errors.quantity" required>
                        <TextInput
                            v-model="minibarForm.quantity"
                            type="number"
                            min="0.0001"
                            :max="minibarAvailable"
                            step="0.0001"
                            :error="minibarForm.errors.quantity"
                        />
                    </FormGroup>
                </div>
                <div class="grid grid-cols-2 gap-3 rounded-xl border border-neutral-200 bg-neutral-50 p-3 text-body-sm">
                    <div>
                        <p class="text-neutral-500">{{ $t('reservationShow.availableStock') }}</p>
                        <p class="mt-1 font-semibold" :class="minibarAvailable > 0 ? 'text-success-700' : 'text-error-600'">
                            {{ minibarAvailable }} {{ selectedMinibarItem?.unit || '' }}
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-neutral-500">{{ $t('reservationShow.folioTotal') }}</p>
                        <p class="mt-1 font-semibold text-primary-900">{{ money(minibarTotal) }}</p>
                    </div>
                </div>
                <p v-if="minibarQuantity > minibarAvailable" class="text-small font-medium text-error-600">
                    {{ $t('reservationShow.insufficientStock') }}
                </p>
            </form>
            <template #footer>
                <Button variant="outline" :disabled="minibarForm.processing" @click="closeMinibarModal">
                    {{ $t('admin.generated.k_1ae76507a0e9') }}
                </Button>
                <Button variant="primary" :loading="minibarForm.processing" :disabled="!minibarCanSubmit" @click="submitMinibar">
                    {{ $t('reservationShow.postMinibar') }}
                </Button>
            </template>
        </Modal>

        <!-- Add a hotel charge to the guest account. Food/drinks come from POS. -->
        <Modal
            :show="showLineModal"
            :title="lineForm.type === 'discount' ? $t('admin.generated.k_d25a1fe93c11') : $t('admin.generated.k_36f1a2410ec6')"
            max-width="md"
            @close="closeLineModal"
        >
            <form @submit.prevent="submitLine" class="space-y-4">
                <div v-if="lineForm.type === 'discount'" class="rounded-lg border border-success-200 bg-success-50 px-3 py-2.5 text-small text-success-800">
{{ $t('admin.generated.k_9bd6ffdd6412') }} </div>
                <div v-else class="rounded-lg border border-info-200 bg-info-50 px-3 py-2.5 text-small text-info-800">
{{ $t('admin.generated.k_d7c075e2b5eb') }} </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <FormGroup :label="$t('admin.generated.k_db85f6c8ba94')" :error="lineForm.errors.type" required>
                        <Select v-model="lineForm.type" :options="lineTypeOptions" :error="lineForm.errors.type" />
                    </FormGroup>
                    <FormGroup :label="lineForm.type === 'discount' ? $t('reservationShow.discountAmount') : $t('reservationShow.amount')" :error="lineForm.errors.amount" required>
                        <TextInput type="number" step="0.01" min="0.01" v-model="lineForm.amount" placeholder="0.00" :error="lineForm.errors.amount" />
                    </FormGroup>
                </div>
                <FormGroup :label="$t('admin.generated.k_35e7b7d42fdc')" :error="lineForm.errors.description" required>
                    <TextInput v-model="lineForm.description" :placeholder="$t('admin.generated.k_31ff6e816134')" :error="lineForm.errors.description" />
                </FormGroup>
                <FormGroup :label="$t('admin.generated.k_935dab229960')" :error="lineForm.errors.charge_date">
                    <DatePicker v-model="lineForm.charge_date" :error="lineForm.errors.charge_date" />
                </FormGroup>
            </form>
            <template #footer>
                <Button variant="outline" @click="closeLineModal">{{ $t('admin.generated.k_1ae76507a0e9') }}</Button>
                <Button variant="primary" :loading="lineForm.processing" @click="submitLine">
                    {{ lineForm.type === 'discount' ? $t('admin.generated.k_db58214701b8') : $t('admin.generated.k_099014d4c4c7') }}
                </Button>
            </template>
        </Modal>

        <!-- Record payment modal -->
        <Modal :show="showPayModal" :title="$t('admin.generated.k_d38fb40fdcb5')" max-width="sm" @close="showPayModal = false">
            <form @submit.prevent="submitPay" class="space-y-4">
                <FormGroup :label="$t('admin.generated.k_522d709a6d49')" :error="payForm.errors.amount" required>
                    <TextInput type="number" step="0.01" min="0.01" v-model="payForm.amount" placeholder="0.00" :error="payForm.errors.amount" />
                </FormGroup>
                <FormGroup :label="$t('admin.generated.k_de3b5772305c')" :error="payForm.errors.method" required>
                    <Select v-model="payForm.method" :options="methodOptions" :error="payForm.errors.method" />
                </FormGroup>
            </form>
            <template #footer>
                <Button variant="outline" @click="showPayModal = false">{{ $t('admin.generated.k_1ae76507a0e9') }}</Button>
                <Button variant="primary" :loading="payForm.processing" @click="submitPay">{{ $t('admin.generated.k_02f7c6d23f37') }}</Button>
            </template>
        </Modal>

        <!-- Invoice (Fature) modal — also the settle-then-checkout flow -->
        <Modal :show="showInvoice" :title="checkoutMode ? $t('admin.generated.k_d0677fc34bd1') : $t('admin.generated.k_9b8aa645dbf0')" max-width="lg" @close="showInvoice = false">
            <div id="invoice" class="space-y-4 text-primary-900">
                <div class="text-center border-b border-neutral-200 pb-3">
                    <p class="text-h3">{{ hotelName }}</p>
                    <p class="text-body-sm text-neutral-500">{{ $t('admin.generated.k_8f10080f8f3f') }}{{ reservation.id }}</p>
                </div>

                <div class="flex justify-between text-body-sm">
                    <div>
                        <p class="text-neutral-500">{{ $t('admin.generated.k_93eeb8e2c428') }}</p>
                        <p class="font-medium">{{ reservation.guest?.name }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-neutral-500">{{ $t('admin.generated.k_7765353fdc9c') }} {{ reservation.room?.room_number }} — {{ reservation.room?.room_type }}</p>
                        <p>{{ formatDate(reservation.check_in_date) }} → {{ formatDate(reservation.check_out_date) }} · {{ reservation.nights }} {{ $t('admin.generated.k_24dc7df026eb') }}</p>
                    </div>
                </div>

                <table class="w-full text-body-sm">
                    <tbody>
                        <tr v-for="g in invoiceGroups" :key="g.key" class="border-b border-neutral-100">
                            <td class="py-2">{{ g.label }}</td>
                            <td class="py-2 text-right" :class="g.key === 'discount' ? 'text-success-600' : ''">{{ g.key === 'discount' ? '−' : '' }}{{ money(g.amount) }}</td>
                        </tr>
                    </tbody>
                </table>

                <div class="space-y-1.5 text-body-sm border-t border-neutral-200 pt-3">
                    <div class="flex justify-between text-neutral-500"><span>{{ $t('admin.generated.k_8e7d78994587') }}</span><span>{{ money(folio.net) }}</span></div>
                    <div class="flex justify-between text-neutral-500"><span>{{ $t('admin.generated.k_aca304907dc3') }}{{ folio.taxRate }}%)</span><span>{{ money(folio.taxAmount) }}</span></div>
                    <div class="flex justify-between font-medium border-t border-neutral-100 pt-1.5"><span>{{ $t('admin.generated.k_eb3e69f5ad4a') }}</span><span>{{ money(folio.gross) }}</span></div>
                    <div class="flex justify-between text-neutral-500"><span>{{ $t('admin.generated.k_ea1bc96b45a5') }}</span><span>− {{ money(folio.paid) }}</span></div>
                    <div class="flex justify-between border-t border-neutral-200 pt-2">
                        <span class="font-semibold">{{ $t('admin.generated.k_224908982d79') }}</span>
                        <span class="text-h4" :class="unsettled ? 'text-error-600' : 'text-success-600'">{{ money(folio.outstanding) }}</span>
                    </div>
                </div>

                <p class="text-tiny text-neutral-400 text-center pt-2">{{ $t('admin.generated.k_60a30ee15a06') }}</p>
            </div>

            <!-- Checkout call-to-action (not part of the printed invoice) -->
            <div v-if="checkoutMode" class="mt-4 rounded-lg border px-4 py-3 print:hidden"
                 :class="hasOpenOrders ? 'border-warning-200 bg-warning-50' : 'border-primary-200 bg-primary-50'">
                <p v-if="hasOpenOrders" class="text-body-sm text-warning-800 font-medium">
{{ $t('admin.generated.k_39d843219116') }} </p>
                <template v-else>
                    <p class="text-body-sm text-primary-800 font-medium mb-0.5">{{ $t('admin.generated.k_834d441511e6') }}</p>
                    <p v-if="unsettled" class="text-small text-neutral-600">
{{ $t('admin.generated.k_8a22fbc4b60e') }} <b>{{ money(folio.outstanding) }}</b> {{ $t('admin.generated.k_c463627d29e5') }} </p>
                    <p v-else class="text-small text-success-700">
{{ $t('admin.generated.k_8c75b2887818') }} </p>
                </template>
            </div>

            <template #footer>
                <template v-if="checkoutMode">
                    <Button variant="outline" @click="showInvoice = false">{{ $t('admin.generated.k_1ae76507a0e9') }}</Button>
                    <Button variant="outline" @click="printInvoice">{{ $t('admin.generated.k_e8eea0bd73c4') }}</Button>
                    <template v-if="!hasOpenOrders">
                        <template v-if="unsettled">
                            <Button variant="outline" :loading="checkingOut" @click="settleAndCheckout('cash')">{{ $t('admin.generated.k_87a50ba2dbca') }}</Button>
                            <Button variant="primary" :loading="checkingOut" @click="settleAndCheckout('card')">{{ $t('admin.generated.k_1ca92da022d3') }}</Button>
                        </template>
                        <Button v-else variant="primary" :loading="checkingOut" @click="settleAndCheckout(null)">{{ $t('admin.generated.k_3c2400f3c583') }}</Button>
                    </template>
                </template>
                <template v-else>
                    <Button variant="outline" @click="showInvoice = false">{{ $t('admin.generated.k_0eccb38cb085') }}</Button>
                    <Button variant="primary" @click="printInvoice">{{ $t('admin.generated.k_e8eea0bd73c4') }}</Button>
                </template>
            </template>
        </Modal>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>

<style>
@media print {
    body * { visibility: hidden !important; }
    #invoice, #invoice * { visibility: visible !important; }
    #invoice { position: absolute; left: 0; top: 0; width: 100%; padding: 24px; }
}
</style>
