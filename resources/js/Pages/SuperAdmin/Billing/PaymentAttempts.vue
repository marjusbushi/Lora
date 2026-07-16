<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import BillingPageHeader from '@/Components/SuperAdmin/BillingPageHeader.vue';
import { BadgeCheck, CircleAlert, Clock3 } from 'lucide-vue-next';

const props = defineProps({ attempts: Object, tenants: Array, filters: Object, stats: Object });
const cards = computed(() => [
    { label: 'Në proces', value: props.stats.pending, detail: 'Në pritje të rezultatit', icon: Clock3 },
    { label: 'Sukses', value: props.stats.succeeded, detail: 'Pagesa të konfirmuara', icon: BadgeCheck },
    { label: 'Dështuar', value: props.stats.failed, detail: 'Kërkojnë kontroll', icon: CircleAlert },
]);

function money(cents, currency) {
    return new Intl.NumberFormat('sq-AL', { style: 'currency', currency, minimumFractionDigits: 2 }).format((cents || 0) / 100);
}

function dateTime(value) {
    return value ? new Intl.DateTimeFormat('sq-AL', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
}

function statusLabel(status) {
    return { succeeded: 'Sukses', failed: 'Dështuar', pending: 'Në pritje', processing: 'Në proces', requires_action: 'Kërkon veprim', canceled: 'Anuluar' }[status] || status;
}

function statusClass(status) {
    return { succeeded: 'bg-emerald-50 text-emerald-700', failed: 'bg-red-50 text-red-700', pending: 'bg-amber-50 text-amber-700', processing: 'bg-blue-50 text-blue-700', requires_action: 'bg-purple-50 text-purple-700', canceled: 'bg-neutral-100 text-neutral-600' }[status] || 'bg-neutral-100 text-neutral-600';
}

function filter(key, value) {
    router.get('/super-admin/billing/payment-attempts', { ...props.filters, [key]: value || undefined }, { preserveState: true, replace: true });
}
</script>

<template>
    <SuperAdminLayout title="Tentativat e pagesës — Lora">
        <main class="sa-page max-w-[1320px] space-y-4">
            <BillingPageHeader title="Tentativat e pagesës" subtitle="Kërkesat te provider-i dhe rezultati i secilës tentativë." />

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
                    <div><h2 class="sa-card-title">Lista e tentativave</h2><p class="sa-card-subtitle">Hap ID-në për zinxhirin teknik të plotë.</p></div>
                    <div class="flex flex-wrap gap-2">
                        <label>Hoteli<select :value="filters.tenant_id || ''" class="sa-control mt-1 block min-w-[160px]" @change="filter('tenant_id', $event.target.value)"><option value="">Të gjithë</option><option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">{{ tenant.name }}</option></select></label>
                        <label>Statusi<select :value="filters.status || ''" class="sa-control mt-1 block min-w-[140px]" @change="filter('status', $event.target.value)"><option value="">Të gjitha</option><option value="pending">Në pritje</option><option value="processing">Në proces</option><option value="requires_action">Kërkon veprim</option><option value="succeeded">Sukses</option><option value="failed">Dështuar</option><option value="canceled">Anuluar</option></select></label>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left">
                        <thead><tr class="sa-table-head"><th class="px-4 py-2.5 font-bold">Tentativa</th><th class="px-4 py-2.5 font-bold">Hoteli</th><th class="px-4 py-2.5 font-bold">Fatura / Pagesa</th><th class="px-4 py-2.5 font-bold">Provider</th><th class="px-4 py-2.5 font-bold">Data</th><th class="px-4 py-2.5 font-bold">Statusi</th><th class="px-4 py-2.5 text-right font-bold">Shuma</th></tr></thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="attempt in attempts.data" :key="attempt.id" class="hover:bg-neutral-50">
                                <td class="px-4 py-3"><Link :href="`/super-admin/billing/payment-attempts/${attempt.id}`" class="sa-table-primary text-emerald-700 no-underline">#{{ attempt.id }} · {{ attempt.attempt_number }}</Link><p class="sa-table-meta font-mono">{{ attempt.provider_attempt_id || 'Pa ID të jashtme' }}</p></td>
                                <td class="px-4 py-3"><Link :href="`/super-admin/tenants/${attempt.tenant.id}`" class="sa-table-primary no-underline hover:text-emerald-700">{{ attempt.tenant.name }}</Link></td>
                                <td class="px-4 py-3 text-xs"><Link v-if="attempt.invoice" :href="`/super-admin/billing/invoices/${attempt.invoice.id}`" class="block font-semibold text-emerald-700 no-underline">{{ attempt.invoice.number }}</Link><Link v-if="attempt.payment" :href="`/super-admin/billing/payments/${attempt.payment.id}`" class="sa-table-meta block no-underline">{{ attempt.payment.number }}</Link><span v-if="!attempt.invoice && !attempt.payment">—</span></td>
                                <td class="px-4 py-3 text-xs text-neutral-600"><span class="capitalize">{{ attempt.provider }}</span><p class="sa-table-meta">{{ attempt.events_count }} evente</p></td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs text-neutral-500">{{ dateTime(attempt.attempted_at) }}</td>
                                <td class="px-4 py-3"><span class="rounded-full px-2 py-1 text-[10px] font-bold" :class="statusClass(attempt.status)">{{ statusLabel(attempt.status) }}</span></td>
                                <td class="px-4 py-3 text-right text-xs font-semibold">{{ money(attempt.amount_cents, attempt.currency) }}</td>
                            </tr>
                            <tr v-if="!attempts.data.length"><td colspan="7" class="px-4 py-10 text-center text-xs text-neutral-400">Nuk ka ende tentativa provider-i.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="attempts.links?.length > 3" class="flex justify-end gap-1 border-t border-neutral-200 px-4 py-3"><Link v-for="link in attempts.links" :key="link.label" :href="link.url || '#'" class="rounded-lg px-3 py-1.5 text-[11px] no-underline" :class="link.active ? 'bg-emerald-700 text-white' : 'text-neutral-500 hover:bg-neutral-100'" v-html="link.label" /></div>
            </section>
        </main>
    </SuperAdminLayout>
</template>
