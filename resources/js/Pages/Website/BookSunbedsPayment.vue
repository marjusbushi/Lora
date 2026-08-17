<script setup>
import { translate } from '@/i18n';
import { ref, computed, watch, nextTick, onMounted } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import WebsiteLayout from '@/Layouts/WebsiteLayout.vue';

// Si te BookingPayment e dhomave: forma drop-in e POK vjen VETËM nga CDN
// (window.PokPayment) — bundle-i npm përmes Vite e prish SDK-në.
const POK_CDN = 'https://static.pokpay.io/public/dist/pokpayments/pok-payment.js';

const props = defineProps({
    orderId: String,
    env: { type: String, default: 'staging' },
    amount: { type: Number, default: 0 },
    currency: { type: String, default: '€' },
    confirmUrl: String,
    confirmationUrl: { type: String, default: '/book-sunbeds' },
    payUrl: { type: String, default: null },
    initialState: { type: Object, default: () => ({}) },
    unitNumber: { type: String, default: '' },
    zoneName: { type: String, default: '' },
    days: { type: Number, default: 0 },
    startDate: { type: String, default: '' },
    endDate: { type: String, default: '' },
    openForPayment: { type: Boolean, default: true },
});

const error = ref('');
const confirming = ref(false);
const started = ref(false);
const sdkLoading = ref(false);
const errorBox = ref(null);
const flashError = computed(() => usePage().props.flash?.error);

function money(v) { const n = Number(v) || 0; return n % 1 === 0 ? String(n) : n.toFixed(2); }

function loadPokSdk() {
    return new Promise((resolve, reject) => {
        if (window.PokPayment) return resolve(window.PokPayment);
        const s = document.createElement('script');
        s.src = POK_CDN;
        s.async = true;
        s.onload = () => (window.PokPayment ? resolve(window.PokPayment) : reject(new Error('PokPayment global missing after load')));
        s.onerror = () => reject(new Error(translate('beach.pay.sdkLoadFailed')));
        document.head.appendChild(s);
    });
}

function focusPokFormWhenReady() {
    const host = document.getElementById('pok-form');
    if (!host) return;
    const obs = new MutationObserver(() => {
        const el = host.querySelector('input, select, iframe, button');
        if (!el) return;
        obs.disconnect();
        sdkLoading.value = false;
        el.focus({ preventScroll: true });
        host.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    obs.observe(host, { childList: true, subtree: true });
    setTimeout(() => { obs.disconnect(); sdkLoading.value = false; }, 12000);
}

async function startPayment() {
    if (started.value || !props.orderId) return;
    started.value = true;
    sdkLoading.value = true;
    error.value = '';
    try {
        const Pok = await loadPokSdk();
        focusPokFormWhenReady();
        Pok.renderForm(
            'pok-form',
            props.orderId,
            () => {
                confirming.value = true;
                router.post(props.confirmUrl, {}, {
                    onError: () => { confirming.value = false; error.value = translate('beach.pay.confirmFailed'); },
                });
            },
            (e) => {
                // Rrjetë sigurie: forma dështoi → faqja e POK-ut vetë (rezervimi mbetet i vlefshëm).
                if (props.payUrl) { window.location.href = props.payUrl; return; }
                error.value = e?.message || translate('beach.pay.formFailed');
                started.value = false;
            },
            { env: props.env, locale: 'al', initialState: { ...props.initialState } },
        );
    } catch (ex) {
        error.value = ex?.message || translate('beach.pay.formFailed');
        started.value = false;
        sdkLoading.value = false;
    }
}

watch([error, flashError], ([e, f]) => {
    if (e || f) nextTick(() => {
        errorBox.value?.focus({ preventScroll: true });
        errorBox.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
});

onMounted(() => {
    if (props.openForPayment && props.orderId) startPayment();
});
</script>

<template>
    <Head :title="$t('beach.pay.headTitle')" />
    <WebsiteLayout>
        <div class="mx-auto max-w-xl px-5 py-16 sm:py-20">
            <h1 class="font-serif text-display-sm text-ink">{{ $t('beach.pay.title') }}</h1>
            <p class="lead mt-2 mb-8 text-driftwood">{{ $t('beach.pay.subtitle') }}</p>

            <div class="mb-6 rounded-2xl border border-limestone bg-bone/60 p-5">
                <div class="flex items-center justify-between text-body-sm text-ink/70">
                    <span>{{ $t('beach.pay.summary', { number: unitNumber, zone: zoneName }) }}</span>
                    <span>{{ startDate }} → {{ endDate }} · {{ $t('beach.public.daysCount', { days }) }}</span>
                </div>
                <div class="mt-3 flex items-baseline justify-between border-t border-limestone pt-3">
                    <span class="font-medium text-ink">{{ $t('beach.pay.totalLabel') }}</span>
                    <span class="font-serif text-3xl text-brass">{{ currency }}{{ money(amount) }}</span>
                </div>
            </div>

            <div
                v-if="error || flashError"
                ref="errorBox"
                role="alert"
                tabindex="-1"
                class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-body-sm text-red-700 focus:outline-none"
            >
                {{ error || flashError }}
            </div>

            <div v-if="!openForPayment" class="rounded-xl border border-limestone bg-limestone/40 px-4 py-6 text-center text-body-sm text-ink/80">
                <p>{{ $t('beach.pay.verifying') }}</p>
                <button type="button" class="mt-3 text-body-sm text-ionian underline" @click="router.reload()">{{ $t('beach.pay.refresh') }}</button>
            </div>

            <template v-else>
                <div v-if="!started" class="text-center">
                    <button type="button" class="rounded-xl bg-ionian px-7 py-3.5 font-medium text-white hover:opacity-90" @click="startPayment">
                        {{ error ? $t('beach.pay.retry') : $t('beach.pay.start') }}
                    </button>
                </div>

                <p v-if="sdkLoading" role="status" class="py-6 text-center text-body-sm text-ink/70">{{ $t('beach.pay.loadingForm') }}</p>

                <div v-show="started && !confirming" id="pok-form" tabindex="-1" :aria-label="$t('beach.pay.formAria')" class="min-h-[220px] outline-none"></div>

                <p v-if="confirming" role="status" aria-live="polite" class="mt-5 text-center text-body-sm text-driftwood">
                    <span class="mr-1.5 inline-block h-4 w-4 animate-spin rounded-full border-2 border-ionian border-t-transparent align-[-2px]" aria-hidden="true"></span>
                    {{ $t('beach.pay.confirming') }}
                </p>

                <p class="mt-8 text-center text-tiny leading-relaxed text-driftwood">{{ $t('beach.pay.optionalNote') }}</p>

                <p v-if="env === 'staging'" class="mt-3 text-center text-tiny leading-relaxed text-driftwood">
                    {{ $t('beach.pay.testCardIntro') }} <b class="text-ink">4242 4242 4242 4242</b>
                </p>
            </template>

            <p class="mt-8 text-center">
                <Link :href="confirmationUrl" class="text-body-sm text-ionian underline underline-offset-4">
                    {{ $t('beach.pay.backToConfirmation') }}
                </Link>
            </p>
        </div>
    </WebsiteLayout>
</template>
