<script setup>
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import { useForm } from '@inertiajs/vue3';
import { translate } from '@/i18n';

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
    hotelEmail: { type: String, default: '' },
    toasts: Object,
});

const form = useForm({
    email_new_reservations: props.settings.email_new_reservations ?? true,
});

function submit() {
    form.put(route('settings.notifications'), {
        preserveScroll: true,
        onSuccess: () => props.toasts?.success(translate('settingsTabs.notifications.saved')),
    });
}
</script>

<template>
    <Card>
        <template #header>
            <div>
                <h3 class="text-h4 text-primary-900">{{ $t('settingsTabs.notifications.title') }}</h3>
                <p class="mt-1 text-body-sm text-neutral-500">{{ $t('settingsTabs.notifications.subtitle') }}</p>
            </div>
        </template>

        <form class="space-y-5" @submit.prevent="submit">
            <label class="flex items-start justify-between gap-5 rounded-xl border border-neutral-200 p-4">
                <span>
                    <strong class="block text-body-sm text-primary-900">{{ $t('settingsTabs.notifications.newReservation') }}</strong>
                    <small class="mt-1 block text-small text-neutral-500">
                        {{ $t('settingsTabs.notifications.sendEmailDesc', { email: hotelEmail || $t('settingsTabs.notifications.hotelEmailFallback') }) }}
                    </small>
                </span>
                <input v-model="form.email_new_reservations" type="checkbox" class="mt-0.5 h-5 w-5 rounded border-neutral-300 text-accent-700 focus:ring-accent-600" />
            </label>

            <p v-if="!hotelEmail" class="rounded-lg border border-warning-200 bg-warning-50 px-4 py-3 text-body-sm text-warning-800">
                {{ $t('settingsTabs.notifications.missingEmailWarning') }}
            </p>

            <div class="settings-actions">
                <Button type="submit" :loading="form.processing">{{ $t('settingsTabs.notifications.save') }}</Button>
            </div>
        </form>
    </Card>
</template>
