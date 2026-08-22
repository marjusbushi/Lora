<script setup>
import { useForm, router } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import BillingPageHeader from '@/Components/SuperAdmin/BillingPageHeader.vue';
import { Sparkles, RotateCw, ArrowLeftRight } from 'lucide-vue-next';

const props = defineProps({ ai: Object, openai: Object, providers: Object });

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

            <section class="sa-card">
                <div class="sa-card-header">
                    <div>
                        <h2 class="sa-card-title">{{ $t('superAdmin.ai.keyTitle') }}</h2>
                        <p class="sa-card-subtitle">{{ $t('superAdmin.ai.keySubtitle') }}</p>
                    </div>
                    <span class="sa-icon-box bg-violet-50 text-violet-700"><Sparkles class="sa-icon" /></span>
                </div>

                <form class="space-y-4 px-4 pb-4" @submit.prevent="save">
                    <div v-if="ai.configured" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800">
                        {{ $t('superAdmin.ai.activeFor', { model: ai.model }) }}
                        <span v-if="ai.from_env" class="ml-1 font-normal text-emerald-700">{{ $t('superAdmin.ai.fromEnv') }}</span>
                    </div>
                    <div v-else class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">
                        {{ $t('superAdmin.ai.notConfigured') }}
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-semibold text-neutral-700">{{ $t('superAdmin.ai.apiKey') }}</label>
                        <input
                            v-model="form.gemini_key"
                            type="password"
                            autocomplete="off"
                            :placeholder="ai.key_hint ? $t('superAdmin.ai.apiKeySavedPlaceholder', { hint: ai.key_hint }) : $t('superAdmin.ai.apiKeyPlaceholder')"
                            class="w-full max-w-md rounded-lg border-neutral-300 text-sm focus:border-violet-600 focus:ring-violet-600"
                        >
                        <p v-if="form.errors.gemini_key" class="mt-1 text-xs text-red-600">{{ form.errors.gemini_key }}</p>
                        <p class="mt-1 text-[11px] text-neutral-500">
                            {{ $t('superAdmin.ai.getKeyHint') }}
                            <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener" class="font-semibold text-violet-700 underline">aistudio.google.com/apikey</a>
                        </p>
                    </div>

                    <!-- Etiketë e ndershme: me çelës serveri në .env, heqja s'e fik trurin — kalon te ai (Codex #559). -->
                    <label v-if="ai.key_hint" class="flex items-center gap-2.5 text-xs font-semibold text-neutral-700">
                        <input v-model="form.clear_key" type="checkbox" class="h-4 w-4 rounded border-neutral-300 text-red-600 focus:ring-red-500">
                        {{ ai.env_key_present ? $t('superAdmin.ai.removeSavedKeyEnv') : $t('superAdmin.ai.removeSavedKey') }}
                    </label>

                    <div class="flex items-center gap-3">
                        <button type="submit" class="sa-button" :disabled="form.processing">{{ $t('superAdmin.ai.save') }}</button>
                        <button type="button" class="sa-button !bg-neutral-900" :disabled="!ai.configured" @click="checkNow">
                            <RotateCw class="sa-icon" /> {{ $t('superAdmin.ai.checkNow') }}
                        </button>
                    </div>
                </form>
            </section>

            <!-- Shoferi i dytë: OpenAI (piloti Luna, task #408) -->
            <section class="sa-card">
                <div class="sa-card-header">
                    <div>
                        <h2 class="sa-card-title">{{ $t('superAdmin.ai.openaiTitle') }}</h2>
                        <p class="sa-card-subtitle">{{ $t('superAdmin.ai.openaiSubtitle') }}</p>
                    </div>
                </div>

                <form class="space-y-4 px-4 pb-4" @submit.prevent="saveOpenai">
                    <div v-if="openai.configured" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800">
                        {{ $t('superAdmin.ai.openaiActiveFor', { model: openai.model }) }}
                        <span v-if="openai.from_env" class="ml-1 font-normal text-emerald-700">{{ $t('superAdmin.ai.fromEnv') }}</span>
                    </div>
                    <div v-else class="rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2 text-xs font-semibold text-neutral-600">
                        {{ $t('superAdmin.ai.openaiNotConfigured') }}
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-semibold text-neutral-700">{{ $t('superAdmin.ai.openaiApiKey') }}</label>
                        <input
                            v-model="openaiForm.openai_key"
                            type="password"
                            autocomplete="off"
                            :placeholder="openai.key_hint ? $t('superAdmin.ai.apiKeySavedPlaceholder', { hint: openai.key_hint }) : $t('superAdmin.ai.apiKeyPlaceholder')"
                            class="w-full max-w-md rounded-lg border-neutral-300 text-sm focus:border-violet-600 focus:ring-violet-600"
                        >
                        <p v-if="openaiForm.errors.openai_key" class="mt-1 text-xs text-red-600">{{ openaiForm.errors.openai_key }}</p>
                        <p class="mt-1 text-[11px] text-neutral-500">
                            {{ $t('superAdmin.ai.getKeyHint') }}
                            <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener" class="font-semibold text-violet-700 underline">platform.openai.com/api-keys</a>
                        </p>
                    </div>

                    <label v-if="openai.key_hint" class="flex items-center gap-2.5 text-xs font-semibold text-neutral-700">
                        <input v-model="openaiForm.clear_openai_key" type="checkbox" class="h-4 w-4 rounded border-neutral-300 text-red-600 focus:ring-red-500">
                        {{ openai.env_key_present ? $t('superAdmin.ai.removeSavedKeyEnv') : $t('superAdmin.ai.openaiRemoveSavedKey') }}
                    </label>

                    <button type="submit" class="sa-button" :disabled="openaiForm.processing">{{ $t('superAdmin.ai.save') }}</button>
                </form>
            </section>

            <!-- Provideri per-hotel + rezerva ndër-provider — vendim i platformës, kurrë i hotelit -->
            <section class="sa-card">
                <div class="sa-card-header">
                    <div>
                        <h2 class="sa-card-title">{{ $t('superAdmin.ai.providerTitle') }}</h2>
                        <p class="sa-card-subtitle">{{ $t('superAdmin.ai.providerSubtitle') }}</p>
                    </div>
                    <span class="sa-icon-box bg-sky-50 text-sky-700"><ArrowLeftRight class="sa-icon" /></span>
                </div>

                <form class="space-y-4 px-4 pb-4" @submit.prevent="saveProviders">
                    <div class="flex flex-wrap items-center gap-4">
                        <label class="text-xs font-semibold text-neutral-700">
                            {{ $t('superAdmin.ai.providerDefault') }}
                            <select v-model="providerForm.provider_default" class="ml-2 rounded-lg border-neutral-300 text-sm">
                                <option v-for="p in providers.options" :key="p" :value="p">{{ p }}</option>
                            </select>
                        </label>
                        <label class="flex items-center gap-2.5 text-xs font-semibold text-neutral-700">
                            <input v-model="providerForm.cross_fallback" type="checkbox" class="h-4 w-4 rounded border-neutral-300 text-sky-700 focus:ring-sky-600">
                            {{ $t('superAdmin.ai.crossFallback') }}
                        </label>
                    </div>
                    <p class="text-[11px] text-neutral-500">{{ $t('superAdmin.ai.crossFallbackHint') }}</p>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left">
                            <thead>
                                <tr class="sa-table-head">
                                    <th class="px-3 py-2 font-bold">{{ $t('superAdmin.ai.tenantColumn') }}</th>
                                    <th class="px-3 py-2 font-bold">{{ $t('superAdmin.ai.overrideColumn') }}</th>
                                    <th class="px-3 py-2 font-bold">{{ $t('superAdmin.ai.effectiveColumn') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-100">
                                <tr v-for="t in providers.tenants" :key="t.id" class="hover:bg-neutral-50">
                                    <td class="px-3 py-2 text-xs font-semibold text-neutral-800">{{ t.name }}</td>
                                    <td class="px-3 py-2">
                                        <select v-model="providerForm.provider_overrides[t.id]" class="rounded-lg border-neutral-300 text-xs">
                                            <option value="">{{ $t('superAdmin.ai.followDefault') }}</option>
                                            <option v-for="p in providers.options" :key="p" :value="p">{{ p }}</option>
                                        </select>
                                    </td>
                                    <td class="px-3 py-2 font-mono text-xs text-neutral-600">{{ t.effective }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <button type="submit" class="sa-button" :disabled="providerForm.processing">{{ $t('superAdmin.ai.save') }}</button>
                </form>
            </section>

            <section class="sa-card">
                <div class="sa-card-header">
                    <div>
                        <h2 class="sa-card-title">{{ $t('superAdmin.ai.healthTitle') }}</h2>
                        <p class="sa-card-subtitle">{{ $t('superAdmin.ai.healthSubtitle') }}</p>
                    </div>
                </div>
                <div class="px-4 pb-4">
                    <div v-if="ai.health && ai.health.ok" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
                        <strong>{{ $t('superAdmin.ai.healthOk') }}</strong>
                        <span class="ml-1 text-emerald-700">{{ $t('superAdmin.ai.checkedAt', { date: dateTime(ai.health.checked_at) }) }}</span>
                    </div>
                    <div v-else-if="ai.health" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">
                        <strong>{{ $t('superAdmin.ai.healthFail') }}</strong> {{ ai.health.error }}
                        <span class="ml-1 text-red-700">{{ $t('superAdmin.ai.checkedAt', { date: dateTime(ai.health.checked_at) }) }}</span>
                    </div>
                    <p v-else class="text-xs text-neutral-500">{{ $t('superAdmin.ai.healthNone') }}</p>

                    <p class="mt-3 text-[11px] text-neutral-500">
                        {{ $t('superAdmin.ai.modelsLine', { model: ai.model, fallback: ai.fallback_model || '—' }) }}
                    </p>
                </div>
            </section>
        </main>
    </SuperAdminLayout>
</template>
