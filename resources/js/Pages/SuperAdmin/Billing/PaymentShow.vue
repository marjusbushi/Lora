<script setup>
import { Link } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { ArrowLeft, Building2, CreditCard, FileText, RefreshCw, Webhook } from 'lucide-vue-next';

const props = defineProps({ payment: Object });

function money(cents) {
    return new Intl.NumberFormat('sq-AL', {
        style: 'currency',
        currency: props.payment.currency,
        minimumFractionDigits: 2,
    }).format((cents || 0) / 100);
}

function dateTime(value) {
    return value
        ? new Intl.DateTimeFormat('sq-AL', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
        : '—';
}

function methodLabel(method) {
    return {
        bank_transfer: 'Transfer bankar',
        cash: 'Cash',
        card: 'Kartë',
        other: 'Tjetër',
    }[method] || method || '—';
}

function providerLabel(provider) {
    return provider === 'manual' ? 'Regjistrim manual' : provider;
}

function statusClass(status) {
    return {
        completed: 'bg-emerald-50 text-emerald-700',
        succeeded: 'bg-emerald-50 text-emerald-700',
        failed: 'bg-red-50 text-red-700',
        pending: 'bg-amber-50 text-amber-700',
        refunded: 'bg-blue-50 text-blue-700',
    }[status] || 'bg-neutral-100 text-neutral-600';
}

function statusLabel(status) {
    return {
        completed: 'E përfunduar',
        succeeded: 'Sukses',
        failed: 'Dështuar',
        pending: 'Në proces',
        refunded: 'Rimbursuar',
    }[status] || status;
}
</script>

<template>
    <SuperAdminLayout :title="`${payment.number} — Pagesa`">
        <main class="sa-page max-w-[1040px] space-y-4">
            <nav class="sa-breadcrumb">
                <Link href="/super-admin/billing/payments" class="no-underline hover:text-neutral-700">Pagesat</Link>
                <span class="mx-2">/</span>
                <span class="font-medium text-neutral-600">{{ payment.number }}</span>
            </nav>

            <header class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="sa-page-title !mt-0">Pagesa {{ payment.number }}</h1>
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-1 text-[10px] font-bold" :class="statusClass(payment.status)">
                            <span class="h-1.5 w-1.5 rounded-full bg-current" />{{ statusLabel(payment.status) }}
                        </span>
                    </div>
                    <p class="sa-page-subtitle">Mandat pagese i lidhur me faturën dhe abonimin.</p>
                </div>
                <div class="sa-actions"><Link href="/super-admin/billing/payments" class="sa-button"><ArrowLeft /> Kthehu</Link></div>
            </header>

            <article class="sa-card">
                <header class="grid gap-5 border-b border-neutral-200 p-5 sm:grid-cols-[1fr_auto] sm:items-start">
                    <div class="flex items-center gap-3">
                        <span class="sa-icon-box-lg bg-blue-600 text-white"><CreditCard class="sa-icon-lg" /></span>
                        <div><strong class="block text-sm text-neutral-950">Mandat pagese</strong><span class="mt-0.5 block text-[10px] text-neutral-500">Lora PMS · Platform billing</span></div>
                    </div>
                    <div class="sm:text-right">
                        <p class="text-[10px] font-bold uppercase tracking-[.12em] text-neutral-400">Pagesa</p>
                        <h2 class="mt-1 text-xl font-semibold tracking-tight text-neutral-950">{{ payment.number }}</h2>
                        <p class="mt-1 text-[11px] text-neutral-500">{{ dateTime(payment.paid_at) }}</p>
                    </div>
                </header>

                <section class="grid gap-5 border-b border-neutral-200 p-5 sm:grid-cols-2">
                    <div>
                        <p class="text-[9px] font-bold uppercase tracking-[.12em] text-neutral-400">Paguesi</p>
                        <Link :href="`/super-admin/tenants/${payment.tenant.id}`" class="mt-2 block text-xs font-semibold text-neutral-900 no-underline hover:text-emerald-700">{{ payment.tenant.name }}</Link>
                        <p class="mt-1 text-[11px] text-neutral-500">Tenant #{{ payment.tenant.id }}</p>
                    </div>
                    <div>
                        <p class="text-[9px] font-bold uppercase tracking-[.12em] text-neutral-400">Marrësi</p>
                        <strong class="mt-2 block text-xs text-neutral-900">Lora PMS</strong>
                        <p class="mt-1 text-[11px] text-neutral-500">Pagesë për shërbimet e platformës</p>
                    </div>
                </section>

                <section class="grid lg:grid-cols-[1fr_320px]">
                    <dl class="grid gap-x-8 border-b border-neutral-200 p-5 text-xs sm:grid-cols-2 lg:border-b-0 lg:border-r">
                        <div class="border-b border-neutral-100 py-3"><dt class="text-[10px] text-neutral-400">Metoda</dt><dd class="mt-1 font-semibold text-neutral-900">{{ methodLabel(payment.method) }}</dd></div>
                        <div class="border-b border-neutral-100 py-3"><dt class="text-[10px] text-neutral-400">Provider</dt><dd class="mt-1 font-semibold capitalize text-neutral-900">{{ providerLabel(payment.provider) }}</dd></div>
                        <div class="border-b border-neutral-100 py-3"><dt class="text-[10px] text-neutral-400">Referenca</dt><dd class="mt-1 break-all font-mono text-[11px] font-semibold text-neutral-900">{{ payment.reference || payment.provider_payment_id || '—' }}</dd></div>
                        <div class="border-b border-neutral-100 py-3"><dt class="text-[10px] text-neutral-400">Regjistruar nga</dt><dd class="mt-1 font-semibold text-neutral-900">{{ payment.recorder?.name || 'Sistemi' }}</dd><span class="text-[10px] text-neutral-400">{{ payment.recorder?.email }}</span></div>
                        <div class="py-3 sm:col-span-2"><dt class="text-[10px] text-neutral-400">ID e provider-it</dt><dd class="mt-1 break-all font-mono text-[11px] text-neutral-700">{{ payment.provider_payment_id || 'Nuk ka ID të jashtme' }}</dd></div>
                    </dl>
                    <div class="flex flex-col justify-center bg-neutral-50/60 p-5 text-right">
                        <span class="text-[10px] font-bold uppercase tracking-[.12em] text-neutral-400">Shuma e paguar</span>
                        <strong class="mt-2 text-3xl font-semibold tracking-tight text-neutral-950">{{ money(payment.amount_cents) }}</strong>
                        <span class="mt-2 text-[11px] text-neutral-500">{{ payment.currency }} · {{ statusLabel(payment.status) }}</span>
                    </div>
                </section>

                <footer v-if="payment.invoice" class="grid border-t border-neutral-200 sm:grid-cols-2">
                    <Link :href="`/super-admin/billing/invoices/${payment.invoice.id}`" class="flex items-center justify-between gap-3 border-b border-neutral-200 p-4 no-underline hover:bg-emerald-50/50 sm:border-b-0 sm:border-r">
                        <div class="flex items-center gap-2.5"><span class="sa-icon-box bg-emerald-50 text-emerald-700"><FileText class="sa-icon" /></span><div><span class="block text-[10px] text-neutral-400">Fatura e lidhur</span><strong class="text-xs text-neutral-900">{{ payment.invoice.number }}</strong></div></div>
                        <span class="text-[11px] font-semibold text-emerald-700">Hap →</span>
                    </Link>
                    <Link :href="`/super-admin/billing/bills/${payment.invoice.id}`" class="flex items-center justify-between gap-3 p-4 no-underline hover:bg-emerald-50/50">
                        <div class="flex items-center gap-2.5"><span class="sa-icon-box bg-amber-50 text-amber-700"><Building2 class="sa-icon" /></span><div><span class="block text-[10px] text-neutral-400">Bill i ciklit</span><strong class="text-xs text-neutral-900">Origjina e faturimit</strong></div></div>
                        <span class="text-[11px] font-semibold text-emerald-700">Hap →</span>
                    </Link>
                </footer>
            </article>

            <section class="sa-card">
                <div class="sa-card-header">
                    <div><h2 class="sa-card-title">Gjurmë teknike</h2><p class="sa-card-subtitle">Tentativat dhe eventet që konfirmojnë pagesën.</p></div>
                </div>
                <div class="grid md:grid-cols-2">
                    <div class="border-b border-neutral-200 md:border-b-0 md:border-r">
                        <div class="flex items-center gap-2 border-b border-neutral-100 px-4 py-3"><RefreshCw class="sa-icon text-neutral-400" /><strong class="text-xs">Tentativat · {{ payment.attempts.length }}</strong></div>
                        <div v-if="payment.attempts.length" class="divide-y divide-neutral-100 px-4">
                            <Link v-for="attempt in payment.attempts" :key="attempt.id" :href="`/super-admin/billing/payment-attempts/${attempt.id}`" class="flex items-center justify-between gap-3 py-3 text-[11px] no-underline"><span>Tentativa #{{ attempt.attempt_number }} · {{ attempt.provider }}</span><strong :class="statusClass(attempt.status)">{{ statusLabel(attempt.status) }}</strong></Link>
                        </div>
                        <p v-else class="p-4 text-[11px] text-neutral-400">Pagesë manuale ose pa tentativa provider-i.</p>
                    </div>
                    <div>
                        <div class="flex items-center gap-2 border-b border-neutral-100 px-4 py-3"><Webhook class="sa-icon text-neutral-400" /><strong class="text-xs">Provider events · {{ payment.events.length }}</strong></div>
                        <div v-if="payment.events.length" class="divide-y divide-neutral-100 px-4">
                            <Link v-for="event in payment.events" :key="event.id" :href="`/super-admin/billing/provider-events/${event.id}`" class="flex items-center justify-between gap-3 py-3 text-[11px] no-underline"><span>{{ event.event_type }}</span><span class="text-neutral-400">{{ dateTime(event.occurred_at) }}</span></Link>
                        </div>
                        <p v-else class="p-4 text-[11px] text-neutral-400">Nuk ka provider events.</p>
                    </div>
                </div>
            </section>
        </main>
    </SuperAdminLayout>
</template>
