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
    payUrl: { type: String, default: null }, // POK's hosted card page (reliable fallback)
    roomName: { type: String, default: null },
    nights: { type: Number, default: 0 },
    openForPayment: { type: Boolean, default: true },
});

const error = ref('');
const diag = ref('');       // on-page technical detail (staging) so a failure is visible without DevTools
const confirming = ref(false);

function money(v) { const n = Number(v) || 0; return n % 1 === 0 ? String(n) : n.toFixed(2); }
function note(m) { diag.value += (diag.value ? '\n' : '') + m; try { console.log('[POK]', m); } catch (e) {} }

onMounted(() => {
    // Surface the SDK's OWN console.error (the real cause it hides behind "GENERAL_ERROR").
    const origErr = console.error;
    console.error = (...a) => {
        try { note('console.error: ' + a.map((x) => x?.message || (x && typeof x === 'object' ? JSON.stringify(x) : String(x))).join(' ')); } catch (e) {}
        origErr.apply(console, a);
    };
    window.addEventListener('error', (ev) => note('JS error: ' + (ev.message || ev.error?.message || ev.error)));
    window.addEventListener('unhandledrejection', (ev) => note('Promise reject: ' + (ev.reason?.message || JSON.stringify(ev.reason))));

    // Capture the network mechanism behind the silent GENERAL_ERROR (websocket / hidden-iframe / fetch).
    try {
        const OrigWS = window.WebSocket;
        window.WebSocket = function (url, proto) {
            note('WebSocket → ' + url);
            const ws = proto ? new OrigWS(url, proto) : new OrigWS(url);
            ws.addEventListener('error', () => note('WebSocket ERROR: ' + url));
            ws.addEventListener('close', (ev) => note('WebSocket closed code=' + ev.code + ' ' + url));
            return ws;
        };
        window.WebSocket.prototype = OrigWS.prototype;

        const origFetch = window.fetch.bind(window);
        window.fetch = async (...a) => {
            try {
                const r = await origFetch(...a);
                if (!r.ok) note('fetch ' + r.status + ' ' + (a[0]?.url || a[0]));
                return r;
            } catch (e) { note('fetch FAILED ' + (a[0]?.url || a[0]) + ' — ' + e.message); throw e; }
        };

        window.addEventListener('message', (ev) => {
            const o = String(ev.origin);
            if (o.includes('pok') || o.includes('cyber') || o.includes('cardinal')) {
                note('postMessage ' + o + ': ' + String(typeof ev.data === 'object' ? JSON.stringify(ev.data) : ev.data).slice(0, 140));
            }
        });
    } catch (e) { note('instrument failed: ' + e.message); }

    if (!props.openForPayment || !props.orderId) return;

    try {
        note('renderForm(' + props.orderId + ', env=' + props.env + ')');
        renderForm(
            'pok-form',
            props.orderId,
            () => {
                confirming.value = true;
                router.post(props.confirmUrl, {}, {
                    onError: () => { confirming.value = false; error.value = "Pagesa u krye, por s'u konfirmua ende. Prit pak sekonda dhe rifresko."; },
                });
            },
            (e) => { error.value = e?.message || 'Pagesa dështoi.'; note('SDK onError: ' + JSON.stringify(e ?? {})); },
            { env: props.env, locale: 'al' },
        );
    } catch (ex) {
        note('renderForm threw: ' + (ex?.message || ex));
        error.value = "Forma s'u ngarkua.";
    }
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

            <div v-if="!openForPayment" class="rounded-xl bg-limestone/40 border border-limestone text-ink/80 text-body-sm px-4 py-6 text-center">
                Po konfirmojmë pagesën tënde… Nëse e ke paguar, rifresko këtë faqe pas pak sekondash.
            </div>

            <template v-if="openForPayment">
                <!-- POK embedded card form mounts here -->
                <div id="pok-form" class="min-h-[220px]"></div>

                <p v-if="confirming" class="text-center text-driftwood text-body-sm mt-5">Po konfirmohet pagesa…</p>

                <!-- Reliable fallback: POK's own hosted card page (opens in the same tab, returns here). -->
                <div v-if="payUrl" class="mt-8 pt-6 border-t border-limestone text-center">
                    <p class="text-driftwood text-body-sm mb-3">Nuk shfaqet forma e kartës më lart?</p>
                    <a :href="payUrl" class="inline-block rounded-xl bg-brass text-white font-medium px-6 py-3 hover:bg-brass-dark no-underline">
                        Paguaj në faqen e sigurt të POK →
                    </a>
                </div>

                <p v-if="env === 'staging'" class="text-center text-tiny text-driftwood mt-8 leading-relaxed">
                    Modaliteti TEST — përdor kartën <b class="text-ink">4242 4242 4242 4242</b>,<br>
                    datë skadence në të ardhmen, çfarëdo CVV 3-shifror.
                </p>

                <!-- Diagnostic (staging only): shows WHY the embedded SDK failed, no DevTools needed. -->
                <pre v-if="env === 'staging' && diag" class="mt-6 whitespace-pre-wrap break-words rounded-lg bg-ink/5 border border-limestone text-ink/60 text-[11px] leading-relaxed p-3">{{ diag }}</pre>
            </template>
        </div>
    </WebsiteLayout>
</template>
