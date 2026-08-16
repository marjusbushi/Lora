<script setup>
import { computed, onBeforeUnmount, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, CalendarDays, CalendarMinus, CalendarPlus, CreditCard, DoorOpen, ExternalLink, Mail, Phone, UserRound, X } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import { getIntlLocale, translate } from '@/i18n';

const props = defineProps({
    reservation: { type: Object, default: null },
    canUpdate: { type: Boolean, default: false },
    hotelToday: { type: String, default: '' },
});
const emit = defineEmits(['close', 'edit', 'check-in', 'check-out', 'early-departure', 'stay-extension']);
const currencyCode = usePage().props.tenant?.currency || 'EUR';

const statusMeta = {
    pending: { label: translate('reservationDrawer.statusPending'), variant: 'warning' },
    confirmed: { label: translate('reservationDrawer.statusConfirmed'), variant: 'info' },
    checked_in: { label: translate('reservationDrawer.statusInHouse'), variant: 'success' },
    checked_out: { label: translate('reservationDrawer.statusCompleted'), variant: 'neutral' },
    cancelled: { label: translate('reservationDrawer.statusCancelled'), variant: 'error' },
};

const guestName = computed(() => props.reservation?.guest?.name
    || [props.reservation?.guest?.first_name, props.reservation?.guest?.last_name].filter(Boolean).join(' ')
    || '—');
const earlyDeparturePlanned = computed(() => Boolean(
    props.reservation?.early_departure_scheduled_at
    && !props.reservation?.early_departure_at
    && props.reservation?.original_check_out_date
));
const canDepartEarly = computed(() => {
    if (props.reservation?.status !== 'checked_in') return false;
    const contractualCheckOut = props.reservation.original_check_out_date || props.reservation.check_out_date;
    const earliestDeparture = new Date(`${props.reservation.check_in_date}T00:00:00Z`);
    earliestDeparture.setUTCDate(earliestDeparture.getUTCDate() + 1);

    return earliestDeparture.toISOString().slice(0, 10) < contractualCheckOut;
});

function money(value) {
    // The payload's amounts are in the RESERVATION's currency (a Lek-base
    // hotel selling in EUR sends €63, not L63) — base is only the fallback.
    const code = props.reservation?.currency || currencyCode;
    return new Intl.NumberFormat(getIntlLocale(), { style: 'currency', currency: code }).format(Number(value || 0));
}
function date(value) {
    if (!value) return '—';
    return new Date(`${value}T12:00:00`).toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short', year: 'numeric' });
}
function closeOnEscape(event) {
    if (event.key === 'Escape') emit('close');
}
watch(() => props.reservation, (reservation) => {
    document.body.style.overflow = reservation ? 'hidden' : '';
}, { immediate: true });
document.addEventListener('keydown', closeOnEscape);
onBeforeUnmount(() => {
    document.body.style.overflow = '';
    document.removeEventListener('keydown', closeOnEscape);
});
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="duration-200 ease-out" enter-from-class="opacity-0" leave-active-class="duration-150 ease-in" leave-to-class="opacity-0">
            <div v-if="reservation" class="fixed inset-0 z-50 bg-neutral-950/35" @click="emit('close')" />
        </Transition>
        <Transition enter-active-class="duration-250 ease-out" enter-from-class="translate-x-full" leave-active-class="duration-200 ease-in" leave-to-class="translate-x-full">
            <aside v-if="reservation" class="fixed inset-y-0 right-0 z-50 flex w-full max-w-[460px] flex-col bg-white shadow-2xl" role="dialog" aria-modal="true" @click.stop>
                <header class="border-b border-neutral-200 px-6 py-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="mb-2 flex items-center gap-2">
                                <Badge :variant="statusMeta[reservation.status]?.variant" dot>{{ statusMeta[reservation.status]?.label }}</Badge>
                                <span class="text-small text-neutral-400">#{{ reservation.id }}</span>
                            </div>
                            <h2 class="text-h3 text-primary-900">{{ guestName }}</h2>
                            <p class="mt-1 text-body-sm text-neutral-500">{{ $t('reservationDrawer.subtitle') }}</p>
                        </div>
                        <button class="rounded-lg p-2 text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-700" :aria-label="$t('reservationDrawer.close')" @click="emit('close')"><X class="h-5 w-5" /></button>
                    </div>
                </header>

                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-5">
                    <section class="rounded-xl border border-neutral-200 p-4">
                        <div class="flex items-center gap-3">
                            <span class="rounded-lg bg-accent-50 p-2 text-accent-700"><UserRound class="h-5 w-5" /></span>
                            <div class="min-w-0 flex-1">
                                <Link v-if="reservation.links?.guest" :href="reservation.links.guest" class="font-semibold text-primary-900 no-underline hover:text-accent-700">{{ guestName }}</Link>
                                <p v-else class="font-semibold text-primary-900">{{ guestName }}</p>
                                <p v-if="reservation.guest?.email" class="mt-1 flex items-center gap-1.5 truncate text-small text-neutral-500"><Mail class="h-3.5 w-3.5" />{{ reservation.guest.email }}</p>
                                <p v-if="reservation.guest?.phone" class="mt-1 flex items-center gap-1.5 text-small text-neutral-500"><Phone class="h-3.5 w-3.5" />{{ reservation.guest.phone }}</p>
                            </div>
                            <ExternalLink v-if="reservation.links?.guest" class="h-4 w-4 text-neutral-300" />
                        </div>
                    </section>

                    <section class="grid grid-cols-2 gap-3">
                        <div class="rounded-xl border border-neutral-200 p-4">
                            <CalendarDays class="mb-3 h-5 w-5 text-accent-700" />
                            <p class="text-tiny font-semibold uppercase tracking-wide text-neutral-400">{{ $t('reservationDrawer.stay') }}</p>
                            <p class="mt-1 text-body-sm font-semibold text-primary-900">{{ date(reservation.check_in_date) }}</p>
                            <p class="text-small text-neutral-500">{{ $t('reservationDrawer.untilNights', { date: date(reservation.check_out_date), nights: reservation.nights }) }}</p>
                            <p v-if="earlyDeparturePlanned" class="mt-1 text-small font-semibold text-info-700">{{ $t('reservationDrawer.earlyDeparturePlanned', { date: date(reservation.original_check_out_date) }) }}</p>
                        </div>
                        <div class="rounded-xl border border-neutral-200 p-4">
                            <DoorOpen class="mb-3 h-5 w-5 text-accent-700" />
                            <p class="text-tiny font-semibold uppercase tracking-wide text-neutral-400">{{ $t('reservationDrawer.room') }}</p>
                            <Link v-if="reservation.links?.room" :href="reservation.links.room" class="mt-1 block text-body-sm font-semibold text-primary-900 no-underline hover:text-accent-700">{{ reservation.room?.room_number || '—' }}</Link>
                            <p class="text-small text-neutral-500">{{ reservation.room?.room_type?.name || '—' }}</p>
                        </div>
                    </section>

                    <section class="rounded-xl border border-neutral-200 p-4">
                        <div class="mb-4 flex items-center justify-between">
                            <div class="flex items-center gap-2"><CreditCard class="h-5 w-5 text-accent-700" /><h3 class="font-semibold text-primary-900">{{ $t('reservationDrawer.finance') }}</h3></div>
                            <Link v-if="reservation.links?.finance" :href="reservation.links.finance" class="flex items-center gap-1 text-small font-semibold text-accent-700 no-underline hover:text-accent-800">{{ $t('reservationDrawer.viewMovements') }} <ArrowRight class="h-3.5 w-3.5" /></Link>
                        </div>
                        <dl class="space-y-2.5 text-body-sm">
                            <div class="flex justify-between"><dt class="text-neutral-500">{{ $t('reservationDrawer.total') }}</dt><dd class="font-medium text-primary-900">{{ money(reservation.gross_amount) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-neutral-500">{{ $t('reservationDrawer.paid') }}</dt><dd class="font-medium text-success-700">{{ money(reservation.paid_amount) }}</dd></div>
                            <div class="flex justify-between border-t border-neutral-100 pt-2.5"><dt class="font-semibold text-primary-900">{{ $t('reservationDrawer.outstanding') }}</dt><dd class="font-semibold" :class="reservation.outstanding_amount > 0 ? 'text-error-600' : 'text-success-700'">{{ money(reservation.outstanding_amount) }}</dd></div>
                        </dl>
                    </section>

                    <section class="rounded-xl bg-neutral-50 p-4 text-body-sm">
                        <div class="flex justify-between"><span class="text-neutral-500">{{ $t('reservationDrawer.source') }}</span><span class="font-medium capitalize text-primary-900">{{ reservation.channel || 'Direct' }}</span></div>
                        <div v-if="reservation.channel_ref" class="mt-2 flex justify-between"><span class="text-neutral-500">{{ $t('reservationDrawer.reference') }}</span><span class="font-medium text-primary-900">{{ reservation.channel_ref }}</span></div>
                        <p v-if="reservation.notes" class="mt-3 border-t border-neutral-200 pt-3 text-neutral-600">{{ reservation.notes }}</p>
                    </section>
                </div>

                <footer class="border-t border-neutral-200 bg-neutral-50 px-6 py-4">
                    <div class="flex flex-wrap justify-end gap-2">
                        <Button variant="outline" @click="emit('close')">{{ $t('reservationDrawer.close') }}</Button>
                        <Button v-if="canUpdate" variant="secondary" @click="emit('edit', reservation)">{{ $t('reservationDrawer.edit') }}</Button>
                        <Button v-if="canUpdate && reservation.status === 'confirmed'" variant="primary" @click="emit('check-in', reservation)">{{ $t('reservationDrawer.checkIn') }}</Button>
                        <Button v-if="canUpdate && reservation.status === 'checked_in' && !earlyDeparturePlanned" variant="outline" @click="emit('stay-extension', reservation)"><CalendarPlus class="h-4 w-4" />{{ $t('reservationDrawer.extendStay') }}</Button>
                        <Button v-if="canUpdate && canDepartEarly" variant="outline" @click="emit('early-departure', reservation)"><CalendarMinus class="h-4 w-4" />{{ earlyDeparturePlanned ? $t('reservationDrawer.manageDeparture') : $t('reservationDrawer.earlyDeparture') }}</Button>
                        <Button v-if="canUpdate && reservation.status === 'checked_in' && !earlyDeparturePlanned" variant="primary" @click="emit('check-out', reservation)">{{ $t('reservationDrawer.checkOut') }}</Button>
                        <Link :href="reservation.links.show" class="no-underline"><Button variant="primary">{{ $t('reservationDrawer.openPage') }} <ArrowRight class="ml-1.5 h-4 w-4" /></Button></Link>
                    </div>
                </footer>
            </aside>
        </Transition>
    </Teleport>
</template>
