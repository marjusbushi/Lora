<script setup>
import { useForm } from '@inertiajs/vue3';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import Checkbox from '@/Components/UI/Checkbox.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import { CHANNELS } from '@/channels';

const props = defineProps({ settings: Object, toasts: Object });

// OTA channels that charge a commission (manual + direct are always 0%).
const feeChannels = CHANNELS.filter((c) => !['manual', 'direct'].includes(c.id));
const savedFees = props.settings.channel_fees || {};
const initialFees = {};
feeChannels.forEach((c) => { initialFees[c.id] = savedFees[c.id] ?? ''; });

const form = useForm({
    tax_rate: props.settings.tax_rate ?? 20,
    payment_methods: props.settings.payment_methods || ['cash', 'card', 'room_charge'],
    currency_symbol: props.settings.default_currency_symbol || '€',
    channel_fees: initialFees,
});

const allMethods = [
    { value: 'cash', label: 'Cash' },
    { value: 'card', label: 'Karte bankare' },
    { value: 'room_charge', label: 'Room Charge (ne folio)' },
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
        onSuccess: () => props.toasts?.success('Konfigurimet financiare u ruajten.'),
    });
}
</script>

<template>
    <Card>
        <template #header>
            <h3 class="text-h4 text-primary-900">Konfigurime Financiare</h3>
        </template>

        <form @submit.prevent="submit" class="space-y-5">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormGroup label="TVSH / Tax Rate (%)" :error="form.errors.tax_rate" required>
                    <TextInput type="number" v-model="form.tax_rate" min="0" max="100" step="0.5" :error="form.errors.tax_rate" />
                </FormGroup>
                <FormGroup label="Simboli i valutes" :error="form.errors.currency_symbol" required>
                    <TextInput v-model="form.currency_symbol" placeholder="€" maxlength="5" :error="form.errors.currency_symbol" />
                </FormGroup>
            </div>

            <FormGroup label="Metodat e pageses aktive" :error="form.errors.payment_methods">
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
                <p class="text-label text-neutral-700 mb-1">Komisionet e Kanaleve (%)</p>
                <p class="text-tiny text-neutral-400 mb-3">Komisioni qe mban secili kanal. Perdoret per te mbushur vete fee-ne te cdo rezervim (p.sh. Booking.com 12%). Lere bosh ose 0 nese nuk ka komision. Manual dhe Direkt jane gjithmone 0%.</p>
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
                <Button type="submit" variant="primary" :loading="form.processing">Ruaj ndryshimet</Button>
            </div>
        </form>
    </Card>
</template>
