<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/UI/Modal.vue';
import Button from '@/Components/UI/Button.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import Select from '@/Components/UI/Select.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    prefill: { type: Object, default: null },
    units: { type: Array, default: () => [] }, // {value, label, price}
});

const emit = defineEmits(['close']);

const form = useForm({
    beach_unit_id: '',
    start_date: '',
    end_date: '',
    guest_name: '',
    guest_phone: '',
    guest_email: '',
});

watch(
    () => props.show,
    (show) => {
        if (!show) return;
        form.clearErrors();
        // Pastrim i plotë eksplicit — reset() i useForm nuk mjafton mes hapjeve.
        form.beach_unit_id = props.prefill?.beach_unit_id ?? '';
        form.start_date = props.prefill?.start_date ?? '';
        form.end_date = props.prefill?.end_date ?? '';
        form.guest_name = '';
        form.guest_phone = '';
        form.guest_email = '';
    },
);

// Informativ — çmimi PËRFUNDIMTAR llogaritet gjithmonë në server.
const totalHint = computed(() => {
    const unit = props.units.find((u) => u.value === Number(form.beach_unit_id));
    if (!unit || !form.start_date || !form.end_date || form.end_date < form.start_date) return null;
    const days = Math.round((new Date(form.end_date) - new Date(form.start_date)) / 86400000) + 1;
    return { days, total: (days * unit.price).toFixed(2) };
});

function submit() {
    form.post(route('beach.reservations.store'), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <Modal :show="show" :title="$t('beach.calendar.newReservation')" @close="emit('close')">
        <form class="space-y-4" @submit.prevent="submit">
            <FormGroup :label="$t('beach.calendar.sunbed')" :error="form.errors.beach_unit_id" required>
                <Select v-model="form.beach_unit_id" :options="units" :placeholder="$t('beach.calendar.pickSunbed')" :error="form.errors.beach_unit_id" />
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
            <p v-if="totalHint" class="rounded-lg bg-primary-50 px-3 py-2 text-body-sm font-semibold text-primary-900">
                {{ $t('beach.calendar.totalHint', { days: totalHint.days, total: totalHint.total }) }}
            </p>
        </form>
        <template #footer>
            <Button variant="outline" @click="emit('close')">{{ $t('beach.setup.cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ $t('beach.setup.create') }}</Button>
        </template>
    </Modal>
</template>
