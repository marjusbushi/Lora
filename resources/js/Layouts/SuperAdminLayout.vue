<script setup>
import Dropdown from '@/Components/Dropdown.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Bell,
    Building2,
    LayoutDashboard,
    ListChecks,
    LogOut,
    Menu,
    PanelLeftClose,
    ShieldCheck,
    UserRound,
    X,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({ title: { type: String, default: 'Lora Control Panel' } });

const page = usePage();
const mobileOpen = ref(false);
const sidebarCollapsed = ref(
    typeof window !== 'undefined' && localStorage.getItem('superAdminSidebarCollapsed') === '1',
);
const user = computed(() => page.props.auth?.user || {});

watch(sidebarCollapsed, (collapsed) => {
    if (typeof window !== 'undefined') {
        localStorage.setItem('superAdminSidebarCollapsed', collapsed ? '1' : '0');
    }
});

const navigation = [
    { label: t('superAdmin.auto.copy120'), href: '/super-admin', match: '/super-admin', exact: true, icon: LayoutDashboard },
    { label: t('superAdmin.auto.copy070'), href: '/super-admin/tenants', match: '/super-admin/tenants', icon: Building2 },
    { label: t('superAdmin.auto.copy107'), href: '/super-admin/activity', match: '/super-admin/activity', icon: ListChecks },
];

function isActive(item) {
    if (item.exact) return page.url === item.href || page.url === `${item.href}/`;
    return page.url.startsWith(item.match);
}
</script>

<template>
    <Head :title="title" />

    <div class="super-admin-shell min-h-screen bg-[var(--sa-canvas)] text-[var(--sa-ink)]">
        <div v-if="mobileOpen" class="fixed inset-0 z-40 bg-neutral-950/35 lg:hidden" @click="mobileOpen = false" />

        <aside
            class="fixed inset-y-0 left-0 z-50 flex w-[228px] flex-col border-r border-[var(--sa-line)] bg-white text-[#64726c] transition-[width,transform] duration-200 lg:translate-x-0"
            :class="[
                mobileOpen ? 'translate-x-0' : '-translate-x-full',
                sidebarCollapsed ? 'lg:w-[76px]' : 'lg:w-[228px]',
            ]"
        >
            <div class="relative flex h-[70px] shrink-0 items-center border-b border-[var(--sa-line)]">
                <a
                    href="https://lorapms.com"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="flex h-full min-w-0 flex-1 items-center gap-3 px-[18px] text-[var(--sa-ink)] no-underline transition hover:bg-emerald-50/70 hover:text-[var(--sa-ink)]"
                    :class="sidebarCollapsed && 'lg:justify-center lg:px-0'"
                    :aria-label="t('superAdmin.compact.publicSiteAria')"
                    :title="sidebarCollapsed ? 'Lora PMS' : undefined"
                >
                    <span class="grid h-[38px] w-[38px] shrink-0 place-items-center rounded-[11px] bg-emerald-100 text-emerald-700">
                        <ShieldCheck class="h-[18px] w-[18px]" :stroke-width="1.8" />
                    </span>
                    <span class="min-w-0 whitespace-nowrap" :class="sidebarCollapsed && 'lg:hidden'">
                        <span class="block text-[15px] font-semibold tracking-tight">Lora PMS</span>
                        <span class="mt-1 block text-[10px] font-bold uppercase tracking-[0.17em] text-[#8b9691]">Control Panel</span>
                    </span>
                </a>
                <button class="absolute right-3 rounded-lg p-2 text-neutral-500 hover:bg-neutral-100 lg:hidden" :aria-label="t('superAdmin.compact.close')" @click="mobileOpen = false">
                    <X class="h-5 w-5" />
                </button>
            </div>

            <nav class="flex-1 px-3 py-[18px]">
                <p class="px-[10px] pb-[5px] pt-[9px] text-[10px] font-bold uppercase tracking-[0.13em] text-[#98a39e]" :class="sidebarCollapsed && 'lg:hidden'">{{ t('superAdmin.compact.platform') }}</p>
                <div class="space-y-[5px]">
                    <Link
                        v-for="item in navigation"
                        :key="item.href"
                        :href="item.href"
                        class="flex h-[42px] items-center gap-[11px] rounded-[10px] px-3 text-sm font-medium text-[#64726c] no-underline transition"
                        :class="[
                            isActive(item)
                                ? 'bg-emerald-50 font-semibold text-emerald-900 ring-1 ring-inset ring-emerald-100'
                                : 'hover:bg-[#f5f7f6] hover:text-[var(--sa-ink)]',
                            sidebarCollapsed && 'lg:mx-auto lg:w-[50px] lg:justify-center lg:gap-0 lg:px-0',
                        ]"
                        :title="sidebarCollapsed ? item.label : undefined"
                        @click="mobileOpen = false"
                    >
                        <span class="grid w-7 shrink-0 place-items-center" :class="isActive(item) && 'text-emerald-700'">
                            <component :is="item.icon" class="h-[18px] w-[18px]" :stroke-width="1.8" />
                        </span>
                        <span class="whitespace-nowrap" :class="sidebarCollapsed && 'lg:hidden'">{{ item.label }}</span>
                    </Link>
                </div>
            </nav>

            <div class="border-t border-[var(--sa-line)] p-3">
                <button
                    type="button"
                    class="hidden h-[42px] w-full items-center rounded-[10px] text-sm font-medium text-[#66746e] transition hover:bg-[#f5f7f6] hover:text-[var(--sa-ink)] lg:flex"
                    :class="sidebarCollapsed ? 'mx-auto w-[50px] justify-center px-0' : 'gap-[11px] px-3'"
                    :aria-label="sidebarCollapsed ? t('superAdmin.compact.open') : t('superAdmin.compact.close')"
                    :title="sidebarCollapsed ? t('superAdmin.compact.open') : undefined"
                    @click="sidebarCollapsed = !sidebarCollapsed"
                >
                    <span class="grid w-7 shrink-0 place-items-center">
                        <PanelLeftClose class="h-[18px] w-[18px] transition-transform" :class="sidebarCollapsed && 'rotate-180'" :stroke-width="1.8" />
                    </span>
                    <span v-if="!sidebarCollapsed">{{ t('superAdmin.compact.close') }}</span>
                </button>
            </div>
        </aside>

        <div class="transition-[padding] duration-200" :class="sidebarCollapsed ? 'lg:pl-[76px]' : 'lg:pl-[228px]'">
            <header class="sticky top-0 z-30 flex h-[70px] items-center justify-between border-b border-[var(--sa-line)] bg-white/95 px-4 backdrop-blur sm:px-7">
                <button class="rounded-[10px] p-2 text-neutral-600 hover:bg-neutral-100 lg:hidden" :aria-label="t('superAdmin.compact.openMenu')" @click="mobileOpen = true">
                    <Menu class="h-5 w-5" />
                </button>

                <div class="hidden lg:block">
                    <p class="text-[10px] font-extrabold uppercase tracking-[0.18em] text-emerald-700">{{ $t('superAdmin.auto.copy146') }}</p>
                    <p class="mt-1 text-xs text-neutral-500">{{ $t('superAdmin.auto.copy149') }}</p>
                </div>

                <div class="ml-auto flex items-center gap-2">
                    <button type="button" class="grid h-[38px] w-[38px] place-items-center rounded-[10px] text-[#617069] transition hover:bg-neutral-50" :aria-label="t('superAdmin.compact.notifications')" :title="t('superAdmin.compact.notifications')">
                        <Bell class="h-[18px] w-[18px]" :stroke-width="1.8" />
                    </button>

                    <Dropdown align="right" width="64" content-classes="overflow-hidden rounded-xl border border-neutral-200 bg-white p-1.5 shadow-dropdown">
                        <template #trigger>
                            <button type="button" class="grid h-[42px] w-[42px] place-items-center rounded-full border border-transparent p-0.5 transition hover:border-emerald-200 hover:bg-emerald-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/35" :aria-label="t('superAdmin.compact.accountMenu')">
                                <span class="grid h-9 w-9 place-items-center rounded-full bg-emerald-100 text-sm font-bold text-emerald-900">
                                    {{ user.name?.charAt(0)?.toUpperCase() || 'A' }}
                                </span>
                            </button>
                        </template>
                        <template #content>
                            <div class="border-b border-neutral-200 px-3 py-2.5">
                                <p class="truncate text-sm font-semibold text-neutral-900">{{ user.name }}</p>
                                <p class="mt-0.5 truncate text-xs text-neutral-500">{{ user.email }}</p>
                                <p class="mt-0.5 text-[11px] text-neutral-400">Super Admin</p>
                            </div>
                            <div class="space-y-0.5 pt-1.5">
                                <Link href="/super-admin/profile" class="flex h-[38px] items-center gap-2.5 rounded-lg px-2.5 text-xs font-semibold text-neutral-700 no-underline hover:bg-emerald-50 hover:text-emerald-900">
                                    <UserRound class="h-4 w-4" :stroke-width="1.8" /> {{ t('superAdmin.compact.profile') }}
                                </Link>
                                <Link href="/logout" method="post" as="button" class="flex h-[38px] w-full items-center gap-2.5 rounded-lg px-2.5 text-left text-xs font-semibold text-red-600 hover:bg-red-50">
                                    <LogOut class="h-4 w-4" :stroke-width="1.8" /> {{ t('superAdmin.compact.logout') }}
                                </Link>
                            </div>
                        </template>
                    </Dropdown>
                </div>
            </header>

            <main class="px-4 py-5 sm:px-6 lg:px-7">
                <slot />
            </main>
        </div>
    </div>
</template>
