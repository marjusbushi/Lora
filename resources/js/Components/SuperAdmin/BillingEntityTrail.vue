<script setup>
import { Link } from '@inertiajs/vue3';
import { Building2, CreditCard, FileText, RefreshCw, Repeat2, Webhook } from 'lucide-vue-next';

defineProps({
    tenant: Object,
    subscriptionId: [Number, String],
    invoice: Object,
    payment: Object,
    attempt: Object,
    event: Object,
});
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 text-xs">
        <Link v-if="tenant" :href="`/super-admin/tenants/${tenant.id}`" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-200 bg-white px-2.5 py-1.5 font-semibold text-neutral-700 no-underline hover:border-emerald-200 hover:text-emerald-800"><Building2 class="h-3.5 w-3.5" /> {{ tenant.name }}</Link>
        <Link v-if="tenant && subscriptionId" :href="`/super-admin/tenants/${tenant.id}`" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-200 bg-white px-2.5 py-1.5 font-semibold text-neutral-700 no-underline hover:border-emerald-200 hover:text-emerald-800"><Repeat2 class="h-3.5 w-3.5" /> Abonimi #{{ subscriptionId }}</Link>
        <Link v-if="invoice" :href="`/super-admin/billing/invoices/${invoice.id}`" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-200 bg-white px-2.5 py-1.5 font-semibold text-neutral-700 no-underline hover:border-emerald-200 hover:text-emerald-800"><FileText class="h-3.5 w-3.5" /> {{ invoice.number }}</Link>
        <Link v-if="payment" :href="`/super-admin/billing/payments/${payment.id}`" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-200 bg-white px-2.5 py-1.5 font-semibold text-neutral-700 no-underline hover:border-emerald-200 hover:text-emerald-800"><CreditCard class="h-3.5 w-3.5" /> {{ payment.number }}</Link>
        <Link v-if="attempt" :href="`/super-admin/billing/payment-attempts/${attempt.id}`" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-200 bg-white px-2.5 py-1.5 font-semibold text-neutral-700 no-underline hover:border-emerald-200 hover:text-emerald-800"><RefreshCw class="h-3.5 w-3.5" /> Tentativa #{{ attempt.id }}</Link>
        <Link v-if="event" :href="`/super-admin/billing/provider-events/${event.id}`" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-200 bg-white px-2.5 py-1.5 font-semibold text-neutral-700 no-underline hover:border-emerald-200 hover:text-emerald-800"><Webhook class="h-3.5 w-3.5" /> Event #{{ event.id }}</Link>
    </div>
</template>
