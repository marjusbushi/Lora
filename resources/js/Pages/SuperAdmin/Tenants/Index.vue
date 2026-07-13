<script setup>
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
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

function createTenant() {
    form.post(route('super-admin.tenants.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('name', 'slug', 'primary_domain', 'owner_name', 'owner_email'),
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
const filteredTenants = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.tenants;
    return props.tenants.filter((t) =>
        [t.name, t.slug, t.primary_domain, ...(t.domains || []).map((d) => d.domain)]
            .filter(Boolean)
            .some((v) => String(v).toLowerCase().includes(q)),
    );
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

function tenantStatusClass(status) {
    return status === 'active'
        ? 'bg-success-50 text-success-700'
        : 'bg-red-50 text-red-700';
}

function tenantStatusLabel(status) {
    return status === 'active' ? 'Aktiv' : (status === 'suspended' ? 'Pezulluar' : status);
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
        <div class="mx-auto max-w-7xl space-y-6">
            <PageHeader
                title="Super Admin — Hotelet"
                :breadcrumbs="[{ label: 'Control Panel', href: '/super-admin' }, { label: 'Hotelet & abonimet' }]"
            />

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
                <section class="overflow-hidden rounded-xl border border-neutral-200 bg-white">
                    <div class="flex flex-col gap-3 border-b border-neutral-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-neutral-900">Hotelet</h2>
                            <p class="mt-1 text-sm text-neutral-500">Çdo hotel ka të dhënat, settings dhe domain-et e veta.</p>
                        </div>
                        <input
                            v-if="tenants.length"
                            v-model="search"
                            type="search"
                            placeholder="Kërko emër / domain…"
                            class="w-full rounded-lg border-neutral-300 text-sm sm:w-56"
                        />
                    </div>

                    <div v-if="filteredTenants.length" class="divide-y divide-neutral-100">
                        <article v-for="tenant in filteredTenants" :key="tenant.id" class="p-5">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <Link :href="route('super-admin.tenants.show', tenant.id)" class="font-semibold text-neutral-900 no-underline hover:text-emerald-700">{{ tenant.name }}</Link>
                                    <span v-if="tenant.id === currentTenantId" class="rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700">Aktual</span>
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="tenantStatusClass(tenant.status)">{{ tenantStatusLabel(tenant.status) }}</span>
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="tenant.billing.status === 'active' || tenant.billing.status === 'trialing'
                                            ? 'bg-emerald-50 text-emerald-700'
                                            : 'bg-amber-50 text-amber-700'"
                                    >
                                        {{ statusLabel(tenant.billing.status) }}
                                    </span>
                                </div>
                                <p class="mt-1 text-sm text-neutral-500">{{ tenant.primary_domain || 'Pa domain' }} · {{ tenant.users_count }} përdorues</p>
                                <p class="mt-1 text-xs text-neutral-400">{{ tenant.slug }} · {{ tenant.timezone }} · {{ tenant.currency }}</p>

                                <div class="mt-3 flex flex-wrap gap-1.5">
                                    <span
                                        v-for="module in Object.values(tenant.billing.modules).filter((item) => item.enabled)"
                                        :key="module.code"
                                        class="rounded-md border border-neutral-200 bg-neutral-50 px-2 py-1 text-[11px] font-medium text-neutral-600"
                                    >
                                        {{ module.name }}
                                        <template v-if="['tiered_per_room', 'per_user', 'per_pos'].includes(module.billing_model)"> · {{ module.quantity }}</template>
                                    </span>
                                </div>
                            </div>

                            <div class="shrink-0 text-left sm:text-right">
                                <p class="text-sm font-semibold text-neutral-900">
                                    {{ tenant.billing.billing_cycle === 'annual'
                                        ? money(tenant.billing.annual_cents, tenant.billing.currency)
                                        : money(tenant.billing.monthly_fixed_cents, tenant.billing.currency) }}
                                </p>
                                <p class="text-xs text-neutral-400">{{ tenant.billing.billing_cycle === 'annual' ? '/ vit' : '/ muaj' }} + tarifa variabël</p>
                                <div class="mt-3 flex flex-wrap gap-2 sm:justify-end">
                                    <Button size="sm" variant="outline" @click="openBilling(tenant)">Abonimi</Button>
                                    <Button size="sm" variant="outline" @click="openConfig(tenant)">Konfigurimi</Button>
                                    <Button
                                        size="sm"
                                        :variant="tenant.id === currentTenantId ? 'outline' : 'primary'"
                                        :disabled="tenant.id === currentTenantId || tenant.status !== 'active'"
                                        @click="switchTenant(tenant)"
                                    >
                                        {{ tenant.id === currentTenantId ? 'Në përdorim' : 'Hap hotelin' }}
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        :class="tenant.status === 'active' ? 'text-red-600' : 'text-success-700'"
                                        @click="toggleStatus(tenant)"
                                    >
                                        {{ tenant.status === 'active' ? 'Pezullo' : 'Aktivizo' }}
                                    </Button>
                                </div>
                            </div>
                            </div>
                        </article>
                    </div>

                    <div v-else-if="tenants.length" class="px-5 py-12 text-center text-sm text-neutral-500">
                        Asnjë hotel nuk përputhet me “{{ search }}”.
                    </div>
                    <div v-else class="px-5 py-16 text-center">
                        <p class="text-sm font-medium text-neutral-700">Ende asnjë hotel</p>
                        <p class="mt-1 text-xs text-neutral-500">Krijo hotelin e parë nga forma anash.</p>
                    </div>
                </section>

                <aside class="rounded-xl border border-neutral-200 bg-white p-5">
                    <h2 class="text-lg font-semibold text-neutral-900">Krijo hotel të ri</h2>
                    <p class="mt-1 text-sm text-neutral-500">Krijon tenantin bosh dhe të lidh ty si owner.</p>

                    <form class="mt-5 space-y-4" @submit.prevent="createTenant">
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
                </aside>
            </div>
        </div>

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

                    <div class="space-y-8 p-5 sm:p-6">
                        <!-- Domains -->
                        <section>
                            <h3 class="font-semibold text-neutral-900">Domain-et</h3>
                            <ul class="mt-3 divide-y divide-neutral-100 rounded-xl border border-neutral-200">
                                <li v-for="domain in configTenant.domains" :key="domain.id" class="flex items-center justify-between gap-3 px-4 py-2.5">
                                    <div class="min-w-0">
                                        <span class="text-sm text-neutral-800">{{ domain.domain }}</span>
                                        <span v-if="domain.is_primary" class="ml-2 rounded-full bg-primary-50 px-2 py-0.5 text-[11px] font-medium text-primary-700">Primar</span>
                                    </div>
                                    <div class="flex shrink-0 gap-2">
                                        <Button v-if="!domain.is_primary" size="sm" variant="outline" @click="makePrimary(domain)">Bëje primar</Button>
                                        <Button v-if="!domain.is_primary" size="sm" variant="outline" @click="removeDomain(domain)">Hiq</Button>
                                    </div>
                                </li>
                                <li v-if="!configTenant.domains.length" class="px-4 py-3 text-sm text-neutral-500">Ende pa domain.</li>
                            </ul>

                            <form class="mt-3 flex gap-2" @submit.prevent="addDomain">
                                <input v-model="domainForm.domain" required class="w-full rounded-lg border-neutral-300 text-sm" placeholder="riviera.lorapms.com" />
                                <Button type="submit" :disabled="domainForm.processing">Shto</Button>
                            </form>
                            <span v-if="domainForm.errors.domain" class="mt-1 block text-xs text-danger-600">{{ domainForm.errors.domain }}</span>
                        </section>

                        <!-- Channex -->
                        <section class="rounded-xl border border-neutral-200 p-4">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <h3 class="font-semibold text-neutral-900">Channex (Channel Manager)</h3>
                                    <p class="text-xs text-neutral-500">
                                        API key: <b>{{ configTenant.integrations.channex.has_api_key ? 'e ruajtur ✓' : 'mungon' }}</b> ·
                                        Webhook secret: <b>{{ configTenant.integrations.channex.has_webhook_secret ? 'i ruajtur ✓' : 'mungon' }}</b>
                                    </p>
                                </div>
                                <label class="flex items-center gap-2 text-sm font-medium text-neutral-700">
                                    <input v-model="channexForm.enabled" type="checkbox" class="rounded border-neutral-300 text-emerald-600" /> Aktiv
                                </label>
                            </div>

                            <form class="mt-4 grid gap-3 sm:grid-cols-2" @submit.prevent="saveChannex">
                                <label class="text-sm font-medium text-neutral-700">
                                    API key
                                    <input v-model="channexForm.api_key" type="password" autocomplete="new-password" class="mt-1 w-full rounded-lg border-neutral-300 text-sm" :placeholder="configTenant.integrations.channex.has_api_key ? '•••• (lëre bosh për ta mbajtur)' : ''" />
                                </label>
                                <label class="text-sm font-medium text-neutral-700">
                                    Webhook secret
                                    <input v-model="channexForm.webhook_secret" type="password" autocomplete="new-password" class="mt-1 w-full rounded-lg border-neutral-300 text-sm" :placeholder="configTenant.integrations.channex.has_webhook_secret ? '•••• (lëre bosh për ta mbajtur)' : ''" />
                                </label>
                                <label class="text-sm font-medium text-neutral-700">
                                    Property ID
                                    <input v-model="channexForm.property_id" class="mt-1 w-full rounded-lg border-neutral-300 text-sm" />
                                    <span v-if="channexForm.errors.property_id" class="mt-1 block text-xs text-danger-600">{{ channexForm.errors.property_id }}</span>
                                </label>
                                <label class="text-sm font-medium text-neutral-700">
                                    Base URL (opsional)
                                    <input v-model="channexForm.base_url" class="mt-1 w-full rounded-lg border-neutral-300 text-sm" placeholder="https://app.channex.io/api/v1" />
                                    <span v-if="channexForm.errors.base_url" class="mt-1 block text-xs text-danger-600">{{ channexForm.errors.base_url }}</span>
                                </label>
                                <div class="sm:col-span-2">
                                    <Button type="submit" :disabled="channexForm.processing">{{ channexForm.processing ? 'Duke ruajtur…' : 'Ruaj Channex' }}</Button>
                                </div>
                            </form>
                        </section>

                        <!-- POK -->
                        <section class="rounded-xl border border-neutral-200 p-4">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <h3 class="font-semibold text-neutral-900">POK (Pagesat me kartë)</h3>
                                    <p class="text-xs text-neutral-500">
                                        Key ID: <b>{{ configTenant.integrations.pok.has_key_id ? 'i ruajtur ✓' : 'mungon' }}</b> ·
                                        Key secret: <b>{{ configTenant.integrations.pok.has_key_secret ? 'i ruajtur ✓' : 'mungon' }}</b>
                                    </p>
                                </div>
                                <label class="flex items-center gap-2 text-sm font-medium text-neutral-700">
                                    <input v-model="pokForm.enabled" type="checkbox" class="rounded border-neutral-300 text-emerald-600" /> Aktiv
                                </label>
                            </div>

                            <form class="mt-4 grid gap-3 sm:grid-cols-2" @submit.prevent="savePok">
                                <label class="text-sm font-medium text-neutral-700">
                                    Key ID
                                    <input v-model="pokForm.key_id" type="password" autocomplete="new-password" class="mt-1 w-full rounded-lg border-neutral-300 text-sm" :placeholder="configTenant.integrations.pok.has_key_id ? '•••• (lëre bosh për ta mbajtur)' : ''" />
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
                                <label class="flex items-end gap-2 pb-2 text-sm font-medium text-neutral-700">
                                    <input v-model="pokForm.production" type="checkbox" class="rounded border-neutral-300 text-emerald-600" /> Production (live)
                                </label>
                                <div class="sm:col-span-2">
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
