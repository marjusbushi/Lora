<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import BillingPageHeader from '@/Components/SuperAdmin/BillingPageHeader.vue';
import { translate } from '@/i18n';
import { BadgeCheck, CircleAlert, Clock3 } from 'lucide-vue-next';

const props = defineProps({ attempts: Object, tenants: Array, filters: Object, stats: Object });
const cards = computed(() => [
    { label: translate('superAdmin.billing.statusInProcess'), value: props.stats.pending, detail: translate('superAdmin.billing.awaitingResult'), icon: Clock3 },
    { label: translate('superAdmin.billing.statusSuccess'), value: props.stats.succeeded, detail: translate('superAdmin.billing.confirmedPayments'), icon: BadgeCheck },
    { label: translate('superAdmin.billing.statusFailed'), value: props.stats.failed, detail: translate('superAdmin.billing.needReview'), icon: CircleAlert },
]);

function money(cents, currency) {
    return new Intl.NumberFormat('sq-AL', { style: 'currency', currency, minimumFractionDigits: 2 }).format((cents || 0) / 100);
}

function dateTime(value) {
    return value ? new Intl.DateTimeFormat('sq-AL', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
}

function statusLabel(status) {
    return {
        succeeded: translate('superAdmin.billing.statusSuccess'),
        failed: translate('superAdmin.billing.statusFailed'),
        pending: translate('superAdmin.billing.statusPending'),
        processing: translate('superAdmin.billing.statusInProcess'),
        requires_action: translate('superAdmin.billing.statusRequiresAction'),
        canceled: translate('superAdmin.billing.statusCanceled'),
    }[status] || status;
}

function statusClass(status) {
    return { succeeded: 'bg-emerald-50 text-emerald-700', failed: 'bg-red-50 text-red-700', pending: 'bg-amber-50 text-amber-700', processing: 'bg-blue-50 text-blue-700', requires_action: 'bg-purple-50 text-purple-700', canceled: 'bg-neutral-100 text-neutral-600' }[status] || 'bg-neutral-100 text-neutral-600';
}

function filter(key, value) {
    router.get('/super-admin/billing/payment-attempts', { ...props.filters, [key]: value || undefined }, { preserveState: true, replace: true });
}
</script>

<template>
    <SuperAdminLayout :title="$t('superAdmin.billing.attemptsPageTitle')">
        <main class="sa-page max-w-[1320px] space-y-4">
            <BillingPageHeader :title="$t('superAdmin.billing.paymentAttempts')" :subtitle="$t('superAdmin.billing.attemptsSubtitle')" />

            <section class="grid gap-3 md:grid-cols-3">
                <article v-for="card in cards" :key="card.label" class="sa-card sa-kpi-card">
                    <div class="flex items-start justify-between gap-4">
                        <div><p class="sa-kpi-label">{{ card.label }}</p><p class="sa-kpi-value">{{ card.value }}</p><p class="sa-kpi-meta">{{ card.detail }}</p></div>
                        <span class="sa-icon-box bg-emerald-50 text-emerald-700"><component :is="card.icon" class="sa-icon" /></span>
                    </div>
                </article>
            </section>

            <section class="sa-card">
                <div class="sa-card-header flex-col items-stretch md:flex-row md:items-end">
                    <div><h2 class="sa-card-title">{{ $t('superAdmin.billing.attemptsList') }}</h2><p class="sa-card-subtitle">{{ $t('superAdmin.billing.attemptsListSubtitle') }}</p></div>
                    <div class="flex flex-wrap gap-2">
                        <label>{{ $t('superAdmin.compact.hotel') }}<select :value="filters.tenant_id || ''" class="sa-control mt-1 block min-w-[160px]" @change="filter('tenant_id', $event.target.value)"><option value="">{{ $t('superAdmin.compact.all') }}</option><option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">{{ tenant.name }}</option></select></label>
                        <label>{{ $t('superAdmin.compact.status') }}<select :value="filters.status || ''" class="sa-control mt-1 block min-w-[140px]" @change="filter('status', $event.target.value)"><option value="">{{ $t('superAdmin.activity.all') }}</option><option value="pending">{{ $t('superAdmin.billing.statusPending') }}</option><option value="processing">{{ $t('superAdmin.billing.statusInProcess') }}</option><option value="requires_action">{{ $t('superAdmin.billing.statusRequiresAction') }}</option><option value="succeeded">{{ $t('superAdmin.billing.statusSuccess') }}</option><option value="failed">{{ $t('superAdmin.billing.statusFailed') }}</option><option value="canceled">{{ $t('superAdmin.billing.statusCanceled') }}</option></select></label>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left">
                        <thead><tr class="sa-table-head"><th class="px-4 py-2.5 font-bold">{{ $t('superAdmin.billing.attempt') }}</th><th class="px-4 py-2.5 font-bold">{{ $t('superAdmin.compact.hotel') }}</th><th class="px-4 py-2.5 font-bold">{{ $t('superAdmin.billing.invoicePayment') }}</th><th class="px-4 py-2.5 font-bold">Provider</th><th class="px-4 py-2.5 font-bold">{{ $t('superAdmin.activity.date') }}</th><th class="px-4 py-2.5 font-bold">{{ $t('superAdmin.compact.status') }}</th><th class="px-4 py-2.5 text-right font-bold">{{ $t('superAdmin.billing.amount') }}</th></tr></thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="attempt in attempts.data" :key="attempt.id" class="hover:bg-neutral-50">
                                <td class="px-4 py-3"><Link :href="`/super-admin/billing/payment-attempts/${attempt.id}`" class="sa-table-primary text-emerald-700 no-underline">#{{ attempt.id }} · {{ attempt.attempt_number }}</Link><p class="sa-table-meta font-mono">{{ attempt.provider_attempt_id || $t('superAdmin.billing.withoutExternalId') }}</p></td>
                                <td class="px-4 py-3"><Link :href="`/super-admin/tenants/${attempt.tenant.id}`" class="sa-table-primary no-underline hover:text-emerald-700">{{ attempt.tenant.name }}</Link></td>
                                <td class="px-4 py-3 text-xs"><Link v-if="attempt.invoice" :href="`/super-admin/billing/invoices/${attempt.invoice.id}`" class="block font-semibold text-emerald-700 no-underline">{{ attempt.invoice.number }}</Link><Link v-if="attempt.payment" :href="`/super-admin/billing/payments/${attempt.payment.id}`" class="sa-table-meta block no-underline">{{ attempt.payment.number }}</Link><span v-if="!attempt.invoice && !attempt.payment">—</span></td>
                                <td class="px-4 py-3 text-xs text-neutral-600"><span class="capitalize">{{ attempt.provider }}</span><p class="sa-table-meta">{{ $t('superAdmin.billing.eventsCount', { count: attempt.events_count }) }}</p></td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs text-neutral-500">{{ dateTime(attempt.attempted_at) }}</td>
                                <td class="px-4 py-3"><span class="rounded-full px-2 py-1 text-[10px] font-bold" :class="statusClass(attempt.status)">{{ statusLabel(attempt.status) }}</span></td>
                                <td class="px-4 py-3 text-right text-xs font-semibold">{{ money(attempt.amount_cents, attempt.currency) }}</td>
                            </tr>
                            <tr v-if="!attempts.data.length"><td colspan="7" class="px-4 py-10 text-center text-xs text-neutral-400">{{ $t('superAdmin.billing.noAttemptsYet') }}</td></tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="attempts.links?.length > 3" class="flex justify-end gap-1 border-t border-neutral-200 px-4 py-3"><Link v-for="link in attempts.links" :key="link.label" :href="link.url || '#'" class="rounded-lg px-3 py-1.5 text-[11px] no-underline" :class="link.active ? 'bg-emerald-700 text-white' : 'text-neutral-500 hover:bg-neutral-100'" v-html="link.label" /></div>
            </section>
        </main>
    </SuperAdminLayout>
</template>
