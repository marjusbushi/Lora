<script setup>
import { i18n } from '@/i18n';
import { ref, computed } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Modal from '@/Components/UI/Modal.vue';
import Select from '@/Components/UI/Select.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';
import ShiftBanner from '@/Components/Pos/ShiftBanner.vue';

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
});

const toasts = ref(null);
const showPayModal = ref(false);
const showOrdersPanel = ref(false);
const selectedOrder = ref(null);
const activeCategory = ref(props.menu?.[0]?.id || null);

// Cart
const cart = ref([]);
const tableNumber = ref('');
const selectedReservation = ref('');

const reservationOptions = props.activeReservations.map((r) => ({
    value: r.id,
    label: `Dhoma ${r.room?.room_number} — ${r.guest?.first_name} ${r.guest?.last_name}`,
}));

const paymentOptions = [
    { value: 'cash', label: 'Cash' },
    { value: 'card', label: 'Karte' },
    { value: 'room_charge', label: 'Room Charge' },
];
const paymentMethod = ref('');
const selectedPayReservation = ref('');

// ===== Cash-drawer shift (hapje/mbyllje turni) =====
const showOpenShift = ref(false);
const showCloseShift = ref(false);
const hasOpenShift = computed(() => !!props.currentShift);

const openShiftForm = useForm({ opening_float: props.defaultOpeningFloat ?? 0 });
const closeShiftForm = useForm({ counted_cash: '', closing_note: '' });

function money(v) {
    return `€${Number(v ?? 0).toFixed(2)}`;
}

function submitOpenShift() {
    openShiftForm.post(route('pos.shift.open'), {
        preserveScroll: true,
        onSuccess: () => { showOpenShift.value = false; toasts.value?.success('Turni u hap 🟢'); },
        onError: () => toasts.value?.error('Hapja e turnit dështoi.'),
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
    if (Math.abs(variance.value) < 0.01) return 'Kasa përputhet ✅';
    if (variance.value < 0) return `Mungesë €${Math.abs(variance.value).toFixed(2)} 🔴`;
    return `Tepricë €${variance.value.toFixed(2)} 🟡`;
});
const varianceClass = computed(() => {
    if (variance.value === null) return 'text-neutral-400';
    if (Math.abs(variance.value) < 0.01) return 'text-success-600';
    if (variance.value < 0) return 'text-error-600';
    return 'text-warning-600';
});

function submitCloseShift() {
    if (countedNum.value === null) { toasts.value?.error('Shëno sa kesh ke numëruar.'); return; }
    closeShiftForm.post(route('pos.shift.close', props.currentShift.id), {
        preserveScroll: true,
        onSuccess: () => { showCloseShift.value = false; toasts.value?.success('Turni u mbyll — Z-Report gati 🧾'); },
        onError: () => toasts.value?.error('Mbyllja e turnit dështoi.'),
    });
}

function printZReport() {
    window.print();
}

const cartTotal = computed(() =>
    cart.value.reduce((sum, item) => sum + item.price * item.qty, 0)
);

const cartCount = computed(() =>
    cart.value.reduce((sum, item) => sum + item.qty, 0)
);

const activeMenuItems = computed(() => {
    const cat = props.menu?.find((c) => c.id === activeCategory.value);
    return cat?.items || [];
});

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
    if (!hasOpenShift.value) { toasts.value?.error('Hap një turn së pari.'); return; }
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
}

function submitOrder() {
    if (!cart.value.length) return;
    if (!hasOpenShift.value) { toasts.value?.error('Hap një turn së pari.'); return; }
    const form = useForm({
        table_number: tableNumber.value || null,
        reservation_id: selectedReservation.value || null,
        items: cart.value.map((c) => ({ menu_item_id: c.id, quantity: c.qty })),
    });

    form.post(route('pos.store'), {
        onSuccess: () => {
            clearCart();
            toasts.value?.success(`Porosia u krijua — €${cartTotal.value.toFixed(2)}`);
        },
    });
}

function openPay(order) {
    if (!hasOpenShift.value) { toasts.value?.error('Hap një turn së pari.'); return; }
    selectedOrder.value = order;
    paymentMethod.value = '';
    selectedPayReservation.value = order.reservation_id || '';
    showPayModal.value = true;
}

function submitPay() {
    router.post(route('pos.complete', selectedOrder.value.id), {
        payment_method: paymentMethod.value,
        reservation_id: paymentMethod.value === 'room_charge' ? selectedPayReservation.value : null,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showPayModal.value = false;
            toasts.value?.success('Pagesa u regjistrua.');
        },
        onError: (errors) => {
            if (errors.inventory) toasts.value?.error(errors.inventory);
        },
    });
}

function cancelOrder(order) {
    if (!confirm('Anulo porosine?')) return;
    router.post(route('pos.cancel', order.id), {}, {
        preserveScroll: true,
        onSuccess: () => toasts.value?.success('Porosia u anulua.'),
    });
}

const statusBadge = {
    open: { variant: 'warning', label: 'E hapur' },
    completed: { variant: 'success', label: 'Paguar' },
    cancelled: { variant: 'error', label: 'Anulluar' },
};

const payLabel = { cash: 'Cash', card: 'Karte', room_charge: 'Room Charge' };

function formatTime(d) {
    return new Date(d).toLocaleTimeString('sq-AL', { hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <AppLayout>
        <ShiftBanner
            :shift="currentShift"
            :can-open="canOpenShift"
            :can-close="canCloseShift"
            class="mb-5"
            @open="showOpenShift = true"
            @close="openCloseModal"
        />
        <div class="flex flex-col lg:flex-row gap-6 h-full">
            <!-- LEFT: Menu area -->
            <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between mb-4">
                    <h1 class="text-h2 text-primary-900">POS Bar/Restaurant</h1>
                    <div class="flex items-center gap-3">
                        <!-- Stats mini -->
                        <div class="hidden sm:flex items-center gap-4 text-body-sm">
                            <span class="text-warning-600 font-medium">{{ stats.open }} hapur</span>
                            <span class="text-success-600 font-medium">{{ stats.today_completed }} sot</span>
                            <span class="text-accent-600 font-medium">€{{ Number(stats.today_revenue).toFixed(2) }}</span>
                        </div>
                        <Button variant="outline" size="sm" @click="showOrdersPanel = !showOrdersPanel">
                            {{ showOrdersPanel ? 'Menu' : 'Porosite' }}
                        </Button>
                    </div>
                </div>

                <!-- Orders Panel (toggle) -->
                <div v-if="showOrdersPanel">
                    <Card :padding="false">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-neutral-200">
                                <thead class="bg-neutral-50">
                                    <tr>
                                        <th class="px-4 py-2.5 text-left text-label text-neutral-600">#</th>
                                        <th class="px-4 py-2.5 text-left text-label text-neutral-600">Ora</th>
                                        <th class="px-4 py-2.5 text-left text-label text-neutral-600">Artikuj</th>
                                        <th class="px-4 py-2.5 text-left text-label text-neutral-600">Status</th>
                                        <th class="px-4 py-2.5 text-right text-label text-neutral-600">Total</th>
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
                                            <Badge :variant="statusBadge[order.status]?.variant" dot size="sm">
                                                {{ statusBadge[order.status]?.label }}
                                            </Badge>
                                        </td>
                                        <td class="px-4 py-2.5 text-right text-body-sm font-medium">€{{ order.total_amount }}</td>
                                        <td class="px-4 py-2.5 text-right">
                                            <div v-if="order.status === 'open'" class="flex justify-end gap-1">
                                                <Button size="sm" variant="primary" :disabled="!hasOpenShift" @click="openPay(order)">Paguaj</Button>
                                                <Button size="sm" variant="ghost" class="text-error-600" @click="cancelOrder(order)">×</Button>
                                            </div>
                                            <Badge v-else-if="order.payment_method" variant="neutral" size="sm">{{ payLabel[order.payment_method] }}</Badge>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="!orders.data?.length" class="px-6 py-8 text-center text-body-sm text-neutral-400">Nuk ka porosi.</div>
                    </Card>
                </div>

                <!-- Menu Cards -->
                <div v-else>
                    <!-- Category tabs -->
                    <div class="flex gap-2 mb-5 overflow-x-auto pb-1">
                        <button
                            v-for="cat in menu"
                            :key="cat.id"
                            :class="[
                                'px-4 py-2.5 rounded-lg text-body-sm font-medium whitespace-nowrap transition-all duration-150',
                                activeCategory === cat.id
                                    ? 'bg-accent-600 text-white shadow-md'
                                    : 'bg-white text-neutral-600 hover:bg-neutral-100 border border-neutral-200',
                            ]"
                            @click="activeCategory = cat.id"
                        >
                            <span class="mr-1.5">{{ categoryIcons[cat.name] || '📋' }}</span>
                            {{ cat.name }}
                            <span class="ml-1 text-tiny opacity-70">({{ cat.items?.length }})</span>
                        </button>
                    </div>

                    <!-- Locked when no shift is open -->
                    <div v-if="!hasOpenShift" class="mb-3 rounded-lg border border-warning-200 bg-warning-50 px-4 py-3 text-center">
                        <span class="text-body-sm font-medium text-warning-900">🔒 Hap turnin për të filluar porositë</span>
                    </div>

                    <!-- Item grid -->
                    <div
                        class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3"
                        :class="{ 'opacity-50 pointer-events-none': !hasOpenShift }"
                    >
                        <button
                            v-for="item in activeMenuItems"
                            :key="item.id"
                            class="group relative overflow-hidden rounded-xl border border-neutral-200 bg-white text-left transition-all duration-150 hover:-translate-y-0.5 hover:border-accent-300 hover:shadow-lg"
                            :class="(!item.is_available || (item.inventory_tracked && item.available_portions !== null && item.available_portions <= 0)) && 'opacity-60'"
                            @click="addToCart(item)"
                        >
                            <!-- Image/Emoji area -->
                            <div class="h-24 bg-neutral-100 flex items-center justify-center overflow-hidden">
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
                                    <span v-else-if="item.sales_count" class="text-tiny text-neutral-400">{{ item.sales_count }} shitje</span>
                                </div>
                            </div>
                            <!-- Hover add indicator -->
                            <div class="absolute top-2 right-2 h-7 w-7 rounded-full bg-accent-600 text-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-150 text-small font-bold shadow-md">
                                +
                            </div>
                            <!-- Not available overlay -->
                            <div v-if="!item.is_available" class="absolute inset-0 bg-white/60 flex items-center justify-center">
                                <Badge variant="error" size="sm">Jo disponueshem</Badge>
                            </div>
                        </button>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Cart sidebar -->
            <div class="lg:w-80 xl:w-96 shrink-0">
                <div class="bg-white rounded-lg border border-neutral-200 sticky top-20 flex flex-col" style="max-height: calc(100vh - 6rem);">
                    <!-- Cart header -->
                    <div class="px-4 py-3 border-b border-neutral-200 flex items-center justify-between">
                        <h3 class="text-label text-primary-900">
                            Porosia
                            <span v-if="cartCount" class="ml-1 text-accent-600">({{ cartCount }})</span>
                        </h3>
                        <button v-if="cart.length" class="text-small text-error-500 hover:text-error-700" @click="clearCart">Pastro</button>
                    </div>

                    <!-- Table/Room selection -->
                    <div class="px-4 py-3 border-b border-neutral-100 flex gap-2">
                        <TextInput v-model="tableNumber" placeholder="Tav. #" class="w-20" />
                        <Select v-model="selectedReservation" :options="reservationOptions" placeholder="Room..." class="flex-1" />
                    </div>

                    <!-- Cart items -->
                    <div class="flex-1 overflow-y-auto px-4 py-2">
                        <div v-if="cart.length" class="space-y-2">
                            <div v-for="(item, i) in cart" :key="i" class="flex items-center gap-3 py-2 border-b border-neutral-50 last:border-0">
                                <span class="text-xl shrink-0">{{ item.emoji }}</span>
                                <div class="flex-1 min-w-0">
                                    <p class="text-body-sm text-primary-900 font-medium truncate">{{ item.name }}</p>
                                    <p class="text-small text-neutral-400">€{{ item.price.toFixed(2) }}</p>
                                </div>
                                <div class="flex items-center gap-1 shrink-0">
                                    <button class="h-7 w-7 rounded-md bg-neutral-100 text-neutral-600 hover:bg-neutral-200 flex items-center justify-center text-body-sm font-medium" @click="updateQty(i, -1)">−</button>
                                    <span class="w-7 text-center text-body-sm font-medium text-primary-900">{{ item.qty }}</span>
                                    <button class="h-7 w-7 rounded-md bg-neutral-100 text-neutral-600 hover:bg-neutral-200 flex items-center justify-center text-body-sm font-medium" @click="updateQty(i, 1)">+</button>
                                </div>
                                <p class="text-body-sm font-medium text-primary-900 w-14 text-right shrink-0">€{{ (item.price * item.qty).toFixed(2) }}</p>
                            </div>
                        </div>

                        <div v-else class="py-12 text-center">
                            <p class="text-3xl mb-2">🛒</p>
                            <p class="text-body-sm text-neutral-400">Kliko artikujt per ti shtuar</p>
                        </div>
                    </div>

                    <!-- Cart footer / total -->
                    <div v-if="cart.length" class="border-t border-neutral-200 px-4 py-4 space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-label text-neutral-500">Total</span>
                            <span class="text-h3 text-primary-900">€{{ cartTotal.toFixed(2) }}</span>
                        </div>
                        <Button variant="primary" size="lg" class="w-full" :disabled="!hasOpenShift" @click="submitOrder">
                            Krijo porosine
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Modal -->
        <Modal :show="showPayModal" title="Perfundo pagesen" max-width="sm" @close="showPayModal = false">
            <div class="space-y-4">
                <div class="text-center py-2">
                    <p class="text-h2 text-primary-900">€{{ selectedOrder?.total_amount }}</p>
                    <p class="text-body-sm text-neutral-500 mt-1">Porosia #{{ selectedOrder?.id }}</p>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <button
                        v-for="opt in paymentOptions"
                        :key="opt.value"
                        :class="[
                            'rounded-lg border-2 p-4 text-center transition-all duration-150',
                            paymentMethod === opt.value
                                ? 'border-accent-500 bg-accent-50 text-accent-700'
                                : 'border-neutral-200 hover:border-neutral-300 text-neutral-600',
                        ]"
                        @click="paymentMethod = opt.value"
                    >
                        <span class="text-2xl block mb-1">{{ opt.value === 'cash' ? '💵' : opt.value === 'card' ? '💳' : '🏨' }}</span>
                        <span class="text-body-sm font-medium">{{ opt.label }}</span>
                    </button>
                </div>

                <!-- Room picker (when charging to a room) -->
                <div v-if="paymentMethod === 'room_charge'">
                    <label class="block text-label text-neutral-600 mb-1.5">Ngarko ne dhomen</label>
                    <Select v-model="selectedPayReservation" :options="reservationOptions" placeholder="Zgjidh dhomen / mysafirin..." />
                    <p v-if="!reservationOptions.length" class="text-small text-error-500 mt-1">Asnje mysafir brenda (check-in) per te ngarkuar.</p>
                </div>
            </div>
            <template #footer>
                <Button variant="outline" @click="showPayModal = false">Anulo</Button>
                <Button variant="primary" :disabled="!paymentMethod || (paymentMethod === 'room_charge' && !selectedPayReservation)" @click="submitPay">Paguaj €{{ selectedOrder?.total_amount }}</Button>
            </template>
        </Modal>

        <!-- Open shift modal -->
        <Modal :show="showOpenShift" title="Hap Turn" max-width="sm" @close="showOpenShift = false">
            <div class="space-y-4">
                <div class="text-center py-1"><span class="text-4xl">🔓</span></div>
                <FormGroup label="Para në kasë (fillestare)" :error="openShiftForm.errors.opening_float" required>
                    <TextInput type="number" step="0.01" min="0" v-model="openShiftForm.opening_float" placeholder="0.00" :error="openShiftForm.errors.opening_float" />
                </FormGroup>
                <p class="text-small text-neutral-500">Shëno sa para cash ke në sirtar tani, në fillim të turnit.</p>
            </div>
            <template #footer>
                <Button variant="outline" @click="showOpenShift = false">Anulo</Button>
                <Button variant="primary" :loading="openShiftForm.processing" @click="submitOpenShift">Hap Turnin</Button>
            </template>
        </Modal>

        <!-- Close shift modal (Z-Report) -->
        <Modal :show="showCloseShift" title="Mbyll Turn — Raporti i Turnit" max-width="md" @close="showCloseShift = false">
            <div v-if="currentShift" class="space-y-4">
                <div id="zreport" class="space-y-4">
                    <!-- Drawer expected -->
                    <div class="rounded-lg bg-neutral-50 border border-neutral-200 p-4 space-y-1.5 text-body-sm">
                        <div class="flex justify-between text-neutral-600"><span>Para në kasë (fillestare)</span><span>{{ money(currentShift.opening_float) }}</span></div>
                        <div class="flex justify-between text-neutral-600"><span>💶 Shitje kesh</span><span>{{ money(currentShift.cash_sales) }}</span></div>
                        <div class="flex justify-between font-semibold text-primary-900 border-t border-neutral-200 pt-1.5"><span>= Arkë e pritur</span><span>{{ money(expectedCash) }}</span></div>
                    </div>

                    <!-- Reported but not in drawer -->
                    <div class="rounded-lg bg-neutral-50/70 px-4 py-3 text-small text-neutral-500 space-y-1">
                        <p class="font-medium text-neutral-600">Për informacion — s'prekin kasën:</p>
                        <div class="flex justify-between"><span>💳 Kartë</span><span>{{ money(currentShift.card_sales) }}</span></div>
                        <div class="flex justify-between"><span>🏨 Në folio (dhomë)</span><span>{{ money(currentShift.room_charge_sales) }}</span></div>
                        <div class="flex justify-between border-t border-neutral-200 pt-1 text-neutral-600"><span>Shitje gjithsej · {{ currentShift.completed_orders }} porosi</span><span>{{ money(totalSales) }}</span></div>
                    </div>

                    <!-- counted result (prints with the report once typed) -->
                    <div v-if="countedNum !== null" class="space-y-1 border-t border-neutral-100 pt-2">
                        <div class="flex justify-between text-body-sm">
                            <span class="text-neutral-600">Kesh i numëruar</span>
                            <span class="text-primary-900 font-medium">{{ money(countedNum) }}</span>
                        </div>
                        <p class="text-center text-body-sm font-semibold" :class="varianceClass">{{ varianceLabel }}</p>
                    </div>
                </div>

                <!-- open orders warning -->
                <div v-if="currentShift.open_orders" class="rounded-lg bg-warning-50 border border-warning-200 px-3 py-2 text-small text-warning-800 print:hidden">
                    ⚠️ {{ currentShift.open_orders }} porosi të pambyllura në këtë turn — mbyllja nuk i fshin, por kontrolloji.
                </div>

                <!-- mandatory count input -->
                <FormGroup label="Numëro kesh-in në sirtar" :error="closeShiftForm.errors.counted_cash" required class="print:hidden">
                    <TextInput type="number" step="0.01" min="0" v-model="closeShiftForm.counted_cash" placeholder="0.00" :error="closeShiftForm.errors.counted_cash" />
                </FormGroup>

                <FormGroup label="Shënim (opsional)" :error="closeShiftForm.errors.closing_note" class="print:hidden">
                    <textarea
                        v-model="closeShiftForm.closing_note"
                        rows="2"
                        maxlength="500"
                        class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-1 focus:ring-accent-500"
                        placeholder="P.sh. arsye për mungesë/tepricë..."
                    ></textarea>
                </FormGroup>
            </div>
            <template #footer>
                <Button variant="outline" @click="showCloseShift = false">Anulo</Button>
                <Button variant="outline" :disabled="countedNum === null" @click="printZReport">🖨️ Printo</Button>
                <Button variant="primary" :loading="closeShiftForm.processing" :disabled="countedNum === null" @click="submitCloseShift">Mbyll Turnin</Button>
            </template>
        </Modal>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>

<style>
@media print {
    body * { visibility: hidden !important; }
    #zreport, #zreport * { visibility: visible !important; }
    #zreport { position: absolute; left: 0; top: 0; width: 100%; padding: 24px; }
}
</style>
