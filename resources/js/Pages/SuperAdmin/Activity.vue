<script setup>
import { useI18n } from 'vue-i18n';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import {
    Building2,
    CreditCard,
    Download,
    Globe2,
    ListChecks,
    LogIn,
    Plug,
    Plus,
    Power,
    Search,
    ShieldCheck,
    UserRound,
    X,
} from 'lucide-vue-next';

const { t } = useI18n();
const props = defineProps({
    logs: Object,
    actions: Array,
    hotels: Array,
    stats: Object,
    filter: Object,
});

const search = ref(props.filter?.q || '');
const actionFilter = ref(props.filter?.action || '');
const hotelFilter = ref(props.filter?.tenant ? String(props.filter.tenant) : '');
const rangeFilter = ref(props.filter?.range || '7');
const selectedLog = ref(null);
let searchTimer;

const rows = computed(() => props.logs?.data || []);

const groupedRows = computed(() => {
    const groups = new Map();
    rows.value.forEach((log) => {
        const key = new Date(log.created_at).toDateString();
        if (!groups.has(key)) groups.set(key, { key, label: dayLabel(log.created_at), rows: [] });
        groups.get(key).rows.push(log);
    });
    return Array.from(groups.values());
});

const ACTIONS = {
    'tenant.create': { label: t('superAdmin.dynamic.actionHotelCreated'), icon: Plus, tone: 'blue' },
    'tenant.switch': { label: t('superAdmin.dynamic.actionHotelLogin'), icon: LogIn, tone: 'neutral' },
    'tenant.subscription.update': { label: t('superAdmin.dynamic.actionSubscriptionUpdated'), icon: CreditCard, tone: 'emerald' },
    'tenant.integration.update': { label: t('superAdmin.dynamic.actionIntegrationUpdated'), icon: Plug, tone: 'violet' },
    'tenant.domain.create': { label: t('superAdmin.dynamic.actionDomainAdded'), icon: Globe2, tone: 'blue' },
    'tenant.domain.delete': { label: t('superAdmin.dynamic.actionDomainRemoved'), icon: Globe2, tone: 'red' },
    'tenant.domain.primary': { label: t('superAdmin.dynamic.actionPrimaryDomainUpdated'), icon: Globe2, tone: 'blue' },
    'tenant.status': { label: t('superAdmin.dynamic.actionHotelStatus'), icon: Power, tone: 'amber' },
};

function actionMeta(action) {
    return ACTIONS[action] || { label: action, icon: ListChecks, tone: 'neutral' };
}

function iconClass(tone) {
    return {
        emerald: 'bg-emerald-50 text-emerald-700',
        blue: 'bg-blue-50 text-blue-700',
        violet: 'bg-violet-50 text-violet-700',
        amber: 'bg-amber-50 text-amber-700',
        red: 'bg-red-50 text-red-700',
        neutral: 'bg-neutral-100 text-neutral-600',
    }[tone] || 'bg-neutral-100 text-neutral-600';
}

function dayLabel(value) {
    const valueDate = new Date(value);
    const today = new Date();
    const startToday = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    const startValue = new Date(valueDate.getFullYear(), valueDate.getMonth(), valueDate.getDate());
    const diff = Math.round((startToday - startValue) / 86400000);
    const locale = document.documentElement.lang === 'en' ? 'en-GB' : 'sq-AL';
    if (diff === 0) return `${t('superAdmin.dynamic.today')} · ${new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long' }).format(valueDate)}`;
    if (diff === 1) return t('superAdmin.dynamic.yesterday');
    return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long', year: 'numeric' }).format(valueDate);
}

function time(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('sq-AL', { hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}

function fullDate(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('sq-AL', {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    }).format(new Date(value));
}

function applyFilters() {
    const params = {
        q: search.value.trim() || undefined,
        action: actionFilter.value || undefined,
        tenant: hotelFilter.value || undefined,
        range: rangeFilter.value || undefined,
    };
    router.get(route('super-admin.activity'), params, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 350);
});

onBeforeUnmount(() => clearTimeout(searchTimer));

function go(url) {
    if (url) router.get(url, {}, { preserveScroll: true, preserveState: true });
}

function exportRows() {
    const header = [t('superAdmin.dynamic.date'), t('superAdmin.auto.copy128'), t('superAdmin.auto.copy018'), t('superAdmin.dynamic.summary'), t('superAdmin.auto.copy105'), 'Email', 'IP'];
    const data = rows.value.map((log) => [
        fullDate(log.created_at),
        actionMeta(log.action).label,
        log.tenant || '',
        log.summary || '',
        log.actor || '',
        log.actor_email || '',
        log.ip_address || '',
    ]);
    const csv = [header, ...data]
        .map((row) => row.map((value) => `"${String(value ?? '').replaceAll('"', '""')}"`).join(','))
        .join('\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8' }));
    link.download = `lora-aktiviteti-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
}
</script>

<template>
    <Head :title="$t('superAdmin.auto.copy129')" />

    <SuperAdminLayout :title="$t('superAdmin.auto.copy129')">
        <div class="mx-auto max-w-[1480px] space-y-5">
            <div>
                <div class="text-xs text-neutral-400"><span>Control Panel</span><span class="mx-2">/</span><span>{{ $t('superAdmin.auto.copy107') }}</span></div>
                <div class="mt-3 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 class="text-3xl font-semibold tracking-tight text-neutral-950">{{ $t('superAdmin.auto.copy108') }}</h1>
                        <p class="mt-2 text-sm text-neutral-500">{{ $t('superAdmin.auto.copy109') }}</p>
                    </div>
                    <button type="button" class="inline-flex items-center justify-center gap-2 rounded-xl border border-neutral-200 bg-white px-4 py-2.5 text-sm font-semibold text-neutral-700 shadow-sm hover:bg-neutral-50 disabled:opacity-50" :disabled="!rows.length" @click="exportRows">
                        <Download class="h-4 w-4" /> {{ t('superAdmin.dynamic.exportCsv') }}
                    </button>
                </div>
            </div>

            <section class="grid gap-4 sm:grid-cols-3">
                <article class="flex items-center gap-3 rounded-2xl border border-neutral-200 bg-white p-4 shadow-sm shadow-neutral-200/30">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-700"><ListChecks class="h-5 w-5" /></span>
                    <div><p class="text-2xl font-semibold text-neutral-900">{{ stats.actions_24h }}</p><p class="text-xs text-neutral-500">{{ $t('superAdmin.auto.copy126') }}</p></div>
                </article>
                <article class="flex items-center gap-3 rounded-2xl border border-neutral-200 bg-white p-4 shadow-sm shadow-neutral-200/30">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-700"><Building2 class="h-5 w-5" /></span>
                    <div><p class="text-2xl font-semibold text-neutral-900">{{ stats.hotels_24h }}</p><p class="text-xs text-neutral-500">{{ $t('superAdmin.auto.copy113') }}</p></div>
                </article>
                <article class="flex items-center gap-3 rounded-2xl border border-neutral-200 bg-white p-4 shadow-sm shadow-neutral-200/30">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-violet-50 text-violet-700"><UserRound class="h-5 w-5" /></span>
                    <div><p class="text-2xl font-semibold text-neutral-900">{{ stats.admins_24h }}</p><p class="text-xs text-neutral-500">{{ $t('superAdmin.auto.copy106') }}</p></div>
                </article>
            </section>

            <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm shadow-neutral-200/30">
                <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4">
                    <div><h2 class="text-lg font-semibold text-neutral-900">{{ $t('superAdmin.auto.copy112') }}</h2><p class="mt-1 text-sm text-neutral-500">{{ $t('superAdmin.auto.copy127') }}</p></div>
                    <span class="rounded-full bg-neutral-100 px-2.5 py-1 text-xs font-semibold text-neutral-600">{{ t('superAdmin.dynamic.resultsCount', { count: logs.total }) }}</span>
                </div>

                <div class="grid gap-3 border-b border-neutral-200 px-5 py-4 lg:grid-cols-[minmax(260px,1fr)_220px_200px_170px]">
                    <label class="relative block">
                        <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                        <input v-model="search" type="search" class="w-full rounded-xl border-neutral-300 py-2.5 pl-10 pr-3 text-sm" :placeholder="$t('superAdmin.auto.copy130')" />
                    </label>
                    <select v-model="actionFilter" class="rounded-xl border-neutral-300 py-2.5 text-sm text-neutral-700" @change="applyFilters">
                        <option value="">{{ $t('superAdmin.auto.copy124') }}</option>
                        <option v-for="action in actions" :key="action" :value="action">{{ actionMeta(action).label }}</option>
                    </select>
                    <select v-model="hotelFilter" class="rounded-xl border-neutral-300 py-2.5 text-sm text-neutral-700" @change="applyFilters">
                        <option value="">{{ $t('superAdmin.auto.copy123') }}</option>
                        <option v-for="hotel in hotels" :key="hotel.id" :value="String(hotel.id)">{{ hotel.name }}</option>
                    </select>
                    <select v-model="rangeFilter" class="rounded-xl border-neutral-300 py-2.5 text-sm text-neutral-700" @change="applyFilters">
                        <option value="today">{{ $t('superAdmin.auto.copy122') }}</option>
                        <option value="7">{{ t('superAdmin.dynamic.last7Days') }}</option>
                        <option value="30">{{ t('superAdmin.dynamic.last30Days') }}</option>
                    </select>
                </div>

                <div v-if="rows.length" class="px-5 pb-4">
                    <section v-for="group in groupedRows" :key="group.key">
                        <div class="flex items-center gap-3 py-4 text-[11px] font-semibold uppercase tracking-[0.1em] text-neutral-500">
                            <span>{{ group.label }}</span><span class="h-px flex-1 bg-neutral-200" />
                        </div>
                        <button v-for="log in group.rows" :key="log.id" type="button" class="grid w-full grid-cols-[40px_minmax(0,1fr)] gap-3 rounded-xl border border-transparent p-3 text-left transition hover:border-neutral-200 hover:bg-neutral-50 sm:grid-cols-[40px_minmax(0,1fr)_auto]" @click="selectedLog = log">
                            <span class="grid h-10 w-10 place-items-center rounded-xl" :class="iconClass(actionMeta(log.action).tone)"><component :is="actionMeta(log.action).icon" class="h-4 w-4" :stroke-width="2" /></span>
                            <span class="min-w-0">
                                <span class="flex flex-wrap items-center gap-x-2 gap-y-1"><strong class="text-sm text-neutral-900">{{ actionMeta(log.action).label }}</strong><strong v-if="log.tenant" class="text-sm text-emerald-700">{{ log.tenant }}</strong></span>
                                <span v-if="log.summary" class="mt-1 block text-sm text-neutral-600">{{ log.summary }}</span>
                                <span class="mt-1 block truncate text-xs text-neutral-400">{{ log.actor }}<template v-if="log.actor_email"> · {{ log.actor_email }}</template><template v-if="log.ip_address"> · {{ log.ip_address }}</template></span>
                            </span>
                            <time class="col-start-2 text-xs text-neutral-400 sm:col-start-3 sm:row-start-1 sm:pt-1">{{ time(log.created_at) }}</time>
                        </button>
                    </section>
                </div>

                <div v-else class="flex flex-col items-center gap-2 px-5 py-16 text-center">
                    <span class="grid h-12 w-12 place-items-center rounded-2xl bg-neutral-100 text-neutral-400"><ListChecks class="h-6 w-6" /></span>
                    <p class="text-sm font-medium text-neutral-700">{{ $t('superAdmin.auto.copy116') }}</p>
                    <p class="text-xs text-neutral-500">{{ $t('superAdmin.auto.copy039') }}</p>
                </div>

                <div v-if="logs.last_page > 1" class="flex items-center justify-between border-t border-neutral-200 px-5 py-3 text-sm">
                    <button class="rounded-lg border border-neutral-200 px-3 py-1.5 text-neutral-600 disabled:opacity-40" :disabled="!logs.prev_page_url" @click="go(logs.prev_page_url)">← {{ t('superAdmin.dynamic.previous') }}</button>
                    <span class="text-xs text-neutral-500">{{ t('superAdmin.dynamic.pagination', { page: logs.current_page, pages: logs.last_page, count: logs.total }) }}</span>
                    <button class="rounded-lg border border-neutral-200 px-3 py-1.5 text-neutral-600 disabled:opacity-40" :disabled="!logs.next_page_url" @click="go(logs.next_page_url)">{{ $t('superAdmin.auto.copy117') }}</button>
                </div>
            </section>
        </div>

        <Teleport to="body">
            <div v-if="selectedLog" class="fixed inset-0 z-50 bg-neutral-950/40" @click.self="selectedLog = null">
                <aside role="dialog" aria-modal="true" class="ml-auto flex h-full w-full max-w-lg flex-col bg-white shadow-2xl">
                    <div class="flex items-start justify-between border-b border-neutral-200 px-6 py-5">
                        <div><h2 class="text-lg font-semibold text-neutral-900">{{ $t('superAdmin.auto.copy111') }}</h2><p class="mt-1 text-sm text-neutral-500">{{ t('superAdmin.dynamic.auditLogNumber', { id: selectedLog.id }) }}</p></div>
                        <button type="button" class="rounded-xl p-2 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" :aria-label="$t('superAdmin.auto.copy131')" @click="selectedLog = null"><X class="h-5 w-5" /></button>
                    </div>

                    <div class="flex-1 space-y-6 overflow-y-auto p-6">
                        <div class="flex items-center gap-3 rounded-xl border border-neutral-200 p-4">
                            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl" :class="iconClass(actionMeta(selectedLog.action).tone)"><component :is="actionMeta(selectedLog.action).icon" class="h-5 w-5" /></span>
                            <div class="min-w-0"><p class="font-semibold text-neutral-900">{{ actionMeta(selectedLog.action).label }}</p><p class="mt-1 truncate text-sm text-neutral-500">{{ selectedLog.tenant || 'Platforma' }}</p></div>
                        </div>

                        <section><h3 class="text-xs font-semibold uppercase tracking-[0.1em] text-neutral-400">{{ $t('superAdmin.auto.copy115') }}</h3><div class="mt-3 divide-y divide-neutral-100 rounded-xl border border-neutral-200"><div class="flex justify-between gap-4 px-4 py-3 text-sm"><span class="text-neutral-500">{{ $t('superAdmin.auto.copy105') }}</span><strong class="text-right text-neutral-900">{{ selectedLog.actor }}</strong></div><div class="flex justify-between gap-4 px-4 py-3 text-sm"><span class="text-neutral-500">Email</span><strong class="break-all text-right text-neutral-900">{{ selectedLog.actor_email || '—' }}</strong></div><div class="flex justify-between gap-4 px-4 py-3 text-sm"><span class="text-neutral-500">{{ $t('superAdmin.auto.copy114') }}</span><strong class="text-right text-neutral-900">{{ fullDate(selectedLog.created_at) }}</strong></div><div class="flex justify-between gap-4 px-4 py-3 text-sm"><span class="text-neutral-500">IP</span><strong class="text-right text-neutral-900">{{ selectedLog.ip_address || '—' }}</strong></div></div></section>

                        <section><h3 class="text-xs font-semibold uppercase tracking-[0.1em] text-neutral-400">{{ $t('superAdmin.auto.copy128') }}</h3><div class="mt-3 divide-y divide-neutral-100 rounded-xl border border-neutral-200"><div class="flex justify-between gap-4 px-4 py-3 text-sm"><span class="text-neutral-500">{{ $t('superAdmin.auto.copy118') }}</span><strong class="text-right text-neutral-900">{{ selectedLog.tenant || 'Platforma' }}</strong></div><div class="flex justify-between gap-4 px-4 py-3 text-sm"><span class="text-neutral-500">{{ $t('superAdmin.auto.copy121') }}</span><strong class="text-right text-emerald-700">{{ $t('superAdmin.auto.copy125') }}</strong></div><div class="flex justify-between gap-4 px-4 py-3 text-sm"><span class="text-neutral-500">{{ $t('superAdmin.auto.copy120') }}</span><strong class="max-w-[280px] text-right text-neutral-900">{{ selectedLog.summary || actionMeta(selectedLog.action).label }}</strong></div></div></section>

                        <div class="flex items-start gap-3 rounded-xl bg-blue-50 px-4 py-3 text-blue-700"><ShieldCheck class="mt-0.5 h-5 w-5 shrink-0" /><div><p class="text-sm font-semibold">{{ $t('superAdmin.auto.copy119') }}</p><p class="mt-1 text-xs leading-5 text-blue-600">{{ $t('superAdmin.auto.copy110') }}</p></div></div>
                    </div>

                    <div class="flex justify-end border-t border-neutral-200 bg-neutral-50/70 px-6 py-4">
                        <button type="button" class="inline-flex items-center gap-2 rounded-xl border border-neutral-200 bg-white px-4 py-2 text-sm font-semibold text-neutral-700 hover:bg-neutral-50" @click="selectedLog = null">{{ $t('superAdmin.auto.copy028') }}</button>
                    </div>
                </aside>
            </div>
        </Teleport>
    </SuperAdminLayout>
</template>
