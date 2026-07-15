<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import BillingWorkspaceNav from '@/Components/SuperAdmin/BillingWorkspaceNav.vue';
import { CircleAlert, Clock3, FilePlus2, ReceiptText, X } from 'lucide-vue-next';

const props = defineProps({ invoices: Object, tenants: Array, stats: Object, filters: Object });
const createOpen = ref(false);
const selected = ref(null);

const form = useForm({
    tenant_id: '',
    period_starts_on: new Date().toISOString().slice(0, 10),
    period_ends_on: '',
    due_on: new Date(Date.now() + 14 * 86400000).toISOString().slice(0, 10),
    issue_now: false,
    notes: '',
});

const cards = computed(() => [
    { label: 'Paguar këtë muaj', value: money(props.stats.paid_cents), detail: 'Fatura të arkëtuara', icon: ReceiptText },
    { label: 'Për t’u arkëtuar', value: money(props.stats.open_cents), detail: 'Fatura Open', icon: Clock3 },
    { label: 'Pagesa të vonuara', value: money(props.stats.overdue_cents), detail: 'Fatura Overdue', icon: CircleAlert },
]);

function money(cents, currency = 'EUR') {
    return new Intl.NumberFormat('sq-AL', { style: 'currency', currency, minimumFractionDigits: 2 }).format((cents || 0) / 100);
}

function date(value) {
    return value ? new Intl.DateTimeFormat('sq-AL', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(`${value}T12:00:00`)) : '—';
}

function statusLabel(status) {
    return { draft: 'Draft', open: 'Open', paid: 'Paguar', overdue: 'Vonuar', void: 'Anuluar' }[status] || status;
}

function statusClass(status) {
    return {
        draft: 'bg-neutral-100 text-neutral-600', open: 'bg-amber-50 text-amber-700',
        paid: 'bg-emerald-50 text-emerald-700', overdue: 'bg-red-50 text-red-700',
        void: 'bg-neutral-100 text-neutral-500',
    }[status];
}

function filter(key, value) {
    router.get('/super-admin/billing/invoices', { ...props.filters, [key]: value || undefined }, { preserveState: true, replace: true });
}

function submit() {
    form.post('/super-admin/billing/invoices', {
        preserveScroll: true,
        onSuccess: () => { createOpen.value = false; form.reset('tenant_id', 'period_ends_on', 'notes'); },
    });
}

function publish(invoice) {
    router.patch(`/super-admin/billing/invoices/${invoice.id}/publish`, {}, { preserveScroll: true, onSuccess: () => { selected.value = null; } });
}

function voidInvoice(invoice) {
    if (window.confirm(`Të anulohet fatura ${invoice.number}?`)) {
        router.patch(`/super-admin/billing/invoices/${invoice.id}/void`, {}, { preserveScroll: true, onSuccess: () => { selected.value = null; } });
    }
}
</script>

<template>
    <SuperAdminLayout title="Faturat — Lora Control Panel">
        <div class="mx-auto max-w-7xl space-y-6">
            <BillingWorkspaceNav />
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-700">Financa e Lora / Faturat</p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-neutral-950">Faturat</h1>
                    <p class="mt-2 text-sm text-neutral-500">Dokumentet e faturimit të abonimeve dhe moduleve të platformës.</p>
                </div>
                <button class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#16875d] px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-[#116f4c]" @click="createOpen = true">
                    <FilePlus2 class="h-4 w-4" /> Krijo faturë
                </button>
            </div>

            <section class="grid gap-4 md:grid-cols-3">
                <article v-for="card in cards" :key="card.label" class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div><p class="text-sm font-medium text-neutral-500">{{ card.label }}</p><p class="mt-3 text-3xl font-semibold tracking-tight text-neutral-950">{{ card.value }}</p><p class="mt-2 text-xs text-neutral-400">{{ card.detail }}</p></div>
                        <span class="grid h-11 w-11 place-items-center rounded-2xl bg-emerald-50 text-emerald-700"><component :is="card.icon" class="h-5 w-5" /></span>
                    </div>
                </article>
            </section>

            <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm">
                <div class="flex flex-col gap-4 border-b border-neutral-200 px-5 py-4 sm:flex-row sm:items-end sm:justify-between">
                    <div><h2 class="font-semibold text-neutral-900">Lista e faturave</h2><p class="mt-1 text-xs text-neutral-500">Kliko numrin për detajet dhe line items.</p></div>
                    <div class="flex flex-wrap gap-2"><label class="text-xs font-medium text-neutral-600">Hoteli
                        <select :value="filters.tenant_id || ''" class="mt-1 block rounded-xl border-neutral-300 text-sm" @change="filter('tenant_id', $event.target.value)"><option value="">Të gjithë</option><option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">{{ tenant.name }}</option></select>
                    </label><label class="text-xs font-medium text-neutral-600">Statusi
                        <select :value="filters.status" class="mt-1 block rounded-xl border-neutral-300 text-sm" @change="filter('status', $event.target.value)">
                            <option value="">Të gjitha</option><option value="draft">Draft</option><option value="open">Open</option><option value="paid">Paguar</option><option value="overdue">Vonuar</option><option value="void">Anuluar</option>
                        </select>
                    </label></div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200 text-sm">
                        <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500"><tr><th class="px-5 py-3 font-semibold">Fatura</th><th class="px-5 py-3 font-semibold">Hoteli</th><th class="px-5 py-3 font-semibold">Periudha</th><th class="px-5 py-3 font-semibold">Afati</th><th class="px-5 py-3 font-semibold">Statusi</th><th class="px-5 py-3 text-right font-semibold">Totali</th></tr></thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="invoice in invoices.data" :key="invoice.id" class="hover:bg-neutral-50/70">
                                <td class="px-5 py-4"><Link :href="`/super-admin/billing/invoices/${invoice.id}`" class="font-semibold text-emerald-700 no-underline hover:text-emerald-800">{{ invoice.number }}</Link><button class="mt-1 block text-[11px] text-neutral-400 hover:text-neutral-700" @click="selected = invoice">Shiko shpejt</button></td>
                                <td class="px-5 py-4"><Link :href="`/super-admin/tenants/${invoice.tenant.id}`" class="font-medium text-neutral-900 no-underline hover:text-emerald-700">{{ invoice.tenant.name }}</Link><p class="mt-1 text-[11px] text-neutral-400">{{ invoice.subscription_id ? `Abonimi #${invoice.subscription_id}` : 'Pa abonim' }}</p></td>
                                <td class="whitespace-nowrap px-5 py-4 text-neutral-500">{{ date(invoice.period_starts_on) }} – {{ date(invoice.period_ends_on) }}</td>
                                <td class="whitespace-nowrap px-5 py-4 text-neutral-500">{{ date(invoice.due_on) }}</td>
                                <td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(invoice.status)">{{ statusLabel(invoice.status) }}</span></td>
                                <td class="whitespace-nowrap px-5 py-4 text-right font-semibold text-neutral-900">{{ money(invoice.total_cents, invoice.currency) }}</td>
                            </tr>
                            <tr v-if="!invoices.data.length"><td colspan="6" class="px-5 py-12 text-center text-neutral-500">Nuk ka ende fatura.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="invoices.links?.length > 3" class="flex flex-wrap justify-end gap-1 border-t border-neutral-200 px-5 py-4">
                    <Link v-for="link in invoices.links" :key="link.label" :href="link.url || '#'" class="rounded-lg px-3 py-1.5 text-xs no-underline" :class="link.active ? 'bg-[#16875d] text-white' : 'text-neutral-500 hover:bg-neutral-100'" v-html="link.label" />
                </div>
            </section>
        </div>

        <Teleport to="body">
            <div v-if="createOpen" class="fixed inset-0 z-50 flex items-end justify-center bg-neutral-950/50 p-0 sm:items-center sm:p-6" @click.self="createOpen = false">
                <form class="max-h-[94vh] w-full max-w-2xl overflow-y-auto rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl" @submit.prevent="submit">
                    <div class="flex items-start justify-between border-b border-neutral-200 px-5 py-4"><div><h2 class="text-lg font-semibold text-neutral-900">Krijo faturë</h2><p class="mt-1 text-sm text-neutral-500">Çmimet merren si snapshot nga abonimi aktual.</p></div><button type="button" class="rounded-lg p-2 text-neutral-400 hover:bg-neutral-100" @click="createOpen = false"><X class="h-5 w-5" /></button></div>
                    <div class="grid gap-4 p-5 sm:grid-cols-2">
                        <label class="text-sm font-medium text-neutral-700 sm:col-span-2">Hoteli<select v-model="form.tenant_id" required class="mt-1 w-full rounded-xl border-neutral-300 text-sm"><option value="" disabled>Zgjidh hotelin</option><option v-for="tenant in tenants.filter(t => t.has_subscription)" :key="tenant.id" :value="tenant.id">{{ tenant.name }}</option></select><span v-if="form.errors.tenant_id" class="mt-1 block text-xs text-red-600">{{ form.errors.tenant_id }}</span></label>
                        <label class="text-sm font-medium text-neutral-700">Periudha fillon<input v-model="form.period_starts_on" required type="date" class="mt-1 w-full rounded-xl border-neutral-300 text-sm" /></label>
                        <label class="text-sm font-medium text-neutral-700">Periudha mbaron<input v-model="form.period_ends_on" type="date" class="mt-1 w-full rounded-xl border-neutral-300 text-sm" /></label>
                        <label class="text-sm font-medium text-neutral-700">Afati i pagesës<input v-model="form.due_on" required type="date" class="mt-1 w-full rounded-xl border-neutral-300 text-sm" /></label>
                        <label class="flex items-center gap-3 self-end rounded-xl border border-neutral-200 p-3 text-sm font-medium text-neutral-700"><input v-model="form.issue_now" type="checkbox" class="rounded border-neutral-300 text-emerald-600 focus:ring-emerald-500" /> Publiko menjëherë</label>
                        <label class="text-sm font-medium text-neutral-700 sm:col-span-2">Shënime<textarea v-model="form.notes" rows="3" class="mt-1 w-full rounded-xl border-neutral-300 text-sm" /></label>
                        <p v-if="Object.keys(form.errors).length" class="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700 sm:col-span-2">Kontrollo fushat dhe provo përsëri.</p>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-neutral-200 px-5 py-4"><button type="button" class="rounded-xl border border-neutral-300 px-4 py-2 text-sm font-semibold" @click="createOpen = false">Anulo</button><button class="rounded-xl bg-[#16875d] px-4 py-2 text-sm font-semibold text-white" :disabled="form.processing">{{ form.processing ? 'Duke krijuar…' : 'Krijo faturën' }}</button></div>
                </form>
            </div>

            <div v-if="selected" class="fixed inset-0 z-50 flex items-end justify-center bg-neutral-950/50 p-0 sm:items-center sm:p-6" @click.self="selected = null">
                <section class="max-h-[94vh] w-full max-w-3xl overflow-y-auto rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl">
                    <div class="flex items-start justify-between border-b border-neutral-200 px-5 py-4"><div><div class="flex items-center gap-2"><h2 class="text-lg font-semibold text-neutral-900">{{ selected.number }}</h2><span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(selected.status)">{{ statusLabel(selected.status) }}</span></div><p class="mt-1 text-sm text-neutral-500">{{ selected.tenant.name }} · afati {{ date(selected.due_on) }}</p></div><button class="rounded-lg p-2 text-neutral-400 hover:bg-neutral-100" @click="selected = null"><X class="h-5 w-5" /></button></div>
                    <div class="p-5">
                        <div class="overflow-hidden rounded-xl border border-neutral-200"><table class="min-w-full text-sm"><thead class="bg-neutral-50 text-left text-xs uppercase text-neutral-500"><tr><th class="px-4 py-3">Përshkrimi</th><th class="px-4 py-3 text-right">Shuma</th></tr></thead><tbody class="divide-y divide-neutral-100"><tr v-for="line in selected.lines" :key="line.id"><td class="px-4 py-3">{{ line.description }}</td><td class="px-4 py-3 text-right font-medium">{{ money(line.amount_cents, selected.currency) }}</td></tr></tbody><tfoot class="border-t border-neutral-200"><tr v-if="selected.discount_cents"><td class="px-4 py-3 text-neutral-500">Zbritje vjetore</td><td class="px-4 py-3 text-right text-emerald-700">−{{ money(selected.discount_cents, selected.currency) }}</td></tr><tr><td class="px-4 py-3 font-semibold">Total</td><td class="px-4 py-3 text-right text-lg font-semibold">{{ money(selected.total_cents, selected.currency) }}</td></tr><tr><td class="px-4 py-3 text-neutral-500">Mbetur</td><td class="px-4 py-3 text-right font-semibold text-red-700">{{ money(selected.balance_cents, selected.currency) }}</td></tr></tfoot></table></div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-neutral-200 px-5 py-4"><button v-if="selected.status === 'draft'" class="rounded-xl border border-red-200 px-4 py-2 text-sm font-semibold text-red-700" @click="voidInvoice(selected)">Anulo faturën</button><button v-if="selected.status === 'draft'" class="rounded-xl bg-[#16875d] px-4 py-2 text-sm font-semibold text-white" @click="publish(selected)">Publiko</button><button class="rounded-xl border border-neutral-300 px-4 py-2 text-sm font-semibold" @click="selected = null">Mbyll</button></div>
                </section>
            </div>
        </Teleport>
    </SuperAdminLayout>
</template>
