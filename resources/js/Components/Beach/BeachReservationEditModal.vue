<script setup>
import { computed, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { translate } from '@/i18n';
import Modal from '@/Components/UI/Modal.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import Select from '@/Components/UI/Select.vue';

const props = defineProps({
    reservation: { type: Object, default: null },
    units: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);

const show = computed(() => props.reservation !== null);

const form = useForm({
    beach_unit_id: '',
    start_date: '',
    end_date: '',
    guest_name: '',
    guest_phone: '',
    guest_email: '',
    status: 'confirmed',
});

watch(
    () => props.reservation,
    (reservation) => {
        if (!reservation) return;
        form.clearErrors();
        form.beach_unit_id = reservation.beach_unit_id;
        form.start_date = reservation.start_date;
        form.end_date = reservation.end_date;
        form.guest_name = reservation.guest_name;
        form.guest_phone = reservation.guest_phone;
        form.guest_email = reservation.guest_email ?? '';
        form.status = reservation.status;
    },
);

const statusOptions = computed(() => [
    { value: 'pending', label: translate('beach.calendar.statusPending') },
    { value: 'confirmed', label: translate('beach.calendar.statusConfirmed') },
]);

function submit() {
    form.put(route('beach.reservations.update', props.reservation.id), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}

function cancelReservation() {
    if (!confirm(translate('beach.calendar.cancelConfirm', { name: props.reservation.guest_name }))) return;
    router.post(route('beach.reservations.cancel', props.reservation.id), {}, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}

// Pagesa e marrë në plazh — një klik, pa dalë nga modali i rezervimit.
function markPaid(method) {
    router.post(route('beach.reservations.mark-paid', props.reservation.id), { method }, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}

function unmarkPaid() {
    if (!confirm(translate('beach.calendar.unmarkConfirm'))) return;
    router.post(route('beach.reservations.unmark-paid', props.reservation.id), {}, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}

const methodLabel = computed(() => ({
    cash: translate('beach.calendar.methodCash'),
    card: translate('beach.calendar.methodCard'),
    online: translate('beach.calendar.methodOnline'),
}[props.reservation?.payment_method] ?? ''));
</script>

<template>
    <Modal :show="show" :title="$t('beach.calendar.editReservation')" @close="emit('close')">
        <form v-if="reservation" class="space-y-4" @submit.prevent="submit">
            <div class="flex items-center gap-2">
                <Badge :variant="reservation.status === 'confirmed' ? 'info' : 'warning'" size="sm">
                    {{ reservation.status === 'confirmed' ? $t('beach.calendar.statusConfirmed') : $t('beach.calendar.statusPending') }}
                </Badge>
                <Badge variant="neutral" size="sm">
                    {{ reservation.source === 'website' ? $t('beach.calendar.sourceWebsite') : $t('beach.calendar.sourceReception') }}
                </Badge>
                <span class="ml-auto text-body-sm font-bold text-primary-900">{{ reservation.total_amount }}</span>
            </div>

            <!-- Pagesa -->
            <div class="rounded-xl border border-neutral-200 bg-neutral-50 px-3 py-2.5">
                <div v-if="reservation.paid_at" class="flex flex-wrap items-center gap-2">
                    <span class="grid h-5 w-5 place-items-center rounded-full bg-emerald-500 text-[11px] font-black text-white">€</span>
                    <span class="text-body-sm font-semibold text-emerald-800">
                        {{ $t('beach.calendar.paidLine', { method: methodLabel, date: reservation.paid_at }) }}
                    </span>
                    <Button
                        v-if="reservation.payment_method !== 'online'"
                        size="sm"
                        variant="ghost"
                        class="ml-auto text-error-600"
                        @click="unmarkPaid"
                    >{{ $t('beach.calendar.unmarkPaid') }}</Button>
                </div>
                <div v-else class="flex flex-wrap items-center gap-2">
                    <span class="text-body-sm font-medium text-amber-800">{{ $t('beach.calendar.notPaidYet') }}</span>
                    <div class="ml-auto flex gap-1.5">
                        <Button size="sm" variant="success" @click="markPaid('cash')">{{ $t('beach.calendar.markPaidCash') }}</Button>
                        <Button size="sm" variant="secondary" @click="markPaid('card')">{{ $t('beach.calendar.markPaidCard') }}</Button>
                    </div>
                </div>
            </div>
            <FormGroup :label="$t('beach.calendar.sunbed')" :error="form.errors.beach_unit_id" required>
                <Select v-model="form.beach_unit_id" :options="units" :error="form.errors.beach_unit_id" />
            </FormGroup>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormGroup :label="$t('beach.calendar.startDate')" :error="form.errors.start_date" required>
                    <TextInput v-model="form.start_date" type="date" :error="form.errors.start_date" />
                </FormGroup>
                <FormGroup :label="$t('beach.calendar.endDate')" :error="form.errors.end_date" required>
                    <TextInput v-model="form.end_date" type="date" :error="form.errors.end_date" />
                </FormGroup>
            </div>
            <FormGroup :label="$t('beach.calendar.guestName')" :error="form.errors.guest_name" required>
                <TextInput v-model="form.guest_name" :error="form.errors.guest_name" />
            </FormGroup>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormGroup :label="$t('beach.calendar.guestPhone')" :error="form.errors.guest_phone" required>
                    <TextInput v-model="form.guest_phone" :error="form.errors.guest_phone" />
                </FormGroup>
                <FormGroup :label="$t('beach.calendar.guestEmail')" :error="form.errors.guest_email">
                    <TextInput v-model="form.guest_email" type="email" :error="form.errors.guest_email" />
                </FormGroup>
            </div>
            <FormGroup :label="$t('beach.calendar.status')" :error="form.errors.status">
                <Select v-model="form.status" :options="statusOptions" :error="form.errors.status" />
            </FormGroup>
        </form>
        <template #footer>
            <Button variant="ghost" class="mr-auto text-error-600" @click="cancelReservation">
                {{ $t('beach.calendar.cancelReservation') }}
            </Button>
            <Button variant="outline" @click="emit('close')">{{ $t('beach.setup.cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ $t('beach.setup.save') }}</Button>
        </template>
    </Modal>
</template>
