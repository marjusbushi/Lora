<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    collapsed: {
        type: Boolean,
        default: false,
    },
    items: {
        type: Array,
        required: true,
        // Each: { label: string, href: string, icon?: string, routeName?: string, children?: array }
    },
    dismissible: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['toggle']);

const page = usePage();
const { t } = useI18n();
const toggleText = computed(() => props.collapsed ? t('sidebar.open') : t('sidebar.close'));
const toggleLabel = computed(() => {
    if (props.dismissible) return t('sidebar.closeMenu');
    return props.collapsed ? t('sidebar.openMenu') : t('sidebar.closeMenu');
});

const navigationRef = ref(null);
const openGroupKey = ref(null);
const sidebarScrollKey = 'pms-sidebar-scroll-top';

function rememberNavigationPosition() {
    if (typeof window === 'undefined' || !navigationRef.value) return;
    window.sessionStorage.setItem(sidebarScrollKey, String(navigationRef.value.scrollTop));
}

async function restoreNavigationPosition() {
    if (typeof window === 'undefined') return;
    await nextTick();
    const savedPosition = Number(window.sessionStorage.getItem(sidebarScrollKey));
    if (navigationRef.value && Number.isFinite(savedPosition)) {
        navigationRef.value.scrollTop = savedPosition;
    }
}

function childActive(item) {
    return (item.children || []).some((c) => isActive(c));
}

function groupKey(item) {
    return item.href || item.children?.[0]?.href || item.label;
}

function groupOpen(item) {
    return openGroupKey.value === groupKey(item);
}

function toggleGroup(item) {
    const key = groupKey(item);
    openGroupKey.value = groupOpen(item) ? null : key;
}

function isActive(item) {
    if (item.routeName) {
        return route().current(item.routeName);
    }
    // `match` lets a nav item link to a sub-page (e.g. /reservations/calendar)
    // while staying highlighted across the whole section (/reservations*).
    const base = item.match || item.href;
    const currentPath = page.url.split('?')[0].split('#')[0];
    return currentPath === base || currentPath.startsWith(base + '/');
}

function isCurrentChild(child, group) {
    const matchingChildren = (group.children || [])
        .filter((item) => isActive(item))
        .sort((left, right) => {
            const leftBase = left.match || left.href || '';
            const rightBase = right.match || right.href || '';
            return rightBase.length - leftBase.length;
        });

    return matchingChildren[0] === child;
}

function syncActiveGroup() {
    const activeGroup = props.items.find((item) => item.children && childActive(item));
    openGroupKey.value = activeGroup ? groupKey(activeGroup) : null;
}

watch(() => page.url, syncActiveGroup, { immediate: true });
onMounted(restoreNavigationPosition);
</script>

<template>
    <aside
        :class="[
            'flex h-dvh flex-col overflow-hidden bg-primary-950 text-neutral-300 transition-all duration-250 sticky top-0',
            collapsed ? 'w-[68px]' : 'w-[260px]',
        ]"
    >
        <!-- Logo area -->
        <div :class="['flex h-14 shrink-0 items-center border-b border-primary-800/50', collapsed ? 'justify-center px-0' : 'px-4']">
            <Link href="/" class="flex items-center gap-3 text-white no-underline hover:text-white">
                <div class="h-8 w-8 rounded-md bg-accent-600 flex items-center justify-center shrink-0">
                    <span class="text-white font-semibold text-label">{{ (page.props.settings?.hotel_name || $t('admin.generated.k_a57869d5c538')).charAt(0) }}</span>
                </div>
                <span v-if="!collapsed" class="text-label text-neutral-100 whitespace-nowrap tracking-tight">{{ page.props.settings?.hotel_name || $t('admin.generated.k_7f8dacfbd096') }}</span>
            </Link>
        </div>

        <!-- Navigation -->
        <nav ref="navigationRef" class="sidebar-navigation flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto overscroll-contain px-3 py-3" @scroll.passive="rememberNavigationPosition">
            <template v-for="item in items" :key="item.href || item.label">
                <!-- Group with children: accordion (e.g. Financa) -->
                <div v-if="item.children" class="min-w-0" :data-sidebar-group="groupKey(item)">
                    <component
                        :is="collapsed ? Link : 'button'"
                        :href="collapsed ? item.children[0].href : undefined"
                        type="button"
                        :class="[
                            'flex w-full items-center rounded-md px-3 py-2.5 text-body-sm leading-5 transition-colors duration-150 no-underline',
                            collapsed ? 'justify-center' : 'gap-3',
                            childActive(item)
                                ? 'bg-accent-600/15 text-accent-400 font-medium'
                                : 'text-neutral-400 hover:bg-primary-800/60 hover:text-neutral-200',
                        ]"
                        :title="collapsed ? item.label : undefined"
                        :data-sidebar-active="collapsed && childActive(item) ? 'true' : undefined"
                        @click="collapsed ? rememberNavigationPosition() : toggleGroup(item)"
                    >
                        <span class="h-5 w-5 shrink-0 flex items-center justify-center" v-html="item.icon" />
                        <span v-if="!collapsed" class="whitespace-nowrap flex-1 text-left">{{ item.label }}</span>
                        <svg v-if="!collapsed" class="h-3.5 w-3.5 shrink-0 transition-transform duration-150" :class="groupOpen(item) && 'rotate-90'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" /></svg>
                    </component>
                    <div v-if="!collapsed" v-show="groupOpen(item)" class="mt-0.5 space-y-0.5">
                        <Link
                            v-for="child in item.children"
                            :key="child.href"
                            :href="child.href"
                            :data-sidebar-active="isCurrentChild(child, item) ? 'true' : undefined"
                            :aria-current="isCurrentChild(child, item) ? 'page' : undefined"
                            @click="rememberNavigationPosition"
                            :class="[
                                'relative flex items-center rounded-md py-2 pl-11 pr-3 text-body-sm leading-5 no-underline transition-colors duration-150',
                                isCurrentChild(child, item)
                                    ? 'bg-primary-800/70 text-accent-300 font-medium'
                                    : 'text-neutral-500 hover:bg-primary-800/60 hover:text-neutral-200',
                            ]"
                        >
                            <span v-if="isCurrentChild(child, item)" class="absolute left-6 h-1.5 w-1.5 rounded-full bg-accent-500" />
                            {{ child.label }}
                        </Link>
                    </div>
                </div>

                <Link
                    v-else
                    :href="item.href"
                    :class="[
                        'flex items-center rounded-md px-3 py-2.5 text-body-sm leading-5 transition-colors duration-150 no-underline',
                        collapsed ? 'justify-center' : 'gap-3',
                        isActive(item)
                            ? 'bg-accent-600/15 text-accent-400 font-medium'
                            : 'text-neutral-400 hover:bg-primary-800/60 hover:text-neutral-200',
                    ]"
                    :title="collapsed ? item.label : undefined"
                    :data-sidebar-active="isActive(item) ? 'true' : undefined"
                    :aria-current="isActive(item) ? 'page' : undefined"
                    @click="rememberNavigationPosition"
                >
                    <!-- Icon placeholder — accepts SVG string or slot -->
                    <span class="h-5 w-5 shrink-0 flex items-center justify-center" v-html="item.icon" />
                    <span v-if="!collapsed" class="whitespace-nowrap">{{ item.label }}</span>
                </Link>
            </template>
        </nav>

        <!-- Collapse toggle -->
        <div class="shrink-0 border-t border-primary-800/50 px-2.5 py-2">
            <button
                :class="[
                    'flex items-center w-full rounded-md py-1.5 px-2 text-neutral-400 hover:text-neutral-100 hover:bg-primary-800/60 transition-colors duration-150',
                    collapsed ? 'justify-center' : 'justify-between',
                ]"
                :title="toggleLabel"
                :aria-label="toggleLabel"
                @click="emit('toggle')"
            >
                <span v-if="!collapsed" class="text-body-sm whitespace-nowrap">{{ toggleText }}</span>
                <svg
                    class="h-5 w-5 shrink-0 transition-transform duration-250"
                    :class="collapsed && 'rotate-180'"
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                >
                    <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 01-.02 1.06L8.832 10l3.938 3.71a.75.75 0 11-1.04 1.08l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 011.06.02z" clip-rule="evenodd" />
                </svg>
            </button>
        </div>
    </aside>
</template>

<style scoped>
.sidebar-navigation {
    scrollbar-color: transparent transparent;
    scrollbar-width: thin;
}

.sidebar-navigation:hover {
    scrollbar-color: rgb(82 96 91 / 65%) transparent;
}

.sidebar-navigation::-webkit-scrollbar {
    width: 5px;
}

.sidebar-navigation::-webkit-scrollbar-track {
    background: transparent;
}

.sidebar-navigation::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: transparent;
}

.sidebar-navigation:hover::-webkit-scrollbar-thumb {
    background: rgb(82 96 91 / 65%);
}
</style>
