<script setup>
import { getIntlLocale, translate } from '@/i18n';
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount, defineAsyncComponent } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';
import { channelMeta } from '@/channels';
import {
    ArrowLeftRight,
    AlertTriangle,
    CalendarDays,
    Clock3,
    CreditCard,
    ExternalLink,
    Mail,
    MessageSquare,
    Pencil,
    Phone,
    X,
} from 'lucide-vue-next';

const ReservationCreateModal = defineAsyncComponent(() => import('@/Components/Reservations/ReservationCreateModal.vue'));
const MoveRoomModal = defineAsyncComponent(() => import('@/Components/Reservations/MoveRoomModal.vue'));
const ReservationEditModal = defineAsyncComponent(() => import('@/Components/Reservations/ReservationEditModal.vue'));
const ReservationConflictCenter = defineAsyncComponent(() => import('@/Components/Reservations/ReservationConflictCenter.vue'));

const props = defineProps({
    rooms: Array,
    reservations: Array,
    guests: Array,
    startDate: String,
    endDate: String,
    visibleDays: { type: Number, default: 14 },
    channelFees: { type: Object, default: () => ({}) },
    demo: { type: Boolean, default: false },
    availableDayRanges: { type: Array, default: () => [14] },
    conflicts: { type: Array, default: () => [] },
});

const emit = defineEmits(['navigate']);

const toasts = ref(null);
const showCreateModal = ref(false);
const showDetailModal = ref(false);
const showMoveModal = ref(false);
const showEditModal = ref(false);
const selectedReservation = ref(null);
const detailScroll = ref(null);
const detailCloseButton = ref(null);
const dateHeaderTrack = ref(null);
const filterInput = ref(null);
const showSummary = ref(false);
const query = ref('');
const statusFilter = ref('all');
const showConflictCenter = ref(false);
const resolvedConflictIds = ref([]);
const resolvingReservationId = ref(null);

const activeConflicts = computed(() => (props.conflicts || []).filter((conflict) => !resolvedConflictIds.value.includes(conflict.id)));
const conflictingReservationIds = computed(() => new Set(activeConflicts.value.flatMap((conflict) => conflict.reservations.map((reservation) => reservation.id))));

const perms = usePage().props.auth.user?.permissions || [];
const currencyCode = usePage().props.tenant?.currency || 'EUR';
const canCreate = props.demo || perms.includes('create_reservations');
const canUpdate = props.demo || perms.includes('update_reservations');

const savedRoomSort = typeof window !== 'undefined' ? window.localStorage.getItem('calendarRoomSort') : null;
const roomSort = ref(['number', 'type'].includes(savedRoomSort) ? savedRoomSort : 'number');
const roomCollator = new Intl.Collator(getIntlLocale(), { numeric: true, sensitivity: 'base' });

watch(roomSort, (value) => {
    if (typeof window !== 'undefined') window.localStorage.setItem('calendarRoomSort', value);
});

const sortedRooms = computed(() => {
    return [...(props.rooms || [])].sort((a, b) => {
        if (roomSort.value === 'type') {
            const typeA = a.room_type?.name?.trim() || '';
            const typeB = b.room_type?.name?.trim() || '';

            if (!typeA && typeB) return 1;
            if (typeA && !typeB) return -1;

            const typeComparison = roomCollator.compare(typeA, typeB);
            if (typeComparison !== 0) return typeComparison;

            const typeIdComparison = roomCollator.compare(String(a.room_type_id ?? ''), String(b.room_type_id ?? ''));
            if (typeIdComparison !== 0) return typeIdComparison;
        }

        const roomComparison = roomCollator.compare(String(a.room_number ?? ''), String(b.room_number ?? ''));
        return roomComparison !== 0 ? roomComparison : Number(a.id) - Number(b.id);
    });
});

// Generate the active 7/14/30-day range returned by the backend.
const days = computed(() => {
    const result = [];
    const start = new Date(props.startDate);
    for (let i = 0; i < props.visibleDays; i++) {
        const d = new Date(start);
        d.setDate(d.getDate() + i);
        result.push({
            date: d.toISOString().split('T')[0],
            day: d.getDate(),
            weekday: d.toLocaleDateString(getIntlLocale(), { weekday: 'short' }),
            month: d.toLocaleDateString(getIntlLocale(), { month: 'short' }),
            isToday: d.toISOString().split('T')[0] === new Date().toISOString().split('T')[0],
            isWeekend: d.getDay() === 0 || d.getDay() === 6,
        });
    }
    return result;
});

const statusColors = {
    pending: 'border-amber-300 bg-amber-100 text-amber-950 hover:bg-amber-200',
    confirmed: 'border-sky-300 bg-sky-100 text-sky-950 hover:bg-sky-200',
    checked_in: 'border-emerald-300 bg-emerald-100 text-emerald-950 hover:bg-emerald-200',
    checked_out: 'border-neutral-300 bg-neutral-100 text-neutral-700 hover:bg-neutral-200',
};

const groupedRooms = computed(() => sortedRooms.value.reduce((groups, room) => {
    const floor = room.floor ?? 0;
    (groups[floor] ||= []).push(room);
    return groups;
}, {}));

const filteredReservations = computed(() => (props.reservations || []).filter((reservation) => {
    const guest = `${reservation.guest?.first_name || ''} ${reservation.guest?.last_name || ''}`.trim().toLowerCase();
    const matchesQuery = !query.value || guest.includes(query.value.trim().toLowerCase());
    const matchesStatus = statusFilter.value === 'all' || reservation.status === statusFilter.value;
    return matchesQuery && matchesStatus;
}));

function reservationsFor(roomId) {
    return filteredReservations.value.filter((reservation) => reservation.room_id === roomId);
}

function dayOffset(value) {
    return Math.round((new Date(`${value}T12:00:00`) - new Date(`${props.startDate}T12:00:00`)) / 86400000);
}

function reservationStyle(reservation) {
    const start = Math.max(0, dayOffset(reservation.check_in_date));
    const end = Math.min(props.visibleDays, dayOffset(reservation.check_out_date));
    if (end <= 0 || start >= props.visibleDays) return { display: 'none' };
    return {
        left: `calc(${(start / props.visibleDays) * 100}% + 3px)`,
        width: `calc(${((end - start) / props.visibleDays) * 100}% - 6px)`,
    };
}

function isOccupied(roomId, date) {
    return (props.reservations || []).some((reservation) => reservation.room_id === roomId
        && reservation.check_in_date <= date && reservation.check_out_date > date);
}

const occupiedRoomNights = computed(() => (props.reservations || []).reduce((total, reservation) => {
    const start = Math.max(0, dayOffset(reservation.check_in_date));
    const end = Math.min(props.visibleDays, dayOffset(reservation.check_out_date));
    return total + Math.max(0, end - start);
}, 0));
const occupancy = computed(() => props.rooms?.length
    ? Math.round((occupiedRoomNights.value / (props.rooms.length * props.visibleDays)) * 100)
    : 0);
const operationalDate = computed(() => days.value.some((day) => day.isToday)
    ? new Date().toISOString().split('T')[0]
    : props.startDate);
const arrivals = computed(() => (props.reservations || []).filter((reservation) => reservation.check_in_date === operationalDate.value).length);
const departures = computed(() => (props.reservations || []).filter((reservation) => reservation.check_out_date === operationalDate.value).length);
const availableToday = computed(() => Math.max(0, (props.rooms?.length || 0) - (props.reservations || []).filter((reservation) => reservation.check_in_date <= operationalDate.value && reservation.check_out_date > operationalDate.value).length));
const dateRangeLabel = computed(() => {
    const first = new Date(`${props.startDate}T12:00:00`);
    const last = new Date(`${props.endDate}T12:00:00`);
    return `${first.toLocaleDateString(getIntlLocale(), { day: 'numeric', month: 'short' })} – ${last.toLocaleDateString(getIntlLocale(), { day: 'numeric', month: 'short', year: 'numeric' })}`;
});
const calendarTrackWidth = computed(() => `${props.visibleDays * 76}px`);
const calendarBodyWidth = computed(() => `${192 + (props.visibleDays * 76)}px`);

function syncDateHeader(event) {
    if (dateHeaderTrack.value) dateHeaderTrack.value.style.transform = `translateX(-${event.currentTarget.scrollLeft}px)`;
}

function goToWeek(startStr, direction, daysCount = props.visibleDays) {
    emit('navigate', { start: startStr, days: daysCount, direction });
}

function setVisibleDays(daysCount) {
    if (!props.availableDayRanges.includes(daysCount)) return;
    goToWeek(props.startDate, 1, daysCount);
}

function navigate(direction) {
    const start = new Date(props.startDate);
    start.setDate(start.getDate() + (direction * 7));
    goToWeek(start.toISOString().split('T')[0], direction);
}

function goToToday() {
    const today = new Date();
    const monday = new Date(today);
    monday.setDate(today.getDate() - today.getDay() + 1);
    const target = monday.toISOString().split('T')[0];
    goToWeek(target, target >= props.startDate ? 1 : -1);
}

async function openDetail(reservation) {
    selectedReservation.value = reservation;
    showDetailModal.value = true;
    await nextTick();
    if (detailScroll.value) detailScroll.value.scrollTop = 0;
    detailCloseButton.value?.focus({ preventScroll: true });
}

function openConflictReservation(reservationId) {
    const reservation = (props.reservations || []).find((item) => item.id === reservationId);
    if (!reservation) return;
    showConflictCenter.value = false;
    openDetail(reservation);
}

function applyConflictSuggestion({ conflictId, reservationId, room }) {
    if (props.demo) {
        resolvedConflictIds.value = [...resolvedConflictIds.value, conflictId];
        showConflictCenter.value = false;
        toasts.value?.success(translate('admin.calendarConflicts.demoApplied', { room: room.room_number }));
        return;
    }

    if (!confirm(translate('admin.calendarConflicts.confirmApply', { room: room.room_number }))) return;

    resolvingReservationId.value = reservationId;
    router.post(route('reservations.resolve-conflict', reservationId), { room_id: room.id }, {
        preserveScroll: true,
        onSuccess: () => {
            showConflictCenter.value = false;
            toasts.value?.success(translate('admin.calendarConflicts.realApplied', { room: room.room_number }));
        },
        onError: (errors) => toasts.value?.error(Object.values(errors)[0] || translate('admin.calendarConflicts.resolutionFailed')),
        onFinish: () => { resolvingReservationId.value = null; },
    });
}

function closeDetail() {
    showDetailModal.value = false;
}

// --- Detail popup helpers ---
const statusLabel = {
    pending: translate('admin.calendarPreview.pending'),
    confirmed: translate('admin.calendarPreview.confirmed'),
    checked_in: translate('admin.calendarPreview.inHouse'),
    checked_out: translate('admin.generated.k_023b466e7e43'),
    cancelled: translate('admin.generated.k_9c647e2278f4'),
};
function statusVariant(s) {
    return s === 'checked_in' ? 'success' : s === 'confirmed' ? 'info' : s === 'pending' ? 'warning' : 'neutral';
}
function roomOf(res) {
    return props.rooms.find((r) => r.id === res?.room_id) || null;
}
function nightsOf(res) {
    if (!res?.check_in_date || !res?.check_out_date) return 0;
    return Math.max(0, Math.round((new Date(res.check_out_date) - new Date(res.check_in_date)) / 86400000));
}
function formatDate(value, options = {}) {
    if (!value) return '—';
    return new Intl.DateTimeFormat(getIntlLocale(), {
        day: '2-digit', month: 'short', year: 'numeric', ...options,
    }).format(new Date(`${value}T12:00:00`));
}
function formatDateTime(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat(getIntlLocale(), {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    }).format(new Date(value));
}
function formatMoney(value) {
    return new Intl.NumberFormat(getIntlLocale(), { style: 'currency', currency: currencyCode }).format(Number(value) || 0);
}
const selectedPaidAmount = computed(() => Number(selectedReservation.value?.paid_amount) || 0);
const selectedBalance = computed(() => Math.max(0, (Number(selectedReservation.value?.total_amount) || 0) - selectedPaidAmount.value));
const selectedPaymentProgress = computed(() => {
    const total = Number(selectedReservation.value?.total_amount) || 0;
    return total ? Math.min(100, Math.round((selectedPaidAmount.value / total) * 100)) : 0;
});
// Other rooms in the same multi-room booking (linked by booking_group_id).
function groupSiblings(res) {
    if (!res?.booking_group_id) return [];
    return props.reservations.filter((r) => r.booking_group_id === res.booking_group_id && r.id !== res.id);
}

// Create form — shared popup with the list view
const prefill = ref(null);

function openCreate(roomId, date, checkoutDate = null) {
    if (!canCreate) return;
    if (props.demo) {
        toasts.value?.success(translate('admin.calendarPreview.mockData'));
        return;
    }
    const start = date || new Date().toISOString().split('T')[0];
    let checkout = checkoutDate;
    if (!checkout) {
        const c = new Date(start);
        c.setDate(c.getDate() + 1);
        checkout = c.toISOString().split('T')[0];
    }
    prefill.value = {
        room_id: roomId || '',
        check_in_date: start,
        check_out_date: checkout,
    };
    showCreateModal.value = true;
}

// --- Drag across empty cells to pick a date RANGE for one room ---
const dragRoom = ref(null);
const dragStart = ref(null);
const dragEnd = ref(null);

function startDrag(roomId, date) {
    if (!canCreate) return;
    dragRoom.value = roomId;
    dragStart.value = date;
    dragEnd.value = date;
}
function extendDrag(roomId, date) {
    if (dragRoom.value === null || dragRoom.value !== roomId) return;
    dragEnd.value = date;
}
function endDrag() {
    if (dragRoom.value === null) return;
    const room = dragRoom.value;
    const a = dragStart.value;
    const b = dragEnd.value;
    const start = a <= b ? a : b;
    const lastNight = a <= b ? b : a;
    const c = new Date(lastNight);
    c.setDate(c.getDate() + 1); // check-out = day after the last selected night
    dragRoom.value = null;
    dragStart.value = null;
    dragEnd.value = null;
    openCreate(room, start, c.toISOString().split('T')[0]);
}
function isInDrag(roomId, date) {
    if (dragRoom.value === null || dragRoom.value !== roomId) return false;
    const lo = dragStart.value <= dragEnd.value ? dragStart.value : dragEnd.value;
    const hi = dragStart.value <= dragEnd.value ? dragEnd.value : dragStart.value;
    return date >= lo && date <= hi;
}

// Event delegation on the grid: robust across fast drags, and it naturally stops
// at a reservation block (those cells carry no data-date), keeping the range contiguous.
function cellFrom(e) {
    const cell = e.target.closest('[data-date]');
    return cell && !cell.disabled ? { roomId: Number(cell.dataset.room), date: cell.dataset.date } : null;
}
function onGridDown(e) {
    const c = cellFrom(e);
    if (!c) return;
    e.preventDefault(); // avoid text selection while dragging
    startDrag(c.roomId, c.date);
}
function onGridOver(e) {
    if (dragRoom.value === null) return;
    const c = cellFrom(e);
    if (c) extendDrag(c.roomId, c.date);
}

// Finalize the selection even if the mouse is released off the grid.
function onEscape(event) {
    if (event.key === 'Escape' && showConflictCenter.value) {
        showConflictCenter.value = false;
        return;
    }
    if (event.key === 'Escape' && showDetailModal.value) closeDetail();
}

onMounted(() => {
    window.addEventListener('mouseup', endDrag);
    window.addEventListener('keydown', onEscape);
});
onBeforeUnmount(() => {
    window.removeEventListener('mouseup', endDrag);
    window.removeEventListener('keydown', onEscape);
});

function onReservationCreated() {
    toasts.value?.success(translate('admin.generated.k_a53fb600f6f3'));
}

function openMove() {
    if (props.demo) {
        toasts.value?.success(translate('admin.calendarPreview.mockData'));
        return;
    }
    showDetailModal.value = false;
    showMoveModal.value = true;
}
function onRoomMoved() {
    toasts.value?.success(translate('admin.generated.k_282bd8c71f67'));
}

function openEdit() {
    if (props.demo) {
        toasts.value?.success(translate('admin.calendarPreview.mockData'));
        return;
    }
    showDetailModal.value = false;
    showEditModal.value = true;
}
function onReservationUpdated() {
    toasts.value?.success(translate('admin.generated.k_500a172395d3'));
}
function doCancel(res) {
    if (!res) return;
    if (props.demo) {
        toasts.value?.success(translate('admin.calendarPreview.mockData'));
        return;
    }
    const room = roomOf(res)?.room_number;
    const extra = groupSiblings(res).length ? translate('admin.generated.k_d42546aba21b') : '';
    const guest = `${res.guest?.first_name || ''} ${res.guest?.last_name || ''}`.trim();
    if (!confirm(`${translate('admin.calendarPreview.cancelConfirmation', { guest, room })}${extra}`)) return;
    router.post(route('reservations.cancel', res.id), {}, {
        preserveScroll: true,
        onSuccess: () => { showDetailModal.value = false; toasts.value?.success(translate('admin.generated.k_bae1d5058ac1')); },
    });
}

function doCheckIn(res) {
    if (props.demo) {
        toasts.value?.success(translate('admin.calendarPreview.mockData'));
        return;
    }
    router.post(route('reservations.check-in', res.id), {}, {
        preserveScroll: true,
        onSuccess: () => { showDetailModal.value = false; toasts.value?.success(translate('admin.generated.k_1515c2ebac05')); },
    });
}

function doCheckOut(res) {
    if (props.demo) {
        toasts.value?.success(translate('admin.calendarPreview.mockData'));
        return;
    }
    router.post(route('reservations.check-out', res.id), {}, {
        preserveScroll: true,
        onSuccess: () => { showDetailModal.value = false; toasts.value?.success(translate('admin.generated.k_80056aa4666f')); },
        // A guest who still owes is blocked server-side — keep the modal open and show why.
        onError: (errors) => toasts.value?.error(errors.settle_method || translate('admin.calendarPreview.checkoutFailed')),
    });
}

</script>

<template>
        <div class="pb-10">
            <div class="mb-5 flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div class="flex items-center gap-3">
                    <span class="grid h-11 w-11 place-items-center rounded-xl bg-accent-100 text-accent-700"><CalendarDays class="h-5 w-5" /></span>
                    <div>
                        <h1 class="text-h2 text-primary-900">{{ $t('admin.calendarPreview.title') }}</h1>
                        <p class="mt-0.5 text-body-sm text-neutral-500">{{ $t('admin.calendarPreview.subtitle') }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span v-if="demo" class="inline-flex items-center rounded-full border border-warning-200 bg-warning-50 px-3 py-1.5 text-tiny font-semibold text-warning-800">{{ $t('admin.calendarPreview.mockData') }}</span>
                    <Link :href="route('reservations.index')" class="no-underline"><Button variant="outline" size="sm">{{ $t('admin.generated.k_8ad6f4281da4') }}</Button></Link>
                    <Button variant="outline" size="sm" @click="showSummary = !showSummary"><span aria-hidden="true">{{ showSummary ? '−' : '+' }}</span>{{ showSummary ? $t('admin.calendarPreview.hideSummary') : $t('admin.calendarPreview.showSummary') }}</Button>
                    <Button variant="outline" size="sm" @click="filterInput?.focus()">{{ $t('admin.calendarPreview.filters') }}</Button>
                    <Button v-if="canCreate" size="sm" @click="openCreate(null, new Date().toISOString().split('T')[0])"><span aria-hidden="true">+</span>{{ $t('admin.calendarPreview.newReservation') }}</Button>
                </div>
            </div>

            <div v-if="activeConflicts.length" role="alert" class="mb-4 flex flex-col gap-3 rounded-xl border border-error-300 bg-error-50 px-4 py-3 shadow-card sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-error-600 text-white"><AlertTriangle class="h-5 w-5" /></span>
                    <div>
                        <p class="text-body-sm font-extrabold text-error-900">{{ $t('admin.calendarConflicts.alertTitle', { count: activeConflicts.length }) }}</p>
                        <p class="mt-0.5 text-tiny text-error-700">{{ $t('admin.calendarConflicts.alertBody') }}</p>
                    </div>
                </div>
                <Button variant="danger" size="sm" class="shrink-0 justify-center" @click="showConflictCenter = true">{{ $t('admin.calendarConflicts.review') }}</Button>
            </div>

            <div v-if="!showSummary" class="mb-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                <div class="flex items-center justify-between rounded-xl border border-neutral-200 bg-white px-4 py-2.5 shadow-card"><div><p class="text-tiny font-medium text-neutral-500">{{ $t('admin.calendarPreview.occupancy') }}</p><p class="mt-0.5 text-h4 font-extrabold text-primary-900">{{ occupancy }}%</p></div><span class="grid h-8 w-8 place-items-center rounded-lg bg-accent-50 text-sm font-bold text-accent-700" aria-hidden="true">●</span></div>
                <div class="flex items-center justify-between rounded-xl border border-neutral-200 bg-white px-4 py-2.5 shadow-card"><div><p class="text-tiny font-medium text-neutral-500">{{ $t('admin.calendarPreview.arrivalsToday') }}</p><p class="mt-0.5 text-h4 font-extrabold text-primary-900">{{ arrivals }}</p></div><span class="grid h-8 w-8 place-items-center rounded-lg bg-info-50 text-sm font-bold text-info-700" aria-hidden="true">↘</span></div>
                <div class="flex items-center justify-between rounded-xl border border-neutral-200 bg-white px-4 py-2.5 shadow-card"><div><p class="text-tiny font-medium text-neutral-500">{{ $t('admin.calendarPreview.departuresToday') }}</p><p class="mt-0.5 text-h4 font-extrabold text-primary-900">{{ departures }}</p></div><span class="grid h-8 w-8 place-items-center rounded-lg bg-warning-50 text-sm font-bold text-warning-700" aria-hidden="true">↗</span></div>
                <div class="flex items-center justify-between rounded-xl border border-neutral-200 bg-white px-4 py-2.5 shadow-card"><div><p class="text-tiny font-medium text-neutral-500">{{ $t('admin.calendarPreview.availableTonight') }}</p><p class="mt-0.5 text-h4 font-extrabold text-primary-900">{{ availableToday }} <span class="text-tiny font-medium text-neutral-400">/ {{ rooms.length }}</span></p></div><span class="grid h-8 w-8 place-items-center rounded-lg bg-success-50 text-sm font-bold text-success-700" aria-hidden="true">✓</span></div>
            </div>

            <div v-else class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-card"><div class="flex items-center justify-between"><p class="text-body-sm font-medium text-neutral-500">{{ $t('admin.calendarPreview.occupancy') }}</p><span class="font-bold text-accent-600" aria-hidden="true">●</span></div><p class="mt-2 text-h2 font-extrabold text-primary-900">{{ occupancy }}%</p><div class="mt-3 h-1.5 overflow-hidden rounded-full bg-neutral-100"><div class="h-full rounded-full bg-accent-500" :style="{ width: `${occupancy}%` }" /></div></div>
                <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-card"><div class="flex items-center justify-between"><p class="text-body-sm font-medium text-neutral-500">{{ $t('admin.calendarPreview.arrivalsToday') }}</p><span class="font-bold text-info-600" aria-hidden="true">↘</span></div><p class="mt-2 text-h2 font-extrabold text-primary-900">{{ arrivals }}</p></div>
                <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-card"><div class="flex items-center justify-between"><p class="text-body-sm font-medium text-neutral-500">{{ $t('admin.calendarPreview.departuresToday') }}</p><span class="font-bold text-warning-600" aria-hidden="true">↗</span></div><p class="mt-2 text-h2 font-extrabold text-primary-900">{{ departures }}</p></div>
                <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-card"><div class="flex items-center justify-between"><p class="text-body-sm font-medium text-neutral-500">{{ $t('admin.calendarPreview.availableTonight') }}</p><span class="font-bold text-success-600" aria-hidden="true">✓</span></div><p class="mt-2 text-h2 font-extrabold text-primary-900">{{ availableToday }} <span class="text-body-sm font-medium text-neutral-400">/ {{ rooms.length }}</span></p><p class="mt-1 text-tiny text-success-700">{{ $t('admin.calendarPreview.readyToSell') }}</p></div>
            </div>

            <section class="rounded-xl border border-neutral-200 bg-white shadow-card">
                <div class="flex flex-col gap-3 border-b border-neutral-200 p-3 xl:flex-row xl:items-center xl:justify-between">
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="flex items-center rounded-lg border border-neutral-200 bg-white p-0.5">
                            <button type="button" class="grid h-8 w-8 place-items-center rounded-md text-lg text-neutral-500 hover:bg-neutral-100" @click="navigate(-1)" aria-label="Previous">‹</button>
                            <button type="button" class="min-w-44 px-3 text-body-sm font-bold text-primary-900" @click="goToToday">{{ dateRangeLabel }}</button>
                            <button type="button" class="grid h-8 w-8 place-items-center rounded-md text-lg text-neutral-500 hover:bg-neutral-100" @click="navigate(1)" aria-label="Next">›</button>
                        </div>
                        <button type="button" class="h-9 rounded-lg border border-neutral-200 px-3 text-body-sm font-medium text-neutral-600 hover:bg-neutral-50" @click="goToToday">{{ $t('admin.calendarPreview.today') }}</button>
                        <div class="inline-flex rounded-lg bg-neutral-100 p-0.5">
                            <button v-for="count in [7, 14, 30]" :key="count" type="button" :disabled="!availableDayRanges.includes(count)" class="rounded-md px-3 py-1.5 text-tiny font-semibold transition disabled:cursor-not-allowed disabled:opacity-40" :class="visibleDays === count ? 'bg-white text-primary-900 shadow-sm' : 'text-neutral-500'" @click="setVisibleDays(count)">{{ count }}d</button>
                        </div>
                        <div class="inline-flex rounded-lg border border-neutral-200 bg-white p-0.5" role="group" :aria-label="$t('admin.generated.k_96f31a0be77c')">
                            <button type="button" :aria-pressed="roomSort === 'number'" class="rounded-md px-2.5 py-1.5 text-tiny font-semibold transition" :class="roomSort === 'number' ? 'bg-primary-900 text-white' : 'text-neutral-500'" @click="roomSort = 'number'">{{ $t('admin.generated.k_fa144b375912') }}</button>
                            <button type="button" :aria-pressed="roomSort === 'type'" class="rounded-md px-2.5 py-1.5 text-tiny font-semibold transition" :class="roomSort === 'type' ? 'bg-primary-900 text-white' : 'text-neutral-500'" @click="roomSort = 'type'">{{ $t('admin.generated.k_e129e8dfc500') }}</button>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="relative min-w-52 flex-1 xl:flex-none">
                            <span class="pointer-events-none absolute left-3 top-2 text-base text-neutral-400" aria-hidden="true">⌕</span>
                            <input ref="filterInput" v-model="query" type="search" class="h-9 w-full rounded-lg border-neutral-200 pl-9 pr-3 text-body-sm focus:border-accent-500 focus:ring-accent-500" :placeholder="$t('admin.calendarPreview.searchGuest')" />
                        </label>
                        <select v-model="statusFilter" class="h-9 rounded-lg border-neutral-200 py-1.5 pl-3 pr-8 text-body-sm text-neutral-600 focus:border-accent-500 focus:ring-accent-500">
                            <option value="all">{{ $t('admin.calendarPreview.allStatuses') }}</option>
                            <option value="checked_in">{{ $t('admin.calendarPreview.inHouse') }}</option>
                            <option value="confirmed">{{ $t('admin.calendarPreview.confirmed') }}</option>
                            <option value="pending">{{ $t('admin.calendarPreview.pending') }}</option>
                            <option value="checked_out">{{ $t('admin.generated.k_75cbe57f73eb') }}</option>
                        </select>
                    </div>
                </div>

                <div class="sticky top-16 z-40 flex border-b border-neutral-300 bg-white shadow-[0_6px_14px_-10px_rgba(15,23,42,0.45)]">
                    <div class="z-50 flex w-48 shrink-0 items-center border-r border-neutral-200 bg-white px-4 py-3 text-tiny font-bold uppercase tracking-wider text-neutral-500">{{ $t('admin.calendarPreview.room') }}</div>
                    <div class="min-w-0 flex-1 overflow-hidden">
                        <div ref="dateHeaderTrack" class="grid min-w-full will-change-transform" :style="{ minWidth: calendarTrackWidth, gridTemplateColumns: `repeat(${visibleDays}, minmax(76px, 1fr))` }">
                            <div v-for="day in days" :key="day.date" class="border-r border-neutral-200 px-2 py-2 text-center" :class="day.isToday ? 'bg-accent-50' : day.isWeekend ? 'bg-neutral-100/80' : ''">
                                <p class="text-[10px] font-bold uppercase tracking-wide text-neutral-400">{{ day.weekday }}</p>
                                <p class="mx-auto mt-1 grid h-7 w-7 place-items-center rounded-full text-body-sm font-bold" :class="day.isToday ? 'bg-accent-600 text-white' : 'text-neutral-700'">{{ day.day }}</p>
                                <p class="mt-0.5 text-[10px] text-neutral-400">{{ day.month }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto overflow-y-visible overscroll-x-contain" @scroll.passive="syncDateHeader">
                    <div :style="{ minWidth: calendarBodyWidth }">
                        <template v-for="(floorRooms, floor) in groupedRooms" :key="floor">
                            <div class="sticky left-0 z-20 flex h-8 items-center border-b border-neutral-200 bg-primary-50 px-4 text-tiny font-bold uppercase tracking-wider text-primary-700">{{ $t('admin.calendarPreview.floor') }} {{ floor }} · {{ floorRooms.length }} {{ $t('admin.calendarPreview.rooms') }}</div>
                            <div v-for="room in floorRooms" :key="room.id" class="group flex border-b border-neutral-200 last:border-b-0">
                                <div class="sticky left-0 z-20 flex h-16 w-48 shrink-0 items-center justify-between border-r border-neutral-200 bg-white px-4 group-hover:bg-neutral-50">
                                    <div class="min-w-0"><p class="text-body-sm font-extrabold text-primary-900">{{ room.room_number }}</p><p class="mt-0.5 max-w-32 truncate text-[11px] text-neutral-400">{{ room.room_type?.name }}</p></div>
                                    <span class="h-2.5 w-2.5 shrink-0 rounded-full ring-2 ring-white" :class="room.status === 'maintenance' ? 'bg-neutral-400' : 'bg-success-500'" />
                                </div>
                                <div class="relative grid h-16 min-w-0 flex-1" :style="{ minWidth: calendarTrackWidth, gridTemplateColumns: `repeat(${visibleDays}, minmax(76px, 1fr))` }" @mousedown="onGridDown" @mouseover="onGridOver">
                                    <button
                                        v-for="day in days"
                                        :key="day.date"
                                        type="button"
                                        :data-date="day.date"
                                        :data-room="room.id"
                                        :disabled="isOccupied(room.id, day.date) || !canCreate"
                                        class="border-r border-neutral-100 transition disabled:cursor-default"
                                        :class="[
                                            day.isToday ? 'bg-accent-50/40' : day.isWeekend ? 'bg-neutral-50' : '',
                                            !isOccupied(room.id, day.date) && canCreate ? 'hover:bg-accent-50' : '',
                                            isInDrag(room.id, day.date) ? 'bg-accent-100' : '',
                                        ]"
                                        :aria-label="`${room.room_number} ${day.date}`"
                                    />
                                    <button
                                        v-for="reservation in reservationsFor(room.id)"
                                        :key="reservation.id"
                                        type="button"
                                        class="absolute top-2 z-10 h-12 overflow-hidden rounded-lg border px-2.5 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                                        :class="[
                                            statusColors[reservation.status],
                                            conflictingReservationIds.has(reservation.id) ? 'ring-2 ring-error-500 ring-offset-1' : '',
                                        ]"
                                        :style="reservationStyle(reservation)"
                                        @click="openDetail(reservation)"
                                    >
                                        <span class="flex items-center gap-1.5 truncate text-[11px] font-extrabold"><span class="h-1.5 w-1.5 shrink-0 rounded-full" :style="{ backgroundColor: channelMeta(reservation.channel).color }" />{{ reservation.guest?.first_name }} {{ reservation.guest?.last_name }}</span>
                                        <span class="mt-0.5 flex items-center justify-between gap-1 text-[10px] opacity-75"><span class="truncate">{{ channelMeta(reservation.channel).label }}</span><span class="shrink-0 font-bold" :class="Number(reservation.paid_amount) >= Number(reservation.total_amount) ? 'text-success-700' : 'text-warning-700'" aria-hidden="true">{{ currencyCode }}</span></span>
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-neutral-200 bg-neutral-50 px-4 py-3">
                    <div class="flex flex-wrap items-center gap-4 text-tiny text-neutral-500">
                        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded bg-emerald-200 ring-1 ring-emerald-300" />{{ $t('admin.calendarPreview.inHouse') }}</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded bg-sky-200 ring-1 ring-sky-300" />{{ $t('admin.calendarPreview.confirmed') }}</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded bg-amber-200 ring-1 ring-amber-300" />{{ $t('admin.calendarPreview.pending') }}</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded bg-neutral-200 ring-1 ring-neutral-300" />{{ $t('admin.generated.k_75cbe57f73eb') }}</span>
                    </div>
                    <p class="text-tiny text-neutral-400">{{ $t('admin.calendarPreview.dragHint') }}</p>
                </div>
            </section>
        </div>

        <ReservationConflictCenter
            v-if="showConflictCenter"
            :conflicts="activeConflicts"
            :demo="demo"
            :resolving-reservation-id="resolvingReservationId"
            @close="showConflictCenter = false"
            @open-reservation="openConflictReservation"
            @apply-suggestion="applyConflictSuggestion"
        />

        <!-- Reservation detail side drawer -->
        <Teleport to="body">
            <Transition name="drawer-fade">
                <button
                    v-if="showDetailModal && selectedReservation"
                    type="button"
                    class="fixed inset-0 z-40 cursor-default bg-primary-900/15 backdrop-blur-[1px]"
                    :aria-label="$t('admin.generated.k_30f7d21af244')"
                    @click="closeDetail"
                />
            </Transition>
            <Transition name="drawer-slide">
                <aside
                    v-if="showDetailModal && selectedReservation"
                    class="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col border-l border-neutral-200 bg-white shadow-2xl"
                    role="dialog"
                    aria-modal="true"
                    :aria-label="$t('admin.calendarPreview.reservationDetails')"
                >
                    <header class="shrink-0 border-b border-neutral-200 bg-white px-5 py-4">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-tiny font-bold uppercase tracking-wider text-accent-700">{{ $t('admin.calendarPreview.reservationDetails') }}</p>
                                    <Badge :variant="statusVariant(selectedReservation.status)" dot>{{ statusLabel[selectedReservation.status] || selectedReservation.status }}</Badge>
                                </div>
                                <h2 class="mt-1 truncate text-h3 text-primary-900">{{ selectedReservation.guest?.first_name }} {{ selectedReservation.guest?.last_name }}</h2>
                                <p class="mt-1 text-tiny text-neutral-400"># {{ selectedReservation.channel_ref || selectedReservation.id }}</p>
                            </div>
                            <button ref="detailCloseButton" type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" @click="closeDetail">
                                <X class="h-5 w-5" />
                            </button>
                        </div>
                    </header>

                    <div ref="detailScroll" class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-5">
                        <section>
                            <div class="mb-2 flex items-center justify-between gap-3">
                                <h3 class="text-body-sm font-bold text-primary-900">{{ $t('admin.calendarPreview.guestDetails') }}</h3>
                                <span v-if="selectedReservation.guest?.nationality" class="text-tiny text-neutral-400">{{ selectedReservation.guest.nationality }}</span>
                            </div>
                            <div class="rounded-xl border border-neutral-200 bg-neutral-50 p-3">
                                <a v-if="selectedReservation.guest?.phone" :href="`tel:${selectedReservation.guest.phone}`" class="flex items-center gap-2 py-1 text-body-sm text-neutral-700 no-underline hover:text-accent-700"><Phone class="h-4 w-4 text-neutral-400" />{{ selectedReservation.guest.phone }}</a>
                                <a v-if="selectedReservation.guest?.email" :href="`mailto:${selectedReservation.guest.email}`" class="flex items-center gap-2 py-1 text-body-sm text-neutral-700 no-underline hover:text-accent-700"><Mail class="h-4 w-4 text-neutral-400" /><span class="truncate">{{ selectedReservation.guest.email }}</span></a>
                                <p v-if="!selectedReservation.guest?.phone && !selectedReservation.guest?.email" class="text-body-sm text-neutral-400">—</p>
                                <div class="mt-3 flex gap-2 border-t border-neutral-200 pt-3">
                                    <a v-if="selectedReservation.guest?.phone" :href="`tel:${selectedReservation.guest.phone}`" class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg border border-neutral-200 bg-white px-3 py-2 text-tiny font-semibold text-neutral-600 no-underline hover:bg-neutral-50"><Phone class="h-3.5 w-3.5" />{{ $t('admin.calendarPreview.call') }}</a>
                                    <a v-if="selectedReservation.guest?.email" :href="`mailto:${selectedReservation.guest.email}`" class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg border border-neutral-200 bg-white px-3 py-2 text-tiny font-semibold text-neutral-600 no-underline hover:bg-neutral-50"><MessageSquare class="h-3.5 w-3.5" />{{ $t('admin.calendarPreview.message') }}</a>
                                </div>
                            </div>
                        </section>

                        <section>
                            <h3 class="mb-2 text-body-sm font-bold text-primary-900">{{ $t('admin.calendarPreview.stayDetails') }}</h3>
                            <div class="overflow-hidden rounded-xl border border-neutral-200">
                                <div class="grid grid-cols-2 divide-x divide-neutral-200 border-b border-neutral-200 bg-neutral-50">
                                    <div class="p-3"><p class="text-[10px] font-bold uppercase tracking-wide text-neutral-400">{{ $t('admin.calendarPreview.checkIn') }}</p><p class="mt-1 text-body-sm font-bold text-primary-900">{{ formatDate(selectedReservation.check_in_date) }}</p><p v-if="selectedReservation.eta" class="mt-0.5 flex items-center gap-1 text-tiny text-neutral-500"><Clock3 class="h-3 w-3" />{{ selectedReservation.eta }}</p></div>
                                    <div class="p-3"><p class="text-[10px] font-bold uppercase tracking-wide text-neutral-400">{{ $t('admin.calendarPreview.checkOut') }}</p><p class="mt-1 text-body-sm font-bold text-primary-900">{{ formatDate(selectedReservation.check_out_date) }}</p><p class="mt-0.5 text-tiny text-neutral-500">{{ nightsOf(selectedReservation) }} {{ $t('admin.calendarPreview.nights').toLowerCase() }}</p></div>
                                </div>
                                <div class="grid grid-cols-2 gap-3 p-3 text-body-sm">
                                    <div><p class="text-tiny text-neutral-400">{{ $t('admin.calendarPreview.room') }}</p><p class="mt-1 font-bold text-primary-900">{{ roomOf(selectedReservation)?.room_number }} · {{ roomOf(selectedReservation)?.room_type?.name }}</p></div>
                                    <div><p class="text-tiny text-neutral-400">{{ $t('admin.calendarPreview.guests') }}</p><p class="mt-1 font-bold text-primary-900">{{ selectedReservation.adults }} {{ $t('admin.calendarPreview.adults') }}<span v-if="selectedReservation.children"> · {{ selectedReservation.children }} {{ $t('admin.generated.k_4ecd4d812403') }}</span></p></div>
                                </div>
                            </div>
                        </section>

                        <section>
                            <div class="mb-2 flex items-center justify-between"><h3 class="text-body-sm font-bold text-primary-900">{{ $t('admin.calendarPreview.paymentSummary') }}</h3><CreditCard class="h-4 w-4 text-neutral-400" /></div>
                            <div class="rounded-xl border border-neutral-200 p-3">
                                <div class="grid grid-cols-3 gap-3">
                                    <div><p class="text-[10px] font-bold uppercase text-neutral-400">{{ $t('admin.calendarPreview.total') }}</p><p class="mt-1 text-body-sm font-bold text-primary-900">{{ formatMoney(selectedReservation.total_amount) }}</p></div>
                                    <div><p class="text-[10px] font-bold uppercase text-neutral-400">{{ $t('admin.calendarPreview.paid') }}</p><p class="mt-1 text-body-sm font-bold text-success-700">{{ formatMoney(selectedPaidAmount) }}</p></div>
                                    <div><p class="text-[10px] font-bold uppercase text-neutral-400">{{ $t('admin.calendarPreview.balance') }}</p><p class="mt-1 text-body-sm font-bold" :class="selectedBalance ? 'text-warning-700' : 'text-success-700'">{{ formatMoney(selectedBalance) }}</p></div>
                                </div>
                                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-neutral-100"><div class="h-full rounded-full bg-success-500 transition-all" :style="{ width: `${selectedPaymentProgress}%` }" /></div>
                                <p class="mt-2 text-right text-[10px] font-semibold text-neutral-400">{{ selectedPaymentProgress }}% {{ $t('admin.calendarPreview.paid').toLowerCase() }}</p>
                            </div>
                        </section>

                        <section class="grid grid-cols-2 gap-3 rounded-xl border border-neutral-200 p-3 text-body-sm">
                            <div><p class="text-tiny text-neutral-400">{{ $t('admin.calendarPreview.channel') }}</p><p class="mt-1 flex items-center gap-1.5 font-bold text-primary-900"><span class="h-2 w-2 rounded-full" :style="{ background: channelMeta(selectedReservation.channel).color }" />{{ channelMeta(selectedReservation.channel).label }}</p></div>
                            <div><p class="text-tiny text-neutral-400">{{ $t('admin.calendarPreview.bookedOn') }}</p><p class="mt-1 font-bold text-primary-900">{{ formatDateTime(selectedReservation.created_at) }}</p></div>
                        </section>

                        <section v-if="selectedReservation.notes">
                            <h3 class="mb-2 text-body-sm font-bold text-primary-900">{{ $t('admin.calendarPreview.specialRequests') }}</h3>
                            <p class="whitespace-pre-line rounded-xl border border-warning-100 bg-warning-50 p-3 text-body-sm text-warning-900">{{ selectedReservation.notes }}</p>
                        </section>

                        <section v-if="groupSiblings(selectedReservation).length" class="rounded-xl border border-info-100 bg-info-50 p-3">
                            <p class="text-body-sm text-info-800">{{ $t('admin.generated.k_00499ea9cb88') }} {{ groupSiblings(selectedReservation).length + 1 }} {{ $t('admin.generated.k_9d9dbeaafaf2') }} <span class="font-bold">{{ groupSiblings(selectedReservation).map((r) => roomOf(r)?.room_number).filter(Boolean).join(', ') }}</span></p>
                        </section>

                        <section>
                            <h3 class="mb-3 text-body-sm font-bold text-primary-900">{{ $t('admin.calendarPreview.activity') }}</h3>
                            <div class="ml-1 space-y-4 border-l border-neutral-200 pl-5">
                                <div class="relative"><span class="absolute -left-[25px] top-1 h-2.5 w-2.5 rounded-full bg-info-500 ring-4 ring-white" /><p class="text-body-sm font-medium text-neutral-700">{{ $t('admin.calendarPreview.reservationCreated') }}</p><p class="text-tiny text-neutral-400">{{ formatDateTime(selectedReservation.created_at) }} · {{ channelMeta(selectedReservation.channel).label }}</p></div>
                                <div v-if="selectedPaidAmount" class="relative"><span class="absolute -left-[25px] top-1 h-2.5 w-2.5 rounded-full bg-success-500 ring-4 ring-white" /><p class="text-body-sm font-medium text-neutral-700">{{ $t('admin.calendarPreview.paymentRecorded') }}</p><p class="text-tiny text-neutral-400">{{ formatMoney(selectedPaidAmount) }}</p></div>
                                <div v-if="selectedReservation.status === 'checked_in'" class="relative"><span class="absolute -left-[25px] top-1 h-2.5 w-2.5 rounded-full bg-accent-500 ring-4 ring-white" /><p class="text-body-sm font-medium text-neutral-700">{{ $t('admin.calendarPreview.checkedIn') }}</p><p class="text-tiny text-neutral-400">{{ formatDate(selectedReservation.check_in_date) }}</p></div>
                            </div>
                        </section>

                        <section v-if="canUpdate && ['pending', 'confirmed', 'checked_in'].includes(selectedReservation.status)" class="space-y-2 border-t border-neutral-200 pt-5">
                            <Button v-if="selectedReservation.status === 'confirmed'" class="w-full justify-center" @click="doCheckIn(selectedReservation)"><CalendarDays class="h-4 w-4" />{{ $t('admin.generated.k_cdcd4da54b15') }}</Button>
                            <Button v-if="selectedReservation.status === 'checked_in'" variant="secondary" class="w-full justify-center" @click="doCheckOut(selectedReservation)"><CalendarDays class="h-4 w-4" />{{ $t('admin.generated.k_21e6813ac89c') }}</Button>
                            <Button v-if="['pending','confirmed'].includes(selectedReservation.status)" variant="ghost" class="w-full justify-center text-error-600" @click="doCancel(selectedReservation)">{{ $t('admin.generated.k_1574ef95826c') }}</Button>
                        </section>
                    </div>

                    <footer class="grid shrink-0 grid-cols-3 gap-2 border-t border-neutral-200 bg-white p-4">
                        <Button v-if="canUpdate && !['checked_in','checked_out','cancelled'].includes(selectedReservation.status)" variant="outline" class="justify-center" @click="openEdit"><Pencil class="h-4 w-4" />{{ $t('admin.calendarPreview.edit') }}</Button>
                        <Button v-else variant="outline" class="justify-center" disabled><Pencil class="h-4 w-4" />{{ $t('admin.calendarPreview.edit') }}</Button>
                        <Button v-if="canUpdate && selectedReservation.status === 'checked_in'" variant="outline" class="justify-center" @click="openMove"><ArrowLeftRight class="h-4 w-4" />{{ $t('admin.calendarPreview.move') }}</Button>
                        <Button v-else variant="outline" class="justify-center" disabled><ArrowLeftRight class="h-4 w-4" />{{ $t('admin.calendarPreview.move') }}</Button>
                        <Link v-if="!demo" :href="route('reservations.show', selectedReservation.id)" class="no-underline"><Button class="w-full justify-center"><ExternalLink class="h-4 w-4" />{{ $t('admin.calendarPreview.manage') }}</Button></Link>
                        <Button v-else class="w-full justify-center" @click="toasts?.success($t('admin.calendarPreview.mockData'))"><ExternalLink class="h-4 w-4" />{{ $t('admin.calendarPreview.manage') }}</Button>
                    </footer>
                </aside>
            </Transition>
        </Teleport>

        <!-- Create Reservation Modal — shared with the list view -->
        <ReservationCreateModal
            :show="showCreateModal"
            :rooms="rooms"
            :guests="guests"
            :channel-fees="channelFees"
            :prefill="prefill"
            @close="showCreateModal = false"
            @created="onReservationCreated"
            @guest-created="toasts?.success(translate('admin.calendarPreview.guestCreated'))"
        />

        <MoveRoomModal
            :show="showMoveModal"
            :reservation="selectedReservation"
            :rooms="rooms"
            @close="showMoveModal = false"
            @moved="onRoomMoved"
        />

        <ReservationEditModal
            :show="showEditModal"
            :reservation="selectedReservation"
            :rooms="rooms"
            :guests="guests"
            :channel-fees="channelFees"
            @close="showEditModal = false"
            @updated="onReservationUpdated"
        />

        <ToastContainer ref="toasts" />
</template>

<style scoped>
.drawer-slide-enter-active,
.drawer-slide-leave-active,
.drawer-fade-enter-active,
.drawer-fade-leave-active {
    transition: opacity 180ms ease, transform 220ms cubic-bezier(0.4, 0, 0.2, 1);
}
.drawer-slide-enter-from,
.drawer-slide-leave-to {
    transform: translateX(100%);
}
.drawer-fade-enter-from,
.drawer-fade-leave-to {
    opacity: 0;
}
@media (prefers-reduced-motion: reduce) {
    .drawer-slide-enter-active,
    .drawer-slide-leave-active,
    .drawer-fade-enter-active,
    .drawer-fade-leave-active {
        transition: opacity 150ms ease;
        transform: none !important;
    }
}
</style>
