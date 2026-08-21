<script setup>
import { ref, onMounted, computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Check, X } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import WebsiteLayout from '@/Layouts/WebsiteLayout.vue';

const brandName = computed(() => usePage().props.settings?.hotel_name || 'Hotel');

const props = defineProps({ reservation: Object, hotel: Object, status: { type: String, default: 'confirmed' } });

// Prefer the reservation's own frozen symbol; the shared setting is only a fallback.
const currencySymbol = computed(() => props.reservation?.currency_symbol || usePage().props.settings?.pricing_currency_symbol || '€');

// Typology lines ("2 × Deluxe") — a multi-room booking is a group of reservations, and the
// guest booked room TYPES, never numbered rooms. Falls back to the single room_type field.
const roomLines = computed(() => {
    const rooms = props.reservation?.rooms || [];
    if (!rooms.length) {
        return props.reservation?.room_type ? [{ name: props.reservation.room_type, qty: 1 }] : [];
    }
    const grouped = new Map();
    rooms.forEach((room) => {
        const entry = grouped.get(room.room_type) || { name: room.room_type, qty: 0 };
        entry.qty += 1;
        grouped.set(room.room_type, entry);
    });
    return [...grouped.values()];
});
const isMultiRoom = computed(() => roomLines.value.reduce((sum, line) => sum + line.qty, 0) > 1);

const { t } = useI18n();

// Arriving from the payment redirect leaves focus on <body> — announce the outcome
// immediately so the guest (and a screen reader) lands on "confirmed" / "not completed".
const headingEl = ref(null);
onMounted(() => headingEl.value?.focus({ preventScroll: true }));
</script>

<template>
    <Head :title="$t('confirmation.pageTitle', { hotel: brandName })" />
    <WebsiteLayout>
        <section class="py-20">
            <div class="max-w-lg mx-auto px-4 text-center">
                <!-- Hold released because online payment was not completed. -->
                <template v-if="status === 'cancelled'">
                    <div class="h-20 w-20 rounded-full bg-error-50 flex items-center justify-center mx-auto mb-6">
                        <X class="h-9 w-9 text-error-500" :stroke-width="1.5" aria-hidden="true" />
                    </div>
                    <h1 ref="headingEl" tabindex="-1" class="text-h1 text-primary-900 focus:outline-none">{{ $t('confirmation.cancelled.heading') }}</h1>
                    <p class="text-body text-neutral-600 mt-3">{{ $t('confirmation.cancelled.message') }}</p>
                    <Link href="/book" class="btn-reserve mt-8">{{ $t('confirmation.cancelled.retry') }}</Link>
                    <p v-if="hotel?.phone" class="text-body-sm text-neutral-500 mt-5">
                        {{ $t('confirmation.cancelled.callUs') }}
                        <a :href="'tel:' + hotel.phone" class="text-ionian font-medium whitespace-nowrap">{{ hotel.phone }}</a>
                    </p>
                </template>

                <template v-else>
                <div class="h-20 w-20 rounded-full bg-ionian/10 flex items-center justify-center mx-auto mb-6">
                    <Check class="h-9 w-9 text-ionian" :stroke-width="1.5" aria-hidden="true" />
                </div>
                <h1 ref="headingEl" tabindex="-1" class="text-h1 text-primary-900 focus:outline-none">{{ $t('confirmation.heading') }}</h1>
                <p class="text-body text-neutral-600 mt-3">{{ $t('confirmation.intro') }}</p>
                <!-- No confirmation email is sent — this reference is the guest's only proof. -->
                <p class="text-body-sm text-neutral-500 mt-2">{{ $t('confirmation.keepReference') }}</p>

                <div class="bg-neutral-50 rounded-xl p-6 mt-8 text-left space-y-3">
                    <div class="flex justify-between items-baseline">
                        <span class="text-body-sm text-neutral-500">{{ $t('confirmation.labels.reference') }}</span>
                        <span class="text-h4 text-primary-900 font-semibold tracking-wider">{{ reservation.reference }}</span>
                    </div>
                    <div v-if="reservation.guest_name" class="flex justify-between">
                        <span class="text-body-sm text-neutral-500">{{ $t('confirmation.labels.guest') }}</span>
                        <span class="text-body-sm text-primary-900">{{ reservation.guest_name }}</span>
                    </div>
                    <div class="flex justify-between gap-4">
                        <span class="text-body-sm text-neutral-500">{{ isMultiRoom ? $t('confirmation.labels.rooms') : $t('confirmation.labels.room') }}</span>
                        <span class="text-body-sm text-primary-900 text-right">
                            <span v-for="line in roomLines" :key="line.name" class="block">{{ line.qty > 1 ? `${line.qty} × ` : '' }}{{ line.name }}</span>
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-body-sm text-neutral-500">{{ $t('confirmation.labels.checkIn') }}</span>
                        <span class="text-body-sm text-primary-900">{{ reservation.check_in_date }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-body-sm text-neutral-500">{{ $t('confirmation.labels.checkOut') }}</span>
                        <span class="text-body-sm text-primary-900">{{ reservation.check_out_date }}</span>
                    </div>
                    <div class="flex justify-between border-t border-neutral-200 pt-3">
                        <span class="text-label text-neutral-700">{{ $t('confirmation.labels.total') }}</span>
                        <span class="text-h4 text-brass">{{ currencySymbol }}{{ reservation.total_amount }}</span>
                    </div>
                </div>

                <Link href="/" class="btn-reserve mt-8">
                    {{ $t('confirmation.cta.home') }}
                </Link>
                </template>
            </div>
        </section>
    </WebsiteLayout>
</template>
