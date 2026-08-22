<script setup>
import { reactive } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import BillingPageHeader from '@/Components/SuperAdmin/BillingPageHeader.vue';
import { Gauge } from 'lucide-vue-next';

const props = defineProps({
    month: String,
    months: Array,
    coefficient: Number,
    rows: Array,
    totals: Object,
    pricing: Object,
});

const billingForm = useForm({
    billing_coefficient: props.coefficient,
});

// Rreshtat e çmimores: mbivendosja dërgohet VETËM kur është realisht e
// tillë (Codex #569 P1) — default-et e paprekur mbeten te config dhe
// përditësimet e ardhshme të çmimeve s'maskohen; çelësi i mbivendosjes i
// hequr = kthim te default-i.
const pricingRows = reactive(Object.fromEntries(
    Object.entries(props.pricing || {}).map(([model, p]) => [model, {
        input: p.input,
        output: p.output,
        override: p.is_override,
        default: p.default,
    }]),
));

const pricingForm = useForm({ pricing_overrides: {} });

function toggleOverride(model) {
    const row = pricingRows[model];
    if (!row.override && row.default) {
        row.input = row.default.input;
        row.output = row.default.output;
    }
}

function changeMonth(event) {
    router.get('/super-admin/ai/usage', { month: event.target.value }, { preserveScroll: true });
}

function saveCoefficient() {
    billingForm.put('/super-admin/ai', { preserveScroll: true });
}

function savePricing() {
    pricingForm.pricing_overrides = Object.fromEntries(
        Object.entries(pricingRows)
            .filter(([, row]) => row.override)
            .map(([model, row]) => [model, { input: row.input, output: row.output }]),
    );
    pricingForm.put('/super-admin/ai', { preserveScroll: true });
}

function usd(value) {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 4 }).format(value ?? 0);
}

function num(value) {
    return new Intl.NumberFormat('sq-AL').format(value ?? 0);
}
</script>

<template>
    <SuperAdminLayout :title="$t('superAdmin.aiUsage.pageTitle')">
        <main class="sa-page max-w-[1080px] space-y-4">
            <BillingPageHeader :title="$t('superAdmin.aiUsage.title')" :subtitle="$t('superAdmin.aiUsage.subtitle')" />

            <section class="sa-card">
                <div class="sa-card-header">
                    <div>
                        <h2 class="sa-card-title">{{ $t('superAdmin.aiUsage.monthTitle') }}</h2>
                        <p class="sa-card-subtitle">{{ $t('superAdmin.aiUsage.monthSubtitle') }}</p>
                    </div>
                    <span class="sa-icon-box bg-sky-50 text-sky-700"><Gauge class="sa-icon" /></span>
                </div>

                <div class="space-y-4 px-4 pb-4">
                    <div class="flex flex-wrap items-center gap-4">
                        <label class="text-xs font-semibold text-neutral-700">
                            {{ $t('superAdmin.aiUsage.month') }}
                            <select :value="month" class="ml-2 rounded-lg border-neutral-300 text-sm" @change="changeMonth">
                                <option v-for="m in months" :key="m" :value="m">{{ m }}</option>
                            </select>
                        </label>

                        <form class="flex items-center gap-2" @submit.prevent="saveCoefficient">
                            <label class="text-xs font-semibold text-neutral-700">{{ $t('superAdmin.aiUsage.coefficient') }}</label>
                            <input
                                v-model="billingForm.billing_coefficient"
                                type="number"
                                step="0.01"
                                min="0"
                                class="w-24 rounded-lg border-neutral-300 text-sm"
                            >
                            <button type="submit" class="sa-button" :disabled="billingForm.processing">{{ $t('superAdmin.aiUsage.save') }}</button>
                        </form>
                    </div>
                    <p class="text-[11px] text-neutral-500">{{ $t('superAdmin.aiUsage.coefficientHint') }}</p>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div class="rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2">
                            <p class="text-[10px] font-bold uppercase tracking-wide text-neutral-500">{{ $t('superAdmin.aiUsage.totalCalls') }}</p>
                            <p class="text-lg font-bold text-neutral-800">{{ num(totals.calls) }}</p>
                        </div>
                        <div class="rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2">
                            <p class="text-[10px] font-bold uppercase tracking-wide text-neutral-500">{{ $t('superAdmin.aiUsage.totalCost') }}</p>
                            <p class="text-lg font-bold text-neutral-800">{{ usd(totals.cost_usd) }}</p>
                        </div>
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2">
                            <p class="text-[10px] font-bold uppercase tracking-wide text-emerald-700">{{ $t('superAdmin.aiUsage.totalBillable') }}</p>
                            <p class="text-lg font-bold text-emerald-800">{{ usd(totals.billable_usd) }}</p>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left">
                            <thead>
                                <tr class="sa-table-head">
                                    <th class="px-3 py-2 font-bold">{{ $t('superAdmin.aiUsage.tenant') }}</th>
                                    <th class="px-3 py-2 text-right font-bold">{{ $t('superAdmin.aiUsage.calls') }}</th>
                                    <th class="px-3 py-2 text-right font-bold">{{ $t('superAdmin.aiUsage.inputTokens') }}</th>
                                    <th class="px-3 py-2 text-right font-bold">{{ $t('superAdmin.aiUsage.outputTokens') }}</th>
                                    <th class="px-3 py-2 text-right font-bold">{{ $t('superAdmin.aiUsage.cost') }}</th>
                                    <th class="px-3 py-2 text-right font-bold">{{ $t('superAdmin.aiUsage.billable') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-100">
                                <tr v-for="row in rows" :key="row.tenant_id" class="align-top hover:bg-neutral-50">
                                    <td class="px-3 py-2">
                                        <p class="text-xs font-semibold text-neutral-800">{{ row.tenant }}</p>
                                        <p v-for="m in row.models" :key="m.provider + m.model" class="mt-0.5 font-mono text-[10px] text-neutral-500">
                                            {{ m.provider }}/{{ m.model }} · {{ num(m.calls) }} · {{ usd(m.cost_usd) }}
                                        </p>
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono text-xs">{{ num(row.calls) }}</td>
                                    <td class="px-3 py-2 text-right font-mono text-xs">{{ num(row.input_tokens) }}</td>
                                    <td class="px-3 py-2 text-right font-mono text-xs">{{ num(row.output_tokens) }}</td>
                                    <td class="px-3 py-2 text-right font-mono text-xs">{{ usd(row.cost_usd) }}</td>
                                    <td class="px-3 py-2 text-right font-mono text-xs font-semibold text-emerald-800">{{ usd(row.billable_usd) }}</td>
                                </tr>
                                <tr v-if="!rows.length">
                                    <td colspan="6" class="px-3 py-10 text-center text-xs text-neutral-500">{{ $t('superAdmin.aiUsage.empty') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="sa-card">
                <div class="sa-card-header">
                    <div>
                        <h2 class="sa-card-title">{{ $t('superAdmin.aiUsage.pricingTitle') }}</h2>
                        <p class="sa-card-subtitle">{{ $t('superAdmin.aiUsage.pricingSubtitle') }}</p>
                    </div>
                </div>
                <form class="space-y-3 px-4 pb-4" @submit.prevent="savePricing">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left">
                            <thead>
                                <tr class="sa-table-head">
                                    <th class="px-3 py-2 font-bold">{{ $t('superAdmin.aiUsage.model') }}</th>
                                    <th class="px-3 py-2 font-bold"></th>
                                    <th class="px-3 py-2 font-bold">{{ $t('superAdmin.aiUsage.inputPrice') }}</th>
                                    <th class="px-3 py-2 font-bold">{{ $t('superAdmin.aiUsage.outputPrice') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-100">
                                <tr v-for="(p, model) in pricingRows" :key="model">
                                    <td class="px-3 py-2 font-mono text-xs text-neutral-700">
                                        {{ model }}
                                        <span v-if="!p.default && !p.override" class="ml-1 rounded bg-amber-100 px-1 text-[10px] font-bold text-amber-800">{{ $t('superAdmin.aiUsage.noPriceWarning') }}</span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <label class="flex items-center gap-1.5 text-[11px] font-semibold text-neutral-600">
                                            <input v-model="p.override" type="checkbox" class="h-3.5 w-3.5 rounded border-neutral-300 text-violet-700" @change="toggleOverride(model)">
                                            {{ $t('superAdmin.aiUsage.overrideLabel') }}
                                        </label>
                                    </td>
                                    <td class="px-3 py-2">
                                        <input v-model.number="p.input" type="number" step="0.01" min="0" :disabled="!p.override" class="w-28 rounded-lg border-neutral-300 text-xs disabled:bg-neutral-100 disabled:text-neutral-400">
                                    </td>
                                    <td class="px-3 py-2">
                                        <input v-model.number="p.output" type="number" step="0.01" min="0" :disabled="!p.override" class="w-28 rounded-lg border-neutral-300 text-xs disabled:bg-neutral-100 disabled:text-neutral-400">
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-[11px] text-neutral-500">{{ $t('superAdmin.aiUsage.pricingHint') }}</p>
                    <button type="submit" class="sa-button" :disabled="pricingForm.processing">{{ $t('superAdmin.aiUsage.save') }}</button>
                </form>
            </section>
        </main>
    </SuperAdminLayout>
</template>
