<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import Modal from '@/Components/UI/Modal.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';
import {
    ArrowRightLeft, Banknote, Check, ChefHat, FileText, Minus,
    Plus, Printer, ReceiptText, Search, ShoppingCart, Users,
} from 'lucide-vue-next';

const props = defineProps({
    tables: { type: Array, default: () => [] },
    areas: { type: Array, default: () => [] },
    menu: { type: Array, default: () => [] },
    activeReservations: { type: Array, default: () => [] },
    currentShift: { type: Object, default: null },
    currency: { type: String, default: 'EUR' },
    printRoundId: { type: Number, default: null },
    selectedTableId: { type: Number, default: null },
    autoAction: { type: String, default: '' },
    stats: { type: Object, default: () => ({}) },
});

const toasts = ref(null);
const activeArea = ref(props.areas[0] || 'Salla kryesore');
const selectedTableId = ref(props.selectedTableId || null);
const activeCategory = ref(props.menu[0]?.id || null);
const searchQuery = ref('');
const cart = ref([]);
const covers = ref(2);
const showRoundModal = ref(false);
const showSummaryModal = ref(false);
const showTransferModal = ref(false);
const showPaymentModal = ref(false);
const destinationTableId = ref('');
const paymentMethod = ref('cash');
const paymentReservationId = ref('');
const splitCashAmount = ref('');
const saving = ref(false);
const printRound = ref(null);
const printTable = ref(null);

const selectedTable = computed(() => props.tables.find((table) => Number(table.id) === Number(selectedTableId.value)) || null);
const selectedOrder = computed(() => selectedTable.value?.open_order || null);
const areaTables = computed(() => props.tables.filter((table) => table.area === activeArea.value));
const freeTables = computed(() => props.tables.filter((table) => table.status === 'free' && Number(table.id) !== Number(selectedTableId.value)));
const cartCount = computed(() => cart.value.reduce((sum, item) => sum + item.quantity, 0));
const cartTotal = computed(() => cart.value.reduce((sum, item) => sum + item.price * item.quantity, 0));
const splitCash = computed(() => Math.min(Number(selectedOrder.value?.total_amount || 0), Math.max(0, Number(splitCashAmount.value || 0))));
const splitCard = computed(() => Math.max(0, Math.round((Number(selectedOrder.value?.total_amount || 0) - splitCash.value) * 100) / 100));
const allMenuItems = computed(() => props.menu.flatMap((category) => category.items.map((item) => ({ ...item, category_id: category.id }))));
const visibleMenuItems = computed(() => {
    const query = searchQuery.value.trim().toLocaleLowerCase('sq');
    if (query) return allMenuItems.value.filter((item) => item.name.toLocaleLowerCase('sq').includes(query));
    return props.menu.find((category) => Number(category.id) === Number(activeCategory.value))?.items || [];
});

function money(value) {
    return new Intl.NumberFormat('sq-AL', { style: 'currency', currency: props.currency }).format(Number(value || 0));
}

function time(value) {
    if (!value) return '—';
    return new Date(value).toLocaleTimeString('sq-AL', { hour: '2-digit', minute: '2-digit' });
}

function elapsed(value) {
    if (!value) return '0 min';
    const minutes = Math.max(0, Math.floor((Date.now() - new Date(value).getTime()) / 60000));
    return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

function tableStatus(table) {
    return table.status === 'free'
        ? { label: 'E lirë', badge: 'success' }
        : table.status === 'bill_requested'
            ? { label: 'Pret faturën', badge: 'warning' }
            : { label: 'E zënë', badge: 'info' };
}

function selectTable(table) {
    if (!props.currentShift) {
        toasts.value?.error('Hap një turn përpara se të zgjedhësh tavolinën.');
        return;
    }
    selectedTableId.value = table.id;
    showSummaryModal.value = false;
    nextTick(() => openRound());
}

function openRound() {
    if (!props.currentShift) {
        toasts.value?.error('Hap një turn përpara se të regjistrosh porosi.');
        return;
    }
    cart.value = [];
    covers.value = selectedOrder.value?.covers || 2;
    activeCategory.value = props.menu[0]?.id || null;
    showRoundModal.value = true;
}

function openSummaryFromTouch() {
    showRoundModal.value = false;
    showSummaryModal.value = true;
}

function openPaymentFromTouch() {
    showRoundModal.value = false;
    openPayment();
}

function addItem(item) {
    const existing = cart.value.find((line) => Number(line.id) === Number(item.id));
    if (existing) existing.quantity += 1;
    else cart.value.push({ id: item.id, name: item.name, price: Number(item.price), image_path: item.image_path, quantity: 1 });
}

function changeQuantity(item, delta) {
    item.quantity += delta;
    if (item.quantity <= 0) cart.value = cart.value.filter((line) => line !== item);
}

function findRound(tables, roundId) {
    for (const table of tables || []) {
        const round = table.open_order?.rounds?.find((item) => Number(item.id) === Number(roundId));
        if (round) return { table, round };
    }
    return null;
}

function printProductionTicket(table, round) {
    printTable.value = table;
    printRound.value = round;
    nextTick(() => {
        document.body.classList.add('printing-production-ticket');
        window.print();
        window.setTimeout(() => document.body.classList.remove('printing-production-ticket'), 500);
    });
}

function submitRound(send) {
    if (!selectedTable.value || !cart.value.length || saving.value) return;
    saving.value = true;
    router.post(route('pos.tables.rounds.store', selectedTable.value.id), {
        items: cart.value.map((item) => ({ menu_item_id: item.id, quantity: item.quantity })),
        covers: selectedOrder.value ? null : covers.value,
        send,
    }, {
        preserveScroll: true,
        onSuccess: (page) => {
            showRoundModal.value = false;
            cart.value = [];
            const error = page.props.flash?.error;
            if (error) {
                toasts.value?.error(error);
                return;
            }
            toasts.value?.success(page.props.flash?.success || (send ? 'Porosia u dërgua.' : 'Porosia u ruajt.'));
            if (send && page.props.printRoundId) {
                const found = findRound(page.props.tables, page.props.printRoundId);
                if (found) printProductionTicket(found.table, found.round);
            }
            selectedTableId.value = null;
        },
        onError: (errors) => toasts.value?.error(errors.inventory || errors.items || 'Porosia nuk u ruajt.'),
        onFinish: () => { saving.value = false; },
    });
}

function sendDraft(round) {
    if (!round.id || saving.value) return;
    saving.value = true;
    router.post(route('pos.rounds.send', round.id), {}, {
        preserveScroll: true,
        onSuccess: (page) => {
            toasts.value?.success(page.props.flash?.success || 'Porosia u dërgua.');
            const found = findRound(page.props.tables, page.props.printRoundId);
            if (found) printProductionTicket(found.table, found.round);
        },
        onError: () => toasts.value?.error('Porosia nuk u dërgua.'),
        onFinish: () => { saving.value = false; },
    });
}

function toggleBillRequest() {
    if (!selectedTable.value?.open_order) return;
    router.post(route('pos.tables.bill', selectedTable.value.id), {}, { preserveScroll: true });
}

function transferTable() {
    if (!destinationTableId.value) return;
    router.post(route('pos.tables.transfer', selectedTable.value.id), {
        destination_table_id: destinationTableId.value,
    }, {
        preserveScroll: true,
        onSuccess: (page) => {
            showTransferModal.value = false;
            destinationTableId.value = '';
            selectedTableId.value = page.props.selectedTableId || selectedTableId.value;
            toasts.value?.success(page.props.flash?.success || 'Llogaria u transferua.');
        },
        onError: (errors) => toasts.value?.error(errors.destination_table_id || 'Transferimi nuk u krye.'),
    });
}

function openPayment() {
    if (!props.currentShift) {
        toasts.value?.error('Hap një turn përpara pagesës.');
        return;
    }
    if (selectedOrder.value?.rounds?.some((round) => round.status === 'draft')) {
        toasts.value?.error('Dërgo dhe printo të gjitha porositë para pagesës.');
        return;
    }
    paymentMethod.value = 'cash';
    paymentReservationId.value = '';
    splitCashAmount.value = '';
    showPaymentModal.value = true;
}

function payTable() {
    if (!selectedOrder.value || !paymentMethod.value) return;
    if (paymentMethod.value === 'room_charge' && !paymentReservationId.value) {
        toasts.value?.error('Zgjidh dhomën ose mysafirin.');
        return;
    }
    const payments = paymentMethod.value === 'split'
        ? [{ method: 'cash', amount: splitCash.value }, { method: 'card', amount: splitCard.value }]
        : [];
    if (paymentMethod.value === 'split' && (!splitCash.value || !splitCard.value)) {
        toasts.value?.error('Vendos një ndarje të vlefshme mes cash dhe kartës.');
        return;
    }
    saving.value = true;
    router.post(route('pos.complete', selectedOrder.value.id), {
        payment_method: paymentMethod.value === 'split' ? null : paymentMethod.value,
        payments,
        reservation_id: paymentMethod.value === 'room_charge' ? paymentReservationId.value : null,
        return_to: 'tables',
        table_id: selectedTable.value.id,
    }, {
        preserveScroll: true,
        onSuccess: (page) => {
            showPaymentModal.value = false;
            selectedTableId.value = null;
            toasts.value?.success(page.props.flash?.success || 'Pagesa u regjistrua dhe tavolina u lirua.');
        },
        onError: (errors) => toasts.value?.error(errors.order || errors.payments || errors.reservation_id || 'Pagesa nuk u regjistrua.'),
        onFinish: () => { saving.value = false; },
    });
}

onMounted(() => {
    if (props.autoAction === 'pay' && selectedOrder.value) openPayment();
});
</script>

<template>
    <AppLayout>
        <div class="space-y-5">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                <div>
                    <p class="text-small font-semibold text-accent-700">POS Bar/Restorant / Shitje</p>
                    <h1 class="mt-1 text-h2 text-primary-900">Shitje POS</h1>
                    <p class="mt-1 text-body-sm text-neutral-500">Zgjidh tavolinën, shto porosinë në Touch POS dhe dërgoje për printim.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :variant="currentShift ? 'success' : 'warning'" dot size="sm">
                        {{ currentShift ? `Turn aktiv · ${currentShift.user_name} · ${currentShift.opened_at}` : 'Pa turn aktiv' }}
                    </Badge>
                    <Button v-if="selectedTable" variant="primary" @click="openRound"><Plus class="h-4 w-4" /> Shto porosi</Button>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Card class="!p-4"><p class="text-small text-neutral-500">Tavolina</p><p class="mt-1 text-h3 text-primary-900">{{ stats.total }}</p></Card>
                <Card class="!p-4"><p class="text-small text-neutral-500">Të zëna</p><p class="mt-1 text-h3 text-info-700">{{ stats.occupied }}</p></Card>
                <Card class="!p-4"><p class="text-small text-neutral-500">Presin faturën</p><p class="mt-1 text-h3 text-warning-700">{{ stats.bill_requested }}</p></Card>
                <Card class="!p-4"><p class="text-small text-neutral-500">Llogari të hapura</p><p class="mt-1 text-h3 text-accent-700">{{ money(stats.open_total) }}</p></Card>
            </div>

            <div
                class="grid min-h-[620px] gap-5"
                :class="selectedTable ? '2xl:grid-cols-[minmax(0,1.2fr)_minmax(430px,0.8fr)]' : 'grid-cols-1'"
            >
                <Card :padding="false" class="overflow-hidden">
                    <div class="flex flex-col gap-3 border-b border-neutral-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div><h2 class="text-h4 text-primary-900">Zgjidh tavolinën</h2><p class="mt-1 text-small text-neutral-500">Prek një tavolinë dhe POS Touch hapet menjëherë.</p></div>
                        <div class="flex gap-2 overflow-x-auto">
                            <button v-for="area in areas" :key="area" type="button" class="rounded-lg border px-3 py-2 text-small font-semibold whitespace-nowrap" :class="activeArea === area ? 'border-accent-600 bg-accent-50 text-accent-700' : 'border-neutral-200 text-neutral-500'" @click="activeArea = area">{{ area }}</button>
                        </div>
                    </div>
                    <div class="grid gap-3 p-5 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-5">
                        <button
                            v-for="table in areaTables"
                            :key="table.id"
                            type="button"
                            class="min-h-36 rounded-xl border-2 p-4 text-left transition hover:-translate-y-0.5 hover:shadow-card"
                            :class="[
                                selectedTableId === table.id ? 'border-accent-600 ring-2 ring-accent-100' : 'border-neutral-200',
                                table.status === 'free' ? 'bg-white' : table.status === 'bill_requested' ? 'bg-warning-50/60' : 'bg-info-50/50',
                            ]"
                            @click="selectTable(table)"
                        >
                            <div class="flex items-start justify-between gap-2"><div><p class="font-bold text-primary-900">{{ table.name }}</p><p class="mt-0.5 text-small text-neutral-500">{{ table.seats }} vende</p></div><Badge :variant="tableStatus(table).badge" dot size="sm">{{ tableStatus(table).label }}</Badge></div>
                            <div v-if="table.open_order" class="mt-7 flex items-end justify-between"><div><p class="text-h4 text-primary-900">{{ money(table.open_order.total_amount) }}</p><p class="mt-0.5 text-tiny text-neutral-500">{{ elapsed(table.open_order.created_at) }} · {{ table.open_order.rounds.length }} porosi</p></div><ArrowRightLeft class="h-4 w-4 text-neutral-400" /></div>
                            <div v-else class="mt-8 flex items-center justify-between text-small text-neutral-400"><span>Prek për ta hapur</span><Plus class="h-4 w-4" /></div>
                        </button>
                    </div>
                </Card>

                <Card v-if="selectedTable" :padding="false" class="flex min-h-0 flex-col overflow-hidden">
                    <template v-if="selectedTable">
                        <div class="border-b border-neutral-200 px-5 py-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div><div class="flex items-center gap-2"><h2 class="text-h3 text-primary-900">{{ selectedTable.name }}</h2><Badge :variant="tableStatus(selectedTable).badge" dot size="sm">{{ tableStatus(selectedTable).label }}</Badge></div><p class="mt-1 text-small text-neutral-500">{{ selectedOrder ? `${selectedOrder.covers || '—'} persona · ${elapsed(selectedOrder.created_at)} · ${selectedOrder.created_by || 'Stafi'}` : `${selectedTable.seats} vende · pa llogari të hapur` }}</p></div>
                                <Button variant="primary" size="sm" @click="openRound"><Plus class="h-4 w-4" /> Shto porosi</Button>
                            </div>
                        </div>

                        <div v-if="selectedOrder" class="min-h-0 flex-1 space-y-3 overflow-y-auto p-5">
                            <div v-for="round in selectedOrder.rounds" :key="round.id || `legacy-${round.sequence}`" class="rounded-xl border border-neutral-200 bg-white p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div><div class="flex flex-wrap items-center gap-2"><p class="font-bold text-primary-900">Porosia #{{ round.sequence }}</p><Badge :variant="round.status === 'sent' ? 'success' : 'warning'" size="sm">{{ round.status === 'sent' ? 'Dërguar & printuar' : 'Pa dërguar' }}</Badge></div><p class="mt-1 text-tiny text-neutral-500">{{ round.created_by || 'Stafi' }} · {{ time(round.created_at) }} · {{ round.destination }}</p></div>
                                    <div class="text-right"><p class="font-bold text-primary-900">{{ money(round.total) }}</p><Button v-if="round.status === 'draft'" variant="outline" size="sm" class="mt-2" :loading="saving" @click="sendDraft(round)"><Printer class="h-3.5 w-3.5" /> Dërgo & printo</Button></div>
                                </div>
                                <div class="mt-3 divide-y divide-neutral-100 border-t border-neutral-100">
                                    <div v-for="item in round.items" :key="item.id" class="flex items-center justify-between gap-3 py-2 text-body-sm"><span><b>{{ item.quantity }}×</b> {{ item.name }}</span><span class="font-semibold text-neutral-700">{{ money(item.total_price) }}</span></div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="grid flex-1 place-items-center px-6 py-16 text-center"><div><span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-neutral-100 text-neutral-400"><ReceiptText class="h-6 w-6" /></span><p class="mt-4 font-semibold text-primary-900">Tavolina është e lirë</p><p class="mt-1 text-body-sm text-neutral-500">Shto porosinë e parë për të hapur llogarinë.</p><Button variant="primary" class="mt-5" @click="openRound"><Plus class="h-4 w-4" /> Porosia e parë</Button></div></div>

                        <div v-if="selectedOrder" class="border-t border-neutral-200 bg-neutral-50 p-4">
                            <div class="mb-3 flex items-center justify-between"><span class="text-body-sm font-semibold text-neutral-600">Totali i tavolinës</span><strong class="text-h3 text-primary-900">{{ money(selectedOrder.total_amount) }}</strong></div>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                <Button variant="outline" size="sm" @click="showTransferModal = true"><ArrowRightLeft class="h-4 w-4" /> Transfero</Button>
                                <Button variant="outline" size="sm" @click="showSummaryModal = true"><FileText class="h-4 w-4" /> Përmbledhja</Button>
                                <Button :variant="selectedOrder.service_status === 'bill_requested' ? 'success' : 'outline'" size="sm" @click="toggleBillRequest"><ReceiptText class="h-4 w-4" /> {{ selectedOrder.service_status === 'bill_requested' ? 'Fatura u kërkua' : 'Kërko faturën' }}</Button>
                                <Button variant="primary" size="sm" @click="openPayment"><Banknote class="h-4 w-4" /> Paguaj</Button>
                            </div>
                        </div>
                    </template>
                </Card>
            </div>
        </div>

        <Modal :show="showRoundModal" :title="`Shitje Touch · ${selectedTable?.name || ''}`" max-width="screen" @close="showRoundModal = false">
            <div class="flex h-[calc(100dvh-8rem)] min-h-[560px] flex-col">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-neutral-200 bg-neutral-50 px-4 py-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <Badge :variant="selectedTable ? tableStatus(selectedTable).badge : 'neutral'" dot size="sm">{{ selectedTable ? tableStatus(selectedTable).label : '—' }}</Badge>
                        <span class="text-body-sm font-semibold text-primary-900">{{ selectedOrder ? `${selectedOrder.rounds.length} porosi · ${money(selectedOrder.total_amount)} gjithsej` : 'Llogari e re' }}</span>
                        <span class="text-small text-neutral-500">{{ selectedTable?.area }} · {{ selectedTable?.seats }} vende</span>
                    </div>
                    <div v-if="selectedOrder" class="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" @click="openSummaryFromTouch"><FileText class="h-4 w-4" /> Përmbledhja</Button>
                        <Button variant="primary" size="sm" @click="openPaymentFromTouch"><Banknote class="h-4 w-4" /> Paguaj</Button>
                    </div>
                </div>
                <div class="grid min-h-0 flex-1 gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(360px,0.28fr)]">
                <div class="min-w-0 overflow-y-auto pr-1">
                    <div class="relative"><Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="searchQuery" type="search" class="w-full rounded-lg border-neutral-200 py-2.5 pl-9 pr-3 text-body-sm focus:border-accent-500 focus:ring-accent-500" placeholder="Kërko produktin..." /></div>
                    <div class="mt-3 flex gap-2 overflow-x-auto pb-1"><button v-for="category in menu" :key="category.id" type="button" class="rounded-full border px-4 py-2 text-small font-semibold whitespace-nowrap" :class="activeCategory === category.id ? 'border-primary-900 bg-primary-900 text-white' : 'border-neutral-200 text-neutral-600'" @click="activeCategory = category.id">{{ category.name }}</button></div>
                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6">
                        <button v-for="item in visibleMenuItems" :key="item.id" type="button" class="overflow-hidden rounded-xl border border-neutral-200 bg-white text-left transition hover:border-accent-400 hover:shadow-card touch-manipulation" @click="addItem(item)"><div class="grid h-24 place-items-center overflow-hidden bg-neutral-100"><img v-if="item.image_path" :src="`/storage/${item.image_path}`" :alt="item.name" class="h-full w-full object-cover" /><ChefHat v-else class="h-7 w-7 text-neutral-300" /></div><div class="p-3"><p class="truncate font-semibold text-primary-900">{{ item.name }}</p><p class="mt-1 text-body-sm font-bold text-accent-700">{{ money(item.price) }}</p></div></button>
                    </div>
                </div>
                <div class="flex min-h-0 flex-col rounded-xl border border-neutral-200 bg-neutral-50">
                    <div class="border-b border-neutral-200 p-4"><div class="flex items-center justify-between"><div><p class="font-bold text-primary-900">Raundi i ri</p><p class="text-small text-neutral-500">{{ cartCount }} artikuj</p></div><div v-if="!selectedOrder" class="flex items-center gap-2 text-small"><Users class="h-4 w-4 text-neutral-400" /><input v-model.number="covers" type="number" min="1" max="99" class="w-16 rounded-lg border-neutral-200 py-1.5 text-center" /></div></div></div>
                    <div class="min-h-0 flex-1 overflow-y-auto p-3">
                        <div v-if="cart.length" class="space-y-2"><div v-for="item in cart" :key="item.id" class="rounded-lg border border-neutral-200 bg-white p-3"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="truncate font-semibold text-primary-900">{{ item.name }}</p><p class="text-small text-neutral-500">{{ money(item.price) }} / copë</p></div><strong>{{ money(item.price * item.quantity) }}</strong></div><div class="mt-3 flex items-center gap-2"><button type="button" class="grid h-9 w-9 place-items-center rounded-lg border border-neutral-200" @click="changeQuantity(item, -1)"><Minus class="h-4 w-4" /></button><span class="w-8 text-center font-bold">{{ item.quantity }}</span><button type="button" class="grid h-9 w-9 place-items-center rounded-lg border border-neutral-200" @click="changeQuantity(item, 1)"><Plus class="h-4 w-4" /></button></div></div></div>
                        <div v-else class="grid h-full min-h-64 place-items-center text-center"><div><ShoppingCart class="mx-auto h-8 w-8 text-neutral-300" /><p class="mt-3 font-semibold text-primary-900">Raundi është bosh</p><p class="mt-1 text-small text-neutral-500">Prek produktet për t’i shtuar.</p></div></div>
                    </div>
                    <div class="border-t border-neutral-200 bg-white p-4"><div class="mb-3 flex items-center justify-between"><span class="text-body-sm text-neutral-500">Totali i raundit</span><strong class="text-h3">{{ money(cartTotal) }}</strong></div><div class="grid grid-cols-2 gap-2"><Button variant="outline" size="lg" :disabled="!cart.length" :loading="saving" @click="submitRound(false)">Mbaj pa dërguar</Button><Button variant="primary" size="lg" :disabled="!cart.length" :loading="saving" @click="submitRound(true)"><Printer class="h-4 w-4" /> Dërgo & printo</Button></div></div>
                </div>
                </div>
            </div>
        </Modal>

        <Modal :show="showSummaryModal" :title="`Përmbledhja · ${selectedTable?.name || ''}`" max-width="lg" @close="showSummaryModal = false">
            <div v-if="selectedOrder" class="space-y-4"><div class="grid grid-cols-3 gap-3"><div class="rounded-lg bg-neutral-50 p-3"><p class="text-tiny text-neutral-500">Raunde</p><p class="mt-1 text-h4">{{ selectedOrder.rounds.length }}</p></div><div class="rounded-lg bg-neutral-50 p-3"><p class="text-tiny text-neutral-500">Persona</p><p class="mt-1 text-h4">{{ selectedOrder.covers || '—' }}</p></div><div class="rounded-lg bg-accent-50 p-3"><p class="text-tiny text-accent-700">Totali</p><p class="mt-1 text-h4 text-accent-800">{{ money(selectedOrder.total_amount) }}</p></div></div><div v-for="round in selectedOrder.rounds" :key="round.id || round.sequence" class="rounded-lg border border-neutral-200 p-3"><div class="flex justify-between"><strong>Porosia #{{ round.sequence }}</strong><strong>{{ money(round.total) }}</strong></div><p class="mt-1 text-small text-neutral-500">{{ round.items.map(item => `${item.quantity}× ${item.name}`).join(', ') }}</p></div></div>
        </Modal>

        <Modal :show="showTransferModal" title="Transfero llogarinë" max-width="sm" @close="showTransferModal = false">
            <p class="text-body-sm text-neutral-600">Zgjidh një tavolinë të lirë. Të gjitha raundet dhe totali kalojnë së bashku.</p><select v-model="destinationTableId" class="mt-4 w-full rounded-lg border-neutral-200 text-body-sm focus:border-accent-500 focus:ring-accent-500"><option value="">Zgjidh tavolinën...</option><option v-for="table in freeTables" :key="table.id" :value="table.id">{{ table.name }} · {{ table.area }}</option></select>
            <template #footer><Button variant="ghost" @click="showTransferModal = false">Anulo</Button><Button variant="primary" :disabled="!destinationTableId" @click="transferTable">Transfero</Button></template>
        </Modal>

        <Modal :show="showPaymentModal" :title="`Paguaj · ${selectedTable?.name || ''}`" max-width="md" @close="showPaymentModal = false">
            <div class="rounded-xl bg-primary-950 p-5 text-center text-white"><p class="text-small text-neutral-300">Totali për pagesë</p><p class="mt-1 text-3xl font-bold">{{ money(selectedOrder?.total_amount) }}</p></div>
            <div class="mt-4 grid grid-cols-2 gap-2"><button v-for="method in [{ id: 'cash', label: 'Cash', icon: '💵' }, { id: 'card', label: 'Kartë', icon: '💳' }, { id: 'split', label: 'Cash + Kartë', icon: '💵＋💳' }, { id: 'room_charge', label: 'Dhomë', icon: '🏨' }]" :key="method.id" type="button" class="rounded-xl border-2 p-3 text-center" :class="paymentMethod === method.id ? 'border-accent-500 bg-accent-50 text-accent-800' : 'border-neutral-200 text-neutral-600'" @click="paymentMethod = method.id"><span class="block text-2xl">{{ method.icon }}</span><span class="mt-1 block text-small font-bold">{{ method.label }}</span></button></div>
            <div v-if="paymentMethod === 'split'" class="mt-4 rounded-xl border border-neutral-200 bg-neutral-50 p-4"><label class="text-small font-semibold text-neutral-600">Shuma cash</label><input v-model="splitCashAmount" type="number" min="0" :max="selectedOrder?.total_amount" step="0.01" class="mt-1.5 w-full rounded-lg border-neutral-200 text-body-sm focus:border-accent-500 focus:ring-accent-500" placeholder="0.00" /><div class="mt-3 flex justify-between text-body-sm"><span class="text-neutral-500">Pjesa me kartë</span><strong>{{ money(splitCard) }}</strong></div></div>
            <select v-if="paymentMethod === 'room_charge'" v-model="paymentReservationId" class="mt-4 w-full rounded-lg border-neutral-200 text-body-sm focus:border-accent-500 focus:ring-accent-500"><option value="">Zgjidh dhomën / mysafirin...</option><option v-for="reservation in activeReservations" :key="reservation.id" :value="reservation.id">{{ reservation.label }}</option></select>
            <template #footer><Button variant="ghost" @click="showPaymentModal = false">Anulo</Button><Button variant="primary" :loading="saving" @click="payTable"><Check class="h-4 w-4" /> Konfirmo pagesën</Button></template>
        </Modal>

        <Teleport to="body">
            <section v-if="printRound && printTable" id="production-ticket" class="production-ticket">
                <h1>POROSI · {{ printTable.name }}</h1><p>Porosia #{{ printRound.sequence }} · {{ time(printRound.sent_at || printRound.created_at) }}</p><p>{{ printRound.created_by || 'Stafi' }} · {{ printRound.destination }}</p><hr /><div v-for="item in printRound.items" :key="item.id" class="ticket-line"><strong>{{ item.quantity }}×</strong><span>{{ item.name }}</span></div><hr /><p class="ticket-footer">Lora PMS · {{ new Date().toLocaleString('sq-AL') }}</p>
            </section>
        </Teleport>
        <ToastContainer ref="toasts" />
    </AppLayout>
</template>

<style>
.production-ticket { display: none; }
@media print {
    body.printing-production-ticket * { visibility: hidden !important; }
    body.printing-production-ticket #production-ticket,
    body.printing-production-ticket #production-ticket * { visibility: visible !important; }
    body.printing-production-ticket #production-ticket { display: block; position: fixed; inset: 0 auto auto 0; width: 80mm; padding: 6mm; color: #000; background: #fff; font-family: ui-monospace, monospace; font-size: 13px; }
    body.printing-production-ticket #production-ticket h1 { font-size: 20px; font-weight: 800; margin: 0 0 6px; }
    body.printing-production-ticket #production-ticket p { margin: 2px 0; }
    body.printing-production-ticket #production-ticket hr { border: 0; border-top: 1px dashed #000; margin: 10px 0; }
    body.printing-production-ticket .ticket-line { display: grid; grid-template-columns: 42px 1fr; gap: 8px; padding: 6px 0; font-size: 16px; }
    body.printing-production-ticket .ticket-footer { font-size: 10px; text-align: center; }
}
</style>
