<script setup>
import { useForm, router } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import BillingPageHeader from '@/Components/SuperAdmin/BillingPageHeader.vue';
import { Coins, RotateCw } from 'lucide-vue-next';

const props = defineProps({ currencies: Object });

const form = useForm({
    enabled: props.currencies.enabled,
    api_key: '',
    clear_key: false,
});

const currencyNames = {
    USD: 'Dollar amerikan', GBP: 'Paund britanik', ALL: 'Lek shqiptar', CHF: 'Frangë zvicerane',
    TRY: 'Lirë turke', JPY: 'Jen japonez', CAD: 'Dollar kanadez', AUD: 'Dollar australian',
    SEK: 'Koronë suedeze', NOK: 'Koronë norvegjeze',
};

function save() {
    form.put('/super-admin/currencies', { preserveScroll: true, onSuccess: () => form.reset('api_key', 'clear_key') });
}

function refreshNow() {
    router.post('/super-admin/currencies/refresh', {}, { preserveScroll: true });
}

function dateTime(value) {
    return value ? new Intl.DateTimeFormat('sq-AL', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : null;
}
</script>

<template>
    <SuperAdminLayout title="Monedhat — Lora Control Panel">
        <main class="sa-page max-w-[1080px] space-y-4">
            <BillingPageHeader title="Monedhat & kurset" subtitle="Një marrje në ditë nga ExchangeRate-API për GJITHË platformën — çdo hotel lexon të njëjtat kurse (baza EUR). Kursi ngrihet në momentin e dokumentit; dokumentet e vjetra s'ndryshojnë kurrë." />

            <section class="sa-card">
                <div class="sa-card-header">
                    <div>
                        <h2 class="sa-card-title">Integrimi (exchangerate-api.com)</h2>
                        <p class="sa-card-subtitle">Hotelet nuk konfigurojnë asgjë — zgjedhin vetëm Automatike ose Manuale te Settings → Monedhat.</p>
                    </div>
                    <span class="sa-icon-box bg-emerald-50 text-emerald-700"><Coins class="sa-icon" /></span>
                </div>

                <form class="space-y-4 px-4 pb-4" @submit.prevent="save">
                    <label class="flex items-center gap-2.5 text-xs font-semibold text-neutral-700">
                        <input v-model="form.enabled" type="checkbox" class="h-4 w-4 rounded border-neutral-300 text-emerald-700 focus:ring-emerald-600">
                        Aktive
                    </label>

                    <div>
                        <label class="mb-1 block text-xs font-semibold text-neutral-700">Çelësi API</label>
                        <input
                            v-model="form.api_key"
                            type="password"
                            autocomplete="off"
                            :placeholder="currencies.configured ? `I ruajtur: ${currencies.api_key_hint} — plotëso vetëm për ta ndërruar` : 'Vendos çelësin API'"
                            class="w-full max-w-md rounded-lg border-neutral-300 text-sm focus:border-emerald-600 focus:ring-emerald-600"
                        >
                        <p v-if="form.errors.api_key" class="mt-1 text-xs text-red-600">{{ form.errors.api_key }}</p>
                    </div>

                    <label v-if="currencies.configured" class="flex items-center gap-2.5 text-xs font-semibold text-neutral-700">
                        <input v-model="form.clear_key" type="checkbox" class="h-4 w-4 rounded border-neutral-300 text-red-600 focus:ring-red-500">
                        Hiq çelësin e ruajtur
                    </label>

                    <div class="flex items-center gap-3">
                        <button type="submit" class="sa-button" :disabled="form.processing">Ruaj</button>
                        <button type="button" class="sa-button !bg-neutral-900" :disabled="!currencies.configured || !currencies.enabled" @click="refreshNow">
                            <RotateCw class="sa-icon" /> Rifresko tani
                        </button>
                        <span v-if="currencies.updated_at" class="text-[11px] text-neutral-500">rifreskuar: {{ dateTime(currencies.updated_at) }}</span>
                    </div>
                </form>
            </section>

            <section class="sa-card">
                <div class="sa-card-header">
                    <div>
                        <h2 class="sa-card-title">Kurset aktuale (1 € =)</h2>
                        <p class="sa-card-subtitle">Këto kurse shpërndahen te të gjitha pronat.</p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left">
                        <thead>
                            <tr class="sa-table-head">
                                <th class="px-4 py-2.5 font-bold">Kodi</th>
                                <th class="px-4 py-2.5 font-bold">Monedha</th>
                                <th class="px-4 py-2.5 text-right font-bold">Kursi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="code in currencies.tracked" :key="code" class="hover:bg-neutral-50">
                                <td class="px-4 py-3"><strong class="sa-table-primary">{{ code }}</strong></td>
                                <td class="px-4 py-3 text-xs text-neutral-600">{{ currencyNames[code] || code }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs font-semibold">{{ currencies.rates[code] ?? '—' }}</td>
                            </tr>
                            <tr v-if="!Object.keys(currencies.rates || {}).length">
                                <td colspan="3" class="px-4 py-10 text-center">
                                    <p class="text-xs font-semibold text-neutral-700">Nuk ka ende kurse.</p>
                                    <p class="sa-table-meta">Vendos çelësin, aktivizo dhe kliko Rifresko tani.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </SuperAdminLayout>
</template>
