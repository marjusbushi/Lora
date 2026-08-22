<script setup>
import { translate } from '@/i18n';
import { Link, useForm } from '@inertiajs/vue3';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import Select from '@/Components/UI/Select.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';

const props = defineProps({ settings: Object, toasts: Object });

const form = useForm({
    name: props.settings.name || '',
    timezone: props.settings.timezone || 'Europe/Tirane',
    currency: props.settings.currency || 'EUR',
    pricing_currency: props.settings.pricing_currency || props.settings.currency || 'EUR',
    check_in_time: props.settings.check_in_time || '14:00',
    check_out_time: props.settings.check_out_time || '11:00',
    logo: null,
});

const currencyOptions = [
    { value: 'EUR', label: translate('admin.generated.k_282e7f385ece') },
    { value: 'ALL', label: translate('admin.generated.k_f80673073fb5') },
    { value: 'USD', label: translate('admin.generated.k_f57b24be53a0') },
    { value: 'GBP', label: translate('admin.generated.k_cfee1e2af2b7') },
    { value: 'CHF', label: 'CHF · Franga zvicerane' },
    { value: 'TRY', label: 'TRY · Lira turke' },
    { value: 'CAD', label: 'CAD · Dollari kanadez' },
    { value: 'AUD', label: 'AUD · Dollari australian' },
    { value: 'SEK', label: 'SEK · Krona suedeze' },
    { value: 'NOK', label: 'NOK · Krona norvegjeze' },
];

const timezoneOptions = [
    { value: 'Europe/Tirane', label: translate('admin.generated.k_5394fc28c1cc') },
    { value: 'Europe/Rome', label: translate('admin.generated.k_46464d96d4bd') },
    { value: 'Europe/London', label: translate('admin.generated.k_deeb0e96a544') },
    { value: 'Europe/Berlin', label: translate('admin.generated.k_6b4b97576461') },
];

function submit() {
    form.put(route('settings.hotel'), {
        onSuccess: () => props.toasts?.success(translate('admin.generated.k_a06f32868a8a')),
    });
}
</script>

<template>
    <Card>
        <template #header>
            <h3 class="text-h4 text-primary-900">{{ $t('admin.generated.k_93983af507a9') }}</h3>
        </template>

        <form @submit.prevent="submit" class="space-y-5">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormGroup :label="$t('admin.generated.k_b921fd1fc57c')" :error="form.errors.name" required>
                    <TextInput v-model="form.name" :placeholder="$t('admin.generated.k_e10513eac211')" :error="form.errors.name" />
                </FormGroup>
                <FormGroup :label="$t('currencySettings.baseCurrencyLabel')" :error="form.errors.currency" required>
                    <Select v-model="form.currency" :options="currencyOptions" :error="form.errors.currency" :disabled="settings.base_currency_locked" />
                    <p v-if="settings.base_currency_locked" class="mt-1 text-tiny text-neutral-400">{{ $t('currencySettings.baseCurrencyLocked') }}</p>
                </FormGroup>
                <FormGroup :label="$t('settingsTabs.hotel.pricingCurrencyLabel')" :error="form.errors.pricing_currency" required>
                    <Select v-model="form.pricing_currency" :options="currencyOptions" :error="form.errors.pricing_currency" />
                    <p class="mt-1 text-tiny text-neutral-400">{{ $t('settingsTabs.hotel.pricingCurrencyHint') }}</p>
                </FormGroup>
            </div>

            <!-- Kontakti/rrjetet/web-i menaxhohen VETËM te Web Studio (task #415) —
                 dy editorë mbi të njëjtët çelësa lejonin mbishkrim të heshtur. -->
            <p class="rounded-lg bg-neutral-50 px-3 py-2.5 text-sm text-neutral-500">
                {{ $t('webStudio.hotelTabContactHint') }}
                <Link href="/pms/web-studio" class="font-semibold text-accent-700 hover:text-accent-800">Web Studio →</Link>
            </p>

            <div class="max-w-sm">
                <FormGroup :label="$t('admin.generated.k_98e81c9f9021')" :error="form.errors.timezone" required>
                    <Select v-model="form.timezone" :options="timezoneOptions" :error="form.errors.timezone" />
                </FormGroup>
            </div>

            <div class="settings-actions">
                <Button type="submit" variant="primary" :loading="form.processing">{{ $t('admin.generated.k_0ffcd1142c0a') }}</Button>
            </div>
        </form>
    </Card>
</template>
