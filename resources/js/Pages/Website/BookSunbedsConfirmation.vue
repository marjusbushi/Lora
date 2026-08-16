<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { CircleCheck, CreditCard, Download } from 'lucide-vue-next';
import WebsiteLayout from '@/Layouts/WebsiteLayout.vue';
import QrCode from '@/Components/UI/QrCode.vue';

const props = defineProps({
    reservation: { type: Object, required: true },
});

const qrWrap = ref(null);

// QR-ja ruhet si foto në telefon — klienti s'e humb edhe pa internet në plazh.
function downloadQr() {
    const img = qrWrap.value?.querySelector('img');
    if (!img?.src) return;
    const link = document.createElement('a');
    link.href = img.src;
    link.download = `rezervimi-cadra-${props.reservation.unit_number}.png`;
    link.click();
}
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
                    <p class="mt-1 text-xl font-bold text-ink">{{ reservation.currency }}{{ reservation.total_amount }}</p>
                    <p v-if="reservation.paid_at" class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-ionian/10 px-3 py-1 text-body-sm font-semibold text-ionian">
                        {{ $t('beach.public.paidBadge', { date: reservation.paid_at }) }}
                    </p>
                    <p v-else class="mt-1 text-body-sm text-driftwood">
                        {{ $t(reservation.payment_mode === 'cash' ? 'beach.public.payOnSite'
                            : reservation.payment_mode === 'online' ? 'beach.public.completeOnline'
                            : 'beach.public.payChoice') }}
                    </p>
                    <!-- !text-white me forcë — stili global i webit i ngjyros <a>-të ionian (teal mbi teal = i padukshëm) -->
                    <Link
                        v-if="reservation.pok_enabled"
                        :href="reservation.pay_url"
                        class="mx-auto mt-4 flex w-full items-center justify-center gap-2 rounded-xl bg-ionian px-6 py-3.5 text-body font-semibold !text-white no-underline transition hover:opacity-90 hover:!text-white"
                    >
                        <CreditCard class="h-5 w-5" aria-hidden="true" />
                        {{ $t('beach.public.payOnline') }}
                    </Link>

                    <div ref="qrWrap" class="mt-6 flex justify-center">
                        <QrCode :value="reservation.confirmation_url" :size="180" :alt="$t('beach.public.qrAlt')" />
                    </div>
                    <p class="mt-3 text-body-sm text-driftwood">{{ $t('beach.public.showAtBeach') }}</p>
                    <button
                        type="button"
                        class="mx-auto mt-4 flex items-center gap-2 rounded-xl border border-ionian px-5 py-2.5 text-body-sm font-semibold text-ionian transition hover:bg-ionian hover:text-bone"
                        @click="downloadQr"
                    >
                        <Download class="h-4 w-4" />{{ $t('beach.public.downloadQr') }}
                    </button>
                    <p class="mt-3 text-body-sm text-driftwood">{{ $t('beach.public.keepLink') }}</p>
                </div>

                <Link href="/" class="mt-8 inline-block text-body-sm text-ionian underline underline-offset-4">
                    {{ $t('beach.public.backHome') }}
                </Link>
            </div>
        </section>
    </WebsiteLayout>
</template>
