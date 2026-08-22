<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import SettingsSidebar from '@/Components/SettingsSidebar.vue';
import { settingsGroups, visibleSettingsTabs } from '@/Pages/Settings/settingsNavigation';
import { getIntlLocale } from '@/i18n';
import {
    AlertTriangle, Banknote, Bot, BriefcaseBusiness, CalendarDays, Check, Clock, Copy, ExternalLink,
    Hotel, MessageSquareText, PackageSearch, Search, ShieldCheck, Sparkles, SprayCan,
    UtensilsCrossed, WalletCards, Wrench, X,
} from 'lucide-vue-next';

const props = defineProps({
    connection: { type: Object, required: true },
    geminiKeyHealth: { type: Object, default: null },
    aiSettings: { type: Object, required: true },
    aiModules: { type: Object, required: true },
    pricingPolicy: { type: Object, required: true },
    recentActions: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({ replies: 0, bookings: 0, bookingRevenue: 0 }) },
});

const copied = ref(false);
const promptCopied = ref('');
const settingsSearch = ref('');
const form = useForm({ ...props.aiSettings });
const page = usePage();
const { locale, t } = useI18n();
const modules = computed(() => page.props.modules || {});
const isAdmin = computed(() => page.props.auth.user?.role === 'admin');
const groupIcons = { Hotel, BriefcaseBusiness, Bot, ShieldCheck };
const navigationTabs = computed(() => visibleSettingsTabs(modules.value).map((tab) => ({
    ...tab,
    label: t(tab.labelKey),
})));
const navigationGroups = computed(() => settingsGroups.map((group) => ({
    ...group,
    label: t(group.labelKey),
    tabs: navigationTabs.value.filter((tab) => tab.group === group.id),
})));
const settingsSearchResults = computed(() => {
    const query = settingsSearch.value.trim().toLocaleLowerCase(locale.value);
    if (!query) return [];

    return navigationTabs.value.filter((tab) => tab.label.toLocaleLowerCase(locale.value).includes(query)).slice(0, 6);
});
const breadcrumbs = computed(() => isAdmin.value
    ? [{ label: t('admin.sidebar.dashboard'), href: '/dashboard' }, { label: t('settingsTabs.menu.settings'), href: '/pms/settings' }, { label: 'Lora AI' }]
    : [{ label: t('admin.sidebar.dashboard'), href: '/dashboard' }, { label: 'Lora AI' }]);

// Kartela e Lorës: emri efektiv ndjek LIVE fushën e identitetit.
const assistantName = computed(() => form.assistant_name?.trim() || 'Lora');
const onDuty = computed(() => form.messages_enabled && form.guest_reply_enabled);
const revenueLabel = computed(() => {
    // Shuma vjen nga total_amount_base — monedha BAZË e hotelit, jo ajo e shitjes.
    const currency = page.props.settings?.currency || 'EUR';
    try {
        return new Intl.NumberFormat(getIntlLocale(), { style: 'currency', currency, maximumFractionDigits: 0 }).format(Number(props.stats.bookingRevenue || 0));
    } catch {
        return `${props.stats.bookingRevenue}`;
    }
});

// Fikja e një shkalle FIK realisht vlerat e varura — jo vetëm i çaktivizon në UI:
// job-et e backend-it lexojnë çelësin e vet direkt (gjetje Codex, PR #542).
watch(() => form.messages_enabled, (on) => {
    if (!on) form.guest_reply_enabled = false;
});
watch(() => form.guest_reply_enabled, (on) => {
    if (!on) {
        form.guest_auto_reply_enabled = false;
        form.whatsapp_auto_reply_enabled = false;
    }
});
watch(() => form.whatsapp_auto_reply_enabled, (on) => {
    if (!on) form.whatsapp_booking_enabled = false;
});
watch(() => form.pricing_enabled, (on) => {
    if (!on) {
        form.price_apply_enabled = false;
        form.ai_price_recommendations_enabled = false;
    }
});

// Shkalla e autonomisë — rendi tregon edhe varësinë: çdo shkallë kërkon ato para saj.
const ladder = computed(() => [
    {
        n: 1, field: 'messages_enabled', titleKey: 'loraAi.ladderReads',
        disabled: !props.aiModules.channel_manager,
    },
    {
        n: 2, field: 'guest_reply_enabled', titleKey: 'loraAi.ladderDrafts',
        disabled: !form.messages_enabled,
    },
    {
        n: 3, field: 'guest_auto_reply_enabled', titleKey: 'loraAi.ladderAuto', badge: 'Lora AI',
        detailsKey: 'loraAi.actionGuestAutoReplyDesc', summaryKey: 'loraAi.howDecides',
        disabled: !form.messages_enabled || !form.guest_reply_enabled,
    },
    {
        n: 4, field: 'whatsapp_auto_reply_enabled', titleKey: 'loraAi.ladderWhatsApp', hot: true,
        detailsKey: 'loraAi.actionWhatsAppAutoReplyDesc', summaryKey: 'loraAi.safetyTerms',
        disabled: !form.messages_enabled || !form.guest_reply_enabled,
    },
    {
        n: 5, field: 'whatsapp_booking_enabled', titleKey: 'loraAi.ladderBooking', hot: true, badgeKey: 'loraAi.badgePok',
        detailsKey: 'loraAi.actionWhatsAppBookingDesc', summaryKey: 'loraAi.safetyTerms',
        disabled: !form.messages_enabled || !form.guest_reply_enabled || !form.whatsapp_auto_reply_enabled,
    },
]);

// Të dhënat që sheh Lora — pllakat. Ngjyrat ndjekin kodin ekzistues të moduleve.
const dataTiles = computed(() => [
    { field: 'reservations_enabled', labelKey: 'loraAi.moduleReservationsShort', icon: CalendarDays, chip: 'bg-blue-50 text-blue-600', disabled: false },
    { field: 'messages_enabled', labelKey: 'loraAi.moduleMessagesShort', icon: MessageSquareText, chip: 'bg-violet-50 text-violet-600', disabled: !props.aiModules.channel_manager },
    { field: 'pricing_enabled', labelKey: 'loraAi.modulePricingShort', icon: Sparkles, chip: 'bg-amber-50 text-amber-600', disabled: !props.aiModules.smart_pricing },
    { field: 'finance_enabled', labelKey: 'loraAi.moduleFinance', icon: WalletCards, chip: 'bg-emerald-50 text-emerald-700', disabled: !props.aiModules.finance || !form.universal_search_enabled },
    { field: 'housekeeping_enabled', label: 'Housekeeping', icon: SprayCan, chip: 'bg-cyan-50 text-cyan-700', disabled: !props.aiModules.housekeeping || !form.universal_search_enabled },
    { field: 'maintenance_enabled', labelKey: 'loraAi.moduleMaintenance', icon: Wrench, chip: 'bg-orange-50 text-orange-700', disabled: !form.universal_search_enabled },
    { field: 'pos_enabled', labelKey: 'loraAi.modulePosShort', icon: UtensilsCrossed, chip: 'bg-rose-50 text-rose-700', disabled: !props.aiModules.pos || !form.universal_search_enabled },
    { field: 'inventory_enabled', labelKey: 'loraAi.moduleInventory', icon: PackageSearch, chip: 'bg-indigo-50 text-indigo-700', disabled: !props.aiModules.finance || !form.universal_search_enabled },
]);

const quickPrompts = computed(() => [
    { shortKey: 'loraAi.promptShortDaily', fullKey: 'loraAi.promptDailySummary' },
    { shortKey: 'loraAi.promptShortFind', fullKey: 'loraAi.promptFindReservation' },
    { shortKey: 'loraAi.promptShortCheckins', fullKey: 'loraAi.promptCheckins' },
    { shortKey: 'loraAi.promptShortPricing', fullKey: 'loraAi.promptComparePricing' },
    { shortKey: 'loraAi.promptShortIssues', fullKey: 'loraAi.promptOpenIssues' },
    { shortKey: 'loraAi.promptShortFinance', fullKey: 'loraAi.promptFindFinance' },
]);

// Aktiviteti në gjuhë njeriu — kurrë çelësa teknikë; fallback i lexueshëm.
const ACTION_META = {
    'message.ai_reply': { labelKey: 'loraAi.actAiReply', tone: 'ok', icon: Check },
    'message.ai_reply_failed': { labelKey: 'loraAi.actAiReplyFailed', tone: 'bad', icon: AlertTriangle },
    'message.ai_booking_hold': { labelKey: 'loraAi.actBookingHold', tone: 'gold', icon: Clock },
    'message.ai_booking_confirmed': { labelKey: 'loraAi.actBookingConfirmed', tone: 'gold', icon: Banknote },
    'message.ai_booking_hold_released': { labelKey: 'loraAi.actHoldReleased', tone: 'neutral', icon: Clock },
    'ai.guest_reply.sent': { labelKey: 'loraAi.actionGuestReplySent', tone: 'ok', icon: Check },
    'ai.pricing_range.applied': { labelKey: 'loraAi.actionPricingApplied', tone: 'ok', icon: Sparkles },
};
const TONE_CLASSES = {
    ok: 'bg-accent-50 text-accent-700',
    bad: 'bg-red-50 text-red-600',
    gold: 'bg-amber-50 text-amber-600',
    neutral: 'bg-neutral-100 text-neutral-500',
};

function actionMeta(action) {
    return ACTION_META[action] || { label: String(action).replace(/[._]/g, ' '), tone: 'neutral', icon: Check };
}

function actionTime(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;

    return date.toLocaleString(getIntlLocale(), { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

function save() {
    form.put(route('lora-ai.update'), { preserveScroll: true });
}

async function copyEndpoint() {
    await navigator.clipboard.writeText(props.connection.endpoint);
    copied.value = true;
    setTimeout(() => copied.value = false, 1800);
}

async function copyPrompt(prompt) {
    await navigator.clipboard.writeText(prompt);
    promptCopied.value = prompt;
    setTimeout(() => promptCopied.value = '', 1600);
}

function disconnect() {
    if (window.confirm(t('loraAi.confirmDisconnect'))) {
        router.delete(route('lora-ai.disconnect'), { preserveScroll: true });
    }
}

function navigateSettingsTab(tab) {
    settingsSearch.value = '';
    router.visit(tab.href || route('settings.index', { tab: tab.id }));
}

function selectSettingsGroup(groupId) {
    const firstTab = navigationGroups.value.find((group) => group.id === groupId)?.tabs[0];
    if (firstTab) navigateSettingsTab(firstTab);
}

function selectSettingsPage(tabId) {
    const tab = navigationTabs.value.find((item) => item.id === tabId);
    if (tab) navigateSettingsTab(tab);
}
</script>

<template>
    <Head title="Lora AI" />
    <AppLayout>
        <div class="pms-settings-shell mx-auto w-full max-w-[1480px]">
            <!-- Shëndeti i çelësit Gemini (task #382): alarmi ditor PARA se Lora të heshtë live -->
            <div v-if="geminiKeyHealth" class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3">
                <p class="text-sm font-bold text-red-800">⚠️ {{ $t('loraAi.keyHealthTitle') }}</p>
                <p class="mt-1 text-[13px] leading-relaxed text-red-700">{{ $t('loraAi.keyHealthBody') }}</p>
                <p v-if="geminiKeyHealth.error" class="mt-0.5 text-[12px] text-red-600/90">{{ geminiKeyHealth.error }}</p>
            </div>

            <header class="settings-page-heading flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                <div>
                    <PageHeader :title="$t('loraAi.title')" :breadcrumbs="breadcrumbs" />
                    <p class="mt-1 text-body-sm text-neutral-500">{{ $t('loraAi.subtitle') }}</p>
                </div>

                <div v-if="isAdmin" class="settings-search relative w-full md:w-[340px]">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                    <input v-model="settingsSearch" type="search" class="w-full border bg-white py-2 pl-9 pr-9" :placeholder="$t('settingsTabs.index.searchPlaceholder')">
                    <button v-if="settingsSearch" type="button" class="absolute right-2 top-1/2 grid h-7 w-7 -translate-y-1/2 place-items-center rounded-md text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" @click="settingsSearch = ''">
                        <X class="h-4 w-4" />
                    </button>
                    <div v-if="settingsSearch" class="settings-search-results absolute right-0 top-[calc(100%+8px)] z-30 w-full overflow-hidden rounded-xl border border-neutral-200 bg-white p-1.5 shadow-xl">
                        <button v-for="tab in settingsSearchResults" :key="tab.id" type="button" class="flex w-full items-center justify-between rounded-lg px-3 py-2.5 text-left text-body-sm text-neutral-700 hover:bg-accent-50 hover:text-accent-800" @click="navigateSettingsTab(tab)">
                            <span>{{ tab.label }}</span>
                            <span class="text-tiny text-neutral-400">{{ navigationGroups.find((group) => group.id === tab.group)?.label }}</span>
                        </button>
                        <p v-if="!settingsSearchResults.length" class="px-3 py-3 text-body-sm text-neutral-500">{{ $t('settingsTabs.index.noResults') }}</p>
                    </div>
                </div>
            </header>

            <nav v-if="isAdmin" class="settings-category-tabs mt-5 hidden grid-cols-4 gap-2 rounded-xl border border-neutral-200 bg-white p-2 shadow-card lg:grid" :aria-label="$t('settingsTabs.index.categoriesAria')">
                <button v-for="group in navigationGroups" :key="group.id" type="button" class="settings-category-tab flex min-h-11 items-center justify-center gap-2 rounded-lg px-3 text-body-sm font-semibold transition" :class="group.id === 'automation' ? 'bg-accent-700 text-white shadow-sm' : 'text-neutral-600 hover:bg-neutral-50 hover:text-neutral-900'" :aria-pressed="group.id === 'automation'" @click="selectSettingsGroup(group.id)">
                    <component :is="groupIcons[group.icon]" class="h-4 w-4" />
                    <span>{{ group.label }}</span>
                    <span class="rounded-full px-1.5 py-0.5 text-tiny" :class="group.id === 'automation' ? 'bg-white/15 text-white' : 'bg-neutral-100 text-neutral-500'">{{ group.tabs.length }}</span>
                </button>
            </nav>

            <div v-if="isAdmin" class="settings-mobile-nav mt-4 grid gap-2 sm:grid-cols-2 lg:hidden">
                <label>
                    <span class="sr-only">{{ locale === 'sq' ? 'Kategoria' : 'Category' }}</span>
                    <select class="w-full" value="automation" @change="selectSettingsGroup($event.target.value)">
                        <option v-for="group in navigationGroups" :key="group.id" :value="group.id">{{ group.label }}</option>
                    </select>
                </label>
                <label>
                    <span class="sr-only">{{ locale === 'sq' ? 'Faqja' : 'Page' }}</span>
                    <select class="w-full" value="ai" @change="selectSettingsPage($event.target.value)">
                        <option v-for="tab in navigationTabs.filter((item) => item.group === 'automation')" :key="tab.id" :value="tab.id">{{ tab.label }}</option>
                    </select>
                </label>
            </div>

            <div class="settings-layout mt-4 flex flex-col gap-4 lg:flex-row lg:items-start">
                <SettingsSidebar v-if="isAdmin" active-item="lora-ai" active-group-only />

                <div class="settings-content min-w-0 flex-1 space-y-4">

                    <!-- KARTELA E LORËS — kush është dhe sa vlen -->
                    <section data-ui="card" class="relative overflow-hidden border border-neutral-200 bg-white">
                        <div class="absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-accent-700 via-accent-500 to-amber-500" />
                        <div data-ui="card-body" data-padding="true" class="flex flex-wrap items-center gap-5 bg-white">
                            <div class="relative grid h-16 w-16 shrink-0 place-items-center rounded-2xl bg-accent-50 text-accent-700">
                                <Bot class="h-8 w-8" :stroke-width="1.8" />
                                <span class="absolute -bottom-0.5 -right-0.5 h-3.5 w-3.5 rounded-full border-2 border-white" :class="onDuty ? 'bg-emerald-500' : 'bg-neutral-300'" />
                            </div>
                            <div class="min-w-[220px] flex-1">
                                <h2 class="text-xl font-extrabold tracking-tight text-neutral-900">{{ assistantName }}</h2>
                                <p class="text-body-sm text-neutral-500">{{ $t('loraAi.heroRole') }} · {{ connection.hotel }}</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-tiny font-semibold" :class="onDuty ? 'bg-emerald-50 text-emerald-700' : 'bg-neutral-100 text-neutral-600'">
                                        <span class="h-1.5 w-1.5 rounded-full" :class="onDuty ? 'animate-pulse bg-emerald-500' : 'bg-neutral-400'" />
                                        {{ onDuty ? $t('loraAi.onDuty') : $t('loraAi.offDuty') }}
                                    </span>
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-tiny font-semibold" :class="connection.connected ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">
                                        ChatGPT: {{ connection.connected ? $t('loraAi.connected') : $t('loraAi.notConnected') }}
                                    </span>
                                </div>
                            </div>
                            <div class="flex divide-x divide-neutral-200">
                                <div class="px-5 first:pl-0">
                                    <b class="block text-xl font-extrabold tracking-tight text-neutral-900">{{ stats.replies }}</b>
                                    <span class="text-tiny text-neutral-500">{{ $t('loraAi.statReplies') }}</span>
                                </div>
                                <div class="px-5">
                                    <b class="block text-xl font-extrabold tracking-tight text-neutral-900">{{ stats.bookings }}</b>
                                    <span class="text-tiny text-neutral-500">{{ $t('loraAi.statBookings') }}</span>
                                </div>
                                <div v-if="stats.bookingRevenue !== null" class="px-5 last:pr-0">
                                    <b class="block text-xl font-extrabold tracking-tight text-amber-600">{{ revenueLabel }}</b>
                                    <span class="text-tiny text-neutral-500">{{ $t('loraAi.statRevenue') }}</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
                        <form class="space-y-4" @submit.prevent="save">

                            <!-- SHKALLA E AUTONOMISË -->
                            <section data-ui="card" class="settings-card-pad border border-neutral-200 bg-white">
                                <div class="flex items-center gap-2">
                                    <h2 data-ui="card-title">{{ $t('loraAi.ladderTitle') }}</h2>
                                    <span class="rounded-md bg-accent-50 px-2 py-0.5 text-tiny font-bold uppercase tracking-wide text-accent-800">{{ $t('loraAi.ladderTag') }}</span>
                                </div>

                                <div class="relative mt-4 space-y-2 pl-11">
                                    <div class="absolute bottom-4 left-[15px] top-4 w-0.5 bg-accent-200" aria-hidden="true" />
                                    <div v-for="step in ladder" :key="step.field" class="relative rounded-xl border p-3.5 pl-4 transition" :class="[step.hot ? 'border-accent-200 bg-accent-50/40' : 'border-neutral-200 bg-white', step.disabled && 'opacity-60']">
                                        <span class="absolute -left-11 top-3.5 grid h-8 w-8 place-items-center rounded-full bg-accent-50 text-body-sm font-bold text-accent-800 ring-1 ring-accent-200" :class="!form[step.field] && 'bg-neutral-100 text-neutral-400 ring-neutral-200'">{{ step.n }}</span>
                                        <div class="flex items-center gap-3">
                                            <div class="min-w-0 flex-1">
                                                <b class="text-sm font-semibold text-neutral-900">
                                                    {{ $t(step.titleKey) }}
                                                    <span v-if="step.badge" class="ml-1 rounded-md bg-emerald-50 px-1.5 py-0.5 align-[2px] text-tiny font-bold text-emerald-700">{{ step.badge }}</span>
                                                    <span v-else-if="step.badgeKey" class="ml-1 rounded-md bg-amber-50 px-1.5 py-0.5 align-[2px] text-tiny font-bold text-amber-700">{{ $t(step.badgeKey) }}</span>
                                                </b>
                                                <details v-if="step.detailsKey" class="mt-0.5">
                                                    <summary class="cursor-pointer text-tiny font-semibold text-accent-700 hover:text-accent-800">{{ $t(step.summaryKey) }}</summary>
                                                    <p class="mt-1.5 rounded-lg bg-neutral-50 px-3 py-2 text-xs leading-5 text-neutral-600">{{ $t(step.detailsKey) }}</p>
                                                </details>
                                            </div>
                                            <label class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center">
                                                <input v-model="form[step.field]" type="checkbox" :disabled="step.disabled" class="peer sr-only">
                                                <span class="absolute inset-0 rounded-full bg-neutral-300 transition peer-checked:bg-accent-600 peer-focus-visible:ring-2 peer-focus-visible:ring-accent-500 peer-focus-visible:ring-offset-2 peer-disabled:cursor-not-allowed" />
                                                <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5" />
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <!-- ÇMIMET -->
                            <section data-ui="card" class="settings-card-pad border border-neutral-200 bg-white">
                                <div class="flex items-center gap-2">
                                    <h2 data-ui="card-title">{{ $t('loraAi.pricingSectionTitle') }}</h2>
                                    <span class="rounded-md bg-accent-50 px-2 py-0.5 text-tiny font-bold uppercase tracking-wide text-accent-800">{{ $t('loraAi.deviationTag', { pct: pricingPolicy.maxDeviationPct }) }}</span>
                                </div>
                                <div class="mt-4 space-y-2">
                                    <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-neutral-200 px-4 py-3" :class="!form.pricing_enabled && 'opacity-60'">
                                        <b class="flex-1 text-sm font-semibold text-neutral-900">{{ $t('loraAi.actionApplyPrices') }}</b>
                                        <span class="relative inline-flex h-6 w-11 shrink-0 items-center">
                                            <input v-model="form.price_apply_enabled" type="checkbox" :disabled="!form.pricing_enabled" class="peer sr-only">
                                            <span class="absolute inset-0 rounded-full bg-neutral-300 transition peer-checked:bg-accent-600 peer-focus-visible:ring-2 peer-focus-visible:ring-accent-500 peer-focus-visible:ring-offset-2 peer-disabled:cursor-not-allowed" />
                                            <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5" />
                                        </span>
                                    </label>
                                    <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-neutral-200 px-4 py-3" :class="!form.pricing_enabled && 'opacity-60'">
                                        <b class="flex-1 text-sm font-semibold text-neutral-900">{{ $t('loraAi.actionAltRecommendation') }}</b>
                                        <span class="relative inline-flex h-6 w-11 shrink-0 items-center">
                                            <input v-model="form.ai_price_recommendations_enabled" type="checkbox" :disabled="!form.pricing_enabled" class="peer sr-only">
                                            <span class="absolute inset-0 rounded-full bg-neutral-300 transition peer-checked:bg-accent-600 peer-focus-visible:ring-2 peer-focus-visible:ring-accent-500 peer-focus-visible:ring-offset-2 peer-disabled:cursor-not-allowed" />
                                            <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5" />
                                        </span>
                                    </label>
                                </div>
                            </section>

                            <!-- SI PREZANTOHET -->
                            <section data-ui="card" class="settings-card-pad border border-neutral-200 bg-white" :class="!form.messages_enabled && 'opacity-60'">
                                <h2 data-ui="card-title">{{ $t('loraAi.identitySectionTitle') }}</h2>
                                <div class="mt-4 grid gap-3 md:grid-cols-[200px_1fr]">
                                    <label class="block">
                                        <span class="text-tiny font-bold uppercase tracking-wide text-neutral-500">{{ $t('loraAi.identityName') }}</span>
                                        <input v-model="form.assistant_name" type="text" maxlength="40" :disabled="!form.messages_enabled" class="mt-1 w-full rounded-lg border-neutral-300 text-sm focus:border-accent-500 focus:ring-accent-500" />
                                    </label>
                                    <label class="block">
                                        <span class="text-tiny font-bold uppercase tracking-wide text-neutral-500">{{ $t('loraAi.identityCharacter') }}</span>
                                        <textarea v-model="form.assistant_character" rows="2" maxlength="600" :disabled="!form.messages_enabled" class="mt-1 w-full rounded-lg border-neutral-300 text-sm focus:border-accent-500 focus:ring-accent-500"></textarea>
                                    </label>
                                </div>
                                <p class="mt-2 text-xs leading-5 text-neutral-500">{{ $t('loraAi.identityHint') }}</p>
                            </section>

                            <!-- TË DHËNAT QË SHEH LORA -->
                            <section data-ui="card" class="settings-card-pad border border-neutral-200 bg-white">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <h2 data-ui="card-title">{{ $t('loraAi.dataTitle') }}</h2>
                                    <label class="flex cursor-pointer items-center gap-2.5 rounded-full bg-neutral-50 px-3.5 py-1.5 text-body-sm font-semibold text-neutral-600">
                                        {{ $t('loraAi.universalSearch') }}
                                        <span class="relative inline-flex h-6 w-11 shrink-0 items-center">
                                            <input v-model="form.universal_search_enabled" type="checkbox" class="peer sr-only">
                                            <span class="absolute inset-0 rounded-full bg-neutral-300 transition peer-checked:bg-accent-600 peer-focus-visible:ring-2 peer-focus-visible:ring-accent-500 peer-focus-visible:ring-offset-2" />
                                            <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5" />
                                        </span>
                                    </label>
                                </div>
                                <div class="mt-4 grid gap-2.5 sm:grid-cols-2">
                                    <label v-for="tile in dataTiles" :key="tile.field" class="flex cursor-pointer items-center gap-2.5 rounded-xl border border-neutral-200 px-3 py-2.5 transition hover:border-accent-300" :class="tile.disabled && 'opacity-50'">
                                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg" :class="tile.chip"><component :is="tile.icon" class="h-4 w-4" /></span>
                                        <b class="min-w-0 flex-1 truncate text-sm font-semibold text-neutral-900" :title="tile.labelKey ? $t(tile.labelKey) : tile.label">{{ tile.labelKey ? $t(tile.labelKey) : tile.label }}</b>
                                        <span class="relative inline-flex h-6 w-11 shrink-0 items-center">
                                            <input v-model="form[tile.field]" type="checkbox" :disabled="tile.disabled" class="peer sr-only">
                                            <span class="absolute inset-0 rounded-full bg-neutral-300 transition peer-checked:bg-accent-600 peer-focus-visible:ring-2 peer-focus-visible:ring-accent-500 peer-focus-visible:ring-offset-2 peer-disabled:cursor-not-allowed" />
                                            <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5" />
                                        </span>
                                    </label>
                                </div>
                                <p class="mt-3 text-xs text-neutral-400">{{ $t('loraAi.dataFootnote') }}</p>
                            </section>

                            <!-- SHIRITI I RUAJTJES — gjithmonë i dukshëm -->
                            <div class="sticky bottom-4 z-20 flex items-center justify-between gap-3 rounded-xl border border-neutral-200 bg-white/95 px-4 py-3 shadow-lg backdrop-blur">
                                <span class="flex items-center gap-2 text-body-sm" :class="form.isDirty ? 'font-semibold text-amber-700' : 'text-neutral-400'">
                                    <span class="h-2 w-2 rounded-full" :class="form.isDirty ? 'bg-amber-500' : 'bg-neutral-300'" />
                                    {{ form.isDirty ? $t('loraAi.unsavedChanges') : $t('loraAi.allSaved') }}
                                </span>
                                <div class="flex items-center gap-4">
                                    <button v-if="connection.revocable" type="button" class="text-sm font-semibold text-neutral-400 hover:text-red-600" @click="disconnect">{{ $t('loraAi.disconnectChatgpt') }}</button>
                                    <button data-ui="button" type="submit" :disabled="form.processing" class="bg-accent-700 px-6 text-white hover:bg-accent-800 disabled:opacity-50">{{ form.processing ? $t('loraAi.saving') : $t('loraAi.savePermissions') }}</button>
                                </div>
                            </div>
                        </form>

                        <aside class="space-y-4">
                            <!-- CHATGPT PËR TY -->
                            <section data-ui="card" class="settings-card-pad border border-neutral-200 bg-white">
                                <div class="flex items-center justify-between gap-3">
                                    <h2 data-ui="card-title">{{ $t('loraAi.chatgptForYou') }}</h2>
                                    <span class="rounded-full px-2.5 py-1 text-tiny font-bold" :class="connection.connected ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">
                                        {{ connection.connected ? $t('loraAi.connected') : $t('loraAi.notConnected') }}
                                    </span>
                                </div>
                                <div class="mt-3 flex items-stretch gap-2">
                                    <code class="min-w-0 flex-1 break-all rounded-lg bg-neutral-900 px-3 py-2 text-[11px] leading-5 text-neutral-100">{{ connection.endpoint }}</code>
                                    <button type="button" class="rounded-lg border border-neutral-200 px-3 text-xs font-semibold text-neutral-600 hover:border-accent-500 hover:text-accent-700" @click="copyEndpoint">
                                        {{ copied ? $t('loraAi.copied') : $t('loraAi.copyShort') }}
                                    </button>
                                </div>
                                <div class="mt-3 flex items-center gap-4">
                                    <a data-ui="button" :href="connection.chatgptUrl" target="_blank" rel="noopener" class="inline-flex items-center gap-2 bg-accent-700 px-4 text-white hover:bg-accent-800">
                                        {{ $t('loraAi.openChatgpt') }} <ExternalLink class="h-4 w-4" />
                                    </a>
                                    <details>
                                        <summary class="cursor-pointer text-xs font-semibold text-accent-700 hover:text-accent-800">{{ $t('loraAi.howToConnect') }}</summary>
                                        <ol class="mt-2 space-y-2 text-xs leading-5 text-neutral-600">
                                            <li class="flex gap-2"><span class="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-accent-50 text-tiny font-bold text-accent-800">1</span><span>{{ $t('loraAi.step1') }}</span></li>
                                            <li class="flex gap-2"><span class="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-accent-50 text-tiny font-bold text-accent-800">2</span><span>{{ $t('loraAi.step2') }}</span></li>
                                            <li class="flex gap-2"><span class="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-accent-50 text-tiny font-bold text-accent-800">3</span><span>{{ $t('loraAi.step3') }}</span></li>
                                        </ol>
                                    </details>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-1.5">
                                    <button v-for="prompt in quickPrompts" :key="prompt.shortKey" type="button" class="inline-flex items-center gap-1.5 rounded-full bg-accent-50 px-3 py-1.5 text-xs font-medium text-accent-800 transition hover:bg-accent-100" @click="copyPrompt($t(prompt.fullKey))">
                                        {{ $t(prompt.shortKey) }}
                                        <Check v-if="promptCopied === $t(prompt.fullKey)" class="h-3 w-3 text-emerald-600" />
                                        <Copy v-else class="h-3 w-3 text-accent-400" />
                                    </button>
                                </div>
                                <p class="mt-3 flex items-center gap-1.5 text-xs text-neutral-400"><ShieldCheck class="h-3.5 w-3.5" /> {{ $t('loraAi.oauthIsolation') }}</p>
                            </section>

                            <!-- ÇFARË BËRI LORA -->
                            <section data-ui="card" class="settings-card-pad border border-neutral-200 bg-white">
                                <h2 data-ui="card-title">{{ $t('loraAi.activityTitle') }}</h2>
                                <div v-if="recentActions.length" class="mt-2 divide-y divide-neutral-100">
                                    <div v-for="item in recentActions" :key="`${item.action}-${item.created_at}`" class="flex items-center gap-3 py-2.5">
                                        <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full" :class="TONE_CLASSES[actionMeta(item.action).tone]">
                                            <component :is="actionMeta(item.action).icon" class="h-3.5 w-3.5" />
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <b class="block truncate text-sm font-semibold" :class="actionMeta(item.action).tone === 'bad' ? 'text-red-700' : 'text-neutral-800'">
                                                {{ actionMeta(item.action).labelKey ? $t(actionMeta(item.action).labelKey) : actionMeta(item.action).label }}
                                            </b>
                                            <span class="text-tiny text-neutral-400">{{ actionTime(item.created_at) }}</span>
                                        </div>
                                    </div>
                                </div>
                                <p v-else class="mt-3 text-sm text-neutral-500">{{ $t('loraAi.noAiActions') }}</p>
                            </section>
                        </aside>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
