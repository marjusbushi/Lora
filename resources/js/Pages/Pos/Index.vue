<script setup>
import { getIntlLocale, i18n, translate } from '@/i18n';
import { ref, computed, nextTick } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Modal from '@/Components/UI/Modal.vue';
import Select from '@/Components/UI/Select.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';
import ShiftBanner from '@/Components/Pos/ShiftBanner.vue';
import PosReceipt from '@/Components/Invoices/PosReceipt.vue';
import { Minus, Pencil, Plus, ReceiptText, RotateCcw, Search, ShoppingCart, Star, Trash2, X } from 'lucide-vue-next';

const props = defineProps({
    orders: Object,
    menu: Array,
    activeReservations: Array,
    filters: Object,
    stats: Object,
    currentShift: { type: Object, default: null },
    canOpenShift: { type: Boolean, default: false },
    canCloseShift: { type: Boolean, default: false },
    defaultOpeningFloat: { type: Number, default: 0 },
    receiptSettings: { type: Object, default: () => ({}) },
});

const toasts = ref(null);
const showPayModal = ref(false);
const showOrdersPanel = ref(Boolean(props.filters?.order_id));
const selectedOrder = ref(null);
const editingOrderId = ref(null);
const showReceipt = ref(false);
const receiptOrder = ref(null);
const fiscalizingOrder = ref(null);
const activeCategory = ref(props.menu?.[0]?.id || null);
const searchQuery = ref('');
const serviceMode = ref('table');

// Cart
const cart = ref([]);
const tableNumber = ref('');
const selectedReservation = ref('');

const reservationOptions = props.activeReservations.map((r) => ({
    value: r.id,
    label: `Dhoma ${r.room?.room_number} — ${r.guest?.first_name} ${r.guest?.last_name}`,
}));

const paymentOptions = [
    { value: 'cash', label: translate('admin.generated.k_a378b744f8ce') },
    { value: 'card', label: translate('admin.generated.k_94a332f07750') },
    { value: 'room_charge', label: translate('admin.generated.k_31417756fe7f') },
];
const paymentMethod = ref('');
const selectedPayReservation = ref('');
const discountType = ref('none');
const discountValue = ref('');
const discountReason = ref('');
const splitCashAmount = ref('');
const showCancelModal = ref(false);
const showRefundModal = ref(false);
const actionOrder = ref(null);
const actionReason = ref('');

// ===== Cash-drawer shift (hapje/mbyllje turni) =====
const showOpenShift = ref(false);
const showCloseShift = ref(false);
const hasOpenShift = computed(() => !!props.currentShift);

const openShiftForm = useForm({ opening_float: props.defaultOpeningFloat ?? 0 });
const closeShiftForm = useForm({ counted_cash: '', closing_note: '' });

function money(v) {
    return new Intl.NumberFormat(getIntlLocale(), {
        style: 'currency', currency: props.receiptSettings.currency || 'EUR',
    }).format(Number(v ?? 0));
}

function submitOpenShift() {
    openShiftForm.post(route('pos.shift.open'), {
        preserveScroll: true,
        onSuccess: () => { showOpenShift.value = false; toasts.value?.success(translate('admin.generated.k_e69c80a44157')); },
        onError: () => toasts.value?.error(translate('admin.generated.k_384ff02204f8')),
    });
}

function openCloseModal() {
    closeShiftForm.reset();
    closeShiftForm.clearErrors();
    showCloseShift.value = true;
}

const expectedCash = computed(() => Number(props.currentShift?.expected_cash ?? 0));
const totalSales = computed(() => {
    const s = props.currentShift;
    if (!s) return 0;
    return Number(s.cash_sales) + Number(s.card_sales) + Number(s.room_charge_sales);
});
const countedNum = computed(() => {
    const v = parseFloat(closeShiftForm.counted_cash);
    return isNaN(v) ? null : v;
});
const variance = computed(() =>
    countedNum.value === null ? null : Math.round((countedNum.value - expectedCash.value) * 100) / 100
);
const varianceLabel = computed(() => {
    if (variance.value === null) return '';
    if (Math.abs(variance.value) < 0.01) return translate('admin.generated.k_2445edbe9bf2');
    if (variance.value < 0) return translate('admin.generated.k_f7bfd0e453ec', { p0: Math.abs(variance.value).toFixed(2) });
    return translate('admin.generated.k_b6f99488d300', { p0: variance.value.toFixed(2) });
});
const varianceClass = computed(() => {
    if (variance.value === null) return 'text-neutral-400';
    if (Math.abs(variance.value) < 0.01) return 'text-success-600';
    if (variance.value < 0) return 'text-error-600';
    return 'text-warning-600';
});

function submitCloseShift() {
    if (countedNum.value === null) { toasts.value?.error(translate('admin.generated.k_af8603fe2aff')); return; }
    closeShiftForm.post(route('pos.shift.close', props.currentShift.id), {
        preserveScroll: true,
        onSuccess: () => { showCloseShift.value = false; toasts.value?.success(translate('admin.generated.k_f49b27350297')); },
        onError: () => toasts.value?.error(translate('admin.generated.k_59a4e2c1c1c1')),
    });
}

function printZReport() {
    document.body.classList.add('printing-z-report');
    window.print();
    window.setTimeout(() => document.body.classList.remove('printing-z-report'), 500);
}

const cartTotal = computed(() =>
    cart.value.reduce((sum, item) => sum + item.price * item.qty, 0)
);

const cartCount = computed(() =>
    cart.value.reduce((sum, item) => sum + item.qty, 0)
);

const paymentSubtotal = computed(() => Number(selectedOrder.value?.subtotal_amount || selectedOrder.value?.total_amount || 0));
const paymentDiscount = computed(() => {
    if (discountType.value === 'complimentary') return paymentSubtotal.value;
    const value = Math.max(0, Number(discountValue.value || 0));
    if (discountType.value === 'percent') return Math.min(paymentSubtotal.value, paymentSubtotal.value * Math.min(value, 100) / 100);
    if (discountType.value === 'fixed') return Math.min(paymentSubtotal.value, value);
    return 0;
});
const paymentTotal = computed(() => Math.max(0, Math.round((paymentSubtotal.value - paymentDiscount.value) * 100) / 100));
const splitCash = computed(() => Math.min(paymentTotal.value, Math.max(0, Number(splitCashAmount.value || 0))));
const splitCard = computed(() => Math.round((paymentTotal.value - splitCash.value) * 100) / 100);

const activeMenuItems = computed(() => {
    const allItems = (props.menu || []).flatMap((category) =>
        (category.items || []).map((item) => ({ ...item, category_name: category.name }))
    );
    const query = searchQuery.value.trim().toLocaleLowerCase('sq');
    const categoryItems = activeCategory.value === 'frequent'
        ? [...allItems]
            .filter((item) => Number(item.sales_count || 0) > 0)
            .sort((a, b) => Number(b.sales_count || 0) - Number(a.sales_count || 0))
            .slice(0, 10)
        : (props.menu?.find((category) => category.id === activeCategory.value)?.items || []);
    if (!query) return categoryItems;
    return allItems.filter((item) => item.name?.toLocaleLowerCase('sq').includes(query));
});

const hasFrequentItems = computed(() => (props.menu || [])
    .some((category) => (category.items || []).some((item) => Number(item.sales_count || 0) > 0)));

function switchService(mode) {
    serviceMode.value = mode;
    if (mode === 'table') selectedReservation.value = '';
    else tableNumber.value = '';
}

// Emoji icons per category
const categoryIcons = {
    'Pije': '🍹',
    'Ushqim': '🍽️',
    'Dessert': '🍰',
};

// Placeholder images per item based on category
function getItemImage(item) {
    if (item.image_path) return `/storage/${item.image_path}`;
    return null;
}

function getItemEmoji(item) {
    const name = item.name?.toLowerCase() || '';
    if (name.includes('espresso') || name.includes('cappuccino') || name.includes('kafe')) return '☕';
    if (name.includes('caj')) return '🍵';
    if (name.includes('leng') || name.includes('portokall')) return '🍊';
    if (name.includes('bire') || name.includes('birr')) return '🍺';
    if (name.includes('vere')) return '🍷';
    if (name.includes('uje')) return '💧';
    if (name.includes('sandvic') || name.includes('burger')) return '🍔';
    if (name.includes('salat')) return '🥗';
    if (name.includes('pasta') || name.includes('carbonara')) return '🍝';
    if (name.includes('pizza')) return '🍕';
    if (name.includes('tiramisu')) return '🍫';
    if (name.includes('akullore')) return '🍨';
    if (name.includes('panna')) return '🍮';
    return '🍽️';
}

function addToCart(menuItem) {
    if (!hasOpenShift.value) { toasts.value?.error(translate('admin.generated.k_d4d2e4579cbb')); return; }
    const existing = cart.value.find((c) => c.id === menuItem.id);
    if (menuItem.inventory_tracked && menuItem.available_portions !== null
        && Number(existing?.qty || 0) >= Math.max(0, Number(menuItem.available_portions))) {
        toasts.value?.error(i18n.global.t('inventory.pos.insufficient'));
        return;
    }
    if (existing) {
        existing.qty++;
    } else {
        cart.value.push({
            id: menuItem.id,
            name: menuItem.name,
            price: parseFloat(menuItem.price),
            qty: 1,
            emoji: getItemEmoji(menuItem),
            inventory_tracked: menuItem.inventory_tracked,
            available_portions: menuItem.available_portions,
        });
    }
}

function removeFromCart(index) {
    cart.value.splice(index, 1);
}

function updateQty(index, delta) {
    if (delta > 0 && cart.value[index].inventory_tracked && cart.value[index].available_portions !== null
        && cart.value[index].qty >= Math.max(0, Number(cart.value[index].available_portions))) {
        toasts.value?.error(i18n.global.t('inventory.pos.insufficient'));
        return;
    }
    cart.value[index].qty += delta;
    if (cart.value[index].qty <= 0) cart.value.splice(index, 1);
}

function clearCart() {
    cart.value = [];
    tableNumber.value = '';
    selectedReservation.value = '';
    serviceMode.value = 'table';
    editingOrderId.value = null;
}

function submitOrder() {
    if (!cart.value.length) return;
    if (!hasOpenShift.value) { toasts.value?.error(translate('admin.generated.k_d4d2e4579cbb')); return; }
    const form = useForm({
        table_number: serviceMode.value === 'table' ? tableNumber.value || null : null,
        reservation_id: serviceMode.value === 'room' ? selectedReservation.value || null : null,
        items: cart.value.map((c) => ({ menu_item_id: c.id, quantity: c.qty })),
    });

    const submittedTotal = cartTotal.value;
    const isEditing = Boolean(editingOrderId.value);
    const options = {
        onSuccess: () => {
            clearCart();
            toasts.value?.success(`${isEditing ? 'Porosia u përditësua' : 'Porosia u krijua'} — ${money(submittedTotal)}`);
        },
        onError: (errors) => toasts.value?.error(errors.inventory || errors.order || 'Porosia nuk u ruajt.'),
    };
    if (editingOrderId.value) form.put(route('pos.update', editingOrderId.value), options);
    else form.post(route('pos.store'), options);
}

function editOrder(order) {
    const allMenuItems = (props.menu || []).flatMap(category => category.items || []);
    cart.value = order.items.map(line => {
        const menuItem = allMenuItems.find(item => Number(item.id) === Number(line.menu_item_id));
        return {
            id: line.menu_item_id,
            name: line.menu_item?.name || menuItem?.name || 'Artikull',
            price: Number(line.unit_price),
            qty: Number(line.quantity),
            emoji: getItemEmoji(menuItem || line.menu_item || {}),
            inventory_tracked: Boolean(menuItem?.inventory_tracked),
            available_portions: menuItem?.available_portions === null || menuItem?.available_portions === undefined
                ? null
                : Number(menuItem.available_portions) + Number(line.quantity),
        };
    });
    tableNumber.value = order.table_number || '';
    selectedReservation.value = order.reservation_id || '';
    serviceMode.value = order.reservation_id ? 'room' : 'table';
    editingOrderId.value = order.id;
    showOrdersPanel.value = false;
}

function openPay(order) {
    if (!hasOpenShift.value) { toasts.value?.error(translate('admin.generated.k_d4d2e4579cbb')); return; }
    selectedOrder.value = order;
    paymentMethod.value = '';
    discountType.value = 'none';
    discountValue.value = '';
    discountReason.value = '';
    splitCashAmount.value = '';
    selectedPayReservation.value = order.reservation_id || '';
    showPayModal.value = true;
}

function submitPay() {
    const orderId = selectedOrder.value.id;
    const payments = [];
    if (paymentTotal.value > 0) {
        if (paymentMethod.value === 'split') {
            if (splitCash.value > 0) payments.push({ method: 'cash', amount: splitCash.value });
            if (splitCard.value > 0) payments.push({ method: 'card', amount: splitCard.value });
        } else if (paymentMethod.value) {
            payments.push({ method: paymentMethod.value, amount: paymentTotal.value });
        }
    }
    router.post(route('pos.complete', selectedOrder.value.id), {
        payments,
        reservation_id: paymentMethod.value === 'room_charge' ? selectedPayReservation.value : null,
        discount_amount: paymentDiscount.value,
        discount_reason: paymentDiscount.value > 0 ? discountReason.value : null,
        complimentary: discountType.value === 'complimentary',
    }, {
        preserveScroll: true,
        onSuccess: (page) => {
            showPayModal.value = false;
            const finalized = page.props.orders?.data?.find((order) => Number(order.id) === Number(orderId));
            if (finalized) openReceipt(finalized);
            const error = page.props.flash?.error;
            if (error) toasts.value?.error(error);
            else toasts.value?.success(translate('admin.generated.k_4d1af80f8706'));
        },
        onError: (errors) => {
            toasts.value?.error(errors.inventory || errors.payments || errors.discount_reason || errors.reservation_id || 'Pagesa nuk u regjistrua.');
        },
    });
}

function openReceipt(order) {
    receiptOrder.value = order;
    showReceipt.value = true;
}

function printReceipt() {
    document.body.classList.add('printing-pos-receipt');
    window.print();
    window.setTimeout(() => document.body.classList.remove('printing-pos-receipt'), 500);
}

function canFiscalize(order) {
    return order?.status === 'completed'
        && !order?.refunded_at
        && ['cash', 'card'].includes(order?.payment_method)
        && order?.fiscal_document?.status !== 'fiscalized';
}

function fiscalizeReceipt(order) {
    fiscalizingOrder.value = order.id;
    router.post(route('pos.fiscalize', order.id), {}, {
        preserveScroll: true,
        onSuccess: (page) => {
            const updated = page.props.orders?.data?.find((item) => Number(item.id) === Number(order.id));
            if (updated) openReceipt(updated);
            toasts.value?.success(translate('invoicePrint.posFiscalSuccess'));
            nextTick(() => printReceipt());
        },
        onError: (errors) => toasts.value?.error(errors.fiscalization || translate('invoicePrint.posFiscalFailed')),
        onFinish: () => { fiscalizingOrder.value = null; },
    });
}

function openCancel(order) {
    actionOrder.value = order;
    actionReason.value = '';
    showCancelModal.value = true;
}

function submitCancel() {
    router.post(route('pos.cancel', actionOrder.value.id), { reason: actionReason.value }, {
        preserveScroll: true,
        onSuccess: () => { showCancelModal.value = false; toasts.value?.success(translate('admin.generated.k_0d9b1bd67bed')); },
        onError: (errors) => toasts.value?.error(errors.reason || 'Anulimi nuk u regjistrua.'),
    });
}

function openRefund(order) {
    actionOrder.value = order;
    actionReason.value = '';
    showRefundModal.value = true;
}

function submitRefund() {
    router.post(route('pos.refund', actionOrder.value.id), { reason: actionReason.value }, {
        preserveScroll: true,
        onSuccess: () => { showRefundModal.value = false; toasts.value?.success('Rimbursimi dhe kthimi i stokut u regjistruan.'); },
        onError: (errors) => toasts.value?.error(errors.reason || errors.refund || 'Rimbursimi nuk u regjistrua.'),
    });
}

const statusBadge = {
    open: { variant: 'warning', label: translate('admin.generated.k_35a3565ef9b7') },
    completed: { variant: 'success', label: translate('admin.generated.k_5a7f6ed24307') },
    cancelled: { variant: 'error', label: translate('admin.generated.k_a870d7f3f846') },
    refunded: { variant: 'neutral', label: 'Rimbursuar' },
};

const payLabel = { cash: 'Cash', card: 'Kartë', room_charge: 'Në dhomë' };

function orderPaymentLabel(order) {
    const methods = [...new Set((order.payments || []).filter(payment => payment.direction === 'in').map(payment => payment.method))];
    if (methods.length > 1) return 'Cash + Kartë';
    return payLabel[methods[0] || order.payment_method] || (order.is_complimentary ? 'Komplimentare' : '—');
}

function formatTime(d) {
    return new Date(d).toLocaleTimeString(getIntlLocale(), { hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <AppLayout>
        <ShiftBanner
            :shift="currentShift"
            :can-open="canOpenShift"
            :can-close="canCloseShift"
            class="mb-4"
            @open="showOpenShift = true"
            @close="openCloseModal"
        />
        <div class="mb-5 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div>
                <h1 class="text-h2 text-primary-900">POS Bar/Restaurant</h1>
                <p class="mt-1 text-body-sm text-neutral-500">Shërbim i shpejtë në banak, tavolinë ose dhomë.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="rounded-xl border border-neutral-200 bg-white px-4 py-2 shadow-card">
                    <p class="text-tiny font-semibold uppercase tracking-wide text-neutral-400">Hapur</p>
                    <p class="text-h4 text-warning-700">{{ stats.open }}</p>
                </div>
                <div class="rounded-xl border border-neutral-200 bg-white px-4 py-2 shadow-card">
                    <p class="text-tiny font-semibold uppercase tracking-wide text-neutral-400">Përfunduar sot</p>
                    <p class="text-h4 text-success-700">{{ stats.today_completed }}</p>
                </div>
                <div class="rounded-xl border border-neutral-200 bg-white px-4 py-2 shadow-card">
                    <p class="text-tiny font-semibold uppercase tracking-wide text-neutral-400">Shitje sot</p>
                    <p class="text-h4 text-accent-700">{{ money(stats.today_revenue) }}</p>
                </div>
                <Button variant="outline" class="h-[58px]" @click="showOrdersPanel = true">
                    <ReceiptText class="h-4 w-4" /> Porositë e hapura
                    <span class="rounded-md bg-warning-50 px-1.5 py-0.5 text-tiny font-semibold text-warning-700">{{ stats.open }}</span>
                </Button>
            </div>
        </div>

        <div class="flex h-full flex-col gap-5 xl:flex-row">
            <!-- LEFT: Menu area -->
            <div class="flex-1 min-w-0">
                <!-- Orders Panel (toggle) -->
                <Teleport to="body">
                    <Transition enter-active-class="duration-200 ease-out" enter-from-class="opacity-0" leave-active-class="duration-150 ease-in" leave-to-class="opacity-0">
                        <div v-if="showOrdersPanel" class="fixed inset-0 z-40 bg-neutral-950/35" @click="showOrdersPanel = false" />
                    </Transition>
                    <Transition enter-active-class="duration-200 ease-out" enter-from-class="translate-x-full" leave-active-class="duration-200 ease-in" leave-to-class="translate-x-full">
                    <aside v-if="showOrdersPanel" class="fixed inset-y-0 right-0 z-50 flex w-full max-w-2xl flex-col bg-white shadow-2xl">
                        <div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4">
                            <div><h2 class="text-h3 text-primary-900">Porositë</h2><p class="mt-0.5 text-small text-neutral-500">Hap, arkëto ose anulo porositë pa humbur shportën aktuale.</p></div>
                            <button type="button" class="rounded-lg p-2 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" @click="showOrdersPanel = false"><X class="h-5 w-5" /></button>
                        </div>
                        <Card :padding="false" class="m-5 min-h-0 flex-1 overflow-auto">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-neutral-200">
                                <thead class="bg-neutral-50">
                                    <tr>
                                        <th class="px-4 py-2.5 text-left text-label text-neutral-600">#</th>
                                        <th class="px-4 py-2.5 text-left text-label text-neutral-600">{{ $t('admin.generated.k_7b1cf144b998') }}</th>
                                        <th class="px-4 py-2.5 text-left text-label text-neutral-600">{{ $t('admin.generated.k_2ade15d943c4') }}</th>
                                        <th class="px-4 py-2.5 text-left text-label text-neutral-600">{{ $t('admin.generated.k_d936f6a10e13') }}</th>
                                        <th class="px-4 py-2.5 text-right text-label text-neutral-600">{{ $t('admin.generated.k_85f1cb8f5091') }}</th>
                                        <th class="px-4 py-2.5 text-right text-label text-neutral-600"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-neutral-100">
                                    <tr v-for="order in orders.data" :key="order.id" class="hover:bg-neutral-50">
                                        <td class="px-4 py-2.5 text-body-sm text-neutral-500">#{{ order.id }}</td>
                                        <td class="px-4 py-2.5 text-body-sm text-neutral-700">{{ formatTime(order.created_at) }}</td>
                                        <td class="px-4 py-2.5 text-body-sm text-neutral-600 max-w-48 truncate">
                                            {{ order.items?.map(i => i.menu_item?.name).join(', ') || '—' }}
                                        </td>
                                        <td class="px-4 py-2.5">
                                            <Badge :variant="statusBadge[order.effective_status]?.variant" dot size="sm">
                                                {{ statusBadge[order.effective_status]?.label }}
                                            </Badge>
                                        </td>
                                        <td class="px-4 py-2.5 text-right text-body-sm font-medium">{{ money(order.total_amount) }}</td>
                                        <td class="px-4 py-2.5 text-right">
                                            <div v-if="order.status === 'open'" class="flex justify-end gap-1">
                                                <Button size="sm" variant="outline" :disabled="!hasOpenShift" @click="editOrder(order)"><Pencil class="h-3.5 w-3.5" /> Ndrysho</Button>
                                                <Button size="sm" variant="primary" :disabled="!hasOpenShift" @click="openPay(order)">{{ $t('admin.generated.k_c0bc68ffb628') }}</Button>
                                                <Button size="sm" variant="ghost" class="text-error-600" @click="openCancel(order)">{{ $t('admin.generated.k_28cc20e7fd5b') }}</Button>
                                            </div>
                                            <div v-else-if="order.status === 'completed'" class="flex flex-wrap justify-end gap-1.5">
                                                <Badge variant="neutral" size="sm">{{ orderPaymentLabel(order) }}</Badge>
                                                <Button
                                                    v-if="canFiscalize(order)"
                                                    size="sm"
                                                    variant="outline"
                                                    :loading="fiscalizingOrder === order.id"
                                                    @click="fiscalizeReceipt(order)"
                                                >{{ $t('invoicePrint.fiscalize') }}</Button>
                                                <Button size="sm" variant="outline" @click="openReceipt(order)">
                                                    <ReceiptText class="h-3.5 w-3.5" /> {{ $t('reservationShow.invoice') }}
                                                </Button>
                                                <Button v-if="!order.refunded_at" size="sm" variant="ghost" class="text-error-600" :disabled="!hasOpenShift" @click="openRefund(order)"><RotateCcw class="h-3.5 w-3.5" /> Rimburso</Button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="!orders.data?.length" class="px-6 py-8 text-center text-body-sm text-neutral-400">{{ $t('admin.generated.k_32ade96872ce') }}</div>
                        </Card>
                    </aside>
                    </Transition>
                </Teleport>

                <!-- Menu Cards -->
                <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-card">
                    <div class="flex flex-col gap-3 border-b border-neutral-200 p-4 sm:flex-row sm:items-center">
                        <div class="relative flex-1">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                            <input
                                v-model="searchQuery"
                                type="search"
                                class="w-full rounded-lg border-neutral-200 bg-neutral-50 py-2.5 pl-9 pr-3 text-body-sm placeholder:text-neutral-400 focus:border-accent-500 focus:bg-white focus:ring-accent-500"
                                placeholder="Kërko produktin..."
                            />
                        </div>
                        <button
                            type="button"
                            class="inline-flex items-center justify-center gap-2 rounded-lg border px-3.5 py-2.5 text-small font-semibold transition"
                            :class="activeCategory === 'frequent' ? 'border-accent-700 bg-accent-700 text-white' : 'border-accent-200 bg-accent-50 text-accent-700 hover:bg-accent-100'"
                            @click="activeCategory = 'frequent'"
                        >
                            <Star class="h-4 w-4" /> Të shpeshtat
                        </button>
                    </div>
                    <!-- Category tabs -->
                    <div class="flex gap-2 overflow-x-auto border-b border-neutral-200 px-4 py-3">
                        <button
                            v-for="cat in menu"
                            :key="cat.id"
                            :class="[
                                'rounded-full border px-4 py-2 text-body-sm font-semibold whitespace-nowrap transition-all duration-150',
                                activeCategory === cat.id
                                    ? 'border-primary-900 bg-primary-900 text-white'
                                    : 'border-neutral-200 bg-white text-neutral-600 hover:border-neutral-300 hover:bg-neutral-50',
                            ]"
                            @click="activeCategory = cat.id"
                        >
                            <span class="mr-1.5">{{ categoryIcons[cat.name] || '📋' }}</span>
                            {{ cat.name }}
                            <span class="ml-1 text-tiny opacity-70">({{ cat.items?.length }})</span>
                        </button>
                    </div>

                    <!-- Locked when no shift is open -->
                    <div v-if="!hasOpenShift" class="mx-4 mt-4 rounded-lg border border-warning-200 bg-warning-50 px-4 py-3 text-center">
                        <span class="text-body-sm font-medium text-warning-900">{{ $t('admin.generated.k_b9030406c1c4') }}</span>
                    </div>

                    <div v-if="activeCategory === 'frequent' && !hasFrequentItems" class="mx-4 mt-4 rounded-lg border border-info-200 bg-info-50 px-4 py-3 text-center text-body-sm text-info-800">
                        Të shpeshtat plotësohen automatikisht pasi të regjistrohen shitjet e para.
                    </div>

                    <!-- Item grid -->
                    <div
                        class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-3 2xl:grid-cols-4"
                        :class="{ 'opacity-50 pointer-events-none': !hasOpenShift }"
                    >
                        <button
                            v-for="item in activeMenuItems"
                            :key="item.id"
                            class="group relative overflow-hidden rounded-xl border border-neutral-200 bg-white text-left transition-all duration-150 hover:-translate-y-0.5 hover:border-accent-300 hover:shadow-lg"
                            :class="(!item.is_available || (item.inventory_tracked && item.available_portions !== null && item.available_portions <= 0)) && 'pointer-events-none opacity-60'"
                            @click="addToCart(item)"
                        >
                            <!-- Image/Emoji area -->
                            <div class="flex h-24 items-center justify-center overflow-hidden bg-gradient-to-br from-neutral-50 to-neutral-100">
                                <img
                                    v-if="getItemImage(item)"
                                    :src="getItemImage(item)"
                                    :alt="item.name"
                                    class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-200"
                                />
                                <span v-else class="text-4xl">{{ getItemEmoji(item) }}</span>
                            </div>
                            <!-- Info -->
                            <div class="p-3">
                                <p class="min-h-8 text-body-sm font-semibold leading-tight text-primary-900">{{ item.name }}</p>
                                <div class="mt-1 flex items-center justify-between gap-2">
                                    <p class="text-label text-accent-700">{{ money(item.price) }}</p>
                                    <span v-if="item.inventory_tracked" class="text-tiny font-semibold" :class="item.available_portions === null ? 'text-warning-600' : item.available_portions > 0 ? 'text-neutral-400' : 'text-error-600'">{{ item.available_portions === null ? $t('inventory.pos.stockUnknown') : item.available_portions > 0 ? item.available_portions + ' ' + $t('inventory.pos.available') : $t('inventory.pos.outOfStock') }}</span>
                                    <span v-else-if="item.sales_count" class="text-tiny text-neutral-400">{{ $t('admin.pos.salesCount', { count: item.sales_count }) }}</span>
                                </div>
                            </div>
                            <!-- Hover add indicator -->
                            <div class="absolute right-2 top-2 flex h-8 w-8 items-center justify-center rounded-lg bg-accent-700 text-white opacity-0 shadow-md transition-opacity duration-150 group-hover:opacity-100">
                                <Plus class="h-4 w-4" />
                            </div>
                            <!-- Not available overlay -->
                            <div v-if="!item.is_available" class="absolute inset-0 bg-white/60 flex items-center justify-center">
                                <Badge variant="error" size="sm">{{ $t('admin.generated.k_6cf4092df322') }}</Badge>
                            </div>
                        </button>
                    </div>
                    <div v-if="!activeMenuItems.length && searchQuery" class="px-6 py-16 text-center">
                        <Search class="mx-auto h-8 w-8 text-neutral-300" />
                        <p class="mt-3 font-medium text-primary-900">Nuk u gjet asnjë produkt</p>
                        <p class="mt-1 text-body-sm text-neutral-500">Provo një emër tjetër ose ndrysho kategorinë.</p>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Cart sidebar -->
            <div class="shrink-0 xl:w-[390px]">
                <div class="flex flex-col overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-card xl:sticky xl:top-20 xl:h-[calc(100vh-7rem)] xl:min-h-[560px]">
                    <!-- Cart header -->
                    <div class="border-b border-neutral-200 px-4 py-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="grid h-9 w-9 place-items-center rounded-lg bg-accent-50 text-accent-700"><ShoppingCart class="h-5 w-5" /></span>
                                <div><h3 class="font-semibold text-primary-900">{{ editingOrderId ? `Ndrysho porosinë #${editingOrderId}` : 'Porosia e re' }}</h3><p class="text-tiny text-neutral-400">{{ cartCount }} artikuj</p></div>
                            </div>
                            <button v-if="cart.length" type="button" class="inline-flex items-center gap-1.5 rounded-lg px-2 py-1.5 text-small font-semibold text-error-600 hover:bg-error-50" @click="clearCart"><Trash2 class="h-4 w-4" /> Pastro</button>
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-1 rounded-lg bg-neutral-100 p-1">
                            <button type="button" class="rounded-md px-3 py-2 text-small font-semibold transition" :class="serviceMode === 'table' ? 'bg-white text-primary-900 shadow-sm' : 'text-neutral-500 hover:text-neutral-700'" @click="switchService('table')">Tavolinë / banak</button>
                            <button type="button" class="rounded-md px-3 py-2 text-small font-semibold transition" :class="serviceMode === 'room' ? 'bg-white text-primary-900 shadow-sm' : 'text-neutral-500 hover:text-neutral-700'" @click="switchService('room')">Dhomë</button>
                        </div>
                    </div>

                    <!-- Table/Room selection -->
                    <div class="border-b border-neutral-100 px-4 py-3">
                        <TextInput v-if="serviceMode === 'table'" v-model="tableNumber" placeholder="Numri i tavolinës · opsional" />
                        <Select v-else v-model="selectedReservation" :options="reservationOptions" placeholder="Zgjidh dhomën / mysafirin" />
                    </div>

                    <!-- Cart items -->
                    <div class="max-h-[420px] flex-1 overflow-y-auto px-4 py-2 xl:max-h-none">
                        <div v-if="cart.length" class="space-y-2">
                            <div v-for="(item, i) in cart" :key="i" class="grid grid-cols-[40px_minmax(0,1fr)_auto] items-center gap-3 border-b border-neutral-100 py-3 last:border-0">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-neutral-50 text-xl">{{ item.emoji }}</span>
                                <div class="flex-1 min-w-0">
                                    <p class="text-body-sm text-primary-900 font-medium truncate">{{ item.name }}</p>
                                    <p class="text-small text-neutral-400">{{ money(item.price) }} / copë</p>
                                    <div class="mt-1.5 flex items-center gap-1 shrink-0">
                                        <button class="grid h-7 w-7 place-items-center rounded-md border border-neutral-200 bg-white text-neutral-600 hover:bg-neutral-50" @click="updateQty(i, -1)"><Minus class="h-3.5 w-3.5" /></button>
                                        <span class="w-7 text-center text-body-sm font-semibold text-primary-900">{{ item.qty }}</span>
                                        <button class="grid h-7 w-7 place-items-center rounded-md border border-neutral-200 bg-white text-neutral-600 hover:bg-neutral-50" @click="updateQty(i, 1)"><Plus class="h-3.5 w-3.5" /></button>
                                    </div>
                                </div>
                                <p class="text-body-sm font-semibold text-primary-900">{{ money(item.price * item.qty) }}</p>
                            </div>
                        </div>

                        <div v-else class="py-12 text-center">
                            <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-neutral-100 text-neutral-400"><ShoppingCart class="h-6 w-6" /></span>
                            <p class="mt-3 font-medium text-primary-900">Shporta është bosh</p>
                            <p class="mt-1 text-body-sm text-neutral-400">Kliko produktet për t’i shtuar.</p>
                        </div>
                    </div>

                    <!-- Cart footer / total -->
                    <div v-if="cart.length" class="space-y-3 border-t border-neutral-200 bg-neutral-50 px-4 py-4">
                        <div class="flex items-center justify-between">
                            <span class="text-label text-neutral-500">{{ $t('admin.generated.k_85f1cb8f5091') }}</span>
                            <span class="text-h3 text-primary-900">{{ money(cartTotal) }}</span>
                        </div>
                        <Button variant="primary" size="lg" class="w-full" :disabled="!hasOpenShift || (serviceMode === 'room' && !selectedReservation)" @click="submitOrder">
                            {{ editingOrderId ? 'Ruaj ndryshimet' : 'Krijo porosinë' }} · {{ money(cartTotal) }}
                        </Button>
                        <p class="text-center text-tiny text-neutral-400">{{ editingOrderId ? 'Stoku i rezervuar përditësohet bashkë me rreshtat.' : 'Porosia krijohet e hapur dhe rezervon stokun menjëherë.' }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Modal -->
        <Modal :show="showPayModal" title="Arkëto porosinë" max-width="md" @close="showPayModal = false">
            <div class="space-y-4">
                <div class="rounded-xl border border-neutral-200 bg-neutral-50 py-5 text-center">
                    <p class="text-small font-semibold uppercase tracking-wide text-neutral-400">Porosia #{{ selectedOrder?.id }}</p>
                    <p class="mt-1 text-h1 text-primary-900">{{ money(paymentTotal) }}</p>
                    <p v-if="paymentDiscount > 0" class="mt-1 text-small text-success-700">Ulje {{ money(paymentDiscount) }} nga {{ money(paymentSubtotal) }}</p>
                </div>
                <div>
                    <p class="mb-2 text-label text-neutral-600">Ulje / komplimentare</p>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <button v-for="option in [{ value: 'none', label: 'Pa ulje' }, { value: 'percent', label: 'Përqindje' }, { value: 'fixed', label: 'Shumë fikse' }, { value: 'complimentary', label: 'Komplimentare' }]" :key="option.value" type="button" class="rounded-lg border px-3 py-2 text-small font-semibold" :class="discountType === option.value ? 'border-accent-500 bg-accent-50 text-accent-700' : 'border-neutral-200 text-neutral-600'" @click="discountType = option.value">{{ option.label }}</button>
                    </div>
                    <div v-if="discountType === 'percent' || discountType === 'fixed'" class="mt-3">
                        <TextInput v-model="discountValue" type="number" min="0" :max="discountType === 'percent' ? 100 : paymentSubtotal" step="0.01" :placeholder="discountType === 'percent' ? 'P.sh. 10%' : 'Shuma e uljes'" />
                    </div>
                    <div v-if="paymentDiscount > 0" class="mt-3">
                        <TextInput v-model="discountReason" placeholder="Arsyeja e uljes / komplimentares · e detyrueshme" />
                    </div>
                </div>

                <div v-if="paymentTotal > 0" class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <button
                        v-for="opt in [...paymentOptions, { value: 'split', label: 'Cash + Kartë' }]"
                        :key="opt.value"
                        :class="[
                            'rounded-xl border-2 p-4 text-center transition-all duration-150',
                            paymentMethod === opt.value
                                ? 'border-accent-500 bg-accent-50 text-accent-700'
                                : 'border-neutral-200 hover:border-neutral-300 text-neutral-600',
                        ]"
                        @click="paymentMethod = opt.value"
                    >
                        <span class="text-2xl block mb-1">{{ opt.value === 'cash' ? '💵' : opt.value === 'card' ? '💳' : opt.value === 'split' ? '💵＋💳' : '🏨' }}</span>
                        <span class="text-body-sm font-medium">{{ opt.label }}</span>
                    </button>
                </div>

                <div v-if="paymentMethod === 'split'" class="rounded-xl border border-neutral-200 bg-neutral-50 p-4">
                    <FormGroup label="Shuma cash">
                        <TextInput v-model="splitCashAmount" type="number" min="0" :max="paymentTotal" step="0.01" placeholder="0.00" />
                    </FormGroup>
                    <div class="mt-3 flex items-center justify-between text-body-sm"><span class="text-neutral-500">Pjesa me kartë</span><strong class="text-primary-900">{{ money(splitCard) }}</strong></div>
                </div>

                <!-- Room picker (when charging to a room) -->
                <div v-if="paymentMethod === 'room_charge'">
                    <label class="block text-label text-neutral-600 mb-1.5">{{ $t('admin.generated.k_66a76c99d2ff') }}</label>
                    <Select v-model="selectedPayReservation" :options="reservationOptions" :placeholder="$t('admin.generated.k_b6c2dcec536d')" />
                    <p v-if="!reservationOptions.length" class="text-small text-error-500 mt-1">{{ $t('admin.generated.k_0732d0e36b56') }}</p>
                </div>
                <p class="rounded-lg border border-info-200 bg-info-50 px-3 py-2.5 text-small text-info-800">Cash regjistrohet në Arka, karta në Banka; pagesa në dhomë kalon në folion e rezervimit.</p>
            </div>
            <template #footer>
                <Button variant="outline" @click="showPayModal = false">{{ $t('admin.generated.k_182fb16b9fb0') }}</Button>
                <Button variant="primary" :disabled="(paymentTotal > 0 && !paymentMethod) || (paymentMethod === 'room_charge' && !selectedPayReservation) || (paymentDiscount > 0 && !discountReason.trim()) || (paymentMethod === 'split' && (splitCash <= 0 || splitCard <= 0))" @click="submitPay">Konfirmo · {{ money(paymentTotal) }}</Button>
            </template>
        </Modal>

        <Modal :show="showCancelModal" title="Anulo porosinë" max-width="sm" @close="showCancelModal = false">
            <div class="space-y-3">
                <p class="text-body-sm text-neutral-600">Porosia #{{ actionOrder?.id }} do të anulohet dhe stoku i rezervuar do të lirohet.</p>
                <textarea v-model="actionReason" rows="3" class="w-full rounded-lg border-neutral-200 text-body-sm focus:border-accent-500 focus:ring-accent-500" placeholder="Arsyeja e anulimit · e detyrueshme" />
            </div>
            <template #footer><Button variant="outline" @click="showCancelModal = false">Mbyll</Button><Button variant="danger" :disabled="actionReason.trim().length < 3" @click="submitCancel">Anulo porosinë</Button></template>
        </Modal>

        <Modal :show="showRefundModal" title="Rimburso porosinë" max-width="sm" @close="showRefundModal = false">
            <div class="space-y-3">
                <div class="rounded-lg border border-warning-200 bg-warning-50 px-3 py-2.5 text-small text-warning-800">Do të kthehen {{ money(actionOrder?.total_amount) }}, do të krijohet lëvizja e kundërt në Financë dhe artikujt do të rikthehen në magazinë.</div>
                <textarea v-model="actionReason" rows="3" class="w-full rounded-lg border-neutral-200 text-body-sm focus:border-accent-500 focus:ring-accent-500" placeholder="Arsyeja e rimbursimit · e detyrueshme" />
            </div>
            <template #footer><Button variant="outline" @click="showRefundModal = false">Mbyll</Button><Button variant="danger" :disabled="actionReason.trim().length < 3" @click="submitRefund">Konfirmo rimbursimin</Button></template>
        </Modal>

        <!-- Thermal POS receipt preview -->
        <Modal :show="showReceipt" :title="$t('invoicePrint.posInvoiceTitle')" max-width="md" @close="showReceipt = false">
            <div class="overflow-x-auto rounded-lg bg-neutral-100 py-4">
                <PosReceipt v-if="receiptOrder" :order="receiptOrder" :settings="receiptSettings" class="shadow-lg" />
            </div>
            <div v-if="receiptOrder?.fiscal_document?.status === 'failed'" class="mt-3 rounded-lg border border-error-200 bg-error-50 px-3 py-2 text-small text-error-700">
                {{ $t('invoicePrint.posFiscalPrintWarning') }}
            </div>
            <div v-else-if="receiptOrder?.payment_method === 'room_charge'" class="mt-3 rounded-lg border border-info-200 bg-info-50 px-3 py-2 text-small text-info-800">
                {{ $t('invoicePrint.roomChargeFiscalHint') }}
            </div>
            <div v-else-if="receiptOrder?.fiscal_document?.status !== 'fiscalized'" class="mt-3 rounded-lg border border-warning-200 bg-warning-50 px-3 py-2 text-small text-warning-800">
                {{ $t('invoicePrint.nonFiscalPrintHint') }}
            </div>
            <div v-else class="mt-3 rounded-lg border border-success-200 bg-success-50 px-3 py-2 text-small text-success-800">
                {{ $t('invoicePrint.fiscalReady') }}
            </div>
            <template #footer>
                <Button variant="outline" @click="showReceipt = false">{{ $t('invoicePrint.cancel') }}</Button>
                <Button
                    variant="primary"
                    :loading="fiscalizingOrder === receiptOrder.id"
                    :disabled="!canFiscalize(receiptOrder)"
                    @click="fiscalizeReceipt(receiptOrder)"
                >{{ receiptOrder?.fiscal_document?.status === 'fiscalized' ? $t('invoicePrint.fiscalizedButton') : $t('invoicePrint.fiscalize') }}</Button>
                <Button variant="outline" @click="printReceipt">{{ $t('invoicePrint.print80') }}</Button>
            </template>
        </Modal>

        <!-- Open shift modal -->
        <Modal :show="showOpenShift" :title="$t('admin.generated.k_b8387a4701eb')" max-width="sm" @close="showOpenShift = false">
            <div class="space-y-4">
                <div class="text-center py-1"><span class="text-4xl">🔓</span></div>
                <FormGroup :label="$t('admin.generated.k_7ebbd21c8fab')" :error="openShiftForm.errors.opening_float" required>
                    <TextInput type="number" step="0.01" min="0" v-model="openShiftForm.opening_float" placeholder="0.00" :error="openShiftForm.errors.opening_float" />
                </FormGroup>
                <p class="text-small text-neutral-500">{{ $t('admin.generated.k_069e17f28ade') }}</p>
            </div>
            <template #footer>
                <Button variant="outline" @click="showOpenShift = false">{{ $t('admin.generated.k_182fb16b9fb0') }}</Button>
                <Button variant="primary" :loading="openShiftForm.processing" @click="submitOpenShift">{{ $t('admin.generated.k_09427f425cef') }}</Button>
            </template>
        </Modal>

        <!-- Close shift modal (Z-Report) -->
        <Modal :show="showCloseShift" :title="$t('admin.generated.k_d693052380a0')" max-width="md" @close="showCloseShift = false">
            <div v-if="currentShift" class="space-y-4">
                <div id="zreport" class="space-y-4">
                    <!-- Drawer expected -->
                    <div class="rounded-lg bg-neutral-50 border border-neutral-200 p-4 space-y-1.5 text-body-sm">
                        <div class="flex justify-between text-neutral-600"><span>{{ $t('admin.generated.k_afaffdd6fba2') }}</span><span>{{ money(currentShift.opening_float) }}</span></div>
                        <div class="flex justify-between text-neutral-600"><span>{{ $t('admin.generated.k_880339104862') }}</span><span>{{ money(currentShift.cash_sales) }}</span></div>
                        <div class="flex justify-between font-semibold text-primary-900 border-t border-neutral-200 pt-1.5"><span>{{ $t('admin.generated.k_81ed24491855') }}</span><span>{{ money(expectedCash) }}</span></div>
                    </div>

                    <!-- Reported but not in drawer -->
                    <div class="rounded-lg bg-neutral-50/70 px-4 py-3 text-small text-neutral-500 space-y-1">
                        <p class="font-medium text-neutral-600">{{ $t('admin.generated.k_fc36fa7bd197') }}</p>
                        <div class="flex justify-between"><span>{{ $t('admin.generated.k_af92a6e399a8') }}</span><span>{{ money(currentShift.card_sales) }}</span></div>
                        <div class="flex justify-between"><span>{{ $t('admin.generated.k_2ed6d0f4fac5') }}</span><span>{{ money(currentShift.room_charge_sales) }}</span></div>
                        <div class="flex justify-between border-t border-neutral-200 pt-1 text-neutral-600"><span>{{ $t('admin.generated.k_d11885dd3f1b') }} {{ currentShift.completed_orders }} {{ $t('admin.generated.k_d422b6155234') }}</span><span>{{ money(totalSales) }}</span></div>
                    </div>

                    <!-- counted result (prints with the report once typed) -->
                    <div v-if="countedNum !== null" class="space-y-1 border-t border-neutral-100 pt-2">
                        <div class="flex justify-between text-body-sm">
                            <span class="text-neutral-600">{{ $t('admin.generated.k_d914e17d696d') }}</span>
                            <span class="text-primary-900 font-medium">{{ money(countedNum) }}</span>
                        </div>
                        <p class="text-center text-body-sm font-semibold" :class="varianceClass">{{ varianceLabel }}</p>
                    </div>
                </div>

                <!-- open orders warning -->
                <div v-if="currentShift.open_orders" class="rounded-lg bg-warning-50 border border-warning-200 px-3 py-2 text-small text-warning-800 print:hidden">
                    ⚠️ {{ currentShift.open_orders }} {{ $t('admin.generated.k_6b58c32bad4e') }} </div>

                <!-- mandatory count input -->
                <FormGroup :label="$t('admin.generated.k_bce57025cf34')" :error="closeShiftForm.errors.counted_cash" required class="print:hidden">
                    <TextInput type="number" step="0.01" min="0" v-model="closeShiftForm.counted_cash" placeholder="0.00" :error="closeShiftForm.errors.counted_cash" />
                </FormGroup>

                <FormGroup :label="$t('admin.generated.k_fd404602b8ba')" :error="closeShiftForm.errors.closing_note" class="print:hidden">
                    <textarea
                        v-model="closeShiftForm.closing_note"
                        rows="2"
                        maxlength="500"
                        class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-1 focus:ring-accent-500"
                        :placeholder="$t('admin.generated.k_5dc7ffcf092f')"
                    ></textarea>
                </FormGroup>
            </div>
            <template #footer>
                <Button variant="outline" @click="showCloseShift = false">{{ $t('admin.generated.k_182fb16b9fb0') }}</Button>
                <Button variant="outline" :disabled="countedNum === null" @click="printZReport">{{ $t('admin.generated.k_95ddf85f4a7e') }}</Button>
                <Button variant="primary" :loading="closeShiftForm.processing" :disabled="countedNum === null" @click="submitCloseShift">{{ $t('admin.generated.k_aca11a3b5c75') }}</Button>
            </template>
        </Modal>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>

<style>
@media print {
    @page { size: 80mm auto; margin: 0; }
    body.printing-z-report * { visibility: hidden !important; }
    body.printing-z-report #zreport, body.printing-z-report #zreport * { visibility: visible !important; }
    body.printing-z-report #zreport { position: absolute; left: 0; top: 0; width: 100%; padding: 24px; }

    body.printing-pos-receipt * { visibility: hidden !important; }
    body.printing-pos-receipt #pos-receipt, body.printing-pos-receipt #pos-receipt * { visibility: visible !important; }
    body.printing-pos-receipt #pos-receipt { position: absolute; left: 0; top: 0; margin: 0; box-shadow: none !important; }
}
</style>
