<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { AlertTriangle, ArrowRightLeft, Ban, CalendarDays, CalendarMinus, CalendarPlus, CalendarRange, ChevronLeft, ChevronRight, Eye, Pencil, Plus, Search, ShieldCheck, SlidersHorizontal } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Select from '@/Components/UI/Select.vue';
import ActionMenu from '@/Components/UI/ActionMenu.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';
import ReservationCreateModal from '@/Components/Reservations/ReservationCreateModal.vue';
import ReservationEditModal from '@/Components/Reservations/ReservationEditModal.vue';
import MoveRoomModal from '@/Components/Reservations/MoveRoomModal.vue';
import EarlyDepartureModal from '@/Components/Reservations/EarlyDepartureModal.vue';
import StayExtensionModal from '@/Components/Reservations/StayExtensionModal.vue';
import ReservationDetailsDrawer from './Components/ReservationDetailsDrawer.vue';
import { channelOptions } from '@/channels';
import { getIntlLocale } from '@/i18n';

const props = defineProps({
    reservations: Object,
    rooms: Array,
    guests: Array,
    filters: Object,
    stats: Object,
    latestReservationId: [Number, String],
    focusReservation: { type: Object, default: null },
    channelFees: { type: Object, default: () => ({}) },
    reconciliation: { type: Object, default: () => ({ open: 0, critical: 0, manual_candidates: 0 }) },
    hotelToday: { type: String, default: '' },
});

const perms = usePage().props.auth.user?.permissions || [];
const currencyCode = usePage().props.tenant?.currency || 'EUR';
const canCreate = perms.includes('create_reservations');
const canUpdate = perms.includes('update_reservations');
const menuItemClass = 'flex w-full items-center gap-2.5 px-3 py-2 text-left text-body-sm text-neutral-700 transition-colors hover:bg-neutral-50 no-underline';

const statusMeta = {
    pending: { label: 'Në pritje', variant: 'warning' },
    confirmed: { label: 'Konfirmuar', variant: 'info' },
    checked_in: { label: 'Në hotel', variant: 'success' },
    checked_out: { label: 'Përfunduar', variant: 'neutral' },
    cancelled: { label: 'Anuluar', variant: 'error' },
};
const statusOptions = Object.entries(statusMeta).map(([value, meta]) => ({ value, label: meta.label }));
const sortOptions = [
    { value: 'latest', label: 'Më të fundit' },
    { value: 'checkin', label: 'Sipas check-in' },
    { value: 'checkout', label: 'Sipas check-out' },
];
const perPageOptions = [25, 50, 100].map((value) => ({ value, label: String(value) }));
const channelLabels = Object.fromEntries(channelOptions.map((option) => [option.value, option.label]));

const toasts = ref(null);
const pageUrl = new URL(usePage().url, 'http://localhost');
const showCreateModal = ref(canCreate && pageUrl.searchParams.get('new') === '1');
const showEditModal = ref(false);
const showMoveModal = ref(false);
const showEarlyDepartureModal = ref(false);
const showStayExtensionModal = ref(false);
const selectedRes = ref(null);
const moveRes = ref(null);
const earlyDepartureRes = ref(null);
const stayExtensionRes = ref(null);
const details = ref(props.focusReservation);
const filterStatus = ref(props.filters?.status || '');
const searchQuery = ref(props.filters?.search || '');
const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');
const perPage = ref(Number(props.filters?.per_page || props.reservations?.per_page || 25));
const sortBy = ref(props.filters?.sort || 'latest');

const totalOutstanding = computed(() => props.reservations.data.reduce((sum, row) => sum + Math.max(0, Number(row.outstanding_amount_base || 0)), 0));
const statCards = computed(() => [
    { label: 'Rezervime totale', value: props.stats.total, tone: 'text-primary-900' },
    { label: 'Mbërritje sot', value: props.stats.arrivals_today || 0, tone: 'text-info-700' },
    { label: 'Në hotel', value: props.stats.checked_in, tone: 'text-success-700' },
    { label: 'Për t’u arkëtuar në listë', value: money(totalOutstanding.value), tone: 'text-warning-700' },
]);

function listParams() {
    return Object.fromEntries(Object.entries({
        status: filterStatus.value || undefined,
        search: searchQuery.value.trim() || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
        per_page: Number(perPage.value),
        sort: sortBy.value,
    }).filter(([, value]) => value !== undefined && value !== ''));
}
let searchDebounce = null;
let lastAppliedSearch = searchQuery.value.trim();

function applyFilters() {
    clearTimeout(searchDebounce);
    lastAppliedSearch = searchQuery.value.trim();
    router.get(route('reservations.index'), listParams(), { preserveState: true, preserveScroll: true, replace: true });
}

// Live search: typing a guest name filters the list by itself — no Enter
// or Filtro click needed (both still work). Skips values already applied,
// so the props-sync watcher below never re-triggers a request.
watch(searchQuery, (value) => {
    const trimmed = value.trim();
    if (trimmed === lastAppliedSearch || trimmed === (props.filters?.search || '')) return;
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(applyFilters, 400);
});
function clearFilters() {
    filterStatus.value = '';
    searchQuery.value = '';
    dateFrom.value = '';
    dateTo.value = '';
    applyFilters();
}
function goToPage(url) {
    if (!url) return;
    const page = Number(new URL(url, window.location.origin).searchParams.get('page') || 1);
    router.get(route('reservations.index'), { ...listParams(), page }, { preserveState: true, preserveScroll: true });
}
function openDetails(reservation, push = true) {
    details.value = reservation;
    if (!push || typeof window === 'undefined') return;
    const url = new URL(window.location.href);
    url.searchParams.delete('new');
    url.searchParams.set('reservation_id', reservation.id);
    window.history.pushState(window.history.state, '', `${url.pathname}${url.search}`);
}
function closeDetails(push = true) {
    details.value = null;
    if (!push || typeof window === 'undefined') return;
    const url = new URL(window.location.href);
    url.searchParams.delete('reservation_id');
    window.history.pushState(window.history.state, '', `${url.pathname}${url.search}`);
}
function onPopState() {
    const id = Number(new URL(window.location.href).searchParams.get('reservation_id'));
    const focused = Number(props.focusReservation?.id) === id ? props.focusReservation : null;
    details.value = id ? props.reservations.data.find((row) => Number(row.id) === id) || focused : null;
}
function closeCreateModal() {
    showCreateModal.value = false;
    const url = new URL(window.location.href);
    url.searchParams.delete('new');
    window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}`);
}
function openEdit(reservation) {
    selectedRes.value = reservation;
    showEditModal.value = true;
}
function openMove(reservation) {
    moveRes.value = reservation;
    showMoveModal.value = true;
}
function openEarlyDeparture(reservation) {
    earlyDepartureRes.value = reservation;
    showEarlyDepartureModal.value = true;
}
function openStayExtension(reservation) {
    stayExtensionRes.value = reservation;
    showStayExtensionModal.value = true;
}
function isEarlyDeparturePlanned(reservation) {
    return Boolean(
        reservation?.early_departure_scheduled_at
        && !reservation?.early_departure_at
        && reservation?.original_check_out_date
    );
}
function canDepartEarly(reservation) {
    if (reservation?.status !== 'checked_in') return false;
    const contractualCheckOut = reservation.original_check_out_date || reservation.check_out_date;
    const earliestDeparture = new Date(`${reservation.check_in_date}T00:00:00Z`);
    earliestDeparture.setUTCDate(earliestDeparture.getUTCDate() + 1);

    return earliestDeparture.toISOString().slice(0, 10) < contractualCheckOut;
}
function doCheckIn(reservation) {
    router.post(route('reservations.check-in', reservation.id), {}, {
        preserveScroll: true,
        onSuccess: () => { closeDetails(false); toasts.value?.success(`Check-in: ${reservation.guest?.name || ''}`); },
        onError: (errors) => toasts.value?.error(errors.check_in || 'Check-in dështoi.'),
    });
}
function doCheckOut(reservation) {
    if (isEarlyDeparturePlanned(reservation)) {
        openEarlyDeparture(reservation);
        return;
    }

    router.post(route('reservations.check-out', reservation.id), {}, {
        preserveScroll: true,
        onSuccess: () => { closeDetails(false); toasts.value?.success(`Check-out: ${reservation.guest?.name || ''}`); },
        onError: (errors) => toasts.value?.error(errors.settle_method || 'Regjistro pagesën para check-out.'),
    });
}
function doCancel(reservation) {
    if (!confirm('Je i sigurt që dëshiron ta anulosh këtë rezervim?')) return;
    router.post(route('reservations.cancel', reservation.id), {}, { preserveScroll: true, onSuccess: () => toasts.value?.success('Rezervimi u anulua.') });
}
function formatDate(value) {
    if (!value) return '—';
    return new Date(`${value}T12:00:00`).toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short', year: 'numeric' });
}
function money(value, currency = currencyCode) {
    return new Intl.NumberFormat(getIntlLocale(), { style: 'currency', currency: currency || currencyCode }).format(Number(value || 0));
}
function isLatest(reservation) {
    return props.latestReservationId != null && Number(reservation.id) === Number(props.latestReservationId);
}

watch(() => props.filters, (filters) => {
    filterStatus.value = filters?.status || '';
    searchQuery.value = filters?.search || '';
    dateFrom.value = filters?.date_from || '';
    dateTo.value = filters?.date_to || '';
    perPage.value = Number(filters?.per_page || props.reservations?.per_page || 25);
    sortBy.value = filters?.sort || 'latest';
});
watch(() => props.focusReservation, (reservation) => { if (reservation) details.value = reservation; });
onMounted(() => window.addEventListener('popstate', onPopState));
onBeforeUnmount(() => window.removeEventListener('popstate', onPopState));
</script>

<template>
    <AppLayout>
        <PageHeader title="Rezervimet" :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Rezervimet' }]">
            <template #actions>
                <Link :href="route('reservations.calendar')" class="no-underline"><Button variant="outline"><CalendarDays class="mr-2 h-4 w-4" />Kalendari</Button></Link>
                <Button v-if="canCreate" variant="primary" @click="showCreateModal = true"><Plus class="mr-2 h-4 w-4" />Rezervim i ri</Button>
            </template>
        </PageHeader>
        <p class="mt-1 text-body-sm text-neutral-500">Menaxho qëndrimet, pagesat dhe veprimet operative nga një vend.</p>

        <div
            v-if="reconciliation.open"
            class="mt-5 flex flex-col gap-3 rounded-xl border px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
            :class="reconciliation.critical ? 'border-error-200 bg-error-50' : 'border-warning-200 bg-warning-50'"
        >
            <div class="flex items-start gap-3">
                <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0" :class="reconciliation.critical ? 'text-error-600' : 'text-warning-600'" />
                <div>
                    <p class="text-body-sm font-semibold" :class="reconciliation.critical ? 'text-error-900' : 'text-warning-900'">
                        {{ reconciliation.open }} diferenca OTA–PMS kërkojnë kontroll
                    </p>
                    <p class="mt-0.5 text-small" :class="reconciliation.critical ? 'text-error-700' : 'text-warning-700'">
                        <span v-if="reconciliation.manual_candidates">{{ reconciliation.manual_candidates }} kanë rezervime manuale të mundshme. </span>
                        Asnjë rezervim nuk është ndryshuar automatikisht.
                    </p>
                </div>
            </div>
            <Link :href="route('reservations.reconciliation')" class="shrink-0 no-underline">
                <Button size="sm" variant="outline">Kontrollo diferencat</Button>
            </Link>
        </div>
        <div v-else-if="reconciliation.last_checked_at" class="mt-5 flex items-center gap-2 text-small text-success-700">
            <ShieldCheck class="h-4 w-4" /> Kontrolli OTA–PMS është në rregull.
        </div>

        <div class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Card v-for="card in statCards" :key="card.label">
                <p class="text-small font-medium text-neutral-500">{{ card.label }}</p>
                <p class="mt-2 text-h3" :class="card.tone">{{ card.value }}</p>
            </Card>
        </div>

        <Card class="mt-6" :padding="false">
            <div class="flex flex-col gap-3 border-b border-neutral-200 px-5 py-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                    <div class="relative min-w-0 flex-1 lg:max-w-xl">
                        <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                        <input v-model="searchQuery" type="search" class="w-full rounded-lg border-neutral-200 py-2 pl-9 pr-3 text-body-sm placeholder:text-neutral-400 focus:border-accent-500 focus:ring-accent-500" placeholder="Kërko emër, telefon, email, dhomë ose #rezervimi" @keyup.enter="applyFilters" />
                    </div>
                    <div class="flex flex-wrap items-center gap-2 lg:ml-auto">
                        <div class="w-40"><Select v-model="filterStatus" :options="statusOptions" placeholder="Të gjitha statuset" @change="applyFilters" /></div>
                        <div class="w-44"><Select v-model="sortBy" :options="sortOptions" placeholder="" @change="applyFilters" /></div>
                    </div>
                </div>

                <div class="flex flex-col gap-3 rounded-xl border border-neutral-200 bg-neutral-50/70 p-3 lg:flex-row lg:items-end">
                    <div class="flex min-w-0 items-center gap-2 lg:mr-1">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-accent-700 shadow-sm ring-1 ring-neutral-200">
                            <CalendarRange class="h-4 w-4" />
                        </span>
                        <div>
                            <p class="text-body-sm font-semibold text-primary-900">Periudha e qëndrimit</p>
                            <p class="text-small text-neutral-500">Shfaq rezervimet që prekin intervalin.</p>
                        </div>
                    </div>
                    <div class="grid min-w-0 flex-1 grid-cols-1 gap-2 sm:grid-cols-2 lg:max-w-md">
                        <label class="block">
                            <span class="mb-1 block text-small font-medium text-neutral-600">Nga data</span>
                            <input v-model="dateFrom" type="date" :max="dateTo || undefined" class="w-full rounded-lg border-neutral-200 bg-white py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500" @change="applyFilters" />
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-small font-medium text-neutral-600">Deri më</span>
                            <input v-model="dateTo" type="date" :min="dateFrom || undefined" class="w-full rounded-lg border-neutral-200 bg-white py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500" @change="applyFilters" />
                        </label>
                    </div>
                    <div class="flex items-center gap-3 lg:ml-auto">
                        <Button variant="outline" @click="applyFilters"><SlidersHorizontal class="mr-2 h-4 w-4" />Filtro</Button>
                        <button v-if="filterStatus || searchQuery || dateFrom || dateTo" type="button" class="text-small font-semibold text-accent-700 hover:text-accent-800" @click="clearFilters">Pastro</button>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Mysafiri / rezervimi</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Dhoma</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Qëndrimi</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Burimi</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Statusi</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Financa</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Veprime</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="res in reservations.data" :key="res.id" tabindex="0" class="cursor-pointer transition hover:bg-accent-50/40 focus:bg-accent-50/50 focus:outline-none" :class="isLatest(res) && 'bg-accent-50/30'" @click="openDetails(res)" @keydown.enter="openDetails(res)" @keydown.space.prevent="openDetails(res)">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-2"><p class="font-semibold text-primary-900">{{ res.guest?.name || '—' }}</p><Badge v-if="isLatest(res)" variant="accent" size="sm">I ri</Badge></div>
                                <p class="mt-0.5 text-small text-neutral-400">#{{ res.id }}<span v-if="res.guest?.phone"> · {{ res.guest.phone }}</span></p>
                            </td>
                            <td class="px-5 py-3.5"><p class="font-medium text-primary-900">{{ res.room?.room_number || '—' }}</p><p class="text-small text-neutral-400">{{ res.room?.room_type?.name || '—' }}</p></td>
                            <td class="px-5 py-3.5">
                                <p class="text-body-sm text-neutral-700">{{ formatDate(res.check_in_date) }} → {{ formatDate(res.check_out_date) }}</p>
                                <p class="text-small text-neutral-400">{{ res.nights }} net · {{ res.adults }} të rritur<span v-if="res.children"> · {{ res.children }} fëmijë</span></p>
                                <p v-if="isEarlyDeparturePlanned(res)" class="mt-1 text-small font-semibold text-info-700">Largim i planifikuar · fillimisht {{ formatDate(res.original_check_out_date) }}</p>
                            </td>
                            <td class="px-5 py-3.5"><p class="text-body-sm font-medium capitalize text-neutral-700">{{ channelLabels[res.channel] || res.channel || 'Direct' }}</p><p v-if="res.channel_ref" class="text-small text-neutral-400">{{ res.channel_ref }}</p></td>
                            <td class="px-5 py-3.5"><Badge :variant="statusMeta[res.status]?.variant" dot>{{ statusMeta[res.status]?.label }}</Badge></td>
                            <td class="px-5 py-3.5 text-right"><p class="font-semibold text-primary-900">{{ money(res.gross_amount, res.currency) }}</p><p class="text-small" :class="res.outstanding_amount > 0 ? 'text-warning-700' : 'text-success-700'">{{ res.outstanding_amount > 0 ? `${money(res.outstanding_amount, res.currency)} mbetur` : 'Paguar' }}</p></td>
                            <td class="px-5 py-3.5 text-right" @click.stop>
                                <div class="flex items-center justify-end gap-1.5">
                                    <Button v-if="canUpdate && res.status === 'confirmed'" size="sm" variant="primary" @click="doCheckIn(res)">Check-in</Button>
                                    <Button v-else size="sm" variant="outline" @click="openDetails(res)"><Eye class="mr-1.5 h-4 w-4" />Detaje</Button>
                                    <ActionMenu>
                                        <Link :href="res.links.show" :class="menuItemClass"><Eye class="h-4 w-4 text-neutral-400" />Hap faqen</Link>
                                        <button v-if="canUpdate && !['checked_in','checked_out','cancelled'].includes(res.status)" type="button" :class="menuItemClass" @click="openEdit(res)"><Pencil class="h-4 w-4 text-neutral-400" />Ndrysho</button>
                                        <button v-if="canUpdate && res.status === 'checked_in'" type="button" :class="menuItemClass" @click="openMove(res)"><ArrowRightLeft class="h-4 w-4 text-neutral-400" />Ndrysho dhomën</button>
                                        <button v-if="canUpdate && res.status === 'checked_in' && !isEarlyDeparturePlanned(res)" type="button" :class="menuItemClass" @click="openStayExtension(res)"><CalendarPlus class="h-4 w-4 text-neutral-400" />Zgjat qëndrimin</button>
                                        <button v-if="canUpdate && canDepartEarly(res)" type="button" :class="menuItemClass" @click="openEarlyDeparture(res)"><CalendarMinus class="h-4 w-4 text-neutral-400" />{{ isEarlyDeparturePlanned(res) ? 'Menaxho largimin' : 'Largim i parakohshëm' }}</button>
                                        <button v-if="canUpdate && ['pending','confirmed'].includes(res.status)" type="button" :class="[menuItemClass, 'text-error-600']" @click="doCancel(res)"><Ban class="h-4 w-4 text-error-500" />Anulo</button>
                                    </ActionMenu>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="!reservations.data?.length" class="px-6 py-16 text-center"><p class="font-medium text-primary-900">Nuk u gjet asnjë rezervim</p><p class="mt-1 text-body-sm text-neutral-500">Ndrysho kërkimin ose filtrat.</p></div>
            <div v-if="reservations.total" class="flex flex-col gap-3 border-t border-neutral-200 bg-neutral-50 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3 text-small text-neutral-500"><div class="w-20"><Select v-model="perPage" :options="perPageOptions" placeholder="" @change="applyFilters" /></div><span>{{ reservations.from }}–{{ reservations.to }} nga {{ reservations.total }}</span></div>
                <div class="flex items-center gap-2"><Button size="sm" variant="outline" :disabled="!reservations.prev_page_url" @click="goToPage(reservations.prev_page_url)"><ChevronLeft class="h-4 w-4" />Para</Button><span class="min-w-16 text-center text-small text-neutral-500">{{ reservations.current_page }} / {{ reservations.last_page }}</span><Button size="sm" variant="outline" :disabled="!reservations.next_page_url" @click="goToPage(reservations.next_page_url)">Pas<ChevronRight class="h-4 w-4" /></Button></div>
            </div>
        </Card>

        <ReservationDetailsDrawer :reservation="details" :can-update="canUpdate" :hotel-today="hotelToday" @close="closeDetails" @edit="openEdit" @check-in="doCheckIn" @check-out="doCheckOut" @early-departure="openEarlyDeparture" @stay-extension="openStayExtension" />
        <ReservationCreateModal :show="showCreateModal" :rooms="rooms" :guests="guests" :channel-fees="channelFees" @close="closeCreateModal" @created="toasts?.success('Rezervimi u krijua me sukses.')" />
        <ReservationEditModal :show="showEditModal" :reservation="selectedRes" :rooms="rooms" :guests="guests" :channel-fees="channelFees" @close="showEditModal = false" @updated="toasts?.success('Rezervimi u përditësua.')" />
        <MoveRoomModal :show="showMoveModal" :reservation="moveRes" :rooms="rooms" @close="showMoveModal = false" @moved="toasts?.success('Dhoma u ndryshua.')" />
        <EarlyDepartureModal
            :show="showEarlyDepartureModal"
            :reservation="earlyDepartureRes"
            :hotel-today="hotelToday"
            @close="showEarlyDepartureModal = false"
            @completed="() => { closeDetails(false); toasts?.success('Plani i largimit u përditësua.') }"
        />
        <StayExtensionModal
            :show="showStayExtensionModal"
            :reservation="stayExtensionRes"
            :hotel-today="hotelToday"
            @close="showStayExtensionModal = false"
            @extended="() => { closeDetails(false); toasts?.success('Qëndrimi u zgjat; përditësimi i inventarit u dërgua te Channex.') }"
        />
        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
