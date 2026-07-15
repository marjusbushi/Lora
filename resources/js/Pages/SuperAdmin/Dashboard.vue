<script setup>
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    Building2,
    Check,
    CreditCard,
    Globe2,
    Layers3,
    ListChecks,
    LogIn,
    MoreHorizontal,
    Plug,
    Plus,
    RefreshCw,
    Search,
    Users,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const props = defineProps({
    stats: Object,
    moduleAdoption: Array,
    needsAttention: Array,
    recentTenants: Array,
    recentActivity: Array,
});

const refreshing = ref(false);
const tenantSearch = ref('');
const tenantStatus = ref('');

function ratio(value, total) {
    return total > 0 ? Math.round((value / total) * 100) : 100;
}

function money(cents) {
    return new Intl.NumberFormat('sq-AL', {
        style: 'currency',
        currency: 'EUR',
        maximumFractionDigits: 0,
    }).format((cents || 0) / 100);
}

const kpis = computed(() => [
    {
        label: t('superAdmin.auto.copy017'),
        value: props.stats.hotels_active,
        lead: `${ratio(props.stats.hotels_active, props.stats.hotels_total)}%`,
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
    },
    {
        label: t('superAdmin.dynamic.domains'),
        value: props.stats.domains_configured,
        total: props.stats.hotels_total,
    },
    {
        label: t('superAdmin.auto.copy088'),
        value: props.stats.integrations_ready,
        total: props.stats.integrations_total,
    },
]);

const filteredTenants = computed(() => {
    const query = tenantSearch.value.trim().toLocaleLowerCase('sq');
    return (props.recentTenants || []).filter((tenant) => {
        const matchesQuery = !query || [tenant.name, tenant.domain, tenant.slug]
            .filter(Boolean)
            .some((value) => value.toLocaleLowerCase('sq').includes(query));
        const matchesStatus = !tenantStatus.value || tenant.status === tenantStatus.value;
        return matchesQuery && matchesStatus;
    });
});

const platformHealthy = computed(() => props.needsAttention.length === 0);

function toneClass(tone) {
    return {
        emerald: 'bg-emerald-50 text-emerald-700',
        blue: 'bg-blue-50 text-blue-700',
        violet: 'bg-violet-50 text-violet-700',
    }[tone] || 'bg-neutral-100 text-neutral-600';
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

function statusClass(status) {
    if (status === 'past_due') return 'bg-amber-50 text-amber-700';
    if (['suspended', 'canceled', 'inactive'].includes(status)) return 'bg-red-50 text-red-700';
    return 'bg-emerald-50 text-emerald-700';
}

function statusDotClass(status) {
    if (status === 'past_due') return 'bg-amber-500';
    if (['suspended', 'canceled', 'inactive'].includes(status)) return 'bg-red-500';
    return 'bg-emerald-600';
}

function initials(name) {
    return String(name || 'L')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

const ACTIONS = {
    'tenant.create': { label: t('superAdmin.dynamic.actionHotelCreated'), icon: Plus, tone: 'blue' },
    'tenant.switch': { label: t('superAdmin.dynamic.actionHotelLogin'), icon: LogIn, tone: 'emerald' },
    'tenant.subscription.update': { label: t('superAdmin.dynamic.actionSubscriptionUpdated'), icon: CreditCard, tone: 'blue' },
    'tenant.integration.update': { label: t('superAdmin.dynamic.actionIntegrationUpdated'), icon: Plug, tone: 'violet' },
    'tenant.domain.create': { label: t('superAdmin.dynamic.actionDomainAdded'), icon: Globe2, tone: 'blue' },
    'tenant.domain.delete': { label: t('superAdmin.dynamic.actionDomainRemoved'), icon: Globe2, tone: 'red' },
    'tenant.domain.primary': { label: t('superAdmin.dynamic.actionPrimaryDomainUpdated'), icon: Globe2, tone: 'emerald' },
};

function actionMeta(action) {
    return ACTIONS[action] || { label: action, icon: ListChecks, tone: 'neutral' };
}

function activityIconClass(tone) {
    return {
        emerald: 'bg-emerald-50 text-emerald-700',
        blue: 'bg-blue-50 text-blue-700',
        violet: 'bg-violet-50 text-violet-700',
        red: 'bg-red-50 text-red-700',
        neutral: 'bg-neutral-100 text-neutral-600',
    }[tone] || 'bg-neutral-100 text-neutral-600';
}

function relativeTime(value) {
    if (!value) return '—';
    const seconds = Math.round((new Date(value).getTime() - Date.now()) / 1000);
    const absolute = Math.abs(seconds);
    if (absolute < 60) return t('superAdmin.compact.now');
    const units = absolute < 3600
        ? [Math.round(seconds / 60), 'minute']
        : absolute < 86400
            ? [Math.round(seconds / 3600), 'hour']
            : [Math.round(seconds / 86400), 'day'];
    return new Intl.RelativeTimeFormat('sq-AL', { numeric: 'auto' }).format(units[0], units[1]);
}

function refresh() {
    router.reload({
        only: ['stats', 'moduleAdoption', 'needsAttention', 'recentTenants', 'recentActivity'],
        onStart: () => { refreshing.value = true; },
        onFinish: () => { refreshing.value = false; },
    });
}
</script>

<template>
    <SuperAdminLayout :title="$t('superAdmin.auto.copy145')">
        <div class="sa-page space-y-4">
            <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <div class="sa-breadcrumb"><span>Control Panel</span><span class="mx-2">/</span><span>{{ $t('superAdmin.auto.copy120') }}</span></div>
                    <h1 class="sa-page-title">{{ $t('superAdmin.auto.copy120') }}</h1>
                    <p class="sa-page-subtitle">{{ $t('superAdmin.auto.copy136') }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="sa-button" :disabled="refreshing" @click="refresh">
                        <RefreshCw class="h-4 w-4" :class="refreshing && 'animate-spin'" />
                        {{ refreshing ? t('superAdmin.compact.refreshing') : t('superAdmin.compact.refresh') }}
                    </button>
                    <Link href="/super-admin/tenants" class="sa-button sa-button-primary">
                        {{ t('superAdmin.dynamic.manageHotels') }} <ArrowRight class="h-4 w-4" />
                    </Link>
                </div>
            </header>

            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <article v-for="kpi in kpis" :key="kpi.label" class="sa-card sa-kpi-card">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="sa-kpi-label truncate">{{ kpi.label }}</p>
                            <p class="sa-kpi-value">{{ kpi.value }}</p>
                            <p class="sa-kpi-meta">
                                <span v-if="kpi.lead !== undefined" class="font-semibold" :class="kpi.lead ? 'text-emerald-700' : 'text-neutral-500'">{{ kpi.lead }}</span>
                                <span :class="kpi.lead !== undefined && 'ml-1'">{{ kpi.detail }}</span>
                            </p>
                        </div>
                        <span class="sa-icon-box-lg" :class="toneClass(kpi.tone)">
                            <component :is="kpi.icon" class="sa-icon-lg" />
                        </span>
                    </div>
                </article>
            </section>

            <section class="sa-card flex flex-col gap-4 border-emerald-200/80 bg-emerald-50/35 p-4 xl:flex-row xl:items-center">
                <div class="flex min-w-0 flex-1 items-center gap-3">
                    <span class="sa-icon-box-lg" :class="platformHealthy ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">
                        <Check v-if="platformHealthy" class="sa-icon-lg" />
                        <AlertTriangle v-else class="sa-icon-lg" />
                    </span>
                    <div class="min-w-0">
                        <p class="font-semibold text-neutral-900">{{ platformHealthy ? t('superAdmin.compact.platformHealthy') : t('superAdmin.compact.platformAttention') }}</p>
                        <p class="mt-0.5 truncate text-xs text-neutral-500">{{ platformHealthy ? t('superAdmin.compact.platformHealthyDetail') : t('superAdmin.compact.issuesCount', { count: needsAttention.length }) }}</p>
                    </div>
                </div>
                <div class="grid flex-[1.55] gap-3 sm:grid-cols-3">
                    <div v-for="signal in signals" :key="signal.label" class="border-l border-neutral-200 pl-4">
                        <div class="flex items-center justify-between text-xs"><span class="text-neutral-500">{{ signal.label }}</span><strong class="text-neutral-900">{{ signal.value }}/{{ signal.total }}</strong></div>
                        <div class="mt-2 h-1 overflow-hidden rounded-full bg-neutral-200/80"><div class="h-full rounded-full" :class="signal.value === signal.total ? 'bg-emerald-700' : 'bg-amber-500'" :style="{ width: `${ratio(signal.value, signal.total)}%` }" /></div>
                    </div>
                </div>
            </section>

            <div class="grid gap-4 xl:grid-cols-[minmax(0,1.7fr)_minmax(320px,0.65fr)]">
                <section class="sa-card">
                    <div class="sa-card-header flex-col items-stretch lg:flex-row lg:items-center">
                        <div>
                            <h2 class="sa-card-title">{{ t('superAdmin.compact.hotels') }}</h2>
                            <p class="sa-card-subtitle">{{ t('superAdmin.compact.portfolioDescription') }}</p>
                        </div>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <label class="relative block sm:w-[210px]">
                                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                                <input v-model="tenantSearch" type="search" class="sa-control w-full py-0 pl-9 pr-3" :placeholder="t('superAdmin.compact.searchHotel')" />
                            </label>
                            <select v-model="tenantStatus" class="sa-control min-w-[120px] py-0 pl-3 pr-8">
                                <option value="">{{ t('superAdmin.compact.all') }}</option>
                                <option value="active">{{ t('superAdmin.compact.active') }}</option>
                                <option value="suspended">{{ t('superAdmin.compact.suspended') }}</option>
                            </select>
                            <Link href="/super-admin/tenants?create=1" class="sa-button sa-button-primary whitespace-nowrap"><Plus class="h-4 w-4" /> {{ t('superAdmin.compact.addHotel') }}</Link>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[720px] text-sm">
                            <thead><tr class="sa-table-head text-left"><th class="px-[18px] font-semibold">{{ t('superAdmin.compact.hotel') }}</th><th class="px-4 font-semibold">{{ t('superAdmin.compact.subscription') }}</th><th class="px-4 font-semibold">{{ t('superAdmin.compact.usage') }}</th><th class="px-4 font-semibold">{{ t('superAdmin.compact.status') }}</th><th class="w-12" /></tr></thead>
                            <tbody class="divide-y divide-neutral-100">
                                <tr v-for="tenant in filteredTenants" :key="tenant.id" class="h-[58px] hover:bg-neutral-50/60">
                                    <td class="px-[18px] py-2"><div class="flex items-center gap-3"><span class="sa-icon-box bg-emerald-50 text-[11px] font-bold text-emerald-800">{{ initials(tenant.name) }}</span><div class="min-w-0"><Link :href="`/super-admin/tenants/${tenant.id}`" class="sa-table-primary block truncate no-underline hover:text-emerald-700">{{ tenant.name }}</Link><p class="sa-table-meta max-w-[260px] truncate">{{ tenant.domain || tenant.slug }}</p></div></div></td>
                                    <td class="px-4 py-2"><p class="text-xs font-semibold text-neutral-900">{{ t('superAdmin.dynamic.amountPerMonth', { amount: money(tenant.mrr_cents) }) }}</p><p class="mt-0.5 text-[11px] text-neutral-400">{{ tenant.billing_cycle === 'annual' ? t('superAdmin.compact.annualBilling') : t('superAdmin.compact.monthlyBilling') }}</p></td>
                                    <td class="px-4 py-2"><p class="text-xs font-semibold text-neutral-800">{{ t('superAdmin.dynamic.usersCount', { count: tenant.users_count }) }}</p><p class="mt-0.5 text-[11px] text-neutral-400">{{ t('superAdmin.dynamic.modulesCount', { count: tenant.modules.length }) }}</p></td>
                                    <td class="px-4 py-2"><span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold" :class="statusClass(tenant.subscription_status)"><span class="h-1.5 w-1.5 rounded-full" :class="statusDotClass(tenant.subscription_status)" />{{ statusLabel(tenant.subscription_status) }}</span></td>
                                    <td class="px-3 text-right"><Link :href="`/super-admin/tenants/${tenant.id}`" class="inline-grid h-8 w-8 place-items-center rounded-lg text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" :aria-label="t('superAdmin.compact.openHotel')"><MoreHorizontal class="h-4 w-4" /></Link></td>
                                </tr>
                                <tr v-if="!filteredTenants.length"><td colspan="5" class="px-5 py-12 text-center text-sm text-neutral-500">{{ t('superAdmin.compact.noHotels') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <div class="space-y-4">
                    <section class="sa-card">
                        <div class="sa-card-header">
                            <div><h2 class="sa-card-title">{{ t('superAdmin.compact.recentActivity') }}</h2><p class="sa-card-subtitle">{{ t('superAdmin.compact.importantActions') }}</p></div>
                            <Link href="/super-admin/activity" class="text-xs font-semibold text-emerald-700 no-underline hover:text-emerald-900">{{ t('superAdmin.compact.viewAll') }} <ArrowRight class="ml-0.5 inline h-3.5 w-3.5" /></Link>
                        </div>
                        <div v-if="recentActivity?.length" class="divide-y divide-neutral-100 px-4">
                            <div v-for="log in recentActivity" :key="log.id" class="flex items-start gap-3 py-3">
                                <span class="sa-icon-box" :class="activityIconClass(actionMeta(log.action).tone)"><component :is="actionMeta(log.action).icon" class="sa-icon" /></span>
                                <div class="min-w-0 flex-1"><p class="truncate text-xs font-semibold text-neutral-900">{{ actionMeta(log.action).label }}<template v-if="log.tenant"> · {{ log.tenant }}</template></p><p class="mt-1 truncate text-[11px] text-neutral-400">{{ log.summary || log.actor }}</p></div>
                                <time class="shrink-0 pt-0.5 text-[10px] text-neutral-400">{{ relativeTime(log.created_at) }}</time>
                            </div>
                        </div>
                        <div v-else class="px-5 py-8 text-center text-xs text-neutral-500">{{ t('superAdmin.compact.noRecentActivity') }}</div>
                    </section>

                    <section class="sa-card">
                        <div class="sa-card-header">
                            <div><h2 class="sa-card-title">{{ t('superAdmin.compact.attention') }}</h2><p class="sa-card-subtitle">{{ t('superAdmin.compact.nonUrgentActions') }}</p></div>
                            <span class="rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-700">{{ t('superAdmin.compact.points', { count: needsAttention.length }) }}</span>
                        </div>
                        <div v-if="needsAttention.length" class="space-y-2 p-4">
                            <Link v-for="item in needsAttention.slice(0, 3)" :key="item.id" :href="`/super-admin/tenants/${item.id}`" class="block rounded-[10px] border border-amber-100 bg-amber-50/70 p-3 no-underline hover:bg-amber-50"><p class="text-xs font-semibold text-amber-900">{{ item.name }}</p><p class="mt-1 text-[11px] text-amber-700">{{ item.reason }}</p></Link>
                        </div>
                        <div v-else class="flex items-center gap-3 p-4"><span class="grid h-9 w-9 place-items-center rounded-[10px] bg-emerald-50 text-emerald-700"><Check class="h-4 w-4" /></span><div><p class="text-xs font-semibold text-neutral-900">{{ t('superAdmin.compact.allGood') }}</p><p class="mt-0.5 text-[11px] text-neutral-500">{{ t('superAdmin.compact.noIssues') }}</p></div></div>
                    </section>
                </div>
            </div>

            <section v-if="moduleAdoption?.length" class="sa-card">
                <div class="sa-card-header"><div><h2 class="sa-card-title">{{ t('superAdmin.compact.moduleUsage') }}</h2><p class="sa-card-subtitle">{{ t('superAdmin.compact.moduleUsageDescription') }}</p></div></div>
                <div class="flex flex-wrap gap-2 p-4"><span v-for="module in moduleAdoption" :key="module.code" class="rounded-[9px] border border-neutral-200 bg-neutral-50 px-3 py-2 text-[11px] text-neutral-600"><strong class="mr-1 text-neutral-900">{{ module.hotels_count }}/{{ stats.hotels_total }}</strong>{{ module.name }}</span></div>
            </section>
        </div>
    </SuperAdminLayout>
</template>
