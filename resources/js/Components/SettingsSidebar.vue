<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { settingsGroups, visibleSettingsTabs } from '@/Pages/Settings/settingsNavigation';

const props = defineProps({
    activeItem: { type: String, default: 'hotel' },
    interactive: { type: Boolean, default: false },
    activeGroupOnly: { type: Boolean, default: false },
});

const emit = defineEmits(['select']);
const { locale } = useI18n();
const modules = computed(() => usePage().props.modules || {});

const tabs = computed(() => visibleSettingsTabs(modules.value)
    .map((tab) => ({
        ...tab,
        id: tab.sidebarId || tab.id,
        sourceId: tab.id,
        label: locale.value === 'sq' ? tab.labelSq : tab.labelEn,
    })));

const activeTab = computed(() => tabs.value.find((tab) => tab.id === props.activeItem || tab.sourceId === props.activeItem));

const groups = computed(() => settingsGroups
    .filter((group) => !props.activeGroupOnly || group.id === activeTab.value?.group)
    .map((group) => ({
        ...group,
        label: locale.value === 'sq' ? group.labelSq : group.labelEn,
        tabs: tabs.value.filter((tab) => tab.group === group.id),
    })));

const isActive = (tab) => tab.id === props.activeItem || tab.sourceId === props.activeItem;
const itemClass = (tab) => [
    'block w-full rounded-lg px-3 py-2.5 text-left text-body-sm no-underline transition-colors duration-150',
    isActive(tab)
        ? 'bg-accent-50 font-semibold text-accent-700'
        : 'text-neutral-600 hover:bg-neutral-50 hover:text-neutral-900',
];
</script>

<template>
    <aside class="settings-sidebar shrink-0 lg:sticky lg:top-5 lg:w-[232px]" :class="activeGroupOnly && 'hidden lg:block'">
        <nav data-ui="settings-nav" class="rounded-xl border border-neutral-200 bg-white p-2 shadow-card" :aria-label="$t('accountCenter.settingsTitle')">
            <div v-for="group in groups" :key="group.id" :class="group.id !== 'hotel' && 'mt-3'">
                <p class="px-3 pb-1 pt-2 text-[10px] font-bold uppercase tracking-[0.14em] text-neutral-400">{{ group.label }}</p>

                <template v-for="tab in group.tabs" :key="tab.id">
                    <button
                        v-if="interactive && !tab.href"
                        type="button"
                        :class="itemClass(tab)"
                        :aria-pressed="isActive(tab)"
                        @click="emit('select', tab.sourceId)"
                    >
                        {{ tab.label }}
                    </button>
                    <Link
                        v-else
                        :href="tab.href || route('settings.index', { tab: tab.id })"
                        :class="itemClass(tab)"
                        :aria-current="isActive(tab) ? 'page' : undefined"
                    >
                        {{ tab.label }}
                    </Link>
                </template>
            </div>
        </nav>
    </aside>
</template>
