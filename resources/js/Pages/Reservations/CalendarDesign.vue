<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowLeftRight,
    BedDouble,
    CalendarDays,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ChevronUp,
    CircleDollarSign,
    Clock3,
    CreditCard,
    Filter,
    Hash,
    LogIn,
    LogOut,
    Mail,
    MessageSquare,
    Pencil,
    Phone,
    Plus,
    Search,
    Sparkles,
    X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import { getIntlLocale } from '@/i18n';

const visibleDays = ref(14);
const showSummary = ref(false);
const anchorDate = ref(startOfDay(new Date()));
const query = ref('');
const statusFilter = ref('all');
const selectedReservation = ref(null);
const dateHeaderTrack = ref(null);

const rooms = [
    { id: 1, number: '101', type: 'Deluxe Sea View', floor: 1, housekeeping: 'clean' },
    { id: 2, number: '102', type: 'Deluxe Sea View', floor: 1, housekeeping: 'clean' },
    { id: 3, number: '103', type: 'Double Garden', floor: 1, housekeeping: 'inspect' },
    { id: 4, number: '104', type: 'Double Garden', floor: 1, housekeeping: 'clean' },
    { id: 5, number: '201', type: 'Junior Suite', floor: 2, housekeeping: 'clean' },
    { id: 6, number: '202', type: 'Junior Suite', floor: 2, housekeeping: 'dirty' },
    { id: 7, number: '203', type: 'Family Suite', floor: 2, housekeeping: 'clean' },
    { id: 8, number: '204', type: 'Family Suite', floor: 2, housekeeping: 'maintenance' },
];

const reservations = [
    { id: 101, roomId: 1, guest: 'Elena Rossi', start: -1, nights: 4, status: 'checked_in', channel: 'Booking.com', paid: true, total: 420, adults: 2, note: 'Late breakfast requested' },
    { id: 102, roomId: 1, guest: 'Lukas Weber', start: 5, nights: 4, status: 'confirmed', channel: 'Direct', paid: false, total: 560, adults: 2 },
    { id: 103, roomId: 2, guest: 'Sophie Martin', start: 1, nights: 5, status: 'confirmed', channel: 'Airbnb', paid: true, total: 725, adults: 2 },
    { id: 104, roomId: 3, guest: 'Arben Kola', start: 0, nights: 2, status: 'pending', channel: 'Direct', paid: false, total: 180, adults: 1 },
    { id: 105, roomId: 3, guest: 'Nora Jensen', start: 7, nights: 3, status: 'confirmed', channel: 'Expedia', paid: true, total: 330, adults: 2 },
    { id: 106, roomId: 4, guest: 'Marco Bianchi', start: 3, nights: 6, status: 'confirmed', channel: 'Booking.com', paid: false, total: 690, adults: 2 },
    { id: 107, roomId: 5, guest: 'Amelia Brown', start: -2, nights: 5, status: 'checked_in', channel: 'Direct', paid: true, total: 780, adults: 2 },
    { id: 108, roomId: 5, guest: 'Dritan Hoxha', start: 6, nights: 4, status: 'confirmed', channel: 'Direct', paid: false, total: 640, adults: 3 },
    { id: 109, roomId: 6, guest: 'Emma Wilson', start: 2, nights: 3, status: 'confirmed', channel: 'Airbnb', paid: true, total: 465, adults: 2 },
    { id: 110, roomId: 7, guest: 'Familja Gashi', start: 0, nights: 7, status: 'checked_in', channel: 'Booking.com', paid: false, total: 1190, adults: 4 },
    { id: 111, roomId: 7, guest: 'Oliver Smith', start: 9, nights: 4, status: 'pending', channel: 'Expedia', paid: false, total: 680, adults: 3 },
];

const reservationMeta = {
    101: { reference: 'VM-240713-101', phone: '+39 347 555 0192', email: 'elena.rossi@example.com', country: 'Italy', paidAmount: 420, bookedAt: '02 Jul 2026', arrivalTime: '15:30', mealPlan: 'Breakfast included', note: 'Late breakfast requested. Airport transfer confirmed.' },
    102: { reference: 'VM-240713-102', phone: '+49 151 234 8890', email: 'lukas.weber@example.com', country: 'Germany', paidAmount: 160, bookedAt: '08 Jul 2026', arrivalTime: '18:00', mealPlan: 'Breakfast included', note: 'Sea-view room preferred.' },
    103: { reference: 'AIR-HM82Q', phone: '+33 6 44 12 88 03', email: 'sophie.martin@example.com', country: 'France', paidAmount: 725, bookedAt: '29 Jun 2026', arrivalTime: '16:00', mealPlan: 'Room only', note: 'Allergic to nuts.' },
    104: { reference: 'VM-240713-104', phone: '+355 69 220 1144', email: 'arben.kola@example.com', country: 'Albania', paidAmount: 0, bookedAt: '12 Jul 2026', arrivalTime: '13:00', mealPlan: 'Breakfast included', note: 'Confirmation call required.' },
    105: { reference: 'EXP-847291', phone: '+45 22 45 19 80', email: 'nora.jensen@example.com', country: 'Denmark', paidAmount: 330, bookedAt: '04 Jul 2026', arrivalTime: '17:30', mealPlan: 'Breakfast included' },
    106: { reference: 'BKG-419735', phone: '+39 333 412 7801', email: 'marco.bianchi@example.com', country: 'Italy', paidAmount: 200, bookedAt: '01 Jul 2026', arrivalTime: '14:30', mealPlan: 'Breakfast included', note: 'Needs baby cot.' },
    107: { reference: 'VM-240713-107', phone: '+44 7700 900218', email: 'amelia.brown@example.com', country: 'United Kingdom', paidAmount: 780, bookedAt: '20 Jun 2026', arrivalTime: '12:45', mealPlan: 'Breakfast included' },
    108: { reference: 'VM-240713-108', phone: '+355 68 330 9081', email: 'dritan.hoxha@example.com', country: 'Albania', paidAmount: 200, bookedAt: '10 Jul 2026', arrivalTime: '15:00', mealPlan: 'Room only' },
    109: { reference: 'AIR-LP19D', phone: '+44 7700 900477', email: 'emma.wilson@example.com', country: 'United Kingdom', paidAmount: 465, bookedAt: '06 Jul 2026', arrivalTime: '19:00', mealPlan: 'Breakfast included' },
    110: { reference: 'BKG-920174', phone: '+383 44 221 760', email: 'gashi.family@example.com', country: 'Kosovo', paidAmount: 500, bookedAt: '18 Jun 2026', arrivalTime: '14:00', mealPlan: 'Breakfast included', note: 'Two extra pillows and a baby cot.' },
    111: { reference: 'EXP-104857', phone: '+44 7700 900612', email: 'oliver.smith@example.com', country: 'United Kingdom', paidAmount: 0, bookedAt: '11 Jul 2026', arrivalTime: '16:30', mealPlan: 'Room only' },
};

const statusStyles = {
    checked_in: 'border-emerald-300 bg-emerald-100 text-emerald-950 hover:bg-emerald-200',
    confirmed: 'border-sky-300 bg-sky-100 text-sky-950 hover:bg-sky-200',
    pending: 'border-amber-300 bg-amber-100 text-amber-950 hover:bg-amber-200',
};

const channelColors = {
    'Direct': '#2d6a4f',
    'Booking.com': '#0e7fd6',
    'Airbnb': '#e05b65',
    'Expedia': '#d4a017',
};

function startOfDay(date) {
    const value = new Date(date);
    value.setHours(0, 0, 0, 0);
    return value;
}

function addDays(date, amount) {
    const value = new Date(date);
    value.setDate(value.getDate() + amount);
    return value;
}

function isoDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

const days = computed(() => Array.from({ length: visibleDays.value }, (_, index) => {
    const date = addDays(anchorDate.value, index);
    return {
        date,
        iso: isoDate(date),
        day: date.getDate(),
        weekday: date.toLocaleDateString(getIntlLocale(), { weekday: 'short' }),
        month: date.toLocaleDateString(getIntlLocale(), { month: 'short' }),
        weekend: [0, 6].includes(date.getDay()),
        today: isoDate(date) === isoDate(new Date()),
    };
}));

const dateRangeLabel = computed(() => {
    const first = days.value[0]?.date;
    const last = days.value.at(-1)?.date;
    if (!first || !last) return '';
    return `${first.toLocaleDateString(getIntlLocale(), { day: 'numeric', month: 'short' })} – ${last.toLocaleDateString(getIntlLocale(), { day: 'numeric', month: 'short', year: 'numeric' })}`;
});

const groupedRooms = computed(() => rooms.reduce((groups, room) => {
    (groups[room.floor] ||= []).push(room);
    return groups;
}, {}));

const filteredReservations = computed(() => reservations.filter((reservation) => {
    const matchesQuery = !query.value || reservation.guest.toLowerCase().includes(query.value.toLowerCase());
    const matchesStatus = statusFilter.value === 'all' || reservation.status === statusFilter.value;
    return matchesQuery && matchesStatus;
}));

function reservationsFor(roomId) {
    return filteredReservations.value.filter((reservation) => reservation.roomId === roomId);
}

function reservationStyle(reservation) {
    const start = Math.max(0, reservation.start);
    const end = Math.min(visibleDays.value, reservation.start + reservation.nights);
    if (end <= 0 || start >= visibleDays.value) return { display: 'none' };
    return {
        left: `calc(${(start / visibleDays.value) * 100}% + 3px)`,
        width: `calc(${((end - start) / visibleDays.value) * 100}% - 6px)`,
    };
}

const occupiedRoomNights = computed(() => reservations.reduce((total, reservation) => {
    const start = Math.max(0, reservation.start);
    const end = Math.min(visibleDays.value, reservation.start + reservation.nights);
    return total + Math.max(0, end - start);
}, 0));

const occupancy = computed(() => Math.round((occupiedRoomNights.value / (rooms.length * visibleDays.value)) * 100));
const arrivals = computed(() => reservations.filter((reservation) => reservation.start === 0).length);
const departures = computed(() => reservations.filter((reservation) => reservation.start + reservation.nights === 0).length + 2);
const availableToday = computed(() => rooms.length - reservations.filter((reservation) => reservation.start <= 0 && reservation.start + reservation.nights > 0).length);

function moveRange(direction) {
    anchorDate.value = addDays(anchorDate.value, direction * Math.min(7, visibleDays.value));
}

function resetToday() {
    anchorDate.value = startOfDay(new Date());
}

function selectReservation(reservation) {
    selectedReservation.value = reservation;
}

const selectedMeta = computed(() => selectedReservation.value ? reservationMeta[selectedReservation.value.id] || {} : {});
const selectedRoom = computed(() => rooms.find((room) => room.id === selectedReservation.value?.roomId));
const selectedCheckIn = computed(() => selectedReservation.value ? addDays(anchorDate.value, selectedReservation.value.start) : null);
const selectedCheckOut = computed(() => selectedReservation.value ? addDays(anchorDate.value, selectedReservation.value.start + selectedReservation.value.nights) : null);
const selectedPaidAmount = computed(() => Number(selectedMeta.value.paidAmount || 0));
const selectedBalance = computed(() => Math.max(0, Number(selectedReservation.value?.total || 0) - selectedPaidAmount.value));
const selectedPaymentProgress = computed(() => selectedReservation.value?.total ? Math.min(100, Math.round((selectedPaidAmount.value / selectedReservation.value.total) * 100)) : 0);

function syncDateHeader(event) {
    if (dateHeaderTrack.value) {
        dateHeaderTrack.value.style.transform = `translateX(-${event.currentTarget.scrollLeft}px)`;
    }
}

function formatMoney(value) {
    return new Intl.NumberFormat(getIntlLocale(), { style: 'currency', currency: 'EUR' }).format(value);
}

function formatDate(value) {
    return value?.toLocaleDateString(getIntlLocale(), { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }) || '—';
}
</script>

<template>
    <Head :title="$t('admin.calendarPreview.pageTitle')" />
    <AppLayout>
        <div class="pb-10">
            <div class="mb-5 flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div>
                    <div class="mb-2 flex items-center gap-2 text-body-sm text-neutral-500">
                        <Link :href="route('reservations.calendar')" class="inline-flex items-center gap-1 text-neutral-500 no-underline hover:text-accent-700">
                            <ArrowLeft class="h-4 w-4" /> {{ $t('admin.calendarPreview.currentCalendar') }}
                        </Link>
                        <span>/</span>
                        <span class="font-medium text-accent-700">{{ $t('admin.calendarPreview.preview') }}</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="grid h-11 w-11 place-items-center rounded-xl bg-accent-100 text-accent-700"><CalendarDays class="h-5 w-5" /></span>
                        <div>
                            <h1 class="text-h2 text-primary-900">{{ $t('admin.calendarPreview.title') }}</h1>
                            <p class="mt-0.5 text-body-sm text-neutral-500">{{ $t('admin.calendarPreview.subtitle') }}</p>
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-warning-200 bg-warning-50 px-3 py-1.5 text-tiny font-semibold text-warning-800">
                        <Sparkles class="h-3.5 w-3.5" /> {{ $t('admin.calendarPreview.mockData') }}
                    </span>
                    <Button variant="outline" size="sm" @click="showSummary = !showSummary">
                        <ChevronUp v-if="showSummary" class="h-4 w-4" />
                        <ChevronDown v-else class="h-4 w-4" />
                        {{ showSummary ? $t('admin.calendarPreview.hideSummary') : $t('admin.calendarPreview.showSummary') }}
                    </Button>
                    <Button variant="outline" size="sm"><Filter class="h-4 w-4" /> {{ $t('admin.calendarPreview.filters') }}</Button>
                    <Button size="sm"><Plus class="h-4 w-4" /> {{ $t('admin.calendarPreview.newReservation') }}</Button>
                </div>
            </div>

            <div v-if="!showSummary" class="mb-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                <div class="flex items-center justify-between rounded-xl border border-neutral-200 bg-white px-4 py-2.5 shadow-card">
                    <div><p class="text-tiny font-medium text-neutral-500">{{ $t('admin.calendarPreview.occupancy') }}</p><p class="mt-0.5 text-h4 font-extrabold text-primary-900">{{ occupancy }}%</p></div>
                    <span class="grid h-8 w-8 place-items-center rounded-lg bg-accent-50 text-accent-700"><BedDouble class="h-4 w-4" /></span>
                </div>
                <div class="flex items-center justify-between rounded-xl border border-neutral-200 bg-white px-4 py-2.5 shadow-card">
                    <div><p class="text-tiny font-medium text-neutral-500">{{ $t('admin.calendarPreview.arrivalsToday') }}</p><p class="mt-0.5 text-h4 font-extrabold text-primary-900">{{ arrivals }}</p></div>
                    <span class="grid h-8 w-8 place-items-center rounded-lg bg-info-50 text-info-700"><LogIn class="h-4 w-4" /></span>
                </div>
                <div class="flex items-center justify-between rounded-xl border border-neutral-200 bg-white px-4 py-2.5 shadow-card">
                    <div><p class="text-tiny font-medium text-neutral-500">{{ $t('admin.calendarPreview.departuresToday') }}</p><p class="mt-0.5 text-h4 font-extrabold text-primary-900">{{ departures }}</p></div>
                    <span class="grid h-8 w-8 place-items-center rounded-lg bg-warning-50 text-warning-700"><LogOut class="h-4 w-4" /></span>
                </div>
                <div class="flex items-center justify-between rounded-xl border border-neutral-200 bg-white px-4 py-2.5 shadow-card">
                    <div><p class="text-tiny font-medium text-neutral-500">{{ $t('admin.calendarPreview.availableTonight') }}</p><p class="mt-0.5 text-h4 font-extrabold text-primary-900">{{ availableToday }} <span class="text-tiny font-medium text-neutral-400">/ {{ rooms.length }}</span></p></div>
                    <span class="grid h-8 w-8 place-items-center rounded-lg bg-success-50 text-success-700"><CheckCircle2 class="h-4 w-4" /></span>
                </div>
            </div>

            <div v-else class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-center justify-between"><p class="text-body-sm font-medium text-neutral-500">{{ $t('admin.calendarPreview.occupancy') }}</p><BedDouble class="h-4 w-4 text-accent-600" /></div>
                    <div class="mt-2 flex items-end justify-between gap-3"><p class="text-h2 font-extrabold text-primary-900">{{ occupancy }}%</p><p class="text-tiny text-success-700">+8% {{ $t('admin.calendarPreview.vsLastWeek') }}</p></div>
                    <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-neutral-100"><div class="h-full rounded-full bg-accent-500" :style="{ width: `${occupancy}%` }" /></div>
                </div>
                <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-center justify-between"><p class="text-body-sm font-medium text-neutral-500">{{ $t('admin.calendarPreview.arrivalsToday') }}</p><LogIn class="h-4 w-4 text-info-600" /></div>
                    <p class="mt-2 text-h2 font-extrabold text-primary-900">{{ arrivals }}</p><p class="mt-1 text-tiny text-neutral-400">{{ $t('admin.calendarPreview.nextArrival') }} 14:00</p>
                </div>
                <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-center justify-between"><p class="text-body-sm font-medium text-neutral-500">{{ $t('admin.calendarPreview.departuresToday') }}</p><LogOut class="h-4 w-4 text-warning-600" /></div>
                    <p class="mt-2 text-h2 font-extrabold text-primary-900">{{ departures }}</p><p class="mt-1 text-tiny text-neutral-400">{{ $t('admin.calendarPreview.before') }} 11:00</p>
                </div>
                <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-center justify-between"><p class="text-body-sm font-medium text-neutral-500">{{ $t('admin.calendarPreview.availableTonight') }}</p><CheckCircle2 class="h-4 w-4 text-success-600" /></div>
                    <p class="mt-2 text-h2 font-extrabold text-primary-900">{{ availableToday }} <span class="text-body-sm font-medium text-neutral-400">/ {{ rooms.length }}</span></p><p class="mt-1 text-tiny text-success-700">{{ $t('admin.calendarPreview.readyToSell') }}</p>
                </div>
            </div>

            <section class="rounded-xl border border-neutral-200 bg-white shadow-card">
                <div class="flex flex-col gap-3 border-b border-neutral-200 p-3 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="flex items-center rounded-lg border border-neutral-200 bg-white p-0.5">
                            <button type="button" class="grid h-8 w-8 place-items-center rounded-md text-neutral-500 hover:bg-neutral-100" @click="moveRange(-1)"><ChevronLeft class="h-4 w-4" /></button>
                            <button type="button" class="min-w-44 px-3 text-body-sm font-bold text-primary-900" @click="resetToday">{{ dateRangeLabel }}</button>
                            <button type="button" class="grid h-8 w-8 place-items-center rounded-md text-neutral-500 hover:bg-neutral-100" @click="moveRange(1)"><ChevronRight class="h-4 w-4" /></button>
                        </div>
                        <button type="button" class="h-9 rounded-lg border border-neutral-200 px-3 text-body-sm font-medium text-neutral-600 hover:bg-neutral-50" @click="resetToday">{{ $t('admin.calendarPreview.today') }}</button>
                        <div class="inline-flex rounded-lg bg-neutral-100 p-0.5">
                            <button v-for="count in [7, 14, 30]" :key="count" type="button" class="rounded-md px-3 py-1.5 text-tiny font-semibold transition" :class="visibleDays === count ? 'bg-white text-primary-900 shadow-sm' : 'text-neutral-500'" @click="visibleDays = count">{{ count }}d</button>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="relative min-w-52 flex-1 lg:flex-none">
                            <Search class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-neutral-400" />
                            <input v-model="query" type="search" class="h-9 w-full rounded-lg border-neutral-200 pl-9 pr-3 text-body-sm focus:border-accent-500 focus:ring-accent-500" :placeholder="$t('admin.calendarPreview.searchGuest')" />
                        </label>
                        <select v-model="statusFilter" class="h-9 rounded-lg border-neutral-200 py-1.5 pl-3 pr-8 text-body-sm text-neutral-600 focus:border-accent-500 focus:ring-accent-500">
                            <option value="all">{{ $t('admin.calendarPreview.allStatuses') }}</option>
                            <option value="checked_in">{{ $t('admin.calendarPreview.inHouse') }}</option>
                            <option value="confirmed">{{ $t('admin.calendarPreview.confirmed') }}</option>
                            <option value="pending">{{ $t('admin.calendarPreview.pending') }}</option>
                        </select>
                    </div>
                </div>

                <div class="sticky top-16 z-40 flex border-b border-neutral-300 bg-white shadow-[0_6px_14px_-10px_rgba(15,23,42,0.45)]">
                    <div class="z-50 flex w-48 shrink-0 items-center border-r border-neutral-200 bg-white px-4 py-3 text-tiny font-bold uppercase tracking-wider text-neutral-500">{{ $t('admin.calendarPreview.room') }}</div>
                    <div class="min-w-0 flex-1 overflow-hidden">
                        <div ref="dateHeaderTrack" class="grid w-full min-w-[858px] will-change-transform" :style="{ gridTemplateColumns: `repeat(${visibleDays}, minmax(76px, 1fr))` }">
                                <div v-for="day in days" :key="day.iso" class="border-r border-neutral-200 px-2 py-2 text-center" :class="day.today ? 'bg-accent-50' : day.weekend ? 'bg-neutral-100/80' : ''">
                                    <p class="text-[10px] font-bold uppercase tracking-wide text-neutral-400">{{ day.weekday }}</p>
                                    <p class="mx-auto mt-1 grid h-7 w-7 place-items-center rounded-full text-body-sm font-bold" :class="day.today ? 'bg-accent-600 text-white' : 'text-neutral-700'">{{ day.day }}</p>
                                    <p class="mt-0.5 text-[10px] text-neutral-400">{{ day.month }}</p>
                                </div>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto overflow-y-visible overscroll-x-contain" @scroll.passive="syncDateHeader">
                    <div class="min-w-[1050px]">
                        <template v-for="(floorRooms, floor) in groupedRooms" :key="floor">
                            <div class="sticky left-0 z-20 flex h-8 items-center border-b border-neutral-200 bg-primary-50 px-4 text-tiny font-bold uppercase tracking-wider text-primary-700">{{ $t('admin.calendarPreview.floor') }} {{ floor }} · {{ floorRooms.length }} {{ $t('admin.calendarPreview.rooms') }}</div>
                            <div v-for="room in floorRooms" :key="room.id" class="group flex border-b border-neutral-200 last:border-b-0">
                                <div class="sticky left-0 z-20 flex h-16 w-48 shrink-0 items-center justify-between border-r border-neutral-200 bg-white px-4 group-hover:bg-neutral-50">
                                    <div><p class="text-body-sm font-extrabold text-primary-900">{{ room.number }}</p><p class="mt-0.5 max-w-28 truncate text-[11px] text-neutral-400">{{ room.type }}</p></div>
                                    <span class="h-2.5 w-2.5 rounded-full ring-2 ring-white" :class="room.housekeeping === 'clean' ? 'bg-success-500' : room.housekeeping === 'dirty' ? 'bg-error-500' : room.housekeeping === 'maintenance' ? 'bg-neutral-400' : 'bg-warning-500'" />
                                </div>
                                <div class="relative grid h-16 min-w-0 flex-1" :style="{ gridTemplateColumns: `repeat(${visibleDays}, minmax(76px, 1fr))` }">
                                    <button v-for="day in days" :key="day.iso" type="button" class="border-r border-neutral-100 transition hover:bg-accent-50" :class="day.today ? 'bg-accent-50/40' : day.weekend ? 'bg-neutral-50' : ''" :aria-label="`${room.number} ${day.iso}`" />
                                    <button
                                        v-for="reservation in reservationsFor(room.id)"
                                        :key="reservation.id"
                                        type="button"
                                        class="absolute top-2 z-10 h-12 overflow-hidden rounded-lg border px-2.5 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                                        :class="statusStyles[reservation.status]"
                                        :style="reservationStyle(reservation)"
                                        @click="selectReservation(reservation)"
                                    >
                                        <span class="flex items-center gap-1.5 truncate text-[11px] font-extrabold"><span class="h-1.5 w-1.5 shrink-0 rounded-full" :style="{ backgroundColor: channelColors[reservation.channel] }" />{{ reservation.guest }}</span>
                                        <span class="mt-0.5 flex items-center justify-between gap-1 text-[10px] opacity-75"><span class="truncate">{{ reservation.channel }}</span><CircleDollarSign class="h-3 w-3 shrink-0" :class="reservation.paid ? 'text-success-700' : 'text-warning-700'" /></span>
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
                    </div>
                    <p class="text-tiny text-neutral-400">{{ $t('admin.calendarPreview.dragHint') }}</p>
                </div>
            </section>
        </div>

        <Transition enter-active-class="duration-200 ease-out" enter-from-class="translate-x-full opacity-0" leave-active-class="duration-150 ease-in" leave-to-class="translate-x-full opacity-0">
            <aside v-if="selectedReservation" class="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col border-l border-neutral-200 bg-white shadow-2xl">
                <div class="shrink-0 border-b border-neutral-200 px-5 py-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-tiny font-bold uppercase tracking-wider text-accent-700">{{ $t('admin.calendarPreview.reservationDetails') }}</p>
                                <span class="rounded-full border px-2 py-0.5 text-[10px] font-bold" :class="statusStyles[selectedReservation.status]">{{ $t(`admin.calendarPreview.${selectedReservation.status === 'checked_in' ? 'inHouse' : selectedReservation.status}`) }}</span>
                            </div>
                            <h2 class="mt-1 truncate text-h3 text-primary-900">{{ selectedReservation.guest }}</h2>
                            <p class="mt-1 flex items-center gap-1.5 text-tiny text-neutral-400"><Hash class="h-3.5 w-3.5" /> {{ selectedMeta.reference }}</p>
                        </div>
                        <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-neutral-400 hover:bg-neutral-100" @click="selectedReservation = null"><X class="h-5 w-5" /></button>
                    </div>
                </div>

                <div class="flex-1 space-y-5 overflow-y-auto p-5">
                    <section>
                        <div class="mb-2 flex items-center justify-between"><h3 class="text-body-sm font-bold text-primary-900">{{ $t('admin.calendarPreview.guestDetails') }}</h3><span class="text-tiny text-neutral-400">{{ selectedMeta.country }}</span></div>
                        <div class="rounded-xl border border-neutral-200 bg-neutral-50 p-4">
                            <div class="grid gap-2 text-body-sm">
                                <a :href="`tel:${selectedMeta.phone}`" class="flex items-center gap-2.5 text-neutral-700 no-underline hover:text-accent-700"><Phone class="h-4 w-4 text-neutral-400" />{{ selectedMeta.phone }}</a>
                                <a :href="`mailto:${selectedMeta.email}`" class="flex items-center gap-2.5 truncate text-neutral-700 no-underline hover:text-accent-700"><Mail class="h-4 w-4 shrink-0 text-neutral-400" /><span class="truncate">{{ selectedMeta.email }}</span></a>
                            </div>
                            <div class="mt-3 flex gap-2 border-t border-neutral-200 pt-3">
                                <button type="button" class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg border border-neutral-200 bg-white px-3 py-2 text-tiny font-semibold text-neutral-600 hover:bg-neutral-50"><Phone class="h-3.5 w-3.5" />{{ $t('admin.calendarPreview.call') }}</button>
                                <button type="button" class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg border border-neutral-200 bg-white px-3 py-2 text-tiny font-semibold text-neutral-600 hover:bg-neutral-50"><MessageSquare class="h-3.5 w-3.5" />{{ $t('admin.calendarPreview.message') }}</button>
                            </div>
                        </div>
                    </section>

                    <section>
                        <h3 class="mb-2 text-body-sm font-bold text-primary-900">{{ $t('admin.calendarPreview.stayDetails') }}</h3>
                        <div class="overflow-hidden rounded-xl border border-neutral-200">
                            <div class="grid grid-cols-2 divide-x divide-neutral-200 bg-neutral-50">
                                <div class="p-3"><p class="text-[10px] font-bold uppercase tracking-wide text-neutral-400">{{ $t('admin.calendarPreview.checkIn') }}</p><p class="mt-1 text-body-sm font-bold text-primary-900">{{ formatDate(selectedCheckIn) }}</p><p class="mt-0.5 flex items-center gap-1 text-tiny text-neutral-500"><Clock3 class="h-3 w-3" />{{ selectedMeta.arrivalTime }}</p></div>
                                <div class="p-3"><p class="text-[10px] font-bold uppercase tracking-wide text-neutral-400">{{ $t('admin.calendarPreview.checkOut') }}</p><p class="mt-1 text-body-sm font-bold text-primary-900">{{ formatDate(selectedCheckOut) }}</p><p class="mt-0.5 text-tiny text-neutral-500">{{ selectedReservation.nights }} {{ $t('admin.calendarPreview.nights').toLowerCase() }}</p></div>
                            </div>
                            <div class="grid grid-cols-2 gap-3 border-t border-neutral-200 p-3 text-body-sm">
                                <div><p class="text-tiny text-neutral-400">{{ $t('admin.calendarPreview.room') }}</p><p class="mt-1 font-bold text-primary-900">{{ selectedRoom?.number }} · {{ selectedRoom?.type }}</p></div>
                                <div><p class="text-tiny text-neutral-400">{{ $t('admin.calendarPreview.guests') }}</p><p class="mt-1 font-bold text-primary-900">{{ selectedReservation.adults }} {{ $t('admin.calendarPreview.adults') }}</p></div>
                                <div class="col-span-2"><p class="text-tiny text-neutral-400">{{ $t('admin.calendarPreview.mealPlan') }}</p><p class="mt-1 font-medium text-neutral-700">{{ selectedMeta.mealPlan }}</p></div>
                            </div>
                        </div>
                    </section>

                    <section>
                        <div class="mb-2 flex items-center justify-between"><h3 class="text-body-sm font-bold text-primary-900">{{ $t('admin.calendarPreview.paymentSummary') }}</h3><CreditCard class="h-4 w-4 text-neutral-400" /></div>
                        <div class="rounded-xl border border-neutral-200 p-4">
                            <div class="grid grid-cols-3 gap-3 text-center">
                                <div><p class="text-[10px] font-bold uppercase text-neutral-400">{{ $t('admin.calendarPreview.total') }}</p><p class="mt-1 text-body-sm font-bold text-primary-900">{{ formatMoney(selectedReservation.total) }}</p></div>
                                <div><p class="text-[10px] font-bold uppercase text-neutral-400">{{ $t('admin.calendarPreview.paid') }}</p><p class="mt-1 text-body-sm font-bold text-success-700">{{ formatMoney(selectedPaidAmount) }}</p></div>
                                <div><p class="text-[10px] font-bold uppercase text-neutral-400">{{ $t('admin.calendarPreview.balance') }}</p><p class="mt-1 text-body-sm font-bold" :class="selectedBalance ? 'text-warning-700' : 'text-success-700'">{{ formatMoney(selectedBalance) }}</p></div>
                            </div>
                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-neutral-100"><div class="h-full rounded-full bg-success-500 transition-all" :style="{ width: `${selectedPaymentProgress}%` }" /></div>
                            <p class="mt-2 text-right text-[10px] font-semibold text-neutral-400">{{ selectedPaymentProgress }}% {{ $t('admin.calendarPreview.paid').toLowerCase() }}</p>
                        </div>
                    </section>

                    <section class="grid grid-cols-2 gap-3 rounded-xl border border-neutral-200 bg-neutral-50 p-4 text-body-sm">
                        <div><p class="text-tiny text-neutral-400">{{ $t('admin.calendarPreview.channel') }}</p><p class="mt-1 flex items-center gap-1.5 font-bold text-primary-900"><span class="h-2 w-2 rounded-full" :style="{ backgroundColor: channelColors[selectedReservation.channel] }" />{{ selectedReservation.channel }}</p></div>
                        <div><p class="text-tiny text-neutral-400">{{ $t('admin.calendarPreview.bookedOn') }}</p><p class="mt-1 font-bold text-primary-900">{{ selectedMeta.bookedAt }}</p></div>
                    </section>

                    <section v-if="selectedMeta.note">
                        <h3 class="mb-2 text-body-sm font-bold text-primary-900">{{ $t('admin.calendarPreview.specialRequests') }}</h3>
                        <p class="rounded-xl border border-warning-200 bg-warning-50 p-3 text-body-sm leading-5 text-warning-900">{{ selectedMeta.note }}</p>
                    </section>

                    <section>
                        <h3 class="mb-3 text-body-sm font-bold text-primary-900">{{ $t('admin.calendarPreview.activity') }}</h3>
                        <div class="space-y-3 border-l-2 border-neutral-100 pl-4">
                            <div class="relative"><span class="absolute -left-[21px] top-1 h-2.5 w-2.5 rounded-full bg-info-500 ring-4 ring-white" /><p class="text-body-sm font-medium text-neutral-700">{{ $t('admin.calendarPreview.reservationCreated') }}</p><p class="text-tiny text-neutral-400">{{ selectedMeta.bookedAt }} · {{ selectedReservation.channel }}</p></div>
                            <div v-if="selectedPaidAmount" class="relative"><span class="absolute -left-[21px] top-1 h-2.5 w-2.5 rounded-full bg-success-500 ring-4 ring-white" /><p class="text-body-sm font-medium text-neutral-700">{{ $t('admin.calendarPreview.paymentRecorded') }}</p><p class="text-tiny text-neutral-400">{{ formatMoney(selectedPaidAmount) }}</p></div>
                            <div v-if="selectedReservation.status === 'checked_in'" class="relative"><span class="absolute -left-[21px] top-1 h-2.5 w-2.5 rounded-full bg-accent-500 ring-4 ring-white" /><p class="text-body-sm font-medium text-neutral-700">{{ $t('admin.calendarPreview.checkedIn') }}</p><p class="text-tiny text-neutral-400">{{ formatDate(selectedCheckIn) }}</p></div>
                        </div>
                    </section>
                </div>

                <div class="grid shrink-0 grid-cols-3 gap-2 border-t border-neutral-200 bg-neutral-50 p-4">
                    <Button variant="outline"><Pencil class="h-4 w-4" />{{ $t('admin.calendarPreview.edit') }}</Button>
                    <Button variant="outline"><ArrowLeftRight class="h-4 w-4" />{{ $t('admin.calendarPreview.move') }}</Button>
                    <Button>{{ $t('admin.calendarPreview.manage') }}</Button>
                </div>
            </aside>
        </Transition>
    </AppLayout>
</template>
