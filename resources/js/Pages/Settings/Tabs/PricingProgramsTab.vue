<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Checkbox from '@/Components/UI/Checkbox.vue';
import TextInput from '@/Components/UI/TextInput.vue';

const props = defineProps({ settings: Object, financial: Object, toasts: Object });

const form = useForm({
    booking_genius_enabled: Boolean(props.settings.booking_genius_enabled),
    booking_genius_pct: props.settings.booking_genius_pct ?? 15,
    booking_mobile_enabled: Boolean(props.settings.booking_mobile_enabled),
    booking_mobile_pct: props.settings.booking_mobile_pct ?? 10,
    booking_preferred_enabled: Boolean(props.settings.booking_preferred_enabled),
    expedia_member_enabled: Boolean(props.settings.expedia_member_enabled),
    expedia_member_pct: props.settings.expedia_member_pct ?? 10,
    expedia_mobile_enabled: Boolean(props.settings.expedia_mobile_enabled),
    expedia_mobile_pct: props.settings.expedia_mobile_pct ?? 10,
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
const bookingCommission = computed(() => Number(props.financial.channel_fees?.['booking.com'] || 0));

function submit() {
    form.put(route('settings.pricing-programs'), {
        preserveScroll: true,
        onSuccess: () => props.toasts?.success('Programet OTA u ruajtën.'),
    });
}
</script>

<template>
    <Card>
        <template #header>
            <div>
                <h3 class="text-h4 text-primary-900">Çmimet & programet OTA</h3>
                <p class="text-tiny text-neutral-500 mt-1">Vendos vetëm programet që hoteli ka aktivizuar realisht në Booking.com dhe Expedia.</p>
            </div>
        </template>

        <form class="space-y-6" @submit.prevent="submit">
            <div class="rounded-xl border border-neutral-200 p-4 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h4 class="text-body font-bold text-primary-900">Booking.com</h4>
                        <p class="text-tiny text-neutral-500">Uljet kombinohen me shumëzim, jo me mbledhje.</p>
                    </div>
                    <span class="text-tiny font-bold rounded-full bg-info-50 text-info-700 px-2.5 py-1">Aplikohet vetë: +{{ booking.modifier }}%</span>
                </div>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div class="rounded-lg bg-neutral-50 p-3 space-y-2">
                        <Checkbox v-model="form.booking_genius_enabled" label="Genius aktiv" />
                        <div class="flex items-center gap-2">
                            <TextInput v-model="form.booking_genius_pct" type="number" min="0" max="50" step="0.5" :disabled="!form.booking_genius_enabled" />
                            <span class="text-body-sm text-neutral-500">%</span>
                        </div>
                    </div>
                    <div class="rounded-lg bg-neutral-50 p-3 space-y-2">
                        <Checkbox v-model="form.booking_mobile_enabled" label="Mobile Price aktiv" />
                        <div class="flex items-center gap-2">
                            <TextInput v-model="form.booking_mobile_pct" type="number" min="0" max="50" step="0.5" :disabled="!form.booking_mobile_enabled" />
                            <span class="text-body-sm text-neutral-500">%</span>
                        </div>
                    </div>
                </div>

                <Checkbox v-model="form.booking_preferred_enabled" label="Preferred Partner aktiv" />
                <p class="text-tiny text-neutral-500">Preferred Partner nuk ul çmimin e klientit. Për fitimin neto përdoret komisioni Booking.com {{ bookingCommission }}% nga skeda Financiare.</p>
                <p class="text-body-sm text-primary-900 bg-accent-50 rounded-lg p-3">Shembull: synimi final €85 → dërgo Booking €{{ booking.example }}. Ulja maksimale e kombinuar: {{ booking.combined }}%.</p>
            </div>

            <div class="rounded-xl border border-neutral-200 p-4 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h4 class="text-body font-bold text-primary-900">Expedia</h4>
                        <p class="text-tiny text-neutral-500">Member Price dhe Mobile Price mbrohen veçmas nga Booking.</p>
                    </div>
                    <span class="text-tiny font-bold rounded-full bg-info-50 text-info-700 px-2.5 py-1">Aplikohet vetë: +{{ expedia.modifier }}%</span>
                </div>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div class="rounded-lg bg-neutral-50 p-3 space-y-2">
                        <Checkbox v-model="form.expedia_member_enabled" label="Member Price aktiv" />
                        <div class="flex items-center gap-2">
                            <TextInput v-model="form.expedia_member_pct" type="number" min="0" max="50" step="0.5" :disabled="!form.expedia_member_enabled" />
                            <span class="text-body-sm text-neutral-500">%</span>
                        </div>
                    </div>
                    <div class="rounded-lg bg-neutral-50 p-3 space-y-2">
                        <Checkbox v-model="form.expedia_mobile_enabled" label="Mobile Price aktiv" />
                        <div class="flex items-center gap-2">
                            <TextInput v-model="form.expedia_mobile_pct" type="number" min="0" max="50" step="0.5" :disabled="!form.expedia_mobile_enabled" />
                            <span class="text-body-sm text-neutral-500">%</span>
                        </div>
                    </div>
                </div>
                <p class="text-body-sm text-primary-900 bg-accent-50 rounded-lg p-3">Shembull: synimi final €85 → dërgo Expedia €{{ expedia.example }}. Ulja maksimale e kombinuar: {{ expedia.combined }}%.</p>
            </div>

            <div class="rounded-xl border border-warning-200 bg-warning-50 p-3 text-body-sm text-warning-800">
                Modifier-at vendosen te mapping-u i secilit kanal në Channex. Mos e rrit çmimin bazë të PMS-së, sepse do të rritej edhe website-i.
            </div>

            <div class="flex justify-end">
                <Button type="submit" variant="primary" :loading="form.processing">Ruaj programet OTA</Button>
            </div>
        </form>
    </Card>
</template>
