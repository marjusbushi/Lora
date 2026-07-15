<script setup>
import { translate } from '@/i18n';
import { router, useForm } from '@inertiajs/vue3';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Checkbox from '@/Components/UI/Checkbox.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';

const props = defineProps({ settings: Object, toasts: Object });

const names = {
    USD: 'Dollar amerikan', GBP: 'Paund britanik', ALL: 'Lek shqiptar',
    CHF: translate('admin.generated.k_1fe887297d57'), TRY: translate('admin.generated.k_7238cea5578a'), JPY: 'Jen japonez',
    CAD: 'Dollar kanadez', AUD: 'Dollar australian', SEK: translate('admin.generated.k_adf6cdb9ec22'), NOK: translate('admin.generated.k_5d5f07d62bf4'),
};

const form = useForm({
    enabled: Boolean(props.settings.enabled),
    api_key: '',
    clear_key: false,
    manual_all_rate: props.settings.fallback_all ?? '',
});

function submit() {
    form.put(route('settings.currencies'), {
        preserveScroll: true,
        onSuccess: () => {
            form.api_key = '';
            form.clear_key = false;
            props.toasts?.success(translate('admin.generated.k_076b6a4a30d9'));
        },
    });
}

function refresh() {
    router.post(route('settings.currencies.refresh'), {}, { preserveScroll: true });
}
</script>

<template>
    <Card>
        <template #header>
            <div>
                <h3 class="text-h4 text-primary-900">{{ $t('admin.generated.k_93d6d7a865bb') }}</h3>
                <p class="text-tiny text-neutral-500 mt-1">
{{ $t('admin.generated.k_16e1e9820e66') }} <b>{{ $t('admin.generated.k_7450cd181e97') }}</b>{{ $t('admin.generated.k_152d02c12735') }} <b>{{ $t('admin.generated.k_239be86c2620') }}</b> {{ $t('admin.generated.k_4e44b170c56c') }} </p>
            </div>
        </template>

        <div class="space-y-6">
            <div class="flex items-center gap-3">
                <Checkbox v-model="form.enabled" :label="$t('admin.generated.k_631f0fd03443')" />
                <span v-if="!form.enabled" class="text-tiny text-neutral-500">{{ $t('admin.generated.k_547524710a92') }}</span>
            </div>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-body-sm font-semibold text-primary-900 mb-1">{{ $t('admin.generated.k_22b71c81d956') }}</label>
                    <TextInput
                        v-model="form.api_key"
                        type="password"
                        class="w-full"
                        :placeholder="settings.configured ? 'I ruajtur: ' + settings.api_key_hint + $t('admin.generated.k_e5edb4f115c3') : $t('admin.generated.k_99e3a1432b0a')"
                        autocomplete="off"
                    />
                    <div v-if="settings.configured" class="mt-2">
                        <Checkbox v-model="form.clear_key" :label="$t('admin.generated.k_85b4ba588c33')" />
                    </div>
                </div>
                <div class="flex items-end gap-2">
                    <Button variant="secondary" :disabled="!settings.configured" @click="refresh">{{ $t('admin.generated.k_7630593e65f6') }}</Button>
                    <span v-if="settings.updated_at" class="text-tiny text-neutral-400 pb-2">{{ $t('admin.generated.k_12b49da83767') }} {{ settings.updated_at }}</span>
                </div>
            </div>

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

            <!-- rates table -->
            <div>
                <h4 class="text-tiny font-bold uppercase tracking-wide text-neutral-400 mb-2">{{ $t('admin.generated.k_03637753a041') }}</h4>
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
                                    <template v-if="settings.rates[code]">{{ settings.rates[code] }}</template>
                                    <span v-else-if="code === 'ALL' && settings.fallback_all" class="text-neutral-500" :title="$t('admin.generated.k_aa31eae61bb8')">{{ settings.fallback_all }} <span class="text-tiny">{{ $t('admin.generated.k_b296959996b5') }}</span></span>
                                    <span v-else class="text-neutral-300">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="flex justify-end">
                <Button :disabled="form.processing" @click="submit">{{ $t('admin.generated.k_3055b6598548') }}</Button>
            </div>
        </div>
    </Card>
</template>
