<script setup>
import { computed, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { AlertTriangle, CalendarCheck2, CalendarPlus, CheckCircle2, LoaderCircle } from 'lucide-vue-next';
import Modal from '@/Components/UI/Modal.vue';
import Button from '@/Components/UI/Button.vue';
import { getIntlLocale, translate } from '@/i18n';

const props = defineProps({
    show: { type: Boolean, default: false },
    reservation: { type: Object, default: null },
    hotelToday: { type: String, default: '' },
});

const emit = defineEmits(['close', 'extended']);
const page = usePage();
const currency = computed(() => props.reservation?.currency || page.props.tenant?.currency || 'EUR');
const quote = ref(null);
const quoteLoading = ref(false);
const quoteError = ref('');
let quoteRequest = 0;

const form = useForm({
    new_check_out_date: '',
    extension_amount: '',
    reason: '',
});

function parseDate(value) {
    return value ? new Date(`${String(value).slice(0, 10)}T00:00:00Z`) : null;
}
function addDays(value, days) {
    const date = parseDate(value);
    if (!date) return '';
    date.setUTCDate(date.getUTCDate() + days);
    return date.toISOString().slice(0, 10);
}
function formattedDate(value) {
    const date = parseDate(value);
    return date
        ? date.toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' })
        : '—';
}
function money(value) {
    return new Intl.NumberFormat(getIntlLocale(), {
        style: 'currency',
        currency: currency.value,
    }).format(Number(value || 0));
}

const minCheckOut = computed(() => addDays(props.reservation?.check_out_date, 1));
const isOta = computed(() => props.reservation?.channel && props.reservation.channel !== 'direct');
const extensionAmount = computed(() => Number(form.extension_amount || 0));
const newRoomTotal = computed(() => (
    Number(props.reservation?.total_amount || 0) + extensionAmount.value
));
const newGross = computed(() => (
    Number(props.reservation?.gross_amount || 0) + extensionAmount.value
));
const valid = computed(() => (
    quote.value?.available
    && form.new_check_out_date >= minCheckOut.value
    && Number.isFinite(extensionAmount.value)
    && extensionAmount.value >= 0
    && form.reason.trim().length >= 3
));

watch(
    () => [props.show, props.reservation?.id],
    ([open]) => {
        if (!open) return;
        form.reset();
        form.clearErrors();
        quote.value = null;
        quoteError.value = '';
        form.new_check_out_date = minCheckOut.value;
    },
);

watch(
    () => form.new_check_out_date,
    async (newCheckOut) => {
        if (!props.show || !props.reservation?.id || !newCheckOut || newCheckOut < minCheckOut.value) {
            quote.value = null;
            return;
        }

        const requestId = ++quoteRequest;
        quoteLoading.value = true;
        quoteError.value = '';
        form.clearErrors('new_check_out_date');
        try {
            const response = await axios.get(
                route('reservations.stay-extension.quote', props.reservation.id),
                { params: { new_check_out_date: newCheckOut }, headers: { Accept: 'application/json' } },
            );
            if (requestId !== quoteRequest) return;
            quote.value = response.data;
            form.extension_amount = Number(response.data.quoted_extension).toFixed(2);
        } catch (error) {
            if (requestId !== quoteRequest) return;
            quote.value = null;
            quoteError.value = error.response?.data?.errors?.new_check_out_date?.[0]
                || translate('reservationModals.stayExtension.availabilityCheckFailed');
        } finally {
            if (requestId === quoteRequest) quoteLoading.value = false;
        }
    },
);

function submit() {
    if (!props.reservation || !valid.value || form.processing) return;

    form.transform((data) => ({
        ...data,
        extension_amount: Number(data.extension_amount),
    })).post(route('reservations.stay-extension', props.reservation.id), {
        preserveScroll: true,
        onSuccess: () => {
            emit('extended');
            emit('close');
            form.reset();
        },
    });
}
</script>

<template>
    <Modal :show="show" :title="$t('reservationModals.stayExtension.title')" max-width="2xl" @close="emit('close')">
        <div v-if="reservation" class="space-y-5">
            <div class="flex items-start gap-3 rounded-xl border border-neutral-200 bg-neutral-50 p-4">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-white text-accent-700 shadow-sm ring-1 ring-neutral-200">
                    <CalendarPlus class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="font-semibold text-primary-900">{{ reservation.guest?.name || $t('reservationModals.stayExtension.guestFallback') }} · {{ $t('reservationModals.stayExtension.room') }} {{ reservation.room?.room_number || '—' }}</p>
                    <p class="mt-1 text-small text-neutral-500">
                        {{ $t('reservationModals.stayExtension.currentCheckOut') }}: {{ formattedDate(reservation.check_out_date) }}
                        · {{ $t('reservationModals.stayExtension.nightsCount', { count: reservation.nights }) }}
                    </p>
                </div>
            </div>

            <div v-if="isOta" class="flex gap-3 rounded-xl border border-warning-200 bg-warning-50 p-3 text-body-sm text-warning-800">
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                <p>{{ $t('reservationModals.stayExtension.otaNotice', { channel: reservation.channel }) }}</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1.5 block text-body-sm font-semibold text-primary-900">{{ $t('reservationModals.stayExtension.newCheckOut') }}</span>
                    <input
                        v-model="form.new_check_out_date"
                        type="date"
                        :min="minCheckOut"
                        class="w-full rounded-lg border-neutral-200 text-body-sm focus:border-accent-500 focus:ring-accent-500"
                    />
                    <span v-if="form.errors.new_check_out_date || quoteError" class="mt-1 block text-small text-error-600">
                        {{ form.errors.new_check_out_date || quoteError }}
                    </span>
                </label>

                <div class="rounded-xl border p-3" :class="quote?.available ? 'border-success-200 bg-success-50' : 'border-neutral-200 bg-neutral-50'">
                    <div v-if="quoteLoading" class="flex h-full items-center gap-2 text-body-sm text-neutral-500">
                        <LoaderCircle class="h-4 w-4 animate-spin" /> {{ $t('reservationModals.stayExtension.checkingRoom') }}
                    </div>
                    <div v-else-if="quote?.available" class="flex items-start gap-2">
                        <CheckCircle2 class="mt-0.5 h-4 w-4 text-success-700" />
                        <div>
                            <p class="text-body-sm font-semibold text-success-800">{{ $t('reservationModals.stayExtension.roomAvailable') }}</p>
                            <p class="mt-1 text-small text-success-700">{{ $t('reservationModals.stayExtension.quoteSummary', { count: quote.additional_nights, amount: money(quote.quoted_extension) }) }}</p>
                        </div>
                    </div>
                    <p v-else class="text-body-sm text-neutral-500">{{ $t('reservationModals.stayExtension.pickDateHint') }}</p>
                </div>
            </div>

            <label class="block">
                <span class="mb-1.5 block text-body-sm font-semibold text-primary-900">{{ $t('reservationModals.stayExtension.agreedPrice') }}</span>
                <div class="relative max-w-xs">
                    <input
                        v-model="form.extension_amount"
                        type="number"
                        min="0"
                        step="0.01"
                        class="w-full rounded-lg border-neutral-200 pr-16 text-body-sm focus:border-accent-500 focus:ring-accent-500"
                    />
                    <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-small font-medium text-neutral-500">{{ currency }}</span>
                </div>
                <span class="mt-1 block text-small text-neutral-500">{{ $t('reservationModals.stayExtension.priceHint') }}</span>
                <span v-if="form.errors.extension_amount" class="mt-1 block text-small text-error-600">{{ form.errors.extension_amount }}</span>
            </label>

            <div class="grid gap-3 rounded-xl border border-neutral-200 bg-neutral-50 p-4 sm:grid-cols-3">
                <div><p class="text-small text-neutral-500">{{ $t('reservationModals.stayExtension.currentAccommodation') }}</p><p class="mt-1 font-semibold text-primary-900">{{ money(reservation.total_amount) }}</p></div>
                <div><p class="text-small text-neutral-500">{{ $t('reservationModals.stayExtension.extension') }}</p><p class="mt-1 font-semibold text-accent-700">+ {{ money(extensionAmount) }}</p></div>
                <div><p class="text-small text-neutral-500">{{ $t('reservationModals.stayExtension.newGross') }}</p><p class="mt-1 font-semibold text-primary-900">{{ money(newGross) }}</p></div>
            </div>

            <div v-if="quote?.available" class="flex gap-3 rounded-xl border border-info-200 bg-info-50 p-3 text-body-sm text-info-800">
                <CalendarCheck2 class="mt-0.5 h-4 w-4 shrink-0" />
                <p>{{ $t('reservationModals.stayExtension.confirmationNotice', { date: formattedDate(form.new_check_out_date), amount: money(newRoomTotal) }) }}</p>
            </div>

            <label class="block">
                <span class="mb-1.5 block text-body-sm font-semibold text-primary-900">{{ $t('reservationModals.stayExtension.reason') }}</span>
                <textarea
                    v-model="form.reason"
                    rows="3"
                    maxlength="500"
                    :placeholder="$t('reservationModals.stayExtension.reasonPlaceholder')"
                    class="w-full rounded-lg border-neutral-200 text-body-sm focus:border-accent-500 focus:ring-accent-500"
                />
                <span v-if="form.errors.reason" class="mt-1 block text-small text-error-600">{{ form.errors.reason }}</span>
            </label>
        </div>

        <template #footer>
            <Button variant="outline" :disabled="form.processing" @click="emit('close')">{{ $t('reservationModals.stayExtension.cancel') }}</Button>
            <Button variant="primary" :loading="form.processing" :disabled="!valid || quoteLoading" @click="submit">
                <CalendarPlus class="h-4 w-4" /> {{ $t('reservationModals.stayExtension.confirmExtension') }}
            </Button>
        </template>
    </Modal>
</template>
