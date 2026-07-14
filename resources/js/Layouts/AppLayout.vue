<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import Sidebar from '@/Components/UI/Sidebar.vue';
import Dropdown from '@/Components/Dropdown.vue';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import NotificationBell from '@/Components/NotificationBell.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ChevronDown, LogOut, Settings, UserRound } from 'lucide-vue-next';

// Persist the collapsed state so it survives Inertia navigations
// (AppLayout re-mounts per page, so we restore from localStorage).
const sidebarCollapsed = ref(
    typeof window !== 'undefined' && localStorage.getItem('sidebarCollapsed') === '1'
);
watch(sidebarCollapsed, (v) => {
    if (typeof window !== 'undefined') {
        localStorage.setItem('sidebarCollapsed', v ? '1' : '0');
    }
});
const mobileMenuOpen = ref(false);

const page = usePage();
const { t } = useI18n();
const userPermissions = computed(() => page.props.auth.user?.permissions || []);
const activeModules = computed(() => page.props.modules || {});
const isAdmin = computed(() => page.props.auth.user?.role === 'admin');
const canAccessSettings = computed(() => isAdmin.value);

function can(permission) {
    return userPermissions.value.includes(permission);
}

function hasModule(module) {
    return !module || activeModules.value[module] === true;
}

// Preserve production's unread OTA-message alert without polling tenants that
// do not have the channel-manager module enabled.
const messagingEnabled = computed(() => can('view_reservations') && hasModule('channel_manager'));
const unreadMessages = ref(0);
let pollTimer = null;
let audioCtx = null;
const baseTitle = typeof document !== 'undefined' ? document.title : '';

function soundMuted() {
    return typeof window !== 'undefined' && localStorage.getItem('msgSoundMuted') === '1';
}

function unlockAudio() {
    try {
        audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
        if (audioCtx.state === 'suspended') audioCtx.resume();
    } catch (error) {
        // Audio is optional; message polling continues silently.
    }
}

function beep(ctx, frequency, at, duration) {
    const oscillator = ctx.createOscillator();
    const gain = ctx.createGain();
    oscillator.type = 'sine';
    oscillator.frequency.value = frequency;
    gain.gain.setValueAtTime(0.0001, at);
    gain.gain.linearRampToValueAtTime(0.16, at + 0.01);
    gain.gain.exponentialRampToValueAtTime(0.0001, at + duration);
    oscillator.connect(gain);
    gain.connect(ctx.destination);
    oscillator.start(at);
    oscillator.stop(at + duration);
}

function playDing() {
    if (soundMuted()) return;
    unlockAudio();
    if (!audioCtx) return;
    const now = audioCtx.currentTime;
    beep(audioCtx, 880, now, 0.14);
    beep(audioCtx, 1174.66, now + 0.13, 0.18);
}

function refreshTitle() {
    if (typeof document === 'undefined') return;
    document.title = unreadMessages.value > 0 ? `(${unreadMessages.value}) ${baseTitle}` : baseTitle;
}

async function pollUnread() {
    if (!messagingEnabled.value || (typeof document !== 'undefined' && document.hidden)) return;
    try {
        const response = await fetch(route('messages.unread'), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!response.ok) return;
        const { count } = await response.json();
        if (count > unreadMessages.value) playDing();
        unreadMessages.value = count;
        refreshTitle();
    } catch (error) {
        // Ignore temporary network failures; the next interval retries.
    }
}

onMounted(() => {
    if (!messagingEnabled.value) return;
    const unlockOnce = () => {
        unlockAudio();
        window.removeEventListener('pointerdown', unlockOnce);
        window.removeEventListener('keydown', unlockOnce);
    };
    window.addEventListener('pointerdown', unlockOnce);
    window.addEventListener('keydown', unlockOnce);
    document.addEventListener('visibilitychange', pollUnread);
    pollUnread();
    pollTimer = setInterval(pollUnread, 25000);
});

onUnmounted(() => {
    if (pollTimer) clearInterval(pollTimer);
    if (typeof document !== 'undefined') {
        document.removeEventListener('visibilitychange', pollUnread);
        document.title = baseTitle;
    }
});

// SVG icons for navigation
const icons = {
    dashboard: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5"><path fill-rule="evenodd" d="M4.25 2A2.25 2.25 0 002 4.25v2.5A2.25 2.25 0 004.25 9h2.5A2.25 2.25 0 009 6.75v-2.5A2.25 2.25 0 006.75 2h-2.5zm0 9A2.25 2.25 0 002 13.25v2.5A2.25 2.25 0 004.25 18h2.5A2.25 2.25 0 009 15.75v-2.5A2.25 2.25 0 006.75 11h-2.5zm9-9A2.25 2.25 0 0011 4.25v2.5A2.25 2.25 0 0013.25 9h2.5A2.25 2.25 0 0018 6.75v-2.5A2.25 2.25 0 0015.75 2h-2.5zm0 9A2.25 2.25 0 0011 13.25v2.5A2.25 2.25 0 0013.25 18h2.5A2.25 2.25 0 0018 15.75v-2.5A2.25 2.25 0 0015.75 11h-2.5z" clip-rule="evenodd" /></svg>',
    rooms: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5"><path d="M1 11.27V2.757C1 1.786 1.786 1 2.757 1h14.486C18.214 1 19 1.786 19 2.757v8.513a2.27 2.27 0 01-.632 1.573L12.932 18.5a1.27 1.27 0 01-1.864 0L5.632 12.843A2.27 2.27 0 011 11.27z" /><path d="M7.5 5a1.5 1.5 0 100 3 1.5 1.5 0 000-3z" /></svg>',
    reservations: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5"><path fill-rule="evenodd" d="M5.75 2a.75.75 0 01.75.75V4h7V2.75a.75.75 0 011.5 0V4h.25A2.75 2.75 0 0118 6.75v8.5A2.75 2.75 0 0115.25 18H4.75A2.75 2.75 0 012 15.25v-8.5A2.75 2.75 0 014.75 4H5V2.75A.75.75 0 015.75 2zm-1 5.5c-.69 0-1.25.56-1.25 1.25v6.5c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25v-6.5c0-.69-.56-1.25-1.25-1.25H4.75z" clip-rule="evenodd" /></svg>',
    guests: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5"><path d="M7 8a3 3 0 100-6 3 3 0 000 6zM14.5 9a2.5 2.5 0 100-5 2.5 2.5 0 000 5zM1.615 16.428a1.224 1.224 0 01-.569-1.175 6.002 6.002 0 0111.908 0c.058.467-.172.92-.57 1.174A9.953 9.953 0 017 18a9.953 9.953 0 01-5.385-1.572zM14.5 16h-.106c.07-.297.088-.611.048-.933a7.47 7.47 0 00-1.588-3.755 4.502 4.502 0 015.874 2.636.818.818 0 01-.36.98A7.465 7.465 0 0114.5 16z" /></svg>',
    housekeeping: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5"><path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 01-9.201 2.466l-.312-.311h2.433a.75.75 0 000-1.5H4.233a.75.75 0 00-.75.75v4a.75.75 0 001.5 0v-2.146l.312.311a7 7 0 0011.712-3.138.75.75 0 00-1.449-.39zm1.455-8.174a.75.75 0 00-1.5 0v2.146l-.312-.311a7 7 0 00-11.712 3.138.75.75 0 001.449.39 5.5 5.5 0 019.201-2.466l.312.311h-2.433a.75.75 0 000 1.5h3.999a.75.75 0 00.75-.75v-4z" clip-rule="evenodd" /></svg>',
    maintenance: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5"><path fill-rule="evenodd" d="M15.312 3.312a4.5 4.5 0 01-5.718 5.718l-5.6 5.6a1.75 1.75 0 102.475 2.475l5.6-5.6a4.5 4.5 0 005.718-5.718l-2.53 2.53-2.475-2.475 2.53-2.53zM4.75 15.5a.75.75 0 110 1.5.75.75 0 010-1.5z" clip-rule="evenodd" /></svg>',
    pos: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5"><path fill-rule="evenodd" d="M2.5 4A1.5 1.5 0 001 5.5V6h18v-.5A1.5 1.5 0 0017.5 4h-15zM19 8.5H1v6A1.5 1.5 0 002.5 16h15a1.5 1.5 0 001.5-1.5v-6zM3 13.25a.75.75 0 01.75-.75h1.5a.75.75 0 010 1.5h-1.5a.75.75 0 01-.75-.75zm4.75-.75a.75.75 0 000 1.5h3.5a.75.75 0 000-1.5h-3.5z" clip-rule="evenodd" /></svg>',
    reports: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5"><path d="M15.5 2a1.5 1.5 0 00-1.5 1.5v13a1.5 1.5 0 001.5 1.5h1a1.5 1.5 0 001.5-1.5v-13A1.5 1.5 0 0016.5 2h-1zM9.5 6A1.5 1.5 0 008 7.5v9A1.5 1.5 0 009.5 18h1a1.5 1.5 0 001.5-1.5v-9A1.5 1.5 0 0010.5 6h-1zM3.5 10A1.5 1.5 0 002 11.5v5A1.5 1.5 0 003.5 18h1A1.5 1.5 0 006 16.5v-5A1.5 1.5 0 004.5 10h-1z" /></svg>',
    messages: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5"><path fill-rule="evenodd" d="M10 2c-4.418 0-8 2.91-8 6.5 0 1.62.732 3.09 1.94 4.21-.075 1.03-.4 2.02-.94 2.86a.5.5 0 00.53.76 6.7 6.7 0 003.02-1.14A9.6 9.6 0 0010 15c4.418 0 8-2.91 8-6.5S14.418 2 10 2z" clip-rule="evenodd" /></svg>',
    pricing: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.94 6.94a.75.75 0 00-1.06-1.06A5.733 5.733 0 006.2 9.25H5.5a.75.75 0 000 1.5h.531a5.78 5.78 0 000 .5H5.5a.75.75 0 000 1.5h.7a5.733 5.733 0 001.68 3.37.75.75 0 101.06-1.06A4.235 4.235 0 017.733 13H10.5a.75.75 0 000-1.5H7.531a4.282 4.282 0 010-.5H10.5a.75.75 0 000-1.5H7.733a4.235 4.235 0 011.207-2.06z" clip-rule="evenodd" /></svg>',
    tenants: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5"><path fill-rule="evenodd" d="M1 5.25A2.25 2.25 0 013.25 3h13.5A2.25 2.25 0 0119 5.25v9.5A2.25 2.25 0 0116.75 17H3.25A2.25 2.25 0 011 14.75v-9.5zM5 7.5A1.5 1.5 0 016.5 6h1A1.5 1.5 0 019 7.5v1A1.5 1.5 0 017.5 10h-1A1.5 1.5 0 015 8.5v-1zm6.25-.75a.75.75 0 000 1.5h3.5a.75.75 0 000-1.5h-3.5zm0 3a.75.75 0 000 1.5h3.5a.75.75 0 000-1.5h-3.5zM5.75 13a.75.75 0 000 1.5h8.5a.75.75 0 000-1.5h-8.5z" clip-rule="evenodd" /></svg>',
    inventory: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5"><path d="M2.25 5.5 10 1.625 17.75 5.5 10 9.375 2.25 5.5Z"/><path d="m2 7.25 7.25 3.625v7.25L2 14.5V7.25Zm8.75 3.625L18 7.25v7.25l-7.25 3.625v-7.25Z"/></svg>',
};

// All possible nav items with permission requirements
const allNavItems = computed(() => [
    { label: t('admin.sidebar.dashboard'), href: '/dashboard', routeName: 'dashboard', icon: icons.dashboard, permission: null },
    { label: t('admin.sidebar.rooms'), href: '/pms/rooms', icon: icons.rooms, permission: 'view_rooms' },
    { label: t('admin.sidebar.reservations'), href: '/pms/reservations', match: '/pms/reservations', icon: icons.reservations, permission: 'view_reservations' },
    { label: t('admin.sidebar.messages'), href: '/pms/messages', match: '/pms/messages', icon: icons.messages, permission: 'view_reservations', module: 'channel_manager' },
    { label: t('admin.sidebar.guests'), href: '/pms/guests', icon: icons.guests, permission: 'view_guests' },
    { label: t('admin.sidebar.housekeeping'), href: '/pms/housekeeping', icon: icons.housekeeping, permission: 'view_housekeeping', module: 'housekeeping' },
    { label: t('maintenance.title'), href: '/pms/maintenance', icon: icons.maintenance, permission: 'view_maintenance' },
    { label: t('admin.sidebar.pos'), href: '/pms/pos', icon: icons.pos, permission: 'view_pos_orders', module: 'pos' },
    {
        label: t('admin.sidebar.finance'),
        icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5"><path d="M20 7V6a2 2 0 0 0-2-2H5a3 3 0 0 0 0 6h15v8a2 2 0 0 1-2 2H5a3 3 0 0 1-3-3V7"/><path d="M16 14h4"/></svg>',
        permission: 'view_finance',
        module: 'finance',
        children: [
            { label: t('admin.sidebar.financeDashboard'), href: '/pms/finance' },
            { label: t('admin.sidebar.cashAndBank'), href: '/pms/finance/accounts' },
            { label: t('admin.sidebar.payments'), href: '/pms/finance/payments' },
            { label: t('admin.sidebar.bills'), href: '/pms/finance/bills' },
            { label: t('admin.sidebar.suppliers'), href: '/pms/finance/suppliers' },
        ],
    },
    {
        label: t('admin.sidebar.inventory'),
        icon: icons.inventory,
        permission: 'view_inventory',
        module: 'finance',
        children: [
            { label: t('admin.sidebar.inventoryOverview'), href: '/pms/inventory' },
            { label: t('admin.sidebar.inventoryItems'), href: '/pms/inventory/items' },
            { label: t('admin.sidebar.inventoryWarehouses'), href: '/pms/inventory/warehouses' },
        ],
    },
    { label: t('admin.sidebar.reports'), href: '/pms/reports', icon: icons.reports, permission: 'view_reports' },
    { label: t('admin.sidebar.pricing'), href: '/pms/pricing', icon: icons.pricing, permission: 'view_settings' },
    { label: t('admin.sidebar.smartPricing'), href: '/pms/pricing/smart', icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5"><path d="M15.98 1.804a1 1 0 00-1.96 0l-.24 1.192a1 1 0 01-.784.785l-1.192.238a1 1 0 000 1.962l1.192.238a1 1 0 01.785.785l.238 1.192a1 1 0 001.962 0l.238-1.192a1 1 0 01.785-.785l1.192-.238a1 1 0 000-1.962l-1.192-.238a1 1 0 01-.785-.785l-.238-1.192zM6.949 5.684a1 1 0 00-1.898 0l-.683 2.051a1 1 0 01-.633.633l-2.051.683a1 1 0 000 1.898l2.051.684a1 1 0 01.633.632l.683 2.051a1 1 0 001.898 0l.683-2.051a1 1 0 01.633-.633l2.051-.683a1 1 0 000-1.898l-2.051-.683a1 1 0 01-.633-.633L6.95 5.684zM13.949 13.684a1 1 0 00-1.898 0l-.184.551a1 1 0 01-.632.633l-.551.183a1 1 0 000 1.898l.551.184a1 1 0 01.633.632l.183.551a1 1 0 001.898 0l.184-.551a1 1 0 01.632-.633l.551-.183a1 1 0 000-1.898l-.551-.184a1 1 0 01-.633-.632l-.183-.551z" /></svg>', permission: 'view_settings', module: 'smart_pricing' },
]);

// Filter nav items based on user permissions
const navItems = computed(() =>
    allNavItems.value.filter((item) =>
        (!item.permission || can(item.permission))
        && hasModule(item.module)
        && (!item.role || page.props.auth.user?.role === item.role)
    )
);
</script>

<template>
    <div class="flex min-h-screen bg-neutral-50">
        <!-- Desktop sidebar -->
        <div class="hidden lg:block">
            <Sidebar
                :items="navItems"
                :collapsed="sidebarCollapsed"
                @toggle="sidebarCollapsed = !sidebarCollapsed"
            />
        </div>

        <!-- Mobile overlay -->
        <Teleport to="body">
            <Transition
                enter-active-class="duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-if="mobileMenuOpen" class="fixed inset-0 z-40 lg:hidden">
                    <div class="fixed inset-0 bg-neutral-900/50" @click="mobileMenuOpen = false" />
                    <div class="fixed inset-y-0 left-0 z-50 w-[260px]">
                        <Sidebar
                            :items="navItems"
                            :collapsed="false"
                            dismissible
                            @toggle="mobileMenuOpen = false"
                        />
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Main content -->
        <div class="flex-1 flex flex-col min-w-0">
            <!-- Top bar -->
            <header class="flex items-center justify-between h-16 px-4 sm:px-6 bg-white border-b border-neutral-200 sticky top-0 z-30">
                <!-- Mobile hamburger -->
                <button
                    class="lg:hidden rounded-md p-2 text-neutral-500 hover:text-neutral-700 hover:bg-neutral-100"
                    @click="mobileMenuOpen = true"
                >
                    <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>

                <div class="hidden lg:block" />

                <!-- User dropdown -->
                <div class="flex items-center gap-4">
                    <NotificationBell v-if="can('view_reservations')" />
                    <LanguageSwitcher variant="icon" />
                    <Dropdown align="right" width="72" content-classes="overflow-hidden rounded-xl bg-white p-2">
                        <template #trigger>
                            <button class="flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-body-sm text-neutral-600 transition-colors duration-150 hover:bg-neutral-50 hover:text-neutral-900">
                                <div class="flex h-9 w-9 items-center justify-center rounded-full bg-primary-100">
                                    <span class="text-small font-semibold text-primary-700">
                                        {{ $page.props.auth.user.name.charAt(0).toUpperCase() }}
                                    </span>
                                </div>
                                <span class="hidden sm:inline">{{ $page.props.auth.user.name }}</span>
                                <ChevronDown class="h-4 w-4 text-neutral-400" />
                            </button>
                        </template>
                        <template #content>
                            <div class="mb-1 flex items-center gap-3 border-b border-neutral-100 px-2.5 py-3">
                                <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-accent-50 font-bold text-accent-700">
                                    {{ $page.props.auth.user.name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase() }}
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate text-body-sm font-bold text-primary-900">{{ $page.props.auth.user.name }}</p>
                                    <p class="truncate text-tiny text-neutral-500">{{ $page.props.auth.user.role || $t('accountCenter.staff') }} · {{ $page.props.tenant?.name }}</p>
                                </div>
                            </div>

                            <Link :href="route('profile.edit')" class="flex items-center gap-3 rounded-lg px-2.5 py-2.5 no-underline transition hover:bg-accent-50">
                                <span class="grid h-8 w-8 place-items-center rounded-lg bg-neutral-100 text-neutral-600"><UserRound class="h-4 w-4" /></span>
                                <span><strong class="block text-body-sm text-primary-900">{{ $t('accountCenter.myProfile') }}</strong><small class="block text-tiny text-neutral-500">{{ $t('accountCenter.profileHint') }}</small></span>
                            </Link>

                            <template v-if="canAccessSettings">
                                <Link :href="route('settings.index')" class="flex items-center gap-3 rounded-lg px-2.5 py-2.5 no-underline transition hover:bg-accent-50">
                                    <span class="grid h-8 w-8 place-items-center rounded-lg bg-accent-50 text-accent-700"><Settings class="h-4 w-4" /></span>
                                    <span><strong class="block text-body-sm text-primary-900">{{ $t('accountCenter.hotelSettings') }}</strong><small class="block text-tiny text-neutral-500">{{ $t('accountCenter.settingsHint') }}</small></span>
                                </Link>
                            </template>

                            <Link :href="route('logout')" method="post" as="button" class="mt-1 flex w-full items-center gap-3 border-t border-neutral-100 px-2.5 pb-2 pt-3 text-left text-body-sm font-semibold text-error-700 transition hover:bg-error-50">
                                <LogOut class="h-4 w-4" /> {{ $t('accountCenter.logout') }}
                            </Link>
                        </template>
                    </Dropdown>
                </div>
            </header>

            <!-- Page content -->
            <main class="flex-1 p-4 sm:p-6">
                <slot />
            </main>
        </div>
    </div>
</template>
