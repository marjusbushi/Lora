<script setup>
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { translate } from '@/i18n';
import { Building2, Check, ChevronRight, FileKey2, Landmark, LoaderCircle, MonitorSmartphone, ShieldCheck, UserRound, XCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps({ tenant: Object, fiscalization: Object });
const today = new Date().toISOString().slice(0, 10);
const certificateInput = ref(null);
const stepDefinitions = [
    { key: 'company', title: translate('superAdmin.onboarding.stepCompany'), subtitle: translate('superAdmin.onboarding.stepCompanySubtitle'), icon: Building2 },
    { key: 'certificate', title: translate('superAdmin.onboarding.stepCertificate'), subtitle: translate('superAdmin.onboarding.stepCertificateSubtitle'), icon: FileKey2 },
    { key: 'branch', title: translate('superAdmin.onboarding.stepBranch'), subtitle: translate('superAdmin.onboarding.stepBranchSubtitle'), icon: Landmark },
    { key: 'device', title: translate('superAdmin.onboarding.stepDevice'), subtitle: translate('superAdmin.onboarding.stepDeviceSubtitle'), icon: MonitorSmartphone },
    { key: 'user', title: translate('superAdmin.onboarding.stepUser'), subtitle: translate('superAdmin.onboarding.operatorCode'), icon: UserRound },
    { key: 'bank', title: translate('superAdmin.onboarding.stepBank'), subtitle: translate('superAdmin.onboarding.optional'), icon: Landmark },
    { key: 'verify', title: translate('superAdmin.onboarding.stepVerify'), subtitle: translate('superAdmin.onboarding.stepVerifySubtitle'), icon: ShieldCheck },
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

// Partner-token availability for the SELECTED environment (falls back to the
// legacy sandbox-only flag for older payloads).
const partnerTokens = props.fiscalization.partner_tokens
    || { sandbox: props.fiscalization.has_partner_token, production: false };
const hasTokenForSelectedEnv = () => !!partnerTokens[registerForm.environment];
const tokenEnvName = () => registerForm.environment === 'production'
    ? 'FATURE_AL_ONBOARDING_TOKEN_PRODUCTION'
    : 'FATURE_AL_ONBOARDING_TOKEN';
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
    <Head :title="$t('superAdmin.onboarding.fiscalizationTitle', { name: tenant.name })" />
    <SuperAdminLayout :title="$t('superAdmin.onboarding.fiscalizationTitle', { name: tenant.name })">
        <main class="sa-page max-w-[1320px] space-y-4">
            <div class="sa-breadcrumb"><Link href="/super-admin/onboarding" class="text-inherit no-underline">Onboarding</Link><span class="mx-2">/</span><Link :href="`/super-admin/onboarding/${tenant.id}`" class="text-inherit no-underline">{{ tenant.name }}</Link><span class="mx-2">/</span><span>Fature.al</span></div>

            <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div><p class="text-[10px] font-bold uppercase tracking-[.13em] text-emerald-700">{{ $t('superAdmin.onboarding.fiscalIntegration') }}</p><h1 class="mt-1 text-[27px] font-semibold tracking-[-.035em]">Onboarding · Fature.al</h1><p class="mt-1 text-xs text-neutral-500">{{ $t('superAdmin.onboarding.fatureSubtitle', { name: tenant.name }) }}</p></div>
                <div class="flex items-center gap-3"><div class="text-right"><strong class="block text-lg">{{ fiscalization.progress }}%</strong><span class="text-[10px] text-neutral-500">{{ fiscalization.environment === 'production' ? 'Production' : 'Sandbox' }}</span></div><span class="grid h-11 w-11 place-items-center rounded-full bg-emerald-50 text-emerald-700"><ShieldCheck class="h-5 w-5" /></span></div>
            </header>

            <div v-if="!hasTokenForSelectedEnv()" class="flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-800"><XCircle class="h-5 w-5 shrink-0" /><div><strong>{{ $t('superAdmin.onboarding.partnerTokenMissing') }}</strong><p class="mt-1">{{ $t('superAdmin.onboarding.setTokenPrefix') }} <code>{{ tokenEnvName() }}</code> {{ $t('superAdmin.onboarding.setTokenSuffix') }}</p></div></div>
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
                    <div class="border-b border-neutral-200 px-5 py-4"><p class="text-[9px] font-bold uppercase tracking-[.12em] text-emerald-700">{{ $t('superAdmin.onboarding.activeStep') }}</p><h2 class="mt-1 text-lg font-semibold">{{ activeDefinition.title }}</h2><p class="mt-1 text-[11px] text-neutral-500">{{ activeDefinition.subtitle }}</p></div>

                    <form v-if="activeStep === 'company'" class="grid gap-4 p-5 sm:grid-cols-2" @submit.prevent="submit(registerForm, 'register')">
                        <label>{{ $t('superAdmin.tenantShow.environment') }}<select v-model="registerForm.environment" class="mt-1 w-full"><option value="sandbox">Sandbox · demo.fature.al</option><option value="production">Production · fature.al</option></select><small class="mt-1 block text-[9px]" :class="registerForm.environment === 'production' ? 'text-amber-700 font-semibold' : 'text-neutral-500'">{{ registerForm.environment === 'production' ? $t('superAdmin.onboarding.productionLiveNote') : $t('superAdmin.onboarding.productionAfterApproval') }}</small></label>
                        <label>NIPT<input v-model.trim="registerForm.nuis" required class="mt-1 w-full uppercase" placeholder="L12345678A" /><small v-if="registerForm.environment === 'sandbox'" class="mt-1 block text-[9px] text-blue-600">{{ $t('superAdmin.onboarding.sandboxTestNipt') }}</small></label>
                        <label>{{ $t('superAdmin.onboarding.companyName') }}<input v-model="registerForm.name" required class="mt-1 w-full" /></label>
                        <label>{{ $t('superAdmin.activity.administrator') }}<input v-model="registerForm.administrator" required class="mt-1 w-full" /></label>
                        <label class="sm:col-span-2">{{ $t('superAdmin.onboarding.address') }}<input v-model="registerForm.address" required class="mt-1 w-full" /></label>
                        <label>{{ $t('superAdmin.onboarding.phone') }}<input v-model="registerForm.phone" required class="mt-1 w-full" /></label>
                        <label>Email<input v-model="registerForm.email" required type="email" class="mt-1 w-full" /></label>
                        <label>{{ $t('superAdmin.onboarding.vatStatus') }}<select v-model="registerForm.issuer_in_vat" class="mt-1 w-full"><option :value="null">{{ $t('superAdmin.onboarding.decidedLater') }}</option><option :value="true">{{ $t('superAdmin.onboarding.withVat') }}</option><option :value="false">{{ $t('superAdmin.onboarding.withoutVat') }}</option></select></label>
                        <label>{{ $t('superAdmin.onboarding.lastEinvoiceNumber') }}<input v-model="registerForm.last_non_cash_einvoice_number" class="mt-1 w-full" :placeholder="$t('superAdmin.onboarding.optional')" /></label>
                        <label class="flex items-center gap-2 rounded-xl border border-neutral-200 p-3 sm:col-span-2"><input v-model="registerForm.uses_cash" type="checkbox" /> {{ $t('superAdmin.onboarding.usesCashNote') }}</label>
                        <div class="flex justify-end sm:col-span-2"><button class="sa-button sa-button-primary" :disabled="registerForm.processing || !hasTokenForSelectedEnv()"><LoaderCircle v-if="registerForm.processing" class="h-4 w-4 animate-spin" />{{ $t('superAdmin.onboarding.registerCompany') }}</button></div>
                    </form>

                    <form v-else-if="activeStep === 'certificate'" class="space-y-4 p-5" @submit.prevent="uploadCertificate">
                        <div class="rounded-xl border border-blue-100 bg-blue-50 p-4 text-[11px] text-blue-700">{{ $t('superAdmin.onboarding.certificateNotStored') }}</div>
                        <label class="block">{{ $t('superAdmin.onboarding.certificateFile') }}<input ref="certificateInput" required type="file" accept=".p12,.pfx" class="mt-1 w-full rounded-xl border border-neutral-300 p-2 text-xs" /></label>
                        <label class="block">{{ $t('superAdmin.onboarding.certificatePassword') }}<input v-model="certificateForm.password" required type="password" autocomplete="new-password" class="mt-1 w-full" /></label>
                        <div class="flex justify-end"><button class="sa-button sa-button-primary" :disabled="certificateForm.processing">{{ $t('superAdmin.onboarding.uploadAndVerify') }}</button></div>
                    </form>

                    <form v-else-if="activeStep === 'branch'" class="grid gap-4 p-5 sm:grid-cols-2" @submit.prevent="submit(branchForm, 'branch')">
                        <label>{{ $t('superAdmin.onboarding.branchName') }}<input v-model="branchForm.name" required class="mt-1 w-full" /></label><label>{{ $t('superAdmin.onboarding.branchCode') }}<input v-model="branchForm.business_unit_code" required class="mt-1 w-full" /></label>
                        <label>{{ $t('superAdmin.activity.administrator') }}<input v-model="branchForm.administrator" required class="mt-1 w-full" /></label><label>{{ $t('superAdmin.onboarding.address') }}<input v-model="branchForm.address" required class="mt-1 w-full" /></label>
                        <div class="flex justify-end sm:col-span-2"><button class="sa-button sa-button-primary" :disabled="branchForm.processing">{{ $t('superAdmin.onboarding.saveBranch') }}</button></div>
                    </form>

                    <form v-else-if="activeStep === 'device'" class="grid gap-4 p-5 sm:grid-cols-2" @submit.prevent="submit(deviceForm, 'device')">
                        <label class="sm:col-span-2">{{ $t('superAdmin.onboarding.deviceName') }}<input v-model="deviceForm.name" required class="mt-1 w-full" /></label><label>{{ $t('superAdmin.onboarding.activeFrom') }}<DatePicker v-model="deviceForm.from_date" :input-attrs="{ required: true }" class="mt-1 w-full" /></label><label>{{ $t('superAdmin.onboarding.activeUntil') }}<DatePicker v-model="deviceForm.to_date" :min="deviceForm.from_date" class="mt-1 w-full" /></label>
                        <div class="flex justify-end sm:col-span-2"><button class="sa-button sa-button-primary" :disabled="deviceForm.processing">{{ $t('superAdmin.onboarding.createTcr') }}</button></div>
                    </form>

                    <form v-else-if="activeStep === 'user'" class="grid gap-4 p-5 sm:grid-cols-2" @submit.prevent="submit(userForm, 'user')">
                        <label>{{ $t('superAdmin.onboarding.operatorName') }}<input v-model="userForm.name" required class="mt-1 w-full" /></label><label>{{ $t('superAdmin.onboarding.operatorCode') }}<input v-model="userForm.operator_code" required class="mt-1 w-full" /></label>
                        <div class="flex justify-end sm:col-span-2"><button class="sa-button sa-button-primary" :disabled="userForm.processing">{{ $t('superAdmin.onboarding.configureOperator') }}</button></div>
                    </form>

                    <form v-else-if="activeStep === 'bank'" class="grid gap-4 p-5 sm:grid-cols-2" @submit.prevent="submit(bankForm, 'bank-account')">
                        <label>{{ $t('superAdmin.onboarding.bankName') }}<input v-model="bankForm.name" class="mt-1 w-full" /></label><label>{{ $t('superAdmin.onboarding.accountHolder') }}<input v-model="bankForm.holder" class="mt-1 w-full" /></label><label class="sm:col-span-2">IBAN<input v-model="bankForm.iban" required class="mt-1 w-full uppercase" /></label><label>SWIFT<input v-model="bankForm.swift" class="mt-1 w-full uppercase" /></label><label>{{ $t('superAdmin.tenantShow.currency') }}<input v-model="bankForm.currency" maxlength="3" class="mt-1 w-full uppercase" /></label>
                        <div class="flex justify-end sm:col-span-2"><button class="sa-button sa-button-primary" :disabled="bankForm.processing">{{ $t('superAdmin.onboarding.addAccount') }}</button></div>
                    </form>

                    <form v-else class="p-5" @submit.prevent="submit(verifyForm, 'verify')">
                        <div class="rounded-xl border border-emerald-100 bg-emerald-50 p-5"><ShieldCheck class="h-8 w-8 text-emerald-700" /><h3 class="mt-3 text-base font-semibold">{{ $t('superAdmin.onboarding.finalAccountCheck') }}</h3><p class="mt-1 max-w-xl text-[11px] leading-5 text-neutral-600">{{ $t('superAdmin.onboarding.finalCheckDescription') }}</p></div>
                        <div class="mt-4 flex justify-end"><button class="sa-button sa-button-primary" :disabled="verifyForm.processing"><LoaderCircle v-if="verifyForm.processing" class="h-4 w-4 animate-spin" />{{ $t('superAdmin.onboarding.testAndActivate') }}</button></div>
                    </form>
                </section>

                <aside class="space-y-3">
                    <section class="sa-card"><div class="sa-card-header"><div><h2 class="sa-card-title">{{ $t('superAdmin.onboarding.state') }}</h2><p class="sa-card-subtitle">{{ $t('superAdmin.onboarding.nonSensitiveData') }}</p></div></div><dl class="divide-y divide-neutral-100 px-4 text-[10.5px]"><div class="flex justify-between gap-3 py-3"><dt class="text-neutral-500">Token API</dt><dd class="font-semibold" :class="fiscalization.has_api_token ? 'text-emerald-700' : 'text-amber-700'">{{ fiscalization.has_api_token ? $t('superAdmin.onboarding.saved') : $t('superAdmin.dynamic.missing') }}</dd></div><div class="flex justify-between gap-3 py-3"><dt class="text-neutral-500">Branch ID</dt><dd class="font-semibold">{{ fiscalization.branch.id || '—' }}</dd></div><div class="flex justify-between gap-3 py-3"><dt class="text-neutral-500">TCR</dt><dd class="max-w-[150px] truncate font-semibold">{{ fiscalization.device.fiscal_tcr_code || '—' }}</dd></div><div class="flex justify-between gap-3 py-3"><dt class="text-neutral-500">{{ $t('superAdmin.onboarding.stepUser') }}</dt><dd class="font-semibold">{{ fiscalization.user.operator_code || '—' }}</dd></div><div class="flex justify-between gap-3 py-3"><dt class="text-neutral-500">IBAN</dt><dd class="max-w-[150px] truncate font-semibold">{{ fiscalization.bank.iban || '—' }}</dd></div></dl></section>
                    <section class="sa-card p-4"><strong class="text-xs">User-Agent</strong><code class="mt-2 block rounded-lg bg-neutral-900 px-3 py-2 text-[10px] text-white">LoraPMS/&lt;build-version&gt;</code><p class="mt-2 text-[9.5px] leading-4 text-neutral-500">{{ $t('superAdmin.onboarding.userAgentNote') }}</p></section>
                    <Link :href="`/super-admin/onboarding/${tenant.id}`" class="sa-button sa-button-secondary w-full justify-center">{{ $t('superAdmin.onboarding.backToOnboarding') }} <ChevronRight class="h-4 w-4" /></Link>
                </aside>
            </div>
        </main>
    </SuperAdminLayout>
</template>
