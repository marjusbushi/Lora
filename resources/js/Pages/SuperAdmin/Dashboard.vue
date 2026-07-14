<script setup>
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import {
    ArrowRight,
    Building2,
    Check,
    CreditCard,
    Gauge,
    Layers3,
    RefreshCw,
    Users,
} from 'lucide-vue-next';

const { t } = useI18n();
const props = defineProps({
    stats: Object,
    moduleAdoption: Array,
    needsAttention: Array,
    recentTenants: Array,
});

const refreshing = ref(false);

function ratio(value, total) {
    return total > 0 ? Math.round((value / total) * 100) : 100;
}

const kpis = computed(() => [
    {
        label: t('superAdmin.auto.copy017'),
        value: props.stats.hotels_active,
        lead: `${ratio(props.stats.hotels_active, props.stats.hotels_total)}%`,
        leadClass: 'text-emerald-700',
        detail: t('superAdmin.dynamic.activePortfolio'),
        icon: Building2,
        tone: 'emerald',
    },
    {
        label: t('superAdmin.dynamic.projectedMrr'),
        value: money(props.stats.mrr_cents),
        detail: t('superAdmin.dynamic.excludesVariableFees'),
        icon: CreditCard,
        tone: 'blue',
    },
    {
        label: t('superAdmin.dynamic.activeSubscriptions'),
        value: props.stats.subscriptions_active,
        lead: props.stats.subscriptions_attention,
        leadClass: props.stats.subscriptions_attention ? 'text-amber-700' : 'text-emerald-700',
        detail: t('superAdmin.dynamic.latePayments'),
        icon: Layers3,
        tone: 'emerald',
    },
    {
        label: t('superAdmin.auto.copy051'),
        value: props.stats.users_total,
        detail: t('superAdmin.dynamic.allHotels'),
        icon: Users,
        tone: 'violet',
    },
]);

const signals = computed(() => [
    {
        label: t('superAdmin.auto.copy132'),
        value: props.stats.subscriptions_active,
        total: props.stats.hotels_total,
        tone: props.stats.subscriptions_attention ? 'attention' : 'ok',
    },
    {
        label: t('superAdmin.dynamic.domains'),
        value: props.stats.domains_configured,
        total: props.stats.hotels_total,
        tone: props.stats.domains_configured === props.stats.hotels_total ? 'ok' : 'attention',
    },
    {
        label: t('superAdmin.auto.copy088'),
        value: props.stats.integrations_ready,
        total: props.stats.integrations_total,
        tone: props.stats.integrations_ready === props.stats.integrations_total ? 'ok' : 'attention',
    },
]);

const healthLabel = computed(() => {
    if (props.stats.health_score >= 85) return t('superAdmin.dynamic.healthExcellent');
    if (props.stats.health_score >= 65) return t('superAdmin.dynamic.healthGood');
    return t('superAdmin.auto.copy064');
});

const healthDetail = computed(() => props.stats.subscriptions_attention
    ? t('superAdmin.dynamic.subscriptionsNeedAttention')
    : t('superAdmin.dynamic.subscriptionsHealthyDetail'));

function toneClass(tone) {
    return {
        emerald: 'bg-emerald-50 text-emerald-700',
        blue: 'bg-blue-50 text-blue-700',
        violet: 'bg-violet-50 text-violet-700',
    }[tone] || 'bg-neutral-100 text-neutral-600';
}

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
        trialing: t('superAdmin.auto.copy049'), active: t('superAdmin.auto.copy005'), past_due: t('superAdmin.auto.copy042'),
        suspended: t('superAdmin.auto.copy044'), canceled: t('superAdmin.auto.copy009'), inactive: t('superAdmin.dynamic.inactive'),
    }[status] || status;
}

function statusClass(status) {
    if (status === 'past_due') return 'bg-amber-50 text-amber-700';
    if (['suspended', 'canceled', 'inactive'].includes(status)) return 'bg-red-50 text-red-700';
    return 'bg-emerald-50 text-emerald-700';
}

function statusDotClass(status) {
    if (status === 'past_due') return 'bg-amber-500';
    if (['suspended', 'canceled', 'inactive'].includes(status)) return 'bg-red-500';
    return 'bg-emerald-500';
}

function attentionClass(severity) {
    return {
        danger: 'border-red-200 bg-red-50 text-red-700',
        warning: 'border-amber-200 bg-amber-50 text-amber-700',
        info: 'border-neutral-200 bg-neutral-50 text-neutral-600',
    }[severity] || 'border-neutral-200 bg-neutral-50 text-neutral-600';
}

function refresh() {
    router.reload({
        only: ['stats', 'moduleAdoption', 'needsAttention', 'recentTenants'],
        onStart: () => { refreshing.value = true; },
        onFinish: () => { refreshing.value = false; },
    });
}
</script>

<template>
    <SuperAdminLayout :title="$t('superAdmin.auto.copy145')">
        <div class="mx-auto max-w-[1480px] space-y-5">
            <div>
                <div class="text-xs text-neutral-400">
                    <span>Control Panel</span><span class="mx-2">/</span><span>{{ $t('superAdmin.auto.copy120') }}</span>
                </div>
                <div class="mt-3 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 class="text-3xl font-semibold tracking-tight text-neutral-950">{{ $t('superAdmin.auto.copy120') }}</h1>
                        <p class="mt-2 text-sm text-neutral-500">{{ $t('superAdmin.auto.copy136') }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="inline-flex items-center gap-2 rounded-xl border border-neutral-200 bg-white px-4 py-2.5 text-sm font-semibold text-neutral-700 shadow-sm hover:bg-neutral-50 disabled:opacity-60" :disabled="refreshing" @click="refresh">
                            <RefreshCw class="h-4 w-4" :class="refreshing && 'animate-spin'" />
                            {{ refreshing ? 'Duke rifreskuar…' : 'Rifresko' }}
                        </button>
                        <Link href="/super-admin/tenants" class="inline-flex items-center gap-2 rounded-xl bg-[#17745c] px-4 py-2.5 text-sm font-semibold text-white no-underline shadow-sm hover:bg-[#125f4c]">
                            {{ t('superAdmin.dynamic.manageHotels') }} <ArrowRight class="h-4 w-4" />
                        </Link>
                    </div>
                </div>
            </div>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article v-for="kpi in kpis" :key="kpi.label" class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm shadow-neutral-200/30">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold text-neutral-500">{{ kpi.label }}</p>
                            <p class="mt-3 text-3xl font-semibold tracking-tight text-neutral-950">{{ kpi.value }}</p>
                            <p class="mt-2 text-xs text-neutral-400">
                                <span v-if="kpi.lead !== undefined" class="font-semibold" :class="kpi.leadClass">{{ kpi.lead }}</span>
                                <span :class="kpi.lead !== undefined && 'ml-1'">{{ kpi.detail }}</span>
                            </p>
                        </div>
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl" :class="toneClass(kpi.tone)">
                            <component :is="kpi.icon" class="h-5 w-5" :stroke-width="1.8" />
                        </span>
                    </div>
                </article>
            </section>

            <div class="grid gap-5 xl:grid-cols-[minmax(0,1.55fr)_minmax(340px,0.75fr)]">
                <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm shadow-neutral-200/30">
                    <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4">
                        <div>
                            <h2 class="text-lg font-semibold text-neutral-900">{{ $t('superAdmin.auto.copy141') }}</h2>
                            <p class="mt-1 text-sm text-neutral-500">{{ $t('superAdmin.auto.copy144') }}</p>
                        </div>
                        <span class="rounded-full bg-neutral-100 px-2.5 py-1 text-xs font-semibold text-neutral-600">{{ t('superAdmin.dynamic.urgentCount', { count: needsAttention.length }) }}</span>
                    </div>

                    <div v-if="!needsAttention.length" class="m-5 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50/70 p-4">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-700"><Check class="h-5 w-5" :stroke-width="2.4" /></span>
                        <div>
                            <p class="font-semibold text-emerald-800">{{ $t('superAdmin.auto.copy133') }}</p>
                            <p class="mt-1 text-xs text-emerald-700/70">{{ $t('superAdmin.auto.copy135') }}</p>
                        </div>
                    </div>

                    <ul v-else class="divide-y divide-neutral-100 border-b border-neutral-100">
                        <li v-for="item in needsAttention" :key="item.id" class="flex flex-col gap-3 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="h-2 w-2 shrink-0 rounded-full" :class="item.severity === 'danger' ? 'bg-red-500' : 'bg-amber-500'" />
                                <Link :href="`/super-admin/tenants/${item.id}`" class="truncate text-sm font-semibold text-neutral-900 no-underline hover:text-emerald-700">{{ item.name }}</Link>
                            </div>
                            <div class="flex items-center gap-3 pl-5 sm:pl-0">
                                <span class="rounded-full border px-2 py-0.5 text-xs font-medium" :class="attentionClass(item.severity)">{{ item.reason }}</span>
                                <span v-if="item.date" class="text-xs text-neutral-400">{{ date(item.date) }}</span>
                            </div>
                        </li>
                    </ul>

                    <div class="grid gap-3 px-5 pb-5 sm:grid-cols-3" :class="needsAttention.length && 'pt-5'">
                        <div v-for="signal in signals" :key="signal.label" class="rounded-xl border border-neutral-200 p-3">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-neutral-500">{{ signal.label }}</span>
                                <span class="font-semibold" :class="signal.tone === 'ok' ? 'text-emerald-700' : 'text-amber-700'">{{ signal.value }}/{{ signal.total }}</span>
                            </div>
                            <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-neutral-100">
                                <div class="h-full rounded-full" :class="signal.tone === 'ok' ? 'bg-emerald-600' : 'bg-amber-500'" :style="{ width: `${ratio(signal.value, signal.total)}%` }" />
                            </div>
                        </div>
                    </div>
                </section>

                <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm shadow-neutral-200/30">
                    <div class="border-b border-neutral-200 px-5 py-4">
                        <h2 class="text-lg font-semibold text-neutral-900">{{ $t('superAdmin.auto.copy143') }}</h2>
                        <p class="mt-1 text-sm text-neutral-500">{{ $t('superAdmin.auto.copy138') }}</p>
                    </div>
                    <div class="flex items-center gap-5 px-5 py-5">
                        <div class="relative grid h-24 w-24 shrink-0 place-items-center rounded-full" :style="{ background: `conic-gradient(#17745c 0 ${stats.health_score}%, #edf1ef ${stats.health_score}% 100%)` }">
                            <span class="absolute inset-2 rounded-full bg-white" />
                            <span class="relative text-xl font-semibold text-neutral-900">{{ stats.health_score }}%</span>
                        </div>
                        <div>
                            <p class="font-semibold text-neutral-900">{{ healthLabel }}</p>
                            <p class="mt-1 text-xs leading-5 text-neutral-500">{{ healthDetail }}</p>
                        </div>
                    </div>
                    <div class="divide-y divide-neutral-100 border-t border-neutral-200 px-5">
                        <div class="flex items-center justify-between py-3 text-sm"><span class="text-neutral-600">{{ $t('superAdmin.auto.copy132') }}</span><span class="font-semibold" :class="stats.subscriptions_attention ? 'text-amber-700' : 'text-emerald-700'">{{ stats.subscriptions_attention ? t('superAdmin.dynamic.needAttentionCount', { count: stats.subscriptions_attention }) : t('superAdmin.auto.copy063') }}</span></div>
                        <div class="flex items-center justify-between py-3 text-sm"><span class="text-neutral-600">{{ $t('superAdmin.auto.copy012') }}</span><span class="font-semibold" :class="stats.domains_configured === stats.hotels_total ? 'text-emerald-700' : 'text-amber-700'">{{ stats.hotels_total - stats.domains_configured ? t('superAdmin.dynamic.missingCount', { count: stats.hotels_total - stats.domains_configured }) : t('superAdmin.dynamic.complete') }}</span></div>
                        <div class="flex items-center justify-between py-3 text-sm"><span class="text-neutral-600">Channex / POK</span><span class="font-semibold" :class="stats.integrations_ready === stats.integrations_total ? 'text-emerald-700' : 'text-amber-700'">{{ stats.integrations_ready === stats.integrations_total ? t('superAdmin.dynamic.configuredPlural') : t('superAdmin.dynamic.needsConfiguration') }}</span></div>
                    </div>
                </section>
            </div>

            <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm shadow-neutral-200/30">
                <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4">
                    <div>
                        <h2 class="text-lg font-semibold text-neutral-900">{{ $t('superAdmin.auto.copy137') }}</h2>
                        <p class="mt-1 text-sm text-neutral-500">{{ $t('superAdmin.auto.copy140') }}</p>
                    </div>
                    <Link href="/super-admin/tenants" class="text-sm font-semibold text-emerald-700 no-underline">{{ $t('superAdmin.auto.copy142') }} <ArrowRight class="ml-1 inline h-4 w-4" /></Link>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead><tr class="border-b border-neutral-200 bg-neutral-50/70 text-left text-[11px] uppercase tracking-wide text-neutral-400"><th class="px-5 py-3 font-semibold">{{ $t('superAdmin.auto.copy018') }}</th><th class="px-4 py-3 font-semibold">{{ $t('superAdmin.auto.copy003') }}</th><th class="px-4 py-3 font-semibold">{{ $t('superAdmin.auto.copy050') }}</th><th class="px-5 py-3 text-right font-semibold">{{ $t('superAdmin.auto.copy059') }}</th></tr></thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="tenant in recentTenants" :key="tenant.id" class="hover:bg-neutral-50/60">
                                <td class="px-5 py-3.5"><div class="flex items-center gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-emerald-50 text-xs font-bold text-emerald-700">{{ tenant.name.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase() }}</span><div class="min-w-0"><Link :href="`/super-admin/tenants/${tenant.id}`" class="truncate font-semibold text-neutral-900 no-underline hover:text-emerald-700">{{ tenant.name }}</Link><p class="mt-0.5 truncate text-xs text-neutral-400">{{ tenant.domain || tenant.slug }} · {{ date(tenant.created_at) }}</p></div></div></td>
                                <td class="px-4 py-3.5"><p class="font-semibold text-neutral-900">{{ t('superAdmin.dynamic.amountPerMonth', { amount: money(tenant.mrr_cents) }) }}</p><p class="mt-0.5 text-xs text-neutral-400">{{ t('superAdmin.dynamic.billingCycle', { cycle: tenant.billing_cycle === 'annual' ? t('superAdmin.dynamic.annualLower') : t('superAdmin.dynamic.monthlyLower') }) }}</p></td>
                                <td class="px-4 py-3.5"><p class="text-neutral-700">{{ t('superAdmin.dynamic.usersCount', { count: tenant.users_count }) }}</p><p class="mt-0.5 text-xs text-neutral-400">{{ t('superAdmin.dynamic.modulesCount', { count: tenant.modules.length }) }}</p></td>
                                <td class="px-5 py-3.5 text-right"><span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(tenant.subscription_status)"><span class="h-1.5 w-1.5 rounded-full" :class="statusDotClass(tenant.subscription_status)" />{{ statusLabel(tenant.subscription_status) }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm shadow-neutral-200/30">
                <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4">
                    <div><h2 class="text-lg font-semibold text-neutral-900">{{ $t('superAdmin.auto.copy134') }}</h2><p class="mt-1 text-sm text-neutral-500">{{ $t('superAdmin.auto.copy139') }}</p></div>
                    <Gauge class="h-5 w-5 text-neutral-400" />
                </div>
                <div class="flex flex-wrap gap-2 p-5">
                    <span v-for="module in moduleAdoption" :key="module.code" class="rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2 text-xs text-neutral-600"><strong class="mr-1 text-neutral-900">{{ module.hotels_count }}/{{ stats.hotels_total }}</strong>{{ module.name }}</span>
                </div>
            </section>
        </div>
    </SuperAdminLayout>
</template>
