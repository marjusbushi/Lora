<script setup>
import { translate } from '@/i18n';
import { useForm } from '@inertiajs/vue3';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import Select from '@/Components/UI/Select.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';

const props = defineProps({ settings: Object, toasts: Object });

const form = useForm({
    name: props.settings.name || '',
    address: props.settings.address || '',
    phone: props.settings.phone || '',
    email: props.settings.email || '',
    timezone: props.settings.timezone || 'Europe/Tirane',
    currency: props.settings.currency || 'EUR',
    pricing_currency: props.settings.pricing_currency || props.settings.currency || 'EUR',
    check_in_time: props.settings.check_in_time || '14:00',
    check_out_time: props.settings.check_out_time || '11:00',
    hero_eyebrow_sq: props.settings.hero_eyebrow_sq || '',
    hero_eyebrow_en: props.settings.hero_eyebrow_en || '',
    hero_title_sq: props.settings.hero_title_sq || '',
    hero_title_en: props.settings.hero_title_en || '',
    hero_subtitle_sq: props.settings.hero_subtitle_sq || '',
    hero_subtitle_en: props.settings.hero_subtitle_en || '',
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
                <FormGroup :label="$t('admin.generated.k_4a1e97745177')" :error="form.errors.email">
                    <TextInput type="email" v-model="form.email" :placeholder="$t('admin.generated.k_bdce9dc634aa')" :error="form.errors.email" />
                </FormGroup>
                <FormGroup :label="$t('admin.generated.k_e9f7f48f4515')" :error="form.errors.phone">
                    <TextInput v-model="form.phone" placeholder="+355 4 234 5678" :error="form.errors.phone" />
                </FormGroup>
                <FormGroup :label="$t('currencySettings.baseCurrencyLabel')" :error="form.errors.currency" required>
                    <Select v-model="form.currency" :options="currencyOptions" :error="form.errors.currency" :disabled="settings.base_currency_locked" />
                    <p v-if="settings.base_currency_locked" class="mt-1 text-tiny text-neutral-400">{{ $t('currencySettings.baseCurrencyLocked') }}</p>
                </FormGroup>
                <FormGroup label="Monedha e çmimeve" :error="form.errors.pricing_currency" required>
                    <Select v-model="form.pricing_currency" :options="currencyOptions" :error="form.errors.pricing_currency" />
                    <p class="mt-1 text-tiny text-neutral-400">Smart Pricing, web, OTA dhe rezervimet.</p>
                </FormGroup>
            </div>

            <FormGroup :label="$t('admin.generated.k_90438e4e9c45')" :error="form.errors.address">
                <TextInput v-model="form.address" :placeholder="$t('admin.generated.k_5ad72e9b0edd')" :error="form.errors.address" />
            </FormGroup>

            <div class="max-w-sm">
                <FormGroup :label="$t('admin.generated.k_98e81c9f9021')" :error="form.errors.timezone" required>
                    <Select v-model="form.timezone" :options="timezoneOptions" :error="form.errors.timezone" />
                </FormGroup>
            </div>

            <hr class="border-neutral-100" />

            <!-- Hero text (per language) — shown at the top of the public Home page -->
            <div>
                <p class="text-label text-neutral-700 mb-1">{{ $t('admin.generated.k_832485474fd4') }}</p>
                <p class="text-tiny text-neutral-400 mb-3">{{ $t('admin.generated.k_3d931a245054') }}</p>
                <div class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <FormGroup :label="$t('admin.generated.k_061e2ab2dc4b')">
                            <TextInput v-model="form.hero_eyebrow_sq" :placeholder="$t('admin.generated.k_70e34701318d')" />
                        </FormGroup>
                        <FormGroup :label="$t('admin.generated.k_d3fdb9154f16')">
                            <TextInput v-model="form.hero_eyebrow_en" :placeholder="$t('admin.generated.k_f6108fc2962e')" />
                        </FormGroup>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <FormGroup :label="$t('admin.generated.k_7d17e28c7f52')">
                            <TextInput v-model="form.hero_title_sq" :placeholder="$t('admin.generated.k_a6f078d4077d')" />
                        </FormGroup>
                        <FormGroup :label="$t('admin.generated.k_2fdaf7ef3dfb')">
                            <TextInput v-model="form.hero_title_en" :placeholder="$t('admin.generated.k_e61301fd636f')" />
                        </FormGroup>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <FormGroup :label="$t('admin.generated.k_ada0dc508a31')">
                            <TextInput v-model="form.hero_subtitle_sq" :placeholder="$t('admin.generated.k_e9952a8aa367')" />
                        </FormGroup>
                        <FormGroup :label="$t('admin.generated.k_b622bce89a98')">
                            <TextInput v-model="form.hero_subtitle_en" :placeholder="$t('admin.generated.k_934aa0a11084')" />
                        </FormGroup>
                    </div>
                </div>
            </div>

            <div class="settings-actions">
                <Button type="submit" variant="primary" :loading="form.processing">{{ $t('admin.generated.k_0ffcd1142c0a') }}</Button>
            </div>
        </form>
    </Card>
</template>
