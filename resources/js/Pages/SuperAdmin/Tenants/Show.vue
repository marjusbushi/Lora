<script setup>
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import Button from '@/Components/UI/Button.vue';
import {
    ArrowUpRight,
    Building2,
    CheckCircle2,
    CircleAlert,
    CircleGauge,
    CreditCard,
    Crown,
    ListChecks,
    LogIn,
    Settings2,
    UserRound,
} from 'lucide-vue-next';

const { t } = useI18n();
const props = defineProps({
    tenant: Object,
    members: Array,
    activity: Array,
    currentTenantId: Number,
});

const isCurrent = computed(() => props.tenant.id === props.currentTenantId);
const isActive = computed(() => props.tenant.status === 'active');
const billingIsHealthy = computed(() => ['active', 'trialing'].includes(props.tenant.billing?.status));
const activeMembers = computed(() => props.members.filter((member) => member.is_active));
const channexConfigured = computed(() => Boolean(
    props.tenant.integrations?.channex?.enabled && props.tenant.integrations?.channex?.has_api_key,
));
const pokConfigured = computed(() => Boolean(
    props.tenant.integrations?.pok?.enabled && props.tenant.integrations?.pok?.has_key_id,
));
const configuredIntegrations = computed(() => Number(channexConfigured.value) + Number(pokConfigured.value));

const readinessChecks = computed(() => [
    isActive.value,
    billingIsHealthy.value,
    Boolean(props.tenant.primary_domain),
    channexConfigured.value,
    pokConfigured.value,
    activeMembers.value.length > 0,
]);
const readinessScore = computed(() => Math.round(
    (readinessChecks.value.filter(Boolean).length / readinessChecks.value.length) * 100,
));
const attentionCount = computed(() => readinessChecks.value.filter((item) => !item).length);
const readinessTone = computed(() => readinessScore.value === 100 ? '#17745c' : '#b56a10');
const readinessRing = computed(() => ({
    background: `conic-gradient(${readinessTone.value} 0 ${readinessScore.value}%, #edf1ef ${readinessScore.value}% 100%)`,
}));

const enabledModules = computed(() =>
    Object.values(props.tenant.billing?.modules || {}).filter((module) => module.enabled),
);

const variableFee = computed(() => {
    const module = enabledModules.value.find((item) => item.billing_model === 'percentage');
    if (!module?.percentage_bps) return t('superAdmin.dynamic.noVariableFee');
    return t('superAdmin.dynamic.percentOfReservations', { percent: Number(module.percentage_bps) / 100 });
});

function money(cents) {
    return new Intl.NumberFormat('sq-AL', {
        style: 'currency',
        currency: props.tenant.currency || 'EUR',
        maximumFractionDigits: 0,
    }).format((cents || 0) / 100);
}

function date(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('sq-AL', {
        day: '2-digit', month: 'short', year: 'numeric',
    }).format(new Date(value));
}

function when(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('sq-AL', {
        day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit',
    }).format(new Date(value));
}

function statusLabel(status) {
    return {
        trialing: t('superAdmin.auto.copy049'),
        active: t('superAdmin.auto.copy005'),
        past_due: t('superAdmin.auto.copy042'),
        suspended: t('superAdmin.auto.copy044'),
        canceled: t('superAdmin.auto.copy009'),
        inactive: t('superAdmin.dynamic.inactive'),
    }[status] || status;
}

function initials(name) {
    return name.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
}

function quantityLabel(module) {
    if (!['tiered_per_room', 'per_user', 'per_pos'].includes(module.billing_model)) return '';
    return ` · ${module.quantity} ${module.unit_label || ''}`.trimEnd();
}

const ACTION_LABELS = {
    'tenant.create': t('superAdmin.dynamic.actionHotelCreated'),
    'tenant.switch': t('superAdmin.dynamic.actionHotelLogin'),
    'tenant.subscription.update': t('superAdmin.dynamic.actionSubscriptionUpdated'),
    'tenant.integration.update': t('superAdmin.dynamic.actionIntegrationUpdated'),
    'tenant.domain.create': t('superAdmin.dynamic.actionDomainAdded'),
    'tenant.domain.delete': t('superAdmin.dynamic.actionDomainRemoved'),
    'tenant.domain.primary': t('superAdmin.dynamic.actionPrimaryDomainUpdated'),
    'tenant.status': t('superAdmin.dynamic.actionHotelStatusUpdated'),
};

function managementUrl(section) {
    return route('super-admin.tenants.index', { manage: props.tenant.id, section });
}

function activityUrl() {
    return route('super-admin.activity', { tenant: props.tenant.id, range: 30 });
}

function openHotel() {
    if (!isActive.value || isCurrent.value) return;
    router.post(route('super-admin.tenants.switch', props.tenant.id));
}

function toggleStatus() {
    const suspend = isActive.value;
    const message = suspend
        ? t('superAdmin.dynamic.confirmSuspendShort', { name: props.tenant.name })
        : t('superAdmin.dynamic.confirmActivate', { name: props.tenant.name });
    if (!confirm(message)) return;
    router.patch(route('super-admin.tenants.status', props.tenant.id), {
        status: suspend ? 'suspended' : 'active',
    }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`${tenant.name} — Lora Control Panel`" />

    <SuperAdminLayout :title="`${tenant.name} — Lora Control Panel`">
        <div class="mx-auto max-w-[1480px] space-y-4">
            <div>
                <div class="text-xs text-neutral-400">
                    <Link href="/super-admin" class="text-neutral-400 no-underline hover:text-neutral-700">Control Panel</Link>
                    <span class="mx-2">/</span>
                    <Link href="/super-admin/tenants" class="text-neutral-400 no-underline hover:text-neutral-700">{{ $t('superAdmin.auto.copy087') }}</Link>
                    <span class="mx-2">/</span>
                    <span class="font-medium text-neutral-600">{{ tenant.name }}</span>
                </div>

                <div class="mt-3 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 class="text-3xl font-semibold tracking-tight text-neutral-950">{{ $t('superAdmin.auto.copy092') }}</h1>
                        <p class="mt-2 text-sm text-neutral-500">{{ $t('superAdmin.auto.copy077') }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" :class="isActive ? '!text-red-600' : '!text-emerald-700'" @click="toggleStatus">
                            {{ isActive ? t('superAdmin.dynamic.suspend') : t('superAdmin.dynamic.activate') }}
                        </Button>
                        <Button variant="primary" :disabled="!isActive || isCurrent" @click="openHotel">
                            {{ isCurrent ? t('superAdmin.dynamic.inUse') : t('superAdmin.dynamic.openHotel') }}
                            <ArrowUpRight class="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            </div>

            <section class="flex flex-col gap-5 rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm shadow-neutral-200/30 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex min-w-0 items-center gap-4">
                    <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-emerald-50 text-emerald-700">
                        <Building2 class="h-6 w-6" :stroke-width="1.8" />
                    </span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="truncate text-lg font-semibold text-neutral-900">{{ tenant.name }}</h2>
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold" :class="isActive ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'">
                                <span class="h-1.5 w-1.5 rounded-full" :class="isActive ? 'bg-emerald-500' : 'bg-red-500'" />
                                {{ isActive ? t('superAdmin.auto.copy005') : t('superAdmin.auto.copy044') }}
                            </span>
                            <span v-if="isCurrent" class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700">{{ $t('superAdmin.auto.copy080') }}</span>
                        </div>
                        <p class="mt-1 truncate text-sm text-neutral-500">
                            {{ tenant.slug }} · {{ tenant.timezone }} · {{ tenant.currency }} · {{ t('superAdmin.dynamic.createdOn', { date: date(tenant.created_at) }) }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                    <div class="grid grid-cols-2 gap-4 sm:flex sm:gap-0">
                        <div class="sm:border-l sm:border-neutral-200 sm:px-5">
                            <p class="text-xs text-neutral-400">{{ $t('superAdmin.auto.copy012') }}</p>
                            <p class="mt-1 max-w-52 truncate text-sm font-semibold" :class="tenant.primary_domain ? 'text-neutral-800' : 'text-amber-700'">
                                {{ tenant.primary_domain || t('superAdmin.dynamic.missing') }}
                            </p>
                        </div>
                        <div class="sm:border-l sm:border-neutral-200 sm:px-5">
                            <p class="text-xs text-neutral-400">{{ $t('superAdmin.auto.copy019') }}</p>
                            <p class="mt-1 text-sm font-semibold" :class="readinessScore === 100 ? 'text-emerald-700' : 'text-amber-700'">
                                {{ readinessScore === 100 ? t('superAdmin.auto.copy063') : t('superAdmin.dynamic.needsAttention') }}
                            </p>
                        </div>
                    </div>
                    <Link :href="managementUrl('config')" class="inline-flex items-center justify-center gap-2 rounded-xl border border-neutral-200 bg-white px-4 py-2.5 text-sm font-semibold text-neutral-700 no-underline hover:bg-neutral-50">
                        <Settings2 class="h-4 w-4" /> {{ t('superAdmin.dynamic.manageConfiguration') }}
                    </Link>
                </div>
            </section>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm shadow-neutral-200/30">
                    <div class="flex items-start justify-between">
                        <p class="text-sm font-medium text-neutral-500">{{ $t('superAdmin.auto.copy003') }}</p>
                        <span class="grid h-10 w-10 place-items-center rounded-xl bg-emerald-50 text-emerald-700"><CreditCard class="h-5 w-5" /></span>
                    </div>
                    <p class="mt-2 text-2xl font-semibold tracking-tight text-neutral-950">{{ statusLabel(tenant.billing.status) }}</p>
                    <p class="mt-1 text-xs text-neutral-400">{{ t('superAdmin.dynamic.billingCycle', { cycle: tenant.billing.billing_cycle === 'annual' ? t('superAdmin.dynamic.annualLower') : t('superAdmin.dynamic.monthlyLower') }) }}</p>
                </article>
                <article class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm shadow-neutral-200/30">
                    <div class="flex items-start justify-between">
                        <p class="text-sm font-medium text-neutral-500">MRR</p>
                        <span class="grid h-10 w-10 place-items-center rounded-xl bg-blue-50 font-semibold text-blue-700">€</span>
                    </div>
                    <p class="mt-2 text-2xl font-semibold tracking-tight text-neutral-950">{{ money(tenant.mrr_cents) }}</p>
                    <p class="mt-1 text-xs text-neutral-400">{{ $t('superAdmin.auto.copy090') }}</p>
                </article>
                <article class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm shadow-neutral-200/30">
                    <div class="flex items-start justify-between">
                        <p class="text-sm font-medium text-neutral-500">{{ $t('superAdmin.auto.copy051') }}</p>
                        <span class="grid h-10 w-10 place-items-center rounded-xl bg-violet-50 text-violet-700"><UserRound class="h-5 w-5" /></span>
                    </div>
                    <p class="mt-2 text-2xl font-semibold tracking-tight text-neutral-950">{{ members.length }}</p>
                    <p class="mt-1 text-xs text-neutral-400">{{ t('superAdmin.dynamic.activeCount', { count: activeMembers.length }) }}</p>
                </article>
                <article class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm shadow-neutral-200/30">
                    <div class="flex items-start justify-between">
                        <p class="text-sm font-medium text-neutral-500">{{ $t('superAdmin.auto.copy085') }}</p>
                        <span class="grid h-10 w-10 place-items-center rounded-xl" :class="readinessScore === 100 ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'"><CircleGauge class="h-5 w-5" /></span>
                    </div>
                    <p class="mt-2 text-2xl font-semibold tracking-tight text-neutral-950">{{ readinessScore }}%</p>
                    <p class="mt-1 text-xs" :class="attentionCount ? 'text-amber-700' : 'text-emerald-700'">
                        {{ attentionCount ? t('superAdmin.dynamic.itemsToComplete', { count: attentionCount }) : t('superAdmin.auto.copy065') }}
                    </p>
                </article>
            </section>

            <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1.45fr)_minmax(320px,.75fr)]">
                <div class="space-y-4">
                    <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm shadow-neutral-200/30">
                        <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4">
                            <div>
                                <h3 class="text-lg font-semibold text-neutral-900">{{ $t('superAdmin.auto.copy086') }}</h3>
                                <p class="mt-1 text-sm text-neutral-500">{{ $t('superAdmin.auto.copy104') }}</p>
                            </div>
                            <span class="rounded-full bg-neutral-100 px-2.5 py-1 text-xs font-semibold text-neutral-600">{{ t('superAdmin.dynamic.actionsCount', { count: attentionCount }) }}</span>
                        </div>
                        <div class="grid gap-5 p-5 md:grid-cols-[92px_minmax(0,1fr)] md:items-center">
                            <div class="relative grid h-[88px] w-[88px] place-items-center rounded-full" :style="readinessRing">
                                <span class="absolute inset-[9px] rounded-full bg-white" />
                                <strong class="relative text-xl text-neutral-900">{{ readinessScore }}%</strong>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <div class="rounded-xl border border-neutral-200 p-3">
                                    <div class="flex items-center justify-between gap-2 text-xs"><span class="text-neutral-500">{{ $t('superAdmin.auto.copy003') }}</span><strong :class="billingIsHealthy ? 'text-emerald-700' : 'text-amber-700'">{{ billingIsHealthy ? t('superAdmin.auto.copy063') : t('superAdmin.dynamic.check') }}</strong></div>
                                    <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-neutral-100"><div class="h-full rounded-full" :class="billingIsHealthy ? 'w-full bg-emerald-600' : 'w-1/12 bg-amber-500'" /></div>
                                </div>
                                <div class="rounded-xl border border-neutral-200 p-3">
                                    <div class="flex items-center justify-between gap-2 text-xs"><span class="text-neutral-500">{{ $t('superAdmin.auto.copy012') }}</span><strong :class="tenant.primary_domain ? 'text-emerald-700' : 'text-amber-700'">{{ tenant.primary_domain ? t('superAdmin.auto.copy063') : t('superAdmin.dynamic.missing') }}</strong></div>
                                    <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-neutral-100"><div class="h-full rounded-full" :class="tenant.primary_domain ? 'w-full bg-emerald-600' : 'w-1/12 bg-amber-500'" /></div>
                                </div>
                                <div class="rounded-xl border border-neutral-200 p-3">
                                    <div class="flex items-center justify-between gap-2 text-xs"><span class="text-neutral-500">{{ $t('superAdmin.auto.copy088') }}</span><strong :class="configuredIntegrations === 2 ? 'text-emerald-700' : 'text-amber-700'">{{ configuredIntegrations }}/2</strong></div>
                                    <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-neutral-100"><div class="h-full rounded-full" :class="configuredIntegrations === 2 ? 'w-full bg-emerald-600' : configuredIntegrations === 1 ? 'w-1/2 bg-amber-500' : 'w-1/12 bg-amber-500'" /></div>
                                </div>
                            </div>
                        </div>
                        <div v-if="attentionCount" class="mx-5 mb-5 flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50/70 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-start gap-3">
                                <CircleAlert class="mt-0.5 h-5 w-5 shrink-0 text-amber-700" />
                                <p class="text-sm text-amber-900"><strong>{{ $t('superAdmin.auto.copy095') }}</strong> {{ $t('superAdmin.auto.copy103') }}</p>
                            </div>
                            <Link :href="managementUrl('config')" class="shrink-0 text-sm font-semibold text-amber-800 no-underline hover:text-amber-950">{{ $t('superAdmin.auto.copy091') }}</Link>
                        </div>
                        <div v-else class="mx-5 mb-5 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50/70 p-4 text-sm font-medium text-emerald-800">
                            <CheckCircle2 class="h-5 w-5" /> {{ t('superAdmin.dynamic.hotelReady') }}
                        </div>
                    </section>

                    <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm shadow-neutral-200/30">
                        <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4">
                            <div><h3 class="text-lg font-semibold text-neutral-900">{{ $t('superAdmin.auto.copy035') }}</h3><p class="mt-1 text-sm text-neutral-500">{{ t('superAdmin.dynamic.includedModulesCount', { count: enabledModules.length }) }}</p></div>
                            <Link :href="managementUrl('billing')" class="rounded-xl border border-neutral-200 px-3.5 py-2 text-sm font-semibold text-neutral-700 no-underline hover:bg-neutral-50">{{ $t('superAdmin.auto.copy089') }}</Link>
                        </div>
                        <div v-if="enabledModules.length" class="flex flex-wrap gap-2 p-5">
                            <span v-for="module in enabledModules" :key="module.code" class="rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-1.5 text-xs font-medium text-neutral-700">
                                {{ module.name }}{{ quantityLabel(module) }}
                            </span>
                        </div>
                        <p v-else class="p-5 text-sm text-neutral-500">{{ $t('superAdmin.auto.copy081') }}</p>
                    </section>
                </div>

                <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm shadow-neutral-200/30">
                    <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4">
                        <div><h3 class="text-lg font-semibold text-neutral-900">{{ $t('superAdmin.auto.copy003') }}</h3><p class="mt-1 text-sm text-neutral-500">{{ $t('superAdmin.auto.copy094') }}</p></div>
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold" :class="billingIsHealthy ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">
                            <span class="h-1.5 w-1.5 rounded-full" :class="billingIsHealthy ? 'bg-emerald-500' : 'bg-amber-500'" />
                            {{ statusLabel(tenant.billing.status) }}
                        </span>
                    </div>
                    <div class="divide-y divide-neutral-100 px-5">
                        <div class="flex justify-between gap-4 py-3.5 text-sm"><span class="text-neutral-500">{{ $t('superAdmin.auto.copy082') }}</span><strong class="text-neutral-800">{{ tenant.billing.billing_cycle === 'annual' ? t('superAdmin.dynamic.annual') : t('superAdmin.dynamic.monthly') }}</strong></div>
                        <div class="flex justify-between gap-4 py-3.5 text-sm"><span class="text-neutral-500">{{ $t('superAdmin.auto.copy101') }}</span><strong class="text-neutral-800">{{ t('superAdmin.dynamic.amountPerMonth', { amount: money(tenant.mrr_cents) }) }}</strong></div>
                        <div class="flex justify-between gap-4 py-3.5 text-sm"><span class="text-neutral-500">{{ $t('superAdmin.auto.copy099') }}</span><strong class="text-right text-neutral-800">{{ variableFee }}</strong></div>
                        <div class="flex justify-between gap-4 py-3.5 text-sm"><span class="text-neutral-500">{{ $t('superAdmin.auto.copy096') }}</span><strong class="text-neutral-800">{{ date(tenant.billing.current_period_ends_at) }}</strong></div>
                        <div class="flex justify-between gap-4 py-3.5 text-sm"><span class="text-neutral-500">{{ $t('superAdmin.auto.copy098') }}</span><strong :class="billingIsHealthy ? 'text-emerald-700' : 'text-amber-700'">{{ billingIsHealthy ? t('superAdmin.auto.copy063') : statusLabel(tenant.billing.status) }}</strong></div>
                    </div>
                    <div class="p-5 pt-2">
                        <Link :href="managementUrl('billing')" class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-neutral-200 px-4 py-2.5 text-sm font-semibold text-neutral-700 no-underline hover:bg-neutral-50">
                            <CreditCard class="h-4 w-4" /> {{ $t('superAdmin.auto.copy029') }}
                        </Link>
                    </div>
                </section>
            </div>

            <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)]">
                <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm shadow-neutral-200/30">
                    <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4">
                        <div><h3 class="text-lg font-semibold text-neutral-900">{{ $t('superAdmin.auto.copy093') }}</h3><p class="mt-1 text-sm text-neutral-500">{{ $t('superAdmin.auto.copy078') }}</p></div>
                        <span class="rounded-full bg-neutral-100 px-2.5 py-1 text-xs font-semibold text-neutral-600">{{ t('superAdmin.dynamic.activeCount', { count: activeMembers.length }) }}</span>
                    </div>
                    <ul v-if="members.length" class="divide-y divide-neutral-100 px-5">
                        <li v-for="member in members" :key="member.id" class="flex items-center justify-between gap-3 py-3.5">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-blue-50 text-xs font-semibold text-blue-700">{{ initials(member.name) }}</span>
                                <div class="min-w-0"><p class="flex items-center gap-1.5 truncate text-sm font-semibold text-neutral-900">{{ member.name }} <Crown v-if="member.is_owner" class="h-3.5 w-3.5 shrink-0 text-amber-500" /></p><p class="truncate text-xs text-neutral-500">{{ member.email }}</p></div>
                            </div>
                            <div class="flex shrink-0 items-center gap-2"><span v-if="member.role" class="rounded-full bg-neutral-100 px-2 py-1 text-xs font-medium text-neutral-600">{{ member.role }}</span><span v-if="!member.is_active" class="rounded-full bg-red-50 px-2 py-1 text-xs font-medium text-red-600">{{ $t('superAdmin.auto.copy102') }}</span></div>
                        </li>
                    </ul>
                    <p v-else class="p-8 text-center text-sm text-neutral-500">{{ $t('superAdmin.auto.copy083') }}</p>
                </section>

                <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm shadow-neutral-200/30">
                    <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4">
                        <div><h3 class="text-lg font-semibold text-neutral-900">{{ $t('superAdmin.auto.copy079') }}</h3><p class="mt-1 text-sm text-neutral-500">{{ $t('superAdmin.auto.copy100') }}</p></div>
                        <Link :href="activityUrl()" class="shrink-0 rounded-xl border border-neutral-200 px-3.5 py-2 text-sm font-semibold text-neutral-700 no-underline hover:bg-neutral-50">{{ $t('superAdmin.auto.copy097') }}</Link>
                    </div>
                    <ul v-if="activity.length" class="divide-y divide-neutral-100 px-5">
                        <li v-for="log in activity.slice(0, 5)" :key="log.id" class="grid grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-3 py-3">
                            <span class="grid h-9 w-9 place-items-center rounded-xl bg-neutral-100 text-neutral-600"><LogIn v-if="log.action === 'tenant.switch'" class="h-4 w-4" /><CreditCard v-else-if="log.action === 'tenant.subscription.update'" class="h-4 w-4" /><ListChecks v-else class="h-4 w-4" /></span>
                            <div class="min-w-0"><p class="truncate text-sm font-semibold text-neutral-800">{{ ACTION_LABELS[log.action] || log.action }}</p><p class="truncate text-xs text-neutral-500">{{ log.actor }}</p></div>
                            <time class="text-xs text-neutral-400">{{ when(log.created_at) }}</time>
                        </li>
                    </ul>
                    <p v-else class="p-8 text-center text-sm text-neutral-500">{{ $t('superAdmin.auto.copy084') }}</p>
                </section>
            </div>
        </div>
    </SuperAdminLayout>
</template>
