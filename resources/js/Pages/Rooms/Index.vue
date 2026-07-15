<script setup>
import { getIntlLocale, translate } from '@/i18n';
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import {
    BedDouble,
    BrushCleaning,
    CalendarDays,
    DoorOpen,
    LayoutGrid,
    List,
    LogIn,
    LogOut,
    Pencil,
    Plus,
    Search,
    Settings2,
    Trash2,
    UserRound,
    Wrench,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import Select from '@/Components/UI/Select.vue';
import Textarea from '@/Components/UI/Textarea.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';
import ActionMenu from '@/Components/UI/ActionMenu.vue';
import ReservationCreateModal from '@/Components/Reservations/ReservationCreateModal.vue';

const props = defineProps({
    // The operational endpoint returns an array. The object fallback keeps the
    // page compatible with the previous paginated payload during deployment.
    rooms: { type: [Array, Object], default: () => [] },
    roomTypes: { type: Array, default: () => [] },
    guests: { type: Array, default: () => [] },
    channelFees: { type: Object, default: () => ({}) },
    floors: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
});

const toasts = ref(null);
const showCreateModal = ref(false);
const showEditModal = ref(false);
const showDeleteModal = ref(false);
const showReservationModal = ref(false);
const reservationPrefill = ref(null);
const selectedRoom = ref(null);
const savedViewMode = typeof window !== 'undefined' ? window.localStorage.getItem('roomsViewMode') : null;
const savedGroupMode = typeof window !== 'undefined' ? window.localStorage.getItem('roomsGroupMode') : null;
const viewMode = ref(['grid', 'table'].includes(savedViewMode) ? savedViewMode : 'grid');
const managementMode = ref(false);
const searchQuery = ref(props.filters?.search || '');
const filterFloor = ref(props.filters?.floor || '');
const filterType = ref(props.filters?.room_type_id || '');
const activeKpi = ref('total');
const groupMode = ref(['floor', 'type'].includes(savedGroupMode) ? savedGroupMode : 'floor');

watch(viewMode, (value) => {
    if (typeof window !== 'undefined') window.localStorage.setItem('roomsViewMode', value);
});
watch(groupMode, (value) => {
    if (typeof window !== 'undefined') window.localStorage.setItem('roomsGroupMode', value);
});

const page = usePage();
const currencyCode = page.props.tenant?.currency || 'EUR';
const userPerms = computed(() => page.props.auth.user?.permissions || []);
const canCreate = computed(() => userPerms.value.includes('create_rooms'));
const canUpdate = computed(() => userPerms.value.includes('update_rooms'));
const canDelete = computed(() => userPerms.value.includes('delete_rooms'));
const canManage = computed(() => canCreate.value || canUpdate.value || canDelete.value);
const canViewReservations = computed(() => userPerms.value.includes('view_reservations'));
const canCreateReservation = computed(() => userPerms.value.includes('create_reservations'));
const canViewGuests = computed(() => userPerms.value.includes('view_guests'));
const canStartReservation = computed(() => (
    canViewReservations.value && canCreateReservation.value && canViewGuests.value
));
const canUpdateReservation = computed(() => userPerms.value.includes('update_reservations'));
const canViewHousekeeping = computed(() => (
    userPerms.value.includes('view_housekeeping') && page.props.modules?.housekeeping === true
));

const menuItemClass = 'flex w-full items-center gap-2.5 px-3 py-2 text-left text-body-sm text-neutral-700 transition-colors hover:bg-neutral-50';

const roomList = computed(() => (
    Array.isArray(props.rooms) ? props.rooms : (props.rooms?.data || [])
));

const roomTypeOptions = computed(() => props.roomTypes.map((type) => ({
    value: type.id,
    label: `${type.name} (${new Intl.NumberFormat(getIntlLocale(), { style: 'currency', currency: currencyCode }).format(Number(type.base_price || 0))})`,
})));
const roomTypeFilterOptions = computed(() => [
    { value: '', label: translate('admin.generated.k_04822094270a') },
    ...props.roomTypes.map((type) => ({ value: type.id, label: type.name })),
]);

const fallbackFloorNumbers = computed(() => {
    const numbers = roomList.value.map((room) => room.floor).filter((floor) => floor !== null && floor !== undefined);
    return [...new Set(numbers)].sort((a, b) => Number(a) - Number(b));
});
const roomFloorOptions = computed(() => (
    props.floors.length
        ? props.floors.map((floor) => ({ value: floor.number, label: floor.name }))
        : (fallbackFloorNumbers.value.length ? fallbackFloorNumbers.value : [1, 2, 3, 4, 5])
            .map((floor) => ({ value: floor, label: `Kati ${floor}` }))
));
const floorFilterOptions = computed(() => [
    { value: '', label: translate('admin.generated.k_04822094270a') },
    ...roomFloorOptions.value,
]);
const floorNameMap = computed(() => Object.fromEntries(roomFloorOptions.value.map((floor) => [String(floor.value), floor.label])));
const floorName = (number) => floorNameMap.value[String(number)] || `Kati ${number}`;

const statusOptions = [
    { value: 'available', label: translate('admin.generated.k_d78c3bb41e73') },
    { value: 'occupied', label: translate('admin.generated.k_9c02368c4f32') },
    { value: 'cleaning', label: translate('admin.generated.k_59e19b31e720') },
    { value: 'maintenance', label: translate('admin.generated.k_7d16819adc7b') },
];

const occupancyBadge = {
    vacant: { variant: 'neutral', label: translate('admin.generated.k_5017092ee971') },
    occupied: { variant: 'info', label: translate('admin.generated.k_9c02368c4f32') },
    maintenance: { variant: 'neutral', label: translate('admin.generated.k_7d16819adc7b') },
};
const housekeepingBadge = {
    clean: { variant: 'success', label: translate('admin.generated.k_dbf552fb4050') },
    dirty: { variant: 'warning', label: translate('admin.generated.k_59e19b31e720') },
    cleaning: { variant: 'warning', label: translate('admin.generated.k_27b9fbdd1d2d') },
    maintenance: { variant: 'neutral', label: translate('admin.generated.k_7d16819adc7b') },
};

const createForm = useForm({ room_type_id: '', room_number: '', floor: '', status: 'available', notes: '' });
const editForm = useForm({ room_type_id: '', room_number: '', floor: '', status: '', notes: '' });

function asRecord(value) {
    return value && typeof value === 'object' && !Array.isArray(value) ? value : null;
}

function normalizeKey(value) {
    return String(value || '').trim().toLowerCase().replace(/[\s-]+/g, '_');
}

function guestName(guest) {
    if (!guest) return '';
    if (typeof guest === 'string') return guest;
    return guest.full_name
        || guest.name
        || [guest.first_name, guest.last_name].filter(Boolean).join(' ')
        || '';
}

function formatDate(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short' });
}

function formatTime(value) {
    if (!value) return '';
    const match = String(value).match(/(?:T|\s)?(\d{2}:\d{2})/);
    return match?.[1] || '';
}

function money(value) {
    const amount = Number(value || 0);
    return new Intl.NumberFormat(getIntlLocale(), { style: 'currency', currency: currencyCode }).format(Number.isFinite(amount) ? amount : 0);
}

function reservationIdOf(...candidates) {
    for (const candidate of candidates) {
        const record = asRecord(candidate);
        const id = record?.reservation_id ?? record?.id;
        if (id !== undefined && id !== null) return id;
    }
    return null;
}

function roomPresentation(room) {
    const operational = asRecord(room.operational) || {};
    const active = asRecord(operational.active_stay);
    const arrival = asRecord(operational.arrival_today);
    const departure = asRecord(operational.departure_today);
    const next = asRecord(operational.next_reservation);
    const hasActive = Boolean(operational.active_stay);
    const hasArrival = Boolean(operational.arrival_today);
    const hasDeparture = Boolean(operational.departure_today);
    const roomType = room.room_type || room.roomType || {};
    const fallbackStatus = normalizeKey(room.status);
    const rawOccupancy = normalizeKey(operational.occupancy_status || operational.occupancy);
    const rawHousekeeping = normalizeKey(
        operational.housekeeping_status
        || asRecord(operational.housekeeping)?.status
        || room.housekeeping_status,
    );
    const rawService = normalizeKey(operational.service_status || operational.room_status);
    const isMaintenance = operational.out_of_order === true
        || ['maintenance', 'out_of_order', 'ooo'].includes(rawService)
        || (!rawService && !rawHousekeeping && fallbackStatus === 'maintenance');

    let occupancyKey = 'vacant';
    if (isMaintenance) occupancyKey = 'maintenance';
    else if (hasActive || ['occupied', 'checked_in', 'in_house'].includes(rawOccupancy)) occupancyKey = 'occupied';
    else if (!rawOccupancy && fallbackStatus === 'occupied') occupancyKey = 'occupied';

    let housekeepingKey = 'clean';
    if (isMaintenance || ['maintenance', 'out_of_order', 'ooo'].includes(rawHousekeeping)) housekeepingKey = 'maintenance';
    else if (['cleaning', 'in_progress'].includes(rawHousekeeping)) housekeepingKey = 'cleaning';
    else if (['dirty', 'pending', 'needs_cleaning'].includes(rawHousekeeping)) housekeepingKey = 'dirty';
    else if (!rawHousekeeping && fallbackStatus === 'cleaning') housekeepingKey = 'dirty';

    const focusReservation = active || departure || arrival || next;
    const guest = operational.guest
        || active?.guest
        || departure?.guest
        || arrival?.guest
        || next?.guest
        || focusReservation?.guest;
    const outstandingRaw = operational.outstanding
        ?? active?.outstanding
        ?? departure?.outstanding
        ?? focusReservation?.outstanding
        ?? focusReservation?.balance_due
        ?? 0;
    const outstanding = Number(outstandingRaw || 0);
    const reservationId = operational.reservation_id
        ?? reservationIdOf(active, departure, arrival, focusReservation);

    let activity = translate('admin.generated.k_4b37e53d8c4f');
    if (hasDeparture) {
        const time = formatTime(departure?.etd || departure?.check_out_time || departure?.check_out_at || operational.departure_time);
        activity = `Largohet sot${time ? ` · ${time}` : ''}`;
    } else if (hasArrival && !hasActive) {
        const time = formatTime(arrival?.eta || arrival?.check_in_time || arrival?.check_in_at || operational.arrival_time);
        activity = translate('admin.generated.k_ded4d66434fa', { p0: time ? ` · ${time}` : '' });
    } else if (hasActive) {
        const until = formatDate(active?.check_out_date || active?.check_out || operational.check_out_date);
        activity = until ? translate('admin.generated.k_baf15ed7b023', { p0: until }) : translate('admin.generated.k_7d2417958091');
    } else if (next) {
        const arrivalDate = formatDate(next.check_in_date || next.check_in || next.arrival_date);
        activity = arrivalDate ? translate('admin.generated.k_8f326d563ab2', { p0: arrivalDate }) : translate('admin.generated.k_970081e1ffc6');
    } else if (housekeepingKey === 'dirty') {
        activity = translate('admin.generated.k_4fe7d59ff1ef');
    }

    const arrivalStatus = normalizeKey(arrival?.status || operational.arrival_status);
    const rawAction = operational.primary_action;

    return {
        raw: room,
        operational,
        active,
        arrival,
        departure,
        next,
        hasActive,
        hasArrival,
        hasDeparture,
        confirmedArrival: hasArrival && (
            arrivalStatus === 'confirmed'
            || operational.arrival_confirmed === true
            || asRecord(rawAction)?.confirmed === true
        ),
        reservationId,
        roomNumber: room.room_number,
        floor: room.floor,
        typeId: room.room_type_id ?? roomType.id,
        typeName: roomType.name || translate('admin.generated.k_b8b560c77947'),
        occupancyKey,
        housekeepingKey,
        guestName: guestName(guest),
        activity,
        outstanding: Number.isFinite(outstanding) ? outstanding : 0,
        rawAction,
    };
}

const roomViews = computed(() => roomList.value.map(roomPresentation));

function explicitAction(view) {
    const raw = view.rawAction;
    if (!raw) return null;
    const record = asRecord(raw);
    const rawKind = record?.key || record?.action || record?.type || record?.name || (typeof raw === 'string' ? raw : '');
    const kind = normalizeKey(rawKind);
    const labelByKind = {
        check_in: 'Check-in',
        check_out: 'Check-out',
        open_stay: translate('admin.generated.k_75b2bf194029'),
        view_stay: translate('admin.generated.k_75b2bf194029'),
        view_reservation: translate('admin.generated.k_9e1a22ee63f6'),
        reserve: '+ Rezervim',
        new_reservation: '+ Rezervim',
        cleaning: translate('admin.generated.k_c8dcd23833bf'),
        housekeeping: translate('admin.generated.k_c8dcd23833bf'),
        maintenance: translate('admin.generated.k_d21931675096'),
    };
    const label = record?.label || labelByKind[kind] || (typeof raw === 'string' ? raw : 'Hap');
    const href = record?.href || record?.url || null;
    return { kind, label, href };
}

function primaryAction(view) {
    const explicit = explicitAction(view);
    const reservationId = view.reservationId;

    // Checkout always opens the reservation/folio first; it is never a one-click
    // state change because an outstanding balance may need settlement details.
    if ((explicit?.kind === 'check_out' || (view.hasActive && view.hasDeparture)) && reservationId && canViewReservations.value) {
        return { kind: 'check_out', label: explicit?.label || 'Check-out', href: route('reservations.show', reservationId), mode: 'visit' };
    }

    // The only direct state-changing action on the rack is a confirmed arrival.
    if (explicit?.kind === 'check_in' && view.confirmedArrival && reservationId && canUpdateReservation.value) {
        return { kind: 'check_in', label: explicit.label || 'Check-in', href: route('reservations.check-in', reservationId), mode: 'check_in' };
    }

    if (explicit?.kind === 'check_in' && reservationId && canViewReservations.value) {
        return { kind: 'view_reservation', label: translate('admin.generated.k_d44c83daccb8'), href: route('reservations.show', reservationId), mode: 'visit' };
    }

    if (['open_stay', 'view_stay', 'view_reservation'].includes(explicit?.kind) && reservationId && canViewReservations.value) {
        return { kind: explicit.kind, label: explicit.label, href: route('reservations.show', reservationId), mode: 'visit' };
    }

    if (['cleaning', 'housekeeping'].includes(explicit?.kind) && canViewHousekeeping.value) {
        return { kind: 'cleaning', label: explicit.label, href: explicit.href || route('housekeeping.index'), mode: 'visit' };
    }

    if (explicit?.kind === 'maintenance' && canViewHousekeeping.value) {
        return { kind: 'maintenance', label: explicit.label, href: explicit.href || route('housekeeping.index'), mode: 'visit' };
    }

    if (['reserve', 'new_reservation'].includes(explicit?.kind) && canStartReservation.value) {
        return { kind: 'reserve', label: explicit.label || '+ Rezervim', href: route('reservations.calendar'), mode: 'create' };
    }

    if (view.hasArrival && reservationId && canViewReservations.value) {
        if (view.confirmedArrival && canUpdateReservation.value) {
            return { kind: 'check_in', label: translate('admin.generated.k_2b6fe82c7d05'), href: route('reservations.check-in', reservationId), mode: 'check_in' };
        }
        return { kind: 'view_reservation', label: translate('admin.generated.k_d44c83daccb8'), href: route('reservations.show', reservationId), mode: 'visit' };
    }

    if (view.hasActive && reservationId && canViewReservations.value) {
        return { kind: 'open_stay', label: translate('admin.generated.k_318e75f6a052'), href: route('reservations.show', reservationId), mode: 'visit' };
    }

    if (view.housekeepingKey === 'dirty' || view.housekeepingKey === 'cleaning') {
        return canViewHousekeeping.value
            ? { kind: 'cleaning', label: view.housekeepingKey === 'dirty' ? translate('admin.generated.k_89dc6781625a') : translate('admin.generated.k_c8dcd23833bf'), href: route('housekeeping.index'), mode: 'visit' }
            : null;
    }

    if (view.housekeepingKey === 'maintenance' || view.occupancyKey === 'maintenance') {
        return canViewHousekeeping.value
            ? { kind: 'maintenance', label: translate('admin.generated.k_8cf645ba5a22'), href: route('housekeeping.index'), mode: 'visit' }
            : null;
    }

    if (view.next && reservationId && canViewReservations.value) {
        return { kind: 'view_reservation', label: translate('admin.generated.k_d44c83daccb8'), href: route('reservations.show', reservationId), mode: 'visit' };
    }

    return canStartReservation.value
        ? { kind: 'reserve', label: translate('admin.generated.k_c2e4bbe7c33f'), href: route('reservations.calendar'), mode: 'visit' }
        : null;
}

function primaryActionClass(action) {
    if (!action) return '';
    if (['check_out', 'open_stay', 'view_stay'].includes(action.kind)) {
        return 'border-info-500 text-info-700 hover:bg-info-50 focus:ring-info-500/30';
    }
    if (action.kind === 'cleaning') {
        return 'border-warning-500 text-warning-700 hover:bg-warning-50 focus:ring-warning-500/30';
    }
    if (action.kind === 'maintenance') {
        return 'border-neutral-400 text-neutral-700 hover:bg-neutral-50 focus:ring-neutral-400/30';
    }
    return 'border-success-600 text-success-700 hover:bg-success-50 focus:ring-success-500/30';
}

function handlePrimary(view) {
    const action = primaryAction(view);
    if (!action) return;

    if (action.mode === 'create' || action.kind === 'reserve') {
        openReservationCreate(view.raw);
        return;
    }

    if (action.mode === 'check_in') {
        router.post(action.href, {}, {
            preserveScroll: true,
            onSuccess: (page) => {
                const error = page.props.flash?.error;
                if (error) {
                    toasts.value?.error(error);
                    return;
                }
                toasts.value?.success(`Check-in: ${view.guestName || `Dhoma ${view.roomNumber}`}`);
            },
            onError: (errors) => toasts.value?.error(errors.status || errors.room_id || translate('admin.generated.k_bf0515040767')),
        });
        return;
    }

    router.visit(action.href);
}

function localDate(daysFromToday = 0) {
    const date = new Date();
    date.setDate(date.getDate() + daysFromToday);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function openReservationCreate(room = null) {
    if (!canStartReservation.value) return;

    reservationPrefill.value = room
        ? { room_id: room.id, check_in_date: localDate(), check_out_date: localDate(1) }
        : null;
    showReservationModal.value = true;
}

function matchesKpi(view, key) {
    if (key === 'arrivals_today') return view.hasArrival;
    if (key === 'departures_today') return view.hasDeparture;
    if (key === 'occupied') return view.occupancyKey === 'occupied';
    if (key === 'cleaning') return ['dirty', 'cleaning'].includes(view.housekeepingKey);
    if (key === 'maintenance') return view.housekeepingKey === 'maintenance' || view.occupancyKey === 'maintenance';
    return true;
}

const filteredRooms = computed(() => {
    const query = searchQuery.value.trim().toLocaleLowerCase(getIntlLocale());
    const rooms = roomViews.value.filter((view) => {
        if (filterFloor.value !== '' && String(view.floor) !== String(filterFloor.value)) return false;
        if (filterType.value !== '' && String(view.typeId) !== String(filterType.value)) return false;
        if (!matchesKpi(view, activeKpi.value)) return false;
        if (!query) return true;
        return [view.roomNumber, view.typeName, view.guestName, view.activity]
            .filter(Boolean)
            .some((value) => String(value).toLocaleLowerCase(getIntlLocale()).includes(query));
    });

    return rooms.sort((a, b) => {
        if (groupMode.value === 'type') {
            const typeCompare = a.typeName.localeCompare(b.typeName, getIntlLocale(), { numeric: true });
            if (typeCompare !== 0) return typeCompare;
        } else {
            const floorCompare = Number(a.floor || 0) - Number(b.floor || 0);
            if (floorCompare !== 0) return floorCompare;
        }
        return String(a.roomNumber).localeCompare(String(b.roomNumber), getIntlLocale(), { numeric: true });
    });
});

const roomGroups = computed(() => {
    const groups = new Map();
    for (const view of filteredRooms.value) {
        const key = groupMode.value === 'type' ? `type:${view.typeId || view.typeName}` : `floor:${view.floor}`;
        const label = groupMode.value === 'type' ? view.typeName : floorName(view.floor);
        if (!groups.has(key)) groups.set(key, { key, label, rooms: [] });
        groups.get(key).rooms.push(view);
    }
    return [...groups.values()];
});

function statValue(key, fallback) {
    return props.stats?.[key] ?? fallback;
}

const kpiItems = computed(() => {
    const items = [
        { key: 'total', label: translate('admin.generated.k_98ea21ad1a70'), value: statValue('total', roomViews.value.length), icon: BedDouble },
    ];

    if (canViewReservations.value) {
        items.push(
            { key: 'arrivals_today', label: translate('admin.generated.k_1288e8726078'), value: statValue('arrivals_today', roomViews.value.filter((room) => room.hasArrival).length), icon: LogIn },
            { key: 'departures_today', label: translate('admin.generated.k_bdbf1845d6d8'), value: statValue('departures_today', roomViews.value.filter((room) => room.hasDeparture).length), icon: LogOut },
        );
    }

    items.push(
        { key: 'occupied', label: translate('admin.generated.k_ce30ecf6098c'), value: statValue('occupied', roomViews.value.filter((room) => room.occupancyKey === 'occupied').length), icon: DoorOpen },
        { key: 'cleaning', label: translate('admin.generated.k_59e19b31e720'), value: statValue('cleaning', roomViews.value.filter((room) => ['dirty', 'cleaning'].includes(room.housekeepingKey)).length), icon: BrushCleaning },
        { key: 'maintenance', label: translate('admin.generated.k_7d16819adc7b'), value: statValue('maintenance', roomViews.value.filter((room) => room.housekeepingKey === 'maintenance').length), icon: Wrench },
    );

    return items;
});

const filtersActive = computed(() => (
    Boolean(searchQuery.value.trim())
    || filterFloor.value !== ''
    || filterType.value !== ''
    || activeKpi.value !== 'total'
));

function selectKpi(key) {
    activeKpi.value = key === 'total' || activeKpi.value === key ? 'total' : key;
}

function clearFilters() {
    searchQuery.value = '';
    filterFloor.value = '';
    filterType.value = '';
    activeKpi.value = 'total';
}

function openEdit(room) {
    selectedRoom.value = room;
    editForm.room_type_id = room.room_type_id;
    editForm.room_number = room.room_number;
    editForm.floor = room.floor;
    editForm.status = room.status || 'available';
    editForm.notes = room.notes || '';
    showEditModal.value = true;
}

function openDelete(room) {
    selectedRoom.value = room;
    showDeleteModal.value = true;
}

function submitCreate() {
    createForm.post(route('rooms.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showCreateModal.value = false;
            createForm.reset();
            toasts.value?.success(translate('admin.generated.k_4f3a489d1765'));
        },
    });
}

function submitEdit() {
    if (!selectedRoom.value) return;
    editForm.put(route('rooms.update', selectedRoom.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            showEditModal.value = false;
            toasts.value?.success(translate('admin.generated.k_8088e5dc07c2'));
        },
    });
}

function submitDelete() {
    if (!selectedRoom.value) return;
    router.delete(route('rooms.destroy', selectedRoom.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            showDeleteModal.value = false;
            toasts.value?.success(translate('admin.generated.k_bd3ae352ccda'));
        },
    });
}
</script>

<template>
    <AppLayout>
        <PageHeader
            :title="$t('admin.generated.k_e3202d16fc49')"
            :breadcrumbs="[{ label: $t('admin.generated.k_114fb4c3cb3d'), href: '/dashboard' }, { label: $t('admin.generated.k_7027332c15ca') }]"
        >
            <template #actions>
                <div class="inline-flex rounded-lg border border-neutral-200 bg-white p-0.5">
                    <button
                        type="button"
                        :class="['inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-body-sm font-medium transition-colors', viewMode === 'grid' ? 'bg-accent-50 text-accent-700 shadow-sm' : 'text-neutral-500 hover:text-neutral-800']"
                        @click="viewMode = 'grid'"
                    >
                        <LayoutGrid class="h-4 w-4" :stroke-width="1.8" />
{{ $t('admin.generated.k_8b10fd9c7e90') }} </button>
                    <button
                        type="button"
                        :class="['inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-body-sm font-medium transition-colors', viewMode === 'table' ? 'bg-accent-50 text-accent-700 shadow-sm' : 'text-neutral-500 hover:text-neutral-800']"
                        @click="viewMode = 'table'"
                    >
                        <List class="h-4 w-4" :stroke-width="1.8" />
{{ $t('admin.generated.k_3020a17461c0') }} </button>
                </div>
                <Button
                    v-if="canManage"
                    :variant="managementMode ? 'secondary' : 'outline'"
                    :aria-pressed="managementMode"
                    @click="managementMode = !managementMode"
                >
                    <template #icon-left><Settings2 class="h-4 w-4" :stroke-width="1.8" /></template>
{{ $t('admin.generated.k_4bfc88bd1197') }} </Button>
                <Button v-if="canStartReservation" variant="primary" @click="openReservationCreate()">
                    <template #icon-left><Plus class="h-4 w-4" :stroke-width="2" /></template>
{{ $t('admin.generated.k_401abd8b8299') }} </Button>
            </template>
        </PageHeader>

        <!-- Search + operational KPIs -->
        <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-8">
            <div class="relative col-span-2 sm:col-span-3 lg:col-span-2">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" :stroke-width="1.8" />
                <input
                    v-model="searchQuery"
                    type="search"
                    class="h-full min-h-16 w-full rounded-lg border border-neutral-200 bg-white py-3 pl-10 pr-3 text-body-sm text-neutral-900 shadow-card placeholder:text-neutral-400 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30"
                    :placeholder="$t('admin.generated.k_9b4cbcc8ac3b')"
                    :aria-label="$t('admin.generated.k_add820b185f7')"
                />
            </div>

            <button
                v-for="item in kpiItems"
                :key="item.key"
                type="button"
                :aria-pressed="activeKpi === item.key"
                :class="[
                    'flex min-h-16 items-center gap-3 rounded-lg border bg-white px-3 py-2 text-left shadow-card transition-colors focus:outline-none focus:ring-2 focus:ring-accent-500/30',
                    activeKpi === item.key ? 'border-accent-500 bg-accent-50/40' : 'border-neutral-200 hover:border-neutral-300 hover:bg-neutral-50',
                ]"
                @click="selectKpi(item.key)"
            >
                <component :is="item.icon" class="h-5 w-5 shrink-0 text-neutral-600" :stroke-width="1.6" />
                <span class="min-w-0">
                    <span class="block truncate text-tiny text-neutral-500">{{ item.label }}</span>
                    <span class="mt-0.5 block text-h4 leading-none text-primary-900">{{ item.value }}</span>
                </span>
            </button>
        </div>

        <!-- Filters + grouping -->
        <div class="mt-3 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-col gap-3 rounded-lg border border-neutral-200 bg-white p-2.5 shadow-card sm:flex-row sm:items-center">
                <label class="flex items-center gap-2 text-small text-neutral-600">
                    <span class="w-20 shrink-0 sm:w-auto">{{ $t('admin.generated.k_3deb89580324') }}</span>
                    <span class="min-w-44 flex-1">
                        <Select v-model="filterFloor" :options="floorFilterOptions" placeholder="" />
                    </span>
                </label>
                <label class="flex items-center gap-2 text-small text-neutral-600">
                    <span class="w-20 shrink-0 sm:w-auto">{{ $t('admin.generated.k_693a32b00041') }}</span>
                    <span class="min-w-52 flex-1">
                        <Select v-model="filterType" :options="roomTypeFilterOptions" placeholder="" />
                    </span>
                </label>
                <Button v-if="filtersActive" variant="ghost" size="sm" @click="clearFilters">{{ $t('admin.generated.k_9bce05bb7db9') }}</Button>
            </div>

            <div class="inline-flex self-start rounded-lg border border-neutral-200 bg-white p-0.5 shadow-card lg:self-auto">
                <button
                    type="button"
                    :class="['rounded-md px-4 py-2 text-body-sm font-medium transition-colors', groupMode === 'floor' ? 'bg-accent-50 text-accent-700' : 'text-neutral-500 hover:text-neutral-800']"
                    @click="groupMode = 'floor'"
                >{{ $t('admin.generated.k_47afeab77851') }}</button>
                <button
                    type="button"
                    :class="['rounded-md px-4 py-2 text-body-sm font-medium transition-colors', groupMode === 'type' ? 'bg-accent-50 text-accent-700' : 'text-neutral-500 hover:text-neutral-800']"
                    @click="groupMode = 'type'"
                >{{ $t('admin.generated.k_07632d9b2a29') }}</button>
            </div>
        </div>

        <div v-if="managementMode" class="mt-3 flex flex-col gap-3 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-body-sm font-medium text-neutral-800">{{ $t('admin.generated.k_91a2347955a8') }}</p>
                <p class="text-small text-neutral-500">{{ $t('admin.generated.k_3ece0701b555') }}</p>
            </div>
            <Button v-if="canCreate" variant="outline" size="sm" @click="showCreateModal = true">
                <template #icon-left><Plus class="h-4 w-4" /></template>
{{ $t('admin.generated.k_e6c45e5c931f') }} </Button>
        </div>

        <!-- Operational room rack -->
        <div v-if="viewMode === 'grid' && filteredRooms.length" class="mt-4 space-y-5">
            <section v-for="group in roomGroups" :key="group.key">
                <div class="mb-2.5 flex items-center gap-3 px-1">
                    <h2 class="text-body-sm font-semibold text-neutral-800">{{ group.label }}</h2>
                    <span class="h-px flex-1 bg-neutral-200" />
                    <span class="text-tiny text-neutral-400">{{ group.rooms.length }} {{ $t('admin.generated.k_0649d391e06d') }}</span>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                    <article
                        v-for="room in group.rooms"
                        :key="room.raw.id"
                        class="flex min-h-48 flex-col rounded-lg border border-neutral-200 bg-white p-3 shadow-card transition-shadow hover:shadow-md"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-h3 leading-none text-primary-900">{{ room.roomNumber }}</p>
                                <p class="mt-1 truncate text-small text-neutral-500">{{ room.typeName }}</p>
                            </div>
                            <ActionMenu v-if="managementMode && (canUpdate || canDelete)">
                                <button v-if="canUpdate" type="button" :class="menuItemClass" @click="openEdit(room.raw)">
                                    <Pencil class="h-4 w-4 text-neutral-400" :stroke-width="1.8" />
{{ $t('admin.generated.k_b2c61837d6bc') }} </button>
                                <button v-if="canDelete" type="button" :class="[menuItemClass, 'text-error-600']" @click="openDelete(room.raw)">
                                    <Trash2 class="h-4 w-4 text-error-500" :stroke-width="1.8" />
{{ $t('admin.generated.k_481988180f04') }} </button>
                            </ActionMenu>
                        </div>

                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <Badge :variant="occupancyBadge[room.occupancyKey]?.variant || 'neutral'">
                                {{ occupancyBadge[room.occupancyKey]?.label || $t('admin.generated.k_5d660e4e013e') }}
                            </Badge>
                            <Badge :variant="housekeepingBadge[room.housekeepingKey]?.variant || 'neutral'">
                                {{ housekeepingBadge[room.housekeepingKey]?.label || '—' }}
                            </Badge>
                        </div>

                        <div class="mt-2.5 flex-1 space-y-1.5">
                            <p v-if="room.guestName" class="flex items-center gap-2 text-small font-medium text-neutral-800">
                                <UserRound class="h-4 w-4 shrink-0 text-neutral-500" :stroke-width="1.7" />
                                <span class="truncate">{{ room.guestName }}</span>
                            </p>
                            <p class="flex items-center gap-2 text-small text-neutral-500">
                                <CalendarDays class="h-4 w-4 shrink-0" :stroke-width="1.7" />
                                <span class="truncate">{{ room.activity }}</span>
                            </p>
                            <p v-if="room.outstanding > 0" class="text-small font-medium text-error-600">
{{ $t('admin.generated.k_d28e31b7e598') }} {{ money(room.outstanding) }}
                            </p>
                        </div>

                        <button
                            v-if="primaryAction(room)"
                            type="button"
                            :class="[
                                'mt-3 inline-flex min-h-9 w-full items-center justify-center rounded-md border px-3 py-1.5 text-body-sm font-medium transition-colors focus:outline-none focus:ring-2',
                                primaryActionClass(primaryAction(room)),
                            ]"
                            @click="handlePrimary(room)"
                        >
                            {{ primaryAction(room).label }}
                        </button>
                    </article>
                </div>
            </section>
        </div>

        <!-- Dense operational table -->
        <div v-if="viewMode === 'table' && filteredRooms.length" class="mt-4">
            <Card :padding="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200">
                        <thead class="bg-neutral-50">
                            <tr>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_c7580722b1ba') }}</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_7d7c760fa449') }}</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_b175f695b1d5') }}</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_b5b9ae690410') }}</th>
                                <th class="px-5 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_d28e31b7e598') }}</th>
                                <th class="px-5 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_81b12621b35b') }}</th>
                                <th v-if="managementMode && (canUpdate || canDelete)" class="w-12 px-3 py-3"><span class="sr-only">{{ $t('admin.generated.k_692e14bb5a22') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="room in filteredRooms" :key="room.raw.id" class="transition-colors hover:bg-neutral-50">
                                <td class="whitespace-nowrap px-5 py-3">
                                    <p class="text-body-sm font-semibold text-primary-900">{{ room.roomNumber }}</p>
                                    <p class="text-small text-neutral-500">{{ room.typeName }} · {{ floorName(room.floor) }}</p>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3">
                                    <div class="flex flex-wrap gap-1.5">
                                        <Badge :variant="occupancyBadge[room.occupancyKey]?.variant || 'neutral'">{{ occupancyBadge[room.occupancyKey]?.label || $t('admin.generated.k_5d660e4e013e') }}</Badge>
                                        <Badge :variant="housekeepingBadge[room.housekeepingKey]?.variant || 'neutral'">{{ housekeepingBadge[room.housekeepingKey]?.label || '—' }}</Badge>
                                    </div>
                                </td>
                                <td class="px-5 py-3 text-body-sm text-neutral-700">{{ room.guestName || '—' }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-small text-neutral-500">{{ room.activity }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-right text-body-sm" :class="room.outstanding > 0 ? 'font-medium text-error-600' : 'text-neutral-400'">
                                    {{ room.outstanding > 0 ? money(room.outstanding) : '—' }}
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <button
                                        v-if="primaryAction(room)"
                                        type="button"
                                        :class="['inline-flex min-h-8 items-center justify-center rounded-md border px-3 py-1 text-body-sm font-medium transition-colors focus:outline-none focus:ring-2', primaryActionClass(primaryAction(room))]"
                                        @click="handlePrimary(room)"
                                    >{{ primaryAction(room).label }}</button>
                                    <span v-else class="text-neutral-400">—</span>
                                </td>
                                <td v-if="managementMode && (canUpdate || canDelete)" class="px-3 py-3 text-right">
                                    <ActionMenu>
                                        <button v-if="canUpdate" type="button" :class="menuItemClass" @click="openEdit(room.raw)">
                                            <Pencil class="h-4 w-4 text-neutral-400" :stroke-width="1.8" /> {{ $t('admin.generated.k_b2c61837d6bc') }} </button>
                                        <button v-if="canDelete" type="button" :class="[menuItemClass, 'text-error-600']" @click="openDelete(room.raw)">
                                            <Trash2 class="h-4 w-4 text-error-500" :stroke-width="1.8" /> {{ $t('admin.generated.k_481988180f04') }} </button>
                                    </ActionMenu>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </div>

        <!-- Empty state -->
        <div v-if="!filteredRooms.length" class="mt-4">
            <Card>
                <div class="text-center py-12">
                    <BedDouble class="mx-auto h-8 w-8 text-neutral-300" :stroke-width="1.5" />
                    <p class="mt-3 text-body-sm text-neutral-600">{{ $t('admin.generated.k_11da110b0524') }}</p>
                    <Button v-if="filtersActive" variant="outline" size="sm" class="mt-3" @click="clearFilters">{{ $t('admin.generated.k_67af202f0546') }}</Button>
                    <Button v-else-if="canCreate" variant="outline" size="sm" class="mt-3" @click="showCreateModal = true">{{ $t('admin.generated.k_a6fa95c13f98') }}</Button>
                </div>
            </Card>
        </div>

        <!-- Create Modal -->
        <Modal :show="showCreateModal" :title="$t('admin.generated.k_14f1a04068bf')" @close="showCreateModal = false">
            <form @submit.prevent="submitCreate" class="space-y-4">
                <FormGroup :label="$t('admin.generated.k_8b4223ed45ea')" :error="createForm.errors.room_number" required>
                    <TextInput v-model="createForm.room_number" :placeholder="$t('admin.generated.k_ed5259641bd1')" :error="createForm.errors.room_number" />
                </FormGroup>
                <FormGroup :label="$t('admin.generated.k_8ed04a040de1')" :error="createForm.errors.room_type_id" required>
                    <Select v-model="createForm.room_type_id" :options="roomTypeOptions" :error="createForm.errors.room_type_id" />
                </FormGroup>
                <FormGroup :label="$t('admin.generated.k_d7ec945ac9f2')" :error="createForm.errors.floor" required>
                    <Select v-model="createForm.floor" :options="roomFloorOptions" :error="createForm.errors.floor" />
                </FormGroup>
                <FormGroup :label="$t('admin.generated.k_5ec0c62ab9f7')">
                    <Textarea v-model="createForm.notes" :placeholder="$t('admin.generated.k_705ac3377d9d')" :rows="2" />
                </FormGroup>
            </form>
            <template #footer>
                <Button variant="outline" @click="showCreateModal = false">{{ $t('admin.generated.k_a134eeb9c5ba') }}</Button>
                <Button variant="primary" :loading="createForm.processing" @click="submitCreate">{{ $t('admin.generated.k_55b86a6b4f98') }}</Button>
            </template>
        </Modal>

        <!-- Edit Modal -->
        <Modal :show="showEditModal" :title="$t('admin.generated.k_6376c72607bb')" @close="showEditModal = false">
            <form @submit.prevent="submitEdit" class="space-y-4">
                <FormGroup :label="$t('admin.generated.k_8b4223ed45ea')" :error="editForm.errors.room_number" required>
                    <TextInput v-model="editForm.room_number" :error="editForm.errors.room_number" />
                </FormGroup>
                <FormGroup :label="$t('admin.generated.k_8ed04a040de1')" :error="editForm.errors.room_type_id" required>
                    <Select v-model="editForm.room_type_id" :options="roomTypeOptions" :error="editForm.errors.room_type_id" />
                </FormGroup>
                <div class="grid grid-cols-2 gap-4">
                    <FormGroup :label="$t('admin.generated.k_d7ec945ac9f2')" :error="editForm.errors.floor" required>
                        <Select v-model="editForm.floor" :options="roomFloorOptions" :error="editForm.errors.floor" />
                    </FormGroup>
                    <FormGroup :label="$t('admin.generated.k_fa1c72dde0ef')" :error="editForm.errors.status" required>
                        <Select v-model="editForm.status" :options="statusOptions" :error="editForm.errors.status" />
                    </FormGroup>
                </div>
                <FormGroup :label="$t('admin.generated.k_5ec0c62ab9f7')">
                    <Textarea v-model="editForm.notes" :rows="2" />
                </FormGroup>
            </form>
            <template #footer>
                <Button variant="outline" @click="showEditModal = false">{{ $t('admin.generated.k_a134eeb9c5ba') }}</Button>
                <Button variant="primary" :loading="editForm.processing" @click="submitEdit">{{ $t('admin.generated.k_6a667ef6c499') }}</Button>
            </template>
        </Modal>

        <!-- Delete Confirmation -->
        <Modal :show="showDeleteModal" :title="$t('admin.generated.k_68cfa489b211')" max-width="sm" @close="showDeleteModal = false">
            <p class="text-body-sm text-neutral-600">
{{ $t('admin.generated.k_f5972c79bb47') }} <strong>{{ selectedRoom?.room_number }}</strong>?
            </p>
            <template #footer>
                <Button variant="outline" @click="showDeleteModal = false">{{ $t('admin.generated.k_a134eeb9c5ba') }}</Button>
                <Button variant="danger" @click="submitDelete">{{ $t('admin.generated.k_481988180f04') }}</Button>
            </template>
        </Modal>

        <ReservationCreateModal
            v-if="canStartReservation"
            :show="showReservationModal"
            :rooms="roomList"
            :guests="guests"
            :channel-fees="channelFees"
            :prefill="reservationPrefill"
            @close="showReservationModal = false"
            @created="toasts?.success('Rezervimi u krijua.')"
            @guest-created="toasts?.success('Mysafiri u shtua.')"
        />

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
