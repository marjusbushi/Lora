<script setup>
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Building2, Check, ChevronRight, FileKey2, Landmark, LoaderCircle, MonitorSmartphone, ShieldCheck, UserRound, XCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps({ tenant: Object, fiscalization: Object });
const today = new Date().toISOString().slice(0, 10);
const certificateInput = ref(null);
const stepDefinitions = [
    { key: 'company', title: 'Kompania', subtitle: 'Regjistrimi dhe token-i', icon: Building2 },
    { key: 'certificate', title: 'Certifikata', subtitle: 'P12/PFX nga e-Albania', icon: FileKey2 },
    { key: 'branch', title: 'Njësia', subtitle: 'Kodi i biznesit', icon: Landmark },
    { key: 'device', title: 'Pajisja TCR', subtitle: 'Vetëm për cash', icon: MonitorSmartphone },
    { key: 'user', title: 'Operatori', subtitle: 'Kodi i operatorit', icon: UserRound },
    { key: 'bank', title: 'Banka', subtitle: 'Opsionale', icon: Landmark },
    { key: 'verify', title: 'Verifikimi', subtitle: 'Aktivizimi final', icon: ShieldCheck },
];
const firstPending = stepDefinitions.find((step) => !props.fiscalization.steps[step.key] && step.key !== 'bank')?.key || 'verify';
const activeStep = ref(firstPending);
const activeDefinition = computed(() => stepDefinitions.find((step) => step.key === activeStep.value));
const error = computed(() => Object.values([
    registerForm.errors, certificateForm.errors, branchForm.errors, deviceForm.errors,
    userForm.errors, bankForm.errors, verifyForm.errors,
]).flatMap((values) => Object.values(values))[0]);

const registerForm = useForm({
    environment: props.fiscalization.environment || 'sandbox',
    nuis: props.fiscalization.company.nuis || '',
    name: props.fiscalization.company.name || props.tenant.name,
    address: '', administrator: '', phone: '', email: '', issuer_in_vat: null,
    last_non_cash_einvoice_number: '', uses_cash: props.fiscalization.uses_cash ?? true,
});
const certificateForm = useForm({ certificate: null, password: '' });
const branchForm = useForm({
    name: props.fiscalization.branch.name || props.tenant.name,
    business_unit_code: props.fiscalization.branch.business_unit_code || '',
    administrator: '', address: '',
});
const deviceForm = useForm({ name: `${props.tenant.name} · TCR`, from_date: today, to_date: '' });
const userForm = useForm({ name: '', operator_code: props.fiscalization.user.operator_code || '' });
const bankForm = useForm({ name: '', holder: props.tenant.name, iban: '', swift: '', currency: props.tenant.currency, notes: '' });
const verifyForm = useForm({});

function submit(form, path, options = {}) {
    form.post(`/super-admin/onboarding/${props.tenant.id}/fiscalization/${path}`, {
        preserveScroll: true,
        ...options,
    });
}

function uploadCertificate() {
    certificateForm.certificate = certificateInput.value?.files?.[0] || null;
    submit(certificateForm, 'certificate', { forceFormData: true });
}

function isLocked(key) {
    const completed = props.fiscalization.steps;
    if (key === 'company') return false;
    if (key === 'certificate') return !completed.company;
    if (key === 'branch') return !completed.certificate;
    if (key === 'device') return !completed.branch || !props.fiscalization.uses_cash;
    if (key === 'user') return !completed.branch || (props.fiscalization.uses_cash && !completed.device);
    if (key === 'bank') return !completed.company;
    return !completed.certificate || !completed.branch || !completed.user || (props.fiscalization.uses_cash && !completed.device);
}

function selectStep(key) {
    if (!isLocked(key) || props.fiscalization.steps[key]) activeStep.value = key;
}
</script>

<template>
    <Head :title="`Fiskalizimi · ${tenant.name}`" />
    <SuperAdminLayout :title="`Fiskalizimi · ${tenant.name}`">
        <main class="sa-page max-w-[1320px] space-y-4">
            <div class="sa-breadcrumb"><Link href="/super-admin/onboarding" class="text-inherit no-underline">Onboarding</Link><span class="mx-2">/</span><Link :href="`/super-admin/onboarding/${tenant.id}`" class="text-inherit no-underline">{{ tenant.name }}</Link><span class="mx-2">/</span><span>Fature.al</span></div>

            <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div><p class="text-[10px] font-bold uppercase tracking-[.13em] text-emerald-700">Integrimi fiskal</p><h1 class="mt-1 text-[27px] font-semibold tracking-[-.035em]">Onboarding · Fature.al</h1><p class="mt-1 text-xs text-neutral-500">Konfiguro kompaninë, certifikatën dhe operatorin fiskal të {{ tenant.name }}.</p></div>
                <div class="flex items-center gap-3"><div class="text-right"><strong class="block text-lg">{{ fiscalization.progress }}%</strong><span class="text-[10px] text-neutral-500">{{ fiscalization.environment === 'production' ? 'Production' : 'Sandbox' }}</span></div><span class="grid h-11 w-11 place-items-center rounded-full bg-emerald-50 text-emerald-700"><ShieldCheck class="h-5 w-5" /></span></div>
            </header>

            <div v-if="!fiscalization.has_partner_token" class="flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-800"><XCircle class="h-5 w-5 shrink-0" /><div><strong>Token-i partner mungon në server</strong><p class="mt-1">Vendos <code>FATURE_AL_ONBOARDING_TOKEN</code> përpara regjistrimit të kompanisë.</p></div></div>
            <div v-if="error || fiscalization.last_error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-xs text-red-700">{{ error || fiscalization.last_error }}</div>

            <section class="sa-card overflow-hidden">
                <div class="grid divide-y divide-neutral-100 md:grid-cols-7 md:divide-x md:divide-y-0">
                    <button v-for="(step, index) in stepDefinitions" :key="step.key" type="button" class="relative flex min-h-[82px] items-center gap-3 p-3 text-left transition md:block" :class="activeStep === step.key ? 'bg-emerald-50' : isLocked(step.key) && !fiscalization.steps[step.key] ? 'cursor-not-allowed bg-neutral-50/70 opacity-50' : 'hover:bg-neutral-50'" @click="selectStep(step.key)">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg" :class="fiscalization.steps[step.key] ? 'bg-emerald-700 text-white' : activeStep === step.key ? 'bg-white text-emerald-700 shadow-sm' : 'bg-neutral-100 text-neutral-500'"><Check v-if="fiscalization.steps[step.key]" class="h-4 w-4" /><component :is="step.icon" v-else class="h-4 w-4" /></span>
                        <span class="md:mt-2 md:block"><strong class="block text-[10.5px]">{{ index + 1 }}. {{ step.title }}</strong><small class="text-[9px] text-neutral-500">{{ step.subtitle }}</small></span>
                    </button>
                </div>
            </section>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_300px]">
                <section class="sa-card overflow-hidden">
                    <div class="border-b border-neutral-200 px-5 py-4"><p class="text-[9px] font-bold uppercase tracking-[.12em] text-emerald-700">Hapi aktiv</p><h2 class="mt-1 text-lg font-semibold">{{ activeDefinition.title }}</h2><p class="mt-1 text-[11px] text-neutral-500">{{ activeDefinition.subtitle }}</p></div>

                    <form v-if="activeStep === 'company'" class="grid gap-4 p-5 sm:grid-cols-2" @submit.prevent="submit(registerForm, 'register')">
                        <label>Mjedisi<select v-model="registerForm.environment" class="mt-1 w-full"><option value="sandbox">Sandbox · demo.fature.al</option><option value="production">Production · fature.al</option></select></label>
                        <label>NIPT<input v-model.trim="registerForm.nuis" required class="mt-1 w-full uppercase" placeholder="L12345678A" /><small v-if="registerForm.environment === 'sandbox'" class="mt-1 block text-[9px] text-blue-600">Për flow test mund të përdorësh L62221018T.</small></label>
                        <label>Emri i kompanisë<input v-model="registerForm.name" required class="mt-1 w-full" /></label>
                        <label>Administratori<input v-model="registerForm.administrator" required class="mt-1 w-full" /></label>
                        <label class="sm:col-span-2">Adresa<input v-model="registerForm.address" required class="mt-1 w-full" /></label>
                        <label>Telefoni<input v-model="registerForm.phone" required class="mt-1 w-full" /></label>
                        <label>Email<input v-model="registerForm.email" required type="email" class="mt-1 w-full" /></label>
                        <label>Statusi TVSH<select v-model="registerForm.issuer_in_vat" class="mt-1 w-full"><option :value="null">Përcaktohet më vonë</option><option :value="true">Me TVSH</option><option :value="false">Pa TVSH</option></select></label>
                        <label>Nr. i fundit eInvoice<input v-model="registerForm.last_non_cash_einvoice_number" class="mt-1 w-full" placeholder="Opsionale" /></label>
                        <label class="flex items-center gap-2 rounded-xl border border-neutral-200 p-3 sm:col-span-2"><input v-model="registerForm.uses_cash" type="checkbox" /> Hoteli lëshon fatura cash dhe kërkon pajisje TCR.</label>
                        <div class="flex justify-end sm:col-span-2"><button class="sa-button sa-button-primary" :disabled="registerForm.processing || !fiscalization.has_partner_token"><LoaderCircle v-if="registerForm.processing" class="h-4 w-4 animate-spin" />Regjistro kompaninë</button></div>
                    </form>

                    <form v-else-if="activeStep === 'certificate'" class="space-y-4 p-5" @submit.prevent="uploadCertificate">
                        <div class="rounded-xl border border-blue-100 bg-blue-50 p-4 text-[11px] text-blue-700">Certifikata dërgohet direkt te Fature.al dhe nuk ruhet në Lora PMS.</div>
                        <label class="block">Certifikata .p12 ose .pfx<input ref="certificateInput" required type="file" accept=".p12,.pfx" class="mt-1 w-full rounded-xl border border-neutral-300 p-2 text-xs" /></label>
                        <label class="block">Fjalëkalimi i certifikatës<input v-model="certificateForm.password" required type="password" autocomplete="new-password" class="mt-1 w-full" /></label>
                        <div class="flex justify-end"><button class="sa-button sa-button-primary" :disabled="certificateForm.processing">Ngarko dhe verifiko</button></div>
                    </form>

                    <form v-else-if="activeStep === 'branch'" class="grid gap-4 p-5 sm:grid-cols-2" @submit.prevent="submit(branchForm, 'branch')">
                        <label>Emri i njësisë<input v-model="branchForm.name" required class="mt-1 w-full" /></label><label>Kodi i njësisë<input v-model="branchForm.business_unit_code" required class="mt-1 w-full" /></label>
                        <label>Administratori<input v-model="branchForm.administrator" required class="mt-1 w-full" /></label><label>Adresa<input v-model="branchForm.address" required class="mt-1 w-full" /></label>
                        <div class="flex justify-end sm:col-span-2"><button class="sa-button sa-button-primary" :disabled="branchForm.processing">Ruaj njësinë</button></div>
                    </form>

                    <form v-else-if="activeStep === 'device'" class="grid gap-4 p-5 sm:grid-cols-2" @submit.prevent="submit(deviceForm, 'device')">
                        <label class="sm:col-span-2">Emri i pajisjes<input v-model="deviceForm.name" required class="mt-1 w-full" /></label><label>Aktive nga<input v-model="deviceForm.from_date" required type="date" class="mt-1 w-full" /></label><label>Aktive deri<input v-model="deviceForm.to_date" type="date" class="mt-1 w-full" /></label>
                        <div class="flex justify-end sm:col-span-2"><button class="sa-button sa-button-primary" :disabled="deviceForm.processing">Krijo TCR</button></div>
                    </form>

                    <form v-else-if="activeStep === 'user'" class="grid gap-4 p-5 sm:grid-cols-2" @submit.prevent="submit(userForm, 'user')">
                        <label>Emri i operatorit<input v-model="userForm.name" required class="mt-1 w-full" /></label><label>Kodi i operatorit<input v-model="userForm.operator_code" required class="mt-1 w-full" /></label>
                        <div class="flex justify-end sm:col-span-2"><button class="sa-button sa-button-primary" :disabled="userForm.processing">Konfiguro operatorin</button></div>
                    </form>

                    <form v-else-if="activeStep === 'bank'" class="grid gap-4 p-5 sm:grid-cols-2" @submit.prevent="submit(bankForm, 'bank-account')">
                        <label>Emri i bankës<input v-model="bankForm.name" class="mt-1 w-full" /></label><label>Mbajtësi<input v-model="bankForm.holder" class="mt-1 w-full" /></label><label class="sm:col-span-2">IBAN<input v-model="bankForm.iban" required class="mt-1 w-full uppercase" /></label><label>SWIFT<input v-model="bankForm.swift" class="mt-1 w-full uppercase" /></label><label>Monedha<input v-model="bankForm.currency" maxlength="3" class="mt-1 w-full uppercase" /></label>
                        <div class="flex justify-end sm:col-span-2"><button class="sa-button sa-button-primary" :disabled="bankForm.processing">Shto llogarinë</button></div>
                    </form>

                    <form v-else class="p-5" @submit.prevent="submit(verifyForm, 'verify')">
                        <div class="rounded-xl border border-emerald-100 bg-emerald-50 p-5"><ShieldCheck class="h-8 w-8 text-emerald-700" /><h3 class="mt-3 text-base font-semibold">Kontrolli final i llogarisë</h3><p class="mt-1 max-w-xl text-[11px] leading-5 text-neutral-600">Lora do të lexojë llogarinë nga Fature.al, do të verifikojë token-in dhe do ta aktivizojë fiskalizimin për hotelin.</p></div>
                        <div class="mt-4 flex justify-end"><button class="sa-button sa-button-primary" :disabled="verifyForm.processing"><LoaderCircle v-if="verifyForm.processing" class="h-4 w-4 animate-spin" />Testo dhe aktivizo</button></div>
                    </form>
                </section>

                <aside class="space-y-3">
                    <section class="sa-card"><div class="sa-card-header"><div><h2 class="sa-card-title">Gjendja</h2><p class="sa-card-subtitle">Të dhëna jo-sensitive.</p></div></div><dl class="divide-y divide-neutral-100 px-4 text-[10.5px]"><div class="flex justify-between gap-3 py-3"><dt class="text-neutral-500">Token API</dt><dd class="font-semibold" :class="fiscalization.has_api_token ? 'text-emerald-700' : 'text-amber-700'">{{ fiscalization.has_api_token ? 'I ruajtur' : 'Mungon' }}</dd></div><div class="flex justify-between gap-3 py-3"><dt class="text-neutral-500">Branch ID</dt><dd class="font-semibold">{{ fiscalization.branch.id || '—' }}</dd></div><div class="flex justify-between gap-3 py-3"><dt class="text-neutral-500">TCR</dt><dd class="max-w-[150px] truncate font-semibold">{{ fiscalization.device.fiscal_tcr_code || '—' }}</dd></div><div class="flex justify-between gap-3 py-3"><dt class="text-neutral-500">Operatori</dt><dd class="font-semibold">{{ fiscalization.user.operator_code || '—' }}</dd></div><div class="flex justify-between gap-3 py-3"><dt class="text-neutral-500">IBAN</dt><dd class="max-w-[150px] truncate font-semibold">{{ fiscalization.bank.iban || '—' }}</dd></div></dl></section>
                    <section class="sa-card p-4"><strong class="text-xs">User-Agent</strong><code class="mt-2 block rounded-lg bg-neutral-900 px-3 py-2 text-[10px] text-white">LoraPMS/&lt;build-version&gt;</code><p class="mt-2 text-[9.5px] leading-4 text-neutral-500">Dërgohet nga backend-i në çdo request për whitelist në firewall.</p></section>
                    <Link :href="`/super-admin/onboarding/${tenant.id}`" class="sa-button sa-button-secondary w-full justify-center">Kthehu te onboarding <ChevronRight class="h-4 w-4" /></Link>
                </aside>
            </div>
        </main>
    </SuperAdminLayout>
</template>
