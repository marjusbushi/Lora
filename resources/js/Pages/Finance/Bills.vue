<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { money } from './financeShared.js';

const props = defineProps({
    bills: Object,
    suppliers: Array,
    categories: Array,
    accounts: Array,
    byCategory: Object,
    filters: Object,
    fxRate: Number,
    can: Object,
});

const chips = [
    { key: null, label: 'Të gjitha' },
    { key: 'unpaid', label: 'Të papaguara' },
    { key: 'due', label: 'Me afat sot / vonesë' },
];
function filter(f) {
    router.get(route('finance.bills'), f ? { filter: f } : {}, { preserveState: true, preserveScroll: true });
}

const statusPill = {
    open: { text: 'E papaguar', cls: 'bg-error-50 text-error-600' },
    partial: { text: 'Pjesërisht', cls: 'bg-warning-50 text-warning-700' },
    paid: { text: 'Paguar', cls: 'bg-accent-50 text-accent-700' },
};

// -- new bill --
const showNew = ref(false);
const form = useForm({
    supplier_id: null, number: '', category: props.categories[0],
    issue_date: new Date().toISOString().slice(0, 10), due_date: null,
    currency: 'ALL', fx_rate: props.fxRate, total: null, notes: '',
});
function submit() {
    form.post(route('finance.bills.store'), {
        preserveScroll: true,
        onSuccess: () => { showNew.value = false; form.reset('number', 'total', 'notes'); },
    });
}

// -- pay bill --
const paying = ref(null);
const payForm = useForm({ account_id: props.accounts[0]?.id, amount: null, method: 'cash' });
function openPay(bill) {
    paying.value = bill;
    payForm.amount = bill.currency === 'EUR'
        ? bill.remaining_base
        : Math.round(bill.remaining_base * (bill.fx_rate || 1) * 100) / 100;
}
function submitPay() {
    payForm.post(route('finance.bills.pay', paying.value.id), {
        preserveScroll: true,
        onSuccess: () => { paying.value = null; },
    });
}
</script>

<template>
    <AppLayout>
        <PageHeader title="Blerjet (Bills)" :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Financa' }, { label: 'Blerjet' }]">
            <template #actions>
                <Button v-if="can.manageBills" @click="showNew = true">+ Faturë blerjeje</Button>
            </template>
        </PageHeader>

        <div class="px-4 sm:px-6 pb-10 space-y-4">
            <div class="flex gap-2 flex-wrap">
                <button
                    v-for="c in chips" :key="c.label" type="button"
                    class="rounded-full border px-3.5 py-1.5 text-tiny font-semibold transition"
                    :class="(filters.filter || null) === c.key ? 'bg-primary-900 border-primary-900 text-white' : 'bg-white border-neutral-200 text-neutral-500 hover:border-neutral-300'"
                    @click="filter(c.key)"
                >{{ c.label }}</button>
            </div>

            <div class="grid gap-4 lg:grid-cols-[2fr,1fr] items-start">
                <Card>
                    <div class="overflow-x-auto">
                        <table class="w-full text-body-sm tabular-nums">
                            <thead><tr class="text-tiny uppercase tracking-wide text-neutral-400 text-left border-b border-neutral-100">
                                <th class="py-2 pr-3">Furnitori</th><th class="py-2 pr-3">Kategoria</th><th class="py-2 pr-3">Afati</th><th class="py-2 pr-3 text-right">Shuma</th><th class="py-2 pr-3 text-right">Mbetja</th><th class="py-2 pr-3">Statusi</th><th class="py-2"></th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="b in bills.data" :key="b.id" class="border-b border-neutral-50 last:border-0">
                                    <td class="py-2.5 pr-3">
                                        <span class="font-semibold text-primary-900">{{ b.supplier }}</span>
                                        <span class="block text-tiny text-neutral-400">{{ b.number || '#' + b.id }} · {{ b.issue_date }}</span>
                                    </td>
                                    <td class="py-2.5 pr-3"><span class="text-tiny font-bold rounded-full bg-neutral-100 text-neutral-500 px-2 py-0.5">{{ b.category }}</span></td>
                                    <td class="py-2.5 pr-3 whitespace-nowrap" :class="b.due_state === 'today' ? 'text-error-600 font-bold' : b.due_state === 'overdue' ? 'text-error-600' : 'text-neutral-500'">
                                        {{ b.due_date || '—' }}<span v-if="b.due_state === 'today'"> · SOT</span>
                                    </td>
                                    <td class="py-2.5 pr-3 text-right whitespace-nowrap font-semibold">
                                        {{ money(b.total, b.currency) }}
                                        <span v-if="b.currency !== 'EUR'" class="block text-tiny font-normal text-neutral-400">≈ {{ money(b.total_base) }}</span>
                                    </td>
                                    <td class="py-2.5 pr-3 text-right whitespace-nowrap" :class="b.remaining_base > 0 ? 'text-error-600 font-semibold' : 'text-neutral-400'">
                                        {{ money(b.remaining_base) }}
                                    </td>
                                    <td class="py-2.5 pr-3"><span class="text-tiny font-bold rounded-full px-2 py-0.5" :class="statusPill[b.status].cls">{{ statusPill[b.status].text }}</span></td>
                                    <td class="py-2.5 text-right">
                                        <Button v-if="can.payBills && b.status !== 'paid'" size="sm" variant="outline" @click="openPay(b)">Paguaj</Button>
                                    </td>
                                </tr>
                                <tr v-if="!bills.data.length"><td colspan="7" class="py-6 text-center text-neutral-400">Asnjë faturë blerjeje me këto filtra.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="bills.prev_page_url || bills.next_page_url" class="flex justify-between items-center mt-3">
                        <Button variant="ghost" size="sm" :disabled="!bills.prev_page_url" @click="router.get(bills.prev_page_url, {}, { preserveState: true })">← Para</Button>
                        <span class="text-tiny text-neutral-400">Faqja {{ bills.current_page }} / {{ bills.last_page }}</span>
                        <Button variant="ghost" size="sm" :disabled="!bills.next_page_url" @click="router.get(bills.next_page_url, {}, { preserveState: true })">Pas →</Button>
                    </div>
                </Card>

                <Card>
                    <h3 class="text-tiny font-bold uppercase tracking-wide text-neutral-400 mb-3">Shpenzimet e muajit sipas kategorive (EUR)</h3>
                    <div class="space-y-3">
                        <div v-for="(total, cat) in byCategory" :key="cat">
                            <div class="flex justify-between text-body-sm mb-1">
                                <span class="text-neutral-600">{{ cat }}</span>
                                <b class="tabular-nums text-primary-900">{{ money(total) }}</b>
                            </div>
                            <div class="h-2 rounded-full bg-neutral-100 overflow-hidden">
                                <div class="h-full rounded-full bg-accent-500/80" :style="{ width: Math.round((total / Math.max(...Object.values(byCategory))) * 100) + '%' }" />
                            </div>
                        </div>
                        <p v-if="!Object.keys(byCategory).length" class="text-body-sm text-neutral-400">Ende pa shpenzime këtë muaj.</p>
                    </div>
                </Card>
            </div>
        </div>

        <!-- new bill modal -->
        <Modal :show="showNew" @close="showNew = false">
            <div class="p-5 space-y-4">
                <h3 class="text-h4 font-bold text-primary-900">Faturë blerjeje</h3>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-body-sm font-semibold text-primary-900 mb-1">Furnitori</label>
                        <select v-model="form.supplier_id" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm">
                            <option :value="null" disabled>Zgjidh…</option>
                            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                        <p v-if="form.errors.supplier_id" class="text-tiny text-error-600 mt-1">{{ form.errors.supplier_id }}</p>
                    </div>
                    <div>
                        <label class="block text-body-sm font-semibold text-primary-900 mb-1">Nr. i faturës (ops.)</label>
                        <TextInput v-model="form.number" class="w-full" placeholder="p.sh. 2026/145" />
                    </div>
                    <div>
                        <label class="block text-body-sm font-semibold text-primary-900 mb-1">Kategoria</label>
                        <select v-model="form.category" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm">
                            <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-body-sm font-semibold text-primary-900 mb-1">Monedha</label>
                        <select v-model="form.currency" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm">
                            <option value="ALL">LEK</option><option value="EUR">EUR €</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-body-sm font-semibold text-primary-900 mb-1">Data e faturës</label>
                        <TextInput v-model="form.issue_date" type="date" class="w-full" />
                    </div>
                    <div>
                        <label class="block text-body-sm font-semibold text-primary-900 mb-1">Afati i pagesës</label>
                        <TextInput v-model="form.due_date" type="date" class="w-full" />
                    </div>
                    <div>
                        <label class="block text-body-sm font-semibold text-primary-900 mb-1">Shuma ({{ form.currency }})</label>
                        <TextInput v-model="form.total" type="number" min="0.01" step="0.01" class="w-full" placeholder="0.00" />
                        <p v-if="form.errors.total" class="text-tiny text-error-600 mt-1">{{ form.errors.total }}</p>
                    </div>
                    <div v-if="form.currency === 'ALL'">
                        <label class="block text-body-sm font-semibold text-primary-900 mb-1">Kursi (L për 1€)</label>
                        <TextInput v-model="form.fx_rate" type="number" min="1" step="0.0001" class="w-full" />
                        <p class="text-tiny text-neutral-400 mt-1">I mbushur nga kursi i ditës — ndryshoje po deshe. Ngrihet përgjithmonë në këtë faturë.</p>
                    </div>
                </div>
                <div>
                    <label class="block text-body-sm font-semibold text-primary-900 mb-1">Shënime (ops.)</label>
                    <TextInput v-model="form.notes" class="w-full" placeholder="p.sh. furnizim jave 29" />
                </div>
                <div class="flex justify-end gap-2">
                    <Button variant="ghost" @click="showNew = false">Anulo</Button>
                    <Button :disabled="form.processing || !form.supplier_id || !form.total" @click="submit">Ruaj faturën</Button>
                </div>
            </div>
        </Modal>

        <!-- pay modal -->
        <Modal :show="!!paying" @close="paying = null">
            <div v-if="paying" class="p-5 space-y-4">
                <h3 class="text-h4 font-bold text-primary-900">Paguaj — {{ paying.supplier }}</h3>
                <p class="text-body-sm text-neutral-500">
                    Fatura {{ paying.number || '#' + paying.id }} · mbetja <b class="text-error-600">{{ money(paying.remaining_base) }}</b>
                    <template v-if="paying.currency !== 'EUR'"> (kursi i ngrirë: {{ paying.fx_rate }})</template>
                </p>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-body-sm font-semibold text-primary-900 mb-1">Nga llogaria</label>
                        <select v-model="payForm.account_id" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm">
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }} ({{ money(a.balance, a.currency) }})</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-body-sm font-semibold text-primary-900 mb-1">Shuma ({{ paying.currency }})</label>
                        <TextInput v-model="payForm.amount" type="number" min="0.01" step="0.01" class="w-full" />
                        <p v-if="payForm.errors.amount" class="text-tiny text-error-600 mt-1">{{ payForm.errors.amount }}</p>
                    </div>
                </div>
                <div>
                    <label class="block text-body-sm font-semibold text-primary-900 mb-1">Metoda</label>
                    <select v-model="payForm.method" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm">
                        <option value="cash">Cash</option><option value="card">Kartë</option><option value="bank">Bankë</option>
                    </select>
                </div>
                <div class="flex justify-end gap-2">
                    <Button variant="ghost" @click="paying = null">Anulo</Button>
                    <Button :disabled="payForm.processing || !payForm.amount" @click="submitPay">Regjistro pagesën</Button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
