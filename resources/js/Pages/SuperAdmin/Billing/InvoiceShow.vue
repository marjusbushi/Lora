<script setup>
import { Link, router } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { ArrowLeft, Building2, CreditCard, FileText, ReceiptText } from 'lucide-vue-next';

const props = defineProps({ invoice: Object });

function money(cents) {
    return new Intl.NumberFormat('sq-AL', {
        style: 'currency',
        currency: props.invoice.currency,
        minimumFractionDigits: 2,
    }).format((cents || 0) / 100);
}

function date(value) {
    return value
        ? new Intl.DateTimeFormat('sq-AL', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(`${value}T12:00:00`))
        : '—';
}

function dateTime(value) {
    return value
        ? new Intl.DateTimeFormat('sq-AL', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
        : '—';
}

function quantity(value) {
    return new Intl.NumberFormat('sq-AL', { maximumFractionDigits: 2 }).format(Number(value || 0));
}

function unitLabel() {
    return props.invoice.subscription?.billing_cycle === 'annual' ? 'vit' : 'muaj';
}

function statusClass(status) {
    return {
        draft: 'bg-neutral-100 text-neutral-600',
        open: 'bg-amber-50 text-amber-700',
        paid: 'bg-emerald-50 text-emerald-700',
        overdue: 'bg-red-50 text-red-700',
        void: 'bg-neutral-100 text-neutral-500',
        completed: 'bg-emerald-50 text-emerald-700',
        failed: 'bg-red-50 text-red-700',
        refunded: 'bg-blue-50 text-blue-700',
    }[status] || 'bg-neutral-100 text-neutral-600';
}

function statusLabel(status) {
    return {
        draft: 'Draft',
        open: 'Për pagesë',
        paid: 'Paguar',
        overdue: 'Me vonesë',
        void: 'Anuluar',
        completed: 'E përfunduar',
        failed: 'Dështuar',
        refunded: 'Rimbursuar',
    }[status] || status;
}

function publishInvoice() {
    router.patch(`/super-admin/billing/invoices/${props.invoice.id}/publish`);
}

function voidInvoice() {
    if (window.confirm(`Të anulohet fatura ${props.invoice.number}?`)) {
        router.patch(`/super-admin/billing/invoices/${props.invoice.id}/void`);
    }
}
</script>

<template>
    <SuperAdminLayout :title="`${invoice.number} — Fatura`">
        <main class="sa-page max-w-[1180px] space-y-4">
            <nav class="sa-breadcrumb">
                <Link href="/super-admin/billing/invoices" class="no-underline hover:text-neutral-700">Faturat</Link>
                <span class="mx-2">/</span>
                <span class="font-medium text-neutral-600">{{ invoice.number }}</span>
            </nav>

            <header class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="sa-page-title !mt-0">Fatura {{ invoice.number }}</h1>
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-1 text-[10px] font-bold" :class="statusClass(invoice.status)">
                            <span class="h-1.5 w-1.5 rounded-full bg-current" />{{ statusLabel(invoice.status) }}
                        </span>
                    </div>
                    <p class="sa-page-subtitle">Dokument financiar i platformës për {{ invoice.tenant.name }}.</p>
                </div>
                <div class="sa-actions flex flex-wrap gap-2">
                    <Link href="/super-admin/billing/invoices" class="sa-button"><ArrowLeft /> Kthehu</Link>
                    <button v-if="invoice.status === 'draft'" type="button" class="sa-button !text-red-600" @click="voidInvoice">Anulo</button>
                    <button v-if="invoice.status === 'draft'" type="button" class="sa-button sa-button-primary" @click="publishInvoice">Publiko</button>
                    <Link v-if="['open', 'overdue'].includes(invoice.status)" :href="`/super-admin/billing/payments?invoice_id=${invoice.id}`" class="sa-button sa-button-primary">Regjistro pagesë</Link>
                </div>
            </header>

            <article class="sa-card">
                <header class="grid gap-5 border-b border-neutral-200 p-5 sm:grid-cols-[1fr_auto] sm:items-start">
                    <div class="flex items-center gap-3">
                        <span class="sa-icon-box-lg bg-emerald-700 text-xs font-bold text-white">L</span>
                        <div>
                            <strong class="block text-sm text-neutral-950">Lora PMS</strong>
                            <span class="mt-0.5 block text-[10px] text-neutral-500">Platform billing</span>
                        </div>
                    </div>
                    <div class="sm:text-right">
                        <p class="text-[10px] font-bold uppercase tracking-[.12em] text-neutral-400">Faturë</p>
                        <h2 class="mt-1 text-xl font-semibold tracking-tight text-neutral-950">{{ invoice.number }}</h2>
                        <p class="mt-1 text-[11px] text-neutral-500">Lëshuar {{ dateTime(invoice.issued_at) }} · Afati {{ date(invoice.due_on) }}</p>
                    </div>
                </header>

                <section class="grid gap-5 border-b border-neutral-200 p-5 sm:grid-cols-2">
                    <div>
                        <p class="text-[9px] font-bold uppercase tracking-[.12em] text-neutral-400">Nga</p>
                        <strong class="mt-2 block text-xs text-neutral-900">Lora PMS</strong>
                        <p class="mt-1 text-[11px] text-neutral-500">Shërbime software dhe menaxhim platforme</p>
                    </div>
                    <div>
                        <p class="text-[9px] font-bold uppercase tracking-[.12em] text-neutral-400">Faturuar për</p>
                        <Link :href="`/super-admin/tenants/${invoice.tenant.id}`" class="mt-2 block text-xs font-semibold text-neutral-900 no-underline hover:text-emerald-700">{{ invoice.tenant.name }}</Link>
                        <p class="mt-1 text-[11px] text-neutral-500">Tenant #{{ invoice.tenant.id }} · Abonimi #{{ invoice.subscription_id }}</p>
                    </div>
                </section>

                <section class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-left">
                        <thead>
                            <tr class="sa-table-head">
                                <th class="px-5 py-2.5 font-bold">Artikulli</th>
                                <th class="px-4 py-2.5 text-right font-bold">Çmimi</th>
                                <th class="px-4 py-2.5 font-bold">Njësia</th>
                                <th class="px-4 py-2.5 text-right font-bold">Sasia</th>
                                <th class="px-5 py-2.5 text-right font-bold">Vlera</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="line in invoice.lines" :key="line.id">
                                <td class="px-5 py-3.5"><strong class="sa-table-primary">{{ line.description }}</strong><p class="sa-table-meta">{{ date(invoice.period_starts_on) }} – {{ date(invoice.period_ends_on) }}</p></td>
                                <td class="px-4 py-3.5 text-right text-xs text-neutral-600">{{ money(line.unit_amount_cents) }}</td>
                                <td class="px-4 py-3.5 text-xs text-neutral-600">{{ unitLabel() }}</td>
                                <td class="px-4 py-3.5 text-right text-xs text-neutral-600">{{ quantity(line.quantity) }}</td>
                                <td class="px-5 py-3.5 text-right text-xs font-semibold text-neutral-900">{{ money(line.amount_cents) }}</td>
                            </tr>
                            <tr v-if="!invoice.lines.length"><td colspan="5" class="px-5 py-10 text-center text-xs text-neutral-400">Nuk ka artikuj në këtë faturë.</td></tr>
                        </tbody>
                    </table>
                </section>

                <section class="grid border-t border-neutral-200 lg:grid-cols-[1fr_360px]">
                    <div class="border-b border-neutral-200 p-5 lg:border-b-0 lg:border-r">
                        <p class="text-[9px] font-bold uppercase tracking-[.12em] text-neutral-400">Shënime</p>
                        <p class="mt-2 max-w-2xl whitespace-pre-wrap text-[11px] leading-5 text-neutral-500">{{ invoice.notes || 'Nuk ka shënime për këtë faturë.' }}</p>
                    </div>
                    <dl class="divide-y divide-neutral-100 px-5 py-2 text-xs">
                        <div class="flex justify-between gap-4 py-2.5"><dt class="text-neutral-500">Nëntotali</dt><dd class="font-semibold">{{ money(invoice.subtotal_cents) }}</dd></div>
                        <div v-if="invoice.discount_cents" class="flex justify-between gap-4 py-2.5"><dt class="text-neutral-500">Zbritje</dt><dd class="font-semibold text-emerald-700">−{{ money(invoice.discount_cents) }}</dd></div>
                        <div class="flex justify-between gap-4 py-2.5"><dt class="text-neutral-500">TVSH / Taksa</dt><dd class="font-semibold">{{ money(invoice.tax_cents) }}</dd></div>
                        <div class="flex justify-between gap-4 py-3 text-sm"><dt class="font-semibold text-neutral-900">Totali</dt><dd class="font-bold text-neutral-950">{{ money(invoice.total_cents) }}</dd></div>
                        <div class="flex justify-between gap-4 py-2.5"><dt class="text-neutral-500">Paguar</dt><dd class="font-semibold text-emerald-700">{{ money(invoice.amount_paid_cents) }}</dd></div>
                        <div class="flex justify-between gap-4 py-3 text-sm"><dt class="font-semibold text-neutral-900">Për t’u paguar</dt><dd class="font-bold" :class="invoice.balance_cents ? 'text-red-700' : 'text-emerald-700'">{{ money(invoice.balance_cents) }}</dd></div>
                    </dl>
                </section>

                <footer class="grid border-t border-neutral-200 md:grid-cols-2">
                    <div class="border-b border-neutral-200 p-4 md:border-b-0 md:border-r">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-2.5"><span class="sa-icon-box bg-emerald-50 text-emerald-700"><FileText /></span><div><strong class="block text-xs text-neutral-900">Bill i ciklit</strong><span class="text-[10px] text-neutral-500">Origjina e kësaj fature</span></div></div>
                            <Link :href="`/super-admin/billing/bills/${invoice.id}`" class="sa-button">Hap Bill</Link>
                        </div>
                    </div>
                    <div class="p-4">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-2.5"><span class="sa-icon-box bg-blue-50 text-blue-700"><CreditCard /></span><div><strong class="block text-xs text-neutral-900">Pagesat</strong><span class="text-[10px] text-neutral-500">{{ invoice.payments.length }} të lidhura</span></div></div>
                            <Link v-if="!invoice.payments.length" :href="`/super-admin/billing/payments?invoice_id=${invoice.id}`" class="sa-button">Regjistro</Link>
                        </div>
                        <div v-if="invoice.payments.length" class="mt-3 divide-y divide-neutral-100 border-t border-neutral-100">
                            <Link v-for="payment in invoice.payments" :key="payment.id" :href="`/super-admin/billing/payments/${payment.id}`" class="flex items-center justify-between gap-3 py-2.5 text-[11px] no-underline hover:text-emerald-700">
                                <span><strong>{{ payment.number }}</strong><span class="ml-2 text-neutral-400">{{ dateTime(payment.paid_at) }}</span></span>
                                <span class="font-semibold">{{ money(payment.amount_cents) }}</span>
                            </Link>
                        </div>
                    </div>
                </footer>
            </article>

            <section class="sa-card">
                <div class="sa-card-header">
                    <div><h2 class="sa-card-title">Gjurmë teknike</h2><p class="sa-card-subtitle">Tentativat dhe eventet mbahen të ndara nga dokumenti financiar.</p></div>
                </div>
                <div class="grid divide-y divide-neutral-100 sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                    <div class="flex items-center gap-3 p-4"><span class="sa-icon-box bg-neutral-50 text-neutral-500"><ReceiptText /></span><div><span class="block text-[10px] text-neutral-400">Burimi</span><strong class="text-xs">{{ invoice.source === 'subscription_schedule' ? 'Recurring billing' : 'Manual' }}</strong></div></div>
                    <div class="flex items-center gap-3 p-4"><span class="sa-icon-box bg-neutral-50 text-neutral-500"><Building2 /></span><div><span class="block text-[10px] text-neutral-400">Tentativa</span><strong class="text-xs">{{ invoice.attempts.length }}</strong></div></div>
                    <div class="flex items-center gap-3 p-4"><span class="sa-icon-box bg-neutral-50 text-neutral-500"><FileText /></span><div><span class="block text-[10px] text-neutral-400">Provider events</span><strong class="text-xs">{{ invoice.events.length }}</strong></div></div>
                </div>
            </section>
        </main>
    </SuperAdminLayout>
</template>
