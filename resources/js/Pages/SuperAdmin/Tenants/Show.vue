<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { translate } from '@/i18n';
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
    initialConfigTab: String,
});

const activeDrawer = ref(null);
const configTab = ref('domains');
const editingMember = ref(null);
// Dosja e hotelit ndahet në seksione (rail + panel) — jo karta të shpërndara.
const activeSection = ref('overview');

const isCurrent = computed(() => props.tenant.id === props.currentTenantId);
const isActive = computed(() => props.tenant.status === 'active');
const billingIsHealthy = computed(() => ['active', 'trialing'].includes(props.tenant.billing?.status));
const activeMembers = computed(() => props.members.filter((member) => member.is_active));
const enabledModules = computed(() => Object.values(props.tenant.billing?.modules || {}).filter((module) => module.enabled));

const euros = (cents) => {
    const value = (Number(cents) || 0) / 100;
    return `€${Number.isInteger(value) ? value : value.toFixed(2)}`;
};

// Catalog price line per billing model (fields shipped by TenantBillingService::summary).
const modulePriceLabel = (module) => {
    switch (module.billing_model) {
        case 'per_user':
            return translate('superAdmin.moduleList.pricePerUnit', { price: euros(module.unit_price_cents), unit: module.unit_label });
        case 'per_pos':
            return translate('superAdmin.moduleList.pricePos', { first: euros(module.first_unit_price_cents), extra: euros(module.unit_price_cents) });
        case 'tiered_per_room':
            return translate('superAdmin.moduleList.priceTiered', { price: euros(module.unit_price_cents), unit: module.unit_label, limit: module.tier_limit || 0, excess: euros(module.excess_unit_price_cents) });
        case 'percentage':
            return translate('superAdmin.moduleList.pricePercentage', { percent: (module.percentage_bps || 0) / 100, unit: module.unit_label });
        default:
            return translate('superAdmin.moduleList.priceFlat', { price: euros(module.unit_price_cents) });
    }
};

// Live monthly cost while editing — follows the form's toggle + quantity, not the saved snapshot.
const moduleLiveMonthly = (module) => {
    const form = billingForm.modules[module.code];
    if (!form?.enabled) return null;
    const qty = Math.max(1, Number(form.quantity) || 1);
    switch (module.billing_model) {
        case 'per_user':
            return (module.unit_price_cents || 0) * qty;
        case 'per_pos':
            return (module.first_unit_price_cents || 0) + (qty - 1) * (module.unit_price_cents || 0);
        case 'tiered_per_room': {
            const limit = module.tier_limit ?? Infinity;
            return Math.min(qty, limit) * (module.unit_price_cents || 0)
                + Math.max(qty - limit, 0) * (module.excess_unit_price_cents || 0);
        }
        case 'percentage':
            return null;
        default:
            return module.unit_price_cents || 0;
    }
};
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

const pendingDomains = computed(() => props.tenant.domains.filter((domain) => domain.status !== 'active').length);
// I njëjti burim si integrationRows — rail-i raporton numrin REAL të
// çështjeve, jo gjithmonë "1" (gjetje Codex PR #583).
const integrationIssues = computed(() => [
    Boolean(props.tenant.primary_domain) && pendingDomains.value === 0,
    channexConfigured.value,
    pokConfigured.value,
    fatureConfigured.value,
].filter((ok) => !ok).length);
const integrationsOk = computed(() => integrationIssues.value === 0);

// Rail-i i seksioneve — pika + meta tregojnë me një shikim ku duhet vëmendje.
const sections = computed(() => [
    {
        id: 'overview',
        label: translate('superAdmin.auto.copy120'),
        meta: attentionCount.value
            ? translate('superAdmin.tenantShow.issuesAttention', { count: attentionCount.value })
            : translate('superAdmin.tenantShow.allGood'),
        warn: attentionCount.value > 0,
        dot: attentionCount.value ? 'amber' : 'green',
    },
    {
        id: 'billing',
        label: translate('superAdmin.tenantShow.subscriptionAndModules'),
        meta: `${money(props.tenant.mrr_cents)}${translate('superAdmin.tenantShow.perMonthSuffix')} · ${translate('superAdmin.tenantShow.activeCountShort', { count: enabledModules.value.length })}`,
        warn: !billingIsHealthy.value,
        dot: billingIsHealthy.value ? 'green' : 'amber',
    },
    {
        id: 'integrations',
        label: translate('superAdmin.tenantShow.railIntegrations'),
        meta: integrationsOk.value
            ? translate('superAdmin.tenantShow.integrationsAllActive')
            : translate('superAdmin.tenantShow.issuesAttention', { count: integrationIssues.value }),
        warn: !integrationsOk.value,
        dot: integrationsOk.value ? 'green' : 'amber',
    },
    {
        id: 'members',
        label: translate('superAdmin.auto.copy093'),
        meta: translate('superAdmin.tenantShow.membersActiveShort', { count: activeMembers.value.length }),
        warn: activeMembers.value.length === 0,
        dot: activeMembers.value.length ? 'green' : 'amber',
    },
    {
        id: 'activity',
        label: translate('superAdmin.auto.copy107'),
        meta: props.activity.length ? when(props.activity[0].created_at) : '—',
        warn: false,
        dot: '',
    },
]);

// Kontrollet e gatishmërisë — rreshta me NJË veprim: kërcim te seksioni përkatës.
const overviewChecks = computed(() => [
    { key: 'active', icon: Building2, ok: isActive.value, title: translate('superAdmin.tenantShow.hotelActiveCheck'), meta: isActive.value ? translate('superAdmin.tenantShow.hotelActiveNote') : translate('superAdmin.tenantShow.hotelSuspendedNote'), state: isActive.value ? translate('superAdmin.auto.copy005') : translate('superAdmin.auto.copy044'), goto: null },
    { key: 'billing', icon: CreditCard, ok: billingIsHealthy.value, title: translate('superAdmin.tenantShow.subscriptionAndModules'), meta: translate('superAdmin.tenantShow.modulesAndNextBilling', { count: enabledModules.value.length, date: date(props.tenant.billing.next_billing_at) }), state: statusLabel(props.tenant.billing.status), goto: 'billing' },
    { key: 'domain', icon: Globe2, ok: Boolean(props.tenant.primary_domain), title: translate('superAdmin.auto.copy012'), meta: props.tenant.primary_domain || translate('superAdmin.tenantShow.notConfigured'), state: props.tenant.primary_domain ? translate('superAdmin.auto.copy005') : translate('superAdmin.dynamic.missing'), goto: 'integrations' },
    { key: 'channex', icon: Plug, ok: channexConfigured.value, title: 'Channex Channel Manager', meta: channexConfigured.value ? translate('superAdmin.tenantShow.channexOk') : translate('superAdmin.tenantShow.channexMissing'), state: channexConfigured.value ? translate('superAdmin.auto.copy005') : translate('superAdmin.dynamic.missing'), goto: 'integrations' },
    { key: 'pok', icon: CreditCard, ok: pokConfigured.value, title: 'POK Payments', meta: pokConfigured.value ? translate('superAdmin.tenantShow.pokOk') : translate('superAdmin.tenantShow.pokMissing'), state: pokConfigured.value ? translate('superAdmin.auto.copy005') : translate('superAdmin.dynamic.missing'), goto: 'integrations' },
    { key: 'members', icon: UserRound, ok: activeMembers.value.length > 0, title: translate('superAdmin.auto.copy093'), meta: translate('superAdmin.tenantShow.membersSubtitle'), state: translate('superAdmin.tenantShow.membersActiveShort', { count: activeMembers.value.length }), goto: 'members' },
]);

const integrationRows = computed(() => [
    { key: 'domains', icon: Globe2, ok: Boolean(props.tenant.primary_domain) && pendingDomains.value === 0, title: translate('superAdmin.dynamic.domains'), meta: (props.tenant.primary_domain || translate('superAdmin.tenantShow.notConfigured')) + (pendingDomains.value ? ` · ${translate('superAdmin.tenantShow.domainsPending', { count: pendingDomains.value })}` : ''), state: pendingDomains.value ? translate('superAdmin.tenantShow.domainsPending', { count: pendingDomains.value }) : (props.tenant.primary_domain ? translate('superAdmin.auto.copy005') : translate('superAdmin.dynamic.missing')), tab: 'domains', action: translate('superAdmin.tenantShow.manage') },
    { key: 'channex', icon: Plug, ok: channexConfigured.value, title: 'Channex Channel Manager', meta: channexConfigured.value ? translate('superAdmin.tenantShow.channexOk') : translate('superAdmin.tenantShow.channexMissing'), state: channexConfigured.value ? translate('superAdmin.auto.copy005') : translate('superAdmin.dynamic.missing'), tab: 'channex', action: translate('superAdmin.tenantShow.configure') },
    { key: 'pok', icon: CreditCard, ok: pokConfigured.value, title: 'POK Payments', meta: pokConfigured.value ? translate('superAdmin.tenantShow.pokOk') : translate('superAdmin.tenantShow.pokMissing'), state: pokConfigured.value ? translate('superAdmin.auto.copy005') : translate('superAdmin.dynamic.missing'), tab: 'pok', action: translate('superAdmin.tenantShow.configure') },
    { key: 'fature', icon: FileCheck2, ok: fatureConfigured.value, title: 'fature.al', meta: fatureConfigured.value ? translate('superAdmin.tenantShow.envTokenSaved', { environment: props.tenant.integrations.fature_al.environment }) : translate('superAdmin.tenantShow.fiscalNotConfigured'), state: fatureConfigured.value ? props.tenant.integrations.fature_al.environment : translate('superAdmin.dynamic.missing'), tab: 'fature', action: translate('superAdmin.tenantShow.manage') },
]);

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
    contract_years: 1,
    billing_currency: 'EUR',
    fx_rate_override: '',
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
    'tenant.create': translate('superAdmin.tenantShow.actionCreated'),
    'tenant.update': translate('superAdmin.tenantShow.actionUpdated'),
    'tenant.switch': translate('superAdmin.dynamic.actionHotelLogin'),
    'tenant.subscription.update': translate('superAdmin.dynamic.actionSubscriptionUpdated'),
    'tenant.integration.update': translate('superAdmin.tenantShow.actionIntegrationUpdated'),
    'tenant.integration.test': translate('superAdmin.tenantShow.actionIntegrationTested'),
    'tenant.domain.create': translate('superAdmin.tenantShow.actionDomainAdded'),
    'tenant.domain.delete': translate('superAdmin.tenantShow.actionDomainRemoved'),
    'tenant.domain.primary': translate('superAdmin.tenantShow.actionDomainPrimary'),
    'tenant.member.create': translate('superAdmin.activity.actionMemberCreated'),
    'tenant.member.update': translate('superAdmin.activity.actionMemberUpdated'),
    'tenant.status': translate('superAdmin.dynamic.actionHotelStatusUpdated'),
};

function initials(name = '') {
    return name.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
}

// Shumat e abonimit vijnë nga katalogu dhe janë GJITHMONË në euro. Monedha
// operative e hotelit (props.tenant.currency) s'ka të bëjë fare me to — dikur
// ishte default këtu dhe i shfaqte €266 si "ALL 266".
function money(cents, currency = 'EUR') {
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
        trialing: translate('superAdmin.auto.copy049'),
        active: translate('superAdmin.auto.copy005'),
        past_due: translate('superAdmin.tenantShow.statusPastDue'),
        suspended: translate('superAdmin.auto.copy044'),
        canceled: translate('superAdmin.auto.copy009'),
        inactive: translate('superAdmin.dynamic.inactive'),
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
        admin: translate('superAdmin.tenantShow.roleAdmin'),
        manager: translate('superAdmin.tenantShow.roleManager'),
        receptionist: translate('superAdmin.tenantShow.roleReceptionist'),
        housekeeping: translate('superAdmin.tenantShow.roleHousekeeping'),
        maintenance: translate('superAdmin.tenantShow.roleMaintenance'),
        pos_staff: translate('superAdmin.tenantShow.rolePosStaff'),
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
    billingForm.contract_years = billing.contract_years || 1;
    billingForm.billing_currency = billing.billing_currency || 'EUR';
    billingForm.fx_rate_override = billing.fx_rate_override ?? '';
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

onMounted(() => {
    if (props.initialConfigTab) {
        // Deep-link nga onboarding-u: hap drawer-in te tab-i i kërkuar dhe
        // vendos seksionin përkatës poshtë tij.
        activeSection.value = 'integrations';
        openConfig(props.initialConfigTab);
    }
});

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

// Domain lifecycle: verify DNS → provision on Forge → active.
const domainBusy = ref(null);

function domainAction(domain, routeName) {
    domainBusy.value = domain.id;
    router.post(route(routeName, [props.tenant.id, domain.id]), {}, {
        preserveScroll: true,
        onFinish: () => { domainBusy.value = null; },
    });
}

const domainStatusMeta = {
    pending_dns: { label: translate('superAdmin.tenantShow.statusPendingDns'), class: 'bg-amber-50 text-amber-700' },
    provisioning: { label: translate('superAdmin.tenantShow.statusProvisioning'), class: 'bg-sky-50 text-sky-700' },
    active: { label: translate('superAdmin.auto.copy005'), class: 'bg-emerald-50 text-emerald-700' },
    failed: { label: translate('superAdmin.activity.failed'), class: 'bg-red-50 text-red-700' },
};

function isPlatformSubdomain(domain) {
    return domain.domain.endsWith('.lorapms.com');
}

// Copy-paste DNS instructions, ready to forward to the hotel.
const copied = ref(null);

function copyText(key, text) {
    navigator.clipboard?.writeText(text).then(() => {
        copied.value = key;
        setTimeout(() => { if (copied.value === key) copied.value = null; }, 2000);
    });
}

function copyDnsInstructions() {
    const ip = props.tenant.domainServerIp;
    copyText('instructions', [
        translate('superAdmin.tenantShow.dnsCopyTitle'),
        '',
        translate('superAdmin.tenantShow.dnsCopyIntro'),
        '',
        translate('superAdmin.tenantShow.dnsCopyTableHead'),
        `A      @     ${ip}`,
        `A      www   ${ip}`,
        '',
        translate('superAdmin.tenantShow.dnsCopyWarning'),
        translate('superAdmin.tenantShow.dnsCopyPropagation'),
    ].join('\n'));
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
    if (!confirm(suspend
        ? translate('superAdmin.tenantShow.confirmSuspend', { name: props.tenant.name })
        : translate('superAdmin.tenantShow.confirmActivate', { name: props.tenant.name }))) return;
    router.patch(route('super-admin.tenants.status', props.tenant.id), {
        status: suspend ? 'suspended' : 'active',
    }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`${tenant.name} — Lora Control Panel`" />

    <SuperAdminLayout :title="`${tenant.name} — Lora Control Panel`" immersive>
        <!-- FOCUS MODE si Onboarding-u: dosja e një hoteli hapet më vete.
             Çdo fakt bazë del NJË herë (chrome) dhe rail-i i seksioneve është
             i vetmi shirit anësor — zhduken tabs e rreme dhe kartat dyfishe. -->
        <header class="sticky top-0 z-40 border-b border-neutral-200 bg-white/95 backdrop-blur">
            <div class="mx-auto flex max-w-[1200px] flex-wrap items-center gap-3 px-4 pt-3 sm:px-6">
                <Link href="/super-admin/tenants" class="whitespace-nowrap text-xs font-bold text-neutral-500 no-underline hover:text-emerald-700">← {{ $t('superAdmin.auto.copy087') }}</Link>
                <span class="h-6 w-px bg-neutral-200" />
                <span class="grid h-9 w-9 place-items-center rounded-[10px] bg-gradient-to-br from-emerald-100 to-emerald-200/80 text-[11px] font-bold text-emerald-900 ring-1 ring-inset ring-emerald-200/60">{{ initials(tenant.name) }}</span>
                <h1 class="text-[15px] font-semibold tracking-tight">{{ tenant.name }}</h1>
                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-bold ring-1 ring-inset" :class="isActive ? 'bg-emerald-50 text-emerald-700 ring-emerald-200/60' : 'bg-red-50 text-red-700 ring-red-200/60'"><span class="h-1.5 w-1.5 rounded-full bg-current" />{{ isActive ? $t('superAdmin.auto.copy005') : $t('superAdmin.auto.copy044') }}</span>
                <div class="ml-auto flex flex-wrap gap-2">
                    <button class="sa-button sa-button-secondary" :class="isActive ? '!text-red-600' : '!text-emerald-700'" @click="toggleStatus">{{ isActive ? $t('superAdmin.dynamic.suspend') : $t('superAdmin.dynamic.activate') }}</button>
                    <button class="sa-button sa-button-secondary" @click="openTenantForm"><Pencil class="h-4 w-4" />{{ $t('superAdmin.auto.copy089') }}</button>
                    <button class="sa-button sa-button-primary" :disabled="!isActive || isCurrent" @click="openHotel">{{ isCurrent ? $t('superAdmin.dynamic.inUse') : $t('superAdmin.dynamic.openHotel') }} <ArrowRight class="h-4 w-4" /></button>
                </div>
            </div>
            <div class="mx-auto flex max-w-[1200px] flex-wrap gap-1.5 px-4 pb-3 pt-2 sm:px-6">
                <span class="chrome-chip">Domain <b :class="tenant.primary_domain ? 'text-emerald-700' : 'text-amber-700'">{{ tenant.primary_domain || $t('superAdmin.dynamic.missing') }}</b></span>
                <span class="chrome-chip"><b>{{ tenant.currency }}</b> · {{ tenant.timezone }}</span>
                <span class="chrome-chip">Tenant <b>#{{ tenant.id }}</b> · {{ tenant.slug }}</span>
                <span class="chrome-chip">{{ $t('superAdmin.tenantShow.clientSince') }} <b>{{ date(tenant.created_at) }}</b></span>
            </div>
        </header>

        <div class="mx-auto max-w-[1200px] px-4 py-5 sm:px-6">
            <div class="grid items-start gap-3 lg:grid-cols-[264px_minmax(0,1fr)]">
                <aside class="sa-card self-start p-2 lg:sticky lg:top-[118px]">
                    <button v-for="section in sections" :key="section.id" class="grid w-full grid-cols-[1fr_auto] items-center gap-x-2.5 rounded-xl border p-3 text-left transition-all duration-200" :class="activeSection === section.id ? 'border-emerald-200 bg-emerald-50 shadow-sm shadow-emerald-900/5' : 'border-transparent hover:bg-neutral-50'" @click="activeSection = section.id">
                        <strong class="text-xs">{{ section.label }}</strong>
                        <span class="row-span-2 h-1.5 w-1.5 rounded-full" :class="section.dot === 'green' ? 'bg-emerald-500' : section.dot === 'amber' ? 'bg-amber-500' : 'bg-neutral-300'" />
                        <small class="col-start-1 mt-0.5 block text-[9.5px]" :class="section.warn ? 'font-bold text-amber-600' : 'text-neutral-400'">{{ section.meta }}</small>
                    </button>
                </aside>

                <!-- PËRMBLEDHJA: gatishmëria + kontrollet — gjendja me një shikim, veprimi te seksioni -->
                <section v-if="activeSection === 'overview'" class="sa-card overflow-hidden">
                    <div class="flex items-center gap-3.5 border-b border-neutral-200 p-4 sm:p-5">
                        <span class="relative grid h-[52px] w-[52px] shrink-0 place-items-center rounded-full" :style="readinessRing"><span class="absolute inset-[5px] rounded-full bg-white" /><strong class="relative text-[11px]">{{ readinessScore }}%</strong></span>
                        <div><strong class="text-[13px] text-neutral-900">{{ attentionCount ? $t('superAdmin.tenantShow.configNeedsAttention') : $t('superAdmin.tenantShow.hotelReady') }}</strong><p class="mt-0.5 text-[11px] text-neutral-500">{{ $t('superAdmin.tenantShow.checksSummary', { ok: readinessChecks.filter(Boolean).length, total: readinessChecks.length }) }}</p></div>
                    </div>
                    <div class="divide-y divide-neutral-100">
                        <!-- Në mobile veprimet zbresin nën tekst — prindi ka overflow-hidden
                             dhe një rresht i vetëm do t'i priste (gjetje Codex PR #583). -->
                        <div v-for="check in overviewChecks" :key="check.key" class="grid grid-cols-[34px_minmax(0,1fr)] items-center gap-3 px-4 py-3 hover:bg-neutral-50/60 sm:grid-cols-[34px_minmax(0,1fr)_auto] sm:px-5">
                            <span class="grid h-8 w-8 place-items-center rounded-[10px] ring-1 ring-inset" :class="check.ok ? 'bg-emerald-50 text-emerald-700 ring-emerald-100' : 'bg-amber-50 text-amber-700 ring-amber-100'"><component :is="check.icon" class="h-4 w-4" /></span>
                            <div class="min-w-0"><strong class="block text-[11.5px]">{{ check.title }}</strong><span class="mt-0.5 block truncate text-[10px] text-neutral-500">{{ check.meta }}</span></div>
                            <div class="col-span-2 flex flex-wrap items-center gap-2 pl-[46px] sm:col-span-1 sm:pl-0"><span class="text-[10px] font-bold" :class="check.ok ? 'text-emerald-700' : 'text-amber-700'">{{ check.state }}</span><button v-if="check.goto" class="rounded-full border border-neutral-200 px-3 py-1.5 text-[10px] font-bold text-neutral-600 transition-all hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700" @click="activeSection = check.goto">{{ check.ok ? $t('superAdmin.tenantShow.manage') : $t('superAdmin.tenantShow.configure') }} →</button></div>
                        </div>
                    </div>
                </section>

                <!-- ABONIMI & MODULET: MRR-ja dhe modulet NJË herë; NJË hyrje te drawer-i -->
                <section v-else-if="activeSection === 'billing'" class="sa-card overflow-hidden">
                    <div class="border-b border-neutral-200 bg-gradient-to-r from-emerald-50/70 to-white p-4 sm:p-5">
                        <button class="sa-button sa-button-primary float-right" @click="openBilling"><CreditCard class="h-4 w-4" />{{ $t('superAdmin.auto.copy029') }}</button>
                        <p class="text-[9.5px] font-bold uppercase tracking-[.1em] text-neutral-500">{{ $t('superAdmin.tenantShow.monthlySubscription') }}</p>
                        <p class="mt-1 text-[28px] font-bold tracking-tight text-neutral-950">{{ money(tenant.mrr_cents) }} <small class="text-[11px] font-medium text-neutral-500">{{ $t('superAdmin.tenantShow.perMonthSuffix') }}</small></p>
                        <div class="mt-2.5 flex flex-wrap gap-1.5">
                            <span class="chrome-chip">{{ $t('superAdmin.auto.copy059') }} <b :class="billingIsHealthy ? 'text-emerald-700' : 'text-amber-700'">{{ statusLabel(tenant.billing.status) }}</b></span>
                            <span class="chrome-chip"><b>{{ tenant.billing.billing_cycle === 'annual' ? $t('superAdmin.dynamic.annual') : $t('superAdmin.dynamic.monthly') }}</b></span>
                            <span class="chrome-chip">{{ $t('superAdmin.tenantShow.nextBilling') }} <b>{{ date(tenant.billing.next_billing_at) }}</b></span>
                            <span class="chrome-chip">{{ $t('superAdmin.tenantShow.contractLength') }} <b>{{ $t('superAdmin.tenantShow.contractYears', { years: tenant.billing.contract_years || 1 }) }}</b></span>
                        </div>
                    </div>
                    <div class="divide-y divide-neutral-100">
                        <div v-for="module in enabledModules" :key="module.code" class="grid grid-cols-[34px_minmax(0,1fr)] items-center gap-3 px-4 py-3 sm:grid-cols-[34px_minmax(0,1fr)_auto] sm:px-5">
                            <span class="grid h-8 w-8 place-items-center rounded-[10px] bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-100"><Settings2 class="h-4 w-4" /></span>
                            <div class="min-w-0"><strong class="block text-[11.5px]">{{ module.name }}</strong><span class="mt-0.5 block truncate text-[10px] text-neutral-500">{{ module.description }}</span></div>
                            <span class="col-span-2 pl-[46px] text-left text-[10.5px] font-bold tabular-nums sm:col-span-1 sm:pl-0 sm:text-right">{{ modulePriceLabel(module) }}</span>
                        </div>
                        <p v-if="!enabledModules.length" class="px-4 py-6 text-center text-[10.5px] text-neutral-400 sm:px-5">{{ $t('superAdmin.tenantShow.noModulesEnabled') }}</p>
                    </div>
                    <div class="flex gap-2 border-t border-neutral-200 p-3 sm:px-5">
                        <Link :href="`/super-admin/billing/invoices?tenant_id=${tenant.id}`" class="flex-1 rounded-full border border-neutral-200 py-2 text-center text-[10px] font-bold text-neutral-600 no-underline transition-all hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700">{{ $t('superAdmin.compact.invoices') }}</Link>
                        <Link :href="`/super-admin/billing/payments?tenant_id=${tenant.id}`" class="flex-1 rounded-full border border-neutral-200 py-2 text-center text-[10px] font-bold text-neutral-600 no-underline transition-all hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700">{{ $t('superAdmin.compact.payments') }}</Link>
                        <Link :href="`/super-admin/billing/payment-attempts?tenant_id=${tenant.id}`" class="flex-1 rounded-full border border-neutral-200 py-2 text-center text-[10px] font-bold text-neutral-600 no-underline transition-all hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700">{{ $t('superAdmin.compact.paymentAttempts') }}</Link>
                    </div>
                </section>

                <!-- INTEGRIMET & DOMAIN-ET: 4 rreshta → drawer-i ekzistues te tab-i përkatës -->
                <section v-else-if="activeSection === 'integrations'" class="sa-card overflow-hidden">
                    <div class="border-b border-neutral-200 p-4 sm:p-5"><h2 class="text-base font-semibold">{{ $t('superAdmin.tenantShow.railIntegrations') }}</h2><p class="mt-0.5 text-[11px] text-neutral-500">{{ $t('superAdmin.tenantShow.integrationsSubtitle') }}</p></div>
                    <div class="divide-y divide-neutral-100">
                        <div v-for="row in integrationRows" :key="row.key" class="grid grid-cols-[34px_minmax(0,1fr)] items-center gap-3 px-4 py-3 hover:bg-neutral-50/60 sm:grid-cols-[34px_minmax(0,1fr)_auto] sm:px-5">
                            <span class="grid h-8 w-8 place-items-center rounded-[10px] ring-1 ring-inset" :class="row.ok ? 'bg-emerald-50 text-emerald-700 ring-emerald-100' : 'bg-amber-50 text-amber-700 ring-amber-100'"><component :is="row.icon" class="h-4 w-4" /></span>
                            <div class="min-w-0"><strong class="block text-[11.5px]">{{ row.title }}</strong><span class="mt-0.5 block truncate text-[10px] text-neutral-500">{{ row.meta }}</span></div>
                            <div class="col-span-2 flex flex-wrap items-center gap-2 pl-[46px] sm:col-span-1 sm:pl-0"><span class="text-[10px] font-bold" :class="row.ok ? 'text-emerald-700' : 'text-amber-700'">{{ row.state }}</span><button class="rounded-full border border-neutral-200 px-3 py-1.5 text-[10px] font-bold text-neutral-600 transition-all hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700" @click="openConfig(row.tab)">{{ row.action }} →</button></div>
                        </div>
                    </div>
                </section>

                <!-- PËRDORUESIT -->
                <section v-else-if="activeSection === 'members'" class="sa-card overflow-hidden">
                    <div class="flex items-center justify-between border-b border-neutral-200 p-4 sm:p-5">
                        <div><h2 class="text-base font-semibold">{{ $t('superAdmin.auto.copy093') }}</h2><p class="mt-0.5 text-[11px] text-neutral-500">{{ $t('superAdmin.tenantShow.membersSubtitle') }}</p></div>
                        <Button size="sm" variant="outline" @click="openMember()"><Plus class="h-4 w-4" /> {{ $t('superAdmin.tenantShow.addMember') }}</Button>
                    </div>
                    <div class="divide-y divide-neutral-100">
                        <div v-for="member in members" :key="member.id" class="grid grid-cols-[34px_minmax(0,1fr)] items-center gap-3 px-4 py-3 hover:bg-neutral-50/60 sm:grid-cols-[34px_minmax(0,1fr)_auto] sm:px-5">
                            <span class="grid h-8 w-8 place-items-center rounded-full bg-blue-50 text-[10px] font-bold text-blue-700">{{ initials(member.name) }}</span>
                            <div class="min-w-0"><strong class="block text-[11.5px]">{{ member.name }}</strong><span class="mt-0.5 block truncate text-[10px] text-neutral-500">{{ member.email }}{{ member.is_owner ? $t('superAdmin.tenantShow.ownerSuffix') : '' }}</span></div>
                            <div class="col-span-2 flex flex-wrap items-center gap-2 pl-[46px] sm:col-span-1 sm:pl-0">
                                <span class="rounded-full bg-neutral-100 px-2.5 py-1 text-[9.5px] font-semibold text-neutral-600">{{ roleLabel(member.role) }}</span>
                                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[9.5px] font-bold" :class="member.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'"><span class="h-1.5 w-1.5 rounded-full bg-current" />{{ member.is_active ? $t('superAdmin.auto.copy005') : $t('superAdmin.dynamic.inactive') }}</span>
                                <button class="rounded-full border border-neutral-200 px-3 py-1.5 text-[10px] font-bold text-neutral-600 transition-all hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700" @click="openMember(member)">{{ $t('superAdmin.auto.copy089') }}</button>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- AKTIVITETI -->
                <section v-else class="sa-card overflow-hidden">
                    <div class="flex items-center justify-between border-b border-neutral-200 p-4 sm:p-5">
                        <div><h2 class="text-base font-semibold">{{ $t('superAdmin.auto.copy079') }}</h2><p class="mt-0.5 text-[11px] text-neutral-500">{{ $t('superAdmin.tenantShow.recentActivitySubtitle') }}</p></div>
                        <Link :href="route('super-admin.activity', { tenant: tenant.id, range: 30 })" class="rounded-full border border-neutral-200 px-3 py-1.5 text-[10px] font-bold text-neutral-600 no-underline transition-all hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700">{{ $t('superAdmin.tenantShow.viewAllArrow') }}</Link>
                    </div>
                    <ul class="divide-y divide-neutral-100">
                        <li v-for="log in activity" :key="log.id" class="grid grid-cols-[34px_minmax(0,1fr)_auto] items-center gap-3 px-4 py-3 sm:px-5">
                            <span class="grid h-8 w-8 place-items-center rounded-[10px] bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-100"><LogIn v-if="log.action === 'tenant.switch'" class="h-4 w-4" /><CreditCard v-else-if="log.action === 'tenant.subscription.update'" class="h-4 w-4" /><Check v-else class="h-4 w-4" /></span>
                            <div class="min-w-0"><strong class="block truncate text-[11.5px] text-neutral-800">{{ ACTION_LABELS[log.action] || log.action }}</strong><span class="mt-0.5 block truncate text-[10px] text-neutral-500">{{ log.actor }}</span></div>
                            <time class="text-[9.5px] text-neutral-400">{{ when(log.created_at) }}</time>
                        </li>
                    </ul>
                </section>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="activeDrawer" class="super-admin-shell fixed inset-0 z-50 bg-neutral-950/45 backdrop-blur-[2px]" @click.self="closeDrawer">
                <aside class="ml-auto flex h-full w-full flex-col bg-white shadow-2xl" :class="activeDrawer === 'billing' ? 'max-w-[920px]' : 'max-w-[760px]'">
                    <header class="flex min-h-[70px] items-center justify-between gap-4 border-b border-neutral-200 px-5 py-3.5">
                        <div class="flex items-center gap-3">
                            <span class="grid h-10 w-10 place-items-center rounded-xl bg-emerald-50 text-emerald-700">
                                <Building2 v-if="activeDrawer === 'tenant'" class="h-5 w-5" />
                                <UserRound v-else-if="activeDrawer === 'member'" class="h-5 w-5" />
                                <CreditCard v-else-if="activeDrawer === 'billing'" class="h-5 w-5" />
                                <Settings2 v-else class="h-5 w-5" />
                            </span>
                            <div><h2 class="text-sm font-bold text-neutral-900">{{ activeDrawer === 'tenant' ? $t('superAdmin.dynamic.hotelDetails') : activeDrawer === 'member' ? (editingMember ? $t('superAdmin.tenantShow.editMember') : $t('superAdmin.tenantShow.addMember')) : activeDrawer === 'billing' ? $t('superAdmin.tenantShow.subscriptionAndModules') : $t('superAdmin.auto.copy020') }}</h2><p class="mt-0.5 text-[10px] text-neutral-500">{{ tenant.name }}</p></div>
                        </div>
                        <button type="button" class="rounded-xl p-2 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" :aria-label="$t('superAdmin.auto.copy028')" @click="closeDrawer"><X class="h-5 w-5" /></button>
                    </header>

                    <div v-if="activeDrawer === 'config'" class="flex shrink-0 gap-1 overflow-x-auto border-b border-neutral-200 bg-neutral-50 px-5 pt-2">
                        <button v-for="tab in [{ id: 'domains', label: $t('superAdmin.dynamic.domains') }, { id: 'channex', label: 'Channex' }, { id: 'pok', label: 'POK' }, { id: 'fature', label: 'fature.al' }]" :key="tab.id" type="button" class="h-10 shrink-0 border-b-2 px-3 text-[11px] font-bold" :class="configTab === tab.id ? 'border-emerald-700 text-emerald-800' : 'border-transparent text-neutral-500'" @click="configTab = tab.id">{{ tab.label }}</button>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto p-5">
                        <form v-if="activeDrawer === 'tenant'" id="tenant-form" class="space-y-4" @submit.prevent="saveTenant">
                            <section class="rounded-xl border border-neutral-200 p-4"><div class="mb-4 flex items-start gap-2.5"><Building2 class="mt-0.5 h-4 w-4 text-emerald-700" /><div><strong class="text-xs text-neutral-900">{{ $t('superAdmin.tenantShow.hotelIdentity') }}</strong><p class="mt-0.5 text-[10px] text-neutral-500">{{ $t('superAdmin.tenantShow.hotelIdentitySubtitle') }}</p></div></div><div class="grid gap-4 sm:grid-cols-2"><label class="text-[11px] font-semibold text-neutral-600">{{ $t('superAdmin.dynamic.hotelName') }}<input v-model="tenantForm.name" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" required /></label><label class="text-[11px] font-semibold text-neutral-600">Slug<input v-model="tenantForm.slug" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" required /></label></div><p v-if="tenantForm.errors.name || tenantForm.errors.slug" class="mt-2 text-xs text-red-600">{{ tenantForm.errors.name || tenantForm.errors.slug }}</p></section>
                            <section class="rounded-xl border border-neutral-200 p-4"><div class="mb-4 flex items-start gap-2.5"><Globe2 class="mt-0.5 h-4 w-4 text-emerald-700" /><div><strong class="text-xs text-neutral-900">{{ $t('superAdmin.dynamic.localization') }}</strong><p class="mt-0.5 text-[10px] text-neutral-500">{{ $t('superAdmin.tenantShow.localizationSubtitle') }}</p></div></div><div class="grid gap-4 sm:grid-cols-2"><label class="text-[11px] font-semibold text-neutral-600">Timezone<select v-model="tenantForm.timezone" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm"><optgroup v-for="(zones, region) in timezoneGroups" :key="region" :label="region"><option v-for="zone in zones" :key="zone.value" :value="zone.value">{{ zone.label }}</option></optgroup></select></label><label class="text-[11px] font-semibold text-neutral-600">{{ $t('superAdmin.tenantShow.currency') }}<select v-model="tenantForm.currency" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm"><option v-for="code in currencyOptions" :key="code" :value="code">{{ currencyLabel(code) }}</option></select></label></div><p v-if="tenantForm.errors.timezone || tenantForm.errors.currency" class="mt-2 text-xs text-red-600">{{ tenantForm.errors.timezone || tenantForm.errors.currency }}</p></section>
                        </form>

                        <form v-else-if="activeDrawer === 'member'" id="member-form" class="space-y-4" @submit.prevent="saveMember">
                            <section class="rounded-xl border border-neutral-200 p-4"><div class="mb-4 flex items-start gap-2.5"><UserRound class="mt-0.5 h-4 w-4 text-emerald-700" /><div><strong class="text-xs text-neutral-900">{{ $t('superAdmin.tenantShow.memberDetails') }}</strong><p class="mt-0.5 text-[10px] text-neutral-500">{{ $t('superAdmin.tenantShow.memberDetailsSubtitle') }}</p></div></div><div class="grid gap-4 sm:grid-cols-2"><label class="text-[11px] font-semibold text-neutral-600">{{ $t('superAdmin.tenantShow.fullName') }}<input v-model="memberForm.name" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" required /></label><label class="text-[11px] font-semibold text-neutral-600">Email<input v-model="memberForm.email" type="email" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" required /></label><label class="text-[11px] font-semibold text-neutral-600">{{ $t('superAdmin.tenantShow.role') }}<select v-model="memberForm.role" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm"><option v-for="role in roleOptions" :key="role" :value="role">{{ roleLabel(role) }}</option></select></label><label class="text-[11px] font-semibold text-neutral-600">{{ $t('superAdmin.auto.copy059') }}<select v-model="memberForm.is_active" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm"><option :value="true">{{ $t('superAdmin.auto.copy005') }}</option><option :value="false">{{ $t('superAdmin.dynamic.inactive') }}</option></select></label></div><p v-if="Object.keys(memberForm.errors).length" class="mt-2 text-xs text-red-600">{{ Object.values(memberForm.errors)[0] }}</p></section>
                            <div class="rounded-xl border border-emerald-200 bg-emerald-50/70 p-4 text-xs text-emerald-900">{{ $t('superAdmin.tenantShow.newMemberPasswordNote') }}</div>
                        </form>

                        <form v-else-if="activeDrawer === 'billing'" id="billing-form" class="grid gap-4 lg:grid-cols-[240px_minmax(0,1fr)]" @submit.prevent="saveBilling">
                            <aside class="h-fit rounded-xl border border-emerald-100 bg-gradient-to-br from-emerald-50/70 to-white p-4"><p class="text-[9px] font-bold uppercase tracking-[.12em] text-neutral-500">{{ $t('superAdmin.tenantShow.currentTotal') }}</p><p class="mt-1 text-2xl font-bold tracking-tight">{{ money(tenant.mrr_cents) }} <small class="text-[10px] font-medium text-neutral-500">{{ $t('superAdmin.tenantShow.perMonthSuffix') }}</small></p><label class="mt-4 block text-[11px] font-semibold text-neutral-600">{{ $t('superAdmin.auto.copy059') }}<select v-model="billingForm.status" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm"><option value="trialing">{{ $t('superAdmin.auto.copy049') }}</option><option value="active">{{ $t('superAdmin.auto.copy005') }}</option><option value="past_due">{{ $t('superAdmin.tenantShow.statusPastDue') }}</option><option value="suspended">{{ $t('superAdmin.auto.copy044') }}</option><option value="canceled">{{ $t('superAdmin.auto.copy009') }}</option></select></label><label class="mt-4 block text-[11px] font-semibold text-neutral-600">{{ $t('superAdmin.dynamic.billingCycleLabel') }}<select v-model="billingForm.billing_cycle" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm"><option value="monthly">{{ $t('superAdmin.dynamic.monthly') }}</option><option value="annual">{{ $t('superAdmin.dynamic.annual') }}</option></select></label><label class="mt-4 block text-[11px] font-semibold text-neutral-600">{{ $t('superAdmin.tenantShow.contractLength') }}<select v-model.number="billingForm.contract_years" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm"><option v-for="option in tenant.billing.contract_options" :key="option.years" :value="option.years">{{ $t('superAdmin.tenantShow.contractOption', { years: option.years, percent: option.discount_percent }) }}</option></select><p v-if="tenant.billing.discount_override_percent !== null && tenant.billing.discount_override_percent !== undefined" class="mt-1 text-[10px] text-amber-600">{{ $t('superAdmin.tenantShow.contractOverride', { percent: tenant.billing.discount_override_percent }) }}</p></label><label v-if="tenant.billing.billing_currency_options" class="mt-4 block text-[11px] font-semibold text-neutral-600">{{ $t('superAdmin.tenantShow.billingCurrency') }}<select v-model="billingForm.billing_currency" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm"><option v-for="code in tenant.billing.billing_currency_options || ['EUR']" :key="code" :value="code">{{ code }}</option></select><p class="mt-1 text-[10px] leading-4 text-neutral-500">{{ $t('superAdmin.tenantShow.billingCurrencyHint') }}</p><p v-if="billingForm.billing_currency !== 'EUR'" class="mt-1 text-[10px] leading-4 font-medium text-amber-700">{{ $t('superAdmin.tenantShow.invoicedIn', { currency: billingForm.billing_currency }) }}</p><span v-if="billingForm.errors.billing_currency" class="mt-1 block text-[10px] text-red-600">{{ billingForm.errors.billing_currency }}</span></label><label v-if="tenant.billing.billing_currency_options && billingForm.billing_currency !== 'EUR'" class="mt-4 block text-[11px] font-semibold text-neutral-600">{{ $t('superAdmin.tenantShow.fxOverride') }}<input v-model="billingForm.fx_rate_override" type="number" step="0.000001" min="0" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" /><p class="mt-1 text-[10px] leading-4 text-neutral-500">{{ $t('superAdmin.tenantShow.fxOverrideHint') }}</p><span v-if="billingForm.errors.fx_rate_override" class="mt-1 block text-[10px] text-red-600">{{ billingForm.errors.fx_rate_override }}</span></label><label class="mt-4 block text-[11px] font-semibold text-neutral-600">{{ $t('superAdmin.dynamic.renewalDate') }}<DatePicker v-model="billingForm.current_period_ends_at" class="mt-1.5 w-full" /></label><label class="mt-4 block text-[11px] font-semibold text-neutral-600">{{ $t('superAdmin.tenantShow.notes') }}<textarea v-model="billingForm.notes" rows="3" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" /></label></aside>
                            <section><h3 class="text-sm font-bold text-neutral-900">{{ $t('superAdmin.tenantShow.subscriptionModules') }}</h3><p class="mt-1 text-[10px] text-neutral-500">{{ $t('superAdmin.tenantShow.subscriptionModulesSubtitle') }} {{ $t('superAdmin.tenantShow.catalogNote') }}</p><div class="mt-3 divide-y divide-neutral-100 overflow-hidden rounded-xl border border-neutral-200"><label v-for="module in tenant.billing.modules" :key="module.code" class="flex cursor-pointer flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2.5 transition-colors sm:flex-nowrap" :class="billingForm.modules[module.code]?.enabled ? 'bg-emerald-50/60' : 'bg-white hover:bg-neutral-50/70'"><input v-model="billingForm.modules[module.code].enabled" type="checkbox" class="h-5 w-5 shrink-0 rounded-[7px] border-[1.5px] border-neutral-300 text-emerald-600 shadow-sm transition-all checked:shadow-md checked:shadow-emerald-600/25 focus:ring-emerald-500/40 disabled:opacity-40" :disabled="module.locked" /><span class="min-w-0 flex-1"><strong class="block text-[11px] text-neutral-900">{{ module.name }}</strong><span class="mt-0.5 block text-[9px] leading-4 text-neutral-500">{{ module.description }}</span></span><span v-if="['tiered_per_room', 'per_user', 'per_pos'].includes(module.billing_model)" class="flex shrink-0 items-center gap-1.5"><input v-model.number="billingForm.modules[module.code].quantity" type="number" min="1" max="10000" class="w-16 rounded-full border-neutral-300 py-1 text-center text-xs shadow-sm focus:border-emerald-400 focus:ring-emerald-500/30" /><span class="text-[9px] text-neutral-500">{{ module.unit_label }}</span></span><span class="w-44 shrink-0 text-right"><strong class="block text-[11px] tabular-nums text-neutral-900">{{ modulePriceLabel(module) }}</strong><span v-if="moduleLiveMonthly(module) !== null" class="text-[9px] font-semibold text-emerald-700">{{ $t('superAdmin.moduleList.nowPaying', { price: euros(moduleLiveMonthly(module)) }) }}</span></span></label></div><p v-if="Object.keys(billingForm.errors).length" class="mt-3 text-xs text-red-600">{{ Object.values(billingForm.errors)[0] }}</p></section>
                        </form>

                        <div v-else-if="activeDrawer === 'config'">
                            <section v-if="configTab === 'domains'" class="space-y-4">
                                <div class="flex items-start gap-2.5"><Globe2 class="mt-0.5 h-4 w-4 text-emerald-700" /><div><strong class="text-xs text-neutral-900">{{ $t('superAdmin.tenantShow.hotelDomains') }}</strong><p class="mt-0.5 text-[10px] text-neutral-500">{{ $t('superAdmin.tenantShow.domainLifecycle') }}</p></div></div>

                                <div class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-4">
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <p class="text-[10px] font-bold uppercase tracking-wide text-emerald-800">{{ $t('superAdmin.tenantShow.dnsInstructionsTitle') }}</p>
                                            <p class="mt-0.5 text-[10px] text-emerald-900/70">{{ $t('superAdmin.tenantShow.dnsInstructionsSubtitle') }}</p>
                                        </div>
                                        <Button size="sm" variant="outline" :disabled="!tenant.domainServerIp" @click="copyDnsInstructions">{{ copied === 'instructions' ? $t('superAdmin.tenantShow.copiedCheck') : $t('superAdmin.tenantShow.copyInstructions') }}</Button>
                                    </div>
                                    <div v-if="tenant.domainServerIp" class="mt-3 overflow-x-auto rounded-lg bg-white p-3 ring-1 ring-emerald-100">
                                        <table class="w-full text-[11px] text-neutral-800">
                                            <thead><tr class="text-left text-[9px] font-bold uppercase tracking-wide text-neutral-400"><th class="pb-1.5 pr-4">{{ $t('superAdmin.tenantShow.typeCol') }}</th><th class="pb-1.5 pr-4">Host</th><th class="pb-1.5 pr-4">{{ $t('superAdmin.tenantShow.valueCol') }}</th><th class="pb-1.5"></th></tr></thead>
                                            <tbody>
                                                <tr><td class="pr-4 font-bold">A</td><td class="pr-4 font-mono">@</td><td class="pr-4 font-mono">{{ tenant.domainServerIp }}</td><td class="py-0.5 text-right"><button type="button" class="rounded-md px-2 py-1 text-[9px] font-bold text-emerald-700 hover:bg-emerald-50" @click="copyText('ip-apex', tenant.domainServerIp)">{{ copied === 'ip-apex' ? $t('superAdmin.tenantShow.copiedCheck') : $t('superAdmin.tenantShow.copy') }}</button></td></tr>
                                                <tr><td class="pr-4 font-bold">A</td><td class="pr-4 font-mono">www</td><td class="pr-4 font-mono">{{ tenant.domainServerIp }}</td><td class="py-0.5 text-right"><button type="button" class="rounded-md px-2 py-1 text-[9px] font-bold text-emerald-700 hover:bg-emerald-50" @click="copyText('ip-www', tenant.domainServerIp)">{{ copied === 'ip-www' ? $t('superAdmin.tenantShow.copiedCheck') : $t('superAdmin.tenantShow.copy') }}</button></td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <p v-else class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-[10px] font-semibold text-amber-800 ring-1 ring-amber-200">{{ $t('superAdmin.tenantShow.forgeIpMissing') }}</p>
                                </div>
                                <div class="overflow-hidden rounded-xl border border-neutral-200">
                                    <div v-for="domain in tenant.domains" :key="domain.id" class="border-b border-neutral-100 px-3 py-3 last:border-b-0">
                                        <div class="grid grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-3">
                                            <span class="grid h-9 w-9 place-items-center rounded-xl bg-emerald-50 text-emerald-700"><Globe2 class="h-4 w-4" /></span>
                                            <div class="min-w-0">
                                                <div class="flex flex-wrap items-center gap-1.5">
                                                    <strong class="text-xs text-neutral-900">{{ domain.domain }}</strong>
                                                    <span class="rounded-lg px-2 py-0.5 text-[9px] font-bold" :class="domainStatusMeta[domain.status]?.class || 'bg-neutral-100 text-neutral-600'">{{ domainStatusMeta[domain.status]?.label || domain.status }}</span>
                                                    <span v-if="domain.is_primary" class="rounded-lg bg-emerald-50 px-2 py-0.5 text-[9px] font-bold text-emerald-700">{{ $t('superAdmin.auto.copy046') }}</span>
                                                </div>
                                                <span v-if="domain.status_message" class="mt-0.5 block text-[9px]" :class="domain.status === 'failed' ? 'text-red-600' : 'text-neutral-500'">{{ domain.status_message }}</span>
                                                <span v-else-if="isPlatformSubdomain(domain)" class="mt-0.5 block text-[9px] text-neutral-500">{{ $t('superAdmin.tenantShow.platformSubdomainNote') }}</span>
                                            </div>
                                            <div class="flex flex-wrap justify-end gap-1.5">
                                                <Button v-if="domain.status !== 'active'" size="sm" variant="outline" :disabled="domainBusy === domain.id" @click="domainAction(domain, 'super-admin.tenants.domains.verify')">{{ $t('superAdmin.tenantShow.verifyDns') }}</Button>
                                                <Button v-if="domain.verified_at && ['pending_dns', 'failed'].includes(domain.status)" size="sm" variant="primary" :disabled="domainBusy === domain.id" @click="domainAction(domain, 'super-admin.tenants.domains.provision')">{{ $t('superAdmin.tenantShow.provision') }}</Button>
                                                <Button v-if="domain.status === 'provisioning'" size="sm" variant="outline" :disabled="domainBusy === domain.id" @click="domainAction(domain, 'super-admin.tenants.domains.refresh')">{{ $t('superAdmin.compact.refresh') }}</Button>
                                                <Button v-if="!domain.is_primary" size="sm" variant="outline" @click="makePrimary(domain)">{{ $t('superAdmin.tenantShow.makePrimary') }}</Button>
                                                <Button v-if="!domain.is_primary" size="sm" variant="outline" class="!text-red-600" @click="removeDomain(domain)">{{ $t('superAdmin.auto.copy016') }}</Button>
                                            </div>
                                        </div>
                                        <div v-if="domain.status === 'pending_dns' && !isPlatformSubdomain(domain)" class="mt-2 rounded-lg bg-neutral-50 p-3">
                                            <p class="text-[9px] font-bold uppercase tracking-wide text-neutral-500">{{ $t('superAdmin.tenantShow.clientDnsHeading') }}</p>
                                            <div class="mt-1.5 overflow-x-auto">
                                                <table class="text-[10px] text-neutral-700">
                                                    <tbody>
                                                        <tr><td class="pr-3 font-mono">@</td><td class="pr-3 font-bold">A</td><td class="font-mono">{{ tenant.domainServerIp || '—' }}</td></tr>
                                                        <tr><td class="pr-3 font-mono">www</td><td class="pr-3 font-bold">A</td><td class="font-mono">{{ tenant.domainServerIp || '—' }}</td></tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <p class="mt-1.5 text-[9px] text-neutral-500">{{ $t('superAdmin.tenantShow.clientDnsFootnote') }}</p>
                                        </div>
                                    </div>
                                </div>
                                <form class="rounded-xl border border-neutral-200 p-4" @submit.prevent="addDomain"><label class="text-[11px] font-semibold text-neutral-600">{{ $t('superAdmin.tenantShow.addNewDomain') }}<div class="mt-1.5 flex gap-2"><input v-model="domainForm.domain" placeholder="booking.hoteli.al" class="min-w-0 flex-1 rounded-xl border-neutral-300 text-sm" /><Button type="submit" variant="primary" :disabled="domainForm.processing">{{ $t('superAdmin.tenantShow.add') }}</Button></div></label><p v-if="domainForm.errors.domain" class="mt-2 text-xs text-red-600">{{ domainForm.errors.domain }}</p><p class="mt-2 text-[9px] text-neutral-500">{{ $t('superAdmin.tenantShow.newDomainNote') }}</p></form>
                            </section>

                            <section v-else-if="configTab === 'channex'" class="space-y-4"><div class="flex items-center justify-between gap-4 rounded-xl border border-neutral-200 bg-neutral-50 p-4"><div><strong class="text-xs text-neutral-900">Channex Channel Manager</strong><p class="mt-1 text-[10px] text-neutral-500">{{ $t('superAdmin.tenantShow.channexSubtitle') }}</p></div><button type="button" class="relative h-6 w-11 rounded-full transition" :class="channexForm.enabled ? 'bg-emerald-700' : 'bg-neutral-300'" @click="channexForm.enabled = !channexForm.enabled"><span class="absolute top-1 h-4 w-4 rounded-full bg-white shadow transition" :class="channexForm.enabled ? 'left-6' : 'left-1'" /></button></div><div class="grid gap-4 rounded-xl border border-neutral-200 p-4 sm:grid-cols-2"><label class="text-[11px] font-semibold text-neutral-600">API key<input v-model="channexForm.api_key" type="password" :placeholder="tenant.integrations.channex.has_api_key ? $t('superAdmin.tenantShow.secretKeepPlaceholder') : $t('superAdmin.tenantShow.pasteApiKey')" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" /></label><label class="text-[11px] font-semibold text-neutral-600">Webhook secret<input v-model="channexForm.webhook_secret" type="password" :placeholder="tenant.integrations.channex.has_webhook_secret ? $t('superAdmin.tenantShow.secretKeepPlaceholder') : $t('superAdmin.dynamic.pasteWebhookSecret')" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" /></label><label class="text-[11px] font-semibold text-neutral-600">Property ID<input v-model="channexForm.property_id" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" /></label><label class="text-[11px] font-semibold text-neutral-600">Base URL<input v-model="channexForm.base_url" placeholder="https://app.channex.io/api/v1" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" /></label><p v-if="Object.keys(channexForm.errors).length" class="text-xs text-red-600 sm:col-span-2">{{ Object.values(channexForm.errors)[0] }}</p></div></section>

                            <section v-else-if="configTab === 'pok'" class="space-y-4"><div class="flex items-center justify-between gap-4 rounded-xl border border-neutral-200 bg-neutral-50 p-4"><div><strong class="text-xs text-neutral-900">POK Payments</strong><p class="mt-1 text-[10px] text-neutral-500">{{ $t('superAdmin.tenantShow.pokSubtitle') }}</p></div><button type="button" class="relative h-6 w-11 rounded-full transition" :class="pokForm.enabled ? 'bg-emerald-700' : 'bg-neutral-300'" @click="pokForm.enabled = !pokForm.enabled"><span class="absolute top-1 h-4 w-4 rounded-full bg-white shadow transition" :class="pokForm.enabled ? 'left-6' : 'left-1'" /></button></div><div class="grid gap-4 rounded-xl border border-neutral-200 p-4 sm:grid-cols-2"><label class="text-[11px] font-semibold text-neutral-600">Key ID<input v-model="pokForm.key_id" type="password" :placeholder="tenant.integrations.pok.has_key_id ? $t('superAdmin.tenantShow.secretKeepPlaceholder') : $t('superAdmin.dynamic.pasteKeyId')" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" /></label><label class="text-[11px] font-semibold text-neutral-600">Key secret<input v-model="pokForm.key_secret" type="password" :placeholder="tenant.integrations.pok.has_key_secret ? $t('superAdmin.tenantShow.secretKeepPlaceholder') : $t('superAdmin.dynamic.pasteKeySecret')" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" /></label><label class="text-[11px] font-semibold text-neutral-600 sm:col-span-2">Merchant ID<input v-model="pokForm.merchant_id" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" /></label><label class="flex cursor-pointer items-center gap-2 text-[11px] font-semibold text-neutral-600 sm:col-span-2"><input v-model="pokForm.production" type="checkbox" class="h-5 w-5 rounded-[7px] border-[1.5px] border-neutral-300 text-emerald-600 shadow-sm transition-all checked:shadow-md checked:shadow-emerald-600/25 focus:ring-emerald-500/40" /> {{ $t('superAdmin.tenantShow.productionEnvironment') }}</label><p v-if="Object.keys(pokForm.errors).length" class="text-xs text-red-600 sm:col-span-2">{{ Object.values(pokForm.errors)[0] }}</p></div></section>

                            <section v-else class="space-y-4"><div class="flex items-center justify-between gap-4 rounded-xl border border-neutral-200 bg-neutral-50 p-4"><div><strong class="text-xs text-neutral-900">fature.al</strong><p class="mt-1 text-[10px] text-neutral-500">{{ $t('superAdmin.tenantShow.fatureSubtitle') }}</p></div><button type="button" class="relative h-6 w-11 rounded-full transition" :class="fatureForm.enabled ? 'bg-emerald-700' : 'bg-neutral-300'" @click="fatureForm.enabled = !fatureForm.enabled"><span class="absolute top-1 h-4 w-4 rounded-full bg-white shadow transition" :class="fatureForm.enabled ? 'left-6' : 'left-1'" /></button></div><div class="grid gap-4 rounded-xl border border-neutral-200 p-4 sm:grid-cols-2"><label class="text-[11px] font-semibold text-neutral-600">{{ $t('superAdmin.tenantShow.environment') }}<select v-model="fatureForm.environment" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm"><option value="sandbox">Sandbox</option><option value="production">Production</option></select></label><label class="text-[11px] font-semibold text-neutral-600">API token<input v-model="fatureForm.api_token" type="password" :placeholder="tenant.integrations.fature_al.has_api_token ? $t('superAdmin.tenantShow.secretKeepPlaceholder') : $t('superAdmin.tenantShow.pasteApiToken')" class="mt-1.5 w-full rounded-xl border-neutral-300 text-sm" /></label><p v-if="Object.keys(fatureForm.errors).length" class="text-xs text-red-600 sm:col-span-2">{{ Object.values(fatureForm.errors)[0] }}</p></div><div class="flex items-center justify-between gap-4 rounded-xl border border-neutral-200 p-4"><div><strong class="text-xs text-neutral-900">{{ $t('superAdmin.tenantShow.connectionTest') }}</strong><p class="mt-1 text-[10px] text-neutral-500">{{ tenant.integrations.fature_al.last_tested_at ? `${tenant.integrations.fature_al.last_test_status} · ${when(tenant.integrations.fature_al.last_tested_at)}` : $t('superAdmin.tenantShow.notTestedYet') }}</p></div><Button variant="outline" @click="testFature"><ExternalLink class="h-4 w-4" /> {{ $t('superAdmin.tenantShow.testConnection') }}</Button></div></section>
                        </div>
                    </div>

                    <footer class="flex shrink-0 items-center justify-between gap-3 border-t border-neutral-200 bg-white px-5 py-3.5">
                        <p class="hidden text-[10px] text-neutral-500 sm:block">{{ $t('superAdmin.tenantShow.auditNote') }}</p>
                        <div class="ml-auto flex gap-2"><Button variant="outline" :disabled="drawerProcessing" @click="closeDrawer">{{ $t('superAdmin.auto.copy008') }}</Button><Button v-if="activeDrawer !== 'config' || configTab !== 'domains'" variant="primary" :disabled="drawerProcessing" @click="activeDrawer === 'tenant' ? saveTenant() : activeDrawer === 'member' ? saveMember() : activeDrawer === 'billing' ? saveBilling() : saveConfig()">{{ drawerProcessing ? $t('superAdmin.tenantShow.saving') : $t('superAdmin.tenantShow.saveChanges') }}</Button></div>
                    </footer>
                </aside>
            </div>
        </Teleport>
    </SuperAdminLayout>
</template>

<style scoped>
.chrome-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    border: 1px solid #e4eae7;
    border-radius: 999px;
    background: linear-gradient(180deg, #fbfdfc, #f4f8f6);
    padding: 4px 11px;
    font-size: 10.5px;
    color: #68766f;
    box-shadow: 0 1px 1.5px rgba(23, 33, 29, 0.03);
}
.chrome-chip b {
    color: #17211d;
    font-weight: 650;
}
</style>
