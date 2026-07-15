<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import {
    ArrowLeft,
    Building2,
    CalendarClock,
    CheckCircle2,
    ChevronRight,
    CircleDollarSign,
    CreditCard,
    FileText,
    ReceiptText,
    RefreshCw,
    Webhook,
} from 'lucide-vue-next';

const props = defineProps({ invoice: Object });
const activeTab = ref('details');

const tabs = computed(() => [
    { id: 'details', label: 'Detajet', icon: ReceiptText },
    { id: 'payments', label: 'Pagesat', icon: CreditCard, count: props.invoice.payments.length },
    { id: 'attempts', label: 'Tentativat', icon: RefreshCw, count: props.invoice.attempts.length },
    { id: 'events', label: 'Eventet', icon: Webhook, count: props.invoice.events.length },
]);

const summary = computed(() => [
    { label: 'Totali i faturës', value: money(props.invoice.total_cents), icon: FileText },
    { label: 'Shuma e paguar', value: money(props.invoice.amount_paid_cents), icon: CheckCircle2 },
    { label: 'Për t’u paguar', value: money(props.invoice.balance_cents), icon: CircleDollarSign },
    { label: 'Afati i pagesës', value: date(props.invoice.due_on), icon: CalendarClock },
]);

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

function statusClass(status) {
    return {
        draft: 'bg-neutral-100 text-neutral-600',
        open: 'bg-amber-50 text-amber-700 ring-amber-600/10',
        paid: 'bg-emerald-50 text-emerald-700 ring-emerald-600/10',
        overdue: 'bg-red-50 text-red-700 ring-red-600/10',
        void: 'bg-neutral-100 text-neutral-500',
        succeeded: 'bg-emerald-50 text-emerald-700 ring-emerald-600/10',
        failed: 'bg-red-50 text-red-700 ring-red-600/10',
        pending: 'bg-amber-50 text-amber-700 ring-amber-600/10',
    }[status] || 'bg-neutral-100 text-neutral-600';
}

function statusLabel(status) {
    return {
        draft: 'Draft',
        open: 'Për pagesë',
        paid: 'Paguar',
        overdue: 'Me vonesë',
        void: 'Anuluar',
        succeeded: 'Sukses',
        failed: 'Dështuar',
        pending: 'Në proces',
    }[status] || status;
}

function syncTabFromHash() {
    const requested = window.location.hash.replace('#', '');
    activeTab.value = tabs.value.some((tab) => tab.id === requested) ? requested : 'details';
}

function selectTab(tabId) {
    activeTab.value = tabId;
    window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}#${tabId}`);
}

function publishInvoice() {
    router.patch(`/super-admin/billing/invoices/${props.invoice.id}/publish`);
}

function voidInvoice() {
    if (window.confirm(`Të anulohet fatura ${props.invoice.number}?`)) {
        router.patch(`/super-admin/billing/invoices/${props.invoice.id}/void`);
    }
}

onMounted(() => {
    syncTabFromHash();
    window.addEventListener('hashchange', syncTabFromHash);
});

onBeforeUnmount(() => window.removeEventListener('hashchange', syncTabFromHash));
</script>

<template>
    <SuperAdminLayout :title="`${invoice.number} — Billing`">
        <div class="mx-auto max-w-7xl space-y-6">
            <nav class="flex flex-wrap items-center gap-2 text-xs text-neutral-500" aria-label="Breadcrumb">
                <Link href="/super-admin/billing/invoices" class="font-medium text-neutral-500 no-underline hover:text-emerald-700">Faturat</Link>
                <ChevronRight class="h-3.5 w-3.5 text-neutral-300" />
                <Link :href="`/super-admin/tenants/${invoice.tenant.id}`" class="font-medium text-neutral-500 no-underline hover:text-emerald-700">{{ invoice.tenant.name }}</Link>
                <ChevronRight class="h-3.5 w-3.5 text-neutral-300" />
                <span class="font-semibold text-neutral-800">{{ invoice.number }}</span>
            </nav>

            <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm">
                <div class="flex flex-col gap-5 px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex min-w-0 items-start gap-4">
                        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-emerald-50 text-emerald-700">
                            <ReceiptText class="h-6 w-6" />
                        </span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-3">
                                <h1 class="truncate text-2xl font-semibold tracking-tight text-neutral-950 sm:text-3xl">{{ invoice.number }}</h1>
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset" :class="statusClass(invoice.status)">{{ statusLabel(invoice.status) }}</span>
                            </div>
                            <p class="mt-2 text-sm text-neutral-500">
                                Periudha {{ date(invoice.period_starts_on) }} – {{ date(invoice.period_ends_on) }}
                                <span class="mx-1.5 text-neutral-300">•</span>
                                {{ invoice.source === 'subscription_schedule' ? 'Gjeneruar nga abonimi' : 'Krijuar manualisht' }}
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Link href="/super-admin/billing/invoices" class="inline-flex items-center gap-2 rounded-xl border border-neutral-200 px-3.5 py-2 text-sm font-semibold text-neutral-700 no-underline hover:bg-neutral-50">
                            <ArrowLeft class="h-4 w-4" /> Kthehu
                        </Link>
                        <button v-if="invoice.status === 'draft'" class="rounded-xl border border-red-200 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50" @click="voidInvoice">Anulo</button>
                        <button v-if="invoice.status === 'draft'" class="rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800" @click="publishInvoice">Publiko</button>
                        <Link v-if="['open', 'overdue'].includes(invoice.status)" :href="`/super-admin/billing/payments?invoice_id=${invoice.id}`" class="rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white no-underline hover:bg-emerald-800">Regjistro pagesë</Link>
                    </div>
                </div>

                <div class="grid border-t border-neutral-200 sm:grid-cols-2 xl:grid-cols-4">
                    <article v-for="(item, index) in summary" :key="item.label" class="flex items-center gap-3 border-neutral-200 px-5 py-4 sm:px-6" :class="{ 'border-t sm:border-t-0': index > 0, 'xl:border-l': index > 0 }">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-neutral-50 text-neutral-500"><component :is="item.icon" class="h-4 w-4" /></span>
                        <div>
                            <p class="text-xs font-medium text-neutral-500">{{ item.label }}</p>
                            <p class="mt-1 text-lg font-semibold text-neutral-950">{{ item.value }}</p>
                        </div>
                    </article>
                </div>
            </section>

            <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm">
                <div class="overflow-x-auto border-b border-neutral-200 px-3 sm:px-5">
                    <div class="flex min-w-max gap-1" role="tablist" aria-label="Përmbajtja e faturës">
                        <button
                            v-for="tab in tabs"
                            :id="`invoice-tab-${tab.id}`"
                            :key="tab.id"
                            type="button"
                            role="tab"
                            :aria-selected="activeTab === tab.id"
                            :aria-controls="`invoice-panel-${tab.id}`"
                            class="relative inline-flex items-center gap-2 px-3 py-4 text-sm font-semibold transition-colors sm:px-4"
                            :class="activeTab === tab.id ? 'text-emerald-800 after:absolute after:inset-x-2 after:bottom-0 after:h-0.5 after:rounded-full after:bg-emerald-700' : 'text-neutral-500 hover:text-neutral-800'"
                            @click="selectTab(tab.id)"
                        >
                            <component :is="tab.icon" class="h-4 w-4" />
                            {{ tab.label }}
                            <span v-if="tab.count !== undefined" class="rounded-full px-2 py-0.5 text-[11px]" :class="activeTab === tab.id ? 'bg-emerald-50 text-emerald-700' : 'bg-neutral-100 text-neutral-500'">{{ tab.count }}</span>
                        </button>
                    </div>
                </div>

                <div v-if="activeTab === 'details'" id="invoice-panel-details" role="tabpanel" aria-labelledby="invoice-tab-details" class="p-4 sm:p-6">
                    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.7fr)_minmax(280px,.65fr)]">
                        <div class="overflow-hidden rounded-xl border border-neutral-200">
                            <div class="border-b border-neutral-200 px-4 py-3.5 sm:px-5">
                                <h2 class="font-semibold text-neutral-900">Zërat e faturës</h2>
                                <p class="mt-1 text-xs text-neutral-500">Shërbimet dhe modulet e përfshira në këtë periudhë.</p>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                                        <tr><th class="px-4 py-3 sm:px-5">Përshkrimi</th><th class="px-4 py-3 text-right sm:px-5">Çmimi</th><th class="px-4 py-3 text-right sm:px-5">Shuma</th></tr>
                                    </thead>
                                    <tbody class="divide-y divide-neutral-100">
                                        <tr v-for="line in invoice.lines" :key="line.id">
                                            <td class="px-4 py-4 font-medium text-neutral-900 sm:px-5">{{ line.description }}</td>
                                            <td class="px-4 py-4 text-right text-neutral-500 sm:px-5">{{ money(line.unit_amount_cents) }}</td>
                                            <td class="px-4 py-4 text-right font-semibold text-neutral-900 sm:px-5">{{ money(line.amount_cents) }}</td>
                                        </tr>
                                    </tbody>
                                    <tfoot class="border-t border-neutral-200 bg-neutral-50/60 text-sm">
                                        <tr v-if="invoice.discount_cents"><td colspan="2" class="px-4 pt-3 text-neutral-500 sm:px-5">Zbritje</td><td class="px-4 pt-3 text-right font-medium text-emerald-700 sm:px-5">−{{ money(invoice.discount_cents) }}</td></tr>
                                        <tr v-if="invoice.tax_cents"><td colspan="2" class="px-4 pt-3 text-neutral-500 sm:px-5">Taksa</td><td class="px-4 pt-3 text-right font-medium sm:px-5">{{ money(invoice.tax_cents) }}</td></tr>
                                        <tr><td colspan="2" class="px-4 py-4 font-semibold text-neutral-900 sm:px-5">Totali</td><td class="px-4 py-4 text-right text-lg font-semibold text-neutral-950 sm:px-5">{{ money(invoice.total_cents) }}</td></tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <aside class="space-y-4">
                            <div class="rounded-xl border border-neutral-200 p-4">
                                <div class="flex items-center gap-3">
                                    <span class="grid h-9 w-9 place-items-center rounded-xl bg-emerald-50 text-emerald-700"><Building2 class="h-4 w-4" /></span>
                                    <div><p class="text-xs text-neutral-500">Hoteli</p><p class="font-semibold text-neutral-900">{{ invoice.tenant.name }}</p></div>
                                </div>
                                <dl class="mt-4 space-y-3 border-t border-neutral-100 pt-4 text-sm">
                                    <div class="flex justify-between gap-3"><dt class="text-neutral-500">Abonimi</dt><dd class="font-semibold">#{{ invoice.subscription_id }}</dd></div>
                                    <div class="flex justify-between gap-3"><dt class="text-neutral-500">Statusi</dt><dd class="font-semibold capitalize">{{ invoice.subscription?.status || '—' }}</dd></div>
                                    <div class="flex justify-between gap-3"><dt class="text-neutral-500">Cikli</dt><dd class="font-semibold capitalize">{{ invoice.subscription?.billing_cycle || '—' }}</dd></div>
                                    <div class="flex justify-between gap-3"><dt class="text-neutral-500">Faturimi tjetër</dt><dd class="text-right font-semibold">{{ dateTime(invoice.subscription?.next_billing_at) }}</dd></div>
                                </dl>
                                <Link :href="`/super-admin/tenants/${invoice.tenant.id}`" class="mt-4 block rounded-xl border border-neutral-200 px-3 py-2.5 text-center text-xs font-semibold text-neutral-700 no-underline hover:border-emerald-200 hover:bg-emerald-50 hover:text-emerald-800">Shiko hotelin dhe abonimin</Link>
                            </div>
                            <div class="rounded-xl border border-neutral-200 p-4">
                                <h3 class="text-sm font-semibold text-neutral-900">Shënime</h3>
                                <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-neutral-500">{{ invoice.notes || 'Nuk ka shënime për këtë faturë.' }}</p>
                            </div>
                        </aside>
                    </div>
                </div>

                <div v-else-if="activeTab === 'payments'" id="invoice-panel-payments" role="tabpanel" aria-labelledby="invoice-tab-payments">
                    <div class="flex items-center justify-between border-b border-neutral-100 px-5 py-4 sm:px-6">
                        <div><h2 class="font-semibold text-neutral-900">Pagesat e faturës</h2><p class="mt-1 text-xs text-neutral-500">Të gjitha pagesat e regjistruara për {{ invoice.number }}.</p></div>
                        <strong class="text-sm text-neutral-900">{{ money(invoice.amount_paid_cents) }}</strong>
                    </div>
                    <div v-if="invoice.payments.length" class="divide-y divide-neutral-100">
                        <Link v-for="payment in invoice.payments" :key="payment.id" :href="`/super-admin/billing/payments/${payment.id}`" class="grid gap-3 px-5 py-4 text-sm no-underline hover:bg-neutral-50 sm:grid-cols-[1fr_auto_auto] sm:items-center sm:px-6">
                            <div><strong class="text-neutral-900">{{ payment.number }}</strong><p class="mt-1 text-xs text-neutral-500">{{ payment.provider }} · {{ payment.method }} · {{ payment.recorder || 'Sistem' }}</p></div>
                            <span class="text-xs text-neutral-500">{{ dateTime(payment.paid_at) }}</span>
                            <strong class="text-emerald-700">{{ money(payment.amount_cents) }}</strong>
                        </Link>
                    </div>
                    <div v-else class="grid min-h-60 place-items-center px-5 py-12 text-center"><div><CreditCard class="mx-auto h-8 w-8 text-neutral-300" /><p class="mt-3 text-sm font-semibold text-neutral-700">Nuk ka pagesa</p><p class="mt-1 text-xs text-neutral-500">Pagesat e kësaj fature do të shfaqen këtu.</p></div></div>
                </div>

                <div v-else-if="activeTab === 'attempts'" id="invoice-panel-attempts" role="tabpanel" aria-labelledby="invoice-tab-attempts">
                    <div class="border-b border-neutral-100 px-5 py-4 sm:px-6"><h2 class="font-semibold text-neutral-900">Tentativat e pagesës</h2><p class="mt-1 text-xs text-neutral-500">Historiku i çdo tentative online dhe rezultati i saj.</p></div>
                    <div v-if="invoice.attempts.length" class="divide-y divide-neutral-100">
                        <Link v-for="attempt in invoice.attempts" :key="attempt.id" :href="`/super-admin/billing/payment-attempts/${attempt.id}`" class="grid gap-3 px-5 py-4 text-sm no-underline hover:bg-neutral-50 sm:grid-cols-[1fr_auto_auto_auto] sm:items-center sm:px-6">
                            <div><strong class="text-neutral-900">Tentativa #{{ attempt.attempt_number }}</strong><p class="mt-1 text-xs text-neutral-500">{{ attempt.provider }} · {{ attempt.provider_attempt_id || 'Pa ID të jashtëm' }}</p></div>
                            <span class="text-xs text-neutral-500">{{ dateTime(attempt.attempted_at) }}</span>
                            <span class="w-fit rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset" :class="statusClass(attempt.status)">{{ statusLabel(attempt.status) }}</span>
                            <strong class="text-neutral-900">{{ money(attempt.amount_cents) }}</strong>
                        </Link>
                    </div>
                    <div v-else class="grid min-h-60 place-items-center px-5 py-12 text-center"><div><RefreshCw class="mx-auto h-8 w-8 text-neutral-300" /><p class="mt-3 text-sm font-semibold text-neutral-700">Nuk ka tentativa online</p><p class="mt-1 text-xs text-neutral-500">Tentativat e ardhshme do të shfaqen këtu.</p></div></div>
                </div>

                <div v-else id="invoice-panel-events" role="tabpanel" aria-labelledby="invoice-tab-events">
                    <div class="border-b border-neutral-100 px-5 py-4 sm:px-6"><h2 class="font-semibold text-neutral-900">Eventet e provider-it</h2><p class="mt-1 text-xs text-neutral-500">Webhook-et dhe eventet teknike të lidhura me faturën.</p></div>
                    <div v-if="invoice.events.length" class="divide-y divide-neutral-100">
                        <Link v-for="event in invoice.events" :key="event.id" :href="`/super-admin/billing/provider-events/${event.id}`" class="grid gap-3 px-5 py-4 text-sm no-underline hover:bg-neutral-50 sm:grid-cols-[1fr_auto_auto] sm:items-center sm:px-6">
                            <div><strong class="text-neutral-900">{{ event.event_type }}</strong><p class="mt-1 font-mono text-xs text-neutral-400">{{ event.provider }} · {{ event.external_id }}</p></div>
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset" :class="statusClass(event.status)">{{ statusLabel(event.status) }}</span>
                            <span class="text-xs text-neutral-500">{{ dateTime(event.occurred_at) }}</span>
                        </Link>
                    </div>
                    <div v-else class="grid min-h-60 place-items-center px-5 py-12 text-center"><div><Webhook class="mx-auto h-8 w-8 text-neutral-300" /><p class="mt-3 text-sm font-semibold text-neutral-700">Nuk ka evente</p><p class="mt-1 text-xs text-neutral-500">Webhook-et e lidhura do të shfaqen këtu.</p></div></div>
                </div>
            </section>
        </div>
    </SuperAdminLayout>
</template>
