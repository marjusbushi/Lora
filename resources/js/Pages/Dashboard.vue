<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';
import { channelMeta } from '@/channels';
import {
    AlertTriangle,
    ArrowLeftRight,
    ArrowRight,
    BedDouble,
    CalendarDays,
    CheckCircle2,
    ChevronDown,
    ChevronUp,
    LogIn,
    LogOut,
    Moon,
    Plus,
    Radio,
    Sparkles,
    TrendingDown,
    TrendingUp,
    UserX,
    UtensilsCrossed,
    Wallet,
} from 'lucide-vue-next';

const props = defineProps({
    permissions: { type: [Object, Array], default: () => ({}) },
    operational: { type: Object, default: () => ({}) },
    otaHealth: { type: Object, default: () => ({}) },
    roomFlow: { type: Array, default: () => [] },
    actions: { type: Array, default: () => [] },
    ownerPulse: { type: Object, default: null },
    forecast: { type: Array, default: () => [] },
    currency: { type: String, default: '€' },
});

const page = usePage();
const toasts = ref(null);
const showAllRooms = ref(false);
const loadedAt = new Date();

const sharedPermissions = computed(() => page.props.auth?.user?.permissions || []);
const activeModules = computed(() => page.props.modules || {});

function hasPermission(name, ...fallbackNames) {
    const names = [name, ...fallbackNames];
    const supplied = props.permissions;

    if (Array.isArray(supplied) && names.some((permission) => supplied.includes(permission))) {
        return true;
    }

    if (supplied && !Array.isArray(supplied)) {
        for (const permission of names) {
            if (Object.prototype.hasOwnProperty.call(supplied, permission)) {
                return Boolean(supplied[permission]);
            }
        }
    }

    return names.some((permission) => sharedPermissions.value.includes(permission));
}

const canViewReservations = computed(() => hasPermission('view_reservations'));
const canCreateReservations = computed(() => hasPermission('create_reservations'));
const canUpdateReservations = computed(() => hasPermission('update_reservations'));
const canViewHousekeeping = computed(() => hasPermission('view_housekeeping') && activeModules.value.housekeeping === true);
const canViewPos = computed(() => hasPermission('view_pos', 'view_pos_orders') && activeModules.value.pos === true);
const canViewFinancials = computed(() => hasPermission('view_financials', 'view_reports'));
const canViewPricing = computed(() => hasPermission('view_pricing'));
const canViewSmartPricing = computed(() => canViewPricing.value && activeModules.value.smart_pricing === true);

const number = (value) => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
};

const integer = (value) => Math.max(0, Math.round(number(value)));
const percentage = (value) => Math.min(100, Math.max(0, Math.round(number(value))));

const money = (value) => `${props.currency}${number(value).toLocaleString('sq-AL', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
})}`;

const weekdaysLongSq = ['e diel', 'e hënë', 'e martë', 'e mërkurë', 'e enjte', 'e premte', 'e shtunë'];
const weekdaysShortSq = ['Die', 'Hën', 'Mar', 'Mër', 'Enj', 'Pre', 'Sht'];
const monthsLongSq = [
    'janar', 'shkurt', 'mars', 'prill', 'maj', 'qershor',
    'korrik', 'gusht', 'shtator', 'tetor', 'nëntor', 'dhjetor',
];

function parseDate(value) {
    if (!value) return null;
    if (value instanceof Date) return Number.isNaN(value.getTime()) ? null : value;

    const dateOnly = String(value).match(/^(\d{4})-(\d{2})-(\d{2})$/);
    const date = dateOnly
        ? new Date(Number(dateOnly[1]), Number(dateOnly[2]) - 1, Number(dateOnly[3]))
        : new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

function formatDate(value, options = { day: '2-digit', month: '2-digit', year: 'numeric' }) {
    const date = parseDate(value);
    if (!date) return '—';
    if (options.weekday === 'long' && Object.keys(options).length === 1) return weekdaysLongSq[date.getDay()];
    if (options.weekday === 'short' && Object.keys(options).length === 1) return weekdaysShortSq[date.getDay()];
    if (options.day === '2-digit' && Object.keys(options).length === 1) return String(date.getDate()).padStart(2, '0');

    return date.toLocaleDateString('sq-AL', options);
}

function formatDateTime(value) {
    const date = parseDate(value);
    return date
        ? date.toLocaleString('sq-AL', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
        : '—';
}

function formatTime(value) {
    if (!value) return null;
    if (/^\d{1,2}:\d{2}/.test(String(value))) return String(value).slice(0, 5);
    const date = parseDate(value);
    return date ? date.toLocaleTimeString('sq-AL', { hour: '2-digit', minute: '2-digit' }) : String(value);
}

const currentDateLabel = `${weekdaysLongSq[loadedAt.getDay()]}, ${loadedAt.getDate()} ${monthsLongSq[loadedAt.getMonth()]} ${loadedAt.getFullYear()}`;

const updatedTimeLabel = loadedAt.toLocaleTimeString('sq-AL', { hour: '2-digit', minute: '2-digit' });

const occupancyTonight = computed(() => ({
    pct: percentage(props.operational?.occupancy_tonight?.pct),
    sold: integer(props.operational?.occupancy_tonight?.sold),
    sellable: integer(props.operational?.occupancy_tonight?.sellable),
}));

const arrivals = computed(() => ({
    total: integer(props.operational?.arrivals?.total),
    remaining: integer(props.operational?.arrivals?.remaining),
    completed: integer(props.operational?.arrivals?.completed),
}));

const departures = computed(() => ({
    total: integer(props.operational?.departures?.total),
    remaining: integer(props.operational?.departures?.remaining),
    completed: integer(props.operational?.departures?.completed),
}));

const housekeepingSummary = computed(() => ({
    open: integer(props.operational?.housekeeping?.open),
    rush: integer(props.operational?.housekeeping?.rush),
}));

const dueToday = computed(() => ({
    amount: number(props.operational?.due_today?.amount),
    count: integer(props.operational?.due_today?.count),
}));

const inHouseCount = computed(() => {
    const value = props.operational?.in_house_reservations;
    if (Array.isArray(value)) return value.length;
    if (value && typeof value === 'object') return integer(value.count);
    return integer(value);
});

const openPos = computed(() => ({
    count: integer(props.operational?.open_pos?.count),
    total: number(props.operational?.open_pos?.total),
}));

const roomRows = computed(() => (Array.isArray(props.roomFlow) ? props.roomFlow.filter(Boolean) : []));
const visibleRoomRows = computed(() => showAllRooms.value ? roomRows.value : roomRows.value.slice(0, 8));
const actionRows = computed(() => (Array.isArray(props.actions) ? props.actions.filter(Boolean) : []));
const actionIssueCount = computed(() => actionRows.value.reduce((total, action) => total + Math.max(1, integer(action.count)), 0));
const forecastDays = computed(() => (Array.isArray(props.forecast) ? props.forecast.filter(Boolean).slice(0, 7) : []));
const forecastAverage = computed(() => {
    if (!forecastDays.value.length) return null;
    return Math.round(forecastDays.value.reduce((sum, day) => sum + percentage(day.pct), 0) / forecastDays.value.length);
});

const forecastAriaLabel = computed(() => {
    if (!forecastDays.value.length) return 'Nuk ka të dhëna për mbushjen e shtatë ditëve.';
    const values = forecastDays.value.map((day) => `${formatDate(day.date, { weekday: 'long' })} ${percentage(day.pct)} për qind`);
    return `Mbushja e shtatë ditëve: ${values.join(', ')}.`;
});

const otaMeta = computed(() => {
    const status = String(props.otaHealth?.status || 'unknown').toLowerCase();
    if (['healthy', 'success', 'ok', 'synced'].includes(status)) {
        return { variant: 'success', strip: 'border-success-200 bg-success-50/70', icon: 'text-success-600', defaultLabel: 'Sinkronizimi në rregull' };
    }
    if (['error', 'failed', 'unhealthy'].includes(status)) {
        return { variant: 'error', strip: 'border-error-200 bg-error-50/70', icon: 'text-error-600', defaultLabel: 'Kërkon kontroll' };
    }
    if (['warning', 'degraded', 'delayed', 'attention'].includes(status)) {
        return { variant: 'warning', strip: 'border-warning-200 bg-warning-50/70', icon: 'text-warning-700', defaultLabel: 'Sinkronizimi ka vonesë' };
    }
    if (status === 'not_configured') {
        return { variant: 'error', strip: 'border-error-200 bg-error-50/70', icon: 'text-error-600', defaultLabel: 'Channex nuk është konfiguruar' };
    }
    if (status === 'waiting') {
        return { variant: 'info', strip: 'border-info-200 bg-info-50/70', icon: 'text-info-600', defaultLabel: 'Në pritje të sinkronizimit' };
    }
    return { variant: 'neutral', strip: 'border-neutral-200 bg-neutral-50', icon: 'text-neutral-500', defaultLabel: 'Gjendja e panjohur' };
});

const mappedRoomTypeCount = computed(() => {
    const mapped = props.otaHealth?.mapped_room_types;
    return Array.isArray(mapped) ? mapped.length : integer(mapped);
});

const currentStatusMeta = {
    available: { label: 'E lirë', variant: 'success' },
    occupied: { label: 'E zënë', variant: 'info' },
    cleaning: { label: 'Në pastrim', variant: 'warning' },
    dirty: { label: 'Për pastrim', variant: 'warning' },
    maintenance: { label: 'Mirëmbajtje', variant: 'error' },
    out_of_order: { label: 'Jashtë përdorimit', variant: 'error' },
};

function roomStatus(status) {
    const key = String(status || '').toLowerCase();
    return currentStatusMeta[key] || { label: status || 'Pa status', variant: 'neutral' };
}

const cleaningStatusMeta = {
    pending: { label: 'Në pritje', variant: 'warning' },
    in_progress: { label: 'Në pastrim', variant: 'info' },
    completed: { label: 'Gati', variant: 'success' },
    inspected: { label: 'Kontrolluar', variant: 'success' },
};

function cleaningStatus(cleaning) {
    if (!cleaning) return null;
    const key = String(cleaning.status || '').toLowerCase();
    return cleaningStatusMeta[key] || { label: cleaning.status || 'E planifikuar', variant: 'neutral' };
}

function guestName(record) {
    if (!record) return null;
    if (!canViewReservations.value) return 'Rezervim';
    return record.guest || 'Rezervim';
}

function reservationHref(record) {
    return canViewReservations.value && record?.id ? route('reservations.show', record.id) : null;
}

function canCheckIn(record) {
    return canUpdateReservations.value
        && record?.id
        && !record.completed
        && record.ready_for_check_in === true
        && String(record.status || '').toLowerCase() === 'confirmed';
}

function doCheckIn(reservation) {
    router.post(route('reservations.check-in', reservation.id), {}, {
        preserveScroll: true,
        onSuccess: () => toasts.value?.success(`Check-in: ${guestName(reservation)}`),
        onError: () => toasts.value?.error('Check-in dështoi. Kontrollo rezervimin dhe provo përsëri.'),
    });
}

function actionIcon(type) {
    const icons = {
        overstay: LogOut,
        overdue_departure: LogOut,
        departure: LogOut,
        no_show: UserX,
        housekeeping: Sparkles,
        room_not_ready: Sparkles,
        pos: UtensilsCrossed,
        stale_pos: UtensilsCrossed,
        cash_difference: Wallet,
        channex: Radio,
        arrival: LogIn,
    };
    return icons[String(type || '').toLowerCase()] || AlertTriangle;
}

function actionLevel(level) {
    const normalized = String(level || 'warning').toLowerCase();
    if (['error', 'danger', 'critical'].includes(normalized)) {
        return { icon: 'bg-error-50 text-error-700 ring-error-200', title: 'text-error-800' };
    }
    if (['success', 'info'].includes(normalized)) {
        return { icon: 'bg-info-50 text-info-700 ring-info-200', title: 'text-neutral-900' };
    }
    return { icon: 'bg-warning-50 text-warning-700 ring-warning-200', title: 'text-neutral-900' };
}

function forecastBarClass(pct) {
    if (percentage(pct) >= 90) return 'bg-success-500';
    if (percentage(pct) < 50) return 'bg-warning-500';
    return 'bg-info-500';
}

function topChannelLabel(channel) {
    if (!channel) return '—';
    return channelMeta(channel).label;
}
</script>

<template>
    <Head title="Paneli i sotëm" />

    <AppLayout>
        <PageHeader title="Paneli i sotëm">
            <template #actions>
                <Button
                    v-if="canViewReservations"
                    variant="outline"
                    size="sm"
                    @click="router.visit(route('reservations.calendar'))"
                >
                    <template #icon-left><CalendarDays class="h-4 w-4" aria-hidden="true" /></template>
                    Kalendari
                </Button>
                <Button
                    v-if="canCreateReservations"
                    size="sm"
                    @click="router.visit(route('reservations.index', { new: 1 }))"
                >
                    <template #icon-left><Plus class="h-4 w-4" aria-hidden="true" /></template>
                    Rezervim
                </Button>
            </template>
        </PageHeader>

        <p class="mt-1 text-body-sm text-neutral-500">
            <span class="capitalize">{{ currentDateLabel }}</span>
            <span aria-hidden="true"> · </span>
            Përditësuar {{ updatedTimeLabel }}
        </p>

        <!-- PMS -> Channex health. This is deliberately not labelled as direct OTA confirmation. -->
        <section
            class="mt-4 rounded-lg border px-4 py-3"
            :class="otaMeta.strip"
            aria-label="Gjendja e sinkronizimit PMS me Channex"
        >
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex min-w-0 items-start gap-3 sm:items-center">
                    <Radio class="mt-0.5 h-5 w-5 shrink-0 sm:mt-0" :class="otaMeta.icon" aria-hidden="true" />
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge :variant="otaMeta.variant" dot>Channex</Badge>
                            <p class="text-body-sm font-semibold text-neutral-900">{{ otaHealth.label || otaMeta.defaultLabel }}</p>
                        </div>
                        <p class="mt-1 text-tiny text-neutral-500">
                            PMS → Channex
                            <template v-if="otaHealth.last_sync_at"> · Sinkronizimi i fundit {{ formatDateTime(otaHealth.last_sync_at) }}</template>
                            <template v-else> · Ende pa kohë sinkronizimi</template>
                        </p>
                        <p v-if="otaHealth.last_error_at && otaMeta.variant !== 'success'" class="mt-1 text-tiny text-error-700">
                            Gabimi i fundit {{ formatDateTime(otaHealth.last_error_at) }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-tiny text-neutral-600 lg:justify-end">
                    <span v-if="mappedRoomTypeCount">{{ mappedRoomTypeCount }} tipe dhomash të lidhura</span>
                    <span v-if="otaHealth.sell_until">
                        {{ otaHealth.status === 'not_configured' ? 'Dritarja e planifikuar deri' : 'Shitja deri' }}
                        <strong class="font-semibold text-neutral-900">{{ formatDate(otaHealth.sell_until) }}</strong>
                    </span>
                    <span v-if="otaHealth.applied_until">Dërguar deri <strong class="font-semibold text-neutral-900">{{ formatDate(otaHealth.applied_until) }}</strong></span>
                </div>
            </div>
        </section>

        <!-- Four decisions that matter now. -->
        <section class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Përmbledhja operative e ditës">
            <Card>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-tiny uppercase tracking-wider text-neutral-500">Sonte</p>
                        <p class="mt-1 text-h2 leading-none text-primary-900">{{ occupancyTonight.pct }}%</p>
                        <p class="mt-1 text-tiny text-neutral-500">{{ occupancyTonight.sold }} nga {{ occupancyTonight.sellable }} dhoma të shitshme</p>
                    </div>
                    <Moon class="h-5 w-5 shrink-0 text-info-600" aria-hidden="true" />
                </div>
            </Card>

            <Card>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-tiny uppercase tracking-wider text-neutral-500">Mbërritje / Nisje</p>
                        <p class="mt-1 text-h2 leading-none text-primary-900">{{ arrivals.remaining }} / {{ departures.remaining }}</p>
                        <p class="mt-1 text-tiny text-neutral-500">
                            Mbeten sot · përfunduar {{ arrivals.completed }} / {{ departures.completed }}
                        </p>
                    </div>
                    <ArrowLeftRight class="h-5 w-5 shrink-0 text-accent-600" aria-hidden="true" />
                </div>
            </Card>

            <Card>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-tiny uppercase tracking-wider text-neutral-500">Për pastrim</p>
                        <p class="mt-1 text-h2 leading-none text-primary-900">{{ housekeepingSummary.open }}</p>
                        <p class="mt-1 text-tiny" :class="housekeepingSummary.rush ? 'text-error-700' : 'text-neutral-500'">
                            {{ housekeepingSummary.rush }} RUSH për hyrje
                        </p>
                    </div>
                    <Sparkles class="h-5 w-5 shrink-0 text-warning-600" aria-hidden="true" />
                </div>
            </Card>

            <Card v-if="canViewFinancials">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-tiny uppercase tracking-wider text-neutral-500">Për t'u mbledhur sot</p>
                        <p class="mt-1 truncate text-h2 leading-none text-primary-900">{{ money(dueToday.amount) }}</p>
                        <p class="mt-1 text-tiny text-neutral-500">{{ dueToday.count }} qëndrime · jo borxhet e ardhshme</p>
                    </div>
                    <Wallet class="h-5 w-5 shrink-0 text-accent-600" aria-hidden="true" />
                </div>
            </Card>

            <Card v-else>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-tiny uppercase tracking-wider text-neutral-500">Qëndrime në shtëpi</p>
                        <p class="mt-1 text-h2 leading-none text-primary-900">{{ inHouseCount }}</p>
                        <p v-if="canViewPos" class="mt-1 text-tiny text-neutral-500">{{ openPos.count }} porosi POS hapur</p>
                        <p v-else class="mt-1 text-tiny text-neutral-500">Rezervime aktive, jo numër personash</p>
                    </div>
                    <BedDouble class="h-5 w-5 shrink-0 text-info-600" aria-hidden="true" />
                </div>
            </Card>
        </section>

        <section class="mt-6 grid grid-cols-1 items-start gap-6 xl:grid-cols-3" aria-label="Operacionet kryesore">
            <!-- Room flow -->
            <Card :padding="false" class="xl:col-span-2">
                <div class="flex flex-col gap-2 border-b border-neutral-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-label font-semibold text-neutral-900">Rrjedha e dhomave sot</h2>
                        <p class="mt-0.5 text-tiny text-neutral-500">Dalje → pastrim → hyrje, në një vend</p>
                    </div>
                    <Button
                        v-if="roomRows.length > 8"
                        variant="ghost"
                        size="sm"
                        :aria-expanded="showAllRooms"
                        @click="showAllRooms = !showAllRooms"
                    >
                        {{ showAllRooms ? 'Shfaq më pak' : `Të gjitha (${roomRows.length})` }}
                        <template #icon-right>
                            <ChevronUp v-if="showAllRooms" class="h-4 w-4" aria-hidden="true" />
                            <ChevronDown v-else class="h-4 w-4" aria-hidden="true" />
                        </template>
                    </Button>
                </div>

                <div v-if="visibleRoomRows.length" class="hidden overflow-x-auto md:block">
                    <table class="w-full min-w-[760px] border-collapse text-left text-body-sm">
                        <caption class="sr-only">Gjendja, dalja, pastrimi dhe hyrja e dhomave për sot</caption>
                        <thead>
                            <tr class="border-b border-neutral-200 bg-neutral-50 text-tiny uppercase tracking-wider text-neutral-500">
                                <th scope="col" class="px-5 py-2.5 font-medium">Dhomë</th>
                                <th scope="col" class="px-3 py-2.5 font-medium">Tani</th>
                                <th scope="col" class="px-3 py-2.5 font-medium">Dalje</th>
                                <th scope="col" class="px-3 py-2.5 font-medium">Pastrim</th>
                                <th scope="col" class="px-3 py-2.5 pr-5 font-medium">Hyrje</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="row in visibleRoomRows" :key="row.room_id || row.room_number" class="align-top hover:bg-neutral-50/70">
                                <th scope="row" class="px-5 py-3 font-semibold text-primary-900">
                                    {{ row.room_number || '—' }}
                                    <span v-if="row.room_type" class="mt-0.5 block max-w-32 truncate text-tiny font-normal text-neutral-500">{{ row.room_type }}</span>
                                </th>
                                <td class="px-3 py-3"><Badge :variant="roomStatus(row.current_status).variant" dot>{{ roomStatus(row.current_status).label }}</Badge></td>
                                <td class="px-3 py-3">
                                    <template v-if="row.departure">
                                        <Badge v-if="row.departure.completed" variant="success">Përfunduar</Badge>
                                        <div v-else>
                                            <p class="font-medium text-neutral-800">{{ formatTime(row.departure.time) || 'Sot' }}</p>
                                            <component
                                                :is="reservationHref(row.departure) ? Link : 'span'"
                                                :href="reservationHref(row.departure) || undefined"
                                                class="mt-0.5 block max-w-32 truncate text-tiny text-neutral-500"
                                                :class="reservationHref(row.departure) && 'hover:text-accent-700 hover:underline'"
                                            >{{ guestName(row.departure) }}</component>
                                            <p v-if="canViewFinancials && number(row.departure.balance) > 0" class="mt-0.5 text-tiny text-error-700">Për t'u mbledhur {{ money(row.departure.balance) }}</p>
                                        </div>
                                    </template>
                                    <span v-else class="text-neutral-400">—</span>
                                </td>
                                <td class="px-3 py-3">
                                    <template v-if="row.cleaning">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <Badge v-if="row.cleaning.rush" variant="error">RUSH</Badge>
                                            <Badge :variant="cleaningStatus(row.cleaning).variant">{{ cleaningStatus(row.cleaning).label }}</Badge>
                                        </div>
                                        <p v-if="canViewHousekeeping" class="mt-1 max-w-32 truncate text-tiny text-neutral-500">{{ row.cleaning.assigned_to || 'Pa caktuar' }}</p>
                                    </template>
                                    <span v-else class="text-neutral-400">—</span>
                                </td>
                                <td class="px-3 py-3 pr-5">
                                    <template v-if="row.arrival">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <Badge v-if="row.arrival.completed" variant="success">Check-in</Badge>
                                                <p v-else class="font-medium text-neutral-800">{{ formatTime(row.arrival.time) || 'Sot' }}</p>
                                                <component
                                                    :is="reservationHref(row.arrival) ? Link : 'span'"
                                                    :href="reservationHref(row.arrival) || undefined"
                                                    class="mt-0.5 block max-w-36 truncate text-tiny text-neutral-500"
                                                    :class="reservationHref(row.arrival) && 'hover:text-accent-700 hover:underline'"
                                                >{{ guestName(row.arrival) }}</component>
                                            </div>
                                            <Button v-if="canCheckIn(row.arrival)" size="sm" variant="success" @click="doCheckIn(row.arrival)">Check-in</Button>
                                        </div>
                                    </template>
                                    <span v-else class="text-neutral-400">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile room flow keeps every decision visible without a wide table. -->
                <ul v-if="visibleRoomRows.length" class="divide-y divide-neutral-100 md:hidden">
                    <li v-for="row in visibleRoomRows" :key="`mobile-${row.room_id || row.room_number}`" class="px-4 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-semibold text-primary-900">Dhoma {{ row.room_number || '—' }}</p>
                                <p v-if="row.room_type" class="text-tiny text-neutral-500">{{ row.room_type }}</p>
                            </div>
                            <Badge :variant="roomStatus(row.current_status).variant" dot>{{ roomStatus(row.current_status).label }}</Badge>
                        </div>
                        <dl class="mt-3 grid grid-cols-1 gap-2 text-body-sm sm:grid-cols-3">
                            <div class="rounded-md bg-neutral-50 px-3 py-2">
                                <dt class="text-tiny uppercase tracking-wider text-neutral-500">Dalje</dt>
                                <dd class="mt-1 text-neutral-800">
                                    <template v-if="row.departure">
                                        <p>{{ row.departure.completed ? 'Përfunduar' : (formatTime(row.departure.time) || 'Sot') }}</p>
                                        <component
                                            :is="reservationHref(row.departure) ? Link : 'span'"
                                            :href="reservationHref(row.departure) || undefined"
                                            class="mt-0.5 block truncate text-tiny text-neutral-500"
                                            :class="reservationHref(row.departure) && 'text-accent-700 hover:underline'"
                                        >{{ guestName(row.departure) }}</component>
                                        <p v-if="canViewFinancials && number(row.departure.balance) > 0" class="mt-1 text-tiny text-error-700">{{ money(row.departure.balance) }} pa mbledhur</p>
                                    </template>
                                    <template v-else>—</template>
                                </dd>
                            </div>
                            <div class="rounded-md bg-neutral-50 px-3 py-2">
                                <dt class="text-tiny uppercase tracking-wider text-neutral-500">Pastrim</dt>
                                <dd class="mt-1 text-neutral-800">
                                    <template v-if="row.cleaning">
                                        <span v-if="row.cleaning.rush" class="font-semibold text-error-700">RUSH · </span>{{ cleaningStatus(row.cleaning).label }}
                                        <span v-if="row.cleaning.assigned_to" class="mt-0.5 block text-tiny text-neutral-500">{{ row.cleaning.assigned_to }}</span>
                                    </template>
                                    <template v-else>—</template>
                                </dd>
                            </div>
                            <div class="rounded-md bg-neutral-50 px-3 py-2">
                                <dt class="text-tiny uppercase tracking-wider text-neutral-500">Hyrje</dt>
                                <dd class="mt-1 flex items-start justify-between gap-2 text-neutral-800">
                                    <div v-if="row.arrival" class="min-w-0">
                                        <p>{{ row.arrival.completed ? 'Check-in' : (formatTime(row.arrival.time) || 'Sot') }}</p>
                                        <component
                                            :is="reservationHref(row.arrival) ? Link : 'span'"
                                            :href="reservationHref(row.arrival) || undefined"
                                            class="mt-0.5 block truncate text-tiny text-neutral-500"
                                            :class="reservationHref(row.arrival) && 'text-accent-700 hover:underline'"
                                        >{{ guestName(row.arrival) }}</component>
                                    </div>
                                    <span v-else>—</span>
                                    <Button v-if="canCheckIn(row.arrival)" size="sm" variant="success" @click="doCheckIn(row.arrival)">Check-in</Button>
                                </dd>
                            </div>
                        </dl>
                    </li>
                </ul>

                <div v-if="!visibleRoomRows.length" class="px-6 py-12 text-center">
                    <CheckCircle2 class="mx-auto h-8 w-8 text-success-500" aria-hidden="true" />
                    <p class="mt-2 text-body-sm font-medium text-neutral-800">Nuk ka lëvizje dhomash për sot.</p>
                    <p class="mt-1 text-tiny text-neutral-500">Dhomat pa dalje, pastrim ose hyrje nuk e mbushin këtë listë.</p>
                </div>
            </Card>

            <!-- Prioritised actions -->
            <Card :padding="false">
                <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4">
                    <div>
                        <h2 class="text-label font-semibold text-neutral-900">Duhet vepruar tani</h2>
                        <p class="mt-0.5 text-tiny text-neutral-500">Renditur sipas rrezikut</p>
                    </div>
                    <Badge :variant="actionRows.length ? 'warning' : 'success'">{{ actionIssueCount }} çështje</Badge>
                </div>

                <ul v-if="actionRows.length" class="max-h-[34rem] divide-y divide-neutral-100 overflow-y-auto">
                    <li v-for="(action, index) in actionRows" :key="`${action.type || 'action'}-${index}`" class="flex items-start gap-3 px-5 py-4">
                        <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full ring-1" :class="actionLevel(action.level).icon">
                            <component :is="actionIcon(action.type)" class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-body-sm font-semibold" :class="actionLevel(action.level).title">{{ action.title || 'Kërkon kontroll' }}</p>
                            <p v-if="action.detail" class="mt-1 text-tiny text-neutral-500">{{ action.detail }}</p>
                            <Link
                                v-if="action.href"
                                :href="action.href"
                                class="mt-2 inline-flex items-center gap-1 text-tiny font-semibold text-accent-700 hover:text-accent-800 hover:underline"
                            >
                                {{ action.cta || 'Hap' }}
                                <ArrowRight class="h-3.5 w-3.5" aria-hidden="true" />
                            </Link>
                        </div>
                    </li>
                </ul>

                <div v-else class="px-6 py-12 text-center">
                    <CheckCircle2 class="mx-auto h-9 w-9 text-success-500" aria-hidden="true" />
                    <p class="mt-2 text-body-sm font-medium text-neutral-800">Nuk ka çështje urgjente.</p>
                    <p class="mt-1 text-tiny text-neutral-500">Operacionet e ditës janë në rregull.</p>
                </div>

                <div v-if="canViewPos && openPos.count" class="border-t border-neutral-200 bg-neutral-50 px-5 py-3 text-tiny text-neutral-600">
                    {{ openPos.count }} porosi POS hapur<span v-if="canViewFinancials"> · {{ money(openPos.total) }}</span>
                </div>
            </Card>
        </section>

        <section
            class="mt-6 grid grid-cols-1 gap-6"
            :class="canViewFinancials && ownerPulse ? 'xl:grid-cols-3' : ''"
            aria-label="Parashikimi dhe pulsi i pronarit"
        >
            <!-- Seven-day occupancy -->
            <Card :class="canViewFinancials && ownerPulse ? 'xl:col-span-2' : ''">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-label font-semibold text-neutral-900">Mbushja · 7 ditët e ardhshme</h2>
                        <p class="mt-0.5 text-tiny text-neutral-500">
                            <template v-if="forecastAverage !== null">Mesatarja {{ forecastAverage }}% · netët e dobëta duken menjëherë</template>
                            <template v-else>Ende pa të dhëna parashikimi</template>
                        </p>
                    </div>
                    <Button
                        v-if="canViewSmartPricing"
                        variant="ghost"
                        size="sm"
                        @click="router.visit(route('pricing.smart.index'))"
                    >
                        Hap Çmimin Inteligjent
                        <template #icon-right><ArrowRight class="h-4 w-4" aria-hidden="true" /></template>
                    </Button>
                </div>

                <div v-if="forecastDays.length" class="mt-5 grid h-48 grid-cols-7 gap-2" role="img" :aria-label="forecastAriaLabel">
                    <div v-for="day in forecastDays" :key="day.date" class="grid min-w-0 grid-rows-[1fr_auto_auto] gap-1 text-center">
                        <div class="flex min-h-28 items-end overflow-hidden rounded-t-md bg-neutral-100">
                            <div
                                class="w-full rounded-t-md transition-all duration-250"
                                :class="forecastBarClass(day.pct)"
                                :style="{ height: `${Math.max(percentage(day.pct), 3)}%` }"
                            />
                        </div>
                        <p class="truncate text-tiny font-semibold text-neutral-800">{{ percentage(day.pct) }}%</p>
                        <p class="truncate text-[10px] capitalize text-neutral-500">
                            {{ formatDate(day.date, { weekday: 'short' }) }} {{ formatDate(day.date, { day: '2-digit' }) }}
                        </p>
                    </div>
                </div>

                <div v-else class="py-12 text-center">
                    <CalendarDays class="mx-auto h-8 w-8 text-neutral-400" aria-hidden="true" />
                    <p class="mt-2 text-body-sm text-neutral-500">Parashikimi nuk është i disponueshëm.</p>
                </div>

                <div v-if="forecastDays.length" class="mt-4 flex flex-wrap gap-x-4 gap-y-2 text-tiny text-neutral-500" aria-hidden="true">
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-warning-500" /> Nën 50%</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-info-500" /> 50–89%</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-success-500" /> 90%+</span>
                </div>
            </Card>

            <!-- Financial data is never rendered without the explicit backend permission. -->
            <Card v-if="canViewFinancials && ownerPulse">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-label font-semibold text-neutral-900">Pulsi i pronarit</h2>
                        <p class="mt-0.5 text-tiny text-neutral-500">Arkëtime, jo “të ardhura të fituara”</p>
                    </div>
                    <Wallet class="h-5 w-5 text-accent-600" aria-hidden="true" />
                </div>

                <dl class="mt-5 divide-y divide-neutral-100">
                    <div class="flex items-start justify-between gap-4 py-3 first:pt-0">
                        <dt class="text-body-sm text-neutral-600">Arkëtuar sot</dt>
                        <dd class="text-right text-body-sm font-semibold text-primary-900">
                            {{ money(ownerPulse.collected_today) }}
                            <span class="mt-0.5 block text-tiny font-normal text-neutral-500">Cash {{ money(ownerPulse.cash_today) }} · Kartë {{ money(ownerPulse.card_today) }}</span>
                        </dd>
                    </div>
                    <div class="flex items-start justify-between gap-4 py-3">
                        <dt class="text-body-sm text-neutral-600">Arkëtuar këtë muaj</dt>
                        <dd class="text-right text-body-sm font-semibold text-primary-900">{{ money(ownerPulse.collected_month) }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-4 py-3">
                        <dt class="text-body-sm text-neutral-600">E njëjta periudhë · muajin e kaluar</dt>
                        <dd class="text-right text-body-sm font-semibold text-primary-900">{{ money(ownerPulse.collected_month_prev) }}</dd>
                    </div>
                    <div v-if="ownerPulse.collected_month_delta !== null && ownerPulse.collected_month_delta !== undefined" class="flex items-start justify-between gap-4 py-3">
                        <dt class="text-body-sm text-neutral-600">Ndryshimi</dt>
                        <dd
                            class="inline-flex items-center gap-1 text-body-sm font-semibold"
                            :class="number(ownerPulse.collected_month_delta) >= 0 ? 'text-success-700' : 'text-error-700'"
                        >
                            <TrendingUp v-if="number(ownerPulse.collected_month_delta) >= 0" class="h-4 w-4" aria-hidden="true" />
                            <TrendingDown v-else class="h-4 w-4" aria-hidden="true" />
                            {{ Math.abs(number(ownerPulse.collected_month_delta)) }}%
                        </dd>
                    </div>
                    <div class="flex items-start justify-between gap-4 py-3 last:pb-0">
                        <dt class="text-body-sm text-neutral-600">Burimi kryesor · 30 ditë</dt>
                        <dd class="text-right text-body-sm font-semibold text-primary-900">{{ topChannelLabel(ownerPulse.top_channel) }}</dd>
                    </div>
                </dl>
            </Card>
        </section>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
