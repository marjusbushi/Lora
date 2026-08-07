<script setup>
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { translate } from '@/i18n';

const props = defineProps({ settings: Object, toasts: Object });

const form = useForm({
    check_in_time: props.settings.check_in_time || '14:00',
    check_out_time: props.settings.check_out_time || '11:00',
});

function submit() {
    form.put(route('settings.booking-policies'), {
        preserveScroll: true,
        onSuccess: () => props.toasts?.success(translate('settingsTabs.bookingPolicies.saved')),
    });
}
</script>

<template>
    <Card>
        <template #header>
            <div>
                <h3 class="text-h4 text-primary-900">{{ $t('settingsTabs.bookingPolicies.title') }}</h3>
                <p class="mt-1 text-body-sm text-neutral-500">{{ $t('settingsTabs.bookingPolicies.subtitle') }}</p>
            </div>
        </template>

        <form class="space-y-5" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <FormGroup :label="$t('settingsTabs.bookingPolicies.checkInLabel')" :error="form.errors.check_in_time" required>
                    <TextInput v-model="form.check_in_time" type="time" :error="form.errors.check_in_time" />
                </FormGroup>
                <FormGroup :label="$t('settingsTabs.bookingPolicies.checkOutLabel')" :error="form.errors.check_out_time" required>
                    <TextInput v-model="form.check_out_time" type="time" :error="form.errors.check_out_time" />
                </FormGroup>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-body-sm text-neutral-600">
                {{ $t('settingsTabs.bookingPolicies.futureNote') }}
            </div>

            <div class="settings-actions">
                <Button type="submit" :loading="form.processing">{{ $t('settingsTabs.bookingPolicies.save') }}</Button>
            </div>
        </form>
    </Card>
</template>
