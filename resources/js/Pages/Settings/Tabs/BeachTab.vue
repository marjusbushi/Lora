<script setup>
import { ref, watch } from 'vue';
import { translate } from '@/i18n';
import { router, useForm } from '@inertiajs/vue3';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import Select from '@/Components/UI/Select.vue';

const props = defineProps({ settings: Object, accountMode: { type: String, default: 'shared' }, posOutlets: { type: Array, default: () => [] }, toasts: Object });

// Ku shkojnë paratë e plazhit — ruhet menjëherë, pavarësisht formës kryesore (si POS).
const accountMode = ref(props.accountMode);
watch(() => props.accountMode, (mode) => { accountMode.value = mode; });

function saveAccountMode(mode) {
    if (accountMode.value === mode) return;
    accountMode.value = mode;
    router.put(route('finance.accounts.beach-mode'), { mode }, {
        preserveScroll: true,
        onSuccess: () => props.toasts?.success(translate('beach.settings.accountModeSaved')),
        onError: () => {
            accountMode.value = props.accountMode;
            props.toasts?.error(translate('beach.settings.accountModeNotSaved'));
        },
    });
}

const form = useForm({
    booking_window_days: Number(props.settings?.booking_window_days ?? 10),
    season_start: props.settings?.season_start ?? '',
    season_end: props.settings?.season_end ?? '',
    payment_mode: props.settings?.payment_mode ?? 'both',
    pos_outlet_id: Number(props.settings?.pos_outlet_id ?? 0) || null,
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

            <FormGroup v-if="posOutlets.length" :label="$t('beach.settings.posOutletLabel')" :error="form.errors.pos_outlet_id">
                <select v-model="form.pos_outlet_id" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500">
                    <option :value="null">{{ $t('beach.settings.posOutletNone') }}</option>
                    <option v-for="outlet in posOutlets.filter((entry) => entry.is_active)" :key="outlet.id" :value="outlet.id">{{ outlet.name }}</option>
                </select>
                <p class="text-small text-neutral-500 mt-1">{{ $t('beach.settings.posOutletHint') }}</p>
            </FormGroup>

            <div class="flex justify-end">
                <Button variant="primary" :loading="form.processing" @click="submit">
                    {{ $t('beach.settings.save') }}
                </Button>
            </div>
        </form>

        <section class="mt-6 border-t border-neutral-100 pt-5">
            <h4 class="text-label text-primary-900">{{ $t('beach.settings.moneyTitle') }}</h4>
            <p class="mt-1 text-small text-neutral-500">{{ $t('beach.settings.moneyHint') }}</p>
            <div class="mt-3 grid gap-3 md:grid-cols-2">
                <label
                    v-for="option in [
                        { value: 'shared', title: $t('beach.settings.accountShared'), text: $t('beach.settings.accountSharedText') },
                        { value: 'split_cash', title: $t('beach.settings.accountSplitCash'), text: $t('beach.settings.accountSplitCashText') },
                        { value: 'split_bank', title: $t('beach.settings.accountSplitBank'), text: $t('beach.settings.accountSplitBankText') },
                        { value: 'split_all', title: $t('beach.settings.accountSplitAll'), text: $t('beach.settings.accountSplitAllText') },
                    ]"
                    :key="option.value"
                    class="cursor-pointer rounded-xl border p-4"
                    :class="accountMode === option.value ? 'border-accent-500 bg-accent-50 ring-2 ring-accent-500/10' : 'border-neutral-200'"
                >
                    <input :checked="accountMode === option.value" type="radio" name="beach-account-mode" :value="option.value" class="sr-only" @change="saveAccountMode(option.value)">
                    <strong class="text-body-sm text-primary-900">{{ option.title }}</strong>
                    <span class="mt-1 block text-tiny text-neutral-500">{{ option.text }}</span>
                </label>
            </div>
        </section>
    </Card>
</template>
