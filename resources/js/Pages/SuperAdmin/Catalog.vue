<script setup>
import { reactive, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import BillingPageHeader from '@/Components/SuperAdmin/BillingPageHeader.vue';
import { translate } from '@/i18n';
import { Tags, RotateCcw } from 'lucide-vue-next';

const props = defineProps({ modules: { type: Array, default: () => [] } });

// UI punon në EURO (dhe % për booking); serveri merr CENTS/BPS.
const toEuro = (cents) => (cents === null || cents === undefined ? null : cents / 100);
const toCents = (euro) => (euro === null || euro === undefined || euro === '' ? null : Math.round(Number(euro) * 100));

const buildRows = (modules) => modules.map((module) => ({
    code: module.code,
    name: module.name,
    description: module.description,
    billing_model: module.billing_model,
    unit_label: module.unit_label,
    defaults: module.defaults,
    has_override: module.has_override,
    updated_by: module.updated_by,
    updated_at: module.updated_at,
    unit_price: toEuro(module.active.unit_price_cents),
    first_unit_price: toEuro(module.active.first_unit_price_cents),
    excess_unit_price: toEuro(module.active.excess_unit_price_cents),
    tier_limit: module.active.tier_limit,
    percentage: module.active.percentage_bps === null ? null : module.active.percentage_bps / 100,
    calculator_default: module.calculator_active,
    calculator_base: module.calculator_base,
}));

const rows = reactive(buildRows(props.modules));

// Pas ruajtjes Inertia sjell props të freskëta por e ruan gjendjen e
// komponentit — pa këtë resync, "Ndryshuar nga…" dhe "Rikthe në bazë"
// mbeteshin të vjetra deri në rifreskim të plotë (gjetje Codex, PR #428).
watch(() => props.modules, (modules) => {
    rows.splice(0, rows.length, ...buildRows(modules));
});

const form = useForm({ modules: [] });

const money = (value) => {
    const number = Number(value) || 0;
    return `€${Number.isInteger(number) ? number : number.toFixed(2)}`;
};

// Parapamja live e formulës — e njëjta gjuhë si dialogu i abonimit.
const preview = (row) => {
    switch (row.billing_model) {
        case 'per_user':
            return translate('superAdmin.moduleList.pricePerUnit', { price: money(row.unit_price), unit: row.unit_label });
        case 'per_pos':
            return translate('superAdmin.moduleList.pricePos', { first: money(row.first_unit_price), extra: money(row.unit_price) });
        case 'tiered_per_room':
            return translate('superAdmin.moduleList.priceTiered', { price: money(row.unit_price), unit: row.unit_label, limit: row.tier_limit || 0, excess: money(row.excess_unit_price) });
        case 'percentage':
            return `${Number(row.percentage) || 0}% / ${row.unit_label}`;
        default:
            return translate('superAdmin.moduleList.priceFlat', { price: money(row.unit_price) });
    }
};

const basePreview = (row) => {
    const base = {
        ...row,
        unit_price: toEuro(row.defaults.unit_price_cents),
        first_unit_price: toEuro(row.defaults.first_unit_price_cents),
        excess_unit_price: toEuro(row.defaults.excess_unit_price_cents),
        tier_limit: row.defaults.tier_limit,
        percentage: row.defaults.percentage_bps === null ? null : row.defaults.percentage_bps / 100,
    };
    return preview(base);
};

const resetRow = (row) => {
    row.unit_price = toEuro(row.defaults.unit_price_cents);
    row.first_unit_price = toEuro(row.defaults.first_unit_price_cents);
    row.excess_unit_price = toEuro(row.defaults.excess_unit_price_cents);
    row.tier_limit = row.defaults.tier_limit;
    row.percentage = row.defaults.percentage_bps === null ? null : row.defaults.percentage_bps / 100;
    row.calculator_default = row.calculator_base;
};

function save() {
    form.transform(() => ({
        modules: rows.map((row) => ({
            code: row.code,
            unit_price_cents: toCents(row.unit_price),
            first_unit_price_cents: toCents(row.first_unit_price),
            excess_unit_price_cents: toCents(row.excess_unit_price),
            tier_limit: row.tier_limit === null || row.tier_limit === '' ? null : Number(row.tier_limit),
            percentage_bps: row.percentage === null || row.percentage === '' ? null : Math.round(Number(row.percentage) * 100),
            calculator_default: Boolean(row.calculator_default),
        })),
    })).put('/super-admin/catalog', { preserveScroll: true });
}

function dateTime(value) {
    return value ? new Intl.DateTimeFormat('sq-AL', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : null;
}

const inputClass = 'w-24 rounded-lg border-neutral-300 py-1.5 text-right text-xs tabular-nums';
</script>

<template>
    <SuperAdminLayout :title="$t('superAdmin.catalog.pageTitle')">
        <main class="sa-page max-w-[1080px] space-y-4">
            <BillingPageHeader :title="$t('superAdmin.catalog.title')" :subtitle="$t('superAdmin.catalog.subtitle')" />

            <section class="sa-card">
                <div class="sa-card-header">
                    <div>
                        <h2 class="sa-card-title">{{ $t('superAdmin.catalog.listTitle') }}</h2>
                        <p class="sa-card-subtitle">{{ $t('superAdmin.catalog.snapshotNote') }}</p>
                    </div>
                    <span class="sa-icon-box bg-emerald-50 text-emerald-700"><Tags class="sa-icon" /></span>
                </div>

                <form @submit.prevent="save">
                    <div class="divide-y divide-neutral-100">
                        <div v-for="row in rows" :key="row.code" class="flex flex-wrap items-start gap-x-4 gap-y-2 px-4 py-3">
                            <div class="min-w-0 flex-1 basis-52">
                                <strong class="block text-xs text-neutral-900">{{ row.name }}</strong>
                                <p class="mt-0.5 text-[10px] leading-4 text-neutral-500">{{ row.description }}</p>
                                <p class="mt-1 text-[10px] font-semibold text-emerald-700">{{ preview(row) }}</p>
                                <p v-if="row.has_override" class="mt-0.5 text-[10px] text-amber-600">
                                    {{ $t('superAdmin.catalog.changedBy', { name: row.updated_by || '—', date: dateTime(row.updated_at) || '—' }) }}
                                    · {{ $t('superAdmin.catalog.basePrefix') }} {{ basePreview(row) }}
                                </p>
                            </div>

                            <div class="flex shrink-0 flex-wrap items-end gap-3">
                                <template v-if="row.billing_model === 'percentage'">
                                    <label class="block text-[10px] font-semibold text-neutral-600">
                                        {{ $t('superAdmin.catalog.percent') }}
                                        <div class="mt-1 flex items-center gap-1">
                                            <input v-model.number="row.percentage" type="number" min="0" max="100" step="0.1" :class="inputClass" />
                                            <span class="text-[10px] text-neutral-500">%</span>
                                        </div>
                                    </label>
                                </template>
                                <template v-else-if="row.billing_model === 'per_pos'">
                                    <label class="block text-[10px] font-semibold text-neutral-600">
                                        {{ $t('superAdmin.catalog.firstUnit') }}
                                        <div class="mt-1 flex items-center gap-1"><span class="text-[10px] text-neutral-500">€</span><input v-model.number="row.first_unit_price" type="number" min="0" step="1" :class="inputClass" /></div>
                                    </label>
                                    <label class="block text-[10px] font-semibold text-neutral-600">
                                        {{ $t('superAdmin.catalog.extraUnit') }}
                                        <div class="mt-1 flex items-center gap-1"><span class="text-[10px] text-neutral-500">€</span><input v-model.number="row.unit_price" type="number" min="0" step="1" :class="inputClass" /></div>
                                    </label>
                                </template>
                                <template v-else-if="row.billing_model === 'tiered_per_room'">
                                    <label class="block text-[10px] font-semibold text-neutral-600">
                                        {{ $t('superAdmin.catalog.price') }}
                                        <div class="mt-1 flex items-center gap-1"><span class="text-[10px] text-neutral-500">€</span><input v-model.number="row.unit_price" type="number" min="0" step="0.5" :class="inputClass" /></div>
                                    </label>
                                    <label class="block text-[10px] font-semibold text-neutral-600">
                                        {{ $t('superAdmin.catalog.tierLimit') }}
                                        <input v-model.number="row.tier_limit" type="number" min="1" step="1" class="mt-1 block" :class="inputClass" />
                                    </label>
                                    <label class="block text-[10px] font-semibold text-neutral-600">
                                        {{ $t('superAdmin.catalog.excessPrice') }}
                                        <div class="mt-1 flex items-center gap-1"><span class="text-[10px] text-neutral-500">€</span><input v-model.number="row.excess_unit_price" type="number" min="0" step="0.5" :class="inputClass" /></div>
                                    </label>
                                </template>
                                <template v-else>
                                    <label class="block text-[10px] font-semibold text-neutral-600">
                                        {{ $t('superAdmin.catalog.price') }}
                                        <div class="mt-1 flex items-center gap-1"><span class="text-[10px] text-neutral-500">€</span><input v-model.number="row.unit_price" type="number" min="0" step="1" :class="inputClass" /></div>
                                    </label>
                                </template>

                                <label class="mb-1 flex items-center gap-1.5 text-[10px] font-semibold text-neutral-600" :title="$t('superAdmin.catalog.inCalculatorTitle')">
                                    <input v-model="row.calculator_default" type="checkbox" class="h-3.5 w-3.5 rounded border-neutral-300 text-emerald-700 focus:ring-emerald-600" />
                                    {{ $t('superAdmin.catalog.inCalculator') }}
                                </label>

                                <button
                                    v-if="row.has_override"
                                    type="button"
                                    class="mb-1 inline-flex items-center gap-1 rounded-lg border border-neutral-200 px-2 py-1.5 text-[10px] font-semibold text-neutral-600 hover:border-neutral-400 hover:text-neutral-900"
                                    :title="$t('superAdmin.catalog.resetTitle')"
                                    @click="resetRow(row)"
                                >
                                    <RotateCcw class="h-3 w-3" /> {{ $t('superAdmin.catalog.reset') }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-3 border-t border-neutral-100 px-4 py-3">
                        <p v-if="Object.keys(form.errors).length" class="text-xs text-red-600">{{ Object.values(form.errors)[0] }}</p>
                        <p v-else class="text-[10px] text-neutral-500">{{ $t('superAdmin.catalog.applyNote') }}</p>
                        <button type="submit" :disabled="form.processing" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-xs font-semibold text-white hover:bg-emerald-800 disabled:opacity-50">
                            {{ form.processing ? $t('superAdmin.catalog.saving') : $t('superAdmin.catalog.save') }}
                        </button>
                    </div>
                </form>
            </section>
        </main>
    </SuperAdminLayout>
</template>
