<script setup>
import { getIntlLocale, translate } from '@/i18n';
import { ref, computed, watch } from 'vue';
import { router, usePage, useForm, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
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
import {
    ArrowLeft,
    ArrowRight,
    Banknote,
    CalendarDays,
    Check,
    ChevronDown,
    CircleAlert,
    CreditCard,
    DoorOpen,
    FileText,
    PackageOpen,
    Plus,
    RefreshCcw,
    ShieldCheck,
    UserRound,
} from 'lucide-vue-next';

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
const paymentSubmitting = ref(false);

const perms = usePage().props.auth.user?.permissions || [];
const canUpdate = perms.includes('update_reservations');

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
    ...(!props.inventoryEnabled ? [{ value: 'minibar', label: translate('admin.generated.k_c88066bb10cd') }] : []),
    { value: 'extra', label: translate('admin.generated.k_74f0c49d9770') },
    { value: 'discount', label: translate('admin.generated.k_0f4209c81496') },
]);
const methodOptions = [
    { value: 'cash', label: translate('admin.generated.k_da508864861c') },
    { value: 'card', label: translate('admin.generated.k_6d64b27daef1') },
];

const hasOpenOrders = computed(() => (props.openPosOrders?.length || 0) > 0);
const unsettled = computed(() => Number(props.folio.outstanding) > 0.005);
const canAddCharge = computed(() => canUpdate && ['pending', 'confirmed', 'checked_in'].includes(props.reservation.status));
const hotelName = usePage().props.settings?.hotel_name || 'Hotel';
const isCheckedIn = computed(() => props.reservation.status === 'checked_in');
const guestInitials = computed(() => (props.reservation.guest?.name || '?')
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase());

const checkoutState = computed(() => {
    if (hasOpenOrders.value) {
        return {
            tone: 'warning',
            title: translate('reservationShow.ordersTitle'),
            description: translate('reservationShow.ordersDescription'),
        };
    }
    if (unsettled.value) {
        return {
            tone: 'warning',
            title: translate('reservationShow.paymentTitle'),
            description: translate('reservationShow.paymentDescription'),
        };
    }
    return {
        tone: 'success',
        title: translate('reservationShow.readyTitle'),
        description: translate('reservationShow.readyDescription'),
    };
});

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
const selectedMinibarItem = computed(() => props.inventoryItems.find(
    (item) => Number(item.id) === Number(minibarForm.inventory_item_id),
));
const selectedMinibarWarehouse = computed(() => props.inventoryWarehouses.find(
    (warehouse) => Number(warehouse.id) === Number(minibarForm.warehouse_id),
));
watch(() => minibarForm.inventory_item_id, () => {
    if (selectedMinibarItem.value?.room_warehouse_id) minibarForm.warehouse_id = selectedMinibarItem.value.room_warehouse_id;
});
const minibarAvailable = computed(() => Number(
    selectedMinibarItem.value?.warehouse_stock?.[String(minibarForm.warehouse_id)] ?? 0,
));
const minibarQuantity = computed(() => Number(minibarForm.quantity));
const minibarTotal = computed(() => Number(selectedMinibarItem.value?.selling_price || 0) * Math.max(0, minibarQuantity.value || 0));
const minibarCanSubmit = computed(() => (
    minibarForm.inventory_item_id
    && minibarForm.warehouse_id
    && Number.isFinite(minibarQuantity.value)
    && minibarQuantity.value > 0
    && minibarQuantity.value <= minibarAvailable.value + 0.00005
));

const paymentAmount = computed(() => Number(payForm.amount));
const paymentIsValid = computed(() => (
    Number.isFinite(paymentAmount.value)
    && paymentAmount.value > 0
    && paymentAmount.value <= Number(props.folio.outstanding) + 0.005
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
    const itemWithStock = props.inventoryItems.find(
        (item) => Number(item.warehouse_stock?.[String(item.room_warehouse_id)] || 0) > 0,
    ) || props.inventoryItems.find(
        (item) => Object.values(item.warehouse_stock || {}).some((quantity) => Number(quantity) > 0),
    );
    minibarForm.inventory_item_id = itemWithStock?.id || props.inventoryItems[0]?.id || '';
    minibarForm.warehouse_id = itemWithStock?.room_warehouse_id || props.inventoryItems[0]?.room_warehouse_id || '';
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
            toasts.value?.success('Minibari u regjistrua dhe stoku u përditësua.');
        },
        onError: (errors) => toasts.value?.error(Object.values(errors)[0] || 'Minibari nuk u regjistrua.'),
    });
}

function openPaymentModal() {
    payForm.reset();
    payForm.clearErrors();
    payForm.amount = Number(props.folio.outstanding).toFixed(2);
    payForm.method = 'cash';
    showPayModal.value = true;
}

function closePaymentModal() {
    if (paymentSubmitting.value) return;
    showPayModal.value = false;
    payForm.reset();
    payForm.clearErrors();
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
async function submitPay() {
    if (paymentSubmitting.value) return;

    payForm.clearErrors();

    if (!paymentIsValid.value) {
        payForm.setError('amount', `Shuma duhet të jetë nga 0.01 deri në ${money(props.folio.outstanding)}.`);
        return;
    }

    paymentSubmitting.value = true;
    try {
        const recordedAmount = paymentAmount.value;
        await axios.post(
            route('reservations.payment', props.reservation.id),
            { amount: recordedAmount, method: payForm.method },
            { headers: { Accept: 'application/json' } },
        );

        paymentSubmitting.value = false;
        closePaymentModal();
        router.reload({ only: ['folio', 'payments'] });
        toasts.value?.success(`Pagesa ${money(recordedAmount)} u regjistrua me sukses.`);
    } catch (error) {
        const status = error.response?.status;
        const errors = error.response?.data?.errors;

        if (status === 422 && errors) {
            Object.entries(errors).forEach(([field, messages]) => payForm.setError(field, messages[0]));
            toasts.value?.error(Object.values(errors)[0]?.[0] || 'Kontrollo të dhënat e pagesës.');
        } else if (status === 503) {
            toasts.value?.warning('Sistemi po përditësohet. Pagesa nuk u regjistrua; provo përsëri pas pak sekondash.');
        } else {
            toasts.value?.error('Pagesa nuk u regjistrua. Provo përsëri.');
        }
    } finally {
        paymentSubmitting.value = false;
    }
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
            class="inline-flex items-center gap-1.5 text-sm font-medium text-neutral-600 no-underline transition-colors hover:text-accent-700"
        >
            <ArrowLeft class="h-4 w-4" :stroke-width="1.75" />
            {{ $t('reservationShow.back') }}
        </Link>

        <div class="mt-3 text-xs text-neutral-400">
            <Link href="/dashboard" class="text-neutral-400 no-underline hover:text-neutral-700">{{ $t('admin.generated.k_00001da4b7fd') }}</Link>
            <span class="mx-2">/</span>
            <Link :href="route('reservations.index')" class="text-neutral-400 no-underline hover:text-neutral-700">{{ $t('admin.generated.k_5c62abaa3794') }}</Link>
            <span class="mx-2">/</span>
            <span class="font-medium text-neutral-600">#{{ reservation.id }}</span>
        </div>

        <div class="mt-2 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-3xl font-semibold tracking-tight text-neutral-950">{{ $t('reservationShow.reservation') }} #{{ reservation.id }}</h1>
                <p class="mt-2 text-sm text-neutral-500">{{ $t('reservationShow.activeStay') }} · {{ formatDate(reservation.check_in_date) }} – {{ formatDate(reservation.check_out_date) }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button variant="outline" @click="openInvoice"><FileText class="h-4 w-4" /> {{ $t('reservationShow.invoice') }}</Button>
                <details class="group relative">
                    <summary class="flex cursor-pointer list-none items-center gap-2 rounded-md border border-neutral-200 bg-white px-4 py-2 text-sm font-medium text-neutral-700 transition hover:border-neutral-300 hover:bg-neutral-50">
                        {{ $t('reservationShow.more') }} <ChevronDown class="h-4 w-4 transition group-open:rotate-180" />
                    </summary>
                    <div class="absolute right-0 z-30 mt-2 w-52 overflow-hidden rounded-xl border border-neutral-200 bg-white p-1.5 shadow-xl">
                        <button v-if="canUpdate && isCheckedIn && inventoryEnabled && inventoryItems.length" type="button" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm text-neutral-700 hover:bg-neutral-50" @click="openMinibarModal"><PackageOpen class="h-4 w-4" />Shto minibar</button>
                        <button v-if="canAddCharge" type="button" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm text-neutral-700 hover:bg-neutral-50" @click="openLineModal"><Plus class="h-4 w-4" />{{ $t('reservationShow.addCharge') }}</button>
                        <button v-if="canUpdate && reservation.status !== 'cancelled' && unsettled" type="button" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm text-neutral-700 hover:bg-neutral-50" @click="openPaymentModal"><CreditCard class="h-4 w-4" />{{ $t('reservationShow.recordPayment') }}</button>
                        <button v-if="canUpdate && isCheckedIn" type="button" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm text-neutral-700 hover:bg-neutral-50" :disabled="requestingCleaning" @click="requestCleaning"><RefreshCcw class="h-4 w-4" />{{ $t('reservationShow.requestCleaning') }}</button>
                    </div>
                </details>
                <Button v-if="canUpdate && isCheckedIn" variant="primary" :disabled="hasOpenOrders" @click="openCheckout">
                    {{ $t('reservationShow.completeCheckout') }} <ArrowRight class="h-4 w-4" />
                </Button>
            </div>
        </div>

        <section class="mt-5 grid gap-4 rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm shadow-neutral-200/30 sm:grid-cols-2 xl:grid-cols-[minmax(300px,1fr)_repeat(3,minmax(130px,.32fr))] xl:items-center">
            <div class="flex min-w-0 items-center gap-3 sm:col-span-2 xl:col-span-1">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-emerald-50 text-sm font-bold text-emerald-700">{{ guestInitials }}</span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="truncate text-lg font-semibold text-neutral-900">{{ reservation.guest?.name }}</h2>
                        <Badge :variant="statusBadge[reservation.status]?.variant" dot>{{ statusBadge[reservation.status]?.label }}</Badge>
                    </div>
                    <p class="mt-1 truncate text-xs text-neutral-500">{{ channelMeta(reservation.channel).label }}<template v-if="reservation.channel_ref"> · #{{ reservation.channel_ref }}</template> · {{ reservation.adults }} {{ $t('reservationShow.adults') }}<template v-if="reservation.children">, {{ reservation.children }} {{ $t('reservationShow.children') }}</template></p>
                </div>
            </div>
            <div class="border-t border-neutral-100 pt-3 xl:border-l xl:border-t-0 xl:pl-5 xl:pt-0"><p class="text-xs text-neutral-400">{{ $t('reservationShow.room') }}</p><p class="mt-1 truncate text-sm font-semibold text-neutral-900">{{ reservation.room?.room_number }} · {{ reservation.room?.room_type }}</p></div>
            <div class="border-t border-neutral-100 pt-3 xl:border-l xl:border-t-0 xl:pl-5 xl:pt-0"><p class="text-xs text-neutral-400">{{ $t('reservationShow.stay') }}</p><p class="mt-1 text-sm font-semibold text-neutral-900">{{ reservation.nights }} {{ $t('reservationShow.nights') }}</p></div>
            <div class="border-t border-neutral-100 pt-3 xl:border-l xl:border-t-0 xl:pl-5 xl:pt-0"><p class="text-xs text-neutral-400">{{ $t('reservationShow.total') }}</p><p class="mt-1 text-sm font-semibold text-neutral-900">{{ money(folio.gross) }}</p></div>
        </section>

        <section
            v-if="isCheckedIn"
            class="mt-4 flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between"
            :class="checkoutState.tone === 'success' ? 'border-success-200 bg-success-50/70' : 'border-warning-200 bg-warning-50/70'"
        >
            <div class="flex items-start gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl" :class="checkoutState.tone === 'success' ? 'bg-success-100 text-success-700' : 'bg-warning-100 text-warning-700'">
                    <Check v-if="checkoutState.tone === 'success'" class="h-5 w-5" />
                    <CircleAlert v-else class="h-5 w-5" />
                </span>
                <div><p class="font-semibold" :class="checkoutState.tone === 'success' ? 'text-success-800' : 'text-warning-800'">{{ checkoutState.title }}</p><p class="mt-0.5 text-sm" :class="checkoutState.tone === 'success' ? 'text-success-700' : 'text-warning-700'">{{ checkoutState.description }}</p></div>
            </div>
            <Button variant="primary" :disabled="hasOpenOrders" @click="openCheckout">{{ $t('reservationShow.completeCheckout') }} <ArrowRight class="h-4 w-4" /></Button>
        </section>

        <div class="mt-4 grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
            <!-- Reservation details -->
            <Card class="lg:order-2 lg:col-span-1">
                <div class="mb-4 flex items-start justify-between gap-3 border-b border-neutral-100 pb-4">
                    <div><h3 class="text-lg font-semibold text-neutral-900">{{ $t('reservationShow.stayDetails') }}</h3><p class="mt-1 text-xs text-neutral-500">{{ $t('reservationShow.stayDetailsSubtitle') }}</p></div>
                    <span class="inline-flex items-center gap-1.5 rounded-lg bg-neutral-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-neutral-700"><span class="h-1.5 w-1.5 rounded-full" :style="{ backgroundColor: channelMeta(reservation.channel).color }" />{{ channelMeta(reservation.channel).label }}</span>
                </div>
                <dl class="space-y-3">
                    <div class="flex justify-between">
                        <dt class="text-body-sm text-neutral-500">{{ $t('admin.generated.k_93eeb8e2c428') }}</dt>
                        <dd class="text-body-sm font-medium text-right">
                            <Link v-if="reservation.links?.guest" :href="reservation.links.guest" class="text-primary-900 no-underline hover:text-accent-700">{{ reservation.guest?.name }}</Link>
                            <span v-else class="text-primary-900">{{ reservation.guest?.name }}</span>
                        </dd>
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
                        <dd class="text-body-sm text-right">
                            <Link v-if="reservation.links?.room" :href="reservation.links.room" class="text-primary-900 no-underline hover:text-accent-700">{{ reservation.room?.room_number }} — {{ reservation.room?.room_type }}</Link>
                            <span v-else class="text-primary-900">{{ reservation.room?.room_number }} — {{ reservation.room?.room_type }}</span>
                        </dd>
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
            <Card class="lg:order-1 lg:col-span-2" :padding="false">
                <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4">
                    <div><h3 class="text-lg font-semibold text-neutral-900">{{ $t('reservationShow.folioTitle') }}</h3><p class="mt-1 text-xs text-neutral-500">{{ $t('reservationShow.folioSubtitle') }}</p></div>
                    <div class="flex items-center gap-2">
                        <Button v-if="canUpdate && isCheckedIn && inventoryEnabled && inventoryItems.length" size="sm" variant="primary" @click="openMinibarModal"><PackageOpen class="h-4 w-4" />Shto minibar</Button>
                        <Button v-if="canAddCharge" size="sm" variant="outline" @click="openLineModal"><Plus class="h-4 w-4" />{{ $t('reservationShow.addCharge') }}</Button>
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

                <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-[10px] font-semibold uppercase tracking-wider text-neutral-500">{{ $t('reservationShow.description') }}</th>
                            <th class="px-5 py-3 text-left text-[10px] font-semibold uppercase tracking-wider text-neutral-500">{{ $t('reservationShow.type') }}</th>
                            <th class="px-5 py-3 text-left text-[10px] font-semibold uppercase tracking-wider text-neutral-500">{{ $t('reservationShow.date') }}</th>
                            <th class="px-5 py-3 text-right text-[10px] font-semibold uppercase tracking-wider text-neutral-500">{{ $t('reservationShow.amount') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr>
                            <td class="px-5 py-3 text-body-sm text-primary-900 font-medium">{{ $t('reservationShow.roomStay') }}<p class="mt-0.5 text-xs font-normal text-neutral-400">{{ reservation.nights }} {{ $t('reservationShow.nights') }} · {{ $t('reservationShow.room') }} {{ reservation.room?.room_number }}</p></td>
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
                </div>

                <!-- Payments -->
                <div v-if="payments.length" class="border-t border-neutral-200">
                    <ul class="divide-y divide-neutral-100">
                        <li v-for="p in payments" :key="p.id" class="flex justify-between px-5 py-3 text-body-sm">
                            <Link v-if="reservation.links?.finance" :href="reservation.links.finance" class="text-neutral-600 no-underline hover:text-accent-700">{{ methodLabel[p.method] || p.method }} · {{ formatDate(p.date) }}</Link>
                            <span v-else class="text-neutral-600">{{ methodLabel[p.method] || p.method }} · {{ formatDate(p.date) }}</span>
                            <span class="text-success-600">− {{ money(p.amount) }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Summary -->
                <div class="space-y-2 border-t border-neutral-200 px-5 py-4">
                    <div class="flex justify-between text-body-sm text-neutral-500">
                        <span>{{ $t('reservationShow.subtotal') }}</span>
                        <span>{{ money(folio.net) }}</span>
                    </div>
                    <div class="flex justify-between text-body-sm text-neutral-500">
                        <span>{{ $t('reservationShow.vat') }} ({{ folio.taxRate }}%)</span>
                        <span>{{ money(folio.taxAmount) }}</span>
                    </div>
                    <div v-if="folio.discounts > 0" class="flex justify-between text-body-sm text-success-600">
                        <span>{{ $t('admin.generated.k_43e94d66754b') }}</span>
                        <span>− {{ money(folio.discounts) }}</span>
                    </div>
                    <div class="flex justify-between text-body-sm text-neutral-700 border-t border-neutral-100 pt-2">
                        <span>{{ $t('reservationShow.total') }}</span>
                        <span>{{ money(folio.gross) }}</span>
                    </div>
                    <div class="flex justify-between text-body-sm text-neutral-500">
                        <span>{{ $t('reservationShow.paid') }}</span>
                        <span>− {{ money(folio.paid) }}</span>
                    </div>
                    <div v-if="reservation.status !== 'cancelled'" class="mt-3 flex items-center justify-between rounded-xl p-3" :class="unsettled ? 'bg-warning-50' : 'bg-success-50'">
                        <span class="font-semibold text-neutral-800">{{ $t('reservationShow.outstanding') }}</span>
                        <span class="text-h4" :class="unsettled ? 'text-error-600' : 'text-success-600'">{{ money(folio.outstanding) }}</span>
                    </div>
                    <div v-else class="flex justify-between border-t border-neutral-200 pt-2">
                        <span class="text-label text-neutral-700">{{ $t('admin.generated.k_68618a4a4a50') }}</span>
                        <span class="text-h4 text-neutral-400">{{ $t('admin.generated.k_060f31a189a6') }}</span>
                    </div>
                </div>
            </Card>
        </div>

        <div class="mt-4 grid items-start gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
            <Card class="lg:order-2">
                <div class="mb-3"><h3 class="text-lg font-semibold text-neutral-900">{{ $t('reservationShow.quickLinks') }}</h3><p class="mt-1 text-xs text-neutral-500">{{ $t('reservationShow.quickLinksSubtitle') }}</p></div>
                <div class="space-y-1">
                    <Link v-if="reservation.links?.guest" :href="reservation.links.guest" class="flex items-center gap-3 rounded-xl p-2.5 text-sm font-medium text-primary-900 no-underline transition hover:bg-neutral-50"><span class="grid h-9 w-9 place-items-center rounded-xl bg-emerald-50 text-emerald-700"><UserRound class="h-4 w-4" /></span>{{ $t('reservationShow.guestProfile') }}<ArrowRight class="ml-auto h-4 w-4 text-neutral-400" /></Link>
                    <Link v-if="reservation.links?.room" :href="reservation.links.room" class="flex items-center gap-3 rounded-xl p-2.5 text-sm font-medium text-primary-900 no-underline transition hover:bg-neutral-50"><span class="grid h-9 w-9 place-items-center rounded-xl bg-emerald-50 text-emerald-700"><DoorOpen class="h-4 w-4" /></span>{{ $t('reservationShow.room') }} {{ reservation.room?.room_number }}<ArrowRight class="ml-auto h-4 w-4 text-neutral-400" /></Link>
                    <Link v-if="reservation.links?.finance" :href="reservation.links.finance" class="flex items-center gap-3 rounded-xl p-2.5 text-sm font-medium text-primary-900 no-underline transition hover:bg-neutral-50"><span class="grid h-9 w-9 place-items-center rounded-xl bg-emerald-50 text-emerald-700"><CreditCard class="h-4 w-4" /></span>{{ $t('reservationShow.financePayments') }}<ArrowRight class="ml-auto h-4 w-4 text-neutral-400" /></Link>
                    <Link :href="route('reservations.calendar')" class="flex items-center gap-3 rounded-xl p-2.5 text-sm font-medium text-primary-900 no-underline transition hover:bg-neutral-50"><span class="grid h-9 w-9 place-items-center rounded-xl bg-emerald-50 text-emerald-700"><CalendarDays class="h-4 w-4" /></span>{{ $t('reservationShow.calendar') }}<ArrowRight class="ml-auto h-4 w-4 text-neutral-400" /></Link>
                </div>
            </Card>
            <Card class="lg:order-1" :padding="false">
                <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4">
                    <div><h3 class="text-lg font-semibold text-neutral-900">{{ $t('reservationShow.history') }}</h3><p class="mt-1 text-xs text-neutral-500">{{ $t('reservationShow.historySubtitle') }}</p></div>
                    <span class="rounded-full bg-neutral-100 px-2.5 py-1 text-xs font-semibold text-neutral-600">{{ history.length }} {{ $t('reservationShow.actions') }}</span>
                </div>
                <AuditTimeline :entries="history" />
            </Card>
        </div>

        <!-- Inventory-backed minibar: folio charge and stock movement are one transaction. -->
        <Modal :show="showMinibarModal" :title="$t('reservationShow.minibarTitle')" max-width="md" :closeable="!minibarForm.processing" @close="closeMinibarModal">
            <form class="space-y-4" @submit.prevent="submitMinibar">
                <div class="rounded-lg border border-accent-100 bg-accent-50/60 px-3 py-2.5 text-small text-accent-900">{{ $t('reservationShow.minibarHint') }}</div>
                <div class="grid gap-3 sm:grid-cols-[72px_1fr]">
                    <div class="grid h-[72px] w-[72px] place-items-center overflow-hidden rounded-xl border border-neutral-200 bg-neutral-50">
                        <img v-if="selectedMinibarItem?.image_path" :src="`/storage/${selectedMinibarItem.image_path}`" :alt="selectedMinibarItem.name" class="h-full w-full object-cover" />
                        <PackageOpen v-else class="h-6 w-6 text-neutral-300" />
                    </div>
                    <FormGroup :label="$t('reservationShow.inventoryItem')" :error="minibarForm.errors.inventory_item_id" required>
                        <Select v-model="minibarForm.inventory_item_id" :options="minibarItemOptions" :error="minibarForm.errors.inventory_item_id" />
                    </FormGroup>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormGroup :label="$t('reservationShow.warehouse')" :error="minibarForm.errors.warehouse_id" required>
                        <div class="rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2 text-body-sm font-medium text-neutral-700">{{ selectedMinibarWarehouse?.name || '—' }}</div>
                    </FormGroup>
                    <FormGroup :label="$t('reservationShow.quantity')" :error="minibarForm.errors.quantity" required>
                        <TextInput v-model="minibarForm.quantity" type="number" min="0.0001" :max="minibarAvailable" step="0.0001" :error="minibarForm.errors.quantity" />
                    </FormGroup>
                </div>
                <div class="grid grid-cols-2 gap-3 rounded-xl border border-neutral-200 bg-neutral-50 p-3 text-body-sm">
                    <div><p class="text-neutral-500">{{ $t('reservationShow.availableStock') }}</p><p class="mt-1 font-semibold" :class="minibarAvailable > 0 ? 'text-success-700' : 'text-error-600'">{{ minibarAvailable }} {{ selectedMinibarItem?.unit || '' }}</p></div>
                    <div class="text-right"><p class="text-neutral-500">{{ $t('reservationShow.folioTotal') }}</p><p class="mt-1 font-semibold text-primary-900">{{ money(minibarTotal) }}</p></div>
                </div>
                <p v-if="minibarQuantity > minibarAvailable" class="text-small font-medium text-error-600">{{ $t('reservationShow.insufficientStock') }}</p>
            </form>
            <template #footer>
                <Button variant="outline" :disabled="minibarForm.processing" @click="closeMinibarModal">{{ $t('admin.generated.k_1ae76507a0e9') }}</Button>
                <Button variant="primary" :loading="minibarForm.processing" :disabled="!minibarCanSubmit" @click="submitMinibar">{{ $t('reservationShow.postMinibar') }}</Button>
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
                    <FormGroup :label="lineForm.type === 'discount' ? 'Shuma e zbritjes' : 'Shuma'" :error="lineForm.errors.amount" required>
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
        <Modal :show="showPayModal" :title="$t('admin.generated.k_d38fb40fdcb5')" max-width="md" :closeable="!paymentSubmitting" @close="closePaymentModal">
            <form @submit.prevent="submitPay" class="space-y-5">
                <div class="rounded-lg border border-accent-100 bg-accent-50/60 p-4">
                    <div class="grid grid-cols-2 gap-4 text-body-sm">
                        <div>
                            <p class="text-neutral-500">Totali i rezervimit</p>
                            <p class="mt-1 font-semibold text-neutral-900">{{ money(folio.gross) }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-neutral-500">Paguar deri tani</p>
                            <p class="mt-1 font-semibold text-success-700">{{ money(folio.paid) }}</p>
                        </div>
                    </div>
                    <div class="mt-3 flex items-center justify-between border-t border-accent-100 pt-3">
                        <span class="font-medium text-neutral-700">Mbetur për t'u paguar</span>
                        <span class="text-h4 text-error-600">{{ money(folio.outstanding) }}</span>
                    </div>
                </div>

                <FormGroup :label="$t('admin.generated.k_522d709a6d49')" :error="payForm.errors.amount" required>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 font-semibold text-neutral-500">{{ currency }}</span>
                        <TextInput
                            v-model="payForm.amount"
                            type="number"
                            step="0.01"
                            min="0.01"
                            :max="folio.outstanding"
                            inputmode="decimal"
                            class="pl-8 text-lg font-semibold"
                            placeholder="0.00"
                            :error="payForm.errors.amount"
                        />
                    </div>
                    <button
                        type="button"
                        class="mt-2 text-body-sm font-medium text-accent-700 hover:text-accent-800"
                        @click="payForm.amount = Number(folio.outstanding).toFixed(2)"
                    >
                        Paguaj të gjithë shumën
                    </button>
                </FormGroup>

                <FormGroup :label="$t('admin.generated.k_de3b5772305c')" :error="payForm.errors.method" required>
                    <div class="grid grid-cols-2 gap-3">
                        <button
                            v-for="option in methodOptions"
                            :key="option.value"
                            type="button"
                            :class="[
                                'flex items-center gap-3 rounded-lg border p-3 text-left transition-colors',
                                payForm.method === option.value
                                    ? 'border-accent-500 bg-accent-50 text-accent-800 ring-1 ring-accent-500'
                                    : 'border-neutral-200 text-neutral-700 hover:border-neutral-300 hover:bg-neutral-50',
                            ]"
                            @click="payForm.method = option.value"
                        >
                            <Banknote v-if="option.value === 'cash'" class="h-5 w-5 shrink-0" />
                            <CreditCard v-else class="h-5 w-5 shrink-0" />
                            <span class="font-medium">{{ option.label }}</span>
                        </button>
                    </div>
                </FormGroup>

                <div class="flex items-start gap-2 text-tiny text-neutral-500">
                    <ShieldCheck class="mt-0.5 h-4 w-4 shrink-0 text-success-600" />
                    <span>Pagesa ruhet vetëm një herë dhe zbritet menjëherë nga balanca e rezervimit.</span>
                </div>
            </form>
            <template #footer>
                <Button variant="outline" :disabled="paymentSubmitting" @click="closePaymentModal">{{ $t('admin.generated.k_1ae76507a0e9') }}</Button>
                <Button variant="success" :loading="paymentSubmitting" :disabled="!paymentIsValid" @click="submitPay">
                    Regjistro {{ paymentIsValid ? money(paymentAmount) : 'pagesën' }}
                </Button>
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
