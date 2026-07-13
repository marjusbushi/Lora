<script setup>
import { ref, computed, watch } from 'vue';
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
import { countryOptions } from '@/countries';
import { Plus, X } from 'lucide-vue-next';

// Shared "new reservation" popup — identical on the list AND the calendar.
// One guest + dates + channel, then one or MORE rooms (a "+ Shto dhome"). Each
// room becomes its own reservation; when >1 they share a booking_group_id so the
// rooms can be managed together. The guest is registered once (not duplicated).
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
    props.guests
        .map((g) => ({
            value: g.id,
            label: `${g.first_name} ${g.last_name}${g.phone ? ' · ' + g.phone : ''}`,
        }))
        .sort((a, b) => a.label.localeCompare(b.label, 'sq'))
);
const roomOptions = computed(() =>
    props.rooms.map((r) => ({
        value: r.id,
        label: `${r.room_number} — ${r.room_type?.name}${r.room_type?.base_price ? ' (€' + r.room_type.base_price + ')' : ''}`,
    }))
);

let roomUid = 0;
function emptyRoom() {
    return { uid: roomUid++, room_id: '', adults: 1, children: 0, total_amount: '' };
}

const form = useForm({
    guest_id: '',
    check_in_date: '',
    check_out_date: '',
    status: 'confirmed',
    channel: 'direct',
    channel_ref: '',
    notes: '',
    rooms: [emptyRoom()],
});

// Per-row flag: once the user edits a price by hand, stop auto-filling it.
let priceTouched = [false];
// Per-row request counter — guards against a stale seasonal quote landing after a newer one.
let priceSeq = [];

// --- Capacity per room ---
function roomById(id) {
    const n = Number(id);
    return n ? props.rooms.find((r) => Number(r.id) === n) || null : null;
}
function maxOccFor(id) {
    return roomById(id)?.room_type?.max_occupancy ?? null;
}
function adultsOptionsFor(id) {
    const cap = maxOccFor(id) || 10;
    return Array.from({ length: cap }, (_, i) => ({ value: i + 1, label: String(i + 1) }));
}
function childrenOptionsFor(row) {
    const cap = maxOccFor(row.room_id) || 10;
    const remaining = Math.max(0, cap - (Number(row.adults) || 1));
    return Array.from({ length: remaining + 1 }, (_, i) => ({ value: i, label: String(i) }));
}

// --- Price + channel commission (live preview; the server is authoritative) ---
function basePriceOf(id) {
    return Number(roomById(id)?.room_type?.base_price) || 0;
}
function nights() {
    const ci = form.check_in_date;
    const co = form.check_out_date;
    if (!ci || !co) return 0;
    const d = Math.round((new Date(co) - new Date(ci)) / 86400000);
    return d > 0 ? d : 0;
}
function suggestedFor(id) {
    return basePriceOf(id) * nights();
}
function feePct(channel) {
    if (channel === 'direct') return 0;
    return Number(props.channelFees?.[channel]) || 0;
}

const totalAmount = computed(() => form.rooms.reduce((s, r) => s + (Number(r.total_amount) || 0), 0));
const commission = computed(() => Math.round(totalAmount.value * feePct(form.channel)) / 100);
const net = computed(() => totalAmount.value - commission.value);

function clampRow(i) {
    const row = form.rooms[i];
    const cap = maxOccFor(row.room_id);
    if (!cap) return;
    if (Number(row.adults) > cap) row.adults = cap;
    if (Number(row.adults) < 1) row.adults = 1;
    if (Number(row.adults) + Number(row.children) > cap) {
        row.children = Math.max(0, cap - Number(row.adults));
    }
}
// Auto-fill a row's price with the SEASONAL total for its room + the chosen dates.
// base_price×nights is only a placeholder shown instantly while the server quote
// (which accounts for seasons + rate overrides) is fetched.
function priceRow(i) {
    if (priceTouched[i]) return; // never clobber a manually-entered price
    const roomId = form.rooms[i].room_id;
    const ci = form.check_in_date;
    const co = form.check_out_date;
    if (!roomId || !ci || !co || nights() <= 0) {
        form.rooms[i].total_amount = '';
        return;
    }
    // Instant local estimate so the field is never blank while the quote loads.
    form.rooms[i].total_amount = suggestedFor(roomId) || '';
    const seq = (priceSeq[i] = (priceSeq[i] || 0) + 1);
    window.axios
        .get(route('reservations.quote'), { params: { room_id: roomId, check_in: ci, check_out: co } })
        .then(({ data }) => {
            const row = form.rooms[i];
            // Drop a stale response: row gone, room changed, a newer request fired, or the user typed a price.
            if (!row || row.room_id !== roomId || seq !== priceSeq[i] || priceTouched[i]) return;
            if (data && Number.isFinite(Number(data.total))) row.total_amount = Number(data.total);
        })
        .catch(() => { /* keep the local estimate if the quote fails */ });
}

function onRoomChange(i, val) {
    form.rooms[i].room_id = val;
    clampRow(i);
    priceTouched[i] = false; // a different room → re-suggest its price
    priceRow(i);
}
function onAdultsChange(i, val) {
    form.rooms[i].adults = val;
    clampRow(i);
}
function onPriceInput(i, val) {
    form.rooms[i].total_amount = val;
    priceTouched[i] = true;
}

function addRoom() {
    form.rooms.push(emptyRoom());
    priceTouched.push(false);
}
function removeRoom(i) {
    if (form.rooms.length <= 1) return;
    form.rooms.splice(i, 1);
    priceTouched.splice(i, 1);
}

// Recompute every row's suggested price when the (shared) dates change.
watch(
    () => [form.check_in_date, form.check_out_date],
    () => form.rooms.forEach((_, i) => priceRow(i))
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
        priceTouched = [false];
        if (props.prefill) {
            form.check_in_date = props.prefill.check_in_date ?? '';
            form.check_out_date = props.prefill.check_out_date ?? '';
            if (props.prefill.room_id) onRoomChange(0, props.prefill.room_id);
        }
    }
);

function submit() {
    form
        .transform((d) => ({
            ...d,
            rooms: d.rooms.map((r) => ({
                room_id: r.room_id,
                adults: r.adults,
                children: r.children,
                total_amount: r.total_amount === '' ? null : r.total_amount,
            })),
        }))
        .post(route('reservations.store-multi'), {
            onSuccess: () => {
                emit('created');
                emit('close');
                form.reset();
            },
        });
}
</script>

<template>
    <Modal :show="show" title="Rezervim i ri" max-width="2xl" @close="emit('close')">
        <form @submit.prevent="submit">
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_260px]">
                <div class="space-y-4">
                    <div>
                        <p class="text-tiny font-semibold uppercase tracking-[0.14em] text-accent-700">Të dhënat e rezervimit</p>
                        <p class="mt-1 text-body-sm text-neutral-500">Zgjidh mysafirin, burimin, datat dhe dhomën.</p>
                    </div>
            <!-- Shared: guest + dates + channel -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormGroup :label="$t('admin.generated.k_fd3481133d25')" :error="form.errors.guest_id" required>
                    <SearchableSelect v-model="form.guest_id" :options="guestOptions" :placeholder="$t('admin.generated.k_738e39ddf7e3')" :search-placeholder="$t('admin.generated.k_d9965f809f66')" :error="form.errors.guest_id" />
                    <button v-if="canCreateGuest" type="button" class="mt-1.5 text-tiny text-accent-700 hover:text-accent-800" @click="showNewGuest = !showNewGuest">
                        {{ showNewGuest ? $t('admin.generated.k_343301bf2715') : $t('admin.generated.k_857d0eeb0c90') }}
                    </button>
                </FormGroup>
                <FormGroup :label="$t('admin.generated.k_fdfb0b54ae04')" :error="form.errors.channel">
                    <Select v-model="form.channel" :options="channelOptions" :error="form.errors.channel" />
                </FormGroup>
                <FormGroup label="Referenca e kanalit" :error="form.errors.channel_ref">
                    <TextInput v-model="form.channel_ref" placeholder="p.sh. Booking #45218" :error="form.errors.channel_ref" />
                </FormGroup>
                <FormGroup label="Statusi" :error="form.errors.status">
                    <Select v-model="form.status" :options="[{ value: 'confirmed', label: 'Konfirmuar' }, { value: 'pending', label: 'Në pritje' }]" :error="form.errors.status" />
                </FormGroup>
                <FormGroup :label="$t('admin.generated.k_7eb4cdcb93e4')" :error="form.errors.check_in_date" required>
                    <DatePicker v-model="form.check_in_date" :error="form.errors.check_in_date" />
                </FormGroup>
                <FormGroup :label="$t('admin.generated.k_7f8bf0962d79')" :error="form.errors.check_out_date" required>
                    <DatePicker v-model="form.check_out_date" :error="form.errors.check_out_date" />
                </FormGroup>
            </div>

            <!-- Inline new-guest panel (stays inside this modal) -->
            <div v-if="showNewGuest" class="rounded-lg border border-accent-200 bg-accent-50/40 p-4 space-y-3">
                <p class="text-label text-neutral-700">{{ $t('admin.generated.k_a9f4d1906bfa') }}</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <FormGroup :label="$t('admin.generated.k_5aad3192c3c3')" :error="guestForm.errors.first_name" required>
                        <TextInput v-model="guestForm.first_name" :placeholder="$t('admin.generated.k_5aad3192c3c3')" :error="guestForm.errors.first_name" />
                    </FormGroup>
                    <FormGroup :label="$t('admin.generated.k_1487093f2402')" :error="guestForm.errors.last_name" required>
                        <TextInput v-model="guestForm.last_name" :placeholder="$t('admin.generated.k_1487093f2402')" :error="guestForm.errors.last_name" />
                    </FormGroup>
                    <FormGroup :label="$t('admin.generated.k_8c2719d64940')" :error="guestForm.errors.email">
                        <TextInput type="email" v-model="guestForm.email" :placeholder="$t('admin.generated.k_fbb06aa6a63d')" :error="guestForm.errors.email" />
                    </FormGroup>
                    <FormGroup :label="$t('admin.generated.k_7f0c2a42b868')" :error="guestForm.errors.phone">
                        <TextInput v-model="guestForm.phone" placeholder="+355..." :error="guestForm.errors.phone" />
                    </FormGroup>
                    <FormGroup :label="$t('admin.generated.k_fff375ddc834')" :error="guestForm.errors.nationality">
                        <Select v-model="guestForm.nationality" :options="countryOptions" :placeholder="$t('admin.generated.k_08ef11bb4742')" :error="guestForm.errors.nationality" />
                    </FormGroup>
                </div>
                <div class="flex justify-end gap-2">
                    <Button variant="outline" type="button" @click="showNewGuest = false">{{ $t('admin.generated.k_37bf3ada5d70') }}</Button>
                    <Button variant="primary" type="button" :loading="guestForm.processing" @click="saveNewGuest">{{ $t('admin.generated.k_2b2b0b52468a') }}</Button>
                </div>
            </div>

            <!-- Rooms (one reservation each) -->
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <p class="text-label text-neutral-700">{{ $t('admin.generated.k_4af5b1381793') }}</p>
                    <Button variant="outline" size="sm" type="button" @click="addRoom">
                        <Plus class="h-4 w-4 mr-1" :stroke-width="2" /> {{ $t('admin.generated.k_caf24c510534') }} </Button>
                </div>

                <div v-for="(row, i) in form.rooms" :key="row.uid" class="rounded-lg border border-neutral-200 p-3">
                    <div class="flex items-start gap-3">
                        <div class="flex-1 grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <FormGroup :label="$t('admin.generated.k_8619d7a60df6')" :error="form.errors[`rooms.${i}.room_id`]" required class="col-span-2">
                                <Select :model-value="row.room_id" :options="roomOptions" :placeholder="$t('admin.generated.k_d48d734ab86c')" :error="form.errors[`rooms.${i}.room_id`]" @update:model-value="(v) => onRoomChange(i, v)" />
                            </FormGroup>
                            <FormGroup :label="$t('admin.generated.k_daa5d0d601e1')">
                                <Select :model-value="row.adults" :options="adultsOptionsFor(row.room_id)" placeholder="" @update:model-value="(v) => onAdultsChange(i, v)" />
                            </FormGroup>
                            <FormGroup :label="$t('admin.generated.k_2d2922dbc74e')">
                                <Select v-model="row.children" :options="childrenOptionsFor(row)" placeholder="" />
                            </FormGroup>
                            <FormGroup :label="$t('admin.generated.k_cabb7b3cad73')" :error="form.errors[`rooms.${i}.total_amount`]" class="col-span-2">
                                <TextInput type="number" :model-value="row.total_amount" min="0" step="0.01" placeholder="0.00" @update:model-value="(v) => onPriceInput(i, v)" />
                            </FormGroup>
                            <p v-if="maxOccFor(row.room_id)" class="col-span-2 self-end pb-2 text-tiny text-neutral-500">
{{ $t('admin.generated.k_20f2bb97613f') }} {{ maxOccFor(row.room_id) }} {{ $t('admin.generated.k_c9b289316ea6') }} </p>
                        </div>
                        <button v-if="form.rooms.length > 1" type="button" class="mt-7 text-neutral-400 hover:text-error-600" :title="$t('admin.generated.k_5492d4e2fe2c')" @click="removeRoom(i)">
                            <X class="h-4 w-4" :stroke-width="2" />
                        </button>
                    </div>
                </div>
            </div>

            <p v-if="form.errors.rooms" class="text-small text-error-600 -mt-1">{{ form.errors.rooms }}</p>

            <!-- Totals -->
            <div class="rounded-lg bg-neutral-50 border border-neutral-100 px-4 py-2.5 flex items-center gap-x-6 gap-y-1 flex-wrap text-body-sm">
                <span class="text-neutral-500">{{ $t('admin.generated.k_1e69e5ea1627') }} <span class="text-neutral-900 font-medium">€{{ totalAmount.toFixed(2) }}</span></span>
                <span class="text-neutral-500">{{ $t('admin.generated.k_f2167a394c1f') }} <span class="text-neutral-400">{{ feePct(form.channel) }}%</span>: <span class="text-neutral-900 font-medium">€{{ commission.toFixed(2) }}</span></span>
                <span class="text-neutral-500">{{ $t('admin.generated.k_fc689ff2a9a7') }} <span class="text-accent-700 font-semibold">€{{ net.toFixed(2) }}</span></span>
            </div>

            <FormGroup :label="$t('admin.generated.k_2c38f71a5f9b')">
                <Textarea v-model="form.notes" :placeholder="$t('admin.generated.k_ac6bf44f81ce')" :rows="2" />
            </FormGroup>
                </div>

                <aside class="h-fit rounded-xl border border-neutral-200 bg-neutral-50 p-4 lg:sticky lg:top-0">
                    <p class="text-label font-semibold text-primary-900">Përmbledhja</p>
                    <div class="mt-4 space-y-3 text-body-sm">
                        <div class="flex justify-between gap-3"><span class="text-neutral-500">Qëndrimi</span><span class="text-right font-medium text-primary-900">{{ nights() || 0 }} net</span></div>
                        <div class="flex justify-between gap-3"><span class="text-neutral-500">Dhoma</span><span class="text-right font-medium text-primary-900">{{ form.rooms.length }}</span></div>
                        <div class="flex justify-between gap-3"><span class="text-neutral-500">Totali</span><span class="text-right font-semibold text-primary-900">€{{ totalAmount.toFixed(2) }}</span></div>
                        <div class="flex justify-between gap-3"><span class="text-neutral-500">Komisioni ({{ feePct(form.channel) }}%)</span><span class="text-right font-medium text-warning-700">− €{{ commission.toFixed(2) }}</span></div>
                        <div class="flex justify-between gap-3 border-t border-neutral-200 pt-3"><span class="font-semibold text-primary-900">Neto</span><span class="text-h4 text-accent-700">€{{ net.toFixed(2) }}</span></div>
                    </div>
                    <div class="mt-4 rounded-lg border border-info-200 bg-info-50 px-3 py-2.5 text-small leading-relaxed text-info-800">
                        Disponueshmëria dhe çmimi sezonal kontrollohen përsëri gjatë ruajtjes.
                    </div>
                </aside>
            </div>
        </form>
        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ $t('admin.generated.k_37bf3ada5d70') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ $t('admin.generated.k_a200aa0ff0bb') }}</Button>
        </template>
    </Modal>
</template>
