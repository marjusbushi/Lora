<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    activeItem: { type: String, default: 'hotel' },
    interactive: { type: Boolean, default: false },
});

const emit = defineEmits(['select']);
const { locale, t } = useI18n();
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
    { id: 'ai', labelSq: 'Asistenti AI', labelEn: 'AI assistant', group: 'system' },
];

const tabs = computed(() => allTabs
    .filter((tab) => !tab.module || modules.value[tab.module] === true)
    .map((tab) => ({ ...tab, label: locale.value === 'sq' ? tab.labelSq : tab.labelEn })));

const groups = computed(() => [
    { id: 'hotel', label: locale.value === 'sq' ? 'Hoteli' : 'Hotel' },
    { id: 'operations', label: locale.value === 'sq' ? 'Operacionet' : 'Operations' },
    { id: 'system', label: locale.value === 'sq' ? 'Sistemi' : 'System' },
].map((group) => ({ ...group, tabs: tabs.value.filter((tab) => tab.group === group.id) })));

const administrationLinks = computed(() => [
    { id: 'users', label: t('admin.users.title'), href: route('users.index') },
    { id: 'history', label: t('accountCenter.auditHistory'), href: route('audit-logs.index') },
]);

const itemClass = (id) => [
    'block w-full rounded-lg px-3 py-2.5 text-left text-body-sm no-underline transition-colors duration-150',
    id === props.activeItem
        ? 'bg-accent-50 font-semibold text-accent-700'
        : 'text-neutral-600 hover:bg-neutral-50 hover:text-neutral-900',
];
</script>

<template>
    <aside class="shrink-0 lg:w-64">
        <nav class="rounded-xl border border-neutral-200 bg-white p-2 shadow-card" :aria-label="$t('accountCenter.settingsTitle')">
            <div v-for="group in groups" :key="group.id" :class="group.id !== 'hotel' && 'mt-3'">
                <p class="px-3 pb-1 pt-2 text-[10px] font-bold uppercase tracking-[0.12em] text-neutral-400">{{ group.label }}</p>

                <template v-for="tab in group.tabs" :key="tab.id">
                    <button
                        v-if="interactive"
                        type="button"
                        :class="itemClass(tab.id)"
                        :aria-pressed="activeItem === tab.id"
                        @click="emit('select', tab.id)"
                    >
                        {{ tab.label }}
                    </button>
                    <Link
                        v-else
                        :href="route('settings.index', { tab: tab.id })"
                        :class="itemClass(tab.id)"
                    >
                        {{ tab.label }}
                    </Link>
                </template>

                <template v-for="item in group.id === 'system' ? administrationLinks : []" :key="item.id">
                    <button
                        v-if="interactive"
                        type="button"
                        :class="itemClass(item.id)"
                        :aria-pressed="activeItem === item.id"
                        @click="emit('select', item.id)"
                    >
                        {{ item.label }}
                    </button>
                    <Link
                        v-else
                        :href="item.href"
                        :class="itemClass(item.id)"
                        :aria-current="activeItem === item.id ? 'page' : undefined"
                    >
                        {{ item.label }}
                    </Link>
                </template>
            </div>
        </nav>
    </aside>
</template>
