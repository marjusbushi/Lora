<script setup>
import { ref, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import WebsiteLayout from '@/Layouts/WebsiteLayout.vue';
import { renderForm } from '@nebula-ltd/pok-payments-js';
import '@nebula-ltd/pok-payments-js/style.css';

const props = defineProps({
    orderId: String,
    env: { type: String, default: 'staging' },
    amount: { type: Number, default: 0 },
    currency: { type: String, default: '€' },
    guestName: { type: String, default: null },
    confirmUrl: String,
    roomName: { type: String, default: null },
    nights: { type: Number, default: 0 },
});

const error = ref('');
const confirming = ref(false);

function money(v) {
    const n = Number(v) || 0;
    return n % 1 === 0 ? String(n) : n.toFixed(2);
}

onMounted(() => {
    if (!props.orderId) {
        error.value = 'Porosia e pagesës nuk u gjet. Kthehu te rezervimi.';
        return;
    }
    // Mount POK's secure card form into #pok-form (card data goes straight to POK, never our server).
    renderForm(
        'pok-form',
        props.orderId,
        () => {
            // Paid — ask our server to verify with POK and confirm the reservation.
            confirming.value = true;
            router.post(props.confirmUrl, {}, {
                onError: () => {
                    confirming.value = false;
                    error.value = 'Pagesa u krye, por s\'u konfirmua ende. Prit pak sekonda dhe rifresko.';
                },
            });
        },
        (e) => { error.value = e?.message || 'Pagesa dështoi. Kontrollo kartën ose provo një tjetër.'; },
        { env: props.env, locale: 'al' },
    );
});
</script>

<template>
    <WebsiteLayout>
        <div class="max-w-xl mx-auto px-5 py-16 sm:py-20">
            <p class="text-eyebrow text-ionian mb-3">Hapi i fundit</p>
            <h1 class="font-serif text-display-sm text-ink">Përfundo pagesën</h1>
            <p class="text-driftwood mt-2 mb-8 lead">
                <template v-if="guestName">{{ guestName }}, r</template><template v-else>R</template>ezervimi mbahet për ty derisa të paguash. Pagesa është e sigurt përmes POK.
            </p>

            <!-- summary -->
            <div class="rounded-2xl border border-limestone bg-bone/60 p-5 mb-6">
                <div class="flex items-center justify-between text-ink/70 text-body-sm">
                    <span>{{ roomName || 'Dhoma' }}</span>
                    <span>{{ nights }} net</span>
                </div>
                <div class="flex items-baseline justify-between mt-3 pt-3 border-t border-limestone">
                    <span class="text-ink font-medium">Total për të paguar</span>
                    <span class="font-serif text-3xl text-brass">{{ currency }}{{ money(amount) }}</span>
                </div>
            </div>

            <div v-if="error" class="mb-5 rounded-xl bg-red-50 border border-red-200 text-red-700 text-body-sm px-4 py-3">
                {{ error }}
            </div>

            <!-- POK embedded card form mounts here -->
            <div id="pok-form" class="min-h-[220px]"></div>

            <p v-if="confirming" class="text-center text-driftwood text-body-sm mt-5">Po konfirmohet pagesa…</p>

            <p v-if="env === 'staging'" class="text-center text-tiny text-driftwood mt-8 leading-relaxed">
                Modaliteti TEST — përdor kartën <b class="text-ink">4242 4242 4242 4242</b>,<br>
                datë skadence në të ardhmen, çfarëdo CVV 3-shifror.
            </p>
        </div>
    </WebsiteLayout>
</template>
