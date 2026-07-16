<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import BillingPageHeader from '@/Components/SuperAdmin/BillingPageHeader.vue';
import { Banknote, CreditCard, Landmark, WalletCards, X } from 'lucide-vue-next';

const props = defineProps({ payments: Object, openInvoices: Array, stats: Object, tenants: Array, filters: Object });
const modalOpen = ref(false);
const form = useForm({ billing_invoice_id: props.filters?.invoice_id || '', amount: '', method: 'bank_transfer', reference: '', paid_at: new Date().toISOString().slice(0, 16), note: '' });
const selectedInvoice = computed(() => props.openInvoices.find((invoice) => invoice.id === Number(form.billing_invoice_id)));

watch(selectedInvoice, (invoice) => {
    form.amount = invoice ? (invoice.balance_cents / 100).toFixed(2) : '';
}, { immediate: true });

const cards = computed(() => [
    { label: 'Arkëtuar këtë muaj', value: money(props.stats.month_cents), detail: 'Të gjitha metodat', icon: WalletCards },
    { label: 'Pagesa manuale', value: money(props.stats.manual_cents), detail: 'Bankë, cash dhe të tjera', icon: Landmark },
    { label: 'Pagesa online', value: money(props.stats.online_cents), detail: 'Provider të integruar', icon: CreditCard },
]);

function money(cents, currency = 'EUR') {
    return new Intl.NumberFormat('sq-AL', { style: 'currency', currency, minimumFractionDigits: 2 }).format((cents || 0) / 100);
}

function dateTime(value) {
    return value ? new Intl.DateTimeFormat('sq-AL', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : '—';
}

function methodLabel(method) {
    return { bank_transfer: 'Transfer bankar', cash: 'Cash', other: 'Tjetër' }[method] || method;
}

function submit() {
    form.post('/super-admin/billing/payments', {
        preserveScroll: true,
        onSuccess: () => { modalOpen.value = false; form.reset('billing_invoice_id', 'amount', 'reference', 'note'); },
    });
}
function filter(key, value) { router.get('/super-admin/billing/payments', { ...props.filters, [key]: value || undefined }, { preserveState: true, replace: true }); }
</script>

<template>
    <SuperAdminLayout title="Pagesat — Lora Control Panel">
        <main class="sa-page max-w-[1320px] space-y-4">
            <BillingPageHeader title="Pagesat">
                <template #actions><button type="button" class="sa-button sa-button-primary" @click="modalOpen = true"><Banknote /> Regjistro pagesë</button></template>
            </BillingPageHeader>

            <section class="grid gap-3 md:grid-cols-3">
                <article v-for="card in cards" :key="card.label" class="sa-card sa-kpi-card"><div class="flex items-start justify-between gap-4"><div><p class="sa-kpi-label">{{ card.label }}</p><p class="sa-kpi-value">{{ card.value }}</p><p class="sa-kpi-meta">{{ card.detail }}</p></div><span class="sa-icon-box bg-emerald-50 text-emerald-700"><component :is="card.icon" class="sa-icon" /></span></div></article>
            </section>

            <section class="sa-card">
                <div class="sa-card-header flex-col items-stretch md:flex-row md:items-end"><div><h2 class="sa-card-title">Transaksionet</h2></div><div class="flex flex-wrap gap-2"><label>Hoteli<select :value="filters.tenant_id || ''" class="sa-control mt-1 block min-w-[160px]" @change="filter('tenant_id', $event.target.value)"><option value="">Të gjithë</option><option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">{{ tenant.name }}</option></select></label><label>Statusi<select :value="filters.status || ''" class="sa-control mt-1 block min-w-[130px]" @change="filter('status', $event.target.value)"><option value="">Të gjitha</option><option value="completed">Përfunduar</option><option value="pending">Në proces</option><option value="failed">Dështuar</option><option value="refunded">Rimbursuar</option></select></label></div></div>
                <div class="overflow-x-auto"><table class="min-w-full text-left"><thead><tr class="sa-table-head"><th class="px-4 py-2.5 font-bold">Pagesa</th><th class="px-4 py-2.5 font-bold">Hoteli</th><th class="px-4 py-2.5 font-bold">Fatura</th><th class="px-4 py-2.5 font-bold">Metoda</th><th class="px-4 py-2.5 font-bold">Data</th><th class="px-4 py-2.5 font-bold">Statusi</th><th class="px-4 py-2.5 text-right font-bold">Shuma</th></tr></thead><tbody class="divide-y divide-neutral-100"><tr v-for="payment in payments.data" :key="payment.id" class="hover:bg-neutral-50"><td class="px-4 py-3"><Link :href="`/super-admin/billing/payments/${payment.id}`" class="sa-table-primary text-emerald-700 no-underline">{{ payment.number }}</Link></td><td class="px-4 py-3"><Link :href="`/super-admin/tenants/${payment.tenant.id}`" class="sa-table-primary no-underline hover:text-emerald-700">{{ payment.tenant.name }}</Link></td><td class="px-4 py-3"><Link v-if="payment.invoice" :href="`/super-admin/billing/invoices/${payment.invoice.id}`" class="text-xs font-semibold text-emerald-700 no-underline">{{ payment.invoice.number }}</Link><span v-else>—</span></td><td class="px-4 py-3 text-xs text-neutral-500"><span class="block">{{ payment.provider === 'manual' ? methodLabel(payment.method) : payment.provider }}</span><span class="sa-table-meta">{{ payment.reference || 'Pa referencë' }}</span></td><td class="whitespace-nowrap px-4 py-3 text-xs text-neutral-500">{{ dateTime(payment.paid_at) }}</td><td class="px-4 py-3"><span class="rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-bold text-emerald-700">{{ payment.status }}</span></td><td class="whitespace-nowrap px-4 py-3 text-right text-xs font-semibold text-neutral-900">{{ money(payment.amount_cents, payment.currency) }}</td></tr><tr v-if="!payments.data.length"><td colspan="7" class="px-4 py-10 text-center text-xs text-neutral-500">Nuk ka ende pagesa.</td></tr></tbody></table></div>
                <div v-if="payments.links?.length > 3" class="flex flex-wrap justify-end gap-1 border-t border-neutral-200 px-5 py-4"><Link v-for="link in payments.links" :key="link.label" :href="link.url || '#'" class="rounded-lg px-3 py-1.5 text-xs no-underline" :class="link.active ? 'bg-[#16875d] text-white' : 'text-neutral-500 hover:bg-neutral-100'" v-html="link.label" /></div>
            </section>
        </main>

        <Teleport to="body">
            <div v-if="modalOpen" class="fixed inset-0 z-50 flex items-end justify-center bg-neutral-950/50 p-0 sm:items-center sm:p-6" @click.self="modalOpen = false">
                <form class="max-h-[94vh] w-full max-w-2xl overflow-y-auto rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl" @submit.prevent="submit">
                    <div class="flex items-start justify-between border-b border-neutral-200 px-5 py-4"><div><h2 class="text-lg font-semibold text-neutral-900">Regjistro pagesë manuale</h2><p class="mt-1 text-sm text-neutral-500">Për transfer bankar, cash ose pagesë të verifikuar jashtë providerit.</p></div><button type="button" class="rounded-lg p-2 text-neutral-400 hover:bg-neutral-100" @click="modalOpen = false"><X class="h-5 w-5" /></button></div>
                    <div class="grid gap-4 p-5 sm:grid-cols-2">
                        <label class="text-sm font-medium text-neutral-700 sm:col-span-2">Fatura<select v-model="form.billing_invoice_id" required class="mt-1 w-full rounded-xl border-neutral-300 text-sm"><option value="" disabled>Zgjidh faturën</option><option v-for="invoice in openInvoices" :key="invoice.id" :value="invoice.id">{{ invoice.number }} · {{ invoice.tenant_name }} · {{ money(invoice.balance_cents, invoice.currency) }}</option></select><span v-if="form.errors.billing_invoice_id" class="mt-1 block text-xs text-red-600">{{ form.errors.billing_invoice_id }}</span></label>
                        <label class="text-sm font-medium text-neutral-700">Shuma<input v-model="form.amount" required min="0.01" step="0.01" type="number" class="mt-1 w-full rounded-xl border-neutral-300 text-sm" /><span v-if="form.errors.amount" class="mt-1 block text-xs text-red-600">{{ form.errors.amount }}</span></label>
                        <label class="text-sm font-medium text-neutral-700">Metoda<select v-model="form.method" class="mt-1 w-full rounded-xl border-neutral-300 text-sm"><option value="bank_transfer">Transfer bankar</option><option value="cash">Cash</option><option value="other">Tjetër</option></select></label>
                        <label class="text-sm font-medium text-neutral-700">Referenca<input v-model="form.reference" type="text" class="mt-1 w-full rounded-xl border-neutral-300 text-sm" placeholder="BKT-882104" /></label>
                        <label class="text-sm font-medium text-neutral-700">Data e pagesës<input v-model="form.paid_at" required type="datetime-local" class="mt-1 w-full rounded-xl border-neutral-300 text-sm" /></label>
                        <label class="text-sm font-medium text-neutral-700 sm:col-span-2">Shënim<textarea v-model="form.note" rows="3" class="mt-1 w-full rounded-xl border-neutral-300 text-sm" /></label>
                        <p v-if="Object.keys(form.errors).length" class="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700 sm:col-span-2">Kontrollo fushat dhe provo përsëri.</p>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-neutral-200 px-5 py-4"><button type="button" class="rounded-xl border border-neutral-300 px-4 py-2 text-sm font-semibold" @click="modalOpen = false">Anulo</button><button class="rounded-xl bg-[#16875d] px-4 py-2 text-sm font-semibold text-white" :disabled="form.processing">{{ form.processing ? 'Duke regjistruar…' : 'Konfirmo pagesën' }}</button></div>
                </form>
            </div>
        </Teleport>
    </SuperAdminLayout>
</template>
