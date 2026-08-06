<script setup>
import { translate } from '@/i18n';
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Checkbox from '@/Components/UI/Checkbox.vue';
import TextInput from '@/Components/UI/TextInput.vue';

const props = defineProps({ settings: Object, financial: Object, toasts: Object });

const form = useForm({
    direct_discount_enabled: props.settings.direct_discount_enabled ?? false,
    direct_discount_pct: props.settings.direct_discount_pct ?? 10,
    booking_genius_enabled: Boolean(props.settings.booking_genius_enabled),
    booking_genius_pct: props.settings.booking_genius_pct ?? 15,
    booking_mobile_enabled: Boolean(props.settings.booking_mobile_enabled),
    booking_mobile_pct: props.settings.booking_mobile_pct ?? 10,
    booking_preferred_enabled: Boolean(props.settings.booking_preferred_enabled),
    expedia_member_enabled: Boolean(props.settings.expedia_member_enabled),
    expedia_member_pct: props.settings.expedia_member_pct ?? 10,
    expedia_mobile_enabled: Boolean(props.settings.expedia_mobile_enabled),
    expedia_mobile_pct: props.settings.expedia_mobile_pct ?? 10,
    airbnb_host_fee_enabled: Boolean(props.settings.airbnb_host_fee_enabled),
    airbnb_host_fee_pct: props.settings.airbnb_host_fee_pct ?? 15,
});

function factor(discounts) {
    return discounts.reduce((value, discount) => value * (1 - Number(discount || 0) / 100), 1);
}
function summary(enabled) {
    const discounts = enabled.map(([on, pct]) => on ? pct : 0);
    const f = Math.max(0.01, factor(discounts));
    return {
        combined: (100 * (1 - f)).toFixed(2).replace(/\.00$/, ''),
        modifier: (100 * (1 / f - 1)).toFixed(2).replace(/\.00$/, ''),
        example: (85 / f).toFixed(2),
    };
}
const booking = computed(() => summary([
    [form.booking_genius_enabled, form.booking_genius_pct],
    [form.booking_mobile_enabled, form.booking_mobile_pct],
]));
const expedia = computed(() => summary([
    [form.expedia_member_enabled, form.expedia_member_pct],
    [form.expedia_mobile_enabled, form.expedia_mobile_pct],
]));
const airbnb = computed(() => summary([
    [form.airbnb_host_fee_enabled, form.airbnb_host_fee_pct],
]));
const bookingCommission = computed(() => Number(props.financial.channel_fees?.['booking.com'] || 0));

function submit() {
    form.put(route('settings.pricing-programs'), {
        preserveScroll: true,
        onSuccess: () => props.toasts?.success(translate('admin.generated.k_e96006e3db4f')),
    });
}
</script>

<template>
    <Card>
        <template #header>
            <div>
                <h3 class="text-h4 text-primary-900">{{ $t('admin.generated.k_1206ef200027') }}</h3>
                <p class="text-tiny text-neutral-500 mt-1">{{ $t('admin.generated.k_aedc165c7e8d') }}</p>
            </div>
        </template>

        <form class="space-y-6" @submit.prevent="submit">
            <div class="rounded-xl border border-success-200 bg-success-50/40 p-4 space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h4 class="text-body font-bold text-primary-900">{{ $t('admin.pricingPrograms.directTitle') }}</h4>
                        <p class="mt-1 text-tiny text-neutral-600">{{ $t('admin.pricingPrograms.directDescription') }}</p>
                    </div>
                    <span class="rounded-full bg-success-100 px-2.5 py-1 text-tiny font-bold text-success-800">
                        {{ $t('admin.pricingPrograms.recommended') }}
                    </span>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-lg bg-white p-3">
                        <Checkbox v-model="form.direct_discount_enabled" :label="$t('admin.pricingPrograms.directEnabled')" />
                    </div>
                    <div class="rounded-lg bg-white p-3">
                        <label class="mb-2 block text-tiny font-semibold text-neutral-700">{{ $t('admin.pricingPrograms.discountPercent') }}</label>
                        <div class="flex items-center gap-2">
                            <TextInput v-model="form.direct_discount_pct" type="number" min="0" max="50" step="0.5" :disabled="!form.direct_discount_enabled" />
                            <span class="text-body-sm text-neutral-500">%</span>
                        </div>
                    </div>
                </div>

                <p class="rounded-lg bg-white p-3 text-body-sm text-primary-900">
                    {{ $t('admin.pricingPrograms.directExample', { price: (100 * (1 - Number(form.direct_discount_enabled ? form.direct_discount_pct : 0) / 100)).toFixed(2) }) }}
                </p>
            </div>

            <div class="rounded-xl border border-neutral-200 p-4 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h4 class="text-body font-bold text-primary-900">{{ $t('admin.generated.k_d53cf4bf654c') }}</h4>
                        <p class="text-tiny text-neutral-500">{{ $t('admin.generated.k_86b55631b168') }}</p>
                    </div>
                    <span class="text-tiny font-bold rounded-full bg-info-50 text-info-700 px-2.5 py-1">{{ $t('admin.generated.k_9eab3b7aef0b') }}{{ booking.modifier }}%</span>
                </div>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div class="rounded-lg bg-neutral-50 p-3 space-y-2">
                        <Checkbox v-model="form.booking_genius_enabled" :label="$t('admin.generated.k_c8aab48b8a07')" />
                        <div class="flex items-center gap-2">
                            <TextInput v-model="form.booking_genius_pct" type="number" min="0" max="50" step="0.5" :disabled="!form.booking_genius_enabled" />
                            <span class="text-body-sm text-neutral-500">%</span>
                        </div>
                    </div>
                    <div class="rounded-lg bg-neutral-50 p-3 space-y-2">
                        <Checkbox v-model="form.booking_mobile_enabled" :label="$t('admin.generated.k_2c1d73fe6beb')" />
                        <div class="flex items-center gap-2">
                            <TextInput v-model="form.booking_mobile_pct" type="number" min="0" max="50" step="0.5" :disabled="!form.booking_mobile_enabled" />
                            <span class="text-body-sm text-neutral-500">%</span>
                        </div>
                    </div>
                </div>

                <Checkbox v-model="form.booking_preferred_enabled" :label="$t('admin.generated.k_39400b3795cf')" />
                <p class="text-tiny text-neutral-500">{{ $t('admin.generated.k_debb924ec9fc') }} {{ bookingCommission }}{{ $t('admin.generated.k_49df5ab25ec1') }}</p>
                <p class="text-body-sm text-primary-900 bg-accent-50 rounded-lg p-3">{{ $t('admin.generated.k_6fe08412089c') }}{{ booking.example }}{{ $t('admin.generated.k_ded025603eff') }} {{ booking.combined }}%.</p>
            </div>

            <div class="rounded-xl border border-neutral-200 p-4 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h4 class="text-body font-bold text-primary-900">{{ $t('admin.generated.k_d93b5b340790') }}</h4>
                        <p class="text-tiny text-neutral-500">{{ $t('admin.generated.k_9c7f3ab052ad') }}</p>
                    </div>
                    <span class="text-tiny font-bold rounded-full bg-info-50 text-info-700 px-2.5 py-1">{{ $t('admin.generated.k_9eab3b7aef0b') }}{{ expedia.modifier }}%</span>
                </div>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div class="rounded-lg bg-neutral-50 p-3 space-y-2">
                        <Checkbox v-model="form.expedia_member_enabled" :label="$t('admin.generated.k_54c5186b27e9')" />
                        <div class="flex items-center gap-2">
                            <TextInput v-model="form.expedia_member_pct" type="number" min="0" max="50" step="0.5" :disabled="!form.expedia_member_enabled" />
                            <span class="text-body-sm text-neutral-500">%</span>
                        </div>
                    </div>
                    <div class="rounded-lg bg-neutral-50 p-3 space-y-2">
                        <Checkbox v-model="form.expedia_mobile_enabled" :label="$t('admin.generated.k_2c1d73fe6beb')" />
                        <div class="flex items-center gap-2">
                            <TextInput v-model="form.expedia_mobile_pct" type="number" min="0" max="50" step="0.5" :disabled="!form.expedia_mobile_enabled" />
                            <span class="text-body-sm text-neutral-500">%</span>
                        </div>
                    </div>
                </div>
                <p class="text-body-sm text-primary-900 bg-accent-50 rounded-lg p-3">{{ $t('admin.generated.k_330136e374de') }}{{ expedia.example }}{{ $t('admin.generated.k_ded025603eff') }} {{ expedia.combined }}%.</p>
            </div>

            <div class="rounded-xl border border-neutral-200 p-4 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h4 class="text-body font-bold text-primary-900">{{ $t('admin.pricingPrograms.airbnbTitle') }}</h4>
                        <p class="text-tiny text-neutral-500">{{ $t('admin.pricingPrograms.airbnbDescription') }}</p>
                    </div>
                    <span class="text-tiny font-bold rounded-full bg-info-50 text-info-700 px-2.5 py-1">{{ $t('admin.generated.k_9eab3b7aef0b') }}{{ airbnb.modifier }}%</span>
                </div>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div class="rounded-lg bg-neutral-50 p-3 space-y-2">
                        <Checkbox v-model="form.airbnb_host_fee_enabled" :label="$t('admin.pricingPrograms.airbnbFeeLabel')" />
                        <div class="flex items-center gap-2">
                            <TextInput v-model="form.airbnb_host_fee_pct" type="number" min="0" max="50" step="0.5" :disabled="!form.airbnb_host_fee_enabled" />
                            <span class="text-body-sm text-neutral-500">%</span>
                        </div>
                    </div>
                </div>
                <p class="text-body-sm text-primary-900 bg-accent-50 rounded-lg p-3">{{ $t('admin.pricingPrograms.airbnbExample', { price: airbnb.example }) }}</p>
            </div>

            <div class="rounded-xl border border-warning-200 bg-warning-50 p-3 text-body-sm text-warning-800">
{{ $t('admin.generated.k_d233ed5effd0') }} </div>

            <div class="settings-actions">
                <Button type="submit" variant="primary" :loading="form.processing">{{ $t('admin.generated.k_c83df3c31df6') }}</Button>
            </div>
        </form>
    </Card>
</template>
