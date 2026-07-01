<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
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

const guestOptions = computed(() =>
    props.guests
        .map((g) => ({ value: g.id, label: `${g.first_name} ${g.last_name}${g.phone ? ' · ' + g.phone : ''}` }))
        .sort((a, b) => a.label.localeCompare(b.label, 'sq'))
);
const roomOptions = computed(() =>
    props.rooms.map((r) => ({
        value: r.id,
        label: `${r.room_number} — ${r.room_type?.name}${r.room_type?.base_price ? ' (€' + r.room_type.base_price + ')' : ''}`,
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
    channel: 'manual',
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
    return Number(props.channelFees?.[channel]) || 0;
}
const commission = computed(() => Math.round((Number(form.total_amount) || 0) * feePct(form.channel)) / 100);
const net = computed(() => (Number(form.total_amount) || 0) - commission.value);

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
        form.channel = r.channel || 'manual';
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
    <Modal :show="show" title="Edito rezervimin" max-width="lg" @close="emit('close')">
        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormGroup label="Mysafiri" :error="form.errors.guest_id" required>
                    <SearchableSelect v-model="form.guest_id" :options="guestOptions" placeholder="Zgjidh mysafirin..." search-placeholder="Kërko mysafir…" :error="form.errors.guest_id" />
                </FormGroup>
                <FormGroup label="Dhoma" :error="form.errors.room_id" required>
                    <Select v-model="form.room_id" :options="roomOptions" :error="form.errors.room_id" />
                </FormGroup>
                <FormGroup label="Check-in" :error="form.errors.check_in_date" required>
                    <DatePicker v-model="form.check_in_date" :error="form.errors.check_in_date" />
                </FormGroup>
                <FormGroup label="Check-out" :error="form.errors.check_out_date" required>
                    <DatePicker v-model="form.check_out_date" :error="form.errors.check_out_date" />
                </FormGroup>
                <FormGroup label="Te rritur">
                    <TextInput type="number" v-model="form.adults" min="1" max="10" />
                </FormGroup>
                <FormGroup label="Femije">
                    <TextInput type="number" v-model="form.children" min="0" max="10" />
                </FormGroup>
                <FormGroup label="Burimi" :error="form.errors.channel">
                    <Select v-model="form.channel" :options="channelOptions" :error="form.errors.channel" />
                </FormGroup>
                <FormGroup label="Cmimi (me fee)" :error="form.errors.total_amount">
                    <TextInput type="number" v-model="form.total_amount" min="0" step="0.01" placeholder="0.00" :error="form.errors.total_amount" />
                </FormGroup>
            </div>
            <div class="rounded-lg bg-neutral-50 border border-neutral-100 px-4 py-2.5 flex items-center gap-x-6 gap-y-1 flex-wrap text-body-sm">
                <span class="text-neutral-500">Komisioni <span class="text-neutral-400">{{ feePct(form.channel) }}%</span>: <span class="text-neutral-900 font-medium">€{{ commission.toFixed(2) }}</span></span>
                <span class="text-neutral-500">Neto: <span class="text-accent-700 font-semibold">€{{ net.toFixed(2) }}</span></span>
            </div>
            <FormGroup label="Shenime">
                <Textarea v-model="form.notes" :rows="2" />
            </FormGroup>
        </form>
        <template #footer>
            <Button variant="outline" @click="emit('close')">Anulo</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">Ruaj</Button>
        </template>
    </Modal>
</template>
