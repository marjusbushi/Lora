<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { CreditCard, FileText, RefreshCw, Webhook } from 'lucide-vue-next';

const page = usePage();
const items = [
    { label: 'Faturat', href: '/super-admin/billing/invoices', match: '/super-admin/billing/invoices', icon: FileText },
    { label: 'Pagesat', href: '/super-admin/billing/payments', match: '/super-admin/billing/payments', icon: CreditCard },
    { label: 'Tentativat', href: '/super-admin/billing/payment-attempts', match: '/super-admin/billing/payment-attempts', icon: RefreshCw },
    { label: 'Provider Events', href: '/super-admin/billing/provider-events', match: '/super-admin/billing/provider-events', icon: Webhook },
];
</script>

<template>
    <nav class="flex gap-1 overflow-x-auto rounded-xl border border-neutral-200 bg-white p-1 shadow-sm" aria-label="Billing modules">
        <Link v-for="item in items" :key="item.href" :href="item.href" class="inline-flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-xs font-semibold no-underline" :class="page.url.startsWith(item.match) ? 'bg-emerald-50 text-emerald-800' : 'text-neutral-500 hover:bg-neutral-50 hover:text-neutral-800'">
            <component :is="item.icon" class="h-4 w-4" /> {{ item.label }}
        </Link>
    </nav>
</template>
