<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import {
    Building2,
    ChevronRight,
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
    UserPlus,
    X,
} from 'lucide-vue-next';

const { t } = useI18n();
const props = defineProps({
    logs: Object,
    actions: Array,
    hotels: Array,
    stats: Object,
    categoryCounts: Object,
    filter: Object,
});

const search = ref(props.filter?.q || '');
const actionFilter = ref(props.filter?.action || '');
const categoryFilter = ref(props.filter?.category || '');
const hotelFilter = ref(props.filter?.tenant ? String(props.filter.tenant) : '');
const rangeFilter = ref(props.filter?.range || '7');
const selectedLog = ref(null);
let searchTimer;

const rows = computed(() => props.logs?.data || []);
const hasFilters = computed(() => Boolean(
    search.value.trim()
    || actionFilter.value
    || categoryFilter.value
    || hotelFilter.value
    || rangeFilter.value !== '7',
));

const groupedRows = computed(() => {
    const groups = new Map();
    rows.value.forEach((log) => {
        const key = new Date(log.created_at).toDateString();
        if (!groups.has(key)) groups.set(key, { key, label: dayLabel(log.created_at), rows: [] });
        groups.get(key).rows.push(log);
    });
    return Array.from(groups.values());
});

const categoryOptions = computed(() => [
    { value: '', label: t('superAdmin.activity.all'), count: props.stats?.actions_range || 0 },
    { value: 'login', label: t('superAdmin.activity.login'), count: props.categoryCounts?.login || 0 },
    { value: 'integrations', label: t('superAdmin.activity.integrations'), count: props.categoryCounts?.integrations || 0 },
    { value: 'subscriptions', label: t('superAdmin.activity.subscriptions'), count: props.categoryCounts?.subscriptions || 0 },
    { value: 'domains', label: t('superAdmin.activity.domains'), count: props.categoryCounts?.domains || 0 },
]);

const rangeLabel = computed(() => ({
    today: t('superAdmin.dynamic.today'),
    7: t('superAdmin.dynamic.last7Days'),
    30: t('superAdmin.dynamic.last30Days'),
}[rangeFilter.value] || t('superAdmin.dynamic.last7Days')));

const ACTIONS = {
    'tenant.create': { label: t('superAdmin.dynamic.actionHotelCreated'), icon: Plus, tone: 'blue' },
    'tenant.update': { label: t('superAdmin.activity.actionHotelUpdated'), icon: Building2, tone: 'emerald' },
    'tenant.switch': { label: t('superAdmin.dynamic.actionHotelLogin'), icon: LogIn, tone: 'neutral' },
    'tenant.member.create': { label: t('superAdmin.activity.actionMemberCreated'), icon: UserPlus, tone: 'blue' },
    'tenant.member.update': { label: t('superAdmin.activity.actionMemberUpdated'), icon: UserPlus, tone: 'blue' },
    'tenant.subscription.update': { label: t('superAdmin.dynamic.actionSubscriptionUpdated'), icon: CreditCard, tone: 'emerald' },
    'tenant.integration.update': { label: t('superAdmin.dynamic.actionIntegrationUpdated'), icon: Plug, tone: 'violet' },
    'tenant.integration.test': { label: t('superAdmin.activity.actionIntegrationTested'), icon: Plug, tone: 'violet' },
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

function initials(name) {
    return String(name || 'LP')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

function dayLabel(value) {
    const valueDate = new Date(value);
    const today = new Date();
    const startToday = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    const startValue = new Date(valueDate.getFullYear(), valueDate.getMonth(), valueDate.getDate());
    const diff = Math.round((startToday - startValue) / 86400000);
    const locale = document.documentElement.lang === 'en' ? 'en-GB' : 'sq-AL';
    const formatted = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long' }).format(valueDate);
    if (diff === 0) return `${t('superAdmin.dynamic.today')} · ${formatted}`;
    if (diff === 1) return `${t('superAdmin.dynamic.yesterday')} · ${formatted}`;
    return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long', year: 'numeric' }).format(valueDate);
}

function time(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('sq-AL', { hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}

function shortDay(value) {
    if (!value) return '—';
    const valueDate = new Date(value);
    const today = new Date();
    const startToday = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    const startValue = new Date(valueDate.getFullYear(), valueDate.getMonth(), valueDate.getDate());
    const diff = Math.round((startToday - startValue) / 86400000);
    if (diff === 0) return t('superAdmin.dynamic.today').toLowerCase();
    if (diff === 1) return t('superAdmin.dynamic.yesterday').toLowerCase();
    return new Intl.DateTimeFormat('sq-AL', { day: '2-digit', month: '2-digit' }).format(valueDate);
}

function fullDate(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('sq-AL', {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    }).format(new Date(value));
}

function applyFilters() {
    router.get(route('super-admin.activity'), {
        q: search.value.trim() || undefined,
        action: actionFilter.value || undefined,
        category: categoryFilter.value || undefined,
        tenant: hotelFilter.value || undefined,
        range: rangeFilter.value || undefined,
    }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function selectCategory(value) {
    categoryFilter.value = value;
    actionFilter.value = '';
    applyFilters();
}

function selectAction() {
    categoryFilter.value = '';
    applyFilters();
}

function resetFilters() {
    clearTimeout(searchTimer);
    search.value = '';
    actionFilter.value = '';
    categoryFilter.value = '';
    hotelFilter.value = '';
    rangeFilter.value = '7';
    applyFilters();
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 350);
});

watch(selectedLog, (log) => {
    if (typeof document !== 'undefined') document.body.style.overflow = log ? 'hidden' : '';
});

function handleEscape(event) {
    if (event.key === 'Escape') selectedLog.value = null;
}

if (typeof document !== 'undefined') document.addEventListener('keydown', handleEscape);

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    if (typeof document !== 'undefined') {
        document.removeEventListener('keydown', handleEscape);
        document.body.style.overflow = '';
    }
});

function go(url) {
    if (url) router.get(url, {}, { preserveScroll: true, preserveState: true });
}

function exportRows() {
    const header = [t('superAdmin.activity.date'), t('superAdmin.activity.action'), t('superAdmin.activity.hotel'), t('superAdmin.dynamic.summary'), t('superAdmin.activity.administrator'), 'Email', 'IP'];
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
    <Head :title="t('superAdmin.activity.title')" />

    <SuperAdminLayout :title="t('superAdmin.activity.title')">
        <div class="sa-page space-y-4">
            <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <div class="text-[11px] text-neutral-400"><span>Control Panel</span><span class="mx-2">/</span><span>{{ t('superAdmin.auto.copy107') }}</span></div>
                    <h1 class="mt-2 text-[27px] font-semibold leading-tight tracking-[-0.035em] text-neutral-950">{{ t('superAdmin.activity.title') }}</h1>
                    <p class="mt-1 text-[13px] text-neutral-500">{{ t('superAdmin.activity.subtitle') }}</p>
                </div>
                <button type="button" class="sa-button self-start sm:self-auto" :disabled="!rows.length" @click="exportRows">
                    <Download class="h-4 w-4" :stroke-width="1.8" /> {{ t('superAdmin.dynamic.exportCsv') }}
                </button>
            </header>

            <section class="sa-card grid md:grid-cols-[1.35fr_1fr_1fr_1fr]">
                <div class="flex items-center gap-3 border-b border-[var(--sa-line)] px-4 py-4 md:border-b-0 md:border-r">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-[11px] bg-emerald-50 text-emerald-700"><ShieldCheck class="h-5 w-5" :stroke-width="1.8" /></span>
                    <div class="min-w-0"><p class="text-sm font-semibold text-neutral-900">{{ t('superAdmin.activity.traceable') }}</p><p class="mt-0.5 text-[11px] leading-4 text-neutral-500">{{ t('superAdmin.activity.immutableSummary') }}</p></div>
                </div>
                <div class="border-b border-[var(--sa-line)] px-4 py-4 md:border-b-0 md:border-r">
                    <p class="text-[9px] font-bold uppercase tracking-[0.13em] text-neutral-400">{{ t('superAdmin.activity.actionsRange', { range: rangeLabel }) }}</p>
                    <p class="mt-1.5 text-xl font-semibold text-neutral-950">{{ stats.actions_range }}</p>
                    <p class="mt-1 text-[10px] text-neutral-500">{{ t('superAdmin.activity.last24Hours', { count: stats.actions_24h }) }}</p>
                </div>
                <div class="border-b border-[var(--sa-line)] px-4 py-4 md:border-b-0 md:border-r">
                    <p class="text-[9px] font-bold uppercase tracking-[0.13em] text-neutral-400">{{ t('superAdmin.activity.hotelsAffected') }}</p>
                    <p class="mt-1.5 text-xl font-semibold text-neutral-950">{{ stats.hotels_range }}</p>
                    <p class="mt-1 text-[10px] text-neutral-500">{{ t('superAdmin.activity.hotelsOfTotal', { count: stats.hotels_total }) }}</p>
                </div>
                <div class="px-4 py-4">
                    <p class="text-[9px] font-bold uppercase tracking-[0.13em] text-neutral-400">{{ t('superAdmin.activity.administrators') }}</p>
                    <p class="mt-1.5 text-xl font-semibold text-neutral-950">{{ stats.admins_range }}</p>
                    <p class="mt-1 text-[10px] text-neutral-500">{{ t('superAdmin.activity.activeInRange') }}</p>
                </div>
            </section>

            <section class="sa-card">
                <div class="sa-card-header">
                    <div><h2 class="text-base font-semibold text-neutral-900">{{ t('superAdmin.activity.trail') }}</h2><p class="mt-0.5 text-xs text-neutral-500">{{ t('superAdmin.activity.trailHint') }}</p></div>
                    <span class="rounded-full bg-neutral-100 px-2.5 py-1 text-[10px] font-semibold text-neutral-600">{{ t('superAdmin.activity.inView', { count: logs.total }) }}</span>
                </div>

                <div class="grid gap-2.5 border-b border-[var(--sa-line)] p-4 xl:grid-cols-[minmax(260px,1fr)_190px_190px_160px_auto]">
                    <label class="relative block">
                        <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" :stroke-width="1.8" />
                        <input v-model="search" type="search" class="sa-control w-full py-0 pl-9 pr-3" :placeholder="t('superAdmin.activity.search')" />
                    </label>
                    <select v-model="actionFilter" class="sa-control w-full px-3 py-0" @change="selectAction">
                        <option value="">{{ t('superAdmin.activity.allActions') }}</option>
                        <option v-for="action in actions" :key="action" :value="action">{{ actionMeta(action).label }}</option>
                    </select>
                    <select v-model="hotelFilter" class="sa-control w-full px-3 py-0" @change="applyFilters">
                        <option value="">{{ t('superAdmin.activity.allHotels') }}</option>
                        <option v-for="hotel in hotels" :key="hotel.id" :value="String(hotel.id)">{{ hotel.name }}</option>
                    </select>
                    <select v-model="rangeFilter" class="sa-control w-full px-3 py-0" @change="applyFilters">
                        <option value="today">{{ t('superAdmin.dynamic.today') }}</option>
                        <option value="7">{{ t('superAdmin.dynamic.last7Days') }}</option>
                        <option value="30">{{ t('superAdmin.dynamic.last30Days') }}</option>
                    </select>
                    <button type="button" class="sa-button" :disabled="!hasFilters" @click="resetFilters">{{ t('superAdmin.activity.clear') }}</button>
                </div>

                <div class="flex gap-2 overflow-x-auto border-b border-[var(--sa-line)] px-4 py-2.5">
                    <button
                        v-for="category in categoryOptions"
                        :key="category.value || 'all'"
                        type="button"
                        class="inline-flex h-7 shrink-0 items-center gap-1.5 rounded-full border px-3 text-[10px] font-semibold transition"
                        :class="categoryFilter === category.value && !actionFilter ? 'border-emerald-800 bg-emerald-800 text-white' : 'border-neutral-200 bg-white text-neutral-600 hover:border-emerald-200 hover:text-emerald-800'"
                        @click="selectCategory(category.value)"
                    >
                        {{ category.label }} <span :class="categoryFilter === category.value && !actionFilter ? 'text-emerald-100' : 'text-neutral-400'">{{ category.count }}</span>
                    </button>
                </div>

                <div v-if="rows.length" class="overflow-hidden">
                    <table class="w-full table-fixed border-collapse">
                        <thead>
                            <tr class="h-10 border-b border-[var(--sa-line)] bg-neutral-50/60 text-left text-[9px] uppercase tracking-[0.12em] text-neutral-400">
                                <th class="px-4 font-semibold">{{ t('superAdmin.activity.action') }}</th>
                                <th class="hidden w-[220px] px-3 font-semibold lg:table-cell">{{ t('superAdmin.activity.hotel') }}</th>
                                <th class="hidden w-[180px] px-3 font-semibold md:table-cell">{{ t('superAdmin.activity.administrator') }}</th>
                                <th class="hidden w-[135px] px-3 font-semibold xl:table-cell">IP</th>
                                <th class="w-[78px] px-2 text-right font-semibold sm:w-[110px]">{{ t('superAdmin.activity.date') }}</th>
                                <th class="w-8" />
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="group in groupedRows" :key="group.key">
                                <tr class="h-9 border-b border-[var(--sa-line)] bg-neutral-50/50"><td colspan="6" class="px-4 text-[9px] font-bold uppercase tracking-[0.12em] text-neutral-500">{{ group.label }}</td></tr>
                                <tr
                                    v-for="log in group.rows"
                                    :key="log.id"
                                    role="button"
                                    tabindex="0"
                                    class="group cursor-pointer border-b border-neutral-100 outline-none transition last:border-b-0 hover:bg-emerald-50/30 focus:bg-emerald-50/40 focus:ring-2 focus:ring-inset focus:ring-emerald-500/30"
                                    @click="selectedLog = log"
                                    @keydown.enter="selectedLog = log"
                                    @keydown.space.prevent="selectedLog = log"
                                >
                                    <td class="px-3 py-2.5 sm:px-4">
                                        <div class="flex min-w-0 items-center gap-3">
                                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-[10px]" :class="iconClass(actionMeta(log.action).tone)"><component :is="actionMeta(log.action).icon" class="h-4 w-4" :stroke-width="1.8" /></span>
                                            <div class="min-w-0">
                                                <p class="truncate text-xs font-semibold text-neutral-900">{{ actionMeta(log.action).label }}</p>
                                                <p class="mt-0.5 truncate text-[10px] text-neutral-500">{{ log.summary || log.tenant || t('superAdmin.activity.platform') }}</p>
                                                <p class="mt-1 truncate text-[9px] text-neutral-400 md:hidden">{{ log.actor }}<template v-if="log.tenant"> · {{ log.tenant }}</template></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="hidden px-3 py-2.5 lg:table-cell"><div class="flex min-w-0 items-center gap-2.5"><span class="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-emerald-50 text-[9px] font-bold text-emerald-800">{{ initials(log.tenant) }}</span><strong class="truncate text-[11px] text-neutral-800">{{ log.tenant || t('superAdmin.activity.platform') }}</strong></div></td>
                                    <td class="hidden px-3 py-2.5 md:table-cell"><strong class="block truncate text-[11px] text-neutral-800">{{ log.actor }}</strong><span class="mt-0.5 block truncate text-[9px] text-neutral-400">{{ log.actor_email || 'Super Admin' }}</span></td>
                                    <td class="hidden px-3 py-2.5 font-mono text-[10px] text-neutral-500 xl:table-cell">{{ log.ip_address || '—' }}</td>
                                    <td class="px-2 py-2.5 text-right"><strong class="block text-[11px] text-neutral-900">{{ time(log.created_at) }}</strong><span class="mt-0.5 block text-[9px] text-neutral-400">{{ shortDay(log.created_at) }}</span></td>
                                    <td class="pr-2 text-right text-neutral-300"><ChevronRight class="inline h-4 w-4 transition group-hover:translate-x-0.5 group-hover:text-emerald-700" /></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div v-else class="flex flex-col items-center gap-2 px-5 py-16 text-center">
                    <span class="grid h-12 w-12 place-items-center rounded-2xl bg-neutral-100 text-neutral-400"><ListChecks class="h-6 w-6" /></span>
                    <p class="text-sm font-medium text-neutral-700">{{ t('superAdmin.activity.noResults') }}</p>
                    <p class="text-xs text-neutral-500">{{ t('superAdmin.activity.noResultsHint') }}</p>
                    <button v-if="hasFilters" type="button" class="sa-button mt-2" @click="resetFilters">{{ t('superAdmin.activity.clear') }}</button>
                </div>

                <div v-if="logs.last_page > 1" class="flex items-center justify-between border-t border-[var(--sa-line)] px-4 py-3 text-sm">
                    <button class="sa-button h-8 px-3 disabled:opacity-40" :disabled="!logs.prev_page_url" @click="go(logs.prev_page_url)">← {{ t('superAdmin.dynamic.previous') }}</button>
                    <span class="text-[10px] text-neutral-500">{{ t('superAdmin.dynamic.pagination', { page: logs.current_page, pages: logs.last_page, count: logs.total }) }}</span>
                    <button class="sa-button h-8 px-3 disabled:opacity-40" :disabled="!logs.next_page_url" @click="go(logs.next_page_url)">{{ t('superAdmin.auto.copy117') }}</button>
                </div>
            </section>
        </div>

        <Teleport to="body">
            <div v-if="selectedLog" class="fixed inset-0 z-50 bg-neutral-950/40 backdrop-blur-[1px]" @click.self="selectedLog = null">
                <aside role="dialog" aria-modal="true" :aria-label="t('superAdmin.activity.details')" class="ml-auto flex h-full w-full max-w-[520px] flex-col bg-white shadow-2xl">
                    <div class="flex items-start justify-between border-b border-neutral-200 px-5 py-4">
                        <div class="flex items-center gap-3">
                            <span class="grid h-9 w-9 place-items-center rounded-[10px]" :class="iconClass(actionMeta(selectedLog.action).tone)"><component :is="actionMeta(selectedLog.action).icon" class="h-4 w-4" /></span>
                            <div><h2 class="text-base font-semibold text-neutral-900">{{ t('superAdmin.activity.details') }}</h2><p class="mt-0.5 text-[10px] text-neutral-500">{{ t('superAdmin.dynamic.auditLogNumber', { id: selectedLog.id }) }}</p></div>
                        </div>
                        <button type="button" class="rounded-lg p-2 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" :aria-label="t('superAdmin.compact.close')" @click="selectedLog = null"><X class="h-5 w-5" /></button>
                    </div>

                    <div class="flex-1 space-y-5 overflow-y-auto p-5">
                        <div class="flex items-center gap-3 rounded-xl border border-neutral-200 p-4">
                            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl" :class="iconClass(actionMeta(selectedLog.action).tone)"><component :is="actionMeta(selectedLog.action).icon" class="h-5 w-5" /></span>
                            <div class="min-w-0"><p class="font-semibold text-neutral-900">{{ actionMeta(selectedLog.action).label }}</p><p class="mt-1 truncate text-xs text-neutral-500">{{ selectedLog.tenant || t('superAdmin.activity.platform') }}</p></div>
                        </div>

                        <section>
                            <h3 class="text-[9px] font-bold uppercase tracking-[0.12em] text-neutral-400">{{ t('superAdmin.activity.actionSection') }}</h3>
                            <div class="mt-2.5 divide-y divide-neutral-100 rounded-xl border border-neutral-200">
                                <div class="flex justify-between gap-4 px-4 py-3 text-xs"><span class="text-neutral-500">{{ t('superAdmin.dynamic.summary') }}</span><strong class="max-w-[280px] text-right text-neutral-900">{{ selectedLog.summary || actionMeta(selectedLog.action).label }}</strong></div>
                                <div class="flex justify-between gap-4 px-4 py-3 text-xs"><span class="text-neutral-500">{{ t('superAdmin.activity.status') }}</span><strong class="text-right" :class="selectedLog.outcome === 'failed' ? 'text-red-600' : 'text-emerald-700'">{{ selectedLog.outcome === 'failed' ? t('superAdmin.activity.failed') : t('superAdmin.activity.success') }}</strong></div>
                                <div class="flex justify-between gap-4 px-4 py-3 text-xs"><span class="text-neutral-500">{{ t('superAdmin.activity.dateTime') }}</span><strong class="text-right text-neutral-900">{{ fullDate(selectedLog.created_at) }}</strong></div>
                            </div>
                        </section>

                        <section>
                            <h3 class="text-[9px] font-bold uppercase tracking-[0.12em] text-neutral-400">{{ t('superAdmin.activity.administrator') }}</h3>
                            <div class="mt-2.5 divide-y divide-neutral-100 rounded-xl border border-neutral-200">
                                <div class="flex justify-between gap-4 px-4 py-3 text-xs"><span class="text-neutral-500">{{ t('superAdmin.compact.name') }}</span><strong class="text-right text-neutral-900">{{ selectedLog.actor }}</strong></div>
                                <div class="flex justify-between gap-4 px-4 py-3 text-xs"><span class="text-neutral-500">Email</span><strong class="break-all text-right text-neutral-900">{{ selectedLog.actor_email || '—' }}</strong></div>
                                <div class="flex justify-between gap-4 px-4 py-3 text-xs"><span class="text-neutral-500">IP</span><strong class="text-right text-neutral-900">{{ selectedLog.ip_address || '—' }}</strong></div>
                            </div>
                        </section>

                        <div class="flex items-start gap-3 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-emerald-800">
                            <ShieldCheck class="mt-0.5 h-5 w-5 shrink-0" />
                            <div><p class="text-xs font-semibold">{{ t('superAdmin.activity.immutableTitle') }}</p><p class="mt-1 text-[10px] leading-4 text-emerald-700">{{ t('superAdmin.activity.immutableDescription') }}</p></div>
                        </div>
                    </div>

                    <div class="flex justify-end border-t border-neutral-200 bg-neutral-50/70 px-5 py-3.5">
                        <button type="button" class="sa-button" @click="selectedLog = null">{{ t('superAdmin.compact.close') }}</button>
                    </div>
                </aside>
            </div>
        </Teleport>
    </SuperAdminLayout>
</template>
