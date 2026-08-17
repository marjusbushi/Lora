<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import SettingsSidebar from '@/Components/SettingsSidebar.vue';
import { settingsGroups, visibleSettingsTabs } from '@/Pages/Settings/settingsNavigation';
import {
    Bot, BriefcaseBusiness, CalendarDays, Check, Copy, ExternalLink, Hotel, MessageSquareText,
    PackageSearch, Search, ShieldCheck, Sparkles, SprayCan, UtensilsCrossed,
    WalletCards, Wrench, X,
} from 'lucide-vue-next';

const props = defineProps({
    connection: { type: Object, required: true },
    aiSettings: { type: Object, required: true },
    aiModules: { type: Object, required: true },
    pricingPolicy: { type: Object, required: true },
    recentActions: { type: Array, default: () => [] },
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

const quickPrompts = computed(() => [
    t('loraAi.promptDailySummary'),
    t('loraAi.promptFindReservation'),
    t('loraAi.promptCheckins'),
    t('loraAi.promptComparePricing'),
    t('loraAi.promptOpenIssues'),
    t('loraAi.promptFindFinance'),
]);

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

const actions = {
    'ai.guest_reply.sent': t('loraAi.actionGuestReplySent'),
    'ai.pricing_range.applied': t('loraAi.actionPricingApplied'),
};
</script>

<template>
    <Head title="Lora AI" />
    <AppLayout>
        <div class="pms-settings-shell mx-auto w-full max-w-[1480px]">
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
            <section data-ui="card" class="overflow-hidden border border-neutral-200 bg-white">
                <div data-ui="card-body" data-padding="true" class="grid gap-4 bg-white text-neutral-900 lg:grid-cols-[1fr_auto] lg:items-center">
                    <div class="flex items-start gap-3">
                        <div class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-accent-50 text-accent-700"><Bot class="h-5 w-5" /></div>
                        <div>
                            <div class="mb-1 flex items-center gap-2">
                                <h2 data-ui="card-title">{{ $t('loraAi.chatgptFor', { hotel: connection.hotel }) }}</h2>
                                <span class="rounded-full px-2.5 py-1 text-tiny font-semibold" :class="connection.connected ? 'bg-emerald-50 text-emerald-700' : 'bg-neutral-100 text-neutral-600'">
                                    {{ connection.connected ? $t('loraAi.connected') : $t('loraAi.notConnected') }}
                                </span>
                            </div>
                            <p data-ui="card-copy" class="max-w-2xl text-neutral-500">{{ $t('loraAi.description') }}</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button data-ui="button" type="button" class="inline-flex items-center gap-2 border border-neutral-300 bg-white px-4 text-neutral-700 hover:bg-neutral-50" @click="copyEndpoint">
                            <Check v-if="copied" class="h-4 w-4" /><Copy v-else class="h-4 w-4" />{{ copied ? $t('loraAi.copied') : $t('loraAi.copyMcpUrl') }}
                        </button>
                        <a data-ui="button" :href="connection.chatgptUrl" target="_blank" rel="noopener" class="inline-flex items-center gap-2 bg-accent-700 px-4 text-white hover:bg-accent-800">
                            {{ $t('loraAi.openChatgpt') }} <ExternalLink class="h-4 w-4" />
                        </a>
                    </div>
                </div>
                <div class="settings-status-strip grid gap-3 border-t border-neutral-200 bg-neutral-50 md:grid-cols-3">
                    <div class="flex items-center gap-2 text-neutral-700"><ShieldCheck class="h-4 w-4 text-primary-600" /> {{ $t('loraAi.oauthIsolation') }}</div>
                    <div class="flex items-center gap-2 text-neutral-700"><Check class="h-4 w-4 text-primary-600" /> {{ $t('loraAi.permissionsInherited') }}</div>
                    <div class="flex items-center gap-2 text-neutral-700"><Check class="h-4 w-4 text-primary-600" /> {{ $t('loraAi.approvalsRequired') }}</div>
                </div>
            </section>

            <section class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_420px]">
                <div data-ui="card" class="settings-card-pad border border-neutral-200 bg-white">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-2 text-neutral-900"><Search class="h-4 w-4 text-primary-700" /><h2 data-ui="card-title">{{ $t('loraAi.quickPromptsTitle') }}</h2></div>
                            <p data-ui="card-copy" class="mt-1 text-neutral-500">{{ $t('loraAi.quickPromptsSubtitle') }}</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">{{ $t('loraAi.readOnly') }}</span>
                    </div>
                    <div class="mt-4 grid gap-2 md:grid-cols-2">
                        <button v-for="prompt in quickPrompts" :key="prompt" data-ui="inner-panel" type="button" class="flex items-center justify-between gap-3 border border-neutral-200 text-left text-neutral-700 transition hover:border-primary-300 hover:bg-primary-50/40" @click="copyPrompt(prompt)">
                            <span>{{ prompt }}</span><Check v-if="promptCopied === prompt" class="h-4 w-4 shrink-0 text-emerald-600" /><Copy v-else class="h-4 w-4 shrink-0 text-neutral-400" />
                        </button>
                    </div>
                </div>

                <div data-ui="card" class="settings-card-pad border border-neutral-200 bg-white">
                    <div class="flex items-center gap-2 text-neutral-900"><Sparkles class="h-4 w-4 text-amber-600" /><h2 data-ui="card-title">{{ $t('loraAi.hybridPricingTitle') }}</h2></div>
                    <p data-ui="card-copy" class="mt-1 text-neutral-500">{{ $t('loraAi.hybridPricingSubtitle') }}</p>
                    <div class="mt-4 space-y-2">
                        <div data-ui="compact-row" class="flex items-center justify-between bg-neutral-50"><span class="text-neutral-500">{{ $t('loraAi.livePrice') }}</span><b class="text-neutral-800">{{ $t('loraAi.sellingNow') }}</b></div>
                        <div data-ui="compact-row" class="flex items-center justify-between bg-blue-50"><span class="text-blue-700">{{ $t('loraAi.loraEngine') }}</span><b class="text-blue-900">{{ $t('loraAi.deterministic') }}</b></div>
                        <div data-ui="compact-row" class="flex items-center justify-between bg-violet-50"><span class="text-violet-700">ChatGPT</span><b class="text-violet-900">{{ $t('loraAi.alternativeReason') }}</b></div>
                        <div data-ui="compact-row" class="flex items-center justify-between bg-amber-50"><span class="text-amber-700">{{ $t('loraAi.market') }}</span><b class="text-amber-900">{{ $t('loraAi.whenDataExists') }}</b></div>
                    </div>
                    <p class="mt-3 text-xs leading-5 text-neutral-500">{{ $t('loraAi.deviationNote', { pct: pricingPolicy.maxDeviationPct }) }}</p>
                </div>
            </section>

            <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
                <form class="space-y-4" @submit.prevent="save">
                    <section data-ui="card" class="border border-neutral-200 bg-white">
                        <div data-ui="card-header" class="border-b border-neutral-200">
                            <div class="flex items-center justify-between gap-4">
                                <div><h2>{{ $t('loraAi.dataSearchTitle') }}</h2><p>{{ $t('loraAi.dataSearchSubtitle') }}</p></div>
                                <label class="flex shrink-0 items-center gap-2 text-sm font-semibold text-neutral-700"><span>{{ $t('loraAi.universalSearch') }}</span><input v-model="form.universal_search_enabled" type="checkbox" class="h-5 w-5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" /></label>
                            </div>
                        </div>
                        <div class="divide-y divide-neutral-100">
                            <label class="settings-module-row flex cursor-pointer items-center justify-between gap-4">
                                <span class="flex items-start gap-3"><span class="grid h-9 w-9 place-items-center rounded-lg bg-blue-50 text-blue-600"><CalendarDays class="h-4 w-4" /></span><span><b class="block text-sm text-neutral-900">{{ $t('loraAi.moduleReservations') }}</b><small class="mt-0.5 block text-neutral-500">{{ $t('loraAi.moduleReservationsDesc') }}</small></span></span>
                                <input v-model="form.reservations_enabled" type="checkbox" class="h-5 w-5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                            </label>
                            <label class="settings-module-row flex cursor-pointer items-center justify-between gap-4" :class="!aiModules.channel_manager && 'opacity-50'">
                                <span class="flex items-start gap-3"><span class="grid h-9 w-9 place-items-center rounded-lg bg-violet-50 text-violet-600"><MessageSquareText class="h-4 w-4" /></span><span><b class="block text-sm text-neutral-900">{{ $t('loraAi.moduleMessages') }}</b><small class="mt-0.5 block text-neutral-500">{{ $t('loraAi.moduleMessagesDesc') }}</small></span></span>
                                <input v-model="form.messages_enabled" type="checkbox" :disabled="!aiModules.channel_manager" class="h-5 w-5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                            </label>
                            <label class="settings-module-row flex cursor-pointer items-center justify-between gap-4" :class="!aiModules.smart_pricing && 'opacity-50'">
                                <span class="flex items-start gap-3"><span class="grid h-9 w-9 place-items-center rounded-lg bg-amber-50 text-amber-600"><Sparkles class="h-4 w-4" /></span><span><b class="block text-sm text-neutral-900">{{ $t('loraAi.modulePricing') }}</b><small class="mt-0.5 block text-neutral-500">{{ $t('loraAi.modulePricingDesc') }}</small></span></span>
                                <input v-model="form.pricing_enabled" type="checkbox" :disabled="!aiModules.smart_pricing" class="h-5 w-5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                            </label>
                            <label class="settings-module-row flex cursor-pointer items-center justify-between gap-4" :class="!aiModules.finance && 'opacity-50'">
                                <span class="flex items-start gap-3"><span class="grid h-9 w-9 place-items-center rounded-lg bg-emerald-50 text-emerald-700"><WalletCards class="h-4 w-4" /></span><span><b class="block text-sm text-neutral-900">{{ $t('loraAi.moduleFinance') }}</b><small class="mt-0.5 block text-neutral-500">{{ $t('loraAi.moduleFinanceDesc') }}</small></span></span>
                                <input v-model="form.finance_enabled" type="checkbox" :disabled="!aiModules.finance || !form.universal_search_enabled" class="h-5 w-5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                            </label>
                            <label class="settings-module-row flex cursor-pointer items-center justify-between gap-4" :class="!aiModules.housekeeping && 'opacity-50'">
                                <span class="flex items-start gap-3"><span class="grid h-9 w-9 place-items-center rounded-lg bg-cyan-50 text-cyan-700"><SprayCan class="h-4 w-4" /></span><span><b class="block text-sm text-neutral-900">Housekeeping</b><small class="mt-0.5 block text-neutral-500">{{ $t('loraAi.moduleHousekeepingDesc') }}</small></span></span>
                                <input v-model="form.housekeeping_enabled" type="checkbox" :disabled="!aiModules.housekeeping || !form.universal_search_enabled" class="h-5 w-5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                            </label>
                            <label class="settings-module-row flex cursor-pointer items-center justify-between gap-4">
                                <span class="flex items-start gap-3"><span class="grid h-9 w-9 place-items-center rounded-lg bg-orange-50 text-orange-700"><Wrench class="h-4 w-4" /></span><span><b class="block text-sm text-neutral-900">{{ $t('loraAi.moduleMaintenance') }}</b><small class="mt-0.5 block text-neutral-500">{{ $t('loraAi.moduleMaintenanceDesc') }}</small></span></span>
                                <input v-model="form.maintenance_enabled" type="checkbox" :disabled="!form.universal_search_enabled" class="h-5 w-5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                            </label>
                            <label class="settings-module-row flex cursor-pointer items-center justify-between gap-4" :class="!aiModules.pos && 'opacity-50'">
                                <span class="flex items-start gap-3"><span class="grid h-9 w-9 place-items-center rounded-lg bg-rose-50 text-rose-700"><UtensilsCrossed class="h-4 w-4" /></span><span><b class="block text-sm text-neutral-900">{{ $t('loraAi.modulePos') }}</b><small class="mt-0.5 block text-neutral-500">{{ $t('loraAi.modulePosDesc') }}</small></span></span>
                                <input v-model="form.pos_enabled" type="checkbox" :disabled="!aiModules.pos || !form.universal_search_enabled" class="h-5 w-5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                            </label>
                            <label class="settings-module-row flex cursor-pointer items-center justify-between gap-4" :class="!aiModules.finance && 'opacity-50'">
                                <span class="flex items-start gap-3"><span class="grid h-9 w-9 place-items-center rounded-lg bg-indigo-50 text-indigo-700"><PackageSearch class="h-4 w-4" /></span><span><b class="block text-sm text-neutral-900">{{ $t('loraAi.moduleInventory') }}</b><small class="mt-0.5 block text-neutral-500">{{ $t('loraAi.moduleInventoryDesc') }}</small></span></span>
                                <input v-model="form.inventory_enabled" type="checkbox" :disabled="!aiModules.finance || !form.universal_search_enabled" class="h-5 w-5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                            </label>
                        </div>
                    </section>

                    <section data-ui="card" class="border border-neutral-200 bg-white">
                        <div data-ui="card-header" class="border-b border-neutral-200">
                            <h2>{{ $t('loraAi.protectedActionsTitle') }}</h2>
                            <p>{{ $t('loraAi.protectedActionsSubtitle') }}</p>
                        </div>
                        <div data-ui="card-body" data-padding="true" class="grid gap-4 md:grid-cols-2">
                            <label data-ui="inner-panel" class="border border-neutral-200" :class="!form.messages_enabled && 'opacity-50'">
                                <span class="flex items-center justify-between gap-3"><b class="text-sm text-neutral-900">{{ $t('loraAi.actionGuestReply') }}</b><input v-model="form.guest_reply_enabled" type="checkbox" :disabled="!form.messages_enabled" class="h-5 w-5 rounded border-neutral-300 text-primary-600" /></span>
                                <span class="mt-2 block text-xs leading-5 text-neutral-500">{{ $t('loraAi.actionGuestReplyDesc') }}</span>
                            </label>
                            <label data-ui="inner-panel" class="border border-neutral-200" :class="(!form.messages_enabled || !form.guest_reply_enabled) && 'opacity-50'">
                                <span class="flex items-center justify-between gap-3"><b class="text-sm text-neutral-900">{{ $t('loraAi.actionGuestAutoReply') }}</b><input v-model="form.guest_auto_reply_enabled" type="checkbox" :disabled="!form.messages_enabled || !form.guest_reply_enabled" class="h-5 w-5 rounded border-neutral-300 text-primary-600" /></span>
                                <span class="mt-2 block text-xs leading-5 text-neutral-500">{{ $t('loraAi.actionGuestAutoReplyDesc') }}</span>
                            </label>
                            <label data-ui="inner-panel" class="border border-[#c9ecd9]" :class="(!form.messages_enabled || !form.guest_reply_enabled) && 'opacity-50'">
                                <span class="flex items-center justify-between gap-3"><b class="text-sm text-neutral-900">{{ $t('loraAi.actionWhatsAppAutoReply') }}</b><input v-model="form.whatsapp_auto_reply_enabled" type="checkbox" :disabled="!form.messages_enabled || !form.guest_reply_enabled" class="h-5 w-5 rounded border-neutral-300 text-primary-600" /></span>
                                <span class="mt-2 block text-xs leading-5 text-neutral-500">{{ $t('loraAi.actionWhatsAppAutoReplyDesc') }}</span>
                            </label>
                            <label data-ui="inner-panel" class="border border-neutral-200" :class="!form.pricing_enabled && 'opacity-50'">
                                <span class="flex items-center justify-between gap-3"><b class="text-sm text-neutral-900">{{ $t('loraAi.actionApplyPrices') }}</b><input v-model="form.price_apply_enabled" type="checkbox" :disabled="!form.pricing_enabled" class="h-5 w-5 rounded border-neutral-300 text-primary-600" /></span>
                                <span class="mt-2 block text-xs leading-5 text-neutral-500">{{ $t('loraAi.actionApplyPricesDesc') }}</span>
                            </label>
                            <label data-ui="inner-panel" class="border border-neutral-200 md:col-span-2" :class="!form.pricing_enabled && 'opacity-50'">
                                <span class="flex items-center justify-between gap-3"><span><b class="block text-sm text-neutral-900">{{ $t('loraAi.actionAltRecommendation') }}</b><small class="mt-1 block text-neutral-500">{{ $t('loraAi.actionAltRecommendationDesc') }}</small></span><input v-model="form.ai_price_recommendations_enabled" type="checkbox" :disabled="!form.pricing_enabled" class="h-5 w-5 rounded border-neutral-300 text-primary-600" /></span>
                                <span class="mt-2 block text-xs leading-5 text-neutral-500">{{ $t('loraAi.actionAltRecommendationNote') }}</span>
                            </label>
                        </div>
                        <div data-ui="card-footer" class="settings-sticky-footer border-t border-neutral-200">
                            <button v-if="connection.revocable" type="button" class="text-sm font-semibold text-red-600 hover:text-red-700" @click="disconnect">{{ $t('loraAi.disconnectChatgpt') }}</button><span v-else />
                            <button data-ui="button" type="submit" :disabled="form.processing" class="bg-primary-700 px-5 text-white hover:bg-primary-800 disabled:opacity-50">{{ form.processing ? $t('loraAi.saving') : $t('loraAi.savePermissions') }}</button>
                        </div>
                    </section>
                </form>

                <aside class="space-y-4">
                    <section data-ui="card" class="settings-card-pad border border-neutral-200 bg-white">
                        <h3 data-ui="card-title">{{ $t('loraAi.howToConnect') }}</h3>
                        <ol class="mt-4 space-y-4 text-body-sm text-neutral-600">
                            <li class="flex gap-3"><span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-primary-50 text-xs font-bold text-primary-700">1</span><span>{{ $t('loraAi.step1') }}</span></li>
                            <li class="flex gap-3"><span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-primary-50 text-xs font-bold text-primary-700">2</span><span>{{ $t('loraAi.step2') }}</span></li>
                            <li class="flex gap-3"><span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-primary-50 text-xs font-bold text-primary-700">3</span><span>{{ $t('loraAi.step3') }}</span></li>
                        </ol>
                        <code class="mt-4 block break-all rounded-lg bg-neutral-900 p-3 text-xs leading-5 text-neutral-100">{{ connection.endpoint }}</code>
                    </section>
                    <section data-ui="card" class="settings-card-pad border border-neutral-200 bg-white">
                        <h3 data-ui="card-title">{{ $t('loraAi.recentAiActivity') }}</h3>
                        <div v-if="recentActions.length" class="mt-3 divide-y divide-neutral-100">
                            <div v-for="item in recentActions" :key="`${item.action}-${item.created_at}`" class="py-3 text-sm">
                                <b class="block text-neutral-800">{{ actions[item.action] || item.action }}</b>
                                <span class="text-xs text-neutral-500">{{ item.created_at }}</span>
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
