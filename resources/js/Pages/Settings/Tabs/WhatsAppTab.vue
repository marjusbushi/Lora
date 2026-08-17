<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { translate } from '@/i18n';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';

// WhatsApp QR-lite: lidh numrin e hotelit me skanim QR (si WhatsApp Web).
// Statusi + QR merren me poll nga ura përmes settings.whatsapp.status.
const props = defineProps({
    whatsapp: { type: Object, default: () => ({}) },
    toasts: { type: Object, default: null },
});

const live = ref({
    status: props.whatsapp?.connection?.status || 'disconnected',
    qr: null,
    phone: props.whatsapp?.connection?.phone_number || null,
    bridge_offline: !props.whatsapp?.bridge_configured,
});
const connecting = ref(false);
let pollTimer = null;

async function poll() {
    try {
        const res = await fetch(route('settings.whatsapp.status'), { headers: { Accept: 'application/json' } });
        if (res.ok) live.value = await res.json();
    } catch {
        // rrjeti dështoi — provoja e radhës e rregullon
    }
}

// Poll i shpeshtë vetëm gjatë çiftimit (QR rifreskohet nga WhatsApp çdo ~20s);
// ndryshe një kontroll i rrallë mjafton.
function schedule() {
    clearInterval(pollTimer);
    pollTimer = setInterval(poll, live.value.status === 'pairing' ? 3000 : 15000);
}
onMounted(() => { poll().then(schedule); });
onBeforeUnmount(() => clearInterval(pollTimer));

function connect() {
    connecting.value = true;
    router.post(route('settings.whatsapp.connect'), {}, {
        preserveScroll: true,
        onFinish: () => { connecting.value = false; poll().then(schedule); },
    });
}

function disconnect() {
    if (!confirm(translate('settingsTabs.whatsapp.confirmDisconnect'))) return;
    router.post(route('settings.whatsapp.disconnect'), {}, {
        preserveScroll: true,
        onSuccess: () => { live.value = { ...live.value, status: 'disconnected', qr: null, phone: null }; schedule(); },
    });
}

const statusLabel = computed(() => ({
    connected: translate('settingsTabs.whatsapp.statusConnected'),
    pairing: translate('settingsTabs.whatsapp.statusPairing'),
    disconnected: translate('settingsTabs.whatsapp.statusDisconnected'),
}[live.value.status] || live.value.status));

const statusClass = computed(() => ({
    connected: 'bg-success-50 text-success-700 border-success-200',
    pairing: 'bg-warning-50 text-warning-700 border-warning-200',
    disconnected: 'bg-neutral-100 text-neutral-600 border-neutral-200',
}[live.value.status] || 'bg-neutral-100 text-neutral-600 border-neutral-200'));
</script>

<template>
    <div class="space-y-5">
        <!-- Paralajmërimi i pranuar i rrezikut (rruga jo-zyrtare e WhatsApp Web) -->
        <div class="rounded-xl border border-warning-300 bg-warning-50 px-4 py-3 text-body-sm text-warning-800" role="alert">
            <b>{{ $t('settingsTabs.whatsapp.warningTitle') }}</b> {{ $t('settingsTabs.whatsapp.warningBody') }}
        </div>

        <Card>
            <template #header>
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h3 class="text-h4 text-primary-900">{{ $t('settingsTabs.whatsapp.title') }}</h3>
                        <p class="text-small text-neutral-500 mt-0.5">{{ $t('settingsTabs.whatsapp.subtitle') }}</p>
                    </div>
                    <span class="rounded-full border px-3 py-1 text-[12px] font-semibold" :class="statusClass">{{ statusLabel }}</span>
                </div>
            </template>

            <!-- Ura offline: udhëzim i qetë, asgjë e thyer -->
            <div v-if="live.bridge_offline" class="rounded-xl border border-neutral-200 bg-neutral-50 px-4 py-6 text-center text-body-sm text-neutral-600">
                {{ $t('settingsTabs.whatsapp.bridgeOffline') }}
            </div>

            <template v-else>
                <div v-if="live.status === 'connected'" class="flex items-center justify-between gap-4">
                    <div>
                        <p class="text-body-sm font-semibold text-primary-900">
                            {{ $t('settingsTabs.whatsapp.connectedAs') }} <span class="tabular-nums">+{{ live.phone }}</span>
                        </p>
                        <p class="text-small text-neutral-500 mt-0.5">{{ $t('settingsTabs.whatsapp.connectedHint') }}</p>
                    </div>
                    <Button variant="ghost" class="text-error-600" @click="disconnect">{{ $t('settingsTabs.whatsapp.disconnect') }}</Button>
                </div>

                <div v-else-if="live.status === 'pairing'" class="flex flex-col items-center gap-3 py-4">
                    <img v-if="live.qr" :src="live.qr" :alt="$t('settingsTabs.whatsapp.qrAlt')" class="h-56 w-56 rounded-xl border border-neutral-200" />
                    <p v-else class="text-body-sm text-neutral-500">{{ $t('settingsTabs.whatsapp.qrLoading') }}</p>
                    <p class="max-w-md text-center text-small text-neutral-600">{{ $t('settingsTabs.whatsapp.qrHint') }}</p>
                    <Button variant="ghost" @click="disconnect">{{ $t('settingsTabs.whatsapp.cancelPairing') }}</Button>
                </div>

                <div v-else class="flex flex-col items-center gap-3 py-6">
                    <p class="max-w-md text-center text-body-sm text-neutral-600">{{ $t('settingsTabs.whatsapp.disconnectedHint') }}</p>
                    <Button variant="primary" :loading="connecting" @click="connect">{{ $t('settingsTabs.whatsapp.connect') }}</Button>
                </div>
            </template>
        </Card>
    </div>
</template>
