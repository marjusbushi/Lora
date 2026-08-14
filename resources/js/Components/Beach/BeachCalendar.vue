<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { getIntlLocale } from '@/i18n';
import Button from '@/Components/UI/Button.vue';
import BeachReservationCreateModal from '@/Components/Beach/BeachReservationCreateModal.vue';
import BeachReservationEditModal from '@/Components/Beach/BeachReservationEditModal.vue';

const props = defineProps({
    zones: { type: Array, default: () => [] },
    reservations: { type: Array, default: () => [] },
    startDate: { type: String, required: true },
    visibleDays: { type: Number, default: 14 },
    hotelToday: { type: String, required: true },
    season: { type: Object, default: () => ({ start: '', end: '' }) },
});

const page = usePage();
const can = (permission) => page.props.auth?.user?.permissions?.includes(permission) ?? false;
const canCreate = can('create_beach');
const canUpdate = can('update_beach');

// Data lokale Y-m-d pa toISOString (anti off-by-one UTC — mësimi i kalendarit të dhomave).
function fmt(d) {
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${m}-${day}`;
}

const days = computed(() => {
    const result = [];
    const [y, m, d] = props.startDate.split('-').map(Number);
    for (let i = 0; i < props.visibleDays; i++) {
        const date = new Date(y, m - 1, d + i);
        const iso = fmt(date);
        result.push({
            date: iso,
            day: date.getDate(),
            weekday: date.toLocaleDateString(getIntlLocale(), { weekday: 'short' }),
            month: date.toLocaleDateString(getIntlLocale(), { month: 'short' }),
            isToday: iso === props.hotelToday,
            isWeekend: date.getDay() === 0 || date.getDay() === 6,
            inSeason: isInSeason(iso),
        });
    }
    return result;
});

function isInSeason(date) {
    const { start, end } = props.season || {};
    if (!start || !end) return true;
    return date >= start && date <= end;
}

function dayOffset(date) {
    const [y1, m1, d1] = props.startDate.split('-').map(Number);
    const [y2, m2, d2] = date.split('-').map(Number);
    return Math.round((new Date(y2, m2 - 1, d2) - new Date(y1, m1 - 1, d1)) / 86400000);
}

// Statuset — i njëjti kod ngjyrash si kalendari i dhomave (pending/confirmed).
const statusColors = {
    pending: 'border-amber-300 bg-amber-100 text-amber-950 hover:bg-amber-200',
    confirmed: 'border-sky-300 bg-sky-100 text-sky-950 hover:bg-sky-200',
};

const trackWidth = computed(() => `${props.visibleDays * 76}px`);

function reservationsFor(unitId) {
    return props.reservations.filter((r) => r.beach_unit_id === unitId);
}

// Ditë INKLUZIVE: bar-i mbulon deri në fund të end_date (+1 kolonë).
function reservationStyle(reservation) {
    const start = Math.max(0, dayOffset(reservation.start_date));
    const end = Math.min(props.visibleDays, dayOffset(reservation.end_date) + 1);
    if (end <= 0 || start >= props.visibleDays) return { display: 'none' };
    return {
        left: `calc(${(start / props.visibleDays) * 100}% + 3px)`,
        width: `calc(${((end - start) / props.visibleDays) * 100}% - 6px)`,
    };
}

function isOccupied(unitId, date) {
    return props.reservations.some((r) => r.beach_unit_id === unitId
        && r.start_date <= date && r.end_date >= date);
}

// --- Navigimi ---
function reload(params) {
    router.get(route('beach.calendar'), { start: props.startDate, days: props.visibleDays, ...params }, {
        preserveScroll: true,
        preserveState: false,
    });
}

function navigate(direction) {
    const [y, m, d] = props.startDate.split('-').map(Number);
    reload({ start: fmt(new Date(y, m - 1, d + direction * props.visibleDays)) });
}

function goToToday() {
    reload({ start: props.hotelToday });
}

function setVisibleDays(count) {
    reload({ days: count });
}

// --- Drag mbi qeliza boshe → interval për një çadër (delegim eventesh) ---
const dragUnit = ref(null);
const dragStart = ref(null);
const dragEnd = ref(null);

function cellFrom(e) {
    const cell = e.target.closest('[data-date]');
    return cell && !cell.disabled ? { unitId: Number(cell.dataset.unit), date: cell.dataset.date } : null;
}

function onGridDown(e) {
    if (!canCreate) return;
    const c = cellFrom(e);
    if (!c) return;
    e.preventDefault();
    dragUnit.value = c.unitId;
    dragStart.value = c.date;
    dragEnd.value = c.date;
}

function onGridOver(e) {
    if (dragUnit.value === null) return;
    const c = cellFrom(e);
    if (c && c.unitId === dragUnit.value) dragEnd.value = c.date;
}

function endDrag() {
    if (dragUnit.value === null) return;
    const unitId = dragUnit.value;
    const a = dragStart.value;
    const b = dragEnd.value;
    dragUnit.value = null;
    dragStart.value = null;
    dragEnd.value = null;
    openCreate(unitId, a <= b ? a : b, a <= b ? b : a);
}

function isInDrag(unitId, date) {
    if (dragUnit.value === null || dragUnit.value !== unitId) return false;
    const lo = dragStart.value <= dragEnd.value ? dragStart.value : dragEnd.value;
    const hi = dragStart.value <= dragEnd.value ? dragEnd.value : dragStart.value;
    return date >= lo && date <= hi;
}

onMounted(() => window.addEventListener('mouseup', endDrag));
onBeforeUnmount(() => window.removeEventListener('mouseup', endDrag));

// --- Modalet ---
const showCreateModal = ref(false);
const createPrefill = ref(null);
const editingReservation = ref(null);

function openCreate(unitId, start, end) {
    createPrefill.value = {
        beach_unit_id: unitId,
        start_date: start ?? props.hotelToday,
        end_date: end ?? start ?? props.hotelToday,
    };
    showCreateModal.value = true;
}

function openEdit(reservation) {
    if (!canUpdate) return;
    editingReservation.value = reservation;
}

const allUnits = computed(() =>
    props.zones.flatMap((zone) => zone.units.map((unit) => ({
        value: unit.id,
        label: `${unit.number} — ${zone.name}`,
        price: Number(zone.price_per_day),
    }))),
);

// Rezervimet aktive që mbulojnë ditën e sotme dhe s'kanë pagesë të shënuar —
// recepsionisti e sheh me një shikim kush s'ka paguar ende.
const unpaidToday = computed(() =>
    props.reservations.filter((r) => r.status !== 'cancelled'
        && !r.paid_at
        && r.start_date <= props.hotelToday
        && r.end_date >= props.hotelToday).length,
);
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-h3 text-primary-900">{{ $t('beach.calendar.title') }}</h1>
            <span
                v-if="unpaidToday > 0"
                class="rounded-full bg-amber-100 px-3 py-1 text-body-sm font-semibold text-amber-900"
            >{{ $t('beach.calendar.unpaidToday', { count: unpaidToday }) }}</span>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <div class="inline-flex items-center rounded-lg border border-neutral-200 bg-white">
                    <button type="button" class="grid h-9 w-9 place-items-center text-lg text-neutral-500 hover:bg-neutral-100 rounded-l-lg" :aria-label="$t('beach.calendar.prevAria')" @click="navigate(-1)">‹</button>
                    <button type="button" class="grid h-9 w-9 place-items-center text-lg text-neutral-500 hover:bg-neutral-100 rounded-r-lg" :aria-label="$t('beach.calendar.nextAria')" @click="navigate(1)">›</button>
                </div>
                <button type="button" class="h-9 rounded-lg border border-neutral-200 bg-white px-3 text-body-sm font-medium text-neutral-600 hover:bg-neutral-50" @click="goToToday">
                    {{ $t('beach.calendar.today') }}
                </button>
                <div class="inline-flex rounded-lg bg-neutral-100 p-0.5">
                    <button
                        v-for="count in [7, 14, 30]"
                        :key="count"
                        type="button"
                        class="rounded-md px-3 py-1.5 text-tiny font-semibold transition"
                        :class="visibleDays === count ? 'bg-white text-primary-900 shadow-sm' : 'text-neutral-500'"
                        @click="setVisibleDays(count)"
                    >{{ $t('beach.calendar.daysShort', { count }) }}</button>
                </div>
                <Button v-if="canCreate" variant="primary" size="sm" @click="openCreate(null)">
                    {{ $t('beach.calendar.newReservation') }}
                </Button>
            </div>
        </div>

        <div v-if="!allUnits.length" class="rounded-xl border border-dashed border-neutral-300 bg-white py-16 text-center">
            <p class="text-h4 text-primary-900">{{ $t('beach.calendar.noUnitsTitle') }}</p>
            <p class="mt-1 text-body-sm text-neutral-500">{{ $t('beach.calendar.noUnitsHint') }}</p>
            <Link href="/pms/beach/setup" class="mt-4 inline-block">
                <Button variant="primary">{{ $t('beach.nav.setup') }}</Button>
            </Link>
        </div>

        <div v-else class="overflow-hidden rounded-xl border border-neutral-200 bg-white">
            <!-- Scroll-i horizontal qëndron BRENDA këtij kontejneri, jo në faqe -->
            <div class="overflow-x-auto overscroll-x-contain">
                <div :style="{ minWidth: `calc(8rem + ${trackWidth})` }">
                    <!-- Header i datave -->
                    <div class="flex border-b border-neutral-300">
                        <div class="sticky left-0 z-30 flex w-32 shrink-0 items-center border-r border-neutral-200 bg-white px-3 py-2 text-tiny font-bold uppercase tracking-wider text-neutral-500">
                            {{ $t('beach.calendar.sunbed') }}
                        </div>
                        <div class="grid flex-1" :style="{ minWidth: trackWidth, gridTemplateColumns: `repeat(${visibleDays}, minmax(76px, 1fr))` }">
                            <div
                                v-for="day in days"
                                :key="day.date"
                                class="border-r border-neutral-200 px-1 py-1.5 text-center"
                                :class="day.isToday ? 'bg-accent-50' : !day.inSeason ? 'bg-neutral-200/60' : day.isWeekend ? 'bg-neutral-100/80' : ''"
                            >
                                <p class="text-[10px] font-bold uppercase tracking-wide text-neutral-400">{{ day.weekday }}</p>
                                <p class="mx-auto mt-0.5 grid h-6 w-6 place-items-center rounded-full text-body-sm font-bold" :class="day.isToday ? 'bg-accent-600 text-white' : 'text-neutral-700'">{{ day.day }}</p>
                                <p class="text-[10px] text-neutral-400">{{ day.month }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Zonat + çadrat -->
                    <template v-for="zone in zones" :key="zone.id">
                        <div class="sticky left-0 z-20 flex h-8 items-center gap-2 border-b border-neutral-200 bg-primary-50 px-3 text-tiny font-bold uppercase tracking-wider text-primary-700">
                            {{ zone.name }}
                            <span class="font-medium normal-case text-primary-500">{{ $t('beach.calendar.zonePrice', { price: zone.price_per_day }) }}</span>
                        </div>
                        <div v-for="unit in zone.units" :key="unit.id" class="group flex border-b border-neutral-100 last:border-b-0">
                            <div class="sticky left-0 z-20 flex h-12 w-32 shrink-0 items-center gap-2 border-r border-neutral-200 bg-white px-3 group-hover:bg-neutral-50">
                                <span class="text-body-sm font-extrabold text-primary-900">{{ unit.number }}</span>
                                <span v-if="!unit.is_active" class="text-[10px] font-semibold uppercase text-neutral-400">{{ $t('beach.setup.inactive') }}</span>
                            </div>
                            <div
                                class="relative grid h-12 min-w-0 flex-1"
                                :style="{ minWidth: trackWidth, gridTemplateColumns: `repeat(${visibleDays}, minmax(76px, 1fr))` }"
                                @mousedown="onGridDown"
                                @mouseover="onGridOver"
                            >
                                <button
                                    v-for="day in days"
                                    :key="day.date"
                                    type="button"
                                    :data-date="day.date"
                                    :data-unit="unit.id"
                                    :disabled="isOccupied(unit.id, day.date) || !canCreate"
                                    class="border-r border-neutral-100 transition disabled:cursor-default"
                                    :class="[
                                        day.isToday ? 'bg-accent-50/40' : !day.inSeason ? 'bg-neutral-100' : day.isWeekend ? 'bg-neutral-50' : '',
                                        !isOccupied(unit.id, day.date) && canCreate ? 'hover:bg-accent-50' : '',
                                        isInDrag(unit.id, day.date) ? 'bg-accent-100' : '',
                                    ]"
                                    :aria-label="`${unit.number} ${day.date}`"
                                />
                                <button
                                    v-for="reservation in reservationsFor(unit.id)"
                                    :key="reservation.id"
                                    type="button"
                                    class="absolute top-1.5 z-10 h-9 overflow-hidden rounded-lg border px-2 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                                    :class="statusColors[reservation.status]"
                                    :style="reservationStyle(reservation)"
                                    @click="openEdit(reservation)"
                                >
                                    <span class="block truncate pr-4 text-[11px] font-extrabold leading-tight">{{ reservation.guest_name }}</span>
                                    <span class="block truncate pr-4 text-[10px] leading-tight opacity-70">{{ reservation.guest_phone }}</span>
                                    <span
                                        v-if="reservation.paid_at"
                                        class="absolute right-1 top-1 grid h-4 w-4 place-items-center rounded-full bg-emerald-500 text-[10px] font-black text-white"
                                        :title="$t('beach.calendar.legendPaid')"
                                    >€</span>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Legjenda -->
            <div class="flex flex-wrap items-center gap-4 border-t border-neutral-200 px-4 py-2 text-tiny text-neutral-500">
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded border border-sky-300 bg-sky-100" /> {{ $t('beach.calendar.statusConfirmed') }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded border border-amber-300 bg-amber-100" /> {{ $t('beach.calendar.statusPending') }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded bg-neutral-200" /> {{ $t('beach.calendar.outOfSeason') }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="grid h-3.5 w-3.5 place-items-center rounded-full bg-emerald-500 text-[9px] font-black text-white">€</span> {{ $t('beach.calendar.legendPaid') }}</span>
            </div>
        </div>

        <BeachReservationCreateModal
            :show="showCreateModal"
            :prefill="createPrefill"
            :units="allUnits"
            @close="showCreateModal = false"
        />
        <BeachReservationEditModal
            :reservation="editingReservation"
            :units="allUnits"
            @close="editingReservation = null"
        />
    </div>
</template>
