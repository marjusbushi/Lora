<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import { Building2, Globe, Users, Plug, ListChecks, Crown } from 'lucide-vue-next';

const props = defineProps({
    tenant: Object,
    members: Array,
    activity: Array,
    currentTenantId: Number,
});

const isCurrent = computed(() => props.tenant.id === props.currentTenantId);
const isActive = computed(() => props.tenant.status === 'active');

function money(cents) {
    return new Intl.NumberFormat('sq-AL', { style: 'currency', currency: props.tenant.currency || 'EUR', maximumFractionDigits: 0 })
        .format((cents || 0) / 100);
}

function date(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('sq-AL', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(value));
}

function when(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('sq-AL', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}

function statusLabel(status) {
    return { trialing: 'Provë', active: 'Aktiv', past_due: 'Pagesë e vonuar', suspended: 'Pezulluar', canceled: 'Anuluar', inactive: 'Joaktiv' }[status] || status;
}

const ACTION_LABELS = {
    'tenant.create': 'Hotel u krijua', 'tenant.switch': 'Hyrje në hotel', 'tenant.subscription.update': 'Abonimi u përditësua',
    'tenant.integration.update': 'Integrim u ndryshua', 'tenant.domain.create': 'Domain u shtua', 'tenant.domain.delete': 'Domain u hoq',
    'tenant.domain.primary': 'Domain primar', 'tenant.status': 'Statusi i hotelit',
};

const enabledModules = computed(() =>
    Object.values(props.tenant.billing?.modules || {}).filter((m) => m.enabled),
);

function openHotel() {
    if (!isActive.value || isCurrent.value) return;
    router.post(route('super-admin.tenants.switch', props.tenant.id));
}

function toggleStatus() {
    const suspend = isActive.value;
    const msg = suspend
        ? `Të pezulloj ${props.tenant.name}? Hoteli s'do të hapet dot derisa ta riaktivizosh.`
        : `Të riaktivizoj ${props.tenant.name}?`;
    if (!confirm(msg)) return;
    router.patch(route('super-admin.tenants.status', props.tenant.id), { status: suspend ? 'suspended' : 'active' }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`${tenant.name} — Lora Control Panel`" />

    <SuperAdminLayout :title="`${tenant.name} — Lora Control Panel`">
        <div class="mx-auto max-w-6xl space-y-6">
            <PageHeader
                :title="tenant.name"
                :breadcrumbs="[{ label: 'Control Panel', href: '/super-admin' }, { label: 'Hotelet', href: '/super-admin/tenants' }, { label: tenant.name }]"
            />

            <!-- Header card -->
            <section class="flex flex-col gap-4 rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-4">
                    <span class="grid h-12 w-12 place-items-center rounded-2xl bg-emerald-50 text-emerald-700">
                        <Building2 class="h-6 w-6" :stroke-width="1.8" />
                    </span>
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold text-neutral-900">{{ tenant.name }}</h2>
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="isActive ? 'bg-success-50 text-success-700' : 'bg-red-50 text-red-700'">
                                {{ isActive ? 'Aktiv' : 'Pezulluar' }}
                            </span>
                            <span v-if="isCurrent" class="rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700">Aktual</span>
                        </div>
                        <p class="mt-1 text-sm text-neutral-500">
                            {{ tenant.primary_domain || 'Pa domain' }} · {{ tenant.slug }} · {{ tenant.timezone }} · {{ tenant.currency }}
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button size="sm" variant="primary" :disabled="!isActive || isCurrent" @click="openHotel">
                        {{ isCurrent ? 'Në përdorim' : 'Hap hotelin' }}
                    </Button>
                    <Button size="sm" variant="outline" :class="isActive ? 'text-red-600' : 'text-success-700'" @click="toggleStatus">
                        {{ isActive ? 'Pezullo' : 'Aktivizo' }}
                    </Button>
                    <Link :href="route('super-admin.tenants.index')" class="inline-flex items-center rounded-lg border border-neutral-200 px-3 py-1.5 text-sm text-neutral-600 no-underline hover:bg-neutral-50">
                        Menaxho (abonim/domains)
                    </Link>
                </div>
            </section>

            <!-- Overview KPIs -->
            <section class="grid gap-4 sm:grid-cols-3">
                <article class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-neutral-500">Abonimi</p>
                    <p class="mt-2 text-xl font-semibold text-neutral-950">{{ statusLabel(tenant.billing.status) }}</p>
                    <p class="mt-1 text-xs text-neutral-400">
                        {{ tenant.billing.billing_cycle === 'annual' ? 'Vjetore' : 'Mujore' }}
                        <template v-if="tenant.billing.current_period_ends_at"> · rinovohet {{ date(tenant.billing.current_period_ends_at) }}</template>
                    </p>
                </article>
                <article class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-neutral-500">MRR</p>
                    <p class="mt-2 text-xl font-semibold text-neutral-950">{{ money(tenant.mrr_cents) }}</p>
                    <p class="mt-1 text-xs text-neutral-400">Pa tarifat variabël</p>
                </article>
                <article class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-neutral-500">Krijuar</p>
                    <p class="mt-2 text-xl font-semibold text-neutral-950">{{ date(tenant.created_at) }}</p>
                    <p class="mt-1 text-xs text-neutral-400">{{ members.length }} përdorues</p>
                </article>
            </section>

            <!-- Modules -->
            <section class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm">
                <h3 class="font-semibold text-neutral-900">Modulet aktive</h3>
                <div v-if="enabledModules.length" class="mt-3 flex flex-wrap gap-2">
                    <span v-for="m in enabledModules" :key="m.code" class="rounded-md border border-neutral-200 bg-neutral-50 px-2.5 py-1 text-xs font-medium text-neutral-600">
                        {{ m.name }}<template v-if="['tiered_per_room', 'per_user', 'per_pos'].includes(m.billing_model)"> · {{ m.quantity }}</template>
                    </span>
                </div>
                <p v-else class="mt-3 text-sm text-neutral-500">Asnjë modul aktiv.</p>
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Members -->
                <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm">
                    <div class="flex items-center gap-2 border-b border-neutral-200 px-5 py-4">
                        <Users class="h-5 w-5 text-neutral-400" :stroke-width="1.8" />
                        <h3 class="font-semibold text-neutral-900">Përdoruesit</h3>
                    </div>
                    <ul v-if="members.length" class="divide-y divide-neutral-100">
                        <li v-for="m in members" :key="m.id" class="flex items-center justify-between gap-3 px-5 py-3">
                            <div class="min-w-0">
                                <p class="flex items-center gap-1.5 truncate text-sm font-medium text-neutral-900">
                                    {{ m.name }}
                                    <Crown v-if="m.is_owner" class="h-3.5 w-3.5 text-amber-500" :stroke-width="2" />
                                </p>
                                <p class="truncate text-xs text-neutral-500">{{ m.email }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <span v-if="m.role" class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-600">{{ m.role }}</span>
                                <span v-if="!m.is_active" class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-600">joaktiv</span>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-8 text-center text-sm text-neutral-500">Ende asnjë përdorues.</p>
                </section>

                <!-- Domains + Integrations -->
                <div class="space-y-6">
                    <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm">
                        <div class="flex items-center gap-2 border-b border-neutral-200 px-5 py-4">
                            <Globe class="h-5 w-5 text-neutral-400" :stroke-width="1.8" />
                            <h3 class="font-semibold text-neutral-900">Domain-et</h3>
                        </div>
                        <ul v-if="tenant.domains.length" class="divide-y divide-neutral-100">
                            <li v-for="d in tenant.domains" :key="d.id" class="flex items-center justify-between px-5 py-2.5 text-sm">
                                <span class="text-neutral-800">{{ d.domain }}</span>
                                <span v-if="d.is_primary" class="rounded-full bg-primary-50 px-2 py-0.5 text-[11px] font-medium text-primary-700">Primar</span>
                            </li>
                        </ul>
                        <p v-else class="px-5 py-6 text-center text-sm text-neutral-500">Pa domain.</p>
                    </section>

                    <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm">
                        <div class="flex items-center gap-2 border-b border-neutral-200 px-5 py-4">
                            <Plug class="h-5 w-5 text-neutral-400" :stroke-width="1.8" />
                            <h3 class="font-semibold text-neutral-900">Integrimet</h3>
                        </div>
                        <div class="space-y-3 px-5 py-4 text-sm">
                            <div class="flex items-center justify-between">
                                <span class="text-neutral-700">Channex</span>
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="tenant.integrations.channex.enabled && tenant.integrations.channex.has_api_key ? 'bg-emerald-50 text-emerald-700' : 'bg-neutral-100 text-neutral-500'">
                                    {{ tenant.integrations.channex.enabled && tenant.integrations.channex.has_api_key ? 'i konfiguruar ✓' : 'jo i konfiguruar' }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-neutral-700">POK (pagesat)</span>
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="tenant.integrations.pok.enabled && tenant.integrations.pok.has_key_id ? 'bg-emerald-50 text-emerald-700' : 'bg-neutral-100 text-neutral-500'">
                                    {{ tenant.integrations.pok.enabled && tenant.integrations.pok.has_key_id ? 'i konfiguruar ✓' : 'jo i konfiguruar' }}
                                </span>
                            </div>
                        </div>
                    </section>
                </div>
            </div>

            <!-- Activity -->
            <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm">
                <div class="flex items-center gap-2 border-b border-neutral-200 px-5 py-4">
                    <ListChecks class="h-5 w-5 text-neutral-400" :stroke-width="1.8" />
                    <h3 class="font-semibold text-neutral-900">Aktiviteti i fundit</h3>
                </div>
                <ul v-if="activity.length" class="divide-y divide-neutral-100">
                    <li v-for="log in activity" :key="log.id" class="flex items-center justify-between gap-3 px-5 py-2.5 text-sm">
                        <span class="text-neutral-700">{{ ACTION_LABELS[log.action] || log.action }} · <span class="text-neutral-400">{{ log.actor }}</span></span>
                        <span class="shrink-0 text-xs text-neutral-400">{{ when(log.created_at) }}</span>
                    </li>
                </ul>
                <p v-else class="px-5 py-8 text-center text-sm text-neutral-500">Ende asnjë veprim.</p>
            </section>
        </div>
    </SuperAdminLayout>
</template>
