<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { Check } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import WebsiteLayout from '@/Layouts/WebsiteLayout.vue';

defineProps({ reservation: Object, hotel: Object, status: { type: String, default: 'confirmed' } });

const { t } = useI18n();
</script>

<template>
    <Head :title="$t('confirmation.pageTitle')" />
    <WebsiteLayout>
        <section class="py-20">
            <div class="max-w-lg mx-auto px-4 text-center">
                <!-- Hold released because online payment was not completed. -->
                <template v-if="status === 'cancelled'">
                    <div class="h-20 w-20 rounded-full bg-error-50 flex items-center justify-center mx-auto mb-6">
                        <span class="text-4xl leading-none text-error-500">×</span>
                    </div>
                    <h1 class="text-h1 text-primary-900">Rezervimi nuk u përfundua</h1>
                    <p class="text-body text-neutral-600 mt-3">Pagesa nuk u përfundua në kohë, ndaj dhoma u lirua. Mund të provosh sërish.</p>
                    <Link href="/book" class="btn-reserve mt-8">Provo sërish</Link>
                </template>

                <template v-else>
                <div class="h-20 w-20 rounded-full bg-ionian/10 flex items-center justify-center mx-auto mb-6">
                    <Check class="h-9 w-9 text-ionian" :stroke-width="1.5" />
                </div>
                <h1 class="text-h1 text-primary-900">{{ $t('confirmation.heading') }}</h1>
                <p class="text-body text-neutral-600 mt-3">{{ $t('confirmation.intro') }}</p>

                <div class="bg-neutral-50 rounded-xl p-6 mt-8 text-left space-y-3">
                    <div class="flex justify-between">
                        <span class="text-body-sm text-neutral-500">{{ $t('confirmation.labels.reference') }}</span>
                        <span class="text-body-sm text-primary-900 font-medium">{{ reservation.reference }}</span>
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
