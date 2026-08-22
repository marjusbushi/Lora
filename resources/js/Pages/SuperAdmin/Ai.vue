<script setup>
import { useForm, router } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import BillingPageHeader from '@/Components/SuperAdmin/BillingPageHeader.vue';
import { Sparkles, RotateCw } from 'lucide-vue-next';

const props = defineProps({ ai: Object });

const form = useForm({
    gemini_key: '',
    clear_key: false,
});

function save() {
    form.put('/super-admin/ai', { preserveScroll: true, onSuccess: () => form.reset('gemini_key', 'clear_key') });
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
