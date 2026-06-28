<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import { Printer } from 'lucide-vue-next';

// Shared shell for every report screen: breadcrumb back to the hub, an optional
// date-range filter (from/to → re-GETs the same route), a print button, and the
// report body in the default slot. Extra filters go in the #filters slot.
const props = defineProps({
    title: { type: String, required: true },
    routeName: { type: String, default: null }, // route to re-GET when applying the range
    filters: { type: Object, default: null },    // { from, to } — omit for no date range
});

const from = ref(props.filters?.from || '');
const to = ref(props.filters?.to || '');

function apply() {
    if (!props.routeName) return;
    router.get(route(props.routeName), { from: from.value, to: to.value }, { preserveState: true, preserveScroll: true });
}
function doPrint() {
    window.print();
}
</script>

<template>
    <AppLayout>
        <PageHeader
            :title="title"
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Raporte', href: route('reports.index') }, { label: title }]"
        >
            <template #actions>
                <Button variant="ghost" @click="doPrint"><Printer class="h-4 w-4 mr-1.5" :stroke-width="1.75" /> Printo</Button>
            </template>
        </PageHeader>

        <div v-if="filters" class="mt-6 flex flex-wrap items-end gap-3 print:hidden">
            <div>
                <label class="block text-label text-neutral-600 mb-1.5">Nga</label>
                <input type="date" v-model="from" class="rounded-lg border border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-2 focus:ring-accent-500/40" />
            </div>
            <div>
                <label class="block text-label text-neutral-600 mb-1.5">Deri</label>
                <input type="date" v-model="to" class="rounded-lg border border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-2 focus:ring-accent-500/40" />
            </div>
            <Button variant="primary" @click="apply">Apliko</Button>
            <slot name="filters" />
        </div>

        <div class="mt-6">
            <slot />
        </div>
    </AppLayout>
</template>
