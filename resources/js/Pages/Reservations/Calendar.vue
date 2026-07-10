<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Modal from '@/Components/UI/Modal.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';
import ReservationCreateModal from '@/Components/Reservations/ReservationCreateModal.vue';
import MoveRoomModal from '@/Components/Reservations/MoveRoomModal.vue';
import ReservationEditModal from '@/Components/Reservations/ReservationEditModal.vue';
import { channelMeta } from '@/channels';
import { Link } from '@inertiajs/vue3';

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

const perms = usePage().props.auth.user?.permissions || [];
const canCreate = perms.includes('create_reservations');
const canUpdate = perms.includes('update_reservations');

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
            weekday: d.toLocaleDateString('sq-AL', { weekday: 'short' }),
            isToday: d.toISOString().split('T')[0] === new Date().toISOString().split('T')[0],
            isWeekend: d.getDay() === 0 || d.getDay() === 6,
        });
    }
    return result;
});

const monthLabel = computed(() => {
    const start = new Date(props.startDate);
    const end = new Date(props.endDate);
    const startMonth = start.toLocaleDateString('sq-AL', { month: 'long', year: 'numeric' });
    const endMonth = end.toLocaleDateString('sq-AL', { month: 'long', year: 'numeric' });
    return startMonth === endMonth ? startMonth : `${start.toLocaleDateString('sq-AL', { month: 'short' })} — ${end.toLocaleDateString('sq-AL', { month: 'short', year: 'numeric' })}`;
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

function openDetail(reservation) {
    selectedReservation.value = reservation;
    showDetailModal.value = true;
}

// --- Detail popup helpers ---
const statusLabel = {
    pending: 'Ne pritje',
    confirmed: 'Konfirmuar',
    checked_in: 'Brenda',
    checked_out: 'Larguar',
    cancelled: 'Anulluar',
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
onMounted(() => window.addEventListener('mouseup', endDrag));
onBeforeUnmount(() => window.removeEventListener('mouseup', endDrag));

function onReservationCreated() {
    toasts.value?.success('Rezervimi u krijua.');
}

function openMove() {
    showDetailModal.value = false;
    showMoveModal.value = true;
}
function onRoomMoved() {
    toasts.value?.success('Mysafiri u zhvendos.');
}

function openEdit() {
    showDetailModal.value = false;
    showEditModal.value = true;
}
function onReservationUpdated() {
    toasts.value?.success('Rezervimi u perditesua.');
}
function doCancel(res) {
    if (!res) return;
    const room = roomOf(res)?.room_number;
    const extra = groupSiblings(res).length ? ' (anulon vetëm këtë dhomë, jo gjithë booking-un)' : '';
    if (!confirm(`Anulo rezervimin e ${res.guest?.first_name} ${res.guest?.last_name} — dhoma ${room}?${extra}`)) return;
    router.post(route('reservations.cancel', res.id), {}, {
        preserveScroll: true,
        onSuccess: () => { showDetailModal.value = false; toasts.value?.success('Rezervimi u anulua.'); },
    });
}

// Net after channel commission — for the Total line in the detail popup.
function feePctOf(res) {
    return Number(props.channelFees?.[res?.channel]) || 0;
}
function netOfRes(res) {
    const total = Number(res?.total_amount) || 0;
    return total - Math.round(total * feePctOf(res)) / 100;
}

function doCheckIn(res) {
    router.post(route('reservations.check-in', res.id), {}, {
        preserveScroll: true,
        onSuccess: () => { showDetailModal.value = false; toasts.value?.success('Check-in OK'); },
    });
}

function doCheckOut(res) {
    router.post(route('reservations.check-out', res.id), {}, {
        preserveScroll: true,
        onSuccess: () => { showDetailModal.value = false; toasts.value?.success('Check-out OK'); },
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
                    <h1 class="text-h2 text-primary-900">Kalendari i Rezervimeve</h1>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <Link :href="route('reservations.index')" class="no-underline">
                    <Button variant="outline" size="sm">📋 Lista</Button>
                </Link>
                <Button variant="outline" size="sm" @click="navigate(-1)">← Para</Button>
                <Button variant="ghost" size="sm" @click="goToToday">Sot</Button>
                <Button variant="outline" size="sm" @click="navigate(1)">Pas →</Button>
                <Button v-if="canCreate" variant="primary" size="sm" @click="openCreate(null, new Date().toISOString().split('T')[0])">+ Rezervim</Button>
            </div>
        </div>

        <!-- Month label -->
        <p class="text-label text-neutral-500 uppercase tracking-wider mb-3">{{ monthLabel }}</p>

        <!-- Calendar grid — slides horizontally when changing week -->
        <div class="relative overflow-hidden">
        <Transition :name="slideDir === 'next' ? 'cal-next' : 'cal-prev'">
        <div :key="startDate" class="bg-white rounded-lg border border-neutral-200 overflow-x-auto">
            <table class="w-full border-collapse" style="min-width: 900px;">
                <!-- Day headers -->
                <thead>
                    <tr>
                        <th class="sticky left-0 z-10 bg-neutral-50 border-b border-r border-neutral-200 px-3 py-2 text-left text-label text-neutral-600 w-28">Dhoma</th>
                        <th
                            v-for="day in days"
                            :key="day.date"
                            :class="[
                                'border-b border-r border-neutral-200 px-1 py-2 text-center min-w-[56px]',
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
                    <tr v-for="room in rooms" :key="room.id" class="group">
                        <!-- Room label -->
                        <td class="sticky left-0 z-10 bg-white border-b border-r border-neutral-200 px-3 py-2 group-hover:bg-neutral-50 transition-colors">
                            <p class="text-body-sm text-primary-900 font-medium">{{ room.room_number }}</p>
                            <p class="text-tiny text-neutral-400">{{ room.room_type?.name }}</p>
                            <span v-if="room.status === 'maintenance'" class="mt-1 inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-tiny bg-warning-100 text-warning-800" title="Kjo dhome nuk rezervohet dot derisa te ndryshosh statusin te Dhomat">🔧 Mirëmbajtje</span>
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
                <span class="text-tiny text-neutral-500">Ne pritje</span>
            </div>
            <div class="flex items-center gap-1.5">
                <div class="h-3 w-6 rounded-sm bg-info-200 border border-info-400"></div>
                <span class="text-tiny text-neutral-500">Konfirmuar</span>
            </div>
            <div class="flex items-center gap-1.5">
                <div class="h-3 w-6 rounded-sm bg-accent-200 border border-accent-500"></div>
                <span class="text-tiny text-neutral-500">Brenda</span>
            </div>
            <div class="flex items-center gap-1.5">
                <div class="h-3 w-6 rounded-sm bg-neutral-200 border border-neutral-400"></div>
                <span class="text-tiny text-neutral-500">Larguar</span>
            </div>
        </div>

        <!-- Reservation Detail Modal -->
        <Modal :show="showDetailModal" :title="`Rezervimi — ${selectedReservation?.guest?.first_name} ${selectedReservation?.guest?.last_name}`" max-width="md" @close="showDetailModal = false">
            <div v-if="selectedReservation" class="space-y-4">
                <!-- Status + source -->
                <div class="flex items-center gap-3 flex-wrap">
                    <Badge :variant="statusVariant(selectedReservation.status)" dot>
                        {{ statusLabel[selectedReservation.status] || selectedReservation.status }}
                    </Badge>
                    <span class="inline-flex items-center gap-1.5 text-tiny text-neutral-500">
                        <span class="h-2 w-2 rounded-full" :style="{ background: channelMeta(selectedReservation.channel).color }" />
                        {{ channelMeta(selectedReservation.channel).label }}
                    </span>
                </div>

                <div v-if="selectedReservation.status === 'cancelled'" class="rounded-lg bg-error-50 border border-error-100 px-3 py-2 text-body-sm text-error-700">
                    Ky rezervim është anuluar — vetëm për shikim.
                </div>

                <!-- Key facts -->
                <div class="grid grid-cols-2 gap-x-4 gap-y-3">
                    <div>
                        <p class="text-tiny text-neutral-400 uppercase">Dhoma</p>
                        <p class="text-body-sm text-primary-900 font-medium">
                            {{ roomOf(selectedReservation)?.room_number }}
                            <span class="text-neutral-400 font-normal">{{ roomOf(selectedReservation)?.room_type?.name }}</span>
                        </p>
                    </div>
                    <div>
                        <p class="text-tiny text-neutral-400 uppercase">Persona</p>
                        <p class="text-body-sm text-primary-900">
                            {{ selectedReservation.adults }} te rritur<span v-if="selectedReservation.children"> · {{ selectedReservation.children }} femije</span>
                        </p>
                    </div>
                    <div>
                        <p class="text-tiny text-neutral-400 uppercase">Check-in</p>
                        <p class="text-body-sm text-primary-900">{{ selectedReservation.check_in_date }}</p>
                    </div>
                    <div>
                        <p class="text-tiny text-neutral-400 uppercase">Check-out</p>
                        <p class="text-body-sm text-primary-900">
                            {{ selectedReservation.check_out_date }}
                            <span class="text-neutral-400">· {{ nightsOf(selectedReservation) }} net</span>
                        </p>
                    </div>
                    <div>
                        <p class="text-tiny text-neutral-400 uppercase">Total</p>
                        <p class="text-body-sm text-accent-600 font-medium">
                            €{{ selectedReservation.total_amount }}
                            <span v-if="feePctOf(selectedReservation) > 0" class="text-neutral-400 font-normal">· Neto €{{ netOfRes(selectedReservation).toFixed(2) }}</span>
                        </p>
                    </div>
                    <div v-if="selectedReservation.guest?.phone || selectedReservation.guest?.email">
                        <p class="text-tiny text-neutral-400 uppercase">Kontakt</p>
                        <p class="text-body-sm text-primary-900 break-words">
                            <a v-if="selectedReservation.guest?.phone" :href="`tel:${selectedReservation.guest.phone}`" class="text-accent-700 no-underline hover:underline">{{ selectedReservation.guest.phone }}</a>
                            <span v-if="selectedReservation.guest?.phone && selectedReservation.guest?.email" class="text-neutral-300"> · </span>
                            <a v-if="selectedReservation.guest?.email" :href="`mailto:${selectedReservation.guest.email}`" class="text-accent-700 no-underline hover:underline">{{ selectedReservation.guest.email }}</a>
                        </p>
                    </div>
                </div>

                <!-- Notes -->
                <div v-if="selectedReservation.notes" class="rounded-lg bg-neutral-50 border border-neutral-100 px-3 py-2">
                    <p class="text-tiny text-neutral-400 uppercase mb-0.5">Shenime</p>
                    <p class="text-body-sm text-neutral-700 whitespace-pre-line">{{ selectedReservation.notes }}</p>
                </div>

                <!-- Multi-room booking -->
                <div v-if="groupSiblings(selectedReservation).length" class="rounded-lg bg-info-50 border border-info-100 px-3 py-2">
                    <p class="text-body-sm text-info-800">
                        🔗 Pjese e nje booking-u me {{ groupSiblings(selectedReservation).length + 1 }} dhoma — bashke me dhomat:
                        <span class="font-medium">{{ groupSiblings(selectedReservation).map((r) => roomOf(r)?.room_number).filter(Boolean).join(', ') }}</span>
                    </p>
                </div>
            </div>
            <template #footer>
                <Button v-if="canUpdate && selectedReservation?.status === 'confirmed'" variant="primary" size="sm" @click="doCheckIn(selectedReservation)">Check-in</Button>
                <Button v-if="canUpdate && selectedReservation?.status === 'checked_in'" variant="secondary" size="sm" @click="doCheckOut(selectedReservation)">Check-out</Button>
                <Button v-if="canUpdate && selectedReservation?.status === 'checked_in'" variant="outline" size="sm" @click="openMove">Zhvendos dhomën</Button>
                <Button v-if="canUpdate && selectedReservation && !['checked_in','checked_out','cancelled'].includes(selectedReservation.status)" variant="outline" size="sm" @click="openEdit">Edito</Button>
                <Link v-if="selectedReservation" :href="route('reservations.show', selectedReservation.id)" class="no-underline">
                    <Button variant="outline" size="sm">Detaje</Button>
                </Link>
                <Button v-if="canUpdate && selectedReservation && ['pending','confirmed'].includes(selectedReservation.status)" variant="outline" size="sm" class="text-error-600" @click="doCancel(selectedReservation)">Anulo</Button>
                <Button variant="ghost" @click="showDetailModal = false">Mbyll</Button>
            </template>
        </Modal>

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
    .cal-next-enter-active,
    .cal-next-leave-active,
    .cal-prev-enter-active,
    .cal-prev-leave-active {
        transition: opacity 150ms ease;
        transform: none !important;
    }
}
</style>
