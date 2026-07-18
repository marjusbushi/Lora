<script setup>
import PageHeader from '@/Components/UI/PageHeader.vue';
import SettingsSidebar from '@/Components/SettingsSidebar.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import AuditLogsPage from '@/Pages/AuditLogs/Index.vue';
import UsersPage from '@/Pages/Users/Index.vue';
import { usePage } from '@inertiajs/vue3';
import { Bot, BriefcaseBusiness, Hotel, Search, ShieldCheck, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AboutTab from './Tabs/AboutTab.vue';
import AiTab from './Tabs/AiTab.vue';
import AmenitiesTab from './Tabs/AmenitiesTab.vue';
import BookingPoliciesTab from './Tabs/BookingPoliciesTab.vue';
import CurrenciesTab from './Tabs/CurrenciesTab.vue';
import FinancialTab from './Tabs/FinancialTab.vue';
import FloorsTab from './Tabs/FloorsTab.vue';
import HotelTab from './Tabs/HotelTab.vue';
import HousekeepingTab from './Tabs/HousekeepingTab.vue';
import IntegrationsTab from './Tabs/IntegrationsTab.vue';
import MarketRatesTab from './Tabs/MarketRatesTab.vue';
import MenuTab from './Tabs/MenuTab.vue';
import NotificationsTab from './Tabs/NotificationsTab.vue';
import PricingProgramsTab from './Tabs/PricingProgramsTab.vue';
import RoomTypesTab from './Tabs/RoomTypesTab.vue';
import SecurityTab from './Tabs/SecurityTab.vue';
import WebsiteTab from './Tabs/WebsiteTab.vue';
import { settingsGroups, visibleSettingsTabs } from './settingsNavigation';

const props = defineProps({
    settings: Object,
    checklistDefaults: { type: Object, default: () => ({}) },
    roomTypes: Array,
    menuCategories: Array,
    inventoryItems: { type: Array, default: () => [] },
    inventoryWarehouses: { type: Array, default: () => [] },
    floors: Array,
    amenities: Array,
    userManagement: { type: Object, default: () => ({}) },
    auditHistory: { type: Object, default: () => ({}) },
    integrations: { type: Array, default: () => [] },
});

const toasts = ref(null);
const modules = computed(() => usePage().props.modules || {});
const { locale } = useI18n();
const search = ref('');
const groupIcons = { Hotel, BriefcaseBusiness, Bot, ShieldCheck };

const requestedTab = new URLSearchParams(usePage().url.split('?')[1] || '').get('tab');
const tabs = computed(() => visibleSettingsTabs(modules.value).map((tab) => ({
    ...tab,
    label: locale.value === 'sq' ? tab.labelSq : tab.labelEn,
})));
const groups = computed(() => settingsGroups.map((group) => ({
    ...group,
    label: locale.value === 'sq' ? group.labelSq : group.labelEn,
    tabs: tabs.value.filter((tab) => tab.group === group.id),
})));
const validTabs = visibleSettingsTabs(modules.value).map((tab) => tab.id);
const activeTab = ref(validTabs.includes(requestedTab) ? requestedTab : 'hotel');
const activeGroup = computed(() => tabs.value.find((tab) => tab.id === activeTab.value)?.group || 'hotel');
const searchResults = computed(() => {
    const query = search.value.trim().toLocaleLowerCase(locale.value);
    if (!query) return [];

    return tabs.value.filter((tab) => tab.label.toLocaleLowerCase(locale.value).includes(query)).slice(0, 6);
});
const generalIntegrations = computed(() => props.integrations);
const channelManagerIntegrations = computed(() => props.integrations.filter((item) => item.id === 'channex'));

function selectTab(tab) {
    activeTab.value = tab;

    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
}

function selectGroup(groupId) {
    const firstTab = groups.value.find((group) => group.id === groupId)?.tabs[0];
    if (firstTab) selectTab(firstTab.id);
}

function selectSearchResult(tab) {
    search.value = '';
    if (tab.href) {
        window.location.href = tab.href;
        return;
    }

    selectTab(tab.id);
}

function selectMobileTab(tabId) {
    const tab = tabs.value.find((item) => item.id === tabId);
    if (tab) selectSearchResult(tab);
}
</script>

<template>
    <AppLayout>
        <div class="pms-settings-shell mx-auto w-full max-w-[1480px]">
            <header class="settings-page-heading flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                <div>
                    <PageHeader
                        :title="$t('accountCenter.settingsTitle')"
                        :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: $t('accountCenter.settingsTitle') }]"
                    />
                    <p class="mt-1 text-body-sm text-neutral-500">{{ $t('accountCenter.settingsSubtitle') }}</p>
                </div>

                <div class="settings-search relative w-full md:w-[340px]">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                    <input v-model="search" type="search" class="w-full border bg-white py-2 pl-9 pr-9" :placeholder="locale === 'sq' ? 'Kërko konfigurim…' : 'Search settings…'">
                    <button v-if="search" type="button" class="absolute right-2 top-1/2 grid h-7 w-7 -translate-y-1/2 place-items-center rounded-md text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" @click="search = ''">
                        <X class="h-4 w-4" />
                    </button>
                    <div v-if="search" class="settings-search-results absolute right-0 top-[calc(100%+8px)] z-30 w-full overflow-hidden rounded-xl border border-neutral-200 bg-white p-1.5 shadow-xl">
                        <button v-for="tab in searchResults" :key="tab.id" type="button" class="flex w-full items-center justify-between rounded-lg px-3 py-2.5 text-left text-body-sm text-neutral-700 hover:bg-accent-50 hover:text-accent-800" @click="selectSearchResult(tab)">
                            <span>{{ tab.label }}</span>
                            <span class="text-tiny text-neutral-400">{{ groups.find((group) => group.id === tab.group)?.label }}</span>
                        </button>
                        <p v-if="!searchResults.length" class="px-3 py-3 text-body-sm text-neutral-500">{{ locale === 'sq' ? 'Nuk u gjet konfigurim.' : 'No settings found.' }}</p>
                    </div>
                </div>
            </header>

            <nav class="settings-category-tabs mt-5 hidden grid-cols-4 gap-2 rounded-xl border border-neutral-200 bg-white p-2 shadow-card lg:grid" :aria-label="locale === 'sq' ? 'Kategoritë e konfigurimit' : 'Settings categories'">
                <button v-for="group in groups" :key="group.id" type="button" class="settings-category-tab flex min-h-11 items-center justify-center gap-2 rounded-lg px-3 text-body-sm font-semibold transition" :class="group.id === activeGroup ? 'bg-accent-700 text-white shadow-sm' : 'text-neutral-600 hover:bg-neutral-50 hover:text-neutral-900'" :aria-pressed="group.id === activeGroup" @click="selectGroup(group.id)">
                    <component :is="groupIcons[group.icon]" class="h-4 w-4" />
                    <span>{{ group.label }}</span>
                    <span class="rounded-full px-1.5 py-0.5 text-tiny" :class="group.id === activeGroup ? 'bg-white/15 text-white' : 'bg-neutral-100 text-neutral-500'">{{ group.tabs.length }}</span>
                </button>
            </nav>

            <div class="settings-mobile-nav mt-4 grid gap-2 sm:grid-cols-2 lg:hidden">
                <label>
                    <span class="sr-only">{{ locale === 'sq' ? 'Kategoria' : 'Category' }}</span>
                    <select class="w-full" :value="activeGroup" @change="selectGroup($event.target.value)">
                        <option v-for="group in groups" :key="group.id" :value="group.id">{{ group.label }}</option>
                    </select>
                </label>
                <label>
                    <span class="sr-only">{{ locale === 'sq' ? 'Faqja' : 'Page' }}</span>
                    <select class="w-full" :value="activeTab" @change="selectMobileTab($event.target.value)">
                        <option v-for="tab in tabs.filter((item) => item.group === activeGroup)" :key="tab.id" :value="tab.id">{{ tab.label }}</option>
                    </select>
                </label>
            </div>

            <div class="settings-layout mt-4 flex flex-col gap-4 lg:flex-row lg:items-start">
                <SettingsSidebar :active-item="activeTab" interactive active-group-only @select="selectTab" />

                <main class="settings-content min-w-0 flex-1">
                    <HotelTab v-if="activeTab === 'hotel'" :settings="settings.hotel || {}" :toasts="toasts" />
                    <WebsiteTab v-else-if="activeTab === 'website'" :settings="settings.hotel || {}" :toasts="toasts" />
                    <AboutTab v-else-if="activeTab === 'about'" :settings="settings.about || {}" :toasts="toasts" />
                    <BookingPoliciesTab v-else-if="activeTab === 'booking-policies'" :settings="settings.hotel || {}" :toasts="toasts" />
                    <RoomTypesTab v-else-if="activeTab === 'room-types'" :room-types="roomTypes" :amenities="amenities" :toasts="toasts" />
                    <AmenitiesTab v-else-if="activeTab === 'amenities'" :amenities="amenities" :toasts="toasts" />
                    <FloorsTab v-else-if="activeTab === 'floors'" :floors="floors" :toasts="toasts" />
                    <MenuTab v-else-if="activeTab === 'menu'" :categories="menuCategories" :inventory-items="inventoryItems" :warehouses="inventoryWarehouses" :inventory-enabled="modules.finance === true" :currency-symbol="settings.financial?.default_currency_symbol || '€'" :toasts="toasts" />
                    <HousekeepingTab v-else-if="activeTab === 'housekeeping'" :settings="settings.housekeeping || {}" :checklist-defaults="checklistDefaults" :toasts="toasts" />
                    <FinancialTab v-else-if="activeTab === 'financial'" :settings="settings.financial || {}" :toasts="toasts" />
                    <CurrenciesTab v-else-if="activeTab === 'currencies'" :settings="settings.currencies || {}" :toasts="toasts" />
                    <PricingProgramsTab v-else-if="activeTab === 'pricing-programs'" :settings="settings.pricing_programs || {}" :financial="settings.financial || {}" :toasts="toasts" />
                    <MarketRatesTab v-else-if="activeTab === 'market-rates'" :settings="settings.market_rates || {}" :toasts="toasts" />
                    <IntegrationsTab v-else-if="activeTab === 'integrations'" :integrations="generalIntegrations" :toasts="toasts" @select-tab="selectTab" />
                    <AiTab v-else-if="activeTab === 'ai'" :settings="settings.ai || {}" :toasts="toasts" />
                    <IntegrationsTab v-else-if="activeTab === 'channel-manager'" :integrations="channelManagerIntegrations" :toasts="toasts" @select-tab="selectTab" />
                    <UsersPage v-else-if="activeTab === 'users'" v-bind="userManagement" embedded />
                    <NotificationsTab v-else-if="activeTab === 'notifications'" :settings="settings.notifications || {}" :hotel-email="settings.hotel?.email || ''" :toasts="toasts" />
                    <SecurityTab v-else-if="activeTab === 'security'" />
                    <AuditLogsPage v-else-if="activeTab === 'history'" v-bind="auditHistory" embedded />
                </main>
            </div>
        </div>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
