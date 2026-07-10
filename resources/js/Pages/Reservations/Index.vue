<script setup>
import { ref, computed, watch } from 'vue';
import { useForm, router, usePage, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import Select from '@/Components/UI/Select.vue';
import Textarea from '@/Components/UI/Textarea.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';
import ActionMenu from '@/Components/UI/ActionMenu.vue';
import { channelOptions } from '@/channels';
import ReservationCreateModal from '@/Components/Reservations/ReservationCreateModal.vue';
import MoveRoomModal from '@/Components/Reservations/MoveRoomModal.vue';
import ReservationEditModal from '@/Components/Reservations/ReservationEditModal.vue';
import { Eye, Pencil, Ban, ArrowRightLeft } from 'lucide-vue-next';

const menuItemClass = 'flex w-full items-center gap-2.5 px-3 py-2 text-left text-body-sm text-neutral-700 transition-colors hover:bg-neutral-50 no-underline';

const props = defineProps({
    reservations: Object,
    rooms: Array,
    guests: Array,
    filters: Object,
    stats: Object,
    channelFees: { type: Object, default: () => ({}) },
});

const toasts = ref(null);
const showCreateModal = ref(false);
const showEditModal = ref(false);
const selectedRes = ref(null);
const showMoveModal = ref(false);
const moveRes = ref(null);

const perms = usePage().props.auth.user?.permissions || [];
const canCreate = perms.includes('create_reservations');
const canUpdate = perms.includes('update_reservations');

const statusBadge = {
    pending: { variant: 'warning', label: 'Ne pritje' },
    confirmed: { variant: 'info', label: 'Konfirmuar' },
    checked_in: { variant: 'success', label: 'Brenda' },
    checked_out: { variant: 'neutral', label: 'Larguar' },
    cancelled: { variant: 'error', label: 'Anulluar' },
};

const statusFilterOptions = [
    { value: 'pending', label: 'Ne pritje' },
    { value: 'confirmed', label: 'Konfirmuar' },
    { value: 'checked_in', label: 'Brenda' },
    { value: 'checked_out', label: 'Larguar' },
    { value: 'cancelled', label: 'Anulluar' },
];

const roomOptions = props.rooms.map((r) => ({
    value: r.id,
    label: `${r.room_number} — ${r.room_type?.name} (€${r.room_type?.base_price})`,
}));

const guestOptions = computed(() => props.guests.map((g) => ({
    value: g.id,
    label: `${g.first_name} ${g.last_name}${g.phone ? ' · ' + g.phone : ''}`,
})));

const filterStatus = ref(props.filters?.status || '');
const searchQuery = ref(props.filters?.search || '');

function applyFilters() {
    const params = {};
    if (filterStatus.value) params.status = filterStatus.value;
    if (searchQuery.value) params.search = searchQuery.value;
    router.get(route('reservations.index'), params, { preserveState: true });
}

function clearFilters() {
    filterStatus.value = '';
    searchQuery.value = '';
    router.get(route('reservations.index'), {}, { preserveState: true });
}

function openEdit(res) {
    selectedRes.value = res;
    showEditModal.value = true;
}

function onReservationCreated() {
    toasts.value?.success('Rezervimi u krijua.');
}

function openMove(res) {
    moveRes.value = res;
    showMoveModal.value = true;
}
function onRoomMoved() {
    toasts.value?.success('Mysafiri u zhvendos.');
}

function onReservationUpdated() {
    toasts.value?.success('Rezervimi u perditesua.');
}

function doCheckIn(res) {
    router.post(route('reservations.check-in', res.id), {}, {
        preserveScroll: true,
        onSuccess: () => toasts.value?.success(`Check-in: ${res.guest?.first_name} ${res.guest?.last_name}`),
        onError: () => toasts.value?.error('Check-in deshtoi.'),
    });
}

function doCheckOut(res) {
    router.post(route('reservations.check-out', res.id), {}, {
        preserveScroll: true,
        onSuccess: () => toasts.value?.success(`Check-out: ${res.guest?.first_name} ${res.guest?.last_name}`),
        // A guest who still owes is blocked server-side — show why (the amount + "record payment first").
        onError: (errors) => toasts.value?.error(errors.settle_method || 'Check-out deshtoi.'),
    });
}

function doCancel(res) {
    if (!confirm('Je i sigurt qe deshiron te anulosh kete rezervim?')) return;
    router.post(route('reservations.cancel', res.id), {}, {
        preserveScroll: true,
        onSuccess: () => toasts.value?.success('Rezervimi u anulua.'),
    });
}

function formatDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('sq-AL', { day: '2-digit', month: 'short', year: 'numeric' });
}
</script>

<template>
    <AppLayout>
        <PageHeader
            title="Rezervimet"
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Rezervimet' }]"
        >
            <template #actions>
                <Link :href="route('reservations.calendar')" class="no-underline">
                    <Button variant="outline">📅 Kalendari</Button>
                </Link>
                <Button v-if="canCreate" variant="primary" @click="showCreateModal = true">+ Rezervim i ri</Button>
            </template>
        </PageHeader>

        <!-- Stats -->
        <div class="mt-6 grid grid-cols-2 sm:grid-cols-4 gap-3">
            <Card v-for="(val, key) in stats" :key="key">
                <div class="text-center">
                    <p class="text-h3 text-primary-900">{{ val }}</p>
                    <p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">
                        {{ key === 'total' ? 'Gjithsej' : statusBadge[key]?.label || key }}
                    </p>
                </div>
            </Card>
        </div>

        <!-- Filters -->
        <div class="mt-6 flex flex-wrap items-end gap-3">
            <div class="w-64">
                <TextInput v-model="searchQuery" placeholder="Kerko mysafir..." @keyup.enter="applyFilters" />
            </div>
            <div class="w-40">
                <Select v-model="filterStatus" :options="statusFilterOptions" placeholder="Statusi..." @change="applyFilters" />
            </div>
            <Button v-if="filterStatus || searchQuery" variant="ghost" size="sm" @click="clearFilters">Pastro</Button>
        </div>

        <!-- Table -->
        <div class="mt-6">
            <Card :padding="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200">
                        <thead class="bg-neutral-50">
                            <tr>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Mysafiri</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Dhoma</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Check-in</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Check-out</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Statusi</th>
                                <th class="px-5 py-3 text-right text-label text-neutral-600">Total</th>
                                <th class="px-5 py-3 text-right text-label text-neutral-600">Veprime</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="res in reservations.data" :key="res.id" class="hover:bg-neutral-50 transition-colors duration-100">
                                <td class="px-5 py-3">
                                    <p class="text-body-sm text-primary-900 font-medium">
                                        {{ res.guest?.first_name }} {{ res.guest?.last_name }}
                                    </p>
                                </td>
                                <td class="px-5 py-3 text-body-sm text-neutral-600">
                                    {{ res.room?.room_number }}
                                    <span class="text-neutral-400">{{ res.room?.room_type?.name }}</span>
                                </td>
                                <td class="px-5 py-3 text-body-sm text-neutral-700">{{ formatDate(res.check_in_date) }}</td>
                                <td class="px-5 py-3 text-body-sm text-neutral-700">{{ formatDate(res.check_out_date) }}</td>
                                <td class="px-5 py-3">
                                    <Badge :variant="statusBadge[res.status]?.variant" dot>
                                        {{ statusBadge[res.status]?.label }}
                                    </Badge>
                                </td>
                                <td class="px-5 py-3 text-right text-body-sm text-primary-900 font-medium">€{{ res.total_amount }}</td>
                                <td class="px-5 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <Button v-if="canUpdate && res.status === 'confirmed'" size="sm" variant="primary" @click="doCheckIn(res)">Check-in</Button>
                                        <Link v-if="canUpdate && res.status === 'checked_in'" :href="route('reservations.show', res.id)" class="no-underline">
                                            <Button size="sm" variant="secondary">Check-out</Button>
                                        </Link>
                                        <ActionMenu>
                                            <Link :href="route('reservations.show', res.id)" :class="menuItemClass">
                                                <Eye class="h-4 w-4 text-neutral-400" :stroke-width="1.75" /> Detaje
                                            </Link>
                                            <button v-if="canUpdate && !['checked_in','checked_out','cancelled'].includes(res.status)" type="button" :class="menuItemClass" @click="openEdit(res)">
                                                <Pencil class="h-4 w-4 text-neutral-400" :stroke-width="1.75" /> Edito
                                            </button>
                                            <button v-if="canUpdate && res.status === 'checked_in'" type="button" :class="menuItemClass" @click="openMove(res)">
                                                <ArrowRightLeft class="h-4 w-4 text-neutral-400" :stroke-width="1.75" /> Zhvendos dhomën
                                            </button>
                                            <button v-if="canUpdate && ['pending','confirmed'].includes(res.status)" type="button" :class="[menuItemClass, 'text-error-600']" @click="doCancel(res)">
                                                <Ban class="h-4 w-4 text-error-500" :stroke-width="1.75" /> Anulo
                                            </button>
                                        </ActionMenu>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="!reservations.data?.length" class="px-6 py-12 text-center">
                    <p class="text-body-sm text-neutral-500">Nuk ka rezervime.</p>
                </div>

                <!-- Pagination -->
                <div v-if="reservations.last_page > 1" class="flex items-center justify-between border-t border-neutral-200 bg-neutral-50 px-5 py-3">
                    <p class="text-small text-neutral-500">{{ reservations.from }}–{{ reservations.to }} nga {{ reservations.total }}</p>
                    <div class="flex gap-1">
                        <Button
                            v-for="link in reservations.links"
                            :key="link.label"
                            size="sm"
                            :variant="link.active ? 'primary' : 'ghost'"
                            :disabled="!link.url"
                            @click="link.url && router.get(link.url, {}, { preserveState: true })"
                            v-html="link.label"
                        />
                    </div>
                </div>
            </Card>
        </div>

        <!-- Create Modal — shared with the calendar view -->
        <ReservationCreateModal
            :show="showCreateModal"
            :rooms="rooms"
            :guests="guests"
            :channel-fees="channelFees"
            @close="showCreateModal = false"
            @created="onReservationCreated"
            @guest-created="toasts?.success('Mysafiri u shtua.')"
        />

        <!-- Edit Modal — shared with the calendar view -->
        <ReservationEditModal
            :show="showEditModal"
            :reservation="selectedRes"
            :rooms="rooms"
            :guests="guests"
            :channel-fees="channelFees"
            @close="showEditModal = false"
            @updated="onReservationUpdated"
        />

        <MoveRoomModal
            :show="showMoveModal"
            :reservation="moveRes"
            :rooms="rooms"
            @close="showMoveModal = false"
            @moved="onRoomMoved"
        />

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
