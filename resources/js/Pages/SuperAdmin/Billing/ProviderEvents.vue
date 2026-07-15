<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { BadgeCheck, CircleAlert, CopyCheck, RotateCw } from 'lucide-vue-next';

const props = defineProps({ events: Object, stats: Object });
const cards = computed(() => [
    { label: 'Processed', value: props.stats.processed, detail: 'Evente të përpunuara', icon: BadgeCheck },
    { label: 'Failed', value: props.stats.failed, detail: 'Kërkojnë retry', icon: CircleAlert },
    { label: 'Duplicate', value: props.stats.duplicates, detail: 'U ndaluan nga idempotency', icon: CopyCheck },
]);

function dateTime(value) {
    return value ? new Intl.DateTimeFormat('sq-AL', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : '—';
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
        <div class="mx-auto max-w-7xl space-y-6">
            <div><p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-700">Financa e Lora / Provider Events</p><h1 class="mt-2 text-3xl font-semibold tracking-tight text-neutral-950">Provider Events</h1><p class="mt-2 text-sm text-neutral-500">Webhook-et, idempotency dhe audit trail i providerëve të pagesave.</p></div>

            <section class="grid gap-4 md:grid-cols-3"><article v-for="card in cards" :key="card.label" class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm"><div class="flex items-start justify-between gap-4"><div><p class="text-sm font-medium text-neutral-500">{{ card.label }}</p><p class="mt-3 text-3xl font-semibold tracking-tight text-neutral-950">{{ card.value }}</p><p class="mt-2 text-xs text-neutral-400">{{ card.detail }}</p></div><span class="grid h-11 w-11 place-items-center rounded-2xl bg-emerald-50 text-emerald-700"><component :is="card.icon" class="h-5 w-5" /></span></div></article></section>

            <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm">
                <div class="border-b border-neutral-200 px-5 py-4"><h2 class="font-semibold text-neutral-900">Eventet e fundit</h2><p class="mt-1 text-xs text-neutral-500">Modul teknik vetëm për Super Admin; nuk shfaqet te hoteli.</p></div>
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-neutral-200 text-sm"><thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500"><tr><th class="px-5 py-3 font-semibold">Event ID</th><th class="px-5 py-3 font-semibold">Provider</th><th class="px-5 py-3 font-semibold">Tipi</th><th class="px-5 py-3 font-semibold">Hoteli</th><th class="px-5 py-3 font-semibold">Koha</th><th class="px-5 py-3 font-semibold">Statusi</th><th class="px-5 py-3 text-right font-semibold">Veprimi</th></tr></thead><tbody class="divide-y divide-neutral-100"><tr v-for="event in events.data" :key="event.id"><td class="px-5 py-4 font-mono text-xs text-neutral-700">{{ event.external_id }}</td><td class="px-5 py-4 font-medium text-neutral-900">{{ event.provider }}</td><td class="px-5 py-4 font-mono text-xs text-neutral-500">{{ event.event_type }}</td><td class="px-5 py-4 text-neutral-500">{{ event.tenant_name || 'Platformë' }}</td><td class="whitespace-nowrap px-5 py-4 text-neutral-500">{{ dateTime(event.occurred_at) }}</td><td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(event.status)">{{ event.status }}</span><span v-if="event.attempt_count" class="ml-2 text-xs text-neutral-400">retry {{ event.attempt_count }}</span></td><td class="px-5 py-4 text-right"><button v-if="event.status === 'failed'" class="inline-flex items-center gap-1 rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-semibold text-neutral-700 hover:bg-neutral-50" @click="retry(event)"><RotateCw class="h-3.5 w-3.5" /> Retry</button><span v-else class="text-neutral-300">—</span></td></tr><tr v-if="!events.data.length"><td colspan="7" class="px-5 py-12 text-center"><p class="font-medium text-neutral-700">Nuk ka ende provider events.</p><p class="mt-1 text-sm text-neutral-400">Eventet do të shfaqen kur të lidhet provider-i online.</p></td></tr></tbody></table></div>
                <div v-if="events.links?.length > 3" class="flex flex-wrap justify-end gap-1 border-t border-neutral-200 px-5 py-4"><Link v-for="link in events.links" :key="link.label" :href="link.url || '#'" class="rounded-lg px-3 py-1.5 text-xs no-underline" :class="link.active ? 'bg-[#16875d] text-white' : 'text-neutral-500 hover:bg-neutral-100'" v-html="link.label" /></div>
            </section>
        </div>
    </SuperAdminLayout>
</template>
