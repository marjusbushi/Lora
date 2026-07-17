<script setup>
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({ settings: Object, toasts: Object });

const form = useForm({
    check_in_time: props.settings.check_in_time || '14:00',
    check_out_time: props.settings.check_out_time || '11:00',
});

function submit() {
    form.put(route('settings.booking-policies'), {
        preserveScroll: true,
        onSuccess: () => props.toasts?.success('Politikat e rezervimeve u ruajtën.'),
    });
}
</script>

<template>
    <Card>
        <template #header>
            <div>
                <h3 class="text-h4 text-primary-900">Rezervimet & politikat</h3>
                <p class="mt-1 text-body-sm text-neutral-500">Rregullat bazë operative për hyrjen dhe daljen e mysafirëve.</p>
            </div>
        </template>

        <form class="space-y-5" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <FormGroup label="Ora standarde e check-in" :error="form.errors.check_in_time" required>
                    <TextInput v-model="form.check_in_time" type="time" :error="form.errors.check_in_time" />
                </FormGroup>
                <FormGroup label="Ora standarde e check-out" :error="form.errors.check_out_time" required>
                    <TextInput v-model="form.check_out_time" type="time" :error="form.errors.check_out_time" />
                </FormGroup>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-body-sm text-neutral-600">
                Politikat e anulimit, parapagimit dhe no-show do të shtohen këtu kur të aktivizohet motori i politikave.
            </div>

            <div class="flex justify-end">
                <Button type="submit" :loading="form.processing">Ruaj politikat</Button>
            </div>
        </form>
    </Card>
</template>
