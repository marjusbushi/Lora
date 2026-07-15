<script setup>
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { Banknote, CreditCard, Landmark, WalletCards, X } from 'lucide-vue-next';

const props = defineProps({ payments: Object, openInvoices: Array, stats: Object });
const modalOpen = ref(false);
const form = useForm({ billing_invoice_id: '', amount: '', method: 'bank_transfer', reference: '', paid_at: new Date().toISOString().slice(0, 16), note: '' });
const selectedInvoice = computed(() => props.openInvoices.find((invoice) => invoice.id === Number(form.billing_invoice_id)));

watch(selectedInvoice, (invoice) => {
    form.amount = invoice ? (invoice.balance_cents / 100).toFixed(2) : '';
});

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
</script>

<template>
    <SuperAdminLayout title="Pagesat — Lora Control Panel">
        <div class="mx-auto max-w-7xl space-y-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div><p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-700">Financa e Lora / Pagesat</p><h1 class="mt-2 text-3xl font-semibold tracking-tight text-neutral-950">Pagesat</h1><p class="mt-2 text-sm text-neutral-500">Ledger unik për pagesat manuale dhe online të platformës.</p></div>
                <button class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#16875d] px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-[#116f4c]" @click="modalOpen = true"><Banknote class="h-4 w-4" /> Regjistro pagesë</button>
            </div>

            <section class="grid gap-4 md:grid-cols-3">
                <article v-for="card in cards" :key="card.label" class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm"><div class="flex items-start justify-between gap-4"><div><p class="text-sm font-medium text-neutral-500">{{ card.label }}</p><p class="mt-3 text-3xl font-semibold tracking-tight text-neutral-950">{{ card.value }}</p><p class="mt-2 text-xs text-neutral-400">{{ card.detail }}</p></div><span class="grid h-11 w-11 place-items-center rounded-2xl bg-emerald-50 text-emerald-700"><component :is="card.icon" class="h-5 w-5" /></span></div></article>
            </section>

            <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm">
                <div class="border-b border-neutral-200 px-5 py-4"><h2 class="font-semibold text-neutral-900">Transaksionet</h2><p class="mt-1 text-xs text-neutral-500">Çdo pagesë lidhet me faturën dhe hotelin përkatës.</p></div>
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-neutral-200 text-sm"><thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500"><tr><th class="px-5 py-3 font-semibold">Pagesa</th><th class="px-5 py-3 font-semibold">Hoteli</th><th class="px-5 py-3 font-semibold">Fatura</th><th class="px-5 py-3 font-semibold">Metoda</th><th class="px-5 py-3 font-semibold">Data</th><th class="px-5 py-3 font-semibold">Statusi</th><th class="px-5 py-3 text-right font-semibold">Shuma</th></tr></thead><tbody class="divide-y divide-neutral-100"><tr v-for="payment in payments.data" :key="payment.id"><td class="px-5 py-4 font-semibold text-neutral-900">{{ payment.number }}</td><td class="px-5 py-4 font-medium text-neutral-900">{{ payment.tenant.name }}</td><td class="px-5 py-4 text-emerald-700">{{ payment.invoice?.number || '—' }}</td><td class="px-5 py-4 text-neutral-500"><span class="block">{{ payment.provider === 'manual' ? methodLabel(payment.method) : payment.provider }}</span><span class="text-xs text-neutral-400">{{ payment.reference || 'Pa referencë' }}</span></td><td class="whitespace-nowrap px-5 py-4 text-neutral-500">{{ dateTime(payment.paid_at) }}</td><td class="px-5 py-4"><span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700">{{ payment.status }}</span></td><td class="whitespace-nowrap px-5 py-4 text-right font-semibold text-neutral-900">{{ money(payment.amount_cents, payment.currency) }}</td></tr><tr v-if="!payments.data.length"><td colspan="7" class="px-5 py-12 text-center text-neutral-500">Nuk ka ende pagesa.</td></tr></tbody></table></div>
                <div v-if="payments.links?.length > 3" class="flex flex-wrap justify-end gap-1 border-t border-neutral-200 px-5 py-4"><Link v-for="link in payments.links" :key="link.label" :href="link.url || '#'" class="rounded-lg px-3 py-1.5 text-xs no-underline" :class="link.active ? 'bg-[#16875d] text-white' : 'text-neutral-500 hover:bg-neutral-100'" v-html="link.label" /></div>
            </section>
        </div>

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
