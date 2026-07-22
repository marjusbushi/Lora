<script setup>
import { translate } from '@/i18n';
import { useForm } from '@inertiajs/vue3';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';

const props = defineProps({ settings: Object, toasts: Object });

const names = {
    USD: 'Dollar amerikan', GBP: 'Paund britanik', ALL: 'Lek shqiptar',
    CHF: translate('admin.generated.k_1fe887297d57'), TRY: translate('admin.generated.k_7238cea5578a'), JPY: 'Jen japonez',
    CAD: 'Dollar kanadez', AUD: 'Dollar australian', SEK: translate('admin.generated.k_adf6cdb9ec22'), NOK: translate('admin.generated.k_5d5f07d62bf4'),
};

const form = useForm({
    mode: props.settings.mode || 'automatic',
    manual_all_rate: props.settings.fallback_all ?? '',
});

function submit() {
    form.put(route('settings.currencies'), {
        preserveScroll: true,
        onSuccess: () => props.toasts?.success(translate('admin.generated.k_076b6a4a30d9')),
    });
}
</script>

<template>
    <Card>
        <template #header>
            <div>
                <h3 class="text-h4 text-primary-900">{{ $t('admin.generated.k_93d6d7a865bb') }}</h3>
                <p class="text-tiny text-neutral-500 mt-1">{{ $t('currencySettings.platformRatesNote') }}</p>
            </div>
        </template>

        <div class="space-y-6">
            <FormGroup :label="$t('currencySettings.modeLabel')" :error="form.errors.mode">
                <div class="space-y-2">
                    <label class="flex items-center gap-2.5 text-body-sm text-primary-900">
                        <input v-model="form.mode" type="radio" value="automatic" class="h-4 w-4 border-neutral-300 text-primary-700 focus:ring-primary-600">
                        {{ $t('currencySettings.modeAutomatic') }}
                    </label>
                    <label class="flex items-center gap-2.5 text-body-sm text-primary-900">
                        <input v-model="form.mode" type="radio" value="manual" class="h-4 w-4 border-neutral-300 text-primary-700 focus:ring-primary-600">
                        {{ $t('currencySettings.modeManual') }}
                    </label>
                </div>
                <p class="mt-1 text-tiny text-neutral-500">{{ $t('currencySettings.modeHelp') }}</p>
            </FormGroup>

            <FormGroup :label="$t('currencySettings.manualAllRate')" :error="form.errors.manual_all_rate">
                <TextInput
                    v-model="form.manual_all_rate"
                    type="number"
                    min="1"
                    max="1000"
                    step="0.0001"
                    placeholder="93.7837"
                    :error="form.errors.manual_all_rate"
                />
                <p class="mt-1 text-tiny text-neutral-500">{{ $t('currencySettings.manualAllRateHelp') }}</p>
            </FormGroup>

            <!-- rates table (read-only — platform-managed) -->
            <div>
                <div class="mb-2 flex items-center gap-2">
                    <h4 class="text-tiny font-bold uppercase tracking-wide text-neutral-400">{{ $t('currencySettings.currentRates') }}</h4>
                    <span v-if="settings.updated_at" class="text-tiny text-neutral-400">{{ $t('currencySettings.refreshedAt') }} {{ settings.updated_at }}</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-body-sm tabular-nums">
                        <thead><tr class="text-tiny uppercase tracking-wide text-neutral-400 text-left border-b border-neutral-100">
                            <th class="py-2 pr-3">{{ $t('admin.generated.k_3dcaac0a0952') }}</th><th class="py-2 pr-3">{{ $t('admin.generated.k_3a6933c3b8db') }}</th><th class="py-2 text-right">{{ $t('admin.generated.k_3989929d8b13') }}</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="code in settings.tracked" :key="code" class="border-b border-neutral-50 last:border-0">
                                <td class="py-2 pr-3 font-bold text-primary-900">{{ code }}</td>
                                <td class="py-2 pr-3 text-neutral-600">{{ names[code] || code }}</td>
                                <td class="py-2 text-right font-semibold">
                                    <span v-if="code === 'ALL' && form.mode === 'manual' && settings.fallback_all" :title="$t('currencySettings.modeManual')">{{ settings.fallback_all }} <span class="text-tiny text-neutral-500">{{ $t('currencySettings.manualBadge') }}</span></span>
                                    <template v-else-if="settings.rates[code]">{{ settings.rates[code] }}</template>
                                    <span v-else-if="code === 'ALL' && settings.fallback_all" class="text-neutral-500">{{ settings.fallback_all }}</span>
                                    <span v-else class="text-neutral-300">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="settings-actions">
                <Button :disabled="form.processing" @click="submit">{{ $t('admin.generated.k_3055b6598548') }}</Button>
            </div>
        </div>
    </Card>
</template>
