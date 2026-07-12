<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import {
    ArrowRight,
    Building2,
    CircleAlert,
    CreditCard,
    Users,
} from 'lucide-vue-next';

const props = defineProps({
    stats: Object,
    moduleAdoption: Array,
    recentTenants: Array,
});

const kpis = computed(() => [
    { label: 'Hotele aktive', value: props.stats.hotels_active, detail: `${props.stats.hotels_total} gjithsej`, icon: Building2, tone: 'emerald' },
    { label: 'Abonime aktive', value: props.stats.subscriptions_active, detail: `${props.stats.subscriptions_attention} kërkojnë vëmendje`, icon: CreditCard, tone: 'blue' },
    { label: 'MRR i parashikuar', value: money(props.stats.mrr_cents), detail: 'Pa tarifat variabël 1%', icon: CircleAlert, tone: 'amber' },
    { label: 'Përdorues aktivë', value: props.stats.users_total, detail: 'Në të gjitha hotelet', icon: Users, tone: 'violet' },
]);

function money(cents) {
    return new Intl.NumberFormat('sq-AL', {
        style: 'currency',
        currency: 'EUR',
        maximumFractionDigits: 0,
    }).format((cents || 0) / 100);
}

function date(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('sq-AL', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(value));
}

function statusLabel(status) {
    return {
        trialing: 'Provë', active: 'Aktiv', past_due: 'Pagesë e vonuar',
        suspended: 'Pezulluar', canceled: 'Anuluar', inactive: 'Joaktiv',
    }[status] || status;
}
</script>

<template>
    <SuperAdminLayout title="Përmbledhje — Lora Control Panel">
        <div class="mx-auto max-w-7xl space-y-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-700">Lora PMS</p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-neutral-950">Control Panel</h1>
                    <p class="mt-2 text-sm text-neutral-500">Pamja qendrore e hoteleve, abonimeve dhe moduleve.</p>
                </div>
                <Link href="/super-admin/tenants" class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#16875d] px-5 py-3 text-sm font-semibold text-white no-underline shadow-sm hover:bg-[#116f4c]">
                    Menaxho hotelet <ArrowRight class="h-4 w-4" />
                </Link>
            </div>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article v-for="kpi in kpis" :key="kpi.label" class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-neutral-500">{{ kpi.label }}</p>
                            <p class="mt-3 text-3xl font-semibold tracking-tight text-neutral-950">{{ kpi.value }}</p>
                            <p class="mt-2 text-xs text-neutral-400">{{ kpi.detail }}</p>
                        </div>
                        <span class="grid h-11 w-11 place-items-center rounded-2xl bg-emerald-50 text-emerald-700">
                            <component :is="kpi.icon" class="h-5 w-5" :stroke-width="1.8" />
                        </span>
                    </div>
                </article>
            </section>

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1.45fr)_minmax(320px,0.75fr)]">
                <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4">
                        <div>
                            <h2 class="font-semibold text-neutral-900">Hotelet e fundit</h2>
                            <p class="mt-1 text-xs text-neutral-500">Tenantët e shtuar së fundmi në platformë.</p>
                        </div>
                        <Link href="/super-admin/tenants" class="text-sm font-semibold text-emerald-700 no-underline">Shiko të gjitha</Link>
                    </div>

                    <div class="divide-y divide-neutral-100">
                        <article v-for="tenant in recentTenants" :key="tenant.id" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-neutral-900">{{ tenant.name }}</p>
                                <p class="mt-1 truncate text-xs text-neutral-500">{{ tenant.domain || tenant.slug }} · {{ tenant.users_count }} përdorues</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-4">
                                <div class="text-right">
                                    <p class="text-sm font-semibold text-neutral-900">{{ money(tenant.mrr_cents) }}/muaj</p>
                                    <p class="mt-1 text-xs text-neutral-400">{{ date(tenant.created_at) }}</p>
                                </div>
                                <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700">{{ statusLabel(tenant.subscription_status) }}</span>
                            </div>
                        </article>
                    </div>
                </section>

                <section class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm">
                    <h2 class="font-semibold text-neutral-900">Përdorimi i moduleve</h2>
                    <p class="mt-1 text-xs text-neutral-500">Sa hotele e kanë aktiv secilin modul.</p>
                    <div class="mt-5 space-y-4">
                        <div v-for="module in moduleAdoption" :key="module.code">
                            <div class="flex items-center justify-between text-sm">
                                <span class="font-medium text-neutral-700">{{ module.name }}</span>
                                <span class="font-semibold text-neutral-900">{{ module.hotels_count }}/{{ stats.hotels_total }}</span>
                            </div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-neutral-100">
                                <div class="h-full rounded-full bg-[#16875d]" :style="{ width: `${stats.hotels_total ? (module.hotels_count / stats.hotels_total) * 100 : 0}%` }" />
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </SuperAdminLayout>
</template>
