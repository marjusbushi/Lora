<script setup>
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { CalendarDays, CheckCircle2, ChevronRight, CircleDashed, Search, TimerReset, UserRound } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps({ tenants: Array, filters: Object });
const search = ref(props.filters?.q || '');
const status = ref(props.filters?.status || '');

const stats = computed(() => ({
    total: props.tenants.length,
    active: props.tenants.filter((item) => ['in_progress', 'ready'].includes(item.onboarding.status)).length,
    completed: props.tenants.filter((item) => item.onboarding.status === 'completed').length,
    overdue: props.tenants.filter((item) => item.onboarding.due_date && new Date(`${item.onboarding.due_date}T23:59:59`) < new Date() && item.onboarding.status !== 'completed').length,
}));

const statusLabel = (value) => ({ not_started: 'Pa filluar', in_progress: 'Në proces', ready: 'Gati', completed: 'Përfunduar' }[value] || value);
const statusClass = (value) => ({
    not_started: 'bg-neutral-100 text-neutral-600',
    in_progress: 'bg-amber-50 text-amber-700',
    ready: 'bg-blue-50 text-blue-700',
    completed: 'bg-emerald-50 text-emerald-700',
}[value] || 'bg-neutral-100 text-neutral-600');

function filter() {
    router.get('/super-admin/onboarding', { q: search.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true });
}
</script>

<template>
    <SuperAdminLayout title="Onboarding — Lora Control Panel">
        <div class="sa-page">
            <header class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <div class="sa-breadcrumb"><Link href="/super-admin" class="text-inherit no-underline hover:text-neutral-700">Control Panel</Link><span class="mx-2">/</span><span>Onboarding</span></div>
                    <h1 class="sa-page-title">Onboarding i hoteleve</h1>
                    <p class="sa-page-subtitle">Proces i kontrolluar nga stafi para dorëzimit te klienti.</p>
                </div>
            </header>

            <section class="mb-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <article class="sa-card sa-kpi-card !min-h-[118px]"><div class="flex items-start justify-between"><p class="sa-kpi-label">Gjithsej</p><span class="sa-icon-box bg-neutral-100 text-neutral-600"><CircleDashed class="h-4 w-4" /></span></div><p class="sa-kpi-value !mt-2">{{ stats.total }}</p><p class="sa-kpi-meta">Hotele në workflow</p></article>
                <article class="sa-card sa-kpi-card !min-h-[118px]"><div class="flex items-start justify-between"><p class="sa-kpi-label">Në proces</p><span class="sa-icon-box bg-amber-50 text-amber-700"><TimerReset class="h-4 w-4" /></span></div><p class="sa-kpi-value !mt-2">{{ stats.active }}</p><p class="sa-kpi-meta">Kërkojnë veprim</p></article>
                <article class="sa-card sa-kpi-card !min-h-[118px]"><div class="flex items-start justify-between"><p class="sa-kpi-label">Përfunduar</p><span class="sa-icon-box bg-emerald-50 text-emerald-700"><CheckCircle2 class="h-4 w-4" /></span></div><p class="sa-kpi-value !mt-2">{{ stats.completed }}</p><p class="sa-kpi-meta">Gati për përdorim</p></article>
                <article class="sa-card sa-kpi-card !min-h-[118px]"><div class="flex items-start justify-between"><p class="sa-kpi-label">Me vonesë</p><span class="sa-icon-box bg-red-50 text-red-600"><CalendarDays class="h-4 w-4" /></span></div><p class="sa-kpi-value !mt-2">{{ stats.overdue }}</p><p class="sa-kpi-meta">Afati ka kaluar</p></article>
            </section>

            <section class="sa-card">
                <div class="sa-card-header flex-col !items-stretch md:flex-row md:!items-center">
                    <div><h2 class="sa-card-title">Hotelet</h2><p class="sa-card-subtitle">Progresi dhe pronësia e çdo onboarding-u.</p></div>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <label class="relative min-w-[240px]"><Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="search" class="w-full pl-9" placeholder="Kërko hotel..." @keyup.enter="filter"></label>
                        <select v-model="status" class="min-w-[150px]" @change="filter"><option value="">Të gjitha</option><option value="not_started">Pa filluar</option><option value="in_progress">Në proces</option><option value="ready">Gati</option><option value="completed">Përfunduar</option></select>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-xs">
                        <thead class="border-b border-neutral-200 bg-neutral-50 text-[10px] uppercase tracking-[.08em] text-neutral-500"><tr><th class="px-[18px] py-3 font-bold">Hoteli</th><th class="px-4 py-3 font-bold">Progresi</th><th class="px-4 py-3 font-bold">Përgjegjësi</th><th class="px-4 py-3 font-bold">Afati</th><th class="px-4 py-3 font-bold">Statusi</th><th class="w-12"></th></tr></thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="item in tenants" :key="item.id" class="hover:bg-neutral-50/80">
                                <td class="px-[18px] py-3"><div class="flex items-center gap-3"><span class="sa-icon-box bg-emerald-50 text-[11px] font-bold text-emerald-800">{{ item.name.split(' ').map((part) => part[0]).join('').slice(0, 2) }}</span><div><Link :href="`/super-admin/onboarding/${item.id}`" class="font-semibold text-neutral-900 no-underline hover:text-emerald-700">{{ item.name }}</Link><p class="mt-0.5 text-[10px] text-neutral-400">{{ item.slug }}</p></div></div></td>
                                <td class="px-4 py-3"><div class="flex min-w-[150px] items-center gap-2"><div class="h-1.5 flex-1 overflow-hidden rounded-full bg-neutral-100"><span class="block h-full rounded-full bg-emerald-600" :style="{ width: `${item.onboarding.progress}%` }" /></div><strong class="w-9 text-right text-[11px]">{{ item.onboarding.progress }}%</strong></div></td>
                                <td class="px-4 py-3"><span v-if="item.onboarding.assignee" class="inline-flex items-center gap-1.5"><UserRound class="h-3.5 w-3.5 text-neutral-400" />{{ item.onboarding.assignee.name }}</span><span v-else class="text-neutral-400">Pa caktuar</span></td>
                                <td class="px-4 py-3 text-neutral-600">{{ item.onboarding.due_date || '—' }}</td>
                                <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-[10px] font-bold" :class="statusClass(item.onboarding.status)">{{ statusLabel(item.onboarding.status) }}</span></td>
                                <td class="px-3 py-3"><Link :href="`/super-admin/onboarding/${item.id}`" class="grid h-8 w-8 place-items-center rounded-lg text-neutral-400 no-underline hover:bg-emerald-50 hover:text-emerald-700"><ChevronRight class="h-4 w-4" /></Link></td>
                            </tr>
                            <tr v-if="!tenants.length"><td colspan="6" class="px-5 py-14 text-center text-sm text-neutral-400">Nuk u gjet asnjë hotel.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </SuperAdminLayout>
</template>
