<script setup>
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import { router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    Bot,
    Cable,
    CheckCircle2,
    CircleDollarSign,
    CircleOff,
    FileCheck2,
    RefreshCw,
    SearchCheck,
    ShieldCheck,
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
const attentionCount = computed(() => props.integrations.filter((item) => item.status === 'needs_attention').length);
const inactiveCount = computed(() => props.integrations.filter((item) => item.status === 'inactive').length);

const integrationCopy = (item) => copy[item.id] || {
    name: item.id,
    sq: 'Shërbim i jashtëm i lidhur me hotelin.',
    en: 'External service connected to the hotel.',
    icon: Cable,
};

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

const settingsTab = (item) => item.settings_tab || (item.id === 'channex' ? 'channel-manager' : null);

const ownerLabel = (item) => item.managed_by === 'lora'
    ? (locale.value === 'sq' ? 'Menaxhohet nga stafi Lora' : 'Managed by Lora staff')
    : (locale.value === 'sq' ? 'Menaxhohet nga hoteli' : 'Managed by the hotel');

function formatLastTest(value) {
    if (!value) return null;

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;

    return new Intl.DateTimeFormat(locale.value === 'sq' ? 'sq-AL' : 'en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

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
    <Card :padding="false">
        <template #header>
            <div class="flex w-full flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3>{{ locale === 'sq' ? 'Qendra e integrimeve' : 'Integration center' }}</h3>
                    <p class="max-w-2xl">
                        {{ locale === 'sq'
                            ? 'Statusi, testimi dhe hyrja drejt konfigurimit për çdo shërbim të jashtëm.'
                            : 'Status, testing and configuration access for every external service.' }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200">
                        <CheckCircle2 class="h-3.5 w-3.5" />
                        {{ configuredCount }} {{ locale === 'sq' ? 'aktive' : 'active' }}
                    </span>
                    <span v-if="attentionCount" class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-700 ring-1 ring-inset ring-amber-200">
                        <AlertTriangle class="h-3.5 w-3.5" />
                        {{ attentionCount }} {{ locale === 'sq' ? 'kërkojnë vëmendje' : 'need attention' }}
                    </span>
                    <span v-if="inactiveCount" class="inline-flex items-center gap-1.5 rounded-full bg-neutral-100 px-2.5 py-1 text-[11px] font-semibold text-neutral-600 ring-1 ring-inset ring-neutral-200">
                        <CircleOff class="h-3.5 w-3.5" />
                        {{ inactiveCount }} {{ locale === 'sq' ? 'jo aktive' : 'inactive' }}
                    </span>
                </div>
            </div>
        </template>

        <div class="divide-y divide-neutral-100">
            <section v-for="category in categories" :key="category.id" class="px-4 py-4 sm:px-5">
                <h4 class="mb-2 px-1 text-[10px] font-bold uppercase tracking-[0.14em] text-neutral-400">{{ category.label }}</h4>
                <div class="divide-y divide-neutral-100 rounded-xl border border-neutral-200 bg-white">
                <article
                    v-for="item in category.items"
                    :key="item.id"
                    class="group grid gap-3 p-4 transition hover:bg-neutral-50/70 lg:grid-cols-[minmax(0,1fr)_190px_auto] lg:items-center"
                >
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-[10px] bg-accent-50 text-accent-700">
                            <component :is="integrationCopy(item).icon" class="h-[18px] w-[18px]" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h5 class="font-semibold text-neutral-900">{{ integrationCopy(item).name }}</h5>
                                <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset" :class="statusClass(item)">
                                    {{ statusLabel(item) }}
                                </span>
                            </div>
                            <p class="mt-1 text-xs leading-5 text-neutral-500">{{ locale === 'sq' ? integrationCopy(item).sq : integrationCopy(item).en }}</p>
                        </div>
                    </div>

                    <div class="space-y-1 lg:border-l lg:border-neutral-100 lg:pl-4">
                        <p class="text-[11px] font-medium text-neutral-600">{{ ownerLabel(item) }}</p>
                        <p v-if="item.environment" class="text-[11px] text-neutral-400">
                            {{ item.environment === 'sandbox' ? 'Sandbox / Test' : 'Production / Live' }}
                        </p>
                        <p v-if="item.last_test_status" class="text-[11px]" :class="item.last_test_status === 'success' ? 'text-emerald-700' : 'text-red-600'">
                            {{ item.last_test_status === 'success'
                                ? (locale === 'sq' ? 'Testi i fundit: në rregull' : 'Last test: successful')
                                : (locale === 'sq' ? 'Testi i fundit: dështoi' : 'Last test: failed') }}
                            <span v-if="formatLastTest(item.last_tested_at)" class="text-neutral-400"> · {{ formatLastTest(item.last_tested_at) }}</span>
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                        <Button
                            v-if="item.test_supported"
                            type="button"
                            size="sm"
                            variant="outline"
                            :disabled="testing === item.id"
                            @click="testConnection(item)"
                        >
                            <RefreshCw class="h-3.5 w-3.5" :class="testing === item.id && 'animate-spin'" />
                            {{ locale === 'sq' ? 'Testo' : 'Test' }}
                        </Button>
                        <Button
                            v-if="settingsTab(item)"
                            type="button"
                            size="sm"
                            variant="outline"
                            @click="emit('select-tab', settingsTab(item))"
                        >
                            {{ item.managed_by === 'lora'
                                ? (locale === 'sq' ? 'Shiko' : 'View')
                                : (locale === 'sq' ? 'Konfiguro' : 'Configure') }}
                            <ArrowRight class="h-3.5 w-3.5" />
                        </Button>
                    </div>
                </article>
                </div>
            </section>
        </div>

        <template #footer>
            <p class="flex items-start gap-2 text-[11px] leading-5 text-neutral-500">
                <ShieldCheck class="mt-0.5 h-4 w-4 shrink-0 text-accent-600" />
                {{ locale === 'sq'
                    ? 'Token-et dhe çelësat privatë ruhen të enkriptuar. Kjo faqe shfaq vetëm statusin dhe nuk dublikon konfigurimet.'
                    : 'Tokens and private keys are encrypted. This page only shows status and does not duplicate configuration.' }}
            </p>
        </template>
    </Card>
</template>
