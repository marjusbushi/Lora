<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import Button from '@/Components/UI/Button.vue';
import {
    ArrowRight,
    Building2,
    Check,
    CreditCard,
    ExternalLink,
    FileCheck2,
    Globe2,
    LogIn,
    Pencil,
    Plug,
    Plus,
    Settings2,
    UserRound,
    X,
} from 'lucide-vue-next';

const props = defineProps({
    tenant: Object,
    members: Array,
    activity: Array,
    currentTenantId: Number,
    currencyOptions: Array,
    timezoneGroups: Object,
    roleOptions: Array,
});

const activeDrawer = ref(null);
const configTab = ref('domains');
const editingMember = ref(null);

const isCurrent = computed(() => props.tenant.id === props.currentTenantId);
const isActive = computed(() => props.tenant.status === 'active');
const billingIsHealthy = computed(() => ['active', 'trialing'].includes(props.tenant.billing?.status));
const activeMembers = computed(() => props.members.filter((member) => member.is_active));
const enabledModules = computed(() => Object.values(props.tenant.billing?.modules || {}).filter((module) => module.enabled));
const channexConfigured = computed(() => Boolean(
    props.tenant.integrations?.channex?.enabled
    && props.tenant.integrations?.channex?.has_api_key
    && props.tenant.integrations?.channex?.property_id,
));
const pokConfigured = computed(() => Boolean(
    props.tenant.integrations?.pok?.enabled && props.tenant.integrations?.pok?.has_key_id,
));
const fatureConfigured = computed(() => Boolean(
    props.tenant.integrations?.fature_al?.enabled && props.tenant.integrations?.fature_al?.has_api_token,
));
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
const readinessRing = computed(() => ({
    background: `conic-gradient(${readinessScore.value === 100 ? '#17745c' : '#b56a10'} 0 ${readinessScore.value}%, #e9efec ${readinessScore.value}% 100%)`,
}));

const tenantForm = useForm({
    name: '',
    slug: '',
    timezone: 'Europe/Tirane',
    currency: 'EUR',
});
const memberForm = useForm({
    name: '',
    email: '',
    role: 'manager',
    is_active: true,
});
const billingForm = useForm({
    status: 'active',
    billing_cycle: 'monthly',
    current_period_ends_at: '',
    notes: '',
    modules: {},
});
const domainForm = useForm({ domain: '' });
const channexForm = useForm({ enabled: false, api_key: '', webhook_secret: '', property_id: '', base_url: '' });
const pokForm = useForm({ enabled: false, key_id: '', key_secret: '', merchant_id: '', production: false });
const fatureForm = useForm({ enabled: false, api_token: '', environment: 'sandbox' });

const drawerProcessing = computed(() => [
    tenantForm,
    memberForm,
    billingForm,
    domainForm,
    channexForm,
    pokForm,
    fatureForm,
].some((form) => form.processing));

const ACTION_LABELS = {
    'tenant.create': 'Hoteli u krijua',
    'tenant.update': 'Të dhënat e hotelit u përditësuan',
    'tenant.switch': 'Hyrje në hotel',
    'tenant.subscription.update': 'Abonimi u përditësua',
    'tenant.integration.update': 'Integrimi u përditësua',
    'tenant.integration.test': 'Lidhja e integrimit u testua',
    'tenant.domain.create': 'Domain-i u shtua',
    'tenant.domain.delete': 'Domain-i u hoq',
    'tenant.domain.primary': 'Domain-i primar u ndryshua',
    'tenant.member.create': 'Përdoruesi u shtua',
    'tenant.member.update': 'Përdoruesi u përditësua',
    'tenant.status': 'Statusi i hotelit u ndryshua',
};

function initials(name = '') {
    return name.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
}

function money(cents, currency = props.tenant.currency || 'EUR') {
    return new Intl.NumberFormat('sq-AL', {
        style: 'currency', currency, maximumFractionDigits: 0,
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
        trialing: 'Provë', active: 'Aktiv', past_due: 'Me vonesë',
        suspended: 'Pezulluar', canceled: 'Anuluar', inactive: 'Joaktiv',
    }[status] || status;
}

function currencyLabel(code) {
    try {
        return `${code} — ${new Intl.DisplayNames(['sq'], { type: 'currency' }).of(code)}`;
    } catch {
        return code;
    }
}

function roleLabel(role) {
    return {
        admin: 'Administrator', manager: 'Menaxher', receptionist: 'Recepsionist',
        housekeeping: 'Housekeeping', maintenance: 'Mirëmbajtje', pos_staff: 'Staf POS',
    }[role] || role;
}

function openTenantForm() {
    tenantForm.name = props.tenant.name;
    tenantForm.slug = props.tenant.slug;
    tenantForm.timezone = props.tenant.timezone;
    tenantForm.currency = props.tenant.currency;
    tenantForm.clearErrors();
    activeDrawer.value = 'tenant';
}

function saveTenant() {
    tenantForm.patch(route('super-admin.tenants.update', props.tenant.id), {
        preserveScroll: true,
        onSuccess: closeDrawer,
    });
}

function openMember(member = null) {
    editingMember.value = member;
    memberForm.name = member?.name || '';
    memberForm.email = member?.email || '';
    memberForm.role = member?.role || 'manager';
    memberForm.is_active = member?.is_active ?? true;
    memberForm.clearErrors();
    activeDrawer.value = 'member';
}

function saveMember() {
    const options = { preserveScroll: true, onSuccess: closeDrawer };
    if (editingMember.value) {
        memberForm.put(route('super-admin.tenants.members.update', [props.tenant.id, editingMember.value.id]), options);
        return;
    }
    memberForm.post(route('super-admin.tenants.members.store', props.tenant.id), options);
}

function openBilling() {
    const billing = props.tenant.billing;
    billingForm.status = billing.status;
    billingForm.billing_cycle = billing.billing_cycle;
    billingForm.current_period_ends_at = billing.current_period_ends_at || '';
    billingForm.notes = billing.notes || '';
    billingForm.modules = Object.fromEntries(
        Object.entries(billing.modules).map(([code, module]) => [
            code,
            { enabled: module.enabled, quantity: module.quantity },
        ]),
    );
    billingForm.clearErrors();
    activeDrawer.value = 'billing';
}

function saveBilling() {
    billingForm.put(route('super-admin.tenants.subscription.update', props.tenant.id), {
        preserveScroll: true,
        onSuccess: closeDrawer,
    });
}

function openConfig(tab = 'domains') {
    configTab.value = tab;
    domainForm.reset();
    domainForm.clearErrors();

    channexForm.enabled = props.tenant.integrations.channex.enabled;
    channexForm.api_key = '';
    channexForm.webhook_secret = '';
    channexForm.property_id = props.tenant.integrations.channex.property_id || '';
    channexForm.base_url = props.tenant.integrations.channex.base_url || '';
    channexForm.clearErrors();

    pokForm.enabled = props.tenant.integrations.pok.enabled;
    pokForm.key_id = '';
    pokForm.key_secret = '';
    pokForm.merchant_id = props.tenant.integrations.pok.merchant_id || '';
    pokForm.production = props.tenant.integrations.pok.production;
    pokForm.clearErrors();

    fatureForm.enabled = props.tenant.integrations.fature_al.enabled;
    fatureForm.api_token = '';
    fatureForm.environment = props.tenant.integrations.fature_al.environment || 'sandbox';
    fatureForm.clearErrors();
    activeDrawer.value = 'config';
}

function addDomain() {
    domainForm.post(route('super-admin.tenants.domains.store', props.tenant.id), {
        preserveScroll: true,
        onSuccess: () => domainForm.reset(),
    });
}

function removeDomain(domain) {
    router.delete(route('super-admin.tenants.domains.destroy', [props.tenant.id, domain.id]), { preserveScroll: true });
}

function makePrimary(domain) {
    router.patch(route('super-admin.tenants.domains.primary', [props.tenant.id, domain.id]), {}, { preserveScroll: true });
}

function saveConfig() {
    const options = { preserveScroll: true, onSuccess: closeDrawer };
    if (configTab.value === 'channex') {
        channexForm.put(route('super-admin.tenants.integrations.update', [props.tenant.id, 'channex']), options);
    } else if (configTab.value === 'pok') {
        pokForm.put(route('super-admin.tenants.integrations.update', [props.tenant.id, 'pok']), options);
    } else if (configTab.value === 'fature') {
        fatureForm.put(route('super-admin.tenants.integrations.update', [props.tenant.id, 'fature_al']), options);
    }
}

function testFature() {
    router.post(route('super-admin.tenants.integrations.test', [props.tenant.id, 'fature_al']), {}, { preserveScroll: true });
}

function closeDrawer() {
    if (!drawerProcessing.value) {
        activeDrawer.value = null;
        editingMember.value = null;
    }
}

function openHotel() {
    if (!isActive.value || isCurrent.value) return;
    router.post(route('super-admin.tenants.switch', props.tenant.id));
}

function toggleStatus() {
    const suspend = isActive.value;
    if (!confirm(suspend ? `Ta pezullojmë ${props.tenant.name}?` : `Ta aktivizojmë ${props.tenant.name}?`)) return;
    router.patch(route('super-admin.tenants.status', props.tenant.id), {
        status: suspend ? 'suspended' : 'active',
    }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`${tenant.name} — Lora Control Panel`" />

    <SuperAdminLayout :title="`${tenant.name} — Lora Control Panel`">
        <main class="mx-auto max-w-[1320px] space-y-3">
            <nav class="text-[11px] text-neutral-400">
                <Link href="/super-admin" class="no-underline hover:text-neutral-700">Control Panel</Link>
                <span class="mx-2">/</span>
                <Link href="/super-admin/tenants" class="no-underline hover:text-neutral-700">Hotelet</Link>
                <span class="mx-2">/</span>
                <span class="font-medium text-neutral-600">{{ tenant.name }}</span>
            </nav>

            <header class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-[#e5f5ef] text-sm font-bold text-[#165d4b]">{{ initials(tenant.name) }}</span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="truncate text-2xl font-semibold tracking-tight text-neutral-950">{{ tenant.name }}</h1>
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-1 text-[10px] font-bold" :class="isActive ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'">
                                <span class="h-1.5 w-1.5 rounded-full bg-current" />{{ isActive ? 'Aktiv' : 'Pezulluar' }}
                            </span>
                        </div>
                        <p class="mt-1 truncate text-xs text-neutral-500">{{ tenant.slug }} · {{ tenant.timezone }} · {{ tenant.currency }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 sm:flex-nowrap">
                    <Button variant="outline" :class="isActive ? '!text-red-600' : '!text-emerald-700'" @click="toggleStatus">
                        {{ isActive ? 'Pezullo' : 'Aktivizo' }}
                    </Button>
                    <Button variant="outline" @click="openTenantForm"><Pencil class="h-4 w-4" /> Ndrysho</Button>
                    <Button variant="primary" :disabled="!isActive || isCurrent" @click="openHotel">
                        {{ isCurrent ? 'Në përdorim' : 'Hap hotelin' }} <ArrowRight class="h-4 w-4" />
                    </Button>
                </div>
            </header>

            <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm shadow-neutral-200/30">
                <div class="grid sm:grid-cols-2 xl:grid-cols-[1.35fr_repeat(4,.55fr)]">
                    <div class="flex items-center gap-3 border-b border-neutral-100 p-4 sm:col-span-2 xl:col-span-1 xl:border-b-0">
                        <span class="relative grid h-11 w-11 shrink-0 place-items-center rounded-full" :style="readinessRing">
                            <span class="absolute inset-[5px] rounded-full bg-white" />
                            <strong class="relative text-[10px]">{{ readinessScore }}%</strong>
                        </span>
                        <div><strong class="text-sm text-neutral-900">{{ attentionCount ? 'Konfigurimi kërkon vëmendje' : 'Hoteli është gati' }}</strong><p class="mt-0.5 text-[11px] text-neutral-500">{{ attentionCount ? `${attentionCount} pika duhen plotësuar.` : 'Të gjitha kontrollet janë në rregull.' }}</p></div>
                    </div>
                    <div class="border-b border-l border-neutral-100 p-4 sm:border-b-0"><span class="text-[10px] text-neutral-400">Domain primar</span><strong class="mt-1.5 block truncate text-xs text-neutral-900">{{ tenant.primary_domain || 'Mungon' }}</strong></div>
                    <div class="border-b border-l border-neutral-100 p-4 sm:border-b-0"><span class="text-[10px] text-neutral-400">Abonimi</span><strong class="mt-1.5 block text-xs text-neutral-900">{{ statusLabel(tenant.billing.status) }} · {{ tenant.billing.billing_cycle === 'annual' ? 'vjetor' : 'mujor' }}</strong></div>
                    <div class="border-l border-neutral-100 p-4"><span class="text-[10px] text-neutral-400">Përdorues</span><strong class="mt-1.5 block text-xs text-neutral-900">{{ activeMembers.length }} aktivë</strong></div>
                    <div class="border-l border-neutral-100 p-4"><span class="text-[10px] text-neutral-400">MRR</span><strong class="mt-1.5 block text-xs text-neutral-900">{{ money(tenant.mrr_cents) }} / muaj</strong></div>
                </div>
            </section>

            <div class="flex h-11 items-end gap-1 overflow-x-auto border-b border-neutral-200">
                <button type="button" class="h-11 shrink-0 border-b-2 border-[#1d765f] px-3 text-xs font-semibold text-[#104c3d]">Përmbledhje</button>
                <a href="#members" class="grid h-11 shrink-0 place-items-center border-b-2 border-transparent px-3 text-xs font-semibold text-neutral-500 no-underline hover:text-neutral-800">Përdoruesit</a>
                <button type="button" class="h-11 shrink-0 border-b-2 border-transparent px-3 text-xs font-semibold text-neutral-500 hover:text-neutral-800" @click="openBilling">Abonimi</button>
                <button type="button" class="h-11 shrink-0 border-b-2 border-transparent px-3 text-xs font-semibold text-neutral-500 hover:text-neutral-800" @click="openConfig('domains')">Konfigurimi</button>
                <Link :href="route('super-admin.activity', { tenant: tenant.id, range: 30 })" class="grid h-11 shrink-0 place-items-center border-b-2 border-transparent px-3 text-xs font-semibold text-neutral-500 no-underline hover:text-neutral-800">Aktiviteti</Link>
            </div>

            <div class="grid items-start gap-3 xl:grid-cols-[minmax(0,1.65fr)_minmax(300px,.72fr)]">
                <div class="space-y-3">
                    <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm shadow-neutral-200/30">
                        <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3.5">
                            <div><h2 class="text-sm font-semibold text-neutral-900">Gjendja e konfigurimit</h2><p class="mt-0.5 text-[11px] text-neutral-500">Kontrollet që ndikojnë përdorimin real të hotelit.</p></div>
                            <span class="rounded-full bg-amber-50 px-2.5 py-1 text-[10px] font-bold text-amber-700">{{ attentionCount }} kërkojnë veprim</span>
                        </div>
                        <div class="divide-y divide-neutral-100">
                            <div class="grid gap-3 px-4 py-3 sm:grid-cols-[36px_minmax(0,1fr)_auto] sm:items-center">
                                <span class="grid h-9 w-9 place-items-center rounded-xl bg-emerald-50 text-emerald-700"><Globe2 class="h-4 w-4" /></span>
                                <div><strong class="text-xs text-neutral-900">Domain primar</strong><p class="mt-0.5 text-[10px] text-neutral-500">{{ tenant.primary_domain || 'Nuk është konfiguruar' }}</p></div>
                                <div class="flex items-center gap-2 pl-12 sm:pl-0"><span class="text-[10px] font-bold" :class="tenant.primary_domain ? 'text-emerald-700' : 'text-amber-700'">{{ tenant.primary_domain ? 'Në rregull' : 'Mungon' }}</span><Button size="sm" variant="outline" @click="openConfig('domains')">Menaxho</Button></div>
                            </div>
                            <div class="grid gap-3 px-4 py-3 sm:grid-cols-[36px_minmax(0,1fr)_auto] sm:items-center">
                                <span class="grid h-9 w-9 place-items-center rounded-xl bg-emerald-50 text-emerald-700"><CreditCard class="h-4 w-4" /></span>
                                <div><strong class="text-xs text-neutral-900">Abonimi dhe modulet</strong><p class="mt-0.5 text-[10px] text-neutral-500">{{ enabledModules.length }} module aktive · rinovim {{ date(tenant.billing.current_period_ends_at) }}</p></div>
                                <div class="flex items-center gap-2 pl-12 sm:pl-0"><span class="text-[10px] font-bold" :class="billingIsHealthy ? 'text-emerald-700' : 'text-amber-700'">{{ statusLabel(tenant.billing.status) }}</span><Button size="sm" variant="outline" @click="openBilling">Ndrysho</Button></div>
                            </div>
                            <div class="grid gap-3 px-4 py-3 sm:grid-cols-[36px_minmax(0,1fr)_auto] sm:items-center">
                                <span class="grid h-9 w-9 place-items-center rounded-xl" :class="channexConfigured ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'"><Plug class="h-4 w-4" /></span>
                                <div><strong class="text-xs text-neutral-900">Channex Channel Manager</strong><p class="mt-0.5 text-[10px] text-neutral-500">{{ channexConfigured ? 'Kredencialet dhe Property ID janë ruajtur' : 'Property ID ose API key mungon' }}</p></div>
                                <div class="flex items-center gap-2 pl-12 sm:pl-0"><span class="text-[10px] font-bold" :class="channexConfigured ? 'text-emerald-700' : 'text-amber-700'">{{ channexConfigured ? 'Aktiv' : 'Mungon' }}</span><Button size="sm" variant="outline" @click="openConfig('channex')">Konfiguro</Button></div>
                            </div>
                            <div class="grid gap-3 px-4 py-3 sm:grid-cols-[36px_minmax(0,1fr)_auto] sm:items-center">
                                <span class="grid h-9 w-9 place-items-center rounded-xl" :class="pokConfigured ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'"><CreditCard class="h-4 w-4" /></span>
                                <div><strong class="text-xs text-neutral-900">POK Payments</strong><p class="mt-0.5 text-[10px] text-neutral-500">{{ pokConfigured ? 'Pagesat online janë konfiguruar' : 'Pagesat online nuk janë aktivizuar' }}</p></div>
                                <div class="flex items-center gap-2 pl-12 sm:pl-0"><span class="text-[10px] font-bold" :class="pokConfigured ? 'text-emerald-700' : 'text-amber-700'">{{ pokConfigured ? 'Aktiv' : 'Mungon' }}</span><Button size="sm" variant="outline" @click="openConfig('pok')">Konfiguro</Button></div>
                            </div>
                            <div class="grid gap-3 px-4 py-3 sm:grid-cols-[36px_minmax(0,1fr)_auto] sm:items-center">
                                <span class="grid h-9 w-9 place-items-center rounded-xl" :class="fatureConfigured ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'"><FileCheck2 class="h-4 w-4" /></span>
                                <div><strong class="text-xs text-neutral-900">fature.al</strong><p class="mt-0.5 text-[10px] text-neutral-500">{{ fatureConfigured ? `${tenant.integrations.fature_al.environment} · token i ruajtur` : 'Fiskalizimi nuk është konfiguruar' }}</p></div>
                                <div class="flex items-center gap-2 pl-12 sm:pl-0"><span class="text-[10px] font-bold" :class="fatureConfigured ? 'text-emerald-700' : 'text-amber-700'">{{ fatureConfigured ? tenant.integrations.fature_al.environment : 'Mungon' }}</span><Button size="sm" variant="outline" @click="openConfig('fature')">Menaxho</Button></div>
                            </div>
                        </div>
                    </section>

                    <section id="members" class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm shadow-neutral-200/30">
                        <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3.5">
                            <div><h2 class="text-sm font-semibold text-neutral-900">Përdoruesit</h2><p class="mt-0.5 text-[11px] text-neutral-500">Pronari dhe ekipi me akses në këtë hotel.</p></div>
                            <Button size="sm" variant="outline" @click="openMember()"><Plus class="h-4 w-4" /> Shto përdorues</Button>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[620px] text-left">
                                <thead><tr class="bg-neutral-50/70 text-[9px] uppercase tracking-[.08em] text-neutral-400"><th class="px-4 py-2.5 font-bold">Përdoruesi</th><th class="px-4 py-2.5 font-bold">Roli</th><th class="px-4 py-2.5 font-bold">Statusi</th><th class="px-4 py-2.5" /></tr></thead>
                                <tbody class="divide-y divide-neutral-100">
                                    <tr v-for="member in members" :key="member.id">
                                        <td class="px-4 py-3"><div class="flex items-center gap-2.5"><span class="grid h-8 w-8 place-items-center rounded-full bg-blue-50 text-[10px] font-bold text-blue-700">{{ initials(member.name) }}</span><div><strong class="block text-xs text-neutral-900">{{ member.name }}</strong><span class="mt-0.5 block text-[10px] text-neutral-500">{{ member.email }}{{ member.is_owner ? ' · pronar' : '' }}</span></div></div></td>
                                        <td class="px-4 py-3"><span class="rounded-lg bg-neutral-100 px-2 py-1 text-[10px] font-semibold text-neutral-600">{{ roleLabel(member.role) }}</span></td>
                                        <td class="px-4 py-3"><span class="inline-flex items-center gap-1.5 rounded-full px-2 py-1 text-[10px] font-bold" :class="member.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'"><span class="h-1.5 w-1.5 rounded-full bg-current" />{{ member.is_active ? 'Aktiv' : 'Joaktiv' }}</span></td>
                                        <td class="px-4 py-3 text-right"><Button size="sm" variant="outline" @click="openMember(member)">Ndrysho</Button></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm shadow-neutral-200/30">
                        <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3.5"><div><h2 class="text-sm font-semibold text-neutral-900">Modulet aktive</h2><p class="mt-0.5 text-[11px] text-neutral-500">Funksionalitetet e përfshira në abonim.</p></div><Button size="sm" variant="outline" @click="openBilling">Menaxho</Button></div>
                        <div class="flex flex-wrap gap-2 p-4"><span v-for="module in enabledModules" :key="module.code" class="rounded-lg border border-neutral-200 bg-neutral-50 px-2.5 py-1.5 text-[10px] font-semibold text-neutral-600">{{ module.name }}</span></div>
                    </section>
                </div>

                <aside class="space-y-3">
                    <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm shadow-neutral-200/30">
                        <div class="bg-gradient-to-br from-emerald-50 to-white p-4"><p class="text-[9px] font-bold uppercase tracking-[.12em] text-neutral-500">Abonimi mujor</p><p class="mt-1 text-3xl font-bold tracking-tight text-neutral-950">{{ money(tenant.mrr_cents) }} <small class="text-[11px] font-medium text-neutral-500">/ muaj</small></p></div>
                        <div class="divide-y divide-neutral-100 px-4 text-[11px]"><div class="flex justify-between gap-3 py-3"><span class="text-neutral-500">Statusi</span><strong class="text-emerald-700">{{ statusLabel(tenant.billing.status) }}</strong></div><div class="flex justify-between gap-3 py-3"><span class="text-neutral-500">Rinovimi</span><strong>{{ date(tenant.billing.current_period_ends_at) }}</strong></div><div class="flex justify-between gap-3 py-3"><span class="text-neutral-500">Modulet</span><strong>{{ enabledModules.length }} aktive</strong></div></div>
                        <div class="border-t border-neutral-100 p-3"><Button variant="outline" class="w-full" @click="openBilling"><CreditCard class="h-4 w-4" /> Menaxho abonimin</Button></div>
                    </section>

                    <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm shadow-neutral-200/30">
                        <div class="flex items-center justify-between border-b border-neutral-200 px-4 py-3.5"><div><h2 class="text-sm font-semibold text-neutral-900">Detaje teknike</h2><p class="mt-0.5 text-[10px] text-neutral-500">Konfigurimi bazë i tenantit.</p></div><Button size="sm" variant="outline" @click="openTenantForm">Ndrysho</Button></div>
                        <div class="divide-y divide-neutral-100 px-4 text-[11px]"><div class="flex justify-between gap-3 py-3"><span class="text-neutral-500">Tenant ID</span><strong>#{{ tenant.id }}</strong></div><div class="flex justify-between gap-3 py-3"><span class="text-neutral-500">Slug</span><strong class="max-w-[180px] truncate">{{ tenant.slug }}</strong></div><div class="flex justify-between gap-3 py-3"><span class="text-neutral-500">Timezone</span><strong>{{ tenant.timezone }}</strong></div><div class="flex justify-between gap-3 py-3"><span class="text-neutral-500">Monedha</span><strong>{{ tenant.currency }}</strong></div><div class="flex justify-between gap-3 py-3"><span class="text-neutral-500">Krijuar</span><strong>{{ date(tenant.created_at) }}</strong></div></div>
                    </section>

                    <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm shadow-neutral-200/30">
                        <div class="flex items-center justify-between border-b border-neutral-200 px-4 py-3.5"><div><h2 class="text-sm font-semibold text-neutral-900">Aktiviteti i fundit</h2><p class="mt-0.5 text-[10px] text-neutral-500">Veprimet e rëndësishme.</p></div><Link :href="route('super-admin.activity', { tenant: tenant.id, range: 30 })" class="text-[10px] font-bold text-emerald-700 no-underline">Të gjitha →</Link></div>
                        <ul class="divide-y divide-neutral-100 px-4"><li v-for="log in activity.slice(0, 4)" :key="log.id" class="grid grid-cols-[30px_minmax(0,1fr)_auto] items-center gap-2.5 py-3"><span class="grid h-8 w-8 place-items-center rounded-lg bg-emerald-50 text-emerald-700"><LogIn v-if="log.action === 'tenant.switch'" class="h-4 w-4" /><CreditCard v-else-if="log.action === 'tenant.subscription.update'" class="h-4 w-4" /><Check v-else class="h-4 w-4" /></span><div class="min-w-0"><strong class="block truncate text-[11px] text-neutral-800">{{ ACTION_LABELS[log.action] || log.action }}</strong><span class="mt-0.5 block truncate text-[9px] text-neutral-500">{{ log.actor }}</span></div><time class="text-[9px] text-neutral-400">{{ when(log.created_at) }}</time></li></ul>
                    </section>
                </aside>
            </div>
        </main>

        <Teleport to="body">
            <div v-if="activeDrawer" class="fixed inset-0 z-50 bg-neutral-950/45 backdrop-blur-[2px]" @click.self="closeDrawer">
                <aside class="ml-auto flex h-full w-full flex-col bg-white shadow-2xl" :class="activeDrawer === 'billing' ? 'max-w-[920px]' : 'max-w-[760px]'">
                    <header class="flex min-h-[70px] items-center justify-between gap-4 border-b border-neutral-200 px-5 py-3.5">
                        <div class="flex items-center gap-3">
                            <span class="grid h-10 w-10 place-items-center rounded-xl bg-emerald-50 text-emerald-700">
                                <Building2 v-if="activeDrawer === 'tenant'" class="h-5 w-5" />
                                <UserRound v-else-if="activeDrawer === 'member'" class="h-5 w-5" />
                                <CreditCard v-else-if="activeDrawer === 'billing'" class="h-5 w-5" />
                                <Settings2 v-else class="h-5 w-5" />
                            </span>
                            <div><h2 class="text-sm font-bold text-neutral-900">{{ activeDrawer === 'tenant' ? 'Të dhënat e hotelit' : activeDrawer === 'member' ? (editingMember ? 'Ndrysho përdoruesin' : 'Shto përdorues') : activeDrawer === 'billing' ? 'Abonimi dhe modulet' : 'Konfigurimi i hotelit' }}</h2><p class="mt-0.5 text-[10px] text-neutral-500">{{ tenant.name }}</p></div>
                        </div>
                        <button type="button" class="rounded-xl p-2 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" aria-label="Mbyll" @click="closeDrawer"><X class="h-5 w-5" /></button>
                    </header>

                    <div v-if="activeDrawer === 'config'" class="flex shrink-0 gap-1 overflow-x-auto border-b border-neutral-200 bg-neutral-50 px-5 pt-2">
                        <button v-for="tab in [{ id: 'domains', label: 'Domain-et' }, { id: 'channex', label: 'Channex' }, { id: 'pok', label: 'POK' }, { id: 'fature', label: 'fature.al' }]" :key="tab.id" type="button" class="h-10 shrink-0 border-b-2 px-3 text-[11px] font-bold" :class="configTab === tab.id ? 'border-emerald-700 text-emerald-800' : 'border-transparent text-neutral-500'" @click="configTab = tab.id">{{ tab.label }}</button>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto p-5">
                        <form v-if="activeDrawer === 'tenant'" id="tenant-form" class="space-y-4" @submit.prevent="saveTenant">
                            <section class="rounded-xl border border-neutral-200 p-4"><div class="mb-4 flex items-start gap-2.5"><Building2 class="mt-0.5 h-4 w-4 text-emerald-700" /><div><strong class="text-xs text-neutral-900">Identiteti i hotelit</strong><p class="mt-0.5 text-[10px] text-neutral-500">Emri dhe identifikuesi teknik.</p></div></div><div class="grid gap-4 sm:grid-cols-2"><label class="text-[11px] font-semibold text-neutral-600">Emri i hotelit<input v-model="tenantForm.name" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" required /></label><label class="text-[11px] font-semibold text-neutral-600">Slug<input v-model="tenantForm.slug" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" required /></label></div><p v-if="tenantForm.errors.name || tenantForm.errors.slug" class="mt-2 text-xs text-red-600">{{ tenantForm.errors.name || tenantForm.errors.slug }}</p></section>
                            <section class="rounded-xl border border-neutral-200 p-4"><div class="mb-4 flex items-start gap-2.5"><Globe2 class="mt-0.5 h-4 w-4 text-emerald-700" /><div><strong class="text-xs text-neutral-900">Lokalizimi</strong><p class="mt-0.5 text-[10px] text-neutral-500">Timezone dhe monedha bazë e hotelit.</p></div></div><div class="grid gap-4 sm:grid-cols-2"><label class="text-[11px] font-semibold text-neutral-600">Timezone<select v-model="tenantForm.timezone" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm"><optgroup v-for="(zones, region) in timezoneGroups" :key="region" :label="region"><option v-for="zone in zones" :key="zone.value" :value="zone.value">{{ zone.label }}</option></optgroup></select></label><label class="text-[11px] font-semibold text-neutral-600">Monedha<select v-model="tenantForm.currency" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm"><option v-for="code in currencyOptions" :key="code" :value="code">{{ currencyLabel(code) }}</option></select></label></div><p v-if="tenantForm.errors.timezone || tenantForm.errors.currency" class="mt-2 text-xs text-red-600">{{ tenantForm.errors.timezone || tenantForm.errors.currency }}</p></section>
                        </form>

                        <form v-else-if="activeDrawer === 'member'" id="member-form" class="space-y-4" @submit.prevent="saveMember">
                            <section class="rounded-xl border border-neutral-200 p-4"><div class="mb-4 flex items-start gap-2.5"><UserRound class="mt-0.5 h-4 w-4 text-emerald-700" /><div><strong class="text-xs text-neutral-900">Të dhënat e përdoruesit</strong><p class="mt-0.5 text-[10px] text-neutral-500">Llogaritë ekzistuese lidhen pa ndryshuar fjalëkalimin.</p></div></div><div class="grid gap-4 sm:grid-cols-2"><label class="text-[11px] font-semibold text-neutral-600">Emri i plotë<input v-model="memberForm.name" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" required /></label><label class="text-[11px] font-semibold text-neutral-600">Email<input v-model="memberForm.email" type="email" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" required /></label><label class="text-[11px] font-semibold text-neutral-600">Roli<select v-model="memberForm.role" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm"><option v-for="role in roleOptions" :key="role" :value="role">{{ roleLabel(role) }}</option></select></label><label class="text-[11px] font-semibold text-neutral-600">Statusi<select v-model="memberForm.is_active" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm"><option :value="true">Aktiv</option><option :value="false">Joaktiv</option></select></label></div><p v-if="Object.keys(memberForm.errors).length" class="mt-2 text-xs text-red-600">{{ Object.values(memberForm.errors)[0] }}</p></section>
                            <div class="rounded-xl border border-emerald-200 bg-emerald-50/70 p-4 text-xs text-emerald-900">Përdoruesi i ri vendos fjalëkalimin nga lidhja “Harrove fjalëkalimin?”.</div>
                        </form>

                        <form v-else-if="activeDrawer === 'billing'" id="billing-form" class="grid gap-4 lg:grid-cols-[240px_minmax(0,1fr)]" @submit.prevent="saveBilling">
                            <aside class="h-fit rounded-xl border border-neutral-200 bg-neutral-50 p-4"><p class="text-[9px] font-bold uppercase tracking-[.12em] text-neutral-500">Totali aktual</p><p class="mt-1 text-2xl font-bold">{{ money(tenant.mrr_cents) }} <small class="text-[10px] font-medium text-neutral-500">/ muaj</small></p><label class="mt-4 block text-[11px] font-semibold text-neutral-600">Statusi<select v-model="billingForm.status" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm"><option value="trialing">Provë</option><option value="active">Aktiv</option><option value="past_due">Me vonesë</option><option value="suspended">Pezulluar</option><option value="canceled">Anuluar</option></select></label><label class="mt-4 block text-[11px] font-semibold text-neutral-600">Cikli i faturimit<select v-model="billingForm.billing_cycle" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm"><option value="monthly">Mujor</option><option value="annual">Vjetor</option></select></label><label class="mt-4 block text-[11px] font-semibold text-neutral-600">Data e rinovimit<input v-model="billingForm.current_period_ends_at" type="date" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" /></label><label class="mt-4 block text-[11px] font-semibold text-neutral-600">Shënime<textarea v-model="billingForm.notes" rows="3" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" /></label></aside>
                            <section><h3 class="text-sm font-bold text-neutral-900">Modulet e abonimit</h3><p class="mt-1 text-[10px] text-neutral-500">Aktivizo vetëm funksionalitetet që hoteli përdor.</p><div class="mt-3 grid gap-2 sm:grid-cols-2"><label v-for="module in tenant.billing.modules" :key="module.code" class="rounded-xl border p-3" :class="billingForm.modules[module.code]?.enabled ? 'border-emerald-200 bg-emerald-50/70' : 'border-neutral-200 bg-white'"><div class="flex items-start gap-2"><input v-model="billingForm.modules[module.code].enabled" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-emerald-700 focus:ring-emerald-600" :disabled="module.locked" /><div><strong class="text-[11px] text-neutral-900">{{ module.name }}</strong><p class="mt-0.5 text-[9px] leading-4 text-neutral-500">{{ module.description }}</p></div></div><div v-if="['tiered_per_room', 'per_user', 'per_pos'].includes(module.billing_model)" class="mt-3 flex items-center justify-between border-t border-emerald-100 pt-2"><span class="text-[9px] text-neutral-500">{{ module.unit_label }}</span><input v-model.number="billingForm.modules[module.code].quantity" type="number" min="1" max="10000" class="w-20 rounded-lg border-neutral-300 py-1 text-right text-xs" /></div></label></div><p v-if="Object.keys(billingForm.errors).length" class="mt-3 text-xs text-red-600">{{ Object.values(billingForm.errors)[0] }}</p></section>
                        </form>

                        <div v-else-if="activeDrawer === 'config'">
                            <section v-if="configTab === 'domains'" class="space-y-4"><div class="flex items-start gap-2.5"><Globe2 class="mt-0.5 h-4 w-4 text-emerald-700" /><div><strong class="text-xs text-neutral-900">Domain-et e hotelit</strong><p class="mt-0.5 text-[10px] text-neutral-500">Domain primar dhe adresat alternative.</p></div></div><div class="overflow-hidden rounded-xl border border-neutral-200"><div v-for="domain in tenant.domains" :key="domain.id" class="grid grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-3 border-b border-neutral-100 px-3 py-3 last:border-b-0"><span class="grid h-9 w-9 place-items-center rounded-xl bg-emerald-50 text-emerald-700"><Globe2 class="h-4 w-4" /></span><div><strong class="block text-xs text-neutral-900">{{ domain.domain }}</strong><span class="mt-0.5 block text-[9px] text-neutral-500">{{ domain.is_primary ? 'Domain primar' : 'Domain alternativ' }}</span></div><div class="flex gap-2"><span v-if="domain.is_primary" class="rounded-lg bg-emerald-50 px-2 py-1 text-[9px] font-bold text-emerald-700">Primar</span><Button v-else size="sm" variant="outline" @click="makePrimary(domain)">Bëj primar</Button><Button v-if="!domain.is_primary" size="sm" variant="outline" class="!text-red-600" @click="removeDomain(domain)">Hiq</Button></div></div></div><form class="rounded-xl border border-neutral-200 p-4" @submit.prevent="addDomain"><label class="text-[11px] font-semibold text-neutral-600">Shto domain të ri<div class="mt-1.5 flex gap-2"><input v-model="domainForm.domain" placeholder="booking.hoteli.al" class="min-w-0 flex-1 rounded-xl border-neutral-300 text-sm" /><Button type="submit" variant="primary" :disabled="domainForm.processing">Shto</Button></div></label><p v-if="domainForm.errors.domain" class="mt-2 text-xs text-red-600">{{ domainForm.errors.domain }}</p><p class="mt-2 text-[9px] text-neutral-500">Pas shtimit duhet të konfigurohen DNS records.</p></form></section>

                            <section v-else-if="configTab === 'channex'" class="space-y-4"><div class="flex items-center justify-between gap-4 rounded-xl border border-neutral-200 bg-neutral-50 p-4"><div><strong class="text-xs text-neutral-900">Channex Channel Manager</strong><p class="mt-1 text-[10px] text-neutral-500">Sinkronizon OTA-t, rezervimet dhe disponibilitetin.</p></div><button type="button" class="relative h-6 w-11 rounded-full transition" :class="channexForm.enabled ? 'bg-emerald-700' : 'bg-neutral-300'" @click="channexForm.enabled = !channexForm.enabled"><span class="absolute top-1 h-4 w-4 rounded-full bg-white shadow transition" :class="channexForm.enabled ? 'left-6' : 'left-1'" /></button></div><div class="grid gap-4 rounded-xl border border-neutral-200 p-4 sm:grid-cols-2"><label class="text-[11px] font-semibold text-neutral-600">API key<input v-model="channexForm.api_key" type="password" :placeholder="tenant.integrations.channex.has_api_key ? '•••••••• (lëre bosh për ta mbajtur)' : 'Ngjit API key'" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" /></label><label class="text-[11px] font-semibold text-neutral-600">Webhook secret<input v-model="channexForm.webhook_secret" type="password" :placeholder="tenant.integrations.channex.has_webhook_secret ? '•••••••• (lëre bosh për ta mbajtur)' : 'Ngjit webhook secret'" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" /></label><label class="text-[11px] font-semibold text-neutral-600">Property ID<input v-model="channexForm.property_id" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" /></label><label class="text-[11px] font-semibold text-neutral-600">Base URL<input v-model="channexForm.base_url" placeholder="https://app.channex.io/api/v1" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" /></label><p v-if="Object.keys(channexForm.errors).length" class="text-xs text-red-600 sm:col-span-2">{{ Object.values(channexForm.errors)[0] }}</p></div></section>

                            <section v-else-if="configTab === 'pok'" class="space-y-4"><div class="flex items-center justify-between gap-4 rounded-xl border border-neutral-200 bg-neutral-50 p-4"><div><strong class="text-xs text-neutral-900">POK Payments</strong><p class="mt-1 text-[10px] text-neutral-500">Pagesa online për rezervime dhe link pagese.</p></div><button type="button" class="relative h-6 w-11 rounded-full transition" :class="pokForm.enabled ? 'bg-emerald-700' : 'bg-neutral-300'" @click="pokForm.enabled = !pokForm.enabled"><span class="absolute top-1 h-4 w-4 rounded-full bg-white shadow transition" :class="pokForm.enabled ? 'left-6' : 'left-1'" /></button></div><div class="grid gap-4 rounded-xl border border-neutral-200 p-4 sm:grid-cols-2"><label class="text-[11px] font-semibold text-neutral-600">Key ID<input v-model="pokForm.key_id" type="password" :placeholder="tenant.integrations.pok.has_key_id ? '•••••••• (lëre bosh për ta mbajtur)' : 'Ngjit Key ID'" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" /></label><label class="text-[11px] font-semibold text-neutral-600">Key secret<input v-model="pokForm.key_secret" type="password" :placeholder="tenant.integrations.pok.has_key_secret ? '•••••••• (lëre bosh për ta mbajtur)' : 'Ngjit Key secret'" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" /></label><label class="text-[11px] font-semibold text-neutral-600 sm:col-span-2">Merchant ID<input v-model="pokForm.merchant_id" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" /></label><label class="flex items-center gap-2 text-[11px] font-semibold text-neutral-600 sm:col-span-2"><input v-model="pokForm.production" type="checkbox" class="rounded border-neutral-300 text-emerald-700 focus:ring-emerald-600" /> Mjedisi production</label><p v-if="Object.keys(pokForm.errors).length" class="text-xs text-red-600 sm:col-span-2">{{ Object.values(pokForm.errors)[0] }}</p></div></section>

                            <section v-else class="space-y-4"><div class="flex items-center justify-between gap-4 rounded-xl border border-neutral-200 bg-neutral-50 p-4"><div><strong class="text-xs text-neutral-900">fature.al</strong><p class="mt-1 text-[10px] text-neutral-500">Fiskalizimi i faturave dhe pagesave të hotelit.</p></div><button type="button" class="relative h-6 w-11 rounded-full transition" :class="fatureForm.enabled ? 'bg-emerald-700' : 'bg-neutral-300'" @click="fatureForm.enabled = !fatureForm.enabled"><span class="absolute top-1 h-4 w-4 rounded-full bg-white shadow transition" :class="fatureForm.enabled ? 'left-6' : 'left-1'" /></button></div><div class="grid gap-4 rounded-xl border border-neutral-200 p-4 sm:grid-cols-2"><label class="text-[11px] font-semibold text-neutral-600">Mjedisi<select v-model="fatureForm.environment" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm"><option value="sandbox">Sandbox</option><option value="production">Production</option></select></label><label class="text-[11px] font-semibold text-neutral-600">API token<input v-model="fatureForm.api_token" type="password" :placeholder="tenant.integrations.fature_al.has_api_token ? '•••••••• (lëre bosh për ta mbajtur)' : 'Ngjit API token'" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" /></label><p v-if="Object.keys(fatureForm.errors).length" class="text-xs text-red-600 sm:col-span-2">{{ Object.values(fatureForm.errors)[0] }}</p></div><div class="flex items-center justify-between gap-4 rounded-xl border border-neutral-200 p-4"><div><strong class="text-xs text-neutral-900">Testi i lidhjes</strong><p class="mt-1 text-[10px] text-neutral-500">{{ tenant.integrations.fature_al.last_tested_at ? `${tenant.integrations.fature_al.last_test_status} · ${when(tenant.integrations.fature_al.last_tested_at)}` : 'Lidhja nuk është testuar ende.' }}</p></div><Button variant="outline" @click="testFature"><ExternalLink class="h-4 w-4" /> Testo lidhjen</Button></div></section>
                        </div>
                    </div>

                    <footer class="flex shrink-0 items-center justify-between gap-3 border-t border-neutral-200 bg-white px-5 py-3.5">
                        <p class="hidden text-[10px] text-neutral-500 sm:block">Ndryshimet regjistrohen në audit log.</p>
                        <div class="ml-auto flex gap-2"><Button variant="outline" :disabled="drawerProcessing" @click="closeDrawer">Anulo</Button><Button v-if="activeDrawer !== 'config' || configTab !== 'domains'" variant="primary" :disabled="drawerProcessing" @click="activeDrawer === 'tenant' ? saveTenant() : activeDrawer === 'member' ? saveMember() : activeDrawer === 'billing' ? saveBilling() : saveConfig()">{{ drawerProcessing ? 'Duke ruajtur…' : 'Ruaj ndryshimet' }}</Button></div>
                    </footer>
                </aside>
            </div>
        </Teleport>
    </SuperAdminLayout>
</template>
