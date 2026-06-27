<script setup>
import { ref, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Modal from '@/Components/UI/Modal.vue';
import Select from '@/Components/UI/Select.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';

const props = defineProps({
    orders: Object,
    menu: Array,
    activeReservations: Array,
    filters: Object,
    stats: Object,
});

const toasts = ref(null);
const showPayModal = ref(false);
const showOrdersPanel = ref(false);
const selectedOrder = ref(null);
const activeCategory = ref(props.menu?.[0]?.id || null);

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
    { value: 'room_charge', label: 'Ne dhome' },
];
const paymentMethod = ref('');

const cartTotal = computed(() => cart.value.reduce((s, i) => s + i.price * i.qty, 0));
const cartCount = computed(() => cart.value.reduce((s, i) => s + i.qty, 0));
const activeMenuItems = computed(() => props.menu?.find((c) => c.id === activeCategory.value)?.items || []);

function getItemImage(item) {
    return item.image_path ? `/storage/${item.image_path}` : null;
}
function addToCart(menuItem) {
    const existing = cart.value.find((c) => c.id === menuItem.id);
    if (existing) existing.qty++;
    else cart.value.push({ id: menuItem.id, name: menuItem.name, price: parseFloat(menuItem.price), qty: 1 });
}
function updateQty(index, delta) {
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
    const form = useForm({
        table_number: tableNumber.value || null,
        reservation_id: selectedReservation.value || null,
        items: cart.value.map((c) => ({ menu_item_id: c.id, quantity: c.qty })),
    });
    form.post(route('pos.store'), {
        onSuccess: () => { clearCart(); toasts.value?.success(`Porosia u krijua — €${cartTotal.value.toFixed(2)}`); },
    });
}
function openPay(order) {
    selectedOrder.value = order;
    paymentMethod.value = '';
    showPayModal.value = true;
}
function submitPay() {
    router.post(route('pos.complete', selectedOrder.value.id), { payment_method: paymentMethod.value }, {
        preserveScroll: true,
        onSuccess: () => { showPayModal.value = false; toasts.value?.success('Pagesa u regjistrua.'); },
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
const payLabel = { cash: 'Cash', card: 'Karte', room_charge: 'Ne dhome' };
function formatTime(d) {
    return new Date(d).toLocaleTimeString('sq-AL', { hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <AppLayout>
        <div class="flex flex-col lg:flex-row gap-6">
            <!-- LEFT: Menu -->
            <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between mb-5">
                    <h1 class="text-h2 text-primary-900">POS Bar/Restaurant</h1>
                    <div class="flex items-center gap-4">
                        <div class="hidden sm:flex items-center gap-4 text-body-sm">
                            <span class="text-neutral-500"><span class="text-warning-600 font-semibold">{{ stats.open }}</span> hapur</span>
                            <span class="text-neutral-500"><span class="text-success-600 font-semibold">{{ stats.today_completed }}</span> sot</span>
                            <span class="text-accent-600 font-semibold">€{{ Number(stats.today_revenue).toFixed(2) }}</span>
                        </div>
                        <div class="inline-flex rounded-lg border border-neutral-200 bg-white p-0.5">
                            <button :class="['px-3 py-1.5 rounded-md text-body-sm font-medium transition-colors', !showOrdersPanel ? 'bg-primary-900 text-white' : 'text-neutral-500 hover:text-neutral-800']" @click="showOrdersPanel = false">Menu</button>
                            <button :class="['px-3 py-1.5 rounded-md text-body-sm font-medium transition-colors', showOrdersPanel ? 'bg-primary-900 text-white' : 'text-neutral-500 hover:text-neutral-800']" @click="showOrdersPanel = true">Porosite</button>
                        </div>
                    </div>
                </div>

                <!-- Orders panel -->
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
                                        <td class="px-4 py-2.5 text-body-sm text-neutral-600 max-w-48 truncate">{{ order.items?.map(i => i.menu_item?.name).join(', ') || '—' }}</td>
                                        <td class="px-4 py-2.5"><Badge :variant="statusBadge[order.status]?.variant" dot size="sm">{{ statusBadge[order.status]?.label }}</Badge></td>
                                        <td class="px-4 py-2.5 text-right text-body-sm font-medium">€{{ order.total_amount }}</td>
                                        <td class="px-4 py-2.5 text-right">
                                            <div v-if="order.status === 'open'" class="flex justify-end gap-1">
                                                <Button size="sm" variant="primary" @click="openPay(order)">Paguaj</Button>
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

                <!-- Menu -->
                <div v-else>
                    <!-- Category tabs -->
                    <div class="flex gap-2 mb-5 overflow-x-auto pb-1">
                        <button
                            v-for="cat in menu"
                            :key="cat.id"
                            :class="[
                                'px-4 py-2 rounded-lg text-body-sm font-medium whitespace-nowrap transition-colors',
                                activeCategory === cat.id ? 'bg-primary-900 text-white' : 'bg-white text-neutral-600 hover:bg-neutral-100 border border-neutral-200',
                            ]"
                            @click="activeCategory = cat.id"
                        >
                            {{ cat.name }}
                            <span class="ml-1 text-tiny opacity-60">{{ cat.items?.length }}</span>
                        </button>
                    </div>

                    <!-- Item tiles (clean, no emoji) -->
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                        <button
                            v-for="item in activeMenuItems"
                            :key="item.id"
                            class="group relative bg-white rounded-lg border border-neutral-200 hover:border-accent-400 hover:shadow-md transition-all duration-150 text-left overflow-hidden"
                            :class="!item.is_available && 'opacity-50 pointer-events-none'"
                            @click="addToCart(item)"
                        >
                            <img v-if="getItemImage(item)" :src="getItemImage(item)" :alt="item.name" class="h-20 w-full object-cover" />
                            <div class="p-3 min-h-[64px] flex flex-col justify-center">
                                <p class="text-body-sm text-primary-900 font-medium leading-tight">{{ item.name }}</p>
                                <p class="text-label text-accent-600 mt-1.5">€{{ item.price }}</p>
                            </div>
                            <div class="absolute top-2 right-2 h-6 w-6 rounded-full bg-accent-600 text-white grid place-items-center opacity-0 group-hover:opacity-100 transition-opacity text-small font-bold shadow-sm">+</div>
                            <div v-if="!item.is_available" class="absolute inset-0 bg-white/60 grid place-items-center">
                                <Badge variant="error" size="sm">Jo disponueshem</Badge>
                            </div>
                        </button>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Cart -->
            <div class="lg:w-80 xl:w-96 shrink-0">
                <div class="bg-white rounded-lg border border-neutral-200 sticky top-20 flex flex-col" style="max-height: calc(100vh - 6rem);">
                    <div class="px-4 py-3 border-b border-neutral-200 flex items-center justify-between">
                        <h3 class="text-label text-primary-900">Porosia<span v-if="cartCount" class="ml-1 text-accent-600">({{ cartCount }})</span></h3>
                        <button v-if="cart.length" class="text-small text-error-500 hover:text-error-700" @click="clearCart">Pastro</button>
                    </div>

                    <div class="px-4 py-3 border-b border-neutral-100 flex gap-2">
                        <TextInput v-model="tableNumber" placeholder="Tav. #" class="w-20" />
                        <Select v-model="selectedReservation" :options="reservationOptions" placeholder="Dhoma..." class="flex-1" />
                    </div>

                    <div class="flex-1 overflow-y-auto px-4 py-2">
                        <div v-if="cart.length" class="space-y-1">
                            <div v-for="(item, i) in cart" :key="i" class="flex items-center gap-3 py-2 border-b border-neutral-50 last:border-0">
                                <div class="flex-1 min-w-0">
                                    <p class="text-body-sm text-primary-900 font-medium truncate">{{ item.name }}</p>
                                    <p class="text-small text-neutral-400">€{{ item.price.toFixed(2) }}</p>
                                </div>
                                <div class="flex items-center gap-1 shrink-0">
                                    <button class="h-7 w-7 rounded-md bg-neutral-100 text-neutral-600 hover:bg-neutral-200 grid place-items-center text-body-sm font-medium" @click="updateQty(i, -1)">−</button>
                                    <span class="w-6 text-center text-body-sm font-medium text-primary-900">{{ item.qty }}</span>
                                    <button class="h-7 w-7 rounded-md bg-neutral-100 text-neutral-600 hover:bg-neutral-200 grid place-items-center text-body-sm font-medium" @click="updateQty(i, 1)">+</button>
                                </div>
                                <p class="text-body-sm font-medium text-primary-900 w-14 text-right shrink-0">€{{ (item.price * item.qty).toFixed(2) }}</p>
                            </div>
                        </div>
                        <div v-else class="py-16 text-center">
                            <p class="text-body-sm text-neutral-400">Kliko artikujt per t'i shtuar ne porosi</p>
                        </div>
                    </div>

                    <div v-if="cart.length" class="border-t border-neutral-200 px-4 py-4 space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-label text-neutral-500">Total</span>
                            <span class="text-h3 text-primary-900">€{{ cartTotal.toFixed(2) }}</span>
                        </div>
                        <Button variant="primary" size="lg" class="w-full" @click="submitOrder">Krijo porosine</Button>
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
                            'rounded-lg border-2 p-4 text-center transition-colors',
                            paymentMethod === opt.value ? 'border-accent-500 bg-accent-50 text-accent-700' : 'border-neutral-200 hover:border-neutral-300 text-neutral-600',
                            opt.value === 'room_charge' && !selectedOrder?.reservation_id && 'opacity-40 pointer-events-none',
                        ]"
                        @click="paymentMethod = opt.value"
                    >
                        <span class="text-body-sm font-medium">{{ opt.label }}</span>
                    </button>
                </div>
            </div>
            <template #footer>
                <Button variant="outline" @click="showPayModal = false">Anulo</Button>
                <Button variant="primary" :disabled="!paymentMethod" @click="submitPay">Paguaj €{{ selectedOrder?.total_amount }}</Button>
            </template>
        </Modal>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
