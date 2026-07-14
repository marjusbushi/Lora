<script setup>
import { getIntlLocale, translate } from '@/i18n';
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

const money = (value) => `${props.currency}${number(value).toLocaleString(getIntlLocale(), {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
})}`;

const weekdaysLongSq = ['e diel', translate('admin.generated.k_06574a6a7c25'), translate('admin.generated.k_754fe3084c7e'), translate('admin.generated.k_9a7a13c77891'), 'e enjte', 'e premte', translate('admin.generated.k_d9c3b08164f5')];
const weekdaysShortSq = ['Die', translate('admin.generated.k_ec287e95ed4c'), 'Mar', translate('admin.generated.k_12efb44b2db9'), 'Enj', 'Pre', 'Sht'];
const monthsLongSq = [
    'janar', 'shkurt', 'mars', 'prill', 'maj', 'qershor',
    'korrik', 'gusht', 'shtator', 'tetor', translate('admin.generated.k_05bbfcb2326e'), 'dhjetor',
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

    return date.toLocaleDateString(getIntlLocale(), options);
}

function formatDateTime(value) {
    const date = parseDate(value);
    return date
        ? date.toLocaleString(getIntlLocale(), { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
        : '—';
}

function formatTime(value) {
    if (!value) return null;
    if (/^\d{1,2}:\d{2}/.test(String(value))) return String(value).slice(0, 5);
    const date = parseDate(value);
    return date ? date.toLocaleTimeString(getIntlLocale(), { hour: '2-digit', minute: '2-digit' }) : String(value);
}

const currentDateLabel = `${weekdaysLongSq[loadedAt.getDay()]}, ${loadedAt.getDate()} ${monthsLongSq[loadedAt.getMonth()]} ${loadedAt.getFullYear()}`;

const updatedTimeLabel = loadedAt.toLocaleTimeString(getIntlLocale(), { hour: '2-digit', minute: '2-digit' });

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
    if (!forecastDays.value.length) return translate('admin.generated.k_a35f9a6c4f2a');
    const values = forecastDays.value.map((day) => translate('admin.generated.k_1af96a255005', { p0: formatDate(day.date, { weekday: 'long' }), p1: percentage(day.pct) }));
    return translate('admin.generated.k_c72fe977e637', { p0: values.join(', ') });
});

const otaMeta = computed(() => {
    const status = String(props.otaHealth?.status || 'unknown').toLowerCase();
    if (['healthy', 'success', 'ok', 'synced'].includes(status)) {
        return { variant: 'success', strip: 'border-success-200 bg-success-50/70', icon: 'text-success-600', defaultLabel: translate('admin.generated.k_0d92c302f28f') };
    }
    if (['error', 'failed', 'unhealthy'].includes(status)) {
        return { variant: 'error', strip: 'border-error-200 bg-error-50/70', icon: 'text-error-600', defaultLabel: translate('admin.generated.k_457352644562') };
    }
    if (['warning', 'degraded', 'delayed', 'attention'].includes(status)) {
        return { variant: 'warning', strip: 'border-warning-200 bg-warning-50/70', icon: 'text-warning-700', defaultLabel: translate('admin.generated.k_df243eafc59b') };
    }
    if (status === 'not_configured') {
        return { variant: 'error', strip: 'border-error-200 bg-error-50/70', icon: 'text-error-600', defaultLabel: translate('admin.generated.k_12ff50f0cc48') };
    }
    if (status === 'waiting') {
        return { variant: 'info', strip: 'border-info-200 bg-info-50/70', icon: 'text-info-600', defaultLabel: translate('admin.generated.k_22e594caa63a') };
    }
    return { variant: 'neutral', strip: 'border-neutral-200 bg-neutral-50', icon: 'text-neutral-500', defaultLabel: 'Gjendja e panjohur' };
});

const mappedRoomTypeCount = computed(() => {
    const mapped = props.otaHealth?.mapped_room_types;
    return Array.isArray(mapped) ? mapped.length : integer(mapped);
});

const currentStatusMeta = {
    available: { label: translate('admin.generated.k_2cdba09e5fea'), variant: 'success' },
    occupied: { label: translate('admin.generated.k_8dd93937d72d'), variant: 'info' },
    cleaning: { label: translate('admin.generated.k_31e065f124a3'), variant: 'warning' },
    dirty: { label: translate('admin.generated.k_a25636e46a0c'), variant: 'warning' },
    maintenance: { label: translate('admin.generated.k_e9c8dc410c39'), variant: 'error' },
    out_of_order: { label: translate('admin.generated.k_473137a83185'), variant: 'error' },
};

function roomStatus(status) {
    const key = String(status || '').toLowerCase();
    return currentStatusMeta[key] || { label: status || translate('admin.generated.k_a558a5f23fc5'), variant: 'neutral' };
}

const cleaningStatusMeta = {
    pending: { label: translate('admin.generated.k_71854d9e49ae'), variant: 'warning' },
    in_progress: { label: translate('admin.generated.k_31e065f124a3'), variant: 'info' },
    completed: { label: translate('admin.generated.k_dd9d74010c4e'), variant: 'success' },
    inspected: { label: translate('admin.generated.k_e69228557f59'), variant: 'success' },
};

function cleaningStatus(cleaning) {
    if (!cleaning) return null;
    const key = String(cleaning.status || '').toLowerCase();
    return cleaningStatusMeta[key] || { label: cleaning.status || 'E planifikuar', variant: 'neutral' };
}

function guestName(record) {
    if (!record) return null;
    if (!canViewReservations.value) return translate('admin.generated.k_1c18223f7adb');
    return record.guest || translate('admin.generated.k_1c18223f7adb');
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
        onError: () => toasts.value?.error(translate('admin.generated.k_e50d34b8895e')),
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
    <Head :title="$t('admin.generated.k_3bb43b615dad')" />

    <AppLayout>
        <PageHeader :title="$t('admin.generated.k_3bb43b615dad')">
            <template #actions>
                <Button
                    v-if="canViewReservations"
                    variant="outline"
                    size="sm"
                    @click="router.visit(route('reservations.calendar'))"
                >
                    <template #icon-left><CalendarDays class="h-4 w-4" aria-hidden="true" /></template>
{{ $t('admin.generated.k_81ef51aa4887') }} </Button>
                <Button
                    v-if="canCreateReservations"
                    size="sm"
                    @click="router.visit(route('reservations.index', { new: 1 }))"
                >
                    <template #icon-left><Plus class="h-4 w-4" aria-hidden="true" /></template>
{{ $t('admin.generated.k_77b9885ce6c7') }} </Button>
            </template>
        </PageHeader>

        <p class="mt-1 text-body-sm text-neutral-500">
            <span class="capitalize">{{ currentDateLabel }}</span>
            <span aria-hidden="true"> · </span>
{{ $t('admin.generated.k_f01c0a9449b2') }} {{ updatedTimeLabel }}
        </p>

        <!-- PMS -> Channex health. This is deliberately not labelled as direct OTA confirmation. -->
        <section
            class="mt-4 rounded-lg border px-4 py-3"
            :class="otaMeta.strip"
            :aria-label="$t('admin.generated.k_8c0c0c747084')"
        >
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex min-w-0 items-start gap-3 sm:items-center">
                    <Radio class="mt-0.5 h-5 w-5 shrink-0 sm:mt-0" :class="otaMeta.icon" aria-hidden="true" />
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge :variant="otaMeta.variant" dot>{{ $t('admin.generated.k_9b3821f6183d') }}</Badge>
                            <p class="text-body-sm font-semibold text-neutral-900">{{ otaHealth.label || otaMeta.defaultLabel }}</p>
                        </div>
                        <p class="mt-1 text-tiny text-neutral-500">
{{ $t('admin.generated.k_f08ecbd00ec7') }} <template v-if="otaHealth.last_sync_at"> {{ $t('admin.generated.k_255deab1e509') }} {{ formatDateTime(otaHealth.last_sync_at) }}</template>
                            <template v-else> {{ $t('admin.generated.k_08d85c6fda1c') }}</template>
                        </p>
                        <p v-if="otaHealth.last_error_at && otaMeta.variant !== 'success'" class="mt-1 text-tiny text-error-700">
{{ $t('admin.generated.k_1cb78d18e7fe') }} {{ formatDateTime(otaHealth.last_error_at) }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-tiny text-neutral-600 lg:justify-end">
                    <span v-if="mappedRoomTypeCount">{{ mappedRoomTypeCount }} {{ $t('admin.generated.k_e56386f71f7b') }}</span>
                    <span v-if="otaHealth.sell_until">
                        {{ otaHealth.status === 'not_configured' ? $t('admin.generated.k_4af50f58ac96') : $t('admin.generated.k_9cc9d20473c0') }}
                        <strong class="font-semibold text-neutral-900">{{ formatDate(otaHealth.sell_until) }}</strong>
                    </span>
                    <span v-if="otaHealth.applied_until">{{ $t('admin.generated.k_1ce6f3392ea8') }} <strong class="font-semibold text-neutral-900">{{ formatDate(otaHealth.applied_until) }}</strong></span>
                </div>
            </div>
        </section>

        <!-- Four decisions that matter now. -->
        <section class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4" :aria-label="$t('admin.generated.k_8437edb13a0d')">
            <Card>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-tiny uppercase tracking-wider text-neutral-500">{{ $t('admin.generated.k_d7c19375ba4f') }}</p>
                        <p class="mt-1 text-h2 leading-none text-primary-900">{{ occupancyTonight.pct }}%</p>
                        <p class="mt-1 text-tiny text-neutral-500">{{ occupancyTonight.sold }} {{ $t('admin.generated.k_5b67427cc9bc') }} {{ occupancyTonight.sellable }} {{ $t('admin.generated.k_13eef48ece88') }}</p>
                    </div>
                    <Moon class="h-5 w-5 shrink-0 text-info-600" aria-hidden="true" />
                </div>
            </Card>

            <Card>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-tiny uppercase tracking-wider text-neutral-500">{{ $t('admin.generated.k_8035f7307191') }}</p>
                        <p class="mt-1 text-h2 leading-none text-primary-900">{{ arrivals.remaining }} / {{ departures.remaining }}</p>
                        <p class="mt-1 text-tiny text-neutral-500">
{{ $t('admin.generated.k_7f877cb4ada2') }} {{ arrivals.completed }} / {{ departures.completed }}
                        </p>
                    </div>
                    <ArrowLeftRight class="h-5 w-5 shrink-0 text-accent-600" aria-hidden="true" />
                </div>
            </Card>

            <Card>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-tiny uppercase tracking-wider text-neutral-500">{{ $t('admin.generated.k_16ccc03435c2') }}</p>
                        <p class="mt-1 text-h2 leading-none text-primary-900">{{ housekeepingSummary.open }}</p>
                        <p class="mt-1 text-tiny" :class="housekeepingSummary.rush ? 'text-error-700' : 'text-neutral-500'">
                            {{ housekeepingSummary.rush }} {{ $t('admin.generated.k_0b09fda106fd') }} </p>
                    </div>
                    <Sparkles class="h-5 w-5 shrink-0 text-warning-600" aria-hidden="true" />
                </div>
            </Card>

            <Card v-if="canViewFinancials">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-tiny uppercase tracking-wider text-neutral-500">{{ $t('admin.generated.k_581516fba779') }}</p>
                        <p class="mt-1 truncate text-h2 leading-none text-primary-900">{{ money(dueToday.amount) }}</p>
                        <p class="mt-1 text-tiny text-neutral-500">{{ dueToday.count }} {{ $t('admin.generated.k_eae396ff5c9e') }}</p>
                    </div>
                    <Wallet class="h-5 w-5 shrink-0 text-accent-600" aria-hidden="true" />
                </div>
            </Card>

            <Card v-else>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-tiny uppercase tracking-wider text-neutral-500">{{ $t('admin.generated.k_02184af51a0f') }}</p>
                        <p class="mt-1 text-h2 leading-none text-primary-900">{{ inHouseCount }}</p>
                        <p v-if="canViewPos" class="mt-1 text-tiny text-neutral-500">{{ openPos.count }} {{ $t('admin.generated.k_7c6f6ad916fe') }}</p>
                        <p v-else class="mt-1 text-tiny text-neutral-500">{{ $t('admin.generated.k_d0db8c38bdb7') }}</p>
                    </div>
                    <BedDouble class="h-5 w-5 shrink-0 text-info-600" aria-hidden="true" />
                </div>
            </Card>
        </section>

        <section class="mt-6 grid grid-cols-1 items-start gap-6 xl:grid-cols-3" :aria-label="$t('admin.generated.k_c88d04501642')">
            <!-- Room flow -->
            <Card :padding="false" class="xl:col-span-2">
                <div class="flex flex-col gap-2 border-b border-neutral-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-label font-semibold text-neutral-900">{{ $t('admin.generated.k_183abb954511') }}</h2>
                        <p class="mt-0.5 text-tiny text-neutral-500">{{ $t('admin.generated.k_ed305042322c') }}</p>
                    </div>
                    <Button
                        v-if="roomRows.length > 8"
                        variant="ghost"
                        size="sm"
                        :aria-expanded="showAllRooms"
                        @click="showAllRooms = !showAllRooms"
                    >
                        {{ showAllRooms ? $t('admin.generated.k_e0c24d45d7cc') : `Të gjitha (${roomRows.length})` }}
                        <template #icon-right>
                            <ChevronUp v-if="showAllRooms" class="h-4 w-4" aria-hidden="true" />
                            <ChevronDown v-else class="h-4 w-4" aria-hidden="true" />
                        </template>
                    </Button>
                </div>

                <div v-if="visibleRoomRows.length" class="hidden overflow-x-auto md:block">
                    <table class="w-full min-w-[760px] border-collapse text-left text-body-sm">
                        <caption class="sr-only">{{ $t('admin.generated.k_cf004817a4ea') }}</caption>
                        <thead>
                            <tr class="border-b border-neutral-200 bg-neutral-50 text-tiny uppercase tracking-wider text-neutral-500">
                                <th scope="col" class="px-5 py-2.5 font-medium">{{ $t('admin.generated.k_92b33ac57a9e') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-medium">{{ $t('admin.generated.k_da4720494d8b') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-medium">{{ $t('admin.generated.k_3e80fa93adb7') }}</th>
                                <th scope="col" class="px-3 py-2.5 font-medium">{{ $t('admin.generated.k_5c05461c3bc0') }}</th>
                                <th scope="col" class="px-3 py-2.5 pr-5 font-medium">{{ $t('admin.generated.k_cd9eb9f2de95') }}</th>
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
                                        <Badge v-if="row.departure.completed" variant="success">{{ $t('admin.generated.k_82be0d76fe0d') }}</Badge>
                                        <div v-else>
                                            <p class="font-medium text-neutral-800">{{ formatTime(row.departure.time) || $t('admin.generated.k_946fe6e5fc77') }}</p>
                                            <component
                                                :is="reservationHref(row.departure) ? Link : 'span'"
                                                :href="reservationHref(row.departure) || undefined"
                                                class="mt-0.5 block max-w-32 truncate text-tiny text-neutral-500"
                                                :class="reservationHref(row.departure) && 'hover:text-accent-700 hover:underline'"
                                            >{{ guestName(row.departure) }}</component>
                                            <p v-if="canViewFinancials && number(row.departure.balance) > 0" class="mt-0.5 text-tiny text-error-700">{{ $t('admin.generated.k_ce448fac5682') }} {{ money(row.departure.balance) }}</p>
                                        </div>
                                    </template>
                                    <span v-else class="text-neutral-400">—</span>
                                </td>
                                <td class="px-3 py-3">
                                    <template v-if="row.cleaning">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <Badge v-if="row.cleaning.rush" variant="error">{{ $t('admin.generated.k_e05ccddc3b49') }}</Badge>
                                            <Badge :variant="cleaningStatus(row.cleaning).variant">{{ cleaningStatus(row.cleaning).label }}</Badge>
                                        </div>
                                        <p v-if="canViewHousekeeping" class="mt-1 max-w-32 truncate text-tiny text-neutral-500">{{ row.cleaning.assigned_to || $t('admin.generated.k_74c08007fbf4') }}</p>
                                    </template>
                                    <span v-else class="text-neutral-400">—</span>
                                </td>
                                <td class="px-3 py-3 pr-5">
                                    <template v-if="row.arrival">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <Badge v-if="row.arrival.completed" variant="success">{{ $t('admin.generated.k_6903f7c68af7') }}</Badge>
                                                <p v-else class="font-medium text-neutral-800">{{ formatTime(row.arrival.time) || $t('admin.generated.k_946fe6e5fc77') }}</p>
                                                <component
                                                    :is="reservationHref(row.arrival) ? Link : 'span'"
                                                    :href="reservationHref(row.arrival) || undefined"
                                                    class="mt-0.5 block max-w-36 truncate text-tiny text-neutral-500"
                                                    :class="reservationHref(row.arrival) && 'hover:text-accent-700 hover:underline'"
                                                >{{ guestName(row.arrival) }}</component>
                                            </div>
                                            <Button v-if="canCheckIn(row.arrival)" size="sm" variant="success" @click="doCheckIn(row.arrival)">{{ $t('admin.generated.k_6903f7c68af7') }}</Button>
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
                                <p class="font-semibold text-primary-900">{{ $t('admin.generated.k_339f29aa1ed8') }} {{ row.room_number || '—' }}</p>
                                <p v-if="row.room_type" class="text-tiny text-neutral-500">{{ row.room_type }}</p>
                            </div>
                            <Badge :variant="roomStatus(row.current_status).variant" dot>{{ roomStatus(row.current_status).label }}</Badge>
                        </div>
                        <dl class="mt-3 grid grid-cols-1 gap-2 text-body-sm sm:grid-cols-3">
                            <div class="rounded-md bg-neutral-50 px-3 py-2">
                                <dt class="text-tiny uppercase tracking-wider text-neutral-500">{{ $t('admin.generated.k_3e80fa93adb7') }}</dt>
                                <dd class="mt-1 text-neutral-800">
                                    <template v-if="row.departure">
                                        <p>{{ row.departure.completed ? $t('admin.generated.k_8325767948f8') : (formatTime(row.departure.time) || $t('admin.generated.k_946fe6e5fc77')) }}</p>
                                        <component
                                            :is="reservationHref(row.departure) ? Link : 'span'"
                                            :href="reservationHref(row.departure) || undefined"
                                            class="mt-0.5 block truncate text-tiny text-neutral-500"
                                            :class="reservationHref(row.departure) && 'text-accent-700 hover:underline'"
                                        >{{ guestName(row.departure) }}</component>
                                        <p v-if="canViewFinancials && number(row.departure.balance) > 0" class="mt-1 text-tiny text-error-700">{{ money(row.departure.balance) }} {{ $t('admin.generated.k_0e0b60e66caf') }}</p>
                                    </template>
                                    <template v-else>—</template>
                                </dd>
                            </div>
                            <div class="rounded-md bg-neutral-50 px-3 py-2">
                                <dt class="text-tiny uppercase tracking-wider text-neutral-500">{{ $t('admin.generated.k_5c05461c3bc0') }}</dt>
                                <dd class="mt-1 text-neutral-800">
                                    <template v-if="row.cleaning">
                                        <span v-if="row.cleaning.rush" class="font-semibold text-error-700">{{ $t('admin.generated.k_0df4938ae9f3') }} </span>{{ cleaningStatus(row.cleaning).label }}
                                        <span v-if="row.cleaning.assigned_to" class="mt-0.5 block text-tiny text-neutral-500">{{ row.cleaning.assigned_to }}</span>
                                    </template>
                                    <template v-else>—</template>
                                </dd>
                            </div>
                            <div class="rounded-md bg-neutral-50 px-3 py-2">
                                <dt class="text-tiny uppercase tracking-wider text-neutral-500">{{ $t('admin.generated.k_cd9eb9f2de95') }}</dt>
                                <dd class="mt-1 flex items-start justify-between gap-2 text-neutral-800">
                                    <div v-if="row.arrival" class="min-w-0">
                                        <p>{{ row.arrival.completed ? $t('admin.generated.k_4a1300882be6') : (formatTime(row.arrival.time) || $t('admin.generated.k_946fe6e5fc77')) }}</p>
                                        <component
                                            :is="reservationHref(row.arrival) ? Link : 'span'"
                                            :href="reservationHref(row.arrival) || undefined"
                                            class="mt-0.5 block truncate text-tiny text-neutral-500"
                                            :class="reservationHref(row.arrival) && 'text-accent-700 hover:underline'"
                                        >{{ guestName(row.arrival) }}</component>
                                    </div>
                                    <span v-else>—</span>
                                    <Button v-if="canCheckIn(row.arrival)" size="sm" variant="success" @click="doCheckIn(row.arrival)">{{ $t('admin.generated.k_6903f7c68af7') }}</Button>
                                </dd>
                            </div>
                        </dl>
                    </li>
                </ul>

                <div v-if="!visibleRoomRows.length" class="px-6 py-12 text-center">
                    <CheckCircle2 class="mx-auto h-8 w-8 text-success-500" aria-hidden="true" />
                    <p class="mt-2 text-body-sm font-medium text-neutral-800">{{ $t('admin.generated.k_3f3893443639') }}</p>
                    <p class="mt-1 text-tiny text-neutral-500">{{ $t('admin.generated.k_88d4d09c9a1f') }}</p>
                </div>
            </Card>

            <!-- Prioritised actions -->
            <Card :padding="false">
                <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4">
                    <div>
                        <h2 class="text-label font-semibold text-neutral-900">{{ $t('admin.generated.k_916e8f743d0a') }}</h2>
                        <p class="mt-0.5 text-tiny text-neutral-500">{{ $t('admin.generated.k_371ddcba188c') }}</p>
                    </div>
                    <Badge :variant="actionRows.length ? 'warning' : 'success'">{{ actionIssueCount }} {{ $t('admin.generated.k_8523c52f2bd2') }}</Badge>
                </div>

                <ul v-if="actionRows.length" class="max-h-[34rem] divide-y divide-neutral-100 overflow-y-auto">
                    <li v-for="(action, index) in actionRows" :key="`${action.type || 'action'}-${index}`" class="flex items-start gap-3 px-5 py-4">
                        <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full ring-1" :class="actionLevel(action.level).icon">
                            <component :is="actionIcon(action.type)" class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-body-sm font-semibold" :class="actionLevel(action.level).title">{{ action.title || $t('admin.generated.k_202a2b688d89') }}</p>
                            <p v-if="action.detail" class="mt-1 text-tiny text-neutral-500">{{ action.detail }}</p>
                            <Link
                                v-if="action.href"
                                :href="action.href"
                                class="mt-2 inline-flex items-center gap-1 text-tiny font-semibold text-accent-700 hover:text-accent-800 hover:underline"
                            >
                                {{ action.cta || $t('admin.generated.k_179b01e8d670') }}
                                <ArrowRight class="h-3.5 w-3.5" aria-hidden="true" />
                            </Link>
                        </div>
                    </li>
                </ul>

                <div v-else class="px-6 py-12 text-center">
                    <CheckCircle2 class="mx-auto h-9 w-9 text-success-500" aria-hidden="true" />
                    <p class="mt-2 text-body-sm font-medium text-neutral-800">{{ $t('admin.generated.k_bfbe2985a2b4') }}</p>
                    <p class="mt-1 text-tiny text-neutral-500">{{ $t('admin.generated.k_f8131cffaee9') }}</p>
                </div>

                <div v-if="canViewPos && openPos.count" class="border-t border-neutral-200 bg-neutral-50 px-5 py-3 text-tiny text-neutral-600">
                    {{ openPos.count }} {{ $t('admin.generated.k_7c6f6ad916fe') }}<span v-if="canViewFinancials"> · {{ money(openPos.total) }}</span>
                </div>
            </Card>
        </section>

        <section
            class="mt-6 grid grid-cols-1 gap-6"
            :class="canViewFinancials && ownerPulse ? 'xl:grid-cols-3' : ''"
            :aria-label="$t('admin.generated.k_76bc0da07c6f')"
        >
            <!-- Seven-day occupancy -->
            <Card :class="canViewFinancials && ownerPulse ? 'xl:col-span-2' : ''">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-label font-semibold text-neutral-900">{{ $t('admin.generated.k_f2aca9e6d758') }}</h2>
                        <p class="mt-0.5 text-tiny text-neutral-500">
                            <template v-if="forecastAverage !== null">{{ $t('admin.generated.k_f1d9a9f9911a') }} {{ forecastAverage }}{{ $t('admin.generated.k_20ee9d7a8681') }}</template>
                            <template v-else>{{ $t('admin.generated.k_950f20c8a542') }}</template>
                        </p>
                    </div>
                    <Button
                        v-if="canViewSmartPricing"
                        variant="ghost"
                        size="sm"
                        @click="router.visit(route('pricing.smart.index'))"
                    >
{{ $t('admin.generated.k_ddbe88f5549d') }} <template #icon-right><ArrowRight class="h-4 w-4" aria-hidden="true" /></template>
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
                    <p class="mt-2 text-body-sm text-neutral-500">{{ $t('admin.generated.k_eada64aa4416') }}</p>
                </div>

                <div v-if="forecastDays.length" class="mt-4 flex flex-wrap gap-x-4 gap-y-2 text-tiny text-neutral-500" aria-hidden="true">
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-warning-500" /> {{ $t('admin.generated.k_bb61c001267d') }}</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-info-500" /> 50–89%</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-success-500" /> 90%+</span>
                </div>
            </Card>

            <!-- Financial data is never rendered without the explicit backend permission. -->
            <Card v-if="canViewFinancials && ownerPulse">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-label font-semibold text-neutral-900">{{ $t('admin.generated.k_623070d33725') }}</h2>
                        <p class="mt-0.5 text-tiny text-neutral-500">{{ $t('admin.generated.k_c4fcd75b8f36') }}</p>
                    </div>
                    <Wallet class="h-5 w-5 text-accent-600" aria-hidden="true" />
                </div>

                <dl class="mt-5 divide-y divide-neutral-100">
                    <div class="flex items-start justify-between gap-4 py-3 first:pt-0">
                        <dt class="text-body-sm text-neutral-600">{{ $t('admin.generated.k_3a3c8fb8eb08') }}</dt>
                        <dd class="text-right text-body-sm font-semibold text-primary-900">
                            {{ money(ownerPulse.collected_today) }}
                            <span class="mt-0.5 block text-tiny font-normal text-neutral-500">{{ $t('admin.generated.k_35f3acff7252') }} {{ money(ownerPulse.cash_today) }} {{ $t('admin.generated.k_c787bff3610c') }} {{ money(ownerPulse.card_today) }}</span>
                        </dd>
                    </div>
                    <div class="flex items-start justify-between gap-4 py-3">
                        <dt class="text-body-sm text-neutral-600">{{ $t('admin.generated.k_d141c6dc5de3') }}</dt>
                        <dd class="text-right text-body-sm font-semibold text-primary-900">{{ money(ownerPulse.collected_month) }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-4 py-3">
                        <dt class="text-body-sm text-neutral-600">{{ $t('admin.generated.k_972d8399b5dc') }}</dt>
                        <dd class="text-right text-body-sm font-semibold text-primary-900">{{ money(ownerPulse.collected_month_prev) }}</dd>
                    </div>
                    <div v-if="ownerPulse.collected_month_delta !== null && ownerPulse.collected_month_delta !== undefined" class="flex items-start justify-between gap-4 py-3">
                        <dt class="text-body-sm text-neutral-600">{{ $t('admin.generated.k_7d9f59442530') }}</dt>
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
                        <dt class="text-body-sm text-neutral-600">{{ $t('admin.generated.k_b782482e13ad') }}</dt>
                        <dd class="text-right text-body-sm font-semibold text-primary-900">{{ topChannelLabel(ownerPulse.top_channel) }}</dd>
                    </div>
                </dl>
            </Card>
        </section>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
