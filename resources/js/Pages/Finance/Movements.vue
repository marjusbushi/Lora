<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { Banknote, TrendingDown, TrendingUp } from 'lucide-vue-next';
import { money } from './financeShared';

const { t } = useI18n();

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    totals: { type: Object, default: () => ({}) },
    baseCurrency: String,
});

const filterState = computed(() => ({
    account_id: props.filters.account_id || '',
    from: props.filters.from || '',
    to: props.filters.to || '',
    movement: props.filters.movement || '',
}));

function applyFilters(overrides = {}) {
    const params = { ...filterState.value, ...overrides };
    Object.keys(params).forEach((key) => { if (!params[key]) delete params[key]; });
    router.get(route('finance.movements'), params, { preserveScroll: true, preserveState: true });
}

function formatDate(value) {
    return value ? new Intl.DateTimeFormat('sq-AL', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : '—';
}
</script>

<template>
    <Head :title="t('financeMovements.pageTitle')" />
    <AppLayout>
        <div class="mx-auto max-w-[1400px] pb-10">
            <header class="mb-5">
                <nav class="mb-1 flex items-center gap-1.5 text-xs text-neutral-500">
                    <span>{{ t('financeAccounts.finance') }}</span><span>/</span><span>{{ t('financeMovements.pageTitle') }}</span>
                </nav>
                <h1 class="text-2xl font-extrabold tracking-tight text-neutral-950">{{ t('financeMovements.title') }}</h1>
                <p class="mt-1 text-sm text-neutral-500">{{ t('financeMovements.subtitle') }}</p>
            </header>

            <section class="grid gap-3 sm:grid-cols-3">
                <article class="rounded-2xl border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between"><p class="text-sm font-semibold text-neutral-500">{{ t('financeMovements.totalDeposits') }}</p><TrendingUp class="h-5 w-5 text-emerald-700" /></div>
                    <p class="mt-2 text-2xl font-extrabold tabular-nums text-neutral-950">{{ money(totals.deposits, baseCurrency) }}</p>
                </article>
                <article class="rounded-2xl border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between"><p class="text-sm font-semibold text-neutral-500">{{ t('financeMovements.totalWithdrawals') }}</p><TrendingDown class="h-5 w-5 text-error-600" /></div>
                    <p class="mt-2 text-2xl font-extrabold tabular-nums text-neutral-950">{{ money(totals.withdrawals, baseCurrency) }}</p>
                </article>
                <article class="rounded-2xl border border-neutral-200 bg-white p-4 shadow-card">
                    <div class="flex items-start justify-between"><p class="text-sm font-semibold text-neutral-500">{{ t('financeMovements.net') }}</p><Banknote class="h-5 w-5 text-emerald-700" /></div>
                    <p class="mt-2 text-2xl font-extrabold tabular-nums" :class="totals.net >= 0 ? 'text-neutral-950' : 'text-error-600'">{{ money(totals.net, baseCurrency) }}</p>
                </article>
            </section>

            <section class="mt-5 rounded-2xl border border-neutral-200 bg-white p-4 shadow-card">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <div>
                        <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ t('financeMovements.account') }}</label>
                        <select :value="filterState.account_id" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm" @change="applyFilters({ account_id: $event.target.value })">
                            <option value="">{{ t('financeMovements.allAccounts') }}</option>
                            <option v-for="accountItem in accounts" :key="accountItem.id" :value="accountItem.id">{{ accountItem.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ t('financeMovements.kind') }}</label>
                        <select :value="filterState.movement" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm" @change="applyFilters({ movement: $event.target.value })">
                            <option value="">{{ t('financeMovements.allKinds') }}</option>
                            <option value="deposit">{{ t('financeAccounts.movementDeposit') }}</option>
                            <option value="withdrawal">{{ t('financeAccounts.movementWithdrawal') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ t('financeMovements.from') }}</label>
                        <TextInput :model-value="filterState.from" type="date" class="w-full" @update:model-value="applyFilters({ from: $event })" />
                    </div>
                    <div>
                        <label class="mb-1 block text-body-sm font-semibold text-primary-900">{{ t('financeMovements.to') }}</label>
                        <TextInput :model-value="filterState.to" type="date" class="w-full" @update:model-value="applyFilters({ to: $event })" />
                    </div>
                    <div class="flex items-end">
                        <Button variant="ghost" type="button" @click="applyFilters({ account_id: '', movement: '', from: '', to: '' })">{{ t('financeMovements.clear') }}</Button>
                    </div>
                </div>
            </section>

            <section class="mt-5 overflow-x-auto rounded-2xl border border-neutral-200 bg-white shadow-card">
                <table class="w-full text-body-sm tabular-nums">
                    <thead>
                        <tr class="border-b border-neutral-100 text-left text-tiny uppercase tracking-wide text-neutral-400">
                            <th class="px-4 py-3">{{ t('financeMovements.date') }}</th>
                            <th class="px-4 py-3">{{ t('financeMovements.description') }}</th>
                            <th class="px-4 py-3">{{ t('financeMovements.account') }}</th>
                            <th class="px-4 py-3">{{ t('financeMovements.byWhom') }}</th>
                            <th class="px-4 py-3">{{ t('financeMovements.kind') }}</th>
                            <th class="px-4 py-3 text-right">{{ t('financeMovements.amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-b border-neutral-50 last:border-0 hover:bg-neutral-50">
                            <td class="whitespace-nowrap px-4 py-3 text-neutral-500">{{ formatDate(row.paid_at) }}</td>
                            <td class="px-4 py-3 font-semibold text-neutral-900">{{ row.description }}</td>
                            <td class="px-4 py-3 text-neutral-600">{{ row.account }}</td>
                            <td class="px-4 py-3 text-neutral-500">{{ row.created_by || '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-1 text-[10px] font-bold" :class="row.movement === 'deposit' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'">
                                    {{ row.movement === 'deposit' ? t('financeAccounts.movementDeposit') : t('financeAccounts.movementWithdrawal') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right font-semibold" :class="row.movement === 'deposit' ? 'text-emerald-700' : 'text-error-600'">
                                {{ row.movement === 'deposit' ? '+' : '−' }}{{ money(row.amount, row.currency) }}
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="6" class="px-4 py-10 text-center">
                                <p class="text-sm font-semibold text-neutral-700">{{ t('financeMovements.empty') }}</p>
                                <p class="mt-1 text-xs text-neutral-400">{{ t('financeMovements.emptyHint') }}</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AppLayout>
</template>
