<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { getIntlLocale, translate } from '@/i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import Modal from '@/Components/UI/Modal.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';
import PosSalespersonSwitcher from '@/Components/Pos/PosSalespersonSwitcher.vue';
import OutletSwitcher from '@/Components/Pos/OutletSwitcher.vue';
import {
    ArrowRightLeft, Banknote, Check, FileText,
    Plus, Printer, ReceiptText,
} from 'lucide-vue-next';

const props = defineProps({
    tables: { type: Array, default: () => [] },
    areas: { type: Array, default: () => [] },
    activeReservations: { type: Array, default: () => [] },
    currentShift: { type: Object, default: null },
    currency: { type: String, default: 'EUR' },
    printRoundId: { type: Number, default: null },
    selectedTableId: { type: Number, default: null },
    autoAction: { type: String, default: '' },
    stats: { type: Object, default: () => ({}) },
    currentSalesperson: { type: Object, default: null },
    salespeople: { type: Array, default: () => [] },
    posSettings: { type: Object, default: () => ({}) },
    payCurrencies: { type: Array, default: () => [] },
    outlets: { type: Array, default: () => [] },
    currentOutletId: { type: Number, default: null },
});

const toasts = ref(null);
const activeArea = ref(props.areas[0] || 'Salla kryesore');
const selectedTableId = ref(props.selectedTableId || null);
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
const freeCount = computed(() => Math.max(0, Number(props.stats.total || 0) - Number(props.stats.occupied || 0) - Number(props.stats.bill_requested || 0)));
const splitCash = computed(() => Math.min(Number(selectedOrder.value?.total_amount || 0), Math.max(0, Number(splitCashAmount.value || 0))));
const splitCard = computed(() => Math.max(0, Math.round((Number(selectedOrder.value?.total_amount || 0) - splitCash.value) * 100) / 100));

const payCurrency = ref('');
const splitCashCurrency = ref('');
const posBaseCurrency = computed(() => props.payCurrencies[0]?.code || 'EUR');
const multiCurrency = computed(() => (props.payCurrencies || []).length > 1);
const orderTotal = computed(() => Number(selectedOrder.value?.total_amount || 0));

function fxRateFor(code) {
    return Number((props.payCurrencies || []).find((entry) => entry.code === code)?.rate || 1);
}

function toTendered(baseAmount, code) {
    const rate = fxRateFor(code);
    return rate > 0 ? Math.round((baseAmount / rate) * 100) / 100 : 0;
}

const payTendered = computed(() => (
    payCurrency.value && payCurrency.value !== posBaseCurrency.value && (paymentMethod.value === 'cash' || paymentMethod.value === 'card')
        ? toTendered(orderTotal.value, payCurrency.value)
        : null
));

const splitCashTendered = computed(() => (
    splitCashCurrency.value && splitCashCurrency.value !== posBaseCurrency.value && splitCash.value > 0
        ? toTendered(splitCash.value, splitCashCurrency.value)
        : null
));

function money(value) {
    return new Intl.NumberFormat(getIntlLocale(), { style: 'currency', currency: props.currency }).format(Number(value || 0));
}

function time(value) {
    if (!value) return '—';
    return new Date(value).toLocaleTimeString(getIntlLocale(), { hour: '2-digit', minute: '2-digit' });
}

function elapsed(value) {
    if (!value) return '0 min';
    const minutes = Math.max(0, Math.floor((Date.now() - new Date(value).getTime()) / 60000));
    return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

function tableStatus(table) {
    return table.status === 'free'
        ? { label: translate('posTables.statusFree'), badge: 'success' }
        : table.status === 'bill_requested'
            ? { label: translate('posTables.statusBillRequested'), badge: 'warning' }
            : { label: translate('posTables.statusOccupied'), badge: 'info' };
}

function tableShortName(table) {
    return `T${table.number || table.id}`;
}

function selectTable(table) {
    selectedTableId.value = table.id;
    showSummaryModal.value = false;
}

function openOrder() {
    if (!props.currentShift) {
        toasts.value?.error(translate('posTables.openShiftBeforeOrder'));
        return;
    }
    router.visit(route('pos.index', { table: selectedTable.value.id }));
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

function printTableSummary() {
    if (!selectedOrder.value) return;
    document.body.classList.add('printing-table-summary');
    window.print();
    window.setTimeout(() => document.body.classList.remove('printing-table-summary'), 500);
}

function payFromSummary() {
    showSummaryModal.value = false;
    openPayment();
}

function fiscalizeFromSummary() {
    toasts.value?.info(translate('posTables.fiscalizeAfterPaymentInfo'));
    payFromSummary();
}

function sendDraft(round) {
    if (!round.id || saving.value) return;
    saving.value = true;
    router.post(route('pos.rounds.send', round.id), {}, {
        preserveScroll: true,
        onSuccess: (page) => {
            toasts.value?.success(page.props.flash?.success || translate('posTables.roundSentToast'));
            const found = findRound(page.props.tables, page.props.printRoundId);
            if (found) printProductionTicket(found.table, found.round);
        },
        onError: () => toasts.value?.error(translate('posTables.roundNotSentToast')),
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
            toasts.value?.success(page.props.flash?.success || translate('posTables.accountTransferred'));
        },
        onError: (errors) => toasts.value?.error(errors.destination_table_id || translate('posTables.transferFailed')),
    });
}

function openPayment() {
    if (!props.currentShift) {
        toasts.value?.error(translate('posTables.openShiftBeforePayment'));
        return;
    }
    if (selectedOrder.value?.rounds?.some((round) => round.status === 'draft')) {
        toasts.value?.error(translate('posTables.sendAllBeforePayment'));
        return;
    }
    paymentMethod.value = 'cash';
    payCurrency.value = posBaseCurrency.value;
    splitCashCurrency.value = posBaseCurrency.value;
    paymentReservationId.value = '';
    splitCashAmount.value = '';
    showPaymentModal.value = true;
}

function payTable() {
    if (!selectedOrder.value || !paymentMethod.value) return;
    if (paymentMethod.value === 'room_charge' && !paymentReservationId.value) {
        toasts.value?.error(translate('posTables.selectRoomOrGuest'));
        return;
    }
    const payments = [];
    if (paymentMethod.value === 'split') {
        const cashTender = { method: 'cash', amount: splitCash.value };
        if (splitCashTendered.value !== null) {
            cashTender.currency = splitCashCurrency.value;
            cashTender.tendered_amount = splitCashTendered.value;
        }
        payments.push(cashTender, { method: 'card', amount: splitCard.value });
    } else if (payTendered.value !== null) {
        payments.push({ method: paymentMethod.value, amount: orderTotal.value, currency: payCurrency.value, tendered_amount: payTendered.value });
    }
    if (paymentMethod.value === 'split' && (!splitCash.value || !splitCard.value)) {
        toasts.value?.error(translate('posTables.invalidSplit'));
        return;
    }
    saving.value = true;
    router.post(route('pos.complete', selectedOrder.value.id), {
        payment_method: paymentMethod.value === 'split' || payments.length ? null : paymentMethod.value,
        payments,
        reservation_id: paymentMethod.value === 'room_charge' ? paymentReservationId.value : null,
    }, {
        preserveScroll: true,
        onSuccess: () => { showPaymentModal.value = false; },
        onError: (errors) => toasts.value?.error(errors.order || errors.payments || errors.reservation_id || Object.values(errors)[0] || translate('posTables.paymentNotRecorded')),
        onFinish: () => { saving.value = false; },
    });
}

onMounted(() => {
    if (props.printRoundId) {
        const found = findRound(props.tables, props.printRoundId);
        if (found) printProductionTicket(found.table, found.round);
    }
    if (props.autoAction === 'pay' && selectedOrder.value) openPayment();
});
</script>

<template>
    <AppLayout :immersive="true">
        <div class="flex h-full min-h-0 flex-col gap-3 bg-neutral-100 p-3">
            <div class="grid shrink-0 gap-3 rounded-xl border border-neutral-200 bg-white p-4 shadow-card xl:grid-cols-[minmax(220px,auto)_minmax(0,1fr)] xl:items-center">
                <div class="shrink-0">
                    <p class="text-small font-semibold text-accent-700">{{ $t('posTables.breadcrumb') }}</p>
                    <div class="mt-0.5 flex flex-wrap items-center gap-3">
                        <h1 class="text-h2 text-primary-900">{{ $t('posTables.title') }}</h1>
                        <Badge :variant="currentShift ? 'success' : 'warning'" dot size="sm">
                            {{ currentShift ? $t('posTables.shiftActive', { user: currentShift.user_name, openedAt: currentShift.opened_at }) : $t('posTables.shiftNone') }}
                        </Badge>
                    </div>
                </div>
                <div class="flex min-w-0 flex-nowrap items-center gap-2 overflow-x-auto pb-1 xl:justify-end xl:pb-0">
                    <OutletSwitcher dense :outlets="outlets" :current-outlet-id="currentOutletId" />
                    <PosSalespersonSwitcher v-if="posSettings.salesperson_enabled" dense :current="currentSalesperson" :salespeople="salespeople" />
                    <div v-if="selectedTable" class="inline-flex h-12 shrink-0 items-center rounded-lg bg-neutral-100 px-3 text-body-sm font-bold whitespace-nowrap text-primary-900">
                        {{ selectedTable.name }} · {{ selectedOrder ? money(selectedOrder.total_amount) : $t('posTables.statusFree') }}
                    </div>
                    <Button class="h-12 shrink-0 whitespace-nowrap" variant="primary" :disabled="!selectedTable" @click="openOrder"><Plus class="h-5 w-5" /> {{ $t('posTables.orderButton') }}</Button>
                    <Button class="h-12 shrink-0 whitespace-nowrap" variant="outline" :disabled="!selectedOrder" @click="showSummaryModal = true"><FileText class="h-5 w-5" /> {{ $t('posTables.summary') }}</Button>
                    <Button class="h-12 shrink-0 whitespace-nowrap" variant="success" :disabled="!selectedOrder" @click="openPayment"><Banknote class="h-5 w-5" /> {{ $t('posTables.pay') }}</Button>
                    <span v-if="posSettings.service_mode !== 'tables'" class="mx-1 h-8 w-px shrink-0 bg-neutral-200" aria-hidden="true"></span>
                    <Button v-if="posSettings.service_mode !== 'tables'" class="h-12 shrink-0 whitespace-nowrap" variant="ghost" :href="route('pos.index', { direct: 1 })">{{ $t('posTables.directSale') }}</Button>
                </div>
            </div>

            <div
                class="grid min-h-0 flex-1 gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(300px,0.4fr)]"
            >
                <Card :padding="false" class="flex min-h-0 flex-col overflow-hidden">
                    <div class="flex flex-col gap-3 border-b border-neutral-200 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex gap-2 overflow-x-auto">
                            <button v-for="area in areas" :key="area" type="button" class="rounded-lg border px-3 py-2 text-small font-semibold whitespace-nowrap" :class="activeArea === area ? 'border-accent-600 bg-accent-50 text-accent-700' : 'border-neutral-200 text-neutral-500'" @click="activeArea = area">{{ area }}</button>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-small text-neutral-500">
                            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-success-500"></span>{{ $t('posTables.legendFree', { count: freeCount }) }}</span>
                            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-info-500"></span>{{ $t('posTables.legendOccupied', { count: stats.occupied }) }}</span>
                            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-warning-500"></span>{{ $t('posTables.legendBillRequested', { count: stats.bill_requested }) }}</span>
                            <span class="font-semibold text-accent-700">{{ $t('posTables.legendOpenTotal', { amount: money(stats.open_total) }) }}</span>
                        </div>
                    </div>
                    <div class="grid min-h-0 flex-1 content-start gap-3 overflow-y-auto p-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                        <button
                            v-for="table in areaTables"
                            :key="table.id"
                            type="button"
                            class="relative grid min-h-28 place-content-center gap-1.5 rounded-xl border-2 p-3 text-center transition hover:-translate-y-0.5 hover:shadow-card touch-manipulation"
                            :class="[
                                Number(selectedTableId) === Number(table.id) ? 'border-accent-600 ring-2 ring-accent-100' : 'border-neutral-200',
                                table.status === 'free' ? 'bg-success-50/50' : table.status === 'bill_requested' ? 'bg-warning-50/70' : 'bg-info-50/60',
                            ]"
                            :aria-label="`${table.name}, ${$t('posTables.seatsCount', { count: table.seats })}, ${tableStatus(table).label}${table.open_order ? `, ${money(table.open_order.total_amount)}` : ''}`"
                            @click="selectTable(table)"
                        >
                            <span class="absolute right-3 top-3 h-2.5 w-2.5 rounded-full" :class="table.status === 'free' ? 'bg-success-500' : table.status === 'bill_requested' ? 'bg-warning-500' : 'bg-info-500'"></span>
                            <strong class="text-h3 text-primary-900">{{ tableShortName(table) }}</strong>
                            <span class="text-small text-neutral-500">{{ table.open_order ? money(table.open_order.total_amount) : $t('posTables.seatsCount', { count: table.seats }) }}</span>
                        </button>
                    </div>
                </Card>

                <Card :padding="false" class="flex min-h-0 flex-col overflow-hidden">
                    <template v-if="selectedTable">
                        <div class="border-b border-neutral-200 px-5 py-4">
                            <div class="flex items-start justify-between gap-3">
                                <div><div class="flex items-center gap-2"><h2 class="text-h3 text-primary-900">{{ selectedTable.name }}</h2><Badge :variant="tableStatus(selectedTable).badge" dot size="sm">{{ tableStatus(selectedTable).label }}</Badge></div><p class="mt-1 text-small text-neutral-500">{{ selectedOrder ? $t('posTables.coversLine', { covers: selectedOrder.covers || '—', elapsed: elapsed(selectedOrder.created_at), staff: selectedOrder.created_by || $t('posTables.staffFallback') }) : $t('posTables.seatsNoOrder', { count: selectedTable.seats }) }}</p></div>
                                <strong class="text-h3 text-primary-900">{{ money(selectedOrder?.total_amount) }}</strong>
                            </div>
                        </div>

                        <div v-if="selectedOrder" class="min-h-0 flex-1 divide-y divide-neutral-200 overflow-y-auto px-5">
                            <div v-for="round in selectedOrder.rounds" :key="round.id || `legacy-${round.sequence}`" class="py-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div><div class="flex flex-wrap items-center gap-2"><p class="font-bold text-primary-900">{{ $t('posTables.roundNumber', { sequence: round.sequence }) }}</p><Badge :variant="round.status === 'sent' ? 'success' : 'warning'" size="sm">{{ round.status === 'sent' ? $t('posTables.roundSentPrinted') : $t('posTables.roundNotSentBadge') }}</Badge></div><p class="mt-1 text-tiny text-neutral-500">{{ round.created_by || $t('posTables.staffFallback') }} · {{ time(round.created_at) }} · {{ round.destination }}</p></div>
                                    <div class="text-right"><p class="font-bold text-primary-900">{{ money(round.total) }}</p><Button v-if="round.status === 'draft'" variant="outline" size="sm" class="mt-2" :loading="saving" @click="sendDraft(round)"><Printer class="h-3.5 w-3.5" /> {{ $t('posTables.sendPrint') }}</Button></div>
                                </div>
                                <div class="mt-3 divide-y divide-neutral-100 border-t border-neutral-100">
                                    <div v-for="item in round.items" :key="item.id" class="flex items-center justify-between gap-3 py-2 text-body-sm"><span><b>{{ item.quantity }}×</b> {{ item.name }}</span><span class="font-semibold text-neutral-700">{{ money(item.total_price) }}</span></div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="grid flex-1 place-items-center px-6 py-16 text-center"><div><span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-neutral-100 text-neutral-400"><ReceiptText class="h-6 w-6" /></span><p class="mt-4 font-semibold text-primary-900">{{ $t('posTables.tableFreeTitle') }}</p><p class="mt-1 text-body-sm text-neutral-500">{{ $t('posTables.tableFreeHint') }}</p></div></div>

                        <div v-if="selectedOrder" class="border-t border-neutral-200 bg-neutral-50 p-4">
                            <div v-if="posSettings.salesperson_enabled" class="mb-3 flex items-center justify-between gap-3 rounded-lg border border-neutral-200 bg-white p-2.5">
                                <div class="min-w-0"><p class="text-tiny font-semibold uppercase tracking-wide text-neutral-400">{{ $t('posTables.tableSalesperson') }}</p><p class="truncate text-body-sm font-bold text-primary-900">{{ selectedOrder.salesperson?.name || selectedOrder.created_by || $t('posTables.staffFallback') }}</p></div>
                                <PosSalespersonSwitcher compact :current="selectedOrder.salesperson || currentSalesperson" :salespeople="salespeople" :order-id="selectedOrder.id" />
                            </div>
                            <div class="mb-3 flex items-center justify-between"><span class="text-body-sm font-semibold text-neutral-600">{{ $t('posTables.tableTotal') }}</span><strong class="text-h3 text-primary-900">{{ money(selectedOrder.total_amount) }}</strong></div>
                            <div class="grid grid-cols-2 gap-2">
                                <Button variant="outline" size="sm" @click="showTransferModal = true"><ArrowRightLeft class="h-4 w-4" /> {{ $t('posTables.transfer') }}</Button>
                                <Button :variant="selectedOrder.service_status === 'bill_requested' ? 'success' : 'outline'" size="sm" @click="toggleBillRequest"><ReceiptText class="h-4 w-4" /> {{ selectedOrder.service_status === 'bill_requested' ? $t('posTables.billRequestedBtn') : $t('posTables.requestBill') }}</Button>
                            </div>
                        </div>
                    </template>
                    <div v-else class="grid flex-1 place-items-center px-6 py-16 text-center">
                        <div><span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-neutral-100 text-neutral-400"><ReceiptText class="h-6 w-6" /></span><p class="mt-4 font-semibold text-primary-900">{{ $t('posTables.selectTableTitle') }}</p><p class="mt-1 text-body-sm text-neutral-500">{{ $t('posTables.selectTableHint') }}</p></div>
                    </div>
                </Card>
            </div>
        </div>

        <Modal :show="showSummaryModal" :title="$t('posTables.summaryTitle', { name: selectedTable?.name || '' })" max-width="lg" @close="showSummaryModal = false">
            <section v-if="selectedOrder" id="table-account-summary" class="space-y-4">
                <div class="grid grid-cols-3 gap-3">
                    <div class="rounded-lg bg-neutral-50 p-3"><p class="text-tiny text-neutral-500">{{ $t('posTables.statOrders') }}</p><p class="mt-1 text-h4">{{ selectedOrder.rounds.length }}</p></div>
                    <div class="rounded-lg bg-neutral-50 p-3"><p class="text-tiny text-neutral-500">{{ $t('posTables.statCovers') }}</p><p class="mt-1 text-h4">{{ selectedOrder.covers || '—' }}</p></div>
                    <div class="rounded-lg bg-accent-50 p-3"><p class="text-tiny text-accent-700">{{ $t('posTables.total') }}</p><p class="mt-1 text-h4 text-accent-800">{{ money(selectedOrder.total_amount) }}</p></div>
                </div>
                <div v-for="round in selectedOrder.rounds" :key="round.id || round.sequence" class="rounded-lg border border-neutral-200 p-3">
                    <div class="flex justify-between"><strong>{{ $t('posTables.roundNumber', { sequence: round.sequence }) }}</strong><strong>{{ money(round.total) }}</strong></div>
                    <p class="mt-1 text-small text-neutral-500">{{ round.items.map(item => `${item.quantity}× ${item.name}`).join(', ') }}</p>
                </div>
                <div class="flex items-center justify-between border-t border-neutral-200 pt-4 text-h4"><span>{{ $t('posTables.total') }}</span><strong>{{ money(selectedOrder.total_amount) }}</strong></div>
            </section>
            <template #footer>
                <Button variant="ghost" @click="showSummaryModal = false">{{ $t('posTables.close') }}</Button>
                <Button variant="outline" @click="printTableSummary"><Printer class="h-4 w-4" /> {{ $t('posTables.print') }}</Button>
                <Button variant="primary" @click="payFromSummary"><Banknote class="h-4 w-4" /> {{ $t('posTables.pay') }}</Button>
                <Button variant="outline" @click="fiscalizeFromSummary"><ReceiptText class="h-4 w-4" /> {{ $t('posTables.fiscalizeAfterPayment') }}</Button>
            </template>
        </Modal>

        <Modal :show="showTransferModal" :title="$t('posTables.transferTitle')" max-width="sm" @close="showTransferModal = false">
            <p class="text-body-sm text-neutral-600">{{ $t('posTables.transferHint') }}</p><select v-model="destinationTableId" class="mt-4 w-full rounded-lg border-neutral-200 text-body-sm focus:border-accent-500 focus:ring-accent-500"><option value="">{{ $t('posTables.selectTablePlaceholder') }}</option><option v-for="table in freeTables" :key="table.id" :value="table.id">{{ table.name }} · {{ table.area }}</option></select>
            <template #footer><Button variant="ghost" @click="showTransferModal = false">{{ $t('posTables.cancel') }}</Button><Button variant="primary" :disabled="!destinationTableId" @click="transferTable">{{ $t('posTables.transfer') }}</Button></template>
        </Modal>

        <Modal :show="showPaymentModal" :title="$t('posTables.payTitle', { name: selectedTable?.name || '' })" max-width="md" @close="showPaymentModal = false">
            <div class="rounded-xl bg-primary-950 p-5 text-center text-white"><p class="text-small text-neutral-300">{{ $t('posTables.totalDue') }}</p><p class="mt-1 text-3xl font-bold">{{ money(selectedOrder?.total_amount) }}</p></div>
            <div class="mt-4 grid grid-cols-2 gap-2"><button v-for="method in [{ id: 'cash', label: $t('posTables.payCash'), icon: '💵' }, { id: 'card', label: $t('posTables.payCard'), icon: '💳' }, { id: 'split', label: $t('posTables.paySplit'), icon: '💵＋💳' }, { id: 'room_charge', label: $t('posTables.payRoom'), icon: '🏨' }]" :key="method.id" type="button" class="rounded-xl border-2 p-3 text-center" :class="paymentMethod === method.id ? 'border-accent-500 bg-accent-50 text-accent-800' : 'border-neutral-200 text-neutral-600'" @click="paymentMethod = method.id"><span class="block text-2xl">{{ method.icon }}</span><span class="mt-1 block text-small font-bold">{{ method.label }}</span></button></div>
            <div v-if="multiCurrency && (paymentMethod === 'cash' || paymentMethod === 'card')" class="mt-4 rounded-xl border border-neutral-200 bg-neutral-50 p-4">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-small font-semibold text-neutral-600">{{ $t('posTables.paymentCurrency') }}</span>
                    <select v-model="payCurrency" class="rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500">
                        <option v-for="entry in payCurrencies" :key="entry.code" :value="entry.code">{{ entry.code }}</option>
                    </select>
                </div>
                <template v-if="payTendered !== null">
                    <div class="mt-3 flex items-center justify-between text-body-sm">
                        <span class="text-neutral-500">{{ $t('posTables.collectFromCustomer') }}</span>
                        <strong class="text-lg">{{ payTendered.toFixed(2) }} {{ payCurrency }}</strong>
                    </div>
                    <p class="mt-1 text-tiny text-neutral-400">{{ $t('posTables.fxRateNote', { from: payCurrency, rate: fxRateFor(payCurrency).toFixed(2), to: posBaseCurrency, currency: payCurrency }) }}</p>
                </template>
            </div>
            <div v-if="paymentMethod === 'split'" class="mt-4 rounded-xl border border-neutral-200 bg-neutral-50 p-4">
                <label class="text-small font-semibold text-neutral-600">{{ $t('posTables.cashAmount') }}</label>
                <input v-model="splitCashAmount" type="number" min="0" :max="selectedOrder?.total_amount" step="0.01" class="mt-1.5 w-full rounded-lg border-neutral-200 text-body-sm focus:border-accent-500 focus:ring-accent-500" placeholder="0.00" />
                <div v-if="multiCurrency" class="mt-3 flex items-center justify-between gap-3">
                    <span class="text-small font-semibold text-neutral-600">{{ $t('posTables.cashCurrency') }}</span>
                    <select v-model="splitCashCurrency" class="rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500">
                        <option v-for="entry in payCurrencies" :key="entry.code" :value="entry.code">{{ entry.code }}</option>
                    </select>
                </div>
                <div v-if="splitCashTendered !== null" class="mt-2 flex items-center justify-between text-body-sm">
                    <span class="text-neutral-500">{{ $t('posTables.collectCashFromCustomer') }}</span>
                    <strong>{{ splitCashTendered.toFixed(2) }} {{ splitCashCurrency }}</strong>
                </div>
                <div class="mt-3 flex justify-between text-body-sm"><span class="text-neutral-500">{{ $t('posTables.cardPortion') }}</span><strong>{{ money(splitCard) }}</strong></div>
            </div>
            <select v-if="paymentMethod === 'room_charge'" v-model="paymentReservationId" class="mt-4 w-full rounded-lg border-neutral-200 text-body-sm focus:border-accent-500 focus:ring-accent-500"><option value="">{{ $t('posTables.selectRoomGuestPlaceholder') }}</option><option v-for="reservation in activeReservations" :key="reservation.id" :value="reservation.id">{{ reservation.label }}</option></select>
            <template #footer><Button variant="ghost" @click="showPaymentModal = false">{{ $t('posTables.cancel') }}</Button><Button variant="primary" :loading="saving" @click="payTable"><Check class="h-4 w-4" /> {{ $t('posTables.confirmPayment') }}</Button></template>
        </Modal>

        <Teleport to="body">
            <section v-if="printRound && printTable" id="production-ticket" class="production-ticket">
                <h1>{{ $t('posTables.ticketTitle', { name: printTable.name }) }}</h1><p>{{ $t('posTables.roundNumber', { sequence: printRound.sequence }) }} · {{ time(printRound.sent_at || printRound.created_at) }}</p><p>{{ printRound.created_by || $t('posTables.staffFallback') }} · {{ printRound.destination }}</p><hr /><div v-for="item in printRound.items" :key="item.id" class="ticket-line"><strong>{{ item.quantity }}×</strong><span>{{ item.name }}</span></div><hr /><p class="ticket-footer">Lora PMS · {{ new Date().toLocaleString(getIntlLocale()) }}</p>
            </section>
        </Teleport>
        <ToastContainer ref="toasts" />
    </AppLayout>
</template>

<style>
.production-ticket { display: none; }
@media print {
    body.printing-table-summary * { visibility: hidden !important; }
    body.printing-table-summary #table-account-summary,
    body.printing-table-summary #table-account-summary * { visibility: visible !important; }
    body.printing-table-summary #table-account-summary { position: absolute; left: 0; top: 0; width: 80mm; padding: 6mm; color: #000; background: #fff; font-family: ui-monospace, monospace; }
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
