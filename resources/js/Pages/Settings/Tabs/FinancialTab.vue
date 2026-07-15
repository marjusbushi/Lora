<script setup>
import { translate } from '@/i18n';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import { CHANNELS } from '@/channels';

const props = defineProps({ settings: Object, toasts: Object });

// OTA channels that charge a commission (Direct is always 0%).
const feeChannels = CHANNELS.filter((c) => c.id !== 'direct');
const savedFees = props.settings.channel_fees || {};
const initialFees = {};
feeChannels.forEach((c) => { initialFees[c.id] = savedFees[c.id] ?? ''; });

const form = useForm({
    vat_status: props.settings.vat_status || '',
    payment_methods: props.settings.payment_methods || ['cash', 'card', 'room_charge'],
    currency_symbol: props.settings.default_currency_symbol || '€',
    channel_fees: initialFees,
});

const providerVatRegistered = computed(() => (
    typeof props.settings.provider_vat_registered === 'boolean'
        ? props.settings.provider_vat_registered
        : null
));
const selectedVatRegistered = computed(() => (
    form.vat_status === 'registered' ? true : form.vat_status === 'not_registered' ? false : null
));
const providerMismatch = computed(() => (
    providerVatRegistered.value !== null
    && selectedVatRegistered.value !== null
    && providerVatRegistered.value !== selectedVatRegistered.value
));

const allMethods = [
    { value: 'cash', label: translate('admin.generated.k_b70b7df57996') },
    { value: 'card', label: translate('admin.generated.k_dff2d7624625') },
    { value: 'room_charge', label: translate('admin.generated.k_1462e7507e49') },
];

function toggleMethod(method) {
    const idx = form.payment_methods.indexOf(method);
    if (idx >= 0) {
        if (form.payment_methods.length > 1) form.payment_methods.splice(idx, 1);
    } else {
        form.payment_methods.push(method);
    }
}

function submit() {
    form.put(route('settings.financial'), {
        onSuccess: () => props.toasts?.success(translate('admin.generated.k_3f05c482ff55')),
    });
}
</script>

<template>
    <Card>
        <template #header>
            <h3 class="text-h4 text-primary-900">{{ $t('admin.generated.k_8a2ae8721d11') }}</h3>
        </template>

        <form @submit.prevent="submit" class="space-y-5">
            <section class="rounded-xl border border-neutral-200 bg-neutral-50/60 p-4 sm:p-5">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h4 class="text-label text-primary-900">{{ $t('admin.vatSettings.title') }}</h4>
                        <p class="mt-1 text-body-sm text-neutral-500">{{ $t('admin.vatSettings.description') }}</p>
                    </div>
                    <span
                        v-if="providerVatRegistered !== null"
                        class="inline-flex w-fit rounded-full px-2.5 py-1 text-tiny font-semibold"
                        :class="providerMismatch ? 'bg-error-50 text-error-700' : 'bg-success-50 text-success-700'"
                    >
                        {{ $t('admin.vatSettings.providerStatus') }}:
                        {{ providerVatRegistered ? $t('admin.vatSettings.registered') : $t('admin.vatSettings.notRegistered') }}
                    </span>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <label
                        class="cursor-pointer rounded-xl border bg-white p-4 transition"
                        :class="form.vat_status === 'registered' ? 'border-accent-500 ring-2 ring-accent-500/15' : 'border-neutral-200 hover:border-neutral-300'"
                    >
                        <span class="flex items-start gap-3">
                            <input v-model="form.vat_status" type="radio" value="registered" class="mt-1 h-4 w-4 border-neutral-300 text-accent-600 focus:ring-accent-500" />
                            <span>
                                <strong class="block text-body-sm text-primary-900">{{ $t('admin.vatSettings.registered') }}</strong>
                                <span class="mt-1 block text-tiny text-neutral-500">{{ $t('admin.vatSettings.registeredHint') }}</span>
                                <span class="mt-3 flex flex-wrap gap-2">
                                    <span class="rounded-md bg-accent-50 px-2 py-1 text-tiny font-semibold text-accent-700">{{ $t('admin.vatSettings.accommodation') }} · 6%</span>
                                    <span class="rounded-md bg-neutral-100 px-2 py-1 text-tiny font-semibold text-neutral-700">{{ $t('admin.vatSettings.products') }} · 20%</span>
                                </span>
                            </span>
                        </span>
                    </label>

                    <label
                        class="cursor-pointer rounded-xl border bg-white p-4 transition"
                        :class="form.vat_status === 'not_registered' ? 'border-accent-500 ring-2 ring-accent-500/15' : 'border-neutral-200 hover:border-neutral-300'"
                    >
                        <span class="flex items-start gap-3">
                            <input v-model="form.vat_status" type="radio" value="not_registered" class="mt-1 h-4 w-4 border-neutral-300 text-accent-600 focus:ring-accent-500" />
                            <span>
                                <strong class="block text-body-sm text-primary-900">{{ $t('admin.vatSettings.notRegistered') }}</strong>
                                <span class="mt-1 block text-tiny text-neutral-500">{{ $t('admin.vatSettings.notRegisteredHint') }}</span>
                                <span class="mt-3 inline-flex rounded-md bg-neutral-100 px-2 py-1 text-tiny font-semibold text-neutral-700">{{ $t('admin.vatSettings.allLines') }} · 0%</span>
                            </span>
                        </span>
                    </label>
                </div>

                <p v-if="form.errors.vat_status" class="mt-2 text-tiny text-error-600">{{ form.errors.vat_status }}</p>
                <p v-else-if="providerMismatch" class="mt-3 rounded-lg bg-error-50 px-3 py-2 text-tiny text-error-700">
                    {{ $t('admin.vatSettings.mismatch') }}
                </p>
                <p v-else class="mt-3 text-tiny text-neutral-400">{{ $t('admin.vatSettings.annualTurnoverNote') }}</p>
            </section>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormGroup :label="$t('admin.generated.k_8b3ea4fc70a0')" :error="form.errors.currency_symbol" required>
                    <TextInput v-model="form.currency_symbol" placeholder="€" maxlength="5" :error="form.errors.currency_symbol" />
                </FormGroup>
            </div>

            <FormGroup :label="$t('admin.generated.k_bd40bacd65d9')" :error="form.errors.payment_methods">
                <div class="space-y-2">
                    <label v-for="method in allMethods" :key="method.value" class="flex items-center gap-2 text-body-sm text-neutral-700">
                        <input
                            type="checkbox"
                            :checked="form.payment_methods.includes(method.value)"
                            class="h-4 w-4 rounded border-neutral-300 text-accent-600"
                            @change="toggleMethod(method.value)"
                        />
                        {{ method.label }}
                    </label>
                </div>
            </FormGroup>

            <hr class="border-neutral-100" />

            <!-- Channel commissions: feed the auto-filled fee on each reservation -->
            <div>
                <p class="text-label text-neutral-700 mb-1">{{ $t('admin.generated.k_78545cb0396b') }}</p>
                <p class="text-tiny text-neutral-400 mb-3">{{ $t('admin.generated.k_61e5dedee9df') }}</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                    <div v-for="c in feeChannels" :key="c.id" class="flex items-center gap-3">
                        <span class="inline-flex items-center gap-2 text-body-sm text-neutral-700 w-32 shrink-0">
                            <span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{ backgroundColor: c.color }" /> {{ c.label }}
                        </span>
                        <TextInput type="number" v-model="form.channel_fees[c.id]" min="0" max="100" step="0.5" placeholder="0" class="flex-1" />
                        <span class="text-body-sm text-neutral-400">%</span>
                    </div>
                </div>
            </div>

            <div class="flex justify-end pt-2">
                <Button type="submit" variant="primary" :loading="form.processing">{{ $t('admin.generated.k_13d431875cf4') }}</Button>
            </div>
        </form>
    </Card>
</template>
