<script setup>
import { computed, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import Modal from '@/Components/UI/Modal.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import Select from '@/Components/UI/Select.vue';
import SearchableSelect from '@/Components/UI/SearchableSelect.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import Textarea from '@/Components/UI/Textarea.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import Button from '@/Components/UI/Button.vue';
import { channelOptions } from '@/channels';

// Shared "edit reservation" popup — used by the list AND the calendar detail popup.
const props = defineProps({
    show: { type: Boolean, default: false },
    reservation: { type: Object, default: null },
    rooms: { type: Array, default: () => [] },
    guests: { type: Array, default: () => [] },
    channelFees: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'updated']);
const currencyCode = usePage().props.settings?.pricing_currency || usePage().props.tenant?.currency || 'EUR';
const money = (value) => new Intl.NumberFormat('sq-AL', { style: 'currency', currency: currencyCode }).format(Number(value || 0));

const guestOptions = computed(() =>
    props.guests
        .map((g) => ({ value: g.id, label: `${g.first_name} ${g.last_name}${g.phone ? ' · ' + g.phone : ''}` }))
        .sort((a, b) => a.label.localeCompare(b.label, 'sq'))
);
const roomOptions = computed(() =>
    props.rooms.map((r) => ({
        value: r.id,
        label: `${r.room_number} — ${r.room_type?.name}${r.room_type?.base_price ? ' (' + money(r.room_type.base_price) + ')' : ''}`,
    }))
);

const form = useForm({
    room_id: '',
    guest_id: '',
    check_in_date: '',
    check_out_date: '',
    status: '',
    adults: 1,
    children: 0,
    notes: '',
    channel: 'direct',
    total_amount: '',
});

// --- Price + channel commission (live preview; server is authoritative) ---
function basePriceOf(roomId) {
    const r = props.rooms.find((x) => Number(x.id) === Number(roomId));
    return Number(r?.room_type?.base_price) || 0;
}
function nightsBetween(ci, co) {
    if (!ci || !co) return 0;
    const d = Math.round((new Date(co) - new Date(ci)) / 86400000);
    return d > 0 ? d : 0;
}
function feePct(channel) {
    if (channel === 'direct') return 0;
    return Number(props.channelFees?.[channel]) || 0;
}
const commission = computed(() => Math.round((Number(form.total_amount) || 0) * feePct(form.channel)) / 100);
const net = computed(() => (Number(form.total_amount) || 0) - commission.value);
const sourceLocked = computed(() => Boolean(props.reservation?.created_via && props.reservation.created_via !== 'staff'));

// Auto-fill price = rate × nights, but keep a manually-entered / OTA price.
let lastSuggest = 0;
watch(
    () => [form.room_id, form.check_in_date, form.check_out_date],
    () => {
        const s = basePriceOf(form.room_id) * nightsBetween(form.check_in_date, form.check_out_date);
        if (!form.total_amount || Number(form.total_amount) === lastSuggest) form.total_amount = s || '';
        lastSuggest = s;
    }
);

// --- Capacity: cap persons by the chosen room's max_occupancy (like the create popup) ---
const selectedRoom = computed(() => {
    const id = Number(form.room_id);
    return id ? props.rooms.find((r) => Number(r.id) === id) || null : null;
});
const maxOccupancy = computed(() => selectedRoom.value?.room_type?.max_occupancy ?? null);
const adultsOptions = computed(() => {
    const cap = maxOccupancy.value || 10;
    return Array.from({ length: cap }, (_, i) => ({ value: i + 1, label: String(i + 1) }));
});
const childrenOptions = computed(() => {
    const cap = maxOccupancy.value || 10;
    const remaining = Math.max(0, cap - (Number(form.adults) || 1));
    return Array.from({ length: remaining + 1 }, (_, i) => ({ value: i, label: String(i) }));
});
// Changing to a smaller room auto-reduces persons so the update can't silently fail on capacity.
watch(
    () => [form.room_id, form.adults],
    () => {
        const cap = maxOccupancy.value;
        if (!cap) return;
        if (Number(form.adults) > cap) form.adults = cap;
        if (Number(form.adults) < 1) form.adults = 1;
        if (Number(form.adults) + Number(form.children) > cap) form.children = Math.max(0, cap - Number(form.adults));
    }
);

function ymd(v) {
    return v ? String(v).split('T')[0] : '';
}

// Populate from the reservation each time the popup opens.
watch(
    () => props.show,
    (open) => {
        if (!open || !props.reservation) return;
        const r = props.reservation;
        form.clearErrors();
        form.room_id = r.room_id;
        form.guest_id = r.guest_id;
        form.check_in_date = ymd(r.check_in_date);
        form.check_out_date = ymd(r.check_out_date);
        form.status = r.status;
        form.adults = r.adults ?? 1;
        form.children = r.children ?? 0;
        form.notes = r.notes || '';
        form.channel = !r.channel || r.channel === 'manual' ? 'direct' : r.channel;
        form.total_amount = r.total_amount ?? '';
        // Baseline so a custom (OTA) price is not overwritten by the auto-fill.
        lastSuggest = basePriceOf(form.room_id) * nightsBetween(form.check_in_date, form.check_out_date);
    }
);

function submit() {
    if (!props.reservation) return;
    form.put(route('reservations.update', props.reservation.id), {
        onSuccess: () => {
            emit('updated');
            emit('close');
        },
    });
}
</script>

<template>
    <Modal :show="show" :title="$t('admin.generated.k_6df19ad54b93')" max-width="lg" @close="emit('close')">
        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormGroup :label="$t('admin.generated.k_5e655a9659b4')" :error="form.errors.guest_id" required>
                    <SearchableSelect v-model="form.guest_id" :options="guestOptions" :placeholder="$t('admin.generated.k_6183b5e3f433')" :search-placeholder="$t('admin.generated.k_02bdd23af041')" :error="form.errors.guest_id" />
                </FormGroup>
                <FormGroup :label="$t('admin.generated.k_e213eac3160a')" :error="form.errors.room_id" required>
                    <Select v-model="form.room_id" :options="roomOptions" :error="form.errors.room_id" />
                </FormGroup>
                <FormGroup :label="$t('admin.generated.k_679f26341e59')" :error="form.errors.check_in_date" required>
                    <DatePicker v-model="form.check_in_date" :error="form.errors.check_in_date" />
                </FormGroup>
                <FormGroup :label="$t('admin.generated.k_837b1fec5b7d')" :error="form.errors.check_out_date" required>
                    <DatePicker v-model="form.check_out_date" :error="form.errors.check_out_date" />
                </FormGroup>
                <FormGroup :label="$t('admin.generated.k_a613b28ef298')" :error="form.errors.adults">
                    <Select v-model="form.adults" :options="adultsOptions" placeholder="" :error="form.errors.adults" />
                </FormGroup>
                <FormGroup :label="$t('admin.generated.k_305cf5d36909')" :error="form.errors.children">
                    <Select v-model="form.children" :options="childrenOptions" placeholder="" :error="form.errors.children" />
                </FormGroup>
                <FormGroup :label="$t('admin.generated.k_77fb5e8d0ec8')" :error="form.errors.channel">
                    <Select v-model="form.channel" :options="channelOptions" :disabled="sourceLocked" :error="form.errors.channel" />
                    <p v-if="sourceLocked" class="mt-1 text-tiny text-neutral-400">{{ $t('admin.generated.k_d038abc79301') }}</p>
                </FormGroup>
                <FormGroup :label="$t('admin.generated.k_a6216a3caa4d')" :error="form.errors.total_amount">
                    <TextInput type="number" v-model="form.total_amount" min="0" step="0.01" placeholder="0.00" :error="form.errors.total_amount" />
                </FormGroup>
            </div>
            <div class="rounded-lg bg-neutral-50 border border-neutral-100 px-4 py-2.5 flex items-center gap-x-6 gap-y-1 flex-wrap text-body-sm">
                <span class="text-neutral-500">{{ $t('admin.generated.k_3369877794e7') }} <span class="text-neutral-400">{{ feePct(form.channel) }}%</span>: <span class="text-neutral-900 font-medium">{{ money(commission) }}</span></span>
                <span class="text-neutral-500">{{ $t('admin.generated.k_6ebbccfcf663') }} <span class="text-accent-700 font-semibold">{{ money(net) }}</span></span>
            </div>
            <FormGroup :label="$t('admin.generated.k_d393fa8ba7bb')">
                <Textarea v-model="form.notes" :rows="2" />
            </FormGroup>
        </form>
        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ $t('admin.generated.k_9198cebae099') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ $t('admin.generated.k_df2c1decffab') }}</Button>
        </template>
    </Modal>
</template>
