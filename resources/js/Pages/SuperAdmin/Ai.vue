<script setup>
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import BillingPageHeader from '@/Components/SuperAdmin/BillingPageHeader.vue';
import { Sparkles, Bot, RotateCw, ArrowLeftRight } from 'lucide-vue-next';

const props = defineProps({ ai: Object, openai: Object, providers: Object });

// Shpjegimi i rezervës: i fshehur si parazgjedhje (minimalizmi), i hapshëm me
// klik — jo vetëm hover, që ta arrijnë edhe prekja/tastiera (Codex P2 #596).
const showFallbackHint = ref(false);

const form = useForm({
    gemini_key: '',
    clear_key: false,
});

const openaiForm = useForm({
    openai_key: '',
    clear_openai_key: false,
});

const providerForm = useForm({
    provider_default: props.providers.default,
    cross_fallback: props.providers.cross_fallback,
    provider_overrides: Object.fromEntries(
        (props.providers.tenants || []).map((t) => [t.id, props.providers.overrides?.[t.id] ?? '']),
    ),
});

// Chip-i "efektiv" ndjek LIVE zgjedhjet e formës (override || default), jo
// vetëm gjendjen e ruajtur të serverit — admini sheh pasojën para se të ruajë.
const effectiveFor = (tenant) => providerForm.provider_overrides[tenant.id] || providerForm.provider_default;

const tenantInitials = (name = '') => name.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();

function save() {
    form.put('/super-admin/ai', { preserveScroll: true, onSuccess: () => form.reset('gemini_key', 'clear_key') });
}

function saveOpenai() {
    openaiForm.put('/super-admin/ai', { preserveScroll: true, onSuccess: () => openaiForm.reset('openai_key', 'clear_openai_key') });
}

function saveProviders() {
    providerForm.put('/super-admin/ai', { preserveScroll: true });
}

function checkNow() {
    router.post('/super-admin/ai/check', {}, { preserveScroll: true });
}

function dateTime(value) {
    return value ? new Intl.DateTimeFormat('sq-AL', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : null;
}
</script>

<template>
    <SuperAdminLayout :title="$t('superAdmin.ai.pageTitle')">
        <main class="sa-page max-w-[1080px] space-y-4">
            <BillingPageHeader :title="$t('superAdmin.ai.title')" :subtitle="$t('superAdmin.ai.subtitle')" />

            <!-- DY karta shoferësh binjake — statusi, modeli DHE shëndeti brenda kartës
                 së vet (karta e ndarë "Shëndeti" + modelsLine u shkrinë këtu). -->
            <div class="grid items-start gap-4 lg:grid-cols-2">
                <section class="sa-card overflow-hidden">
                    <div class="flex items-center gap-2.5 px-4 pt-4">
                        <span class="grid h-9 w-9 place-items-center rounded-[11px] bg-gradient-to-br from-emerald-100 to-emerald-200/80 text-emerald-800 ring-1 ring-inset ring-emerald-200/60"><Sparkles class="h-4 w-4" /></span>
                        <h2 class="text-[13.5px] font-bold">Google Gemini</h2>
                        <span class="rounded-full px-2.5 py-1 text-[9.5px] font-bold ring-1 ring-inset" :class="ai.configured ? 'bg-emerald-50 text-emerald-700 ring-emerald-200/60' : 'bg-amber-50 text-amber-700 ring-amber-200/60'">{{ ai.configured ? $t('superAdmin.auto.copy005') : $t('superAdmin.ai.notConfiguredChip') }}</span>
                    </div>

                    <div v-if="ai.configured" class="flex flex-wrap gap-1.5 px-4 pt-2.5">
                        <span class="chrome-chip">{{ $t('superAdmin.ai.modelChip') }} <b class="font-mono !text-[9.5px]">{{ ai.model }}</b></span>
                        <span v-if="ai.fallback_model" class="chrome-chip">{{ $t('superAdmin.ai.fallbackChip') }} <b class="font-mono !text-[9.5px]">{{ ai.fallback_model }}</b></span>
                        <span v-if="ai.key_hint" class="chrome-chip">{{ $t('superAdmin.ai.keyChip') }} <b class="font-mono !text-[9.5px]">{{ ai.key_hint }}</b></span>
                        <span v-if="ai.from_env" class="chrome-chip"><b>{{ $t('superAdmin.ai.fromEnv') }}</b></span>
                    </div>
                    <div v-else class="mx-4 mt-2.5 rounded-xl border border-amber-200 border-l-4 border-l-amber-400 bg-gradient-to-r from-amber-50 to-amber-50/40 px-3 py-2 text-[10.5px] font-semibold text-amber-800">{{ $t('superAdmin.ai.notConfigured') }}</div>

                    <!-- Shëndeti i KËTIJ shoferi — kontrolli dhe rezultati bashkë -->
                    <div class="mx-4 mt-3 flex items-start gap-2.5 rounded-xl border px-3 py-2 text-[10.5px]" :class="ai.health && ai.health.ok ? 'border-emerald-200 bg-gradient-to-r from-emerald-50 to-white text-emerald-800' : ai.health ? 'border-red-200 bg-gradient-to-r from-red-50 to-white text-red-800' : 'border-neutral-200 bg-neutral-50/60 text-neutral-500'">
                        <span class="mt-[5px] h-2 w-2 shrink-0 rounded-full" :class="ai.health && ai.health.ok ? 'bg-emerald-500 shadow-[0_0_0_3px_rgba(29,157,120,.15)]' : ai.health ? 'bg-red-500 shadow-[0_0_0_3px_rgba(220,38,38,.15)]' : 'bg-neutral-300'" />
                        <span class="min-w-0 flex-1 break-words">
                            <template v-if="ai.health && ai.health.ok"><strong>{{ $t('superAdmin.ai.healthOk') }}</strong> · {{ dateTime(ai.health.checked_at) }}</template>
                            <template v-else-if="ai.health"><strong>{{ $t('superAdmin.ai.healthFail') }}</strong> {{ ai.health.error }} · {{ dateTime(ai.health.checked_at) }}</template>
                            <template v-else>{{ $t('superAdmin.ai.healthNone') }}</template>
                        </span>
                        <button type="button" class="inline-flex shrink-0 items-center gap-1 rounded-full border border-neutral-200 bg-white px-2.5 py-1 text-[9.5px] font-bold text-neutral-600 transition-all hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700 disabled:opacity-40" :disabled="!ai.configured" @click="checkNow"><RotateCw class="h-3 w-3" />{{ $t('superAdmin.ai.checkNow') }}</button>
                    </div>

                    <form class="space-y-3 p-4" @submit.prevent="save">
                        <div>
                            <label class="mb-1 block text-[10.5px] font-bold text-neutral-500">{{ $t('superAdmin.ai.apiKey') }}</label>
                            <input
                                v-model="form.gemini_key"
                                type="password"
                                autocomplete="off"
                                :placeholder="ai.key_hint ? $t('superAdmin.ai.apiKeySavedPlaceholder', { hint: ai.key_hint }) : $t('superAdmin.ai.apiKeyPlaceholder')"
                                class="w-full rounded-[10px] border-neutral-300 bg-neutral-50/60 text-sm shadow-sm focus:border-emerald-500 focus:bg-white focus:ring-emerald-500/30"
                            >
                            <p v-if="form.errors.gemini_key" class="mt-1 text-xs text-red-600">{{ form.errors.gemini_key }}</p>
                            <p class="mt-1 text-[10px] text-neutral-400">
                                <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener" class="font-bold text-emerald-700 hover:text-emerald-800">aistudio.google.com/apikey</a>
                            </p>
                        </div>

                        <!-- Etiketë e ndershme: me çelës serveri në .env, heqja s'e fik trurin — kalon te ai (Codex #559). -->
                        <label v-if="ai.key_hint" class="flex cursor-pointer items-center gap-2.5 text-[10.5px] font-semibold text-neutral-600">
                            <input v-model="form.clear_key" type="checkbox" class="h-5 w-5 rounded-[7px] border-[1.5px] border-neutral-300 text-red-600 shadow-sm transition-all focus:ring-red-500/40">
                            {{ ai.env_key_present ? $t('superAdmin.ai.removeSavedKeyEnv') : $t('superAdmin.ai.removeSavedKey') }}
                        </label>

                        <div class="flex justify-end">
                            <button type="submit" class="sa-button sa-button-primary" :disabled="form.processing">{{ $t('superAdmin.ai.save') }}</button>
                        </div>
                    </form>
                </section>

                <section class="sa-card overflow-hidden">
                    <div class="flex items-center gap-2.5 px-4 pt-4">
                        <span class="grid h-9 w-9 place-items-center rounded-[11px] bg-gradient-to-br from-emerald-100 to-emerald-200/80 text-emerald-800 ring-1 ring-inset ring-emerald-200/60"><Bot class="h-4 w-4" /></span>
                        <h2 class="text-[13.5px] font-bold">OpenAI</h2>
                        <span class="rounded-full px-2.5 py-1 text-[9.5px] font-bold ring-1 ring-inset" :class="openai.configured ? 'bg-emerald-50 text-emerald-700 ring-emerald-200/60' : 'bg-neutral-100 text-neutral-500 ring-neutral-200/70'">{{ openai.configured ? $t('superAdmin.auto.copy005') : $t('superAdmin.ai.notConfiguredChip') }}</span>
                    </div>

                    <div v-if="openai.configured" class="flex flex-wrap gap-1.5 px-4 pt-2.5">
                        <span class="chrome-chip">{{ $t('superAdmin.ai.modelChip') }} <b class="font-mono !text-[9.5px]">{{ openai.model }}</b></span>
                        <span v-if="openai.key_hint" class="chrome-chip">{{ $t('superAdmin.ai.keyChip') }} <b class="font-mono !text-[9.5px]">{{ openai.key_hint }}</b></span>
                        <span v-if="openai.from_env" class="chrome-chip"><b>{{ $t('superAdmin.ai.fromEnv') }}</b></span>
                    </div>
                    <div v-else class="mx-4 mt-2.5 rounded-xl border border-neutral-200 bg-neutral-50/60 px-3 py-2 text-[10.5px] font-semibold text-neutral-500">{{ $t('superAdmin.ai.openaiNotConfigured') }}</div>

                    <form class="space-y-3 p-4" @submit.prevent="saveOpenai">
                        <div>
                            <label class="mb-1 block text-[10.5px] font-bold text-neutral-500">{{ $t('superAdmin.ai.openaiApiKey') }}</label>
                            <input
                                v-model="openaiForm.openai_key"
                                type="password"
                                autocomplete="off"
                                :placeholder="openai.key_hint ? $t('superAdmin.ai.apiKeySavedPlaceholder', { hint: openai.key_hint }) : $t('superAdmin.ai.apiKeyPlaceholder')"
                                class="w-full rounded-[10px] border-neutral-300 bg-neutral-50/60 text-sm shadow-sm focus:border-emerald-500 focus:bg-white focus:ring-emerald-500/30"
                            >
                            <p v-if="openaiForm.errors.openai_key" class="mt-1 text-xs text-red-600">{{ openaiForm.errors.openai_key }}</p>
                            <p class="mt-1 text-[10px] text-neutral-400">
                                <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener" class="font-bold text-emerald-700 hover:text-emerald-800">platform.openai.com/api-keys</a>
                            </p>
                        </div>

                        <label v-if="openai.key_hint" class="flex cursor-pointer items-center gap-2.5 text-[10.5px] font-semibold text-neutral-600">
                            <input v-model="openaiForm.clear_openai_key" type="checkbox" class="h-5 w-5 rounded-[7px] border-[1.5px] border-neutral-300 text-red-600 shadow-sm transition-all focus:ring-red-500/40">
                            {{ openai.env_key_present ? $t('superAdmin.ai.removeSavedKeyEnv') : $t('superAdmin.ai.openaiRemoveSavedKey') }}
                        </label>

                        <div class="flex justify-end">
                            <button type="submit" class="sa-button sa-button-primary" :disabled="openaiForm.processing">{{ $t('superAdmin.ai.save') }}</button>
                        </div>
                    </form>
                </section>
            </div>

            <!-- RRUGËZIMI: kryesori si segmented, rezerva si toggle, efektivi LIVE -->
            <section class="sa-card overflow-hidden">
                <div class="flex items-center gap-2.5 border-b border-neutral-200 p-4">
                    <span class="grid h-9 w-9 place-items-center rounded-[11px] bg-gradient-to-br from-emerald-100 to-emerald-200/80 text-emerald-800 ring-1 ring-inset ring-emerald-200/60"><ArrowLeftRight class="h-4 w-4" /></span>
                    <div><h2 class="text-[13.5px] font-bold">{{ $t('superAdmin.ai.providerTitle') }}</h2><p class="mt-0.5 text-[10.5px] text-neutral-500">{{ $t('superAdmin.ai.providerSubtitle') }}</p></div>
                </div>

                <form @submit.prevent="saveProviders">
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-3 border-b border-neutral-100 px-4 py-3.5">
                        <div class="flex items-center gap-2.5">
                            <span class="text-[10.5px] font-bold text-neutral-500">{{ $t('superAdmin.ai.providerDefault') }}</span>
                            <span class="inline-flex rounded-full bg-neutral-100 p-[3px]">
                                <button v-for="p in providers.options" :key="p" type="button" class="rounded-full px-4 py-1.5 text-[10.5px] font-bold transition-all" :class="providerForm.provider_default === p ? 'bg-white text-emerald-800 shadow-sm' : 'text-neutral-500 hover:text-neutral-700'" @click="providerForm.provider_default = p">{{ p }}</button>
                            </span>
                        </div>
                        <label class="flex cursor-pointer items-center gap-2.5 text-[10.5px] font-bold text-neutral-600">
                            <button type="button" class="relative h-[21px] w-[38px] rounded-full transition-colors" :class="providerForm.cross_fallback ? 'bg-emerald-600' : 'bg-neutral-300'" @click="providerForm.cross_fallback = !providerForm.cross_fallback"><span class="absolute top-[2.5px] h-4 w-4 rounded-full bg-white shadow transition-all" :class="providerForm.cross_fallback ? 'left-[19px]' : 'left-[3px]'" /></button>
                            {{ $t('superAdmin.ai.crossFallback') }}
                        </label>
                        <button type="button" class="grid h-[18px] w-[18px] place-items-center rounded-full border border-neutral-300 text-[10px] font-bold text-neutral-500 transition-colors hover:border-emerald-300 hover:text-emerald-700" :aria-expanded="showFallbackHint" :aria-label="$t('superAdmin.ai.crossFallback')" @click="showFallbackHint = !showFallbackHint">?</button>
                    </div>
                    <p v-if="showFallbackHint" class="border-b border-neutral-100 px-4 py-2 text-[10px] text-neutral-500">{{ $t('superAdmin.ai.crossFallbackHint') }}</p>

                    <div class="divide-y divide-neutral-100">
                        <div v-for="t in providers.tenants" :key="t.id" class="grid grid-cols-[34px_minmax(0,1fr)] items-center gap-3 px-4 py-2.5 hover:bg-neutral-50/60 sm:grid-cols-[34px_minmax(0,1fr)_auto_auto]">
                            <span class="grid h-8 w-8 place-items-center rounded-[9px] bg-gradient-to-br from-emerald-100 to-emerald-200/80 text-[10px] font-bold text-emerald-900">{{ tenantInitials(t.name) }}</span>
                            <strong class="truncate text-[11.5px]">{{ t.name }}</strong>
                            <div class="col-span-2 flex flex-wrap items-center gap-2 pl-[46px] sm:col-span-1 sm:pl-0">
                                <select v-model="providerForm.provider_overrides[t.id]" class="rounded-full border-neutral-200 py-1 pl-3 pr-8 text-[10px] font-bold text-neutral-600 shadow-sm focus:border-emerald-400 focus:ring-emerald-500/30">
                                    <option value="">{{ $t('superAdmin.ai.followDefault') }}</option>
                                    <option v-for="p in providers.options" :key="p" :value="p">{{ p }}</option>
                                </select>
                            </div>
                            <span class="hidden rounded-full bg-emerald-50 px-2.5 py-1 font-mono text-[9.5px] font-bold text-emerald-700 ring-1 ring-inset ring-emerald-200/50 sm:inline-flex">{{ effectiveFor(t) }}</span>
                        </div>
                    </div>

                    <div class="flex justify-end border-t border-neutral-200 p-3.5">
                        <button type="submit" class="sa-button sa-button-primary" :disabled="providerForm.processing">{{ $t('superAdmin.ai.routingSave') }}</button>
                    </div>
                </form>
            </section>
        </main>
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
    font-size: 10px;
    color: #68766f;
    box-shadow: 0 1px 1.5px rgba(23, 33, 29, 0.03);
}
.chrome-chip b {
    color: #17211d;
    font-weight: 650;
}
</style>
