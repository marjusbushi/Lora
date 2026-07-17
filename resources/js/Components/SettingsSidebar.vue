<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    activeItem: { type: String, default: 'hotel' },
    interactive: { type: Boolean, default: false },
});

const emit = defineEmits(['select']);
const { locale } = useI18n();
const modules = computed(() => usePage().props.modules || {});

const allTabs = [
    { id: 'hotel', labelSq: 'Të dhënat e hotelit', labelEn: 'Hotel information', group: 'hotel' },
    { id: 'room-types', labelSq: 'Tipet e dhomave', labelEn: 'Room types', group: 'hotel' },
    { id: 'floors', labelSq: 'Katet', labelEn: 'Floors', group: 'hotel' },
    { id: 'amenities', labelSq: 'Pajisjet', labelEn: 'Amenities', group: 'hotel' },
    { id: 'website', labelSq: 'Faqja Web', labelEn: 'Website', group: 'hotel' },
    { id: 'about', labelSq: 'Rreth Nesh', labelEn: 'About page', group: 'hotel' },
    { id: 'booking-policies', labelSq: 'Rezervimet & politikat', labelEn: 'Reservations & policies', group: 'operations' },
    { id: 'pricing-programs', labelSq: 'Çmimet & OTA', labelEn: 'Pricing & OTA', group: 'operations' },
    { id: 'market-rates', labelSq: 'Çmimet e tregut', labelEn: 'Market rates', group: 'operations' },
    { id: 'menu', labelSq: 'Menuja POS', labelEn: 'POS menu', group: 'operations', module: 'pos' },
    { id: 'housekeeping', labelSq: 'Housekeeping', labelEn: 'Housekeeping', group: 'operations', module: 'housekeeping' },
    { id: 'financial', labelSq: 'Financa', labelEn: 'Finance', group: 'operations' },
    { id: 'currencies', labelSq: 'Monedhat', labelEn: 'Currencies', group: 'operations', module: 'finance' },
    { id: 'integrations', labelSq: 'Integrimet', labelEn: 'Integrations', group: 'automation' },
    { id: 'lora-ai', labelSq: 'Konfigurimi i Lora AI', labelEn: 'Lora AI configuration', group: 'automation', href: '/pms/lora-ai' },
    { id: 'channel-manager', labelSq: 'Channel Manager', labelEn: 'Channel Manager', group: 'automation', module: 'channel_manager' },
    { id: 'users', labelSq: 'Përdoruesit & rolet', labelEn: 'Users & roles', group: 'system' },
    { id: 'notifications', labelSq: 'Njoftimet', labelEn: 'Notifications', group: 'system' },
    { id: 'security', labelSq: 'Siguria', labelEn: 'Security', group: 'system' },
    { id: 'history', labelSq: 'Auditimi', labelEn: 'Audit', group: 'system' },
];

const tabs = computed(() => allTabs
    .filter((tab) => !tab.module || modules.value[tab.module] === true)
    .map((tab) => ({ ...tab, label: locale.value === 'sq' ? tab.labelSq : tab.labelEn })));

const groups = computed(() => [
    { id: 'hotel', label: locale.value === 'sq' ? 'Hoteli' : 'Hotel' },
    { id: 'operations', label: locale.value === 'sq' ? 'Operacionet' : 'Operations' },
    { id: 'automation', label: locale.value === 'sq' ? 'Automatizimi' : 'Automation' },
    { id: 'system', label: locale.value === 'sq' ? 'Sistemi' : 'System' },
].map((group) => ({ ...group, tabs: tabs.value.filter((tab) => tab.group === group.id) })));

const itemClass = (id) => [
    'block w-full rounded-lg px-3 py-2.5 text-left text-body-sm no-underline transition-colors duration-150',
    id === props.activeItem
        ? 'bg-accent-50 font-semibold text-accent-700'
        : 'text-neutral-600 hover:bg-neutral-50 hover:text-neutral-900',
];
</script>

<template>
    <aside class="settings-sidebar shrink-0 lg:sticky lg:top-5 lg:w-[232px]">
        <nav data-ui="settings-nav" class="rounded-xl border border-neutral-200 bg-white p-2 shadow-card" :aria-label="$t('accountCenter.settingsTitle')">
            <div v-for="group in groups" :key="group.id" :class="group.id !== 'hotel' && 'mt-3'">
                <p class="px-3 pb-1 pt-2 text-[10px] font-bold uppercase tracking-[0.14em] text-neutral-400">{{ group.label }}</p>

                <template v-for="tab in group.tabs" :key="tab.id">
                    <button
                        v-if="interactive && !tab.href"
                        type="button"
                        :class="itemClass(tab.id)"
                        :aria-pressed="activeItem === tab.id"
                        @click="emit('select', tab.id)"
                    >
                        {{ tab.label }}
                    </button>
                    <Link
                        v-else
                        :href="tab.href || route('settings.index', { tab: tab.id })"
                        :class="itemClass(tab.id)"
                        :aria-current="activeItem === tab.id ? 'page' : undefined"
                    >
                        {{ tab.label }}
                    </Link>
                </template>
            </div>
        </nav>
    </aside>
</template>
