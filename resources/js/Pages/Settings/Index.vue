<script setup>
import PageHeader from '@/Components/UI/PageHeader.vue';
import SettingsSidebar from '@/Components/SettingsSidebar.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import AuditLogsPage from '@/Pages/AuditLogs/Index.vue';
import UsersPage from '@/Pages/Users/Index.vue';
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AboutTab from './Tabs/AboutTab.vue';
import AiTab from './Tabs/AiTab.vue';
import AmenitiesTab from './Tabs/AmenitiesTab.vue';
import CurrenciesTab from './Tabs/CurrenciesTab.vue';
import FinancialTab from './Tabs/FinancialTab.vue';
import FloorsTab from './Tabs/FloorsTab.vue';
import HotelTab from './Tabs/HotelTab.vue';
import HousekeepingTab from './Tabs/HousekeepingTab.vue';
import IntegrationsTab from './Tabs/IntegrationsTab.vue';
import MarketRatesTab from './Tabs/MarketRatesTab.vue';
import MenuTab from './Tabs/MenuTab.vue';
import PricingProgramsTab from './Tabs/PricingProgramsTab.vue';
import RoomTypesTab from './Tabs/RoomTypesTab.vue';
import WebsiteTab from './Tabs/WebsiteTab.vue';

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

const allTabs = [
    { id: 'hotel', labelSq: 'Të dhënat e hotelit', labelEn: 'Hotel information', group: 'hotel' },
    { id: 'website', labelSq: 'Faqja Web', labelEn: 'Website', group: 'hotel' },
    { id: 'about', labelSq: 'Rreth Nesh', labelEn: 'About page', group: 'hotel' },
    { id: 'room-types', labelSq: 'Tipet e dhomave', labelEn: 'Room types', group: 'hotel' },
    { id: 'amenities', labelSq: 'Pajisjet', labelEn: 'Amenities', group: 'hotel' },
    { id: 'floors', labelSq: 'Katet', labelEn: 'Floors', group: 'hotel' },
    { id: 'menu', labelSq: 'Menuja POS', labelEn: 'POS menu', group: 'operations', module: 'pos' },
    { id: 'housekeeping', labelSq: 'Housekeeping', labelEn: 'Housekeeping', group: 'operations', module: 'housekeeping' },
    { id: 'financial', labelSq: 'Financa', labelEn: 'Finance', group: 'operations' },
    { id: 'currencies', labelSq: 'Monedhat', labelEn: 'Currencies', group: 'operations', module: 'finance' },
    { id: 'pricing-programs', labelSq: 'Çmimet & OTA', labelEn: 'Pricing & OTA', group: 'operations' },
    { id: 'market-rates', labelSq: 'Çmimet e tregut', labelEn: 'Market rates', group: 'operations' },
    { id: 'integrations', labelSq: 'Integrimet', labelEn: 'Integrations', group: 'system' },
    { id: 'ai', labelSq: 'Asistenti AI', labelEn: 'AI assistant', group: 'system' },
];

const requestedTab = new URLSearchParams(usePage().url.split('?')[1] || '').get('tab');
const validTabs = [...allTabs.map((tab) => tab.id), 'users', 'history'];
const activeTab = ref(validTabs.includes(requestedTab) ? requestedTab : 'hotel');

function selectTab(tab) {
    activeTab.value = tab;

    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
}
</script>

<template>
    <AppLayout>
        <PageHeader
            :title="$t('accountCenter.settingsTitle')"
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: $t('accountCenter.settingsTitle') }]"
        />
        <p class="mt-1 text-body-sm text-neutral-500">{{ $t('accountCenter.settingsSubtitle') }}</p>

        <div class="mt-6 flex flex-col gap-6 lg:flex-row">
            <SettingsSidebar :active-item="activeTab" interactive @select="selectTab" />

            <div class="min-w-0 flex-1">
                <HotelTab v-if="activeTab === 'hotel'" :settings="settings.hotel || {}" :toasts="toasts" />
                <WebsiteTab v-else-if="activeTab === 'website'" :settings="settings.hotel || {}" :toasts="toasts" />
                <AboutTab v-else-if="activeTab === 'about'" :settings="settings.about || {}" :toasts="toasts" />
                <RoomTypesTab v-else-if="activeTab === 'room-types'" :room-types="roomTypes" :amenities="amenities" :toasts="toasts" />
                <AmenitiesTab v-else-if="activeTab === 'amenities'" :amenities="amenities" :toasts="toasts" />
                <FloorsTab v-else-if="activeTab === 'floors'" :floors="floors" :toasts="toasts" />
                <MenuTab v-else-if="activeTab === 'menu'" :categories="menuCategories" :inventory-items="inventoryItems" :warehouses="inventoryWarehouses" :inventory-enabled="modules.finance === true" :toasts="toasts" />
                <HousekeepingTab v-else-if="activeTab === 'housekeeping'" :settings="settings.housekeeping || {}" :checklist-defaults="checklistDefaults" :toasts="toasts" />
                <FinancialTab v-else-if="activeTab === 'financial'" :settings="settings.financial || {}" :toasts="toasts" />
                <CurrenciesTab v-else-if="activeTab === 'currencies'" :settings="settings.currencies || {}" :toasts="toasts" />
                <PricingProgramsTab v-else-if="activeTab === 'pricing-programs'" :settings="settings.pricing_programs || {}" :financial="settings.financial || {}" :toasts="toasts" />
                <MarketRatesTab v-else-if="activeTab === 'market-rates'" :settings="settings.market_rates || {}" :toasts="toasts" />
                <IntegrationsTab v-else-if="activeTab === 'integrations'" :integrations="integrations" :toasts="toasts" @select-tab="selectTab" />
                <AiTab v-else-if="activeTab === 'ai'" :settings="settings.ai || {}" :toasts="toasts" />
                <UsersPage v-else-if="activeTab === 'users'" v-bind="userManagement" embedded />
                <AuditLogsPage v-else-if="activeTab === 'history'" v-bind="auditHistory" embedded />
            </div>
        </div>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
