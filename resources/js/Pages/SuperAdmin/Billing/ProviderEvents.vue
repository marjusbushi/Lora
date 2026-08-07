<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import BillingPageHeader from '@/Components/SuperAdmin/BillingPageHeader.vue';
import { translate } from '@/i18n';
import { BadgeCheck, CircleAlert, CopyCheck, RotateCw } from 'lucide-vue-next';

const props = defineProps({ events: Object, stats: Object });
const cards = computed(() => [
    { label: translate('superAdmin.billing.statusProcessed'), value: props.stats.processed, detail: translate('superAdmin.billing.completedEvents'), icon: BadgeCheck },
    { label: translate('superAdmin.billing.statusFailed'), value: props.stats.failed, detail: translate('superAdmin.billing.needRetry'), icon: CircleAlert },
    { label: translate('superAdmin.billing.statusDuplicate'), value: props.stats.duplicates, detail: translate('superAdmin.billing.blockedByIdempotency'), icon: CopyCheck },
]);

function dateTime(value) {
    return value ? new Intl.DateTimeFormat('sq-AL', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : '—';
}

function statusLabel(status) {
    return {
        processed: translate('superAdmin.billing.statusProcessed'),
        failed: translate('superAdmin.billing.statusFailed'),
        duplicate: translate('superAdmin.billing.statusDuplicate'),
        pending: translate('superAdmin.billing.statusPending'),
    }[status] || status;
}

function statusClass(status) {
    return { processed: 'bg-emerald-50 text-emerald-700', failed: 'bg-red-50 text-red-700', duplicate: 'bg-neutral-100 text-neutral-600', pending: 'bg-amber-50 text-amber-700' }[status] || 'bg-neutral-100 text-neutral-600';
}

function retry(event) {
    router.patch(`/super-admin/billing/provider-events/${event.id}/retry`, {}, { preserveScroll: true });
}
</script>

<template>
    <SuperAdminLayout title="Provider Events — Lora Control Panel">
        <main class="sa-page max-w-[1320px] space-y-4">
            <BillingPageHeader title="Provider Events" :subtitle="$t('superAdmin.billing.providerEventsSubtitle')" />

            <section class="grid gap-3 md:grid-cols-3">
                <article v-for="card in cards" :key="card.label" class="sa-card sa-kpi-card">
                    <div class="flex items-start justify-between gap-4">
                        <div><p class="sa-kpi-label">{{ card.label }}</p><p class="sa-kpi-value">{{ card.value }}</p><p class="sa-kpi-meta">{{ card.detail }}</p></div>
                        <span class="sa-icon-box bg-emerald-50 text-emerald-700"><component :is="card.icon" class="sa-icon" /></span>
                    </div>
                </article>
            </section>

            <section class="sa-card">
                <div class="sa-card-header"><div><h2 class="sa-card-title">{{ $t('superAdmin.billing.recentEvents') }}</h2><p class="sa-card-subtitle">{{ $t('superAdmin.billing.recentEventsSubtitle') }}</p></div></div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left">
                        <thead><tr class="sa-table-head"><th class="px-4 py-2.5 font-bold">Event ID</th><th class="px-4 py-2.5 font-bold">{{ $t('superAdmin.billing.providerType') }}</th><th class="px-4 py-2.5 font-bold">{{ $t('superAdmin.compact.hotel') }}</th><th class="px-4 py-2.5 font-bold">{{ $t('superAdmin.billing.links') }}</th><th class="px-4 py-2.5 font-bold">{{ $t('superAdmin.auto.copy114') }}</th><th class="px-4 py-2.5 font-bold">{{ $t('superAdmin.compact.status') }}</th><th class="px-4 py-2.5 text-right font-bold">{{ $t('superAdmin.activity.action') }}</th></tr></thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="event in events.data" :key="event.id" class="hover:bg-neutral-50">
                                <td class="px-4 py-3"><Link :href="`/super-admin/billing/provider-events/${event.id}`" class="font-mono text-[11px] font-semibold text-emerald-700 no-underline">{{ event.external_id }}</Link></td>
                                <td class="px-4 py-3"><strong class="sa-table-primary capitalize">{{ event.provider }}</strong><span class="sa-table-meta block font-mono">{{ event.event_type }}</span></td>
                                <td class="px-4 py-3"><Link v-if="event.tenant" :href="`/super-admin/tenants/${event.tenant.id}`" class="text-xs font-semibold text-neutral-700 no-underline hover:text-emerald-700">{{ event.tenant.name }}</Link><span v-else class="text-xs">{{ $t('superAdmin.billing.platform') }}</span></td>
                                <td class="px-4 py-3 text-xs"><Link v-if="event.invoice" :href="`/super-admin/billing/invoices/${event.invoice.id}`" class="block font-semibold text-emerald-700 no-underline">{{ event.invoice.number }}</Link><Link v-if="event.payment" :href="`/super-admin/billing/payments/${event.payment.id}`" class="sa-table-meta block no-underline">{{ event.payment.number }}</Link><Link v-if="event.attempt" :href="`/super-admin/billing/payment-attempts/${event.attempt.id}`" class="sa-table-meta block no-underline">{{ $t('superAdmin.billing.attemptNumberRef', { number: event.attempt.id }) }}</Link><span v-if="!event.invoice && !event.payment && !event.attempt">—</span></td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs text-neutral-500">{{ dateTime(event.occurred_at) }}</td>
                                <td class="px-4 py-3"><span class="rounded-full px-2 py-1 text-[10px] font-bold" :class="statusClass(event.status)">{{ statusLabel(event.status) }}</span><span v-if="event.attempt_count" class="ml-2 text-[10px] text-neutral-400">retry {{ event.attempt_count }}</span></td>
                                <td class="px-4 py-3 text-right"><button v-if="event.status === 'failed'" type="button" class="sa-button !h-8 !min-h-8 !px-2.5" @click="retry(event)"><RotateCw class="sa-icon" /> Retry</button><Link v-else :href="`/super-admin/billing/provider-events/${event.id}`" class="text-[11px] font-bold text-emerald-700 no-underline">{{ $t('superAdmin.compact.open') }}</Link></td>
                            </tr>
                            <tr v-if="!events.data.length"><td colspan="7" class="px-4 py-10 text-center"><p class="text-xs font-semibold text-neutral-700">{{ $t('superAdmin.billing.noProviderEventsYet') }}</p><p class="sa-table-meta">{{ $t('superAdmin.billing.eventsAppearAfterProvider') }}</p></td></tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="events.links?.length > 3" class="flex flex-wrap justify-end gap-1 border-t border-neutral-200 px-4 py-3"><Link v-for="link in events.links" :key="link.label" :href="link.url || '#'" class="rounded-lg px-3 py-1.5 text-[11px] no-underline" :class="link.active ? 'bg-emerald-700 text-white' : 'text-neutral-500 hover:bg-neutral-100'" v-html="link.label" /></div>
            </section>
        </main>
    </SuperAdminLayout>
</template>
