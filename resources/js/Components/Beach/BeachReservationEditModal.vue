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
