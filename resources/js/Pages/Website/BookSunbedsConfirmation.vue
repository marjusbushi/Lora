<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { CircleCheck } from 'lucide-vue-next';
import WebsiteLayout from '@/Layouts/WebsiteLayout.vue';
import QrCode from '@/Components/UI/QrCode.vue';

const props = defineProps({
    reservation: { type: Object, required: true },
});
</script>

<template>
    <Head :title="$t('beach.public.confirmationHeadTitle')" />
    <WebsiteLayout>
        <section class="bg-bone py-10 sm:py-16">
            <div class="mx-auto max-w-lg px-4 text-center sm:px-6">
                <CircleCheck class="mx-auto h-14 w-14 text-ionian" />
                <h1 class="mt-4 text-2xl font-semibold text-ink">{{ $t('beach.public.confirmedTitle') }}</h1>
                <p class="mt-2 text-body-sm text-driftwood">
                    {{ $t('beach.public.confirmedHint', { name: reservation.guest_name }) }}
                </p>

                <div class="mt-8 rounded-2xl border border-driftwood/20 bg-white p-6 shadow-sm">
                    <p class="text-4xl font-black text-ink">{{ reservation.unit_number }}</p>
                    <p class="mt-1 text-body text-driftwood">{{ reservation.zone_name }}</p>
                    <p class="mt-3 text-body font-medium text-ink">
                        {{ $t('beach.public.confirmedDates', { start: reservation.start_date, end: reservation.end_date, days: reservation.days }) }}
                    </p>
                    <p class="mt-1 text-xl font-bold text-ink">{{ reservation.total_amount }}</p>
                    <p class="mt-1 text-body-sm text-driftwood">{{ $t('beach.public.payOnSite') }}</p>

                    <div class="mt-6 flex justify-center">
                        <QrCode :value="reservation.confirmation_url" :size="180" :alt="$t('beach.public.qrAlt')" />
                    </div>
                    <p class="mt-3 text-body-sm text-driftwood">{{ $t('beach.public.showAtBeach') }}</p>
                </div>

                <Link href="/" class="mt-8 inline-block text-body-sm text-ionian underline underline-offset-4">
                    {{ $t('beach.public.backHome') }}
                </Link>
            </div>
        </section>
    </WebsiteLayout>
</template>
