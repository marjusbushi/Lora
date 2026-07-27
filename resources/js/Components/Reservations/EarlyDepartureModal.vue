<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { AlertTriangle, CalendarClock, CalendarMinus, CheckCircle2, CreditCard, RotateCcw } from 'lucide-vue-next';
import Modal from '@/Components/UI/Modal.vue';
import Button from '@/Components/UI/Button.vue';
import Select from '@/Components/UI/Select.vue';
import { getIntlLocale } from '@/i18n';

const props = defineProps({
    show: { type: Boolean, default: false },
    reservation: { type: Object, default: null },
    hotelToday: { type: String, default: '' },
});

const emit = defineEmits(['close', 'completed']);
const page = usePage();
const currency = computed(() => props.reservation?.currency || page.props.tenant?.currency || 'EUR');
const methods = [
    { value: 'cash', label: 'Kesh' },
    { value: 'card', label: 'Kartë' },
];
const policies = [
    { value: 'waive', title: 'Pa penalitet', description: 'Hiqen netët e papërdorura.' },
    { value: 'partial', title: 'Penalitet i pjesshëm', description: 'Ngarkohet një pjesë e netëve të mbetura.' },
    { value: 'full', title: 'Pagesë e plotë', description: 'Totali fillestar nuk ndryshon.' },
];

const form = useForm({
    departure_date: '',
    policy: 'waive',
    penalty_amount: '',
    reason: '',
    settle_method: '',
    refund_method: '',
});
const cancellingPlan = ref(false);

function parseDate(value) {
    return value ? new Date(`${String(value).slice(0, 10)}T00:00:00Z`) : null;
}
function dateString(date) {
    return date?.toISOString().slice(0, 10) || '';
}
function addDays(value, days) {
    const date = parseDate(value);
    if (!date) return '';
    date.setUTCDate(date.getUTCDate() + days);
    return dateString(date);
}
function daysBetween(from, to) {
    const start = parseDate(from);
    const end = parseDate(to);
    if (!start || !end) return 0;
    return Math.max(0, Math.round((end - start) / 86400000));
}
function money(value) {
    return new Intl.NumberFormat(getIntlLocale(), {
        style: 'currency',
        currency: currency.value,
    }).format(Number(value || 0));
}
function formattedDate(value) {
    const date = parseDate(value);
    return date
        ? date.toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' })
        : '—';
}

const planned = computed(() => Boolean(
    props.reservation?.early_departure_scheduled_at
    && !props.reservation?.early_departure_at
    && props.reservation?.original_check_out_date
));
const contractualCheckOut = computed(() => (
    props.reservation?.original_check_out_date || props.reservation?.check_out_date || ''
));
const earliestDeparture = computed(() => addDays(props.reservation?.check_in_date, 1));
const latestDeparture = computed(() => addDays(contractualCheckOut.value, -1));
const isFuturePlan = computed(() => Boolean(
    form.departure_date && props.hotelToday && form.departure_date > props.hotelToday
));
const originalNights = computed(() => Math.max(
    1,
    daysBetween(props.reservation?.check_in_date, contractualCheckOut.value),
));
const usedNights = computed(() => daysBetween(props.reservation?.check_in_date, form.departure_date));
const originalRoomTotal = computed(() => Number(
    props.reservation?.early_departure_original_room_total
    ?? props.reservation?.total_amount
    ?? 0
));
const usedRoomTotal = computed(() => Math.round(
    originalRoomTotal.value * usedNights.value / originalNights.value * 100
) / 100);
const unusedRoomValue = computed(() => Math.max(0, Math.round(
    (originalRoomTotal.value - usedRoomTotal.value) * 100
) / 100));
const effectivePenalty = computed(() => {
    if (form.policy === 'full') return unusedRoomValue.value;
    if (form.policy === 'partial') return Math.max(0, Number(form.penalty_amount || 0));
    return 0;
});
const adjustedRoomTotal = computed(() => form.policy === 'full'
    ? originalRoomTotal.value
    : Math.round((usedRoomTotal.value + effectivePenalty.value) * 100) / 100);
const nonRoomNet = computed(() => (
    Number(props.reservation?.gross_amount || 0) - Number(props.reservation?.total_amount || 0)
));
const adjustedGross = computed(() => Math.round((adjustedRoomTotal.value + nonRoomNet.value) * 100) / 100);
const paid = computed(() => Number(props.reservation?.paid_amount || 0));
const balance = computed(() => Math.round((adjustedGross.value - paid.value) * 100) / 100);
const paymentDue = computed(() => balance.value > 0.005 ? balance.value : 0);
const refundDue = computed(() => balance.value < -0.005 ? Math.abs(balance.value) : 0);
const isOta = computed(() => props.reservation?.channel && props.reservation.channel !== 'direct');
const valid = computed(() => (
    form.departure_date
    && form.departure_date >= earliestDeparture.value
    && form.departure_date <= latestDeparture.value
    && form.reason.trim().length >= 3
    && (form.policy !== 'partial'
        || (effectivePenalty.value > 0 && effectivePenalty.value <= unusedRoomValue.value))
    && (isFuturePlan.value || !paymentDue.value || form.settle_method)
    && (isFuturePlan.value || !refundDue.value || form.refund_method)
));

watch(
    () => [props.show, props.reservation?.id],
    ([open]) => {
        if (!open) return;
        form.reset();
        form.clearErrors();
        const defaultDate = planned.value
            ? props.reservation.check_out_date
            : (props.hotelToday >= earliestDeparture.value && props.hotelToday <= latestDeparture.value
                ? props.hotelToday
                : earliestDeparture.value);
        form.departure_date = defaultDate;
        form.policy = props.reservation?.early_departure_policy || 'waive';
        form.penalty_amount = props.reservation?.early_departure_policy === 'partial'
            ? props.reservation?.early_departure_penalty_amount
            : '';
        form.reason = props.reservation?.early_departure_reason || '';
    },
);

function submit() {
    if (!props.reservation || !valid.value) return;

    form.transform((data) => ({
        ...data,
        penalty_amount: data.policy === 'partial' ? Number(data.penalty_amount) : null,
        settle_method: !isFuturePlan.value && paymentDue.value ? data.settle_method : null,
        refund_method: !isFuturePlan.value && refundDue.value ? data.refund_method : null,
    })).post(route('reservations.early-departure', props.reservation.id), {
        preserveScroll: true,
        onSuccess: () => {
            emit('completed', { mode: isFuturePlan.value ? 'scheduled' : 'completed' });
            emit('close');
            form.reset();
        },
    });
}

function cancelPlan() {
    if (!props.reservation || !planned.value || form.processing || cancellingPlan.value) return;
    if (!confirm('Ta anulosh planin dhe t’i rikthesh netët në inventarin e rezervuar?')) return;

    form.clearErrors();
    router.delete(route('reservations.early-departure-plan.cancel', props.reservation.id), {
        preserveScroll: true,
        onStart: () => { cancellingPlan.value = true; },
        onSuccess: () => {
            emit('completed', { mode: 'cancelled' });
            emit('close');
        },
        onFinish: () => { cancellingPlan.value = false; },
    });
}
</script>

<template>
    <Modal :show="show" title="Largim i parakohshëm" max-width="2xl" @close="emit('close')">
        <div v-if="reservation" class="space-y-5">
            <div class="flex items-start gap-3 rounded-xl border border-neutral-200 bg-neutral-50 p-4">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-white text-accent-700 shadow-sm ring-1 ring-neutral-200">
                    <CalendarMinus class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="font-semibold text-primary-900">{{ reservation.guest?.name || 'Mysafiri' }} · Dhoma {{ reservation.room?.room_number || '—' }}</p>
                    <p class="mt-1 text-small text-neutral-500">
                        {{ formattedDate(reservation.check_in_date) }} → {{ formattedDate(reservation.check_out_date) }}
                        · {{ originalNights }} net
                    </p>
                </div>
            </div>

            <div v-if="isOta" class="flex gap-3 rounded-xl border border-warning-200 bg-warning-50 p-3 text-body-sm text-warning-800">
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                <p>Ky rezervim vjen nga {{ reservation.channel }}. PMS do të lirojë inventarin, por ndryshimi kontraktual duhet kontrolluar edhe në kanalin OTA.</p>
            </div>

            <div v-if="isFuturePlan" class="flex gap-3 rounded-xl border border-info-200 bg-info-50 p-3 text-body-sm text-info-800">
                <CalendarClock class="mt-0.5 h-4 w-4 shrink-0" />
                <p>Mysafiri mbetet në hotel. Netët pas {{ formattedDate(form.departure_date) }} lirohen tani në PMS dhe Channex; checkout-i dhe pastrimi kryhen vetëm ditën e largimit.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1.5 block text-body-sm font-semibold text-primary-900">Data reale e largimit</span>
                    <input
                        v-model="form.departure_date"
                        type="date"
                        :min="earliestDeparture"
                        :max="latestDeparture"
                        class="w-full rounded-lg border-neutral-200 text-body-sm focus:border-accent-500 focus:ring-accent-500"
                    />
                    <span v-if="form.errors.departure_date" class="mt-1 block text-small text-error-600">{{ form.errors.departure_date }}</span>
                </label>
                <div class="rounded-xl border border-neutral-200 p-3">
                    <p class="text-small text-neutral-500">Qëndrimi i ri</p>
                    <p class="mt-1 font-semibold text-primary-900">{{ usedNights }} net</p>
                    <p class="text-small text-neutral-500">{{ originalNights - usedNights }} net të papërdorura</p>
                </div>
            </div>

            <fieldset>
                <legend class="mb-2 text-body-sm font-semibold text-primary-900">Politika financiare</legend>
                <div class="grid gap-2 sm:grid-cols-3">
                    <button
                        v-for="policy in policies"
                        :key="policy.value"
                        type="button"
                        class="rounded-xl border p-3 text-left transition"
                        :class="form.policy === policy.value
                            ? 'border-accent-500 bg-accent-50 ring-1 ring-accent-200'
                            : 'border-neutral-200 hover:border-neutral-300 hover:bg-neutral-50'"
                        @click="form.policy = policy.value"
                    >
                        <span class="flex items-center gap-2 font-semibold text-primary-900">
                            <CheckCircle2 v-if="form.policy === policy.value" class="h-4 w-4 text-accent-700" />
                            {{ policy.title }}
                        </span>
                        <span class="mt-1 block text-small text-neutral-500">{{ policy.description }}</span>
                    </button>
                </div>
            </fieldset>

            <label v-if="form.policy === 'partial'" class="block">
                <span class="mb-1.5 block text-body-sm font-semibold text-primary-900">Vlera e penalitetit</span>
                <div class="relative max-w-xs">
                    <input
                        v-model="form.penalty_amount"
                        type="number"
                        min="0.01"
                        :max="unusedRoomValue"
                        step="0.01"
                        class="w-full rounded-lg border-neutral-200 pr-16 text-body-sm focus:border-accent-500 focus:ring-accent-500"
                    />
                    <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-small font-medium text-neutral-500">{{ currency }}</span>
                </div>
                <span class="mt-1 block text-small text-neutral-500">Maksimumi: {{ money(unusedRoomValue) }}</span>
                <span v-if="form.errors.penalty_amount" class="mt-1 block text-small text-error-600">{{ form.errors.penalty_amount }}</span>
            </label>

            <div class="grid gap-3 rounded-xl border border-neutral-200 bg-neutral-50 p-4 sm:grid-cols-3">
                <div><p class="text-small text-neutral-500">Totali fillestar</p><p class="mt-1 font-semibold text-primary-900">{{ money(originalRoomTotal) }}</p></div>
                <div><p class="text-small text-neutral-500">Totali i ri i akomodimit</p><p class="mt-1 font-semibold text-accent-700">{{ money(adjustedRoomTotal) }}</p></div>
                <div><p class="text-small text-neutral-500">Fatura totale me shtesat</p><p class="mt-1 font-semibold text-primary-900">{{ money(adjustedGross) }}</p></div>
            </div>

            <div v-if="!isFuturePlan && (paymentDue || refundDue)" class="rounded-xl border p-4" :class="refundDue ? 'border-info-200 bg-info-50' : 'border-warning-200 bg-warning-50'">
                <div class="flex items-start gap-3">
                    <RotateCcw v-if="refundDue" class="mt-0.5 h-5 w-5 text-info-700" />
                    <CreditCard v-else class="mt-0.5 h-5 w-5 text-warning-700" />
                    <div class="flex-1">
                        <p class="font-semibold text-primary-900">
                            {{ refundDue ? `Rimbursim ${money(refundDue)}` : `Pagesë e mbetur ${money(paymentDue)}` }}
                        </p>
                        <p class="mt-1 text-small text-neutral-600">Bilanci duhet të mbyllet përpara checkout-it.</p>
                        <div class="mt-3 max-w-xs">
                            <Select
                                v-if="refundDue"
                                v-model="form.refund_method"
                                :options="methods"
                                placeholder="Mënyra e rimbursimit"
                                :error="form.errors.refund_method"
                            />
                            <Select
                                v-else
                                v-model="form.settle_method"
                                :options="methods"
                                placeholder="Mënyra e pagesës"
                                :error="form.errors.settle_method"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <label class="block">
                <span class="mb-1.5 block text-body-sm font-semibold text-primary-900">Arsyeja</span>
                <textarea
                    v-model="form.reason"
                    rows="3"
                    maxlength="500"
                    class="w-full rounded-lg border-neutral-200 text-body-sm placeholder:text-neutral-400 focus:border-accent-500 focus:ring-accent-500"
                    placeholder="p.sh. Ndryshim i planit të udhëtimit"
                />
                <span v-if="form.errors.reason" class="mt-1 block text-small text-error-600">{{ form.errors.reason }}</span>
            </label>
        </div>

        <template #footer>
            <Button v-if="planned" variant="outline" :disabled="cancellingPlan" @click="cancelPlan">Anulo planin</Button>
            <span class="flex-1" />
            <Button variant="outline" :disabled="form.processing" @click="emit('close')">Mbyll</Button>
            <Button variant="primary" :loading="form.processing" :disabled="!valid" @click="submit">
                {{ isFuturePlan ? (planned ? 'Përditëso planin' : 'Planifiko largimin') : 'Përfundo largimin' }}
            </Button>
        </template>
    </Modal>
</template>
