<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import HotelTab from './Tabs/HotelTab.vue';
import WebsiteTab from './Tabs/WebsiteTab.vue';
import AboutTab from './Tabs/AboutTab.vue';
import RoomTypesTab from './Tabs/RoomTypesTab.vue';
import FloorsTab from './Tabs/FloorsTab.vue';
import AmenitiesTab from './Tabs/AmenitiesTab.vue';
import MenuTab from './Tabs/MenuTab.vue';
import HousekeepingTab from './Tabs/HousekeepingTab.vue';
import FinancialTab from './Tabs/FinancialTab.vue';
import AiTab from './Tabs/AiTab.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';

const props = defineProps({
    settings: Object,
    checklistDefaults: { type: Object, default: () => ({}) },
    roomTypes: Array,
    menuCategories: Array,
    floors: Array,
    amenities: Array,
});

const toasts = ref(null);
const activeTab = ref('hotel');
const modules = computed(() => usePage().props.modules || {});

const allTabs = [
    { id: 'hotel', label: 'Hotel Info' },
    { id: 'website', label: 'Faqja Web' },
    { id: 'about', label: 'Faqja: Rreth Nesh' },
    { id: 'room-types', label: 'Tipet e dhomave' },
    { id: 'amenities', label: 'Pajisjet' },
    { id: 'floors', label: 'Katet' },
    { id: 'menu', label: 'Menu POS', module: 'pos' },
    { id: 'housekeeping', label: 'Housekeeping', module: 'housekeeping' },
    { id: 'financial', label: 'Financiare' },
    { id: 'ai', label: 'Asistenti AI' },
];
const tabs = computed(() => allTabs.filter((tab) => !tab.module || modules.value[tab.module] === true));
</script>

<template>
    <AppLayout>
        <PageHeader
            title="Settings"
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Settings' }]"
        />

        <div class="mt-6 flex flex-col lg:flex-row gap-6">
            <!-- Sidebar tabs -->
            <aside class="lg:w-56 shrink-0">
                <nav class="space-y-0.5">
                    <button
                        v-for="tab in tabs"
                        :key="tab.id"
                        :class="[
                            'block w-full text-left rounded-md px-3 py-2.5 text-body-sm transition-colors duration-150',
                            activeTab === tab.id
                                ? 'bg-accent-50 text-accent-700 font-medium'
                                : 'text-neutral-600 hover:bg-neutral-100',
                        ]"
                        @click="activeTab = tab.id"
                    >
                        {{ tab.label }}
                    </button>
                </nav>
            </aside>

            <!-- Tab content -->
            <div class="flex-1 min-w-0">
                <HotelTab v-if="activeTab === 'hotel'" :settings="settings.hotel || {}" :toasts="toasts" />
                <WebsiteTab v-else-if="activeTab === 'website'" :settings="settings.hotel || {}" :toasts="toasts" />
                <AboutTab v-else-if="activeTab === 'about'" :settings="settings.about || {}" :toasts="toasts" />
                <RoomTypesTab v-else-if="activeTab === 'room-types'" :room-types="roomTypes" :amenities="amenities" :toasts="toasts" />
                <AmenitiesTab v-else-if="activeTab === 'amenities'" :amenities="amenities" :toasts="toasts" />
                <FloorsTab v-else-if="activeTab === 'floors'" :floors="floors" :toasts="toasts" />
                <MenuTab v-else-if="activeTab === 'menu'" :categories="menuCategories" :toasts="toasts" />
                <HousekeepingTab v-else-if="activeTab === 'housekeeping'" :settings="settings.housekeeping || {}" :checklist-defaults="checklistDefaults" :toasts="toasts" />
                <FinancialTab v-else-if="activeTab === 'financial'" :settings="settings.financial || {}" :toasts="toasts" />
                <AiTab v-else-if="activeTab === 'ai'" :settings="settings.ai || {}" :toasts="toasts" />
            </div>
        </div>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
