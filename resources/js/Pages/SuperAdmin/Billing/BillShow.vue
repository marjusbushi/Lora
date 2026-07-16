<script setup>
import { Link } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { ArrowLeft, CalendarClock, CreditCard, FileText, Repeat2 } from 'lucide-vue-next';

const props = defineProps({ bill: Object });

function money(cents) {
    return new Intl.NumberFormat('sq-AL', {
        style: 'currency',
        currency: props.bill.invoice.currency,
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

function cycleLabel(cycle) {
    return cycle === 'annual' ? 'Vjetor' : 'Mujor';
}

function unitLabel() {
    return props.bill.subscription?.billing_cycle === 'annual' ? 'vit' : 'muaj';
}

function invoiceStatus(status) {
    return {
        draft: 'Draft',
        open: 'Për pagesë',
        paid: 'Paguar',
        overdue: 'Me vonesë',
        void: 'Anuluar',
    }[status] || status;
}
</script>

<template>
    <SuperAdminLayout :title="`${bill.number} — Bill`">
        <main class="sa-page max-w-[1120px] space-y-4">
            <nav class="sa-breadcrumb">
                <Link href="/super-admin/billing/invoices" class="no-underline hover:text-neutral-700">Faturimi</Link>
                <span class="mx-2">/</span>
                <Link :href="`/super-admin/billing/invoices/${bill.invoice.id}`" class="no-underline hover:text-neutral-700">{{ bill.invoice.number }}</Link>
                <span class="mx-2">/</span>
                <span class="font-medium text-neutral-600">{{ bill.number }}</span>
            </nav>

            <header class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="sa-page-title !mt-0">Bill {{ bill.number }}</h1>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-bold text-emerald-700"><span class="h-1.5 w-1.5 rounded-full bg-current" />I faturuar</span>
                    </div>
                    <p class="sa-page-subtitle">Dokumenti i ciklit që gjeneroi faturën {{ bill.invoice.number }}.</p>
                </div>
                <div class="sa-actions"><Link :href="`/super-admin/billing/invoices/${bill.invoice.id}`" class="sa-button"><ArrowLeft /> Kthehu te fatura</Link></div>
            </header>

            <article class="sa-card">
                <header class="grid gap-5 border-b border-neutral-200 p-5 sm:grid-cols-[1fr_auto] sm:items-start">
                    <div class="flex items-center gap-3">
                        <span class="sa-icon-box-lg bg-amber-600 text-white"><Repeat2 class="sa-icon-lg" /></span>
                        <div><strong class="block text-sm text-neutral-950">Cikli i faturimit</strong><span class="mt-0.5 block text-[10px] text-neutral-500">Recurring billing · {{ cycleLabel(bill.subscription?.billing_cycle) }}</span></div>
                    </div>
                    <div class="sm:text-right">
                        <p class="text-[10px] font-bold uppercase tracking-[.12em] text-neutral-400">Bill</p>
                        <h2 class="mt-1 text-xl font-semibold tracking-tight text-neutral-950">{{ bill.number }}</h2>
                        <p class="mt-1 text-[11px] text-neutral-500">{{ date(bill.invoice.period_starts_on) }} – {{ date(bill.invoice.period_ends_on) }}</p>
                    </div>
                </header>

                <section class="grid gap-5 border-b border-neutral-200 p-5 sm:grid-cols-3">
                    <div>
                        <p class="text-[9px] font-bold uppercase tracking-[.12em] text-neutral-400">Hoteli</p>
                        <Link :href="`/super-admin/tenants/${bill.invoice.tenant.id}`" class="mt-2 block text-xs font-semibold text-neutral-900 no-underline hover:text-emerald-700">{{ bill.invoice.tenant.name }}</Link>
                        <p class="mt-1 text-[11px] text-neutral-500">Tenant #{{ bill.invoice.tenant.id }}</p>
                    </div>
                    <div>
                        <p class="text-[9px] font-bold uppercase tracking-[.12em] text-neutral-400">Abonimi</p>
                        <strong class="mt-2 block text-xs text-neutral-900">#{{ bill.invoice.subscription_id }} · {{ cycleLabel(bill.subscription?.billing_cycle) }}</strong>
                        <p class="mt-1 text-[11px] capitalize text-neutral-500">Statusi: {{ bill.subscription?.status || '—' }}</p>
                    </div>
                    <div>
                        <p class="text-[9px] font-bold uppercase tracking-[.12em] text-neutral-400">Faturimi tjetër</p>
                        <strong class="mt-2 block text-xs text-neutral-900">{{ dateTime(bill.subscription?.next_billing_at) }}</strong>
                        <p class="mt-1 text-[11px] text-neutral-500">Sipas planit të abonimit</p>
                    </div>
                </section>

                <section class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-left">
                        <thead>
                            <tr class="sa-table-head">
                                <th class="px-5 py-2.5 font-bold">Shërbimi</th>
                                <th class="px-4 py-2.5 font-bold">Periudha</th>
                                <th class="px-4 py-2.5 text-right font-bold">Çmimi</th>
                                <th class="px-4 py-2.5 font-bold">Njësia</th>
                                <th class="px-4 py-2.5 text-right font-bold">Sasia</th>
                                <th class="px-5 py-2.5 text-right font-bold">Vlera</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="line in bill.invoice.lines" :key="line.id">
                                <td class="px-5 py-3.5"><strong class="sa-table-primary">{{ line.description }}</strong><p class="sa-table-meta">Shërbim i abonimit</p></td>
                                <td class="whitespace-nowrap px-4 py-3.5 text-[11px] text-neutral-500">{{ date(bill.invoice.period_starts_on) }} – {{ date(bill.invoice.period_ends_on) }}</td>
                                <td class="px-4 py-3.5 text-right text-xs text-neutral-600">{{ money(line.unit_amount_cents) }}</td>
                                <td class="px-4 py-3.5 text-xs text-neutral-600">{{ unitLabel() }}</td>
                                <td class="px-4 py-3.5 text-right text-xs text-neutral-600">{{ quantity(line.quantity) }}</td>
                                <td class="px-5 py-3.5 text-right text-xs font-semibold text-neutral-900">{{ money(line.amount_cents) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="grid border-t border-neutral-200 lg:grid-cols-[1fr_360px]">
                    <div class="border-b border-neutral-200 p-5 lg:border-b-0 lg:border-r">
                        <div class="flex items-start gap-3"><CalendarClock class="sa-icon mt-0.5 text-neutral-400" /><div><strong class="text-xs text-neutral-900">Origjina e dokumentit</strong><p class="mt-1 text-[11px] leading-5 text-neutral-500">{{ bill.invoice.source === 'subscription_schedule' ? 'Krijuar automatikisht nga cikli recurring i abonimit.' : 'Krijuar nga një faturim manual.' }}</p></div></div>
                    </div>
                    <dl class="divide-y divide-neutral-100 px-5 py-2 text-xs">
                        <div class="flex justify-between gap-4 py-2.5"><dt class="text-neutral-500">Nëntotali</dt><dd class="font-semibold">{{ money(bill.invoice.subtotal_cents) }}</dd></div>
                        <div v-if="bill.invoice.discount_cents" class="flex justify-between gap-4 py-2.5"><dt class="text-neutral-500">Zbritje</dt><dd class="font-semibold text-emerald-700">−{{ money(bill.invoice.discount_cents) }}</dd></div>
                        <div class="flex justify-between gap-4 py-2.5"><dt class="text-neutral-500">Taksa</dt><dd class="font-semibold">{{ money(bill.invoice.tax_cents) }}</dd></div>
                        <div class="flex justify-between gap-4 py-3 text-sm"><dt class="font-semibold text-neutral-900">Vlera e Bill-it</dt><dd class="font-bold text-neutral-950">{{ money(bill.invoice.total_cents) }}</dd></div>
                    </dl>
                </section>

                <footer class="grid border-t border-neutral-200 sm:grid-cols-2">
                    <Link :href="`/super-admin/billing/invoices/${bill.invoice.id}`" class="flex items-center justify-between gap-3 border-b border-neutral-200 p-4 no-underline hover:bg-emerald-50/50 sm:border-b-0 sm:border-r">
                        <div class="flex items-center gap-2.5"><span class="sa-icon-box bg-emerald-50 text-emerald-700"><FileText class="sa-icon" /></span><div><span class="block text-[10px] text-neutral-400">Fatura e gjeneruar</span><strong class="text-xs text-neutral-900">{{ bill.invoice.number }} · {{ invoiceStatus(bill.invoice.status) }}</strong></div></div>
                        <span class="text-[11px] font-semibold text-emerald-700">Hap →</span>
                    </Link>
                    <div class="p-4">
                        <div class="flex items-center gap-2.5"><span class="sa-icon-box bg-blue-50 text-blue-700"><CreditCard class="sa-icon" /></span><div><span class="block text-[10px] text-neutral-400">Pagesat e lidhura</span><strong class="text-xs text-neutral-900">{{ bill.payments.length }} pagesa · {{ money(bill.invoice.amount_paid_cents) }}</strong></div></div>
                        <div v-if="bill.payments.length" class="mt-3 divide-y divide-neutral-100 border-t border-neutral-100">
                            <Link v-for="payment in bill.payments" :key="payment.id" :href="`/super-admin/billing/payments/${payment.id}`" class="flex items-center justify-between gap-3 py-2.5 text-[11px] no-underline hover:text-emerald-700"><span>{{ payment.number }}</span><strong>{{ money(payment.amount_cents) }}</strong></Link>
                        </div>
                        <Link v-else :href="`/super-admin/billing/payments?invoice_id=${bill.invoice.id}`" class="sa-button mt-3">Regjistro pagesë</Link>
                    </div>
                </footer>
            </article>
        </main>
    </SuperAdminLayout>
</template>
