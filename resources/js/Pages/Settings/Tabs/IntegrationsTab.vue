<script setup>
import Button from '@/Components/UI/Button.vue';
import { router } from '@inertiajs/vue3';
import {
    Bot,
    Cable,
    CircleDollarSign,
    FileCheck2,
    RefreshCw,
    SearchCheck,
    Waves,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    integrations: { type: Array, default: () => [] },
    toasts: Object,
});

const emit = defineEmits(['select-tab']);
const { locale } = useI18n();
const testing = ref(null);

const copy = {
    channex: {
        name: 'Channex',
        sq: 'Rezervime dhe disponibilitet nga kanalet OTA.',
        en: 'OTA reservations and availability synchronization.',
        icon: Cable,
    },
    pok: {
        name: 'POK Payments',
        sq: 'Pagesa online për rezervimet direkte.',
        en: 'Online payments for direct bookings.',
        icon: CircleDollarSign,
    },
    fature_al: {
        name: 'fature.al',
        sq: 'Fiskalizimi i faturave të hotelit dhe POS-it.',
        en: 'Fiscalization for hotel and POS invoices.',
        icon: FileCheck2,
    },
    gemini: {
        name: 'Google Gemini',
        sq: 'Asistenti AI dhe analizat inteligjente.',
        en: 'AI assistant and intelligent analysis.',
        icon: Bot,
    },
    exchange_rates: {
        name: 'ExchangeRate API',
        sq: 'Kurset ditore të këmbimit për financën.',
        en: 'Daily exchange rates for finance.',
        icon: Waves,
    },
    serp_api: {
        name: 'SerpAPI',
        sq: 'Krahasimi i çmimeve të hotelit me tregun.',
        en: 'Market-rate comparison for the hotel.',
        icon: SearchCheck,
    },
};

const categories = computed(() => [
    { id: 'fiscalization', sq: 'Fiskalizimi', en: 'Fiscalization' },
    { id: 'channels', sq: 'Kanalet e shitjes', en: 'Sales channels' },
    { id: 'payments', sq: 'Pagesat', en: 'Payments' },
    { id: 'ai_data', sq: 'AI & të dhënat', en: 'AI & data' },
].map((category) => ({
    ...category,
    label: locale.value === 'sq' ? category.sq : category.en,
    items: props.integrations.filter((item) => item.category === category.id),
})).filter((category) => category.items.length));

const configuredCount = computed(() => props.integrations.filter((item) => item.configured).length);

const statusLabel = (item) => {
    if (item.configured) return locale.value === 'sq' ? 'Konfiguruar' : 'Configured';
    if (item.status === 'needs_attention') return locale.value === 'sq' ? 'Kërkon konfigurim' : 'Needs setup';
    return locale.value === 'sq' ? 'Jo aktiv' : 'Inactive';
};

const statusClass = (item) => item.configured
    ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
    : item.status === 'needs_attention'
        ? 'bg-amber-50 text-amber-700 ring-amber-200'
        : 'bg-neutral-100 text-neutral-500 ring-neutral-200';

function testConnection(item) {
    testing.value = item.id;
    router.post(route('settings.integrations.test', item.id), {}, {
        preserveScroll: true,
        onSuccess: (page) => {
            const error = page.props.flash?.error;
            if (error) props.toasts?.error(error);
            else props.toasts?.success(locale.value === 'sq' ? 'Lidhja funksionon.' : 'Connection successful.');
        },
        onError: () => props.toasts?.error(locale.value === 'sq' ? 'Testi i lidhjes dështoi.' : 'Connection test failed.'),
        onFinish: () => { testing.value = null; },
    });
}
</script>

<template>
    <div class="space-y-6">
        <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-card">
            <div class="flex flex-col gap-5 border-b border-neutral-200 bg-gradient-to-br from-[#f3faf7] to-white p-6 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#2d6a4f]">{{ $t('integrationCenter.title') }}</p>
                    <h2 class="mt-2 text-2xl font-semibold text-neutral-900">
                        {{ locale === 'sq' ? 'Lidhjet e hotelit' : 'Hotel integrations' }}
                    </h2>
                    <p class="mt-1 max-w-2xl text-sm leading-6 text-neutral-500">
                        {{ locale === 'sq'
                            ? 'Statusi i të gjitha shërbimeve të jashtme në një vend. Kredencialet kryesore menaxhohen në mënyrë të sigurt nga Lora.'
                            : 'The status of every external service in one place. Core credentials are securely managed by Lora.' }}
                    </p>
                </div>
                <div class="shrink-0 rounded-xl border border-emerald-100 bg-white px-5 py-3 text-center shadow-sm">
                    <p class="text-2xl font-semibold text-emerald-700">{{ configuredCount }}/{{ integrations.length }}</p>
                    <p class="text-xs text-neutral-500">{{ locale === 'sq' ? 'të konfiguruara' : 'configured' }}</p>
                </div>
            </div>
        </section>

        <section v-for="category in categories" :key="category.id">
            <h3 class="mb-3 text-xs font-bold uppercase tracking-[0.12em] text-neutral-400">{{ category.label }}</h3>
            <div class="grid gap-4 xl:grid-cols-2">
                <article
                    v-for="item in category.items"
                    :key="item.id"
                    class="group rounded-2xl border border-neutral-200 bg-white p-5 shadow-card transition hover:border-neutral-300 hover:shadow-md"
                >
                    <div class="flex items-start gap-4">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-[#edf7f2] text-[#2d6a4f]">
                            <component :is="copy[item.id].icon" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h4 class="font-semibold text-neutral-900">{{ copy[item.id].name }}</h4>
                                <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset" :class="statusClass(item)">
                                    {{ statusLabel(item) }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm leading-5 text-neutral-500">{{ locale === 'sq' ? copy[item.id].sq : copy[item.id].en }}</p>

                            <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-neutral-100 pt-4">
                                <span v-if="item.environment" class="rounded-lg bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">
                                    {{ item.environment === 'sandbox' ? 'Sandbox / Test' : 'Production / Live' }}
                                </span>
                                <span v-if="item.last_test_status" class="text-xs" :class="item.last_test_status === 'success' ? 'text-emerald-700' : 'text-red-600'">
                                    {{ item.last_test_status === 'success'
                                        ? (locale === 'sq' ? 'Lidhja u verifikua' : 'Connection verified')
                                        : (locale === 'sq' ? 'Testi i fundit dështoi' : 'Last test failed') }}
                                </span>
                                <span v-if="item.managed_by === 'lora'" class="mr-auto text-xs text-neutral-400">
                                    {{ locale === 'sq' ? 'Menaxhohet nga Lora Control Panel' : 'Managed in Lora Control Panel' }}
                                </span>
                                <span v-else class="mr-auto" />

                                <Button
                                    v-if="item.test_supported"
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    :disabled="testing === item.id"
                                    @click="testConnection(item)"
                                >
                                    <RefreshCw class="mr-1.5 h-3.5 w-3.5" :class="testing === item.id && 'animate-spin'" />
                                    {{ locale === 'sq' ? 'Testo lidhjen' : 'Test connection' }}
                                </Button>
                                <Button
                                    v-else-if="item.settings_tab"
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    @click="emit('select-tab', item.settings_tab)"
                                >
                                    {{ locale === 'sq' ? 'Konfiguro' : 'Configure' }}
                                </Button>
                            </div>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        <p class="rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-xs leading-5 text-blue-700">
            {{ locale === 'sq'
                ? 'Siguri: token-et dhe çelësat privatë ruhen të enkriptuar në server dhe nuk dërgohen në shfletues.'
                : 'Security: tokens and private keys are encrypted on the server and are never sent to the browser.' }}
        </p>
    </div>
</template>
