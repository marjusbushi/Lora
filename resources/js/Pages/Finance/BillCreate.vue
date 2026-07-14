<script setup>
import { computed, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle2,
    CircleDollarSign,
    PackagePlus,
    Plus,
    ReceiptText,
    Trash2,
    Warehouse,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { money } from './financeShared.js';

const props = defineProps({
    suppliers: Array,
    categories: Array,
    inventoryItems: Array,
    warehouses: Array,
    fxRate: Number,
    can: Object,
});

function localDateString(date = new Date()) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

const defaultWarehouseId = computed(() => props.warehouses.find((warehouse) => warehouse.is_default)?.id || props.warehouses[0]?.id || null);

function emptyLine() {
    return {
        inventory_item_id: null,
        warehouse_id: defaultWarehouseId.value,
        quantity: 1,
        unit_cost: null,
    };
}

const form = useForm({
    supplier_id: null,
    number: '',
    category: props.categories[0] || 'Të tjera',
    issue_date: localDateString(),
    due_date: null,
    currency: 'ALL',
    fx_rate: props.fxRate,
    total: 0,
    notes: '',
    receive_stock: true,
    items: props.inventoryItems.length ? [emptyLine()] : [],
});

const selectedSupplier = computed(() => props.suppliers.find((supplier) => supplier.id === Number(form.supplier_id)));
const lineTotal = (line) => Number(line.quantity || 0) * Number(line.unit_cost || 0);
const invoiceTotal = computed(() => form.items.reduce((total, line) => total + lineTotal(line), 0));
const totalBase = computed(() => {
    if (form.currency === 'EUR') return invoiceTotal.value;
    const rate = Number(form.fx_rate || 0);
    return rate > 0 ? invoiceTotal.value / rate : 0;
});
const stockableLines = computed(() => form.items.filter((line) => selectedItem(line)?.type !== 'service' && line.inventory_item_id).length);
const errorMessages = computed(() => [...new Set(Object.values(form.errors))]);

const canSubmit = computed(() => {
    if (!form.supplier_id || !form.issue_date || invoiceTotal.value <= 0 || !form.items.length) return false;
    if (form.currency === 'ALL' && Number(form.fx_rate) < 1) return false;

    return form.items.every((line) => {
        const item = selectedItem(line);
        return item
            && Number(line.quantity) > 0
            && Number(line.unit_cost) >= 0
            && (item.type === 'service' || Boolean(line.warehouse_id));
    });
});

watch(invoiceTotal, (total) => {
    form.total = Number(total.toFixed(2));
}, { immediate: true });

watch(() => form.supplier_id, () => {
    const supplier = selectedSupplier.value;
    if (!supplier) return;
    if (supplier.category && props.categories.includes(supplier.category)) form.category = supplier.category;
    applyPaymentTerms();
});

watch(() => form.issue_date, applyPaymentTerms);

function applyPaymentTerms() {
    const supplier = selectedSupplier.value;
    if (!supplier || !form.issue_date) return;

    const date = new Date(`${form.issue_date}T12:00:00`);
    date.setDate(date.getDate() + Number(supplier.payment_terms_days || 0));
    form.due_date = localDateString(date);
}

function selectedItem(line) {
    return props.inventoryItems.find((item) => item.id === Number(line.inventory_item_id));
}

function applyItemDefaults(line) {
    const item = selectedItem(line);
    if (!item) return;
    if (line.unit_cost === null || line.unit_cost === '') line.unit_cost = Number(item.average_cost || 0);
    line.warehouse_id = item.type === 'service' ? null : (line.warehouse_id || defaultWarehouseId.value);
}

function addLine() {
    if (form.items.length < 50) form.items.push(emptyLine());
}

function removeLine(index) {
    form.items.splice(index, 1);
}

function submit() {
    form.post(route('finance.bills.store'), { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <div class="mx-auto max-w-[1500px] space-y-5 pb-8">
            <div>
                <PageHeader title="Faturë e re blerjeje" :breadcrumbs="[{ label: 'Financa' }, { label: 'Faturat', href: route('finance.bills') }, { label: 'Faturë e re' }]">
                    <template #actions>
                        <Link :href="route('finance.bills')" class="inline-flex items-center gap-2 rounded-md border border-neutral-200 bg-white px-4 py-2 text-body-sm font-medium text-neutral-700 no-underline hover:bg-neutral-50">
                            <ArrowLeft class="h-4 w-4" /> Anulo
                        </Link>
                        <Button :loading="form.processing" :disabled="!canSubmit" @click="submit">
                            <CheckCircle2 class="h-4 w-4" /> Ruaj faturën
                        </Button>
                    </template>
                </PageHeader>
                <p class="mt-1 text-body-sm text-neutral-500">Regjistro dokumentin, rreshtat e blerjes dhe hyrjen në magazinë në një faqe të vetme.</p>
            </div>

            <div v-if="errorMessages.length" class="rounded-lg border border-error-200 bg-error-50 px-4 py-3 text-body-sm text-error-700">
                <strong class="block">Kontrollo të dhënat e faturës:</strong>
                <ul class="mt-1 list-disc pl-5">
                    <li v-for="message in errorMessages" :key="message">{{ message }}</li>
                </ul>
            </div>

            <div v-if="!suppliers.length || !inventoryItems.length" class="rounded-lg border border-warning-200 bg-warning-50 px-4 py-3 text-body-sm text-warning-800">
                <template v-if="!suppliers.length">Shto të paktën një furnitor aktiv përpara faturës. </template>
                <template v-if="!inventoryItems.length">Shto të paktën një artikull ose shërbim aktiv përpara faturës.</template>
            </div>

            <Card>
                <template #header>
                    <div class="flex items-center gap-3">
                        <span class="grid h-9 w-9 place-items-center rounded-lg bg-accent-50 text-accent-700"><ReceiptText class="h-5 w-5" /></span>
                        <div><h2 class="text-label font-bold text-primary-900">Të dhënat e faturës</h2><p class="mt-0.5 text-tiny text-neutral-400">Furnitori, dokumenti dhe afati i pagesës.</p></div>
                    </div>
                </template>

                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div class="xl:col-span-2">
                        <label class="mb-1 block text-body-sm font-semibold text-primary-900">Furnitori</label>
                        <select v-model="form.supplier_id" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500">
                            <option :value="null" disabled>Zgjidh furnitorin…</option>
                            <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}<template v-if="supplier.nipt"> · {{ supplier.nipt }}</template></option>
                        </select>
                        <p v-if="form.errors.supplier_id" class="mt-1 text-tiny text-error-600">{{ form.errors.supplier_id }}</p>
                    </div>
                    <div>
                        <label class="mb-1 flex items-center justify-between text-body-sm font-semibold text-primary-900"><span>Numri i faturës</span><small class="font-normal text-neutral-400">Opsional</small></label>
                        <TextInput v-model="form.number" class="w-full" placeholder="p.sh. 2026/145" />
                        <p v-if="form.errors.number" class="mt-1 text-tiny text-error-600">{{ form.errors.number }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-body-sm font-semibold text-primary-900">Kategoria</label>
                        <select v-model="form.category" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500">
                            <option v-for="category in categories" :key="category" :value="category">{{ category }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-body-sm font-semibold text-primary-900">Data e faturës</label>
                        <TextInput v-model="form.issue_date" type="date" class="w-full" />
                        <p v-if="form.errors.issue_date" class="mt-1 text-tiny text-error-600">{{ form.errors.issue_date }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-body-sm font-semibold text-primary-900">Afati i pagesës</label>
                        <TextInput v-model="form.due_date" type="date" class="w-full" />
                        <p v-if="form.errors.due_date" class="mt-1 text-tiny text-error-600">{{ form.errors.due_date }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-body-sm font-semibold text-primary-900">Monedha</label>
                        <select v-model="form.currency" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500">
                            <option value="ALL">ALL · Lek</option>
                            <option value="EUR">EUR · Euro</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 flex items-center justify-between text-body-sm font-semibold text-primary-900"><span>Kursi</span><small class="font-normal text-neutral-400">L për 1 €</small></label>
                        <TextInput v-model="form.fx_rate" type="number" min="1" step="0.0001" class="w-full" :disabled="form.currency === 'EUR'" />
                        <p v-if="form.errors.fx_rate" class="mt-1 text-tiny text-error-600">{{ form.errors.fx_rate }}</p>
                    </div>
                </div>
            </Card>

            <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr),320px]">
                <div class="space-y-5">
                    <Card :padding="false">
                        <template #header>
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex items-center gap-3">
                                    <span class="grid h-9 w-9 place-items-center rounded-lg bg-info-50 text-info-700"><PackagePlus class="h-5 w-5" /></span>
                                    <div><h2 class="text-label font-bold text-primary-900">Artikujt dhe shërbimet</h2><p class="mt-0.5 text-tiny text-neutral-400">Çdo artikull regjistrohet si rresht i faturës.</p></div>
                                </div>
                                <Button variant="outline" size="sm" :disabled="!inventoryItems.length || form.items.length >= 50" @click="addLine"><Plus class="h-4 w-4" /> Shto rresht</Button>
                            </div>
                        </template>

                        <div v-if="form.items.length" class="overflow-x-auto">
                            <table class="w-full min-w-[980px] border-collapse">
                                <thead class="bg-neutral-50 text-left text-tiny font-bold uppercase tracking-wide text-neutral-400">
                                    <tr>
                                        <th class="w-12 px-4 py-3 text-center">#</th>
                                        <th class="min-w-[260px] px-3 py-3">Artikulli / shërbimi</th>
                                        <th class="w-28 px-3 py-3">Sasia</th>
                                        <th class="w-36 px-3 py-3">Kosto / njësi</th>
                                        <th class="min-w-[190px] px-3 py-3">Magazina</th>
                                        <th class="w-36 px-3 py-3 text-right">Totali</th>
                                        <th class="w-14 px-3 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-neutral-100">
                                    <tr v-for="(line, index) in form.items" :key="index" class="align-top">
                                        <td class="px-4 py-3 text-center text-body-sm font-semibold text-neutral-400">{{ index + 1 }}</td>
                                        <td class="px-3 py-3">
                                            <select v-model="line.inventory_item_id" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500" @change="applyItemDefaults(line)">
                                                <option :value="null" disabled>Zgjidh artikullin…</option>
                                                <option v-for="item in inventoryItems" :key="item.id" :value="item.id">{{ item.name }} · {{ item.sku }}</option>
                                            </select>
                                            <p v-if="selectedItem(line)" class="mt-1 text-tiny text-neutral-400">{{ selectedItem(line).type === 'service' ? 'Shërbim' : 'Artikull stoku' }} · {{ selectedItem(line).unit }}</p>
                                            <p v-if="form.errors[`items.${index}.inventory_item_id`]" class="mt-1 text-tiny text-error-600">{{ form.errors[`items.${index}.inventory_item_id`] }}</p>
                                        </td>
                                        <td class="px-3 py-3">
                                            <TextInput v-model="line.quantity" type="number" min="0.0001" step="0.0001" class="w-full" />
                                            <p v-if="form.errors[`items.${index}.quantity`]" class="mt-1 text-tiny text-error-600">{{ form.errors[`items.${index}.quantity`] }}</p>
                                        </td>
                                        <td class="px-3 py-3">
                                            <TextInput v-model="line.unit_cost" type="number" min="0" step="0.01" class="w-full" />
                                            <p v-if="form.errors[`items.${index}.unit_cost`]" class="mt-1 text-tiny text-error-600">{{ form.errors[`items.${index}.unit_cost`] }}</p>
                                        </td>
                                        <td class="px-3 py-3">
                                            <select v-if="selectedItem(line)?.type !== 'service'" v-model="line.warehouse_id" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm focus:border-accent-500 focus:ring-accent-500">
                                                <option :value="null" disabled>Zgjidh magazinën…</option>
                                                <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                                            </select>
                                            <span v-else class="inline-flex rounded-full bg-neutral-100 px-2.5 py-1.5 text-tiny font-semibold text-neutral-500">Nuk prek stokun</span>
                                            <p v-if="form.errors[`items.${index}.warehouse_id`]" class="mt-1 text-tiny text-error-600">{{ form.errors[`items.${index}.warehouse_id`] }}</p>
                                        </td>
                                        <td class="px-3 py-3 text-right text-body-sm font-bold tabular-nums text-primary-900">{{ money(lineTotal(line), form.currency) }}</td>
                                        <td class="px-3 py-3 text-right">
                                            <button type="button" class="rounded-md p-2 text-neutral-400 hover:bg-error-50 hover:text-error-600" title="Hiq rreshtin" @click="removeLine(index)"><Trash2 class="h-4 w-4" /></button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="px-5 py-12 text-center">
                            <span class="mx-auto grid h-11 w-11 place-items-center rounded-full bg-neutral-100 text-neutral-500"><PackagePlus class="h-5 w-5" /></span>
                            <strong class="mt-3 block text-body-sm text-primary-900">Fatura nuk ka ende rreshta</strong>
                            <p class="mt-1 text-tiny text-neutral-400">Shto një artikull ose shërbim nga katalogu.</p>
                            <Button class="mt-4" variant="outline" size="sm" :disabled="!inventoryItems.length" @click="addLine"><Plus class="h-4 w-4" /> Shto rreshtin e parë</Button>
                        </div>

                        <template #footer>
                            <label class="flex cursor-pointer items-start gap-3">
                                <input v-model="form.receive_stock" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-accent-600 focus:ring-accent-500" />
                                <span><strong class="block text-body-sm text-primary-900">Prano stokun menjëherë</strong><small class="mt-0.5 block text-tiny text-neutral-400">{{ stockableLines }} rreshta fizikë do të hyjnë në magazinat e zgjedhura kur ruhet fatura.</small></span>
                            </label>
                        </template>
                    </Card>

                    <Card>
                        <label class="mb-1 flex items-center justify-between text-body-sm font-semibold text-primary-900"><span>Shënime</span><small class="font-normal text-neutral-400">Opsionale</small></label>
                        <textarea v-model="form.notes" rows="4" maxlength="500" class="w-full rounded-lg border-neutral-200 px-3 py-2 text-body-sm placeholder:text-neutral-400 focus:border-accent-500 focus:ring-accent-500" placeholder="p.sh. furnizim për javën, referencë porosie…" />
                        <div class="mt-1 flex justify-between text-tiny text-neutral-400"><span>{{ form.errors.notes }}</span><span>{{ form.notes.length }}/500</span></div>
                    </Card>
                </div>

                <aside class="space-y-4 xl:sticky xl:top-5">
                    <Card :padding="false">
                        <template #header><h2 class="text-label font-bold text-primary-900">Përmbledhja</h2></template>
                        <div class="divide-y divide-neutral-100 px-5">
                            <div class="flex items-center justify-between gap-3 py-3 text-body-sm"><span class="text-neutral-500">Furnitori</span><b class="max-w-[170px] truncate text-right text-primary-900">{{ selectedSupplier?.name || '—' }}</b></div>
                            <div class="flex items-center justify-between gap-3 py-3 text-body-sm"><span class="text-neutral-500">Nr. faturës</span><b class="text-primary-900">{{ form.number || 'Pa numër' }}</b></div>
                            <div class="flex items-center justify-between gap-3 py-3 text-body-sm"><span class="text-neutral-500">Rreshta</span><b class="text-primary-900">{{ form.items.length }}</b></div>
                            <div class="flex items-center justify-between gap-3 py-3 text-body-sm"><span class="text-neutral-500">Kursi i ngrirë</span><b class="text-primary-900">{{ form.currency === 'ALL' ? (form.fx_rate || '—') : '1.00' }}</b></div>
                        </div>
                        <div class="border-t border-neutral-200 bg-accent-50 px-5 py-4">
                            <div class="flex items-center justify-between gap-3 text-body-sm text-accent-800"><span>Totali i faturës</span><strong class="text-h3 tabular-nums">{{ money(invoiceTotal, form.currency) }}</strong></div>
                            <div class="mt-2 flex items-center justify-between gap-3 text-tiny text-accent-700"><span>Detyrimi në EUR</span><b class="tabular-nums">{{ money(totalBase) }}</b></div>
                        </div>
                    </Card>

                    <div class="rounded-lg border border-info-200 bg-info-50 p-4 text-tiny leading-relaxed text-info-800">
                        <div class="flex items-start gap-3"><CircleDollarSign class="mt-0.5 h-4 w-4 shrink-0" /><p>Fatura krijon detyrim ndaj furnitorit. Arka ose banka ndryshon vetëm kur regjistrohet pagesa.</p></div>
                    </div>
                    <div class="rounded-lg border border-accent-200 bg-accent-50 p-4 text-tiny leading-relaxed text-accent-800">
                        <div class="flex items-start gap-3"><Warehouse class="mt-0.5 h-4 w-4 shrink-0" /><p>Artikujt fizikë hyjnë në magazinën e çdo rreshti; shërbimet nuk ndryshojnë inventarin.</p></div>
                    </div>

                    <Button class="w-full" size="lg" :loading="form.processing" :disabled="!canSubmit" @click="submit"><CheckCircle2 class="h-5 w-5" /> Ruaj faturën</Button>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
