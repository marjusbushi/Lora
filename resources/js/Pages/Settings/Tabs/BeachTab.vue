<script setup>
import { translate } from '@/i18n';
import { useForm } from '@inertiajs/vue3';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import Select from '@/Components/UI/Select.vue';

const props = defineProps({ settings: Object, toasts: Object });

const form = useForm({
    booking_window_days: Number(props.settings?.booking_window_days ?? 10),
    season_start: props.settings?.season_start ?? '',
    season_end: props.settings?.season_end ?? '',
    payment_mode: props.settings?.payment_mode ?? 'both',
});

const paymentModeOptions = [
    { value: 'cash', label: translate('beach.settings.paymentCash') },
    { value: 'online', label: translate('beach.settings.paymentOnline') },
    { value: 'both', label: translate('beach.settings.paymentBoth') },
];

function submit() {
    form.transform((data) => ({
        ...data,
        season_start: data.season_start || null,
        season_end: data.season_end || null,
    })).put(route('settings.beach'), {
        preserveScroll: true,
        onSuccess: () => props.toasts?.success(translate('beach.settings.saved')),
    });
}
</script>

<template>
    <Card>
        <template #header>
            <div>
                <h3 class="text-h4 text-primary-900">{{ $t('beach.settings.title') }}</h3>
                <p class="text-small text-neutral-500 mt-0.5">{{ $t('beach.settings.subtitle') }}</p>
            </div>
        </template>

        <form class="space-y-5 max-w-xl" @submit.prevent="submit">
            <FormGroup :label="$t('beach.settings.windowLabel')" :error="form.errors.booking_window_days" required>
                <TextInput v-model="form.booking_window_days" type="number" min="1" max="365" :error="form.errors.booking_window_days" />
                <p class="text-small text-neutral-500 mt-1">{{ $t('beach.settings.windowHint') }}</p>
            </FormGroup>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormGroup :label="$t('beach.settings.seasonStart')" :error="form.errors.season_start">
                    <TextInput v-model="form.season_start" type="date" :error="form.errors.season_start" />
                </FormGroup>
                <FormGroup :label="$t('beach.settings.seasonEnd')" :error="form.errors.season_end">
                    <TextInput v-model="form.season_end" type="date" :error="form.errors.season_end" />
                </FormGroup>
            </div>
            <p class="text-small text-neutral-500 -mt-2">{{ $t('beach.settings.seasonHint') }}</p>

            <FormGroup :label="$t('beach.settings.paymentModeLabel')" :error="form.errors.payment_mode" required>
                <Select v-model="form.payment_mode" :options="paymentModeOptions" :error="form.errors.payment_mode" />
                <p class="text-small text-neutral-500 mt-1">{{ $t('beach.settings.paymentModeHint') }}</p>
            </FormGroup>

            <div class="flex justify-end">
                <Button variant="primary" :loading="form.processing" @click="submit">
                    {{ $t('beach.settings.save') }}
                </Button>
            </div>
        </form>
    </Card>
</template>
