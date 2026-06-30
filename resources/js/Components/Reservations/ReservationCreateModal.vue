<script setup>
import { ref, computed, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import Modal from '@/Components/UI/Modal.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import Select from '@/Components/UI/Select.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import Textarea from '@/Components/UI/Textarea.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import Button from '@/Components/UI/Button.vue';
import { channelOptions } from '@/channels';
import { countryOptions } from '@/countries';

// Shared "new reservation" popup — identical on the list AND the calendar.
// The calendar passes `prefill` (room + dates from the clicked cell); the list
// passes none. Persons (adults + children) are capped by the room's capacity.
const props = defineProps({
    show: { type: Boolean, default: false },
    rooms: { type: Array, default: () => [] },
    guests: { type: Array, default: () => [] },
    channelFees: { type: Object, default: () => ({}) },
    prefill: { type: Object, default: null },
});

const emit = defineEmits(['close', 'created', 'guest-created']);

const perms = usePage().props.auth.user?.permissions || [];
const canCreateGuest = perms.includes('create_guests');

const guestOptions = computed(() =>
    props.guests.map((g) => ({
        value: g.id,
        label: `${g.first_name} ${g.last_name}${g.phone ? ' · ' + g.phone : ''}`,
    }))
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
    status: 'confirmed',
    adults: 1,
    children: 0,
    notes: '',
    channel: 'manual',
    total_amount: '',
});

// --- Capacity: cap adults + children by the chosen room's max_occupancy ---
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

// Keep persons within capacity when the room or adults change.
watch(
    () => [form.room_id, form.adults],
    () => {
        const cap = maxOccupancy.value;
        if (!cap) return;
        if (Number(form.adults) > cap) form.adults = cap;
        if (Number(form.adults) < 1) form.adults = 1;
        if (Number(form.adults) + Number(form.children) > cap) {
            form.children = Math.max(0, cap - Number(form.adults));
        }
    }
);

// --- Price + channel commission (live preview; the server is authoritative) ---
function basePriceOf(roomId) {
    const r = props.rooms.find((x) => Number(x.id) === Number(roomId));
    return Number(r?.room_type?.base_price) || 0;
}
function nightsBetween(ci, co) {
    if (!ci || !co) return 0;
    const d = Math.round((new Date(co) - new Date(ci)) / 86400000);
    return d > 0 ? d : 0;
}
function suggestedPrice() {
    return basePriceOf(form.room_id) * nightsBetween(form.check_in_date, form.check_out_date);
}
function feePct(channel) {
    return Number(props.channelFees?.[channel]) || 0;
}
const commission = computed(() => Math.round((Number(form.total_amount) || 0) * feePct(form.channel)) / 100);
const net = computed(() => (Number(form.total_amount) || 0) - commission.value);

// Auto-fill the price with room rate × nights, but stop overwriting once the
// user types a custom amount (value-based: keep filling while it still matches
// the last suggestion).
let lastSuggest = 0;
watch(
    () => [form.room_id, form.check_in_date, form.check_out_date],
    () => {
        const s = suggestedPrice();
        if (!form.total_amount || Number(form.total_amount) === lastSuggest) {
            form.total_amount = s || '';
        }
        lastSuggest = s;
    }
);

// --- Inline "new guest" (stays inside this modal) ---
const showNewGuest = ref(false);
const guestForm = useForm({ first_name: '', last_name: '', email: '', phone: '', nationality: '' });

function saveNewGuest() {
    const existingIds = new Set(props.guests.map((g) => g.id));
    guestForm.post(route('guests.store'), {
        preserveScroll: true,
        preserveState: true,
        only: ['guests'],
        onSuccess: () => {
            const created = props.guests.find((g) => !existingIds.has(g.id));
            if (created) form.guest_id = created.id;
            guestForm.reset();
            showNewGuest.value = false;
            emit('guest-created');
        },
    });
}

// --- Fresh state + prefill each time the popup opens ---
watch(
    () => props.show,
    (open) => {
        if (!open) return;
        form.reset();
        form.clearErrors();
        showNewGuest.value = false;
        guestForm.reset();
        guestForm.clearErrors();
        lastSuggest = 0;
        if (props.prefill) {
            form.room_id = props.prefill.room_id ?? '';
            form.check_in_date = props.prefill.check_in_date ?? '';
            form.check_out_date = props.prefill.check_out_date ?? '';
        }
    }
);

function submit() {
    form.post(route('reservations.store'), {
        onSuccess: () => {
            emit('created');
            emit('close');
            form.reset();
        },
    });
}
</script>

<template>
    <Modal :show="show" title="Rezervim i ri" max-width="lg" @close="emit('close')">
        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormGroup label="Mysafiri" :error="form.errors.guest_id" required>
                    <Select v-model="form.guest_id" :options="guestOptions" placeholder="Zgjidh mysafirin..." :error="form.errors.guest_id" />
                    <button v-if="canCreateGuest" type="button" class="mt-1.5 text-tiny text-accent-700 hover:text-accent-800" @click="showNewGuest = !showNewGuest">
                        {{ showNewGuest ? '− Mbyll' : '+ Mysafir i ri' }}
                    </button>
                </FormGroup>
                <FormGroup label="Dhoma" :error="form.errors.room_id" required>
                    <Select v-model="form.room_id" :options="roomOptions" placeholder="Zgjidh dhomen..." :error="form.errors.room_id" />
                </FormGroup>
                <FormGroup label="Check-in" :error="form.errors.check_in_date" required>
                    <DatePicker v-model="form.check_in_date" :error="form.errors.check_in_date" />
                </FormGroup>
                <FormGroup label="Check-out" :error="form.errors.check_out_date" required>
                    <DatePicker v-model="form.check_out_date" :error="form.errors.check_out_date" />
                </FormGroup>
                <FormGroup label="Te rritur" :error="form.errors.adults">
                    <Select v-model="form.adults" :options="adultsOptions" placeholder="" :error="form.errors.adults" />
                </FormGroup>
                <FormGroup label="Femije" :error="form.errors.children">
                    <Select v-model="form.children" :options="childrenOptions" placeholder="" :error="form.errors.children" />
                </FormGroup>
                <FormGroup label="Burimi" :error="form.errors.channel">
                    <Select v-model="form.channel" :options="channelOptions" :error="form.errors.channel" />
                </FormGroup>
                <FormGroup label="Cmimi (me fee)" :error="form.errors.total_amount">
                    <TextInput type="number" v-model="form.total_amount" min="0" step="0.01" placeholder="0.00" :error="form.errors.total_amount" />
                </FormGroup>
            </div>

            <p v-if="maxOccupancy" class="text-small text-neutral-500 -mt-1">
                Kapaciteti i kesaj dhome: <span class="font-medium text-neutral-700">{{ maxOccupancy }} persona</span> (te rritur + femije).
            </p>

            <!-- Inline new-guest panel (stays inside this modal) -->
            <div v-if="showNewGuest" class="rounded-lg border border-accent-200 bg-accent-50/40 p-4 space-y-3">
                <p class="text-label text-neutral-700">Shto nje mysafir te ri</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <FormGroup label="Emri" :error="guestForm.errors.first_name" required>
                        <TextInput v-model="guestForm.first_name" placeholder="Emri" :error="guestForm.errors.first_name" />
                    </FormGroup>
                    <FormGroup label="Mbiemri" :error="guestForm.errors.last_name" required>
                        <TextInput v-model="guestForm.last_name" placeholder="Mbiemri" :error="guestForm.errors.last_name" />
                    </FormGroup>
                    <FormGroup label="Email" :error="guestForm.errors.email">
                        <TextInput type="email" v-model="guestForm.email" placeholder="email (opsional)" :error="guestForm.errors.email" />
                    </FormGroup>
                    <FormGroup label="Telefon" :error="guestForm.errors.phone">
                        <TextInput v-model="guestForm.phone" placeholder="+355..." :error="guestForm.errors.phone" />
                    </FormGroup>
                    <FormGroup label="Kombesia" :error="guestForm.errors.nationality">
                        <Select v-model="guestForm.nationality" :options="countryOptions" placeholder="Zgjidh shtetin..." :error="guestForm.errors.nationality" />
                    </FormGroup>
                </div>
                <div class="flex justify-end gap-2">
                    <Button variant="outline" type="button" @click="showNewGuest = false">Anulo</Button>
                    <Button variant="primary" type="button" :loading="guestForm.processing" @click="saveNewGuest">Ruaj mysafirin</Button>
                </div>
            </div>

            <div class="rounded-lg bg-neutral-50 border border-neutral-100 px-4 py-2.5 flex items-center gap-x-6 gap-y-1 flex-wrap text-body-sm">
                <span class="text-neutral-500">Komisioni <span class="text-neutral-400">{{ feePct(form.channel) }}%</span>: <span class="text-neutral-900 font-medium">€{{ commission.toFixed(2) }}</span></span>
                <span class="text-neutral-500">Neto: <span class="text-accent-700 font-semibold">€{{ net.toFixed(2) }}</span></span>
            </div>
            <FormGroup label="Shenime">
                <Textarea v-model="form.notes" placeholder="Kerkesa speciale..." :rows="2" />
            </FormGroup>
        </form>
        <template #footer>
            <Button variant="outline" @click="emit('close')">Anulo</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">Krijo rezervim</Button>
        </template>
    </Modal>
</template>
