<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Bot, ChevronDown, ChevronUp, History, UserRound } from 'lucide-vue-next';

defineProps({
    entries: { type: Array, default: () => [] },
    showIp: { type: Boolean, default: false },
});

const expanded = ref(new Set());
const sourceLabels = {
    staff: 'Stafi', channex: 'Channex / OTA', website: 'Faqja online',
    import: 'Import', system: 'Sistemi',
};

function toggle(id) {
    const next = new Set(expanded.value);
    next.has(id) ? next.delete(id) : next.add(id);
    expanded.value = next;
}

function visibleChanges(entry) {
    return expanded.value.has(entry.id) ? entry.changes : entry.changes.slice(0, 4);
}

function formatDateTime(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString('sq-AL', {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}

function dotClass(action) {
    if (action.includes('cancel') || action.includes('delete')) return 'bg-error-500 ring-error-100';
    if (action.includes('check_in') || action.includes('check_out') || action.startsWith('payment.')) return 'bg-success-500 ring-success-100';
    if (action.includes('created')) return 'bg-accent-500 ring-accent-100';
    return 'bg-info-500 ring-info-100';
}
</script>

<template>
    <div v-if="entries.length" class="divide-y divide-neutral-100">
        <article v-for="entry in entries" :key="entry.id" class="relative px-5 py-4 sm:pl-12">
            <span class="absolute left-5 top-5 hidden h-2.5 w-2.5 rounded-full ring-4 sm:block" :class="dotClass(entry.action)" />

            <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                <div class="min-w-0">
                    <p class="text-body-sm font-semibold text-primary-900">{{ entry.label }}</p>
                    <Link v-if="entry.subject?.url" :href="entry.subject.url" class="mt-0.5 block truncate text-small text-accent-700 no-underline hover:underline">
                        {{ entry.subject.label }}
                    </Link>
                    <p v-else-if="entry.subject?.label" class="mt-0.5 truncate text-small text-neutral-500">{{ entry.subject.label }}</p>
                </div>
                <time class="shrink-0 text-tiny text-neutral-400">{{ formatDateTime(entry.created_at) }}</time>
            </div>

            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-small text-neutral-500">
                <span class="inline-flex items-center gap-1.5">
                    <UserRound v-if="entry.actor" class="h-3.5 w-3.5" :stroke-width="1.8" />
                    <Bot v-else class="h-3.5 w-3.5" :stroke-width="1.8" />
                    {{ entry.actor || sourceLabels[entry.source] || entry.source }}
                </span>
                <span class="rounded-full bg-neutral-100 px-2 py-0.5 text-tiny text-neutral-600">{{ sourceLabels[entry.source] || entry.source }}</span>
                <span v-if="showIp && entry.ip_address" class="text-tiny text-neutral-400">IP {{ entry.ip_address }}</span>
            </div>

            <div v-if="entry.changes.length" class="mt-3 grid gap-2 sm:grid-cols-2">
                <div v-for="change in visibleChanges(entry)" :key="change.field" class="rounded-md border border-neutral-100 bg-neutral-50 px-3 py-2">
                    <p class="text-tiny font-medium uppercase tracking-wide text-neutral-500">{{ change.label }}</p>
                    <p class="mt-0.5 break-words text-small text-neutral-700">
                        <span class="text-neutral-400 line-through">{{ change.from }}</span>
                        <span class="px-1.5 text-neutral-400">→</span>
                        <span class="font-medium text-primary-900">{{ change.to }}</span>
                    </p>
                </div>
            </div>

            <div v-if="entry.details.length" class="mt-2 flex flex-wrap gap-2">
                <span v-for="detail in entry.details" :key="detail.label" class="rounded-md bg-neutral-50 px-2 py-1 text-tiny text-neutral-600">
                    {{ detail.label }}: <b>{{ detail.value }}</b>
                </span>
            </div>

            <button v-if="entry.changes.length > 4" type="button" class="mt-2 inline-flex items-center gap-1 text-small font-medium text-accent-700" @click="toggle(entry.id)">
                <template v-if="expanded.has(entry.id)"><ChevronUp class="h-3.5 w-3.5" /> Më pak</template>
                <template v-else><ChevronDown class="h-3.5 w-3.5" /> Shiko të gjitha ({{ entry.changes.length }})</template>
            </button>
        </article>
    </div>

    <div v-else class="px-6 py-10 text-center">
        <History class="mx-auto h-8 w-8 text-neutral-300" :stroke-width="1.5" />
        <p class="mt-2 text-body-sm text-neutral-500">Nuk ka ende veprime të regjistruara.</p>
    </div>
</template>
