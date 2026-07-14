<script setup>
import { ref, onMounted, computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Check, X } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import WebsiteLayout from '@/Layouts/WebsiteLayout.vue';

const brandName = computed(() => usePage().props.settings?.hotel_name || 'Hotel');

defineProps({ reservation: Object, hotel: Object, status: { type: String, default: 'confirmed' } });

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
                    <div class="flex justify-between">
                        <span class="text-body-sm text-neutral-500">{{ $t('confirmation.labels.room') }}</span>
                        <span class="text-body-sm text-primary-900">{{ reservation.room_number }} — {{ reservation.room_type }}</span>
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
                        <span class="text-h4 text-brass">€{{ reservation.total_amount }}</span>
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
