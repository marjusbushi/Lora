<script setup>
import { getIntlLocale, translate } from '@/i18n';
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';
import ReservationCreateModal from '@/Components/Reservations/ReservationCreateModal.vue';
import MoveRoomModal from '@/Components/Reservations/MoveRoomModal.vue';
import ReservationEditModal from '@/Components/Reservations/ReservationEditModal.vue';
import { channelMeta } from '@/channels';
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
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

const props = defineProps({
    rooms: Array,
    reservations: Array,
    guests: Array,
    startDate: String,
    endDate: String,
    channelFees: { type: Object, default: () => ({}) },
});

const toasts = ref(null);
const showCreateModal = ref(false);
const showDetailModal = ref(false);
const showMoveModal = ref(false);
const showEditModal = ref(false);
const selectedReservation = ref(null);
const detailScroll = ref(null);
const detailCloseButton = ref(null);

const perms = usePage().props.auth.user?.permissions || [];
const canCreate = perms.includes('create_reservations');
const canUpdate = perms.includes('update_reservations');

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

// Generate 14 days array
const days = computed(() => {
    const result = [];
    const start = new Date(props.startDate);
    for (let i = 0; i < 14; i++) {
        const d = new Date(start);
        d.setDate(d.getDate() + i);
        result.push({
            date: d.toISOString().split('T')[0],
            day: d.getDate(),
            weekday: d.toLocaleDateString(getIntlLocale(), { weekday: 'short' }),
            isToday: d.toISOString().split('T')[0] === new Date().toISOString().split('T')[0],
            isWeekend: d.getDay() === 0 || d.getDay() === 6,
        });
    }
    return result;
});

const monthLabel = computed(() => {
    const start = new Date(props.startDate);
    const end = new Date(props.endDate);
    const startMonth = start.toLocaleDateString(getIntlLocale(), { month: 'long', year: 'numeric' });
    const endMonth = end.toLocaleDateString(getIntlLocale(), { month: 'long', year: 'numeric' });
    return startMonth === endMonth ? startMonth : `${start.toLocaleDateString(getIntlLocale(), { month: 'short' })} — ${end.toLocaleDateString(getIntlLocale(), { month: 'short', year: 'numeric' })}`;
});

const statusColors = {
    pending: 'bg-warning-200 border-warning-400 text-warning-800',
    confirmed: 'bg-info-200 border-info-400 text-info-800',
    checked_in: 'bg-accent-200 border-accent-500 text-accent-900',
    checked_out: 'bg-neutral-200 border-neutral-400 text-neutral-600',
};

// Get reservation for a room on a specific date
function getReservation(roomId, date) {
    return props.reservations.find(r =>
        r.room_id === roomId &&
        r.check_in_date <= date &&
        r.check_out_date > date
    );
}

// Check if date is check-in day for a reservation
function isCheckInDay(reservation, date) {
    return reservation.check_in_date === date;
}

// Get span (days) for a reservation block starting from a date
function getReservationSpan(reservation, fromDate) {
    const end = new Date(reservation.check_out_date);
    const from = new Date(fromDate);
    const lastDay = new Date(props.endDate);
    if (end <= lastDay) {
        return Math.ceil((end - from) / (1000 * 60 * 60 * 24));
    }
    // continues past the visible window — the guest still occupies the last column
    return Math.ceil((lastDay - from) / (1000 * 60 * 60 * 24)) + 1;
}

// Direction the grid slides when the week changes: 'next' = forward, 'prev' = backward
const slideDir = ref('next');

function goToWeek(startStr, direction) {
    slideDir.value = direction >= 0 ? 'next' : 'prev';
    router.get(route('reservations.calendar'), { start: startStr }, { preserveState: true, preserveScroll: true });
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
    return new Intl.NumberFormat(getIntlLocale(), { style: 'currency', currency: 'EUR' }).format(Number(value) || 0);
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
    const td = e.target.closest('td[data-date]');
    return td ? { roomId: Number(td.dataset.room), date: td.dataset.date } : null;
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
    showDetailModal.value = false;
    showMoveModal.value = true;
}
function onRoomMoved() {
    toasts.value?.success(translate('admin.generated.k_282bd8c71f67'));
}

function openEdit() {
    showDetailModal.value = false;
    showEditModal.value = true;
}
function onReservationUpdated() {
    toasts.value?.success(translate('admin.generated.k_500a172395d3'));
}
function doCancel(res) {
    if (!res) return;
    const room = roomOf(res)?.room_number;
    const extra = groupSiblings(res).length ? translate('admin.generated.k_d42546aba21b') : '';
    if (!confirm(`Anulo rezervimin e ${res.guest?.first_name} ${res.guest?.last_name} — dhoma ${room}?${extra}`)) return;
    router.post(route('reservations.cancel', res.id), {}, {
        preserveScroll: true,
        onSuccess: () => { showDetailModal.value = false; toasts.value?.success(translate('admin.generated.k_bae1d5058ac1')); },
    });
}

function doCheckIn(res) {
    router.post(route('reservations.check-in', res.id), {}, {
        preserveScroll: true,
        onSuccess: () => { showDetailModal.value = false; toasts.value?.success(translate('admin.generated.k_1515c2ebac05')); },
    });
}

function doCheckOut(res) {
    router.post(route('reservations.check-out', res.id), {}, {
        preserveScroll: true,
        onSuccess: () => { showDetailModal.value = false; toasts.value?.success(translate('admin.generated.k_80056aa4666f')); },
        // A guest who still owes is blocked server-side — keep the modal open and show why.
        onError: (errors) => toasts.value?.error(errors.settle_method || 'Check-out deshtoi.'),
    });
}

// Build calendar grid - for each room, pre-calculate which cells are occupied
function getRoomCalendarCells(room) {
    const cells = [];
    let skipUntil = null;

    for (const day of days.value) {
        if (skipUntil && day.date < skipUntil) {
            continue; // skip — covered by colspan
        }
        skipUntil = null;

        const res = getReservation(room.id, day.date);
        if (res && isCheckInDay(res, day.date)) {
            const span = getReservationSpan(res, day.date);
            cells.push({ type: 'reservation', reservation: res, span, date: day.date });
            const skipDate = new Date(day.date);
            skipDate.setDate(skipDate.getDate() + span);
            skipUntil = skipDate.toISOString().split('T')[0];
        } else if (res) {
            // mid-reservation — already covered by previous colspan from earlier start
            // but if reservation started before our view, we need to show it
            const viewStart = new Date(props.startDate);
            const resStart = new Date(res.check_in_date);
            if (resStart < viewStart && day.date === days.value[0].date) {
                const span = getReservationSpan(res, day.date);
                cells.push({ type: 'reservation', reservation: res, span, date: day.date });
                const skipDate = new Date(day.date);
                skipDate.setDate(skipDate.getDate() + span);
                skipUntil = skipDate.toISOString().split('T')[0];
            } else {
                continue;
            }
        } else {
            cells.push({ type: 'empty', date: day.date, roomId: room.id });
        }
    }
    return cells;
}
</script>

<template>
    <AppLayout>
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-h2 text-primary-900">{{ $t('admin.generated.k_eb3d197bf264') }}</h1>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <Link :href="route('reservations.index')" class="no-underline">
                    <Button variant="outline" size="sm">{{ $t('admin.generated.k_8ad6f4281da4') }}</Button>
                </Link>
                <Button variant="outline" size="sm" @click="navigate(-1)">{{ $t('admin.generated.k_fe085fc2cb10') }}</Button>
                <Button variant="ghost" size="sm" @click="goToToday">{{ $t('admin.generated.k_6538d1136795') }}</Button>
                <Button variant="outline" size="sm" @click="navigate(1)">{{ $t('admin.generated.k_b46636dde7af') }}</Button>
                <Button v-if="canCreate" variant="primary" size="sm" @click="openCreate(null, new Date().toISOString().split('T')[0])">{{ $t('admin.generated.k_fb45801b58c0') }}</Button>
            </div>
        </div>

        <!-- Month label and room order -->
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <p class="text-label text-neutral-500 uppercase tracking-wider">{{ monthLabel }}</p>
            <div class="flex items-center gap-2">
                <span class="whitespace-nowrap text-small text-neutral-500">{{ $t('admin.generated.k_d27e193601ff') }}</span>
                <div class="inline-flex rounded-lg border border-neutral-200 bg-white p-0.5" role="group" :aria-label="$t('admin.generated.k_96f31a0be77c')">
                    <button
                        type="button"
                        :aria-pressed="roomSort === 'number'"
                        :class="['rounded-md px-3 py-1.5 text-body-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500/50', roomSort === 'number' ? 'bg-primary-900 text-white' : 'text-neutral-500 hover:text-neutral-800']"
                        @click="roomSort = 'number'"
                    >{{ $t('admin.generated.k_fa144b375912') }}</button>
                    <button
                        type="button"
                        :aria-pressed="roomSort === 'type'"
                        :class="['rounded-md px-3 py-1.5 text-body-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500/50', roomSort === 'type' ? 'bg-primary-900 text-white' : 'text-neutral-500 hover:text-neutral-800']"
                        @click="roomSort = 'type'"
                    >{{ $t('admin.generated.k_e129e8dfc500') }}</button>
                </div>
            </div>
        </div>

        <!-- Calendar grid — slides horizontally when changing week -->
        <div class="relative overflow-hidden">
        <Transition :name="slideDir === 'next' ? 'cal-next' : 'cal-prev'">
        <div :key="startDate" class="bg-white rounded-lg border border-neutral-200 max-h-[calc(100vh-14rem)] overflow-auto overscroll-contain">
            <table class="w-full border-collapse" style="min-width: 900px;">
                <!-- Day headers -->
                <thead>
                    <tr>
                        <th class="sticky top-0 left-0 z-30 bg-neutral-50 border-b border-r border-neutral-200 px-3 py-2 text-left text-label text-neutral-600 w-28">{{ $t('admin.generated.k_a1206af0984a') }}</th>
                        <th
                            v-for="day in days"
                            :key="day.date"
                            :class="[
                                'sticky top-0 z-20 border-b border-r border-neutral-200 px-1 py-2 text-center min-w-[56px]',
                                day.isToday ? 'bg-accent-50' : day.isWeekend ? 'bg-neutral-100' : 'bg-neutral-50',
                            ]"
                        >
                            <p class="text-tiny text-neutral-400 uppercase">{{ day.weekday }}</p>
                            <p :class="[
                                'text-body-sm font-medium mt-0.5',
                                day.isToday ? 'text-accent-700' : 'text-neutral-700',
                            ]">{{ day.day }}</p>
                        </th>
                    </tr>
                </thead>

                <!-- Room rows -->
                <tbody @mousedown="onGridDown" @mouseover="onGridOver">
                    <tr v-for="room in sortedRooms" :key="room.id" class="group">
                        <!-- Room label -->
                        <td class="sticky left-0 z-10 bg-white border-b border-r border-neutral-200 px-3 py-2 group-hover:bg-neutral-50 transition-colors">
                            <p class="text-body-sm text-primary-900 font-medium">{{ room.room_number }}</p>
                            <p class="text-tiny text-neutral-400">{{ room.room_type?.name }}</p>
                            <span v-if="room.status === 'maintenance'" class="mt-1 inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-tiny bg-warning-100 text-warning-800" :title="$t('admin.generated.k_d00b57c2ba36')">{{ $t('admin.generated.k_b751d73e5e72') }}</span>
                        </td>

                        <!-- Calendar cells -->
                        <template v-for="cell in getRoomCalendarCells(room)" :key="cell.date">
                            <!-- Reservation block -->
                            <td
                                v-if="cell.type === 'reservation'"
                                :colspan="cell.span"
                                class="border-b border-r border-neutral-200 p-0.5 h-12"
                            >
                                <button
                                    :class="[
                                        'w-full h-full rounded-md border px-2 py-1 text-left cursor-pointer transition-all duration-100 hover:shadow-md truncate',
                                        statusColors[cell.reservation.status],
                                    ]"
                                    @click="openDetail(cell.reservation)"
                                >
                                    <p class="text-tiny font-medium truncate">{{ cell.reservation.guest?.first_name }} {{ cell.reservation.guest?.last_name }}</p>
                                    <p class="text-tiny opacity-70">€{{ cell.reservation.total_amount }}</p>
                                </button>
                            </td>

                            <!-- Empty cell — click for 1 night, or drag across days for a range -->
                            <td
                                v-else
                                :data-date="cell.date"
                                :data-room="cell.roomId"
                                class="border-b border-r border-neutral-200 p-0.5 h-12 select-none"
                                :class="[
                                    canCreate && 'cursor-pointer hover:bg-accent-50/50',
                                    isInDrag(cell.roomId, cell.date) && 'bg-accent-100',
                                ]"
                            >
                            </td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </div>
        </Transition>
        </div>

        <!-- Legend -->
        <div class="mt-4 flex flex-wrap gap-4">
            <div class="flex items-center gap-1.5">
                <div class="h-3 w-6 rounded-sm bg-warning-200 border border-warning-400"></div>
                <span class="text-tiny text-neutral-500">{{ $t('admin.generated.k_61e9368aebf5') }}</span>
            </div>
            <div class="flex items-center gap-1.5">
                <div class="h-3 w-6 rounded-sm bg-info-200 border border-info-400"></div>
                <span class="text-tiny text-neutral-500">{{ $t('admin.generated.k_8285dafa3dd1') }}</span>
            </div>
            <div class="flex items-center gap-1.5">
                <div class="h-3 w-6 rounded-sm bg-accent-200 border border-accent-500"></div>
                <span class="text-tiny text-neutral-500">{{ $t('admin.generated.k_d7c217631347') }}</span>
            </div>
            <div class="flex items-center gap-1.5">
                <div class="h-3 w-6 rounded-sm bg-neutral-200 border border-neutral-400"></div>
                <span class="text-tiny text-neutral-500">{{ $t('admin.generated.k_75cbe57f73eb') }}</span>
            </div>
        </div>

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
                        <Link :href="route('reservations.show', selectedReservation.id)" class="no-underline"><Button class="w-full justify-center"><ExternalLink class="h-4 w-4" />{{ $t('admin.calendarPreview.manage') }}</Button></Link>
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
            @guest-created="toasts?.success('Mysafiri u shtua.')"
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
    </AppLayout>
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
/* Week-change slide: the leaving grid overlaps the entering one so they cross, not stack */
.cal-next-enter-active,
.cal-next-leave-active,
.cal-prev-enter-active,
.cal-prev-leave-active {
    transition: transform 420ms cubic-bezier(0.4, 0, 0.2, 1), opacity 420ms ease;
    will-change: transform, opacity;
}
.cal-next-leave-active,
.cal-prev-leave-active {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
}
/* Pas (forward): new week comes in from the right, old exits left */
.cal-next-enter-from {
    transform: translateX(100%);
    opacity: 0;
}
.cal-next-leave-to {
    transform: translateX(-100%);
    opacity: 0;
}
/* Para (backward): new week comes in from the left, old exits right */
.cal-prev-enter-from {
    transform: translateX(-100%);
    opacity: 0;
}
.cal-prev-leave-to {
    transform: translateX(100%);
    opacity: 0;
}
@media (prefers-reduced-motion: reduce) {
    .drawer-slide-enter-active,
    .drawer-slide-leave-active,
    .drawer-fade-enter-active,
    .drawer-fade-leave-active,
    .cal-next-enter-active,
    .cal-next-leave-active,
    .cal-prev-enter-active,
    .cal-prev-leave-active {
        transition: opacity 150ms ease;
        transform: none !important;
    }
}
</style>
