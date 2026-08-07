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
import { getIntlLocale, translate } from '@/i18n';

const props = defineProps({
    integrations: { type: Array, default: () => [] },
    toasts: Object,
});

const emit = defineEmits(['select-tab']);
const testing = ref(null);

const copy = {
    channex: { name: 'Channex', descKey: 'settingsTabs.integrations.services.channex', icon: Cable },
    pok: { name: 'POK Payments', descKey: 'settingsTabs.integrations.services.pok', icon: CircleDollarSign },
    fature_al: { name: 'fature.al', descKey: 'settingsTabs.integrations.services.fatureAl', icon: FileCheck2 },
    gemini: { name: 'Google Gemini', descKey: 'settingsTabs.integrations.services.gemini', icon: Bot },
    exchange_rates: { name: 'ExchangeRate API', descKey: 'settingsTabs.integrations.services.exchangeRates', icon: Waves },
    serp_api: { name: 'SerpAPI', descKey: 'settingsTabs.integrations.services.serpApi', icon: SearchCheck },
};

const categories = computed(() => [
    { id: 'fiscalization', labelKey: 'settingsTabs.integrations.categories.fiscalization' },
    { id: 'channels', labelKey: 'settingsTabs.integrations.categories.channels' },
    { id: 'payments', labelKey: 'settingsTabs.integrations.categories.payments' },
    { id: 'ai_data', labelKey: 'settingsTabs.integrations.categories.aiData' },
].map((category) => ({
    ...category,
    label: translate(category.labelKey),
    items: props.integrations.filter((item) => item.category === category.id),
})).filter((category) => category.items.length));

const configuredCount = computed(() => props.integrations.filter((item) => item.configured).length);
const attentionCount = computed(() => props.integrations.filter((item) => item.status === 'needs_attention').length);
const inactiveCount = computed(() => props.integrations.filter((item) => item.status === 'inactive').length);

const integrationCopy = (item) => copy[item.id] || {
    name: item.id,
    descKey: 'settingsTabs.integrations.services.fallback',
    icon: Cable,
};

const statusLabel = (item) => {
    if (item.configured) return translate('settingsTabs.integrations.statusConfigured');
    if (item.status === 'needs_attention') return translate('settingsTabs.integrations.statusNeedsSetup');
    return translate('settingsTabs.integrations.statusInactive');
};

const statusClass = (item) => item.configured
    ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
    : item.status === 'needs_attention'
        ? 'bg-amber-50 text-amber-700 ring-amber-200'
        : 'bg-neutral-100 text-neutral-500 ring-neutral-200';

const settingsTab = (item) => item.settings_tab || (item.id === 'channex' ? 'channel-manager' : null);

const ownerLabel = (item) => item.managed_by === 'lora'
    ? translate('settingsTabs.integrations.managedByLora')
    : translate('settingsTabs.integrations.managedByHotel');

function formatLastTest(value) {
    if (!value) return null;

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;

    return new Intl.DateTimeFormat(getIntlLocale(), {
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
            else props.toasts?.success(translate('settingsTabs.integrations.connectionOk'));
        },
        onError: () => props.toasts?.error(translate('settingsTabs.integrations.connectionFailed')),
        onFinish: () => { testing.value = null; },
    });
}
</script>

<template>
    <Card :padding="false">
        <template #header>
            <div class="flex w-full flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3>{{ $t('settingsTabs.integrations.title') }}</h3>
                    <p class="max-w-2xl">
                        {{ $t('settingsTabs.integrations.subtitle') }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200">
                        <CheckCircle2 class="h-3.5 w-3.5" />
                        {{ configuredCount }} {{ $t('settingsTabs.integrations.active') }}
                    </span>
                    <span v-if="attentionCount" class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-700 ring-1 ring-inset ring-amber-200">
                        <AlertTriangle class="h-3.5 w-3.5" />
                        {{ attentionCount }} {{ $t('settingsTabs.integrations.needAttention') }}
                    </span>
                    <span v-if="inactiveCount" class="inline-flex items-center gap-1.5 rounded-full bg-neutral-100 px-2.5 py-1 text-[11px] font-semibold text-neutral-600 ring-1 ring-inset ring-neutral-200">
                        <CircleOff class="h-3.5 w-3.5" />
                        {{ inactiveCount }} {{ $t('settingsTabs.integrations.inactive') }}
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
                            <p class="mt-1 text-xs leading-5 text-neutral-500">{{ $t(integrationCopy(item).descKey) }}</p>
                        </div>
                    </div>

                    <div class="space-y-1 lg:border-l lg:border-neutral-100 lg:pl-4">
                        <p class="text-[11px] font-medium text-neutral-600">{{ ownerLabel(item) }}</p>
                        <p v-if="item.environment" class="text-[11px] text-neutral-400">
                            {{ item.environment === 'sandbox' ? 'Sandbox / Test' : 'Production / Live' }}
                        </p>
                        <p v-if="item.last_test_status" class="text-[11px]" :class="item.last_test_status === 'success' ? 'text-emerald-700' : 'text-red-600'">
                            {{ item.last_test_status === 'success'
                                ? $t('settingsTabs.integrations.lastTestOk')
                                : $t('settingsTabs.integrations.lastTestFailed') }}
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
                            {{ $t('settingsTabs.integrations.test') }}
                        </Button>
                        <Button
                            v-if="settingsTab(item)"
                            type="button"
                            size="sm"
                            variant="outline"
                            @click="emit('select-tab', settingsTab(item))"
                        >
                            {{ item.managed_by === 'lora'
                                ? $t('settingsTabs.integrations.view')
                                : $t('settingsTabs.integrations.configure') }}
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
                {{ $t('settingsTabs.integrations.footer') }}
            </p>
        </template>
    </Card>
</template>
