<script setup>
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Building2,
    Check,
    CircleAlert,
    CreditCard,
    Download,
    ExternalLink,
    Globe,
    Plug,
    Search,
    Users,
    X,
} from 'lucide-vue-next';
import { ref, computed } from 'vue';

const props = defineProps({
    tenants: Array,
    currentTenantId: Number,
});

const form = useForm({
    name: '',
    slug: '',
    primary_domain: '',
    timezone: 'Europe/Tirane',
    currency: 'EUR',
    owner_name: '',
    owner_email: '',
});

const editingTenant = ref(null);
const billingForm = useForm({
    status: 'active',
    billing_cycle: 'monthly',
    current_period_ends_at: '',
    notes: '',
    modules: {},
});

const showCreate = ref(false);

function openCreate() {
    form.reset('name', 'slug', 'primary_domain', 'owner_name', 'owner_email');
    form.clearErrors();
    showCreate.value = true;
}

function closeCreate() {
    if (!form.processing) showCreate.value = false;
}

function createTenant() {
    form.post(route('super-admin.tenants.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('name', 'slug', 'primary_domain', 'owner_name', 'owner_email');
            showCreate.value = false;
        },
    });
}

// ---- Konfigurimi (domains + integrimet) ----
const configTenant = ref(null);

const domainForm = useForm({ domain: '' });
const channexForm = useForm({ enabled: false, api_key: '', webhook_secret: '', property_id: '', base_url: '' });
const pokForm = useForm({ enabled: false, key_id: '', key_secret: '', merchant_id: '', production: false });

function openConfig(tenant) {
    configTenant.value = tenant;
    domainForm.reset();
    domainForm.clearErrors();

    // Secrets are NEVER sent to the browser — the fields start blank and a
    // blank submit keeps whatever is stored on the server.
    channexForm.enabled = tenant.integrations.channex.enabled;
    channexForm.api_key = '';
    channexForm.webhook_secret = '';
    channexForm.property_id = tenant.integrations.channex.property_id || '';
    channexForm.base_url = tenant.integrations.channex.base_url || '';
    channexForm.clearErrors();

    pokForm.enabled = tenant.integrations.pok.enabled;
    pokForm.key_id = '';
    pokForm.key_secret = '';
    pokForm.merchant_id = tenant.integrations.pok.merchant_id || '';
    pokForm.production = tenant.integrations.pok.production;
    pokForm.clearErrors();
}

function closeConfig() {
    if (!domainForm.processing && !channexForm.processing && !pokForm.processing) configTenant.value = null;
}

function refreshConfig() {
    router.reload({
        only: ['tenants'],
        onSuccess: (page) => {
            const fresh = page.props.tenants.find((t) => t.id === configTenant.value?.id);
            if (fresh) openConfig(fresh);
        },
    });
}

function addDomain() {
    domainForm.post(route('super-admin.tenants.domains.store', configTenant.value.id), {
        preserveScroll: true,
        onSuccess: refreshConfig,
    });
}

function removeDomain(domain) {
    router.delete(route('super-admin.tenants.domains.destroy', [configTenant.value.id, domain.id]), {
        preserveScroll: true,
        onSuccess: refreshConfig,
    });
}

function makePrimary(domain) {
    router.patch(route('super-admin.tenants.domains.primary', [configTenant.value.id, domain.id]), {}, {
        preserveScroll: true,
        onSuccess: refreshConfig,
    });
}

function saveChannex() {
    channexForm.put(route('super-admin.tenants.integrations.update', [configTenant.value.id, 'channex']), {
        preserveScroll: true,
        onSuccess: refreshConfig,
    });
}

function savePok() {
    pokForm.put(route('super-admin.tenants.integrations.update', [configTenant.value.id, 'pok']), {
        preserveScroll: true,
        onSuccess: refreshConfig,
    });
}

function switchTenant(tenant) {
    router.post(route('super-admin.tenants.switch', tenant.id));
}

const search = ref('');
const statusFilter = ref('all');
const billingCycle = ref('');
const sortOrder = ref('mrr');
const selectedTenant = ref(null);

function moduleEnabled(tenant, code) {
    return Boolean(tenant.billing?.modules?.[code]?.enabled);
}

function monthlyMrr(tenant) {
    if (tenant.status !== 'active' || !['active', 'trialing'].includes(tenant.billing?.status)) return 0;
    return tenant.billing.billing_cycle === 'annual'
        ? Math.round(Number(tenant.billing.annual_cents || 0) / 12)
        : Number(tenant.billing.monthly_fixed_cents || 0);
}

function tenantHealth(tenant) {
    const status = hotelStatus(tenant);
    if (status.tone !== 'ok') return { ...status, detail: 'Kontrollo abonimin ose statusin' };

    const missing = [];
    if (!tenant.primary_domain) missing.push('domain');
    if (moduleEnabled(tenant, 'channel_manager')
        && (!tenant.integrations?.channex?.enabled || !tenant.integrations?.channex?.has_api_key)) missing.push('Channex');
    if (moduleEnabled(tenant, 'booking_engine')
        && (!tenant.integrations?.pok?.enabled || !tenant.integrations?.pok?.has_key_id)) missing.push('POK');

    return missing.length
        ? { label: 'Kërkon vëmendje', tone: 'attention', detail: `Mungon: ${missing.join(', ')}` }
        : { label: 'Në rregull', tone: 'ok', detail: 'Konfigurimi është i plotë' };
}

const summary = computed(() => ({
    active: props.tenants.filter((tenant) => tenant.status === 'active').length,
    users: props.tenants.reduce((total, tenant) => total + Number(tenant.users_count || 0), 0),
    mrr: props.tenants.reduce((total, tenant) => total + monthlyMrr(tenant), 0),
    attention: props.tenants.filter((tenant) => tenant.status !== 'suspended' && tenantHealth(tenant).tone !== 'ok').length,
    suspended: props.tenants.filter((tenant) => tenant.status === 'suspended').length,
}));

const statusChips = computed(() => [
    { key: 'all', label: 'Të gjithë', count: props.tenants.length },
    { key: 'healthy', label: 'Në rregull', count: props.tenants.filter((tenant) => tenantHealth(tenant).tone === 'ok').length },
    { key: 'attention', label: 'Kërkojnë vëmendje', count: summary.value.attention },
    { key: 'suspended', label: 'Të pezulluar', count: summary.value.suspended },
]);

const filteredTenants = computed(() => {
    const q = search.value.trim().toLowerCase();
    const rows = props.tenants.filter((tenant) => {
        const matchesSearch = !q || [tenant.name, tenant.slug, tenant.primary_domain, ...(tenant.domains || []).map((domain) => domain.domain)]
            .filter(Boolean)
            .some((value) => String(value).toLowerCase().includes(q));
        const health = tenantHealth(tenant);
        const matchesStatus = statusFilter.value === 'all'
            || (statusFilter.value === 'healthy' && health.tone === 'ok')
            || (statusFilter.value === 'attention' && tenant.status !== 'suspended' && health.tone !== 'ok')
            || (statusFilter.value === 'suspended' && tenant.status === 'suspended');

        return matchesSearch
            && matchesStatus
            && (!billingCycle.value || tenant.billing?.billing_cycle === billingCycle.value);
    });

    return [...rows].sort((left, right) => {
        if (sortOrder.value === 'name') return left.name.localeCompare(right.name, 'sq');
        if (sortOrder.value === 'attention') {
            return Number(tenantHealth(right).tone !== 'ok') - Number(tenantHealth(left).tone !== 'ok')
                || left.name.localeCompare(right.name, 'sq');
        }
        return monthlyMrr(right) - monthlyMrr(left) || left.name.localeCompare(right.name, 'sq');
    });
});

function toggleStatus(tenant) {
    const suspend = tenant.status === 'active';
    const msg = suspend
        ? `Të pezulloj ${tenant.name}? Hoteli s'do të hapet dot (faqja, paneli, rezervimet) derisa ta riaktivizosh.`
        : `Të riaktivizoj ${tenant.name}?`;
    if (!confirm(msg)) return;
    router.patch(route('super-admin.tenants.status', tenant.id), {
        status: suspend ? 'suspended' : 'active',
    }, { preserveScroll: true });
}

const openMenuId = ref(null);
function toggleMenu(id) { openMenuId.value = openMenuId.value === id ? null : id; }
function closeMenu() { openMenuId.value = null; }

function enabledCount(tenant) {
    return Object.values(tenant.billing.modules).filter((m) => m.enabled).length;
}

function initials(name) {
    return name.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
}

function exportTenants() {
    const header = ['Hoteli', 'Domain', 'Abonimi', 'Përdorues', 'Module', 'MRR EUR', 'Shëndeti', 'Statusi'];
    const rows = filteredTenants.value.map((tenant) => [
        tenant.name,
        tenant.primary_domain || '',
        tenant.billing?.billing_cycle === 'annual' ? 'Vjetore' : 'Mujore',
        tenant.users_count,
        enabledCount(tenant),
        (monthlyMrr(tenant) / 100).toFixed(2),
        tenantHealth(tenant).label,
        hotelStatus(tenant).label,
    ]);
    const csv = [header, ...rows]
        .map((row) => row.map((value) => `"${String(value ?? '').replaceAll('"', '""')}"`).join(','))
        .join('\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8' }));
    link.download = `lora-hotelet-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
}

function shortDate(value) {
    return new Intl.DateTimeFormat('sq-AL', { day: '2-digit', month: '2-digit' }).format(new Date(value));
}

// One status per hotel: suspension and billing trouble collapse into a single
// clear pill instead of two separate 'Aktiv' badges.
function hotelStatus(tenant) {
    if (tenant.status === 'suspended') return { label: 'Pezulluar', tone: 'bad' };
    const b = tenant.billing || {};
    if (b.status === 'past_due') return { label: 'Pagesë e vonuar', tone: 'warn' };
    if (['suspended', 'canceled', 'inactive'].includes(b.status)) return { label: statusLabel(b.status), tone: 'warn' };
    if (b.current_period_ends_at) {
        const ends = new Date(b.current_period_ends_at);
        const soon = new Date();
        soon.setDate(soon.getDate() + 14);
        if (ends <= soon) return { label: `Rinovim më ${shortDate(b.current_period_ends_at)}`, tone: 'warn' };
    }
    return { label: 'Aktiv', tone: 'ok' };
}

function statusPillClass(tone) {
    return { ok: 'bg-emerald-50 text-emerald-700', attention: 'bg-amber-50 text-amber-700', warn: 'bg-amber-50 text-amber-700', bad: 'bg-red-50 text-red-700' }[tone] || 'bg-neutral-100 text-neutral-600';
}
function statusDotClass(tone) {
    return { ok: 'bg-emerald-500', attention: 'bg-amber-500', warn: 'bg-amber-500', bad: 'bg-red-500' }[tone] || 'bg-neutral-400';
}

function openBilling(tenant) {
    editingTenant.value = tenant;
    billingForm.status = tenant.billing.status;
    billingForm.billing_cycle = tenant.billing.billing_cycle;
    billingForm.current_period_ends_at = tenant.billing.current_period_ends_at || '';
    billingForm.notes = tenant.billing.notes || '';
    billingForm.modules = Object.fromEntries(
        Object.entries(tenant.billing.modules).map(([code, module]) => [
            code,
            { enabled: module.enabled, quantity: module.quantity },
        ]),
    );
    billingForm.clearErrors();
}

function closeBilling() {
    if (!billingForm.processing) editingTenant.value = null;
}

function saveBilling() {
    billingForm.put(route('super-admin.tenants.subscription.update', editingTenant.value.id), {
        preserveScroll: true,
        onSuccess: closeBilling,
    });
}

function money(cents, currency = 'EUR') {
    return new Intl.NumberFormat('sq-AL', {
        style: 'currency',
        currency,
        maximumFractionDigits: 2,
    }).format((cents || 0) / 100);
}

function statusLabel(status) {
    return {
        trialing: 'Provë',
        active: 'Aktiv',
        past_due: 'Pagesë e vonuar',
        suspended: 'Pezulluar',
        canceled: 'Anuluar',
        inactive: 'Joaktiv',
    }[status] || status;
}
</script>

<template>
    <Head title="Super Admin — Hotelet" />

    <SuperAdminLayout title="Hotelet & abonimet — Lora Control Panel">
        <div class="mx-auto max-w-[1480px] space-y-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <PageHeader
                        title="Hotelet & abonimet"
                        :breadcrumbs="[{ label: 'Control Panel', href: '/super-admin' }, { label: 'Hotelet & abonimet' }]"
                    />
                    <p class="mt-1 text-sm text-neutral-500">Menaxho tenantët, të ardhurat dhe shëndetin e platformës.</p>
                </div>
                <Button variant="primary" @click="openCreate">+ Shto hotel</Button>
            </div>

            <section class="grid gap-4 md:grid-cols-3">
                <article class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm shadow-neutral-200/30">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-neutral-500">Hotele aktive</p>
                            <p class="mt-3 text-3xl font-semibold tracking-tight text-neutral-900">{{ summary.active }}</p>
                            <p class="mt-1 text-xs text-neutral-400">{{ summary.users }} përdorues në platformë</p>
                        </div>
                        <span class="grid h-11 w-11 place-items-center rounded-xl bg-emerald-50 text-emerald-700"><Building2 class="h-5 w-5" /></span>
                    </div>
                </article>
                <article class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm shadow-neutral-200/30">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-neutral-500">MRR i vlerësuar</p>
                            <p class="mt-3 text-3xl font-semibold tracking-tight text-neutral-900">{{ money(summary.mrr, 'EUR') }}</p>
                            <p class="mt-1 text-xs text-neutral-400">Nga abonimet aktive</p>
                        </div>
                        <span class="grid h-11 w-11 place-items-center rounded-xl bg-blue-50 text-blue-700"><CreditCard class="h-5 w-5" /></span>
                    </div>
                </article>
                <article class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm shadow-neutral-200/30">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-neutral-500">Kërkojnë vëmendje</p>
                            <p class="mt-3 text-3xl font-semibold tracking-tight" :class="summary.attention ? 'text-amber-700' : 'text-neutral-900'">{{ summary.attention }}</p>
                            <p class="mt-1 text-xs text-neutral-400">Abonime, domain-e ose integrime</p>
                        </div>
                        <span class="grid h-11 w-11 place-items-center rounded-xl bg-amber-50 text-amber-700"><CircleAlert class="h-5 w-5" /></span>
                    </div>
                </article>
            </section>

            <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm shadow-neutral-200/30">
                <div class="flex flex-col gap-4 border-b border-neutral-200 px-5 py-5 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-neutral-900">Portofoli i hoteleve</h2>
                        <p class="mt-1 text-sm text-neutral-500">Pamje e përmbledhur e përdorimit dhe konfigurimit.</p>
                    </div>
                    <Button v-if="tenants.length" variant="outline" class="gap-2" @click="exportTenants">
                        <Download class="h-4 w-4" /> Eksporto CSV
                    </Button>
                </div>

                <div v-if="tenants.length" class="space-y-4 border-b border-neutral-100 px-5 py-4">
                    <div class="grid gap-3 lg:grid-cols-[minmax(260px,1fr)_190px_190px]">
                        <label class="relative block">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                            <input v-model="search" type="search" placeholder="Kërko hotel, slug ose domain…" class="w-full rounded-xl border-neutral-300 py-2.5 pl-10 pr-3 text-sm" />
                        </label>
                        <select v-model="billingCycle" class="rounded-xl border-neutral-300 py-2.5 text-sm text-neutral-700">
                            <option value="">Të gjitha pagesat</option>
                            <option value="monthly">Mujore</option>
                            <option value="annual">Vjetore</option>
                        </select>
                        <select v-model="sortOrder" class="rounded-xl border-neutral-300 py-2.5 text-sm text-neutral-700">
                            <option value="mrr">Rendit: MRR</option>
                            <option value="name">Rendit: Emri</option>
                            <option value="attention">Rendit: Vëmendja</option>
                        </select>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="chip in statusChips"
                            :key="chip.key"
                            type="button"
                            class="rounded-full border px-3 py-1.5 text-xs font-medium transition"
                            :class="statusFilter === chip.key ? 'border-[#123d32] bg-[#123d32] text-white' : 'border-neutral-200 bg-white text-neutral-600 hover:border-neutral-300 hover:bg-neutral-50'"
                            @click="statusFilter = chip.key"
                        >
                            {{ chip.label }} · {{ chip.count }}
                        </button>
                    </div>
                </div>

                <div v-if="filteredTenants.length" class="overflow-x-auto">
                    <table class="w-full min-w-[920px] text-sm">
                        <thead>
                            <tr class="border-b border-neutral-200 bg-neutral-50/70 text-left text-[11px] uppercase tracking-[0.08em] text-neutral-400">
                                <th class="px-5 py-3 font-semibold">Hoteli</th>
                                <th class="px-4 py-3 font-semibold">Abonimi</th>
                                <th class="px-4 py-3 font-semibold">Përdorimi</th>
                                <th class="px-4 py-3 font-semibold">Shëndeti</th>
                                <th class="px-4 py-3 font-semibold">Statusi</th>
                                <th class="px-5 py-3 text-right font-semibold">Veprime</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="tenant in filteredTenants" :key="tenant.id" class="cursor-pointer transition hover:bg-emerald-50/30" @click="selectedTenant = tenant">
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-[#e8f3ef] text-xs font-bold text-[#24624f]">{{ initials(tenant.name) }}</span>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <Link :href="route('super-admin.tenants.show', tenant.id)" class="truncate font-semibold text-neutral-900 no-underline hover:text-emerald-700" @click.stop>{{ tenant.name }}</Link>
                                                <span v-if="tenant.id === currentTenantId" class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700">AKTUAL</span>
                                            </div>
                                            <p class="mt-0.5 max-w-[280px] truncate text-xs text-neutral-400">{{ tenant.primary_domain || tenant.slug }} · {{ tenant.timezone }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <p class="font-semibold tabular-nums text-neutral-900">{{ money(monthlyMrr(tenant), tenant.billing.currency) }} <span class="text-xs font-normal text-neutral-400">/ muaj</span></p>
                                    <p class="mt-0.5 text-xs text-neutral-500">{{ tenant.billing.billing_cycle === 'annual' ? 'Faturim vjetor' : 'Faturim mujor' }}</p>
                                </td>
                                <td class="px-4 py-4">
                                    <p class="font-medium text-neutral-800"><Users class="mr-1 inline h-3.5 w-3.5 text-neutral-400" />{{ tenant.users_count }} përdorues</p>
                                    <p class="mt-0.5 text-xs text-neutral-500">{{ enabledCount(tenant) }} {{ enabledCount(tenant) === 1 ? 'modul aktiv' : 'module aktive' }}</p>
                                </td>
                                <td class="px-4 py-4">
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium" :class="statusPillClass(tenantHealth(tenant).tone)">
                                        <span class="h-1.5 w-1.5 rounded-full" :class="statusDotClass(tenantHealth(tenant).tone)" />
                                        {{ tenantHealth(tenant).label }}
                                    </span>
                                    <p class="mt-1 max-w-[210px] truncate text-[11px] text-neutral-400">{{ tenantHealth(tenant).detail }}</p>
                                </td>
                                <td class="px-4 py-4">
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium" :class="statusPillClass(hotelStatus(tenant).tone)">
                                        <span class="h-1.5 w-1.5 rounded-full" :class="statusDotClass(hotelStatus(tenant).tone)" />
                                        {{ hotelStatus(tenant).label }}
                                    </span>
                                </td>
                                <td class="px-5 py-4" @click.stop>
                                    <div class="flex items-center justify-end gap-2">
                                        <Button
                                            size="sm"
                                            :variant="tenant.id === currentTenantId ? 'outline' : 'primary'"
                                            :disabled="tenant.id === currentTenantId || tenant.status !== 'active'"
                                            @click="switchTenant(tenant)"
                                        >
                                            {{ tenant.id === currentTenantId ? 'Aktual' : 'Hap' }}
                                        </Button>
                                        <div class="relative">
                                            <button type="button" class="grid h-8 w-8 place-items-center rounded-lg border border-neutral-200 text-lg leading-none text-neutral-500 hover:bg-neutral-50" aria-label="Veprime të tjera" @click.stop="toggleMenu(tenant.id)">⋯</button>
                                            <div v-if="openMenuId === tenant.id" class="absolute right-0 z-50 mt-1 w-44 overflow-hidden rounded-xl border border-neutral-200 bg-white py-1 text-left shadow-lg">
                                                <button type="button" class="block w-full px-4 py-2 text-left text-sm text-neutral-700 hover:bg-neutral-50" @click="closeMenu(); openBilling(tenant)">Abonimi</button>
                                                <button type="button" class="block w-full px-4 py-2 text-left text-sm text-neutral-700 hover:bg-neutral-50" @click="closeMenu(); openConfig(tenant)">Konfigurimi</button>
                                                <button type="button" class="block w-full px-4 py-2 text-left text-sm hover:bg-neutral-50" :class="tenant.status === 'active' ? 'text-red-600' : 'text-success-700'" @click="closeMenu(); toggleStatus(tenant)">{{ tenant.status === 'active' ? 'Pezullo' : 'Aktivizo' }}</button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else-if="tenants.length" class="px-5 py-14 text-center">
                    <p class="text-sm font-medium text-neutral-700">Nuk u gjet asnjë hotel</p>
                    <p class="mt-1 text-xs text-neutral-500">Ndrysho kërkimin ose filtrat e zgjedhur.</p>
                </div>
                <div v-else class="px-5 py-16 text-center">
                    <p class="text-sm font-medium text-neutral-700">Ende asnjë hotel</p>
                    <p class="mt-1 text-xs text-neutral-500">Krijo hotelin e parë të platformës.</p>
                    <Button variant="primary" class="mt-4" @click="openCreate">+ Shto hotel</Button>
                </div>

                <div v-if="tenants.length" class="border-t border-neutral-100 px-5 py-3 text-xs text-neutral-400">
                    Po shfaqen {{ filteredTenants.length }} nga {{ tenants.length }} hotele
                </div>
            </section>

            <div v-if="openMenuId" class="fixed inset-0 z-40" @click="closeMenu" />
        </div>

        <Teleport to="body">
            <div v-if="selectedTenant" class="fixed inset-0 z-50 bg-neutral-950/40" @click.self="selectedTenant = null">
                <aside class="ml-auto flex h-full w-full max-w-lg flex-col bg-white shadow-2xl">
                    <div class="flex items-start justify-between border-b border-neutral-200 px-6 py-5">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-[#e8f3ef] text-sm font-bold text-[#24624f]">{{ initials(selectedTenant.name) }}</span>
                            <div class="min-w-0">
                                <h2 class="truncate text-lg font-semibold text-neutral-900">{{ selectedTenant.name }}</h2>
                                <p class="truncate text-sm text-neutral-500">{{ selectedTenant.primary_domain || selectedTenant.slug }}</p>
                            </div>
                        </div>
                        <button type="button" class="rounded-xl p-2 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" aria-label="Mbyll" @click="selectedTenant = null"><X class="h-5 w-5" /></button>
                    </div>

                    <div class="flex-1 space-y-6 overflow-y-auto p-6">
                        <div class="grid grid-cols-3 gap-3">
                            <div class="rounded-xl bg-neutral-50 p-3">
                                <p class="text-[11px] font-medium uppercase tracking-wide text-neutral-400">MRR</p>
                                <p class="mt-1 font-semibold text-neutral-900">{{ money(monthlyMrr(selectedTenant), selectedTenant.billing.currency) }}</p>
                            </div>
                            <div class="rounded-xl bg-neutral-50 p-3">
                                <p class="text-[11px] font-medium uppercase tracking-wide text-neutral-400">Përdorues</p>
                                <p class="mt-1 font-semibold text-neutral-900">{{ selectedTenant.users_count }}</p>
                            </div>
                            <div class="rounded-xl bg-neutral-50 p-3">
                                <p class="text-[11px] font-medium uppercase tracking-wide text-neutral-400">Module</p>
                                <p class="mt-1 font-semibold text-neutral-900">{{ enabledCount(selectedTenant) }}</p>
                            </div>
                        </div>

                        <section>
                            <h3 class="text-sm font-semibold text-neutral-900">Kontrolli i shëndetit</h3>
                            <div class="mt-3 divide-y divide-neutral-100 rounded-xl border border-neutral-200">
                                <div class="flex items-center justify-between gap-3 px-4 py-3">
                                    <span class="text-sm text-neutral-600">Abonimi</span>
                                    <span class="text-sm font-medium text-neutral-900">{{ statusLabel(selectedTenant.billing.status) }}</span>
                                </div>
                                <div class="flex items-center justify-between gap-3 px-4 py-3">
                                    <span class="text-sm text-neutral-600">Domain primar</span>
                                    <span class="max-w-[240px] truncate text-sm font-medium" :class="selectedTenant.primary_domain ? 'text-neutral-900' : 'text-amber-700'">{{ selectedTenant.primary_domain || 'Mungon' }}</span>
                                </div>
                                <div v-if="moduleEnabled(selectedTenant, 'channel_manager')" class="flex items-center justify-between gap-3 px-4 py-3">
                                    <span class="text-sm text-neutral-600">Channex</span>
                                    <span class="text-sm font-medium" :class="selectedTenant.integrations.channex.enabled && selectedTenant.integrations.channex.has_api_key ? 'text-emerald-700' : 'text-amber-700'">{{ selectedTenant.integrations.channex.enabled && selectedTenant.integrations.channex.has_api_key ? 'Konfiguruar' : 'Kërkon konfigurim' }}</span>
                                </div>
                                <div v-if="moduleEnabled(selectedTenant, 'booking_engine')" class="flex items-center justify-between gap-3 px-4 py-3">
                                    <span class="text-sm text-neutral-600">POK</span>
                                    <span class="text-sm font-medium" :class="selectedTenant.integrations.pok.enabled && selectedTenant.integrations.pok.has_key_id ? 'text-emerald-700' : 'text-amber-700'">{{ selectedTenant.integrations.pok.enabled && selectedTenant.integrations.pok.has_key_id ? 'Konfiguruar' : 'Kërkon konfigurim' }}</span>
                                </div>
                            </div>
                        </section>

                        <section>
                            <h3 class="text-sm font-semibold text-neutral-900">Modulet aktive</h3>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <span v-for="module in Object.values(selectedTenant.billing.modules).filter((item) => item.enabled)" :key="module.code" class="rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-700">{{ module.name }}</span>
                            </div>
                        </section>
                    </div>

                    <div class="flex flex-wrap justify-end gap-2 border-t border-neutral-200 px-6 py-4">
                        <Button variant="outline" @click="selectedTenant = null">Mbyll</Button>
                        <Button variant="outline" @click="openConfig(selectedTenant); selectedTenant = null">Konfigurimi</Button>
                        <Link :href="route('super-admin.tenants.show', selectedTenant.id)" class="inline-flex items-center gap-2 rounded-lg bg-[#123d32] px-4 py-2 text-sm font-medium text-white no-underline hover:bg-[#0d3027]">
                            Shiko profilin <ExternalLink class="h-4 w-4" />
                        </Link>
                    </div>
                </aside>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="showCreate" class="fixed inset-0 z-50 flex items-end justify-center bg-neutral-950/50 p-0 sm:items-center sm:p-6" @click.self="closeCreate">
                <section class="max-h-[94vh] w-full max-w-lg overflow-y-auto rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl">
                    <div class="sticky top-0 z-10 flex items-start justify-between border-b border-neutral-200 bg-white px-5 py-4 sm:px-6">
                        <div>
                            <h2 class="text-lg font-semibold text-neutral-900">Krijo hotel të ri</h2>
                            <p class="mt-1 text-sm text-neutral-500">Krijon tenantin dhe të lidh ty si owner.</p>
                        </div>
                        <button class="rounded-lg p-2 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" type="button" @click="closeCreate">✕</button>
                    </div>
                    <form class="space-y-4 p-5 sm:p-6" @submit.prevent="createTenant">
                        <label class="block text-sm font-medium text-neutral-700">
                            Emri
                            <input v-model="form.name" required class="mt-1 w-full rounded-lg border-neutral-300 text-sm" placeholder="Hotel Riviera" />
                            <span v-if="form.errors.name" class="mt-1 block text-xs text-danger-600">{{ form.errors.name }}</span>
                        </label>

                        <label class="block text-sm font-medium text-neutral-700">
                            Slug
                            <input v-model="form.slug" required class="mt-1 w-full rounded-lg border-neutral-300 text-sm" placeholder="hotel-riviera" />
                            <span v-if="form.errors.slug" class="mt-1 block text-xs text-danger-600">{{ form.errors.slug }}</span>
                        </label>

                        <label class="block text-sm font-medium text-neutral-700">
                            Domain primar (opsional)
                            <input v-model="form.primary_domain" class="mt-1 w-full rounded-lg border-neutral-300 text-sm" placeholder="riviera.lorapms.com" />
                            <span v-if="form.errors.primary_domain" class="mt-1 block text-xs text-danger-600">{{ form.errors.primary_domain }}</span>
                        </label>

                        <div class="grid grid-cols-2 gap-3">
                            <label class="block text-sm font-medium text-neutral-700">
                                Timezone
                                <input v-model="form.timezone" required class="mt-1 w-full rounded-lg border-neutral-300 text-sm" />
                            </label>
                            <label class="block text-sm font-medium text-neutral-700">
                                Monedha
                                <input v-model="form.currency" required maxlength="3" class="mt-1 w-full rounded-lg border-neutral-300 text-sm uppercase" />
                            </label>
                        </div>

                        <div class="rounded-lg border border-neutral-200 bg-neutral-50/60 p-3">
                            <p class="text-sm font-semibold text-neutral-800">Pronari i parë (opsional)</p>
                            <p class="mt-0.5 text-xs text-neutral-500">Merr rolin admin + owner; fjalëkalimin e vendos vetë me "Kam harruar fjalëkalimin".</p>

                            <label class="mt-3 block text-sm font-medium text-neutral-700">
                                Emri i pronarit
                                <input v-model="form.owner_name" class="mt-1 w-full rounded-lg border-neutral-300 text-sm" placeholder="Ana Berisha" />
                                <span v-if="form.errors.owner_name" class="mt-1 block text-xs text-danger-600">{{ form.errors.owner_name }}</span>
                            </label>

                            <label class="mt-3 block text-sm font-medium text-neutral-700">
                                Email i pronarit
                                <input v-model="form.owner_email" type="email" class="mt-1 w-full rounded-lg border-neutral-300 text-sm" placeholder="ana@hotelriviera.com" />
                                <span v-if="form.errors.owner_email" class="mt-1 block text-xs text-danger-600">{{ form.errors.owner_email }}</span>
                            </label>
                        </div>

                        <Button type="submit" class="w-full justify-center" :disabled="form.processing">
                            {{ form.processing ? 'Duke krijuar…' : 'Krijo tenant' }}
                        </Button>
                    </form>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="editingTenant" class="fixed inset-0 z-50 flex items-end justify-center bg-neutral-950/50 p-0 sm:items-center sm:p-6" @click.self="closeBilling">
                <section class="max-h-[94vh] w-full max-w-3xl overflow-y-auto rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl">
                    <div class="sticky top-0 z-10 flex items-start justify-between border-b border-neutral-200 bg-white px-5 py-4 sm:px-6">
                        <div>
                            <h2 class="text-lg font-semibold text-neutral-900">Abonimi — {{ editingTenant.name }}</h2>
                            <p class="mt-1 text-sm text-neutral-500">Aktivizo vetëm modulet e kontraktuara nga hoteli.</p>
                        </div>
                        <button class="rounded-lg p-2 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" type="button" @click="closeBilling">✕</button>
                    </div>

                    <form class="space-y-6 p-5 sm:p-6" @submit.prevent="saveBilling">
                        <div class="grid gap-4 sm:grid-cols-3">
                            <label class="text-sm font-medium text-neutral-700">
                                Statusi
                                <select v-model="billingForm.status" class="mt-1 w-full rounded-lg border-neutral-300 text-sm">
                                    <option value="trialing">Provë</option>
                                    <option value="active">Aktiv</option>
                                    <option value="past_due">Pagesë e vonuar</option>
                                    <option value="suspended">Pezulluar</option>
                                    <option value="canceled">Anuluar</option>
                                </select>
                            </label>
                            <label class="text-sm font-medium text-neutral-700">
                                Pagesa
                                <select v-model="billingForm.billing_cycle" class="mt-1 w-full rounded-lg border-neutral-300 text-sm">
                                    <option value="monthly">Mujore</option>
                                    <option value="annual">Vjetore · -20%</option>
                                </select>
                            </label>
                            <label class="text-sm font-medium text-neutral-700">
                                Rinovohet deri më
                                <input v-model="billingForm.current_period_ends_at" type="date" class="mt-1 w-full rounded-lg border-neutral-300 text-sm" />
                            </label>
                        </div>

                        <div>
                            <div class="mb-3 flex items-end justify-between gap-4">
                                <div>
                                    <h3 class="font-semibold text-neutral-900">Modulet</h3>
                                    <p class="text-sm text-neutral-500">Core është baza dhe nuk çaktivizohet.</p>
                                </div>
                            </div>

                            <div class="space-y-3">
                                <article
                                    v-for="module in Object.values(editingTenant.billing.modules)"
                                    :key="module.code"
                                    class="rounded-xl border border-neutral-200 p-4"
                                    :class="billingForm.modules[module.code]?.enabled ? 'bg-emerald-50/40' : 'bg-neutral-50/60'"
                                >
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <label class="flex min-w-0 items-start gap-3">
                                            <input
                                                v-model="billingForm.modules[module.code].enabled"
                                                type="checkbox"
                                                class="mt-1 rounded border-neutral-300 text-emerald-600 focus:ring-emerald-500"
                                                :disabled="module.locked"
                                            />
                                            <span>
                                                <span class="block text-sm font-semibold text-neutral-900">{{ module.name }}</span>
                                                <span class="mt-0.5 block text-xs text-neutral-500">{{ module.description }}</span>
                                                <span v-if="module.billing_model === 'percentage'" class="mt-1 block text-xs font-medium text-emerald-700">
                                                    {{ module.percentage_bps / 100 }}% për rezervim direkt
                                                </span>
                                            </span>
                                        </label>

                                        <label
                                            v-if="['tiered_per_room', 'per_user', 'per_pos'].includes(module.billing_model)"
                                            class="shrink-0 text-xs font-medium text-neutral-600"
                                        >
                                            {{ module.unit_label }}
                                            <input
                                                v-model.number="billingForm.modules[module.code].quantity"
                                                type="number"
                                                min="1"
                                                max="10000"
                                                class="ml-2 w-24 rounded-lg border-neutral-300 text-sm"
                                                :disabled="!billingForm.modules[module.code].enabled"
                                            />
                                        </label>
                                    </div>
                                </article>
                            </div>
                        </div>

                        <label class="block text-sm font-medium text-neutral-700">
                            Shënime të brendshme
                            <textarea v-model="billingForm.notes" rows="3" class="mt-1 w-full rounded-lg border-neutral-300 text-sm" placeholder="Kontrata, marrëveshja ose shënime për pagesën…" />
                        </label>

                        <p v-if="Object.keys(billingForm.errors).length" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                            Kontrollo fushat e abonimit dhe provo përsëri.
                        </p>

                        <div class="sticky bottom-0 flex items-center justify-end gap-3 border-t border-neutral-200 bg-white pt-4">
                            <Button type="button" variant="outline" @click="closeBilling">Anulo</Button>
                            <Button type="submit" :disabled="billingForm.processing">
                                {{ billingForm.processing ? 'Duke ruajtur…' : 'Ruaj abonimin' }}
                            </Button>
                        </div>
                    </form>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="configTenant" class="fixed inset-0 z-50 flex items-end justify-center bg-neutral-950/50 p-0 sm:items-center sm:p-6" @click.self="closeConfig">
                <section class="max-h-[94vh] w-full max-w-3xl overflow-y-auto rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl">
                    <div class="sticky top-0 z-10 flex items-start justify-between border-b border-neutral-200 bg-white px-5 py-4 sm:px-6">
                        <div>
                            <h2 class="text-lg font-semibold text-neutral-900">Konfigurimi — {{ configTenant.name }}</h2>
                            <p class="mt-1 text-sm text-neutral-500">Domain-et dhe integrimet e këtij hoteli. Sekretet ruhen të enkriptuara dhe nuk rishfaqen.</p>
                        </div>
                        <button class="rounded-lg p-2 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" type="button" @click="closeConfig">✕</button>
                    </div>

                    <div class="space-y-6 p-5 sm:p-6">
                        <!-- Domains -->
                        <section class="overflow-hidden rounded-xl border border-neutral-200">
                            <div class="flex items-center gap-2.5 border-b border-neutral-100 bg-neutral-50/60 px-4 py-3">
                                <span class="grid h-8 w-8 place-items-center rounded-lg border border-neutral-200 bg-white text-neutral-500"><Globe class="h-4 w-4" :stroke-width="1.8" /></span>
                                <div>
                                    <h3 class="font-semibold leading-tight text-neutral-900">Domain-et</h3>
                                    <p class="text-xs text-neutral-500">Adresat ku hapet ky hotel</p>
                                </div>
                            </div>
                            <div class="p-4">
                                <ul class="divide-y divide-neutral-100 rounded-lg border border-neutral-200">
                                    <li v-for="domain in configTenant.domains" :key="domain.id" class="flex items-center justify-between gap-3 px-4 py-2.5">
                                        <div class="flex min-w-0 items-center gap-2">
                                            <span class="truncate text-sm text-neutral-800">{{ domain.domain }}</span>
                                            <span v-if="domain.is_primary" class="rounded-full bg-primary-50 px-2 py-0.5 text-[11px] font-medium text-primary-700">Primar</span>
                                        </div>
                                        <div class="flex shrink-0 gap-2">
                                            <Button v-if="!domain.is_primary" size="sm" variant="outline" @click="makePrimary(domain)">Bëje primar</Button>
                                            <Button v-if="!domain.is_primary" size="sm" variant="outline" class="text-red-600" @click="removeDomain(domain)">Hiq</Button>
                                        </div>
                                    </li>
                                    <li v-if="!configTenant.domains.length" class="px-4 py-3 text-sm text-neutral-500">Ende pa domain.</li>
                                </ul>

                                <form class="mt-3 flex gap-2" @submit.prevent="addDomain">
                                    <input v-model="domainForm.domain" required class="w-full rounded-lg border-neutral-300 text-sm" placeholder="riviera.lorapms.com" />
                                    <Button type="submit" :disabled="domainForm.processing">Shto</Button>
                                </form>
                                <span v-if="domainForm.errors.domain" class="mt-1 block text-xs text-danger-600">{{ domainForm.errors.domain }}</span>
                            </div>
                        </section>

                        <!-- Channex -->
                        <section class="overflow-hidden rounded-xl border border-neutral-200">
                            <div class="flex flex-wrap items-center gap-3 border-b border-neutral-100 bg-neutral-50/60 px-4 py-3">
                                <span class="grid h-8 w-8 place-items-center rounded-lg border border-neutral-200 bg-white text-neutral-500"><Plug class="h-4 w-4" :stroke-width="1.8" /></span>
                                <div class="mr-auto">
                                    <div class="flex items-center gap-2">
                                        <h3 class="font-semibold leading-tight text-neutral-900">Channex</h3>
                                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium" :class="configTenant.integrations.channex.has_api_key ? 'bg-emerald-50 text-emerald-700' : 'bg-neutral-100 text-neutral-500'">
                                            <Check v-if="configTenant.integrations.channex.has_api_key" class="h-3 w-3" :stroke-width="2.5" />{{ configTenant.integrations.channex.has_api_key ? 'kredencialet e ruajtura' : 'pa kredenciale' }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-neutral-500">Channel Manager — shpërndan dhomat te OTA-t</p>
                                </div>
                                <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-neutral-700">
                                    <input v-model="channexForm.enabled" type="checkbox" class="h-4 w-4 rounded border-neutral-300 text-emerald-600 focus:ring-emerald-500" /> Aktiv
                                </label>
                            </div>

                            <form class="grid gap-3 p-4 sm:grid-cols-2" @submit.prevent="saveChannex">
                                <label class="text-sm font-medium text-neutral-700">
                                    API key
                                    <input v-model="channexForm.api_key" type="password" autocomplete="new-password" class="mt-1 w-full rounded-lg border-neutral-300 text-sm" :placeholder="configTenant.integrations.channex.has_api_key ? '•••• (lëre bosh për ta mbajtur)' : 'ngjit çelësin Channex'" />
                                </label>
                                <label class="text-sm font-medium text-neutral-700">
                                    Webhook secret
                                    <input v-model="channexForm.webhook_secret" type="password" autocomplete="new-password" class="mt-1 w-full rounded-lg border-neutral-300 text-sm" :placeholder="configTenant.integrations.channex.has_webhook_secret ? '•••• (lëre bosh për ta mbajtur)' : ''" />
                                </label>
                                <label class="text-sm font-medium text-neutral-700">
                                    Property ID
                                    <input v-model="channexForm.property_id" class="mt-1 w-full rounded-lg border-neutral-300 text-sm" placeholder="p.sh. 5f2a…" />
                                    <span v-if="channexForm.errors.property_id" class="mt-1 block text-xs text-danger-600">{{ channexForm.errors.property_id }}</span>
                                </label>
                                <label class="text-sm font-medium text-neutral-700">
                                    Base URL (opsional)
                                    <input v-model="channexForm.base_url" class="mt-1 w-full rounded-lg border-neutral-300 text-sm" placeholder="https://app.channex.io/api/v1" />
                                    <span v-if="channexForm.errors.base_url" class="mt-1 block text-xs text-danger-600">{{ channexForm.errors.base_url }}</span>
                                </label>
                                <div class="flex justify-end sm:col-span-2">
                                    <Button type="submit" :disabled="channexForm.processing">{{ channexForm.processing ? 'Duke ruajtur…' : 'Ruaj Channex' }}</Button>
                                </div>
                            </form>
                        </section>

                        <!-- POK -->
                        <section class="overflow-hidden rounded-xl border border-neutral-200">
                            <div class="flex flex-wrap items-center gap-3 border-b border-neutral-100 bg-neutral-50/60 px-4 py-3">
                                <span class="grid h-8 w-8 place-items-center rounded-lg border border-neutral-200 bg-white text-neutral-500"><CreditCard class="h-4 w-4" :stroke-width="1.8" /></span>
                                <div class="mr-auto">
                                    <div class="flex items-center gap-2">
                                        <h3 class="font-semibold leading-tight text-neutral-900">POK</h3>
                                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium" :class="configTenant.integrations.pok.has_key_id ? 'bg-emerald-50 text-emerald-700' : 'bg-neutral-100 text-neutral-500'">
                                            <Check v-if="configTenant.integrations.pok.has_key_id" class="h-3 w-3" :stroke-width="2.5" />{{ configTenant.integrations.pok.has_key_id ? 'kredencialet e ruajtura' : 'pa kredenciale' }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-neutral-500">Pagesat me kartë në booking-un online</p>
                                </div>
                                <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-neutral-700">
                                    <input v-model="pokForm.enabled" type="checkbox" class="h-4 w-4 rounded border-neutral-300 text-emerald-600 focus:ring-emerald-500" /> Aktiv
                                </label>
                            </div>

                            <form class="grid gap-3 p-4 sm:grid-cols-2" @submit.prevent="savePok">
                                <label class="text-sm font-medium text-neutral-700">
                                    Key ID
                                    <input v-model="pokForm.key_id" type="password" autocomplete="new-password" class="mt-1 w-full rounded-lg border-neutral-300 text-sm" :placeholder="configTenant.integrations.pok.has_key_id ? '•••• (lëre bosh për ta mbajtur)' : 'ngjit Key ID'" />
                                </label>
                                <label class="text-sm font-medium text-neutral-700">
                                    Key secret
                                    <input v-model="pokForm.key_secret" type="password" autocomplete="new-password" class="mt-1 w-full rounded-lg border-neutral-300 text-sm" :placeholder="configTenant.integrations.pok.has_key_secret ? '•••• (lëre bosh për ta mbajtur)' : ''" />
                                </label>
                                <label class="text-sm font-medium text-neutral-700">
                                    Merchant ID
                                    <input v-model="pokForm.merchant_id" class="mt-1 w-full rounded-lg border-neutral-300 text-sm" />
                                    <span v-if="pokForm.errors.merchant_id" class="mt-1 block text-xs text-danger-600">{{ pokForm.errors.merchant_id }}</span>
                                </label>
                                <label class="flex items-center gap-2 pt-6 text-sm font-medium text-neutral-700">
                                    <input v-model="pokForm.production" type="checkbox" class="h-4 w-4 rounded border-neutral-300 text-emerald-600 focus:ring-emerald-500" /> Production (live)
                                </label>
                                <div class="flex justify-end sm:col-span-2">
                                    <Button type="submit" :disabled="pokForm.processing">{{ pokForm.processing ? 'Duke ruajtur…' : 'Ruaj POK' }}</Button>
                                </div>
                            </form>
                        </section>
                                        </div>
                </section>
            </div>
        </Teleport>
    </SuperAdminLayout>
</template>
