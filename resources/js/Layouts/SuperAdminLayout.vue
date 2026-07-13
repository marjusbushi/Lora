<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Building2,
    ExternalLink,
    LayoutDashboard,
    ListChecks,
    LogOut,
    Menu,
    ShieldCheck,
    X,
} from 'lucide-vue-next';

defineProps({ title: { type: String, default: 'Lora Control Panel' } });

const page = usePage();
const mobileOpen = ref(false);
const user = computed(() => page.props.auth?.user || {});

const navigation = [
    { label: 'Përmbledhje', href: '/super-admin', match: '/super-admin', exact: true, icon: LayoutDashboard },
    { label: 'Hotelet & abonimet', href: '/super-admin/tenants', match: '/super-admin/tenants', icon: Building2 },
    { label: 'Aktiviteti', href: '/super-admin/activity', match: '/super-admin/activity', icon: ListChecks },
];

function isActive(item) {
    if (item.exact) return page.url === item.href || page.url === `${item.href}/`;
    return page.url.startsWith(item.match);
}
</script>

<template>
    <Head :title="title" />

    <div class="min-h-screen bg-[#f5f7f6] text-[#17201d]">
        <div v-if="mobileOpen" class="fixed inset-0 z-40 bg-neutral-950/45 lg:hidden" @click="mobileOpen = false" />

        <aside
            class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col bg-[#123d32] text-white transition-transform duration-200 lg:translate-x-0"
            :class="mobileOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex h-20 items-center justify-between border-b border-white/10 px-6">
                <Link href="/super-admin" class="flex items-center gap-3 text-white no-underline">
                    <span class="grid h-10 w-10 place-items-center rounded-2xl bg-[#7ed6ad] text-[#123d32]">
                        <ShieldCheck class="h-5 w-5" :stroke-width="2" />
                    </span>
                    <span>
                        <span class="block text-lg font-semibold tracking-tight">Lora PMS</span>
                        <span class="block text-[11px] font-medium uppercase tracking-[0.18em] text-white/50">Control Panel</span>
                    </span>
                </Link>
                <button class="rounded-lg p-2 text-white/60 hover:bg-white/10 hover:text-white lg:hidden" @click="mobileOpen = false">
                    <X class="h-5 w-5" />
                </button>
            </div>

            <nav class="flex-1 space-y-1 px-4 py-6">
                <Link
                    v-for="item in navigation"
                    :key="item.href"
                    :href="item.href"
                    class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium no-underline transition"
                    :class="isActive(item) ? 'bg-white text-[#123d32] shadow-sm' : 'text-white/70 hover:bg-white/10 hover:text-white'"
                    @click="mobileOpen = false"
                >
                    <component :is="item.icon" class="h-5 w-5" :stroke-width="1.8" />
                    {{ item.label }}
                </Link>
            </nav>

            <div class="border-t border-white/10 p-4">
                <a href="https://lorapms.com" target="_blank" rel="noopener" class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium text-white/60 no-underline hover:bg-white/10 hover:text-white">
                    <ExternalLink class="h-5 w-5" :stroke-width="1.8" />
                    Faqja prezantuese
                </a>
            </div>
        </aside>

        <div class="lg:pl-72">
            <header class="sticky top-0 z-30 flex h-20 items-center justify-between border-b border-neutral-200 bg-white/95 px-4 backdrop-blur sm:px-8">
                <button class="rounded-xl p-2 text-neutral-600 hover:bg-neutral-100 lg:hidden" @click="mobileOpen = true">
                    <Menu class="h-6 w-6" />
                </button>

                <div class="hidden lg:block">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-700">Administrimi i platformës</p>
                    <p class="mt-1 text-sm text-neutral-500">Pa të dhëna operative të hotelit</p>
                </div>

                <div class="ml-auto flex items-center gap-3">
                    <div class="hidden text-right sm:block">
                        <p class="text-sm font-semibold text-neutral-900">{{ user.name }}</p>
                        <p class="text-xs text-neutral-500">Super Admin</p>
                    </div>
                    <div class="grid h-10 w-10 place-items-center rounded-full bg-emerald-100 text-sm font-bold text-emerald-800">
                        {{ user.name?.charAt(0)?.toUpperCase() || 'A' }}
                    </div>
                    <Link href="/logout" method="post" as="button" class="rounded-xl p-2.5 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" title="Dil">
                        <LogOut class="h-5 w-5" />
                    </Link>
                </div>
            </header>

            <main class="p-4 sm:p-8">
                <slot />
            </main>
        </div>
    </div>
</template>
