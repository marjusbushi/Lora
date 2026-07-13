<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { ListChecks } from 'lucide-vue-next';

const props = defineProps({
    logs: Object,          // Inertia paginator { data, links, current_page, last_page, total }
    actions: Array,        // distinct action strings for the filter
    filter: Object,        // { action }
});

const rows = computed(() => props.logs?.data || []);

const ACTION_LABELS = {
    'tenant.create': 'Hotel u krijua',
    'tenant.switch': 'Hyrje në hotel',
    'tenant.subscription.update': 'Abonimi u përditësua',
    'tenant.integration.update': 'Integrim u ndryshua',
    'tenant.domain.create': 'Domain u shtua',
    'tenant.domain.delete': 'Domain u hoq',
    'tenant.domain.primary': 'Domain primar u ndryshua',
    'tenant.status': 'Statusi i hotelit',
};

function actionLabel(action) {
    return ACTION_LABELS[action] || action;
}

function toneFor(action) {
    if (action === 'tenant.status') return 'bg-amber-50 text-amber-700';
    if (action.startsWith('tenant.domain')) return 'bg-blue-50 text-blue-700';
    if (action === 'tenant.integration.update') return 'bg-violet-50 text-violet-700';
    if (action === 'tenant.switch') return 'bg-neutral-100 text-neutral-600';
    return 'bg-emerald-50 text-emerald-700';
}

function when(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('sq-AL', {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    }).format(new Date(value));
}

function applyFilter(event) {
    const action = event.target.value;
    router.get(route('super-admin.activity'), action ? { action } : {}, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function go(url) {
    if (url) router.get(url, {}, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <Head title="Aktiviteti — Lora Control Panel" />

    <SuperAdminLayout title="Aktiviteti — Lora Control Panel">
        <div class="mx-auto max-w-5xl space-y-6">
            <PageHeader
                title="Aktiviteti i platformës"
                :breadcrumbs="[{ label: 'Control Panel', href: '/super-admin' }, { label: 'Aktiviteti' }]"
            />

            <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm">
                <div class="flex flex-col gap-3 border-b border-neutral-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="font-semibold text-neutral-900">Gjurma e veprimeve</h2>
                        <p class="mt-1 text-xs text-neutral-500">Çdo veprim i super-adminit mbi hotelet — i pandryshueshëm.</p>
                    </div>
                    <label class="text-sm text-neutral-600">
                        <select
                            :value="filter.action || ''"
                            class="rounded-lg border-neutral-300 text-sm"
                            @change="applyFilter"
                        >
                            <option value="">Të gjitha veprimet</option>
                            <option v-for="a in actions" :key="a" :value="a">{{ actionLabel(a) }}</option>
                        </select>
                    </label>
                </div>

                <div v-if="rows.length" class="divide-y divide-neutral-100">
                    <article v-for="log in rows" :key="log.id" class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="toneFor(log.action)">
                                    {{ actionLabel(log.action) }}
                                </span>
                                <span v-if="log.tenant" class="text-sm font-semibold text-neutral-900">{{ log.tenant }}</span>
                            </div>
                            <p v-if="log.summary" class="mt-1 text-sm text-neutral-600">{{ log.summary }}</p>
                            <p class="mt-1 text-xs text-neutral-400">
                                {{ log.actor }}<template v-if="log.actor_email"> · {{ log.actor_email }}</template>
                                <template v-if="log.ip_address"> · {{ log.ip_address }}</template>
                            </p>
                        </div>
                        <p class="shrink-0 text-xs text-neutral-400 sm:text-right">{{ when(log.created_at) }}</p>
                    </article>
                </div>

                <div v-else class="flex flex-col items-center gap-2 px-5 py-16 text-center">
                    <span class="grid h-12 w-12 place-items-center rounded-2xl bg-neutral-100 text-neutral-400">
                        <ListChecks class="h-6 w-6" :stroke-width="1.8" />
                    </span>
                    <p class="text-sm font-medium text-neutral-700">Ende asnjë veprim i regjistruar</p>
                    <p class="text-xs text-neutral-500">Veprimet mbi hotelet do të shfaqen këtu ndërsa platforma përdoret.</p>
                </div>

                <div v-if="logs.last_page > 1" class="flex items-center justify-between border-t border-neutral-200 px-5 py-3 text-sm">
                    <button
                        class="rounded-lg border border-neutral-200 px-3 py-1.5 text-neutral-600 disabled:opacity-40"
                        :disabled="!logs.prev_page_url"
                        @click="go(logs.prev_page_url)"
                    >
                        ← Të mëparshme
                    </button>
                    <span class="text-xs text-neutral-500">Faqja {{ logs.current_page }} nga {{ logs.last_page }} · {{ logs.total }} veprime</span>
                    <button
                        class="rounded-lg border border-neutral-200 px-3 py-1.5 text-neutral-600 disabled:opacity-40"
                        :disabled="!logs.next_page_url"
                        @click="go(logs.next_page_url)"
                    >
                        Në vazhdim →
                    </button>
                </div>
            </section>
        </div>
    </SuperAdminLayout>
</template>
