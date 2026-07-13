<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    Banknote,
    ChevronLeft,
    ChevronRight,
    Download,
    Landmark,
    Plus,
    Search,
    SlidersHorizontal,
    WalletCards,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { money, sourceBadge } from './financeShared.js';

const props = defineProps({
    accounts: Array,
    selectedId: Number,
    ledger: Array,
    baseCurrency: String,
    fxRate: Number,
    currencies: { type: Array, default: () => ['EUR', 'ALL'] },
    can: Object,
});

// The page lists every account (management view); money can only move
// through the active ones, so the transfer dropdowns filter on is_active.
const activeAccounts = computed(() => props.accounts.filter((a) => a.is_active));
const selectedAccount = computed(() => props.accounts.find((a) => a.id === props.selectedId));

function balanceInBase(a) {
    if (a.balance_base !== undefined && a.balance_base !== null) return Number(a.balance_base);
    if (a.currency === props.baseCurrency || a.currency === 'EUR') return Number(a.balance || 0);
    return props.fxRate ? Number(a.balance || 0) / Number(props.fxRate) : 0;
}

const totalBalance = computed(() => activeAccounts.value.reduce((sum, a) => sum + balanceInBase(a), 0));
const cashBalance = computed(() => activeAccounts.value.filter((a) => a.type === 'cash').reduce((sum, a) => sum + balanceInBase(a), 0));
const bankBalance = computed(() => activeAccounts.value.filter((a) => a.type === 'bank').reduce((sum, a) => sum + balanceInBase(a), 0));

const search = ref('');
const sourceFilter = ref('all');
const dateFrom = ref('');
const dateTo = ref('');
const pageSize = ref(20);
const currentPage = ref(1);

const filteredLedger = computed(() => {
    const query = search.value.trim().toLocaleLowerCase('sq');

    return props.ledger.filter((p) => {
        const description = (p.description || '').toLocaleLowerCase('sq');
        const badge = sourceBadge(p).text.toLocaleLowerCase('sq');
        const rowDate = (p.paid_at || '').slice(0, 10);
        const matchesSearch = !query || description.includes(query) || badge.includes(query);
        const matchesFrom = !dateFrom.value || rowDate >= dateFrom.value;
        const matchesTo = !dateTo.value || rowDate <= dateTo.value;
        const matchesSource = sourceFilter.value === 'all'
            || (sourceFilter.value === 'folio' && description.includes('folio'))
            || (sourceFilter.value === 'pos' && description.includes('pos'))
            || (sourceFilter.value === 'manual' && p.source !== 'auto')
            || (sourceFilter.value === 'transfer' && p.direction === 'transfer');

        return matchesSearch && matchesFrom && matchesTo && matchesSource;
    });
});

const totalPages = computed(() => Math.max(1, Math.ceil(filteredLedger.value.length / pageSize.value)));
const paginatedLedger = computed(() => {
    const start = (currentPage.value - 1) * pageSize.value;
    return filteredLedger.value.slice(start, start + Number(pageSize.value));
});
const resultStart = computed(() => filteredLedger.value.length ? (currentPage.value - 1) * pageSize.value + 1 : 0);
const resultEnd = computed(() => Math.min(currentPage.value * pageSize.value, filteredLedger.value.length));
const visiblePages = computed(() => {
    if (totalPages.value <= 7) return Array.from({ length: totalPages.value }, (_, i) => i + 1);
    const pages = new Set([1, totalPages.value, currentPage.value - 1, currentPage.value, currentPage.value + 1]);
    const sorted = [...pages].filter((page) => page > 0 && page <= totalPages.value).sort((a, b) => a - b);
    const result = [];
    sorted.forEach((page, index) => {
        if (index && page - sorted[index - 1] > 1) result.push(`gap-${page}`);
        result.push(page);
    });
    return result;
});
const hasFilters = computed(() => search.value || sourceFilter.value !== 'all' || dateFrom.value || dateTo.value);

watch([search, sourceFilter, dateFrom, dateTo, pageSize, () => props.selectedId], () => {
    currentPage.value = 1;
});

function clearFilters() {
    search.value = '';
    sourceFilter.value = 'all';
    dateFrom.value = '';
    dateTo.value = '';
}

function csvCell(value) {
    return `"${String(value ?? '').replaceAll('"', '""')}"`;
}

function exportLedger() {
    const header = ['Data', 'Përshkrimi', 'Burimi', 'Hyrje', 'Dalje', 'Bilanci'];
    const rows = filteredLedger.value.map((p) => [
        p.paid_at,
        p.description,
        sourceBadge(p).text,
        p.delta >= 0 ? Number(p.delta).toFixed(2) : '',
        p.delta < 0 ? Math.abs(Number(p.delta)).toFixed(2) : '',
        Number(p.balance).toFixed(2),
    ]);
    const csv = `\uFEFF${[header, ...rows].map((row) => row.map(csvCell).join(';')).join('\n')}`;
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `libri-${(selectedAccount.value?.name || 'llogarise').toLocaleLowerCase('sq').replaceAll(' ', '-')}.csv`;
    link.click();
    URL.revokeObjectURL(url);
}

function pick(a) {
    router.get(route('finance.accounts'), { account_id: a.id }, { preserveScroll: true, preserveState: true });
}

const showTransfer = ref(false);
const transfer = useForm({ from_account_id: props.selectedId, to_account_id: null, amount: null, description: '' });
function submitTransfer() {
    transfer.post(route('finance.transfers.store'), {
        preserveScroll: true,
        onSuccess: () => { showTransfer.value = false; transfer.reset(); },
    });
}

const showNewAccount = ref(false);
const account = useForm({ name: '', type: 'cash', currency: 'EUR', iban: '' });
function submitAccount() {
    account.post(route('finance.accounts.store'), {
        preserveScroll: true,
        onSuccess: () => { showNewAccount.value = false; account.reset(); },
    });
}

function toggleAccount(a) {
    if (a.is_active && !confirm(`Të çaktivizohet llogaria "${a.name}"? Historiku i saj ruhet dhe mund ta riaktivizosh kurdo.`)) return;
    router.put(route('finance.accounts.toggle', a.id), {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <PageHeader title="Arka & Banka" :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Financa' }, { label: 'Arka & Banka' }]">
            <template #actions>
                <Button v-if="can.transfers && activeAccounts.length > 1" variant="outline" @click="showTransfer = true">
                    <ArrowLeftRight class="h-4 w-4" />
                    Transfertë
                </Button>
                <Button v-if="can.manageAccounts" @click="showNewAccount = true">
                    <Plus class="h-4 w-4" />
                    Llogari e re
                </Button>
            </template>
        </PageHeader>

        <p class="mt-1 text-body-sm text-neutral-500">Shiko gjendjet dhe lëvizjet e arkës dhe bankës në kohë reale.</p>

        <div class="mt-5 pb-10 space-y-5">
            <!-- summary -->
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="flex items-center gap-3 rounded-xl border border-neutral-200 bg-white p-4 shadow-card">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-accent-50 text-accent-700"><WalletCards class="h-5 w-5" /></span>
                    <div class="min-w-0">
                        <p class="text-body-sm font-medium text-neutral-500">Gjendja totale</p>
                        <p class="mt-0.5 truncate text-h3 font-extrabold tabular-nums text-primary-900">{{ money(totalBalance) }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-neutral-200 bg-white p-4 shadow-card">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-accent-50 text-accent-700"><Banknote class="h-5 w-5" /></span>
                    <div class="min-w-0">
                        <p class="text-body-sm font-medium text-neutral-500">Arka</p>
                        <p class="mt-0.5 truncate text-h3 font-extrabold tabular-nums text-primary-900">{{ money(cashBalance) }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-neutral-200 bg-white p-4 shadow-card">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-accent-50 text-accent-700"><Landmark class="h-5 w-5" /></span>
                    <div class="min-w-0">
                        <p class="text-body-sm font-medium text-neutral-500">Banka</p>
                        <p class="mt-0.5 truncate text-h3 font-extrabold tabular-nums text-primary-900">{{ money(bankBalance) }}</p>
                    </div>
                </div>
            </div>

            <!-- account selector -->
            <div class="grid overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-card" :class="accounts.length > 1 ? 'sm:grid-cols-2' : 'grid-cols-1'">
                <div
                    v-for="a in accounts"
                    :key="a.id"
                    class="relative flex min-w-0 items-stretch border-neutral-200 [&:not(:last-child)]:border-b sm:[&:not(:last-child)]:border-b-0 sm:[&:not(:last-child)]:border-r"
                    :class="!a.is_active && 'opacity-60'"
                >
                    <button
                        type="button"
                        class="flex min-w-0 flex-1 items-center justify-center gap-2.5 border-t-2 px-4 py-3 text-left transition-colors"
                        :class="a.id === selectedId ? 'border-accent-600 bg-accent-50/50 text-accent-700' : 'border-transparent text-neutral-600 hover:bg-neutral-50 hover:text-primary-900'"
                        @click="pick(a)"
                    >
                        <Banknote v-if="a.type === 'cash'" class="h-4 w-4 shrink-0" />
                        <Landmark v-else class="h-4 w-4 shrink-0" />
                        <span class="truncate text-body-sm font-semibold">{{ a.name }} <span class="font-normal text-neutral-400">({{ a.currency }})</span></span>
                        <span v-if="!a.is_active" class="rounded-full bg-neutral-200 px-2 py-0.5 text-[10px] font-bold text-neutral-600">JOAKTIVE</span>
                    </button>
                    <button
                        v-if="can.manageAccounts"
                        type="button"
                        class="shrink-0 px-3 text-tiny font-semibold transition-colors"
                        :class="a.is_active ? 'text-neutral-400 hover:bg-error-50 hover:text-error-600' : 'text-accent-700 hover:bg-accent-50'"
                        :title="a.is_active ? 'Çaktivizo llogarinë' : 'Riaktivizo llogarinë'"
                        @click="toggleAccount(a)"
                    >{{ a.is_active ? 'Çaktivizo' : 'Riaktivizo' }}</button>
                </div>
            </div>

            <!-- ledger -->
            <section class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-card">
                <div class="flex flex-col gap-3 border-b border-neutral-200 p-4 xl:flex-row xl:items-center xl:justify-between">
                    <div>
                        <h2 class="text-body font-bold text-primary-900">Libri i llogarisë — {{ selectedAccount?.name }}</h2>
                        <p class="mt-0.5 text-tiny text-neutral-400">{{ filteredLedger.length }} lëvizje të gjetura</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="relative min-w-[220px] flex-1 xl:w-64 xl:flex-none">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                            <input v-model="search" type="search" class="w-full rounded-lg border-neutral-200 py-2 pl-9 pr-3 text-body-sm placeholder:text-neutral-400 focus:border-accent-500 focus:ring-accent-500" placeholder="Kërko transaksion…" />
                        </label>
                        <label class="relative">
                            <SlidersHorizontal class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                            <select v-model="sourceFilter" class="rounded-lg border-neutral-200 py-2 pl-9 pr-8 text-body-sm text-neutral-700 focus:border-accent-500 focus:ring-accent-500">
                                <option value="all">Të gjitha burimet</option>
                                <option value="folio">Folio</option>
                                <option value="pos">POS</option>
                                <option value="manual">Manuale</option>
                                <option value="transfer">Transferta</option>
                            </select>
                        </label>
                        <Button variant="outline" size="sm" :disabled="!filteredLedger.length" @click="exportLedger">
                            <Download class="h-4 w-4" />
                            Eksporto
                        </Button>
                    </div>
                </div>

                <div class="flex flex-wrap items-end gap-3 border-b border-neutral-100 bg-neutral-50/60 px-4 py-3">
                    <label class="text-tiny font-semibold text-neutral-500">
                        <span class="mb-1 block">Nga data</span>
                        <input v-model="dateFrom" type="date" class="rounded-lg border-neutral-200 py-1.5 text-body-sm text-neutral-700 focus:border-accent-500 focus:ring-accent-500" />
                    </label>
                    <label class="text-tiny font-semibold text-neutral-500">
                        <span class="mb-1 block">Deri më</span>
                        <input v-model="dateTo" type="date" class="rounded-lg border-neutral-200 py-1.5 text-body-sm text-neutral-700 focus:border-accent-500 focus:ring-accent-500" />
                    </label>
                    <button v-if="hasFilters" type="button" class="mb-1 text-tiny font-semibold text-accent-700 hover:text-accent-800" @click="clearFilters">Pastro filtrat</button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-body-sm tabular-nums">
                        <thead><tr class="border-b border-neutral-100 bg-white text-left text-tiny uppercase tracking-wide text-neutral-400">
                            <th class="px-4 py-3">Data</th><th class="px-4 py-3">Përshkrimi</th><th class="px-4 py-3">Burimi</th><th class="px-4 py-3 text-right">Hyrje</th><th class="px-4 py-3 text-right">Dalje</th><th class="px-4 py-3 text-right">Bilanci</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="p in paginatedLedger" :key="p.id" class="border-b border-neutral-100 last:border-0 hover:bg-neutral-50/60">
                                <td class="whitespace-nowrap px-4 py-3 text-neutral-500">{{ p.paid_at.slice(0, 16) }}</td>
                                <td class="px-4 py-3 font-medium text-primary-900">{{ p.description }}</td>
                                <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-tiny font-bold" :class="sourceBadge(p).cls">{{ sourceBadge(p).text }}</span></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold text-accent-600">{{ p.delta >= 0 ? `+ ${money(p.delta, p.currency)}` : '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold text-error-600">{{ p.delta < 0 ? `− ${money(Math.abs(p.delta), p.currency)}` : '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-neutral-600">{{ money(p.balance, p.currency) }}</td>
                            </tr>
                            <tr v-if="!paginatedLedger.length"><td colspan="6" class="px-4 py-12 text-center text-neutral-400">{{ hasFilters ? 'Nuk u gjet asnjë lëvizje me këto filtra.' : "Kjo llogari s'ka ende lëvizje." }}</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-col gap-3 border-t border-neutral-200 bg-neutral-50/60 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <label class="flex items-center gap-2 text-body-sm text-neutral-500">
                        <select v-model.number="pageSize" class="rounded-lg border-neutral-200 py-1.5 pr-8 text-body-sm text-neutral-700 focus:border-accent-500 focus:ring-accent-500">
                            <option :value="10">10</option><option :value="20">20</option><option :value="50">50</option>
                        </select>
                        për faqe
                    </label>
                    <nav class="flex items-center justify-center gap-1" aria-label="Faqet e librit të llogarisë">
                        <button type="button" class="grid h-8 w-8 place-items-center rounded-md border border-neutral-200 bg-white text-neutral-600 hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-40" :disabled="currentPage === 1" @click="currentPage--"><ChevronLeft class="h-4 w-4" /></button>
                        <template v-for="page in visiblePages" :key="page">
                            <span v-if="typeof page === 'string'" class="grid h-8 w-8 place-items-center text-neutral-400">…</span>
                            <button v-else type="button" class="h-8 min-w-8 rounded-md px-2 text-body-sm font-semibold" :class="page === currentPage ? 'bg-accent-600 text-white' : 'text-neutral-600 hover:bg-neutral-100'" @click="currentPage = page">{{ page }}</button>
                        </template>
                        <button type="button" class="grid h-8 w-8 place-items-center rounded-md border border-neutral-200 bg-white text-neutral-600 hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-40" :disabled="currentPage === totalPages" @click="currentPage++"><ChevronRight class="h-4 w-4" /></button>
                    </nav>
                    <p class="text-body-sm tabular-nums text-neutral-500">{{ resultStart }}–{{ resultEnd }} nga {{ filteredLedger.length }} rezultate</p>
                </div>
                <p class="border-t border-neutral-100 px-4 py-3 text-tiny text-neutral-400">💡 Turnet e POS-it dhe pagesat e folios derdhen vetë këtu — manualisht futen vetëm daljet e vogla dhe transfertat.</p>
            </section>
        </div>

        <!-- transfer modal -->
        <Modal :show="showTransfer" @close="showTransfer = false">
            <div class="p-5 space-y-4">
                <h3 class="text-h4 font-bold text-primary-900">Transfertë mes llogarive</h3>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-body-sm font-semibold text-primary-900 mb-1">Nga</label>
                        <select v-model="transfer.from_account_id" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm">
                            <option v-for="a in activeAccounts" :key="a.id" :value="a.id">{{ a.name }} ({{ money(a.balance, a.currency) }})</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-body-sm font-semibold text-primary-900 mb-1">Te</label>
                        <select v-model="transfer.to_account_id" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm">
                            <option v-for="a in activeAccounts.filter((x) => x.id !== transfer.from_account_id)" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-body-sm font-semibold text-primary-900 mb-1">Shuma</label>
                    <TextInput v-model="transfer.amount" type="number" min="0.01" step="0.01" class="w-full" placeholder="0.00" />
                    <p v-if="transfer.errors.amount" class="text-tiny text-error-600 mt-1">{{ transfer.errors.amount }}</p>
                </div>
                <div>
                    <label class="block text-body-sm font-semibold text-primary-900 mb-1">Përshkrimi (ops.)</label>
                    <TextInput v-model="transfer.description" class="w-full" placeholder="p.sh. depozitim i arkës" />
                </div>
                <div class="flex justify-end gap-2">
                    <Button variant="ghost" @click="showTransfer = false">Anulo</Button>
                    <Button :disabled="transfer.processing || !transfer.to_account_id || !transfer.amount" @click="submitTransfer">Kryej transfertën</Button>
                </div>
            </div>
        </Modal>

        <!-- new account modal -->
        <Modal :show="showNewAccount" @close="showNewAccount = false">
            <div class="p-5 space-y-4">
                <h3 class="text-h4 font-bold text-primary-900">Llogari e re</h3>
                <div>
                    <label class="block text-body-sm font-semibold text-primary-900 mb-1">Emri</label>
                    <TextInput v-model="account.name" class="w-full" placeholder='p.sh. "Arka e Restorantit" ose "BKT"' maxlength="60" />
                    <p v-if="account.errors.name" class="text-tiny text-error-600 mt-1">{{ account.errors.name }}</p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-body-sm font-semibold text-primary-900 mb-1">Lloji</label>
                        <select v-model="account.type" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm">
                            <option value="cash">💵 Arkë (kesh)</option>
                            <option value="bank">🏦 Bankë</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-body-sm font-semibold text-primary-900 mb-1">Monedha</label>
                        <select v-model="account.currency" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm">
                            <option v-for="c in currencies" :key="c" :value="c">{{ c === 'ALL' ? 'ALL (Lek)' : c }}</option>
                        </select>
                    </div>
                </div>
                <div v-if="account.type === 'bank'">
                    <label class="block text-body-sm font-semibold text-primary-900 mb-1">IBAN (ops.)</label>
                    <TextInput v-model="account.iban" class="w-full" placeholder="AL__ ____ ____ ____" maxlength="40" />
                    <p v-if="account.errors.iban" class="text-tiny text-error-600 mt-1">{{ account.errors.iban }}</p>
                </div>
                <p class="text-tiny text-neutral-400">Llogaria e re nis me bilanc 0 — lëvizjet i regjistron te Pagesat ose me Transfertë.</p>
                <div class="flex justify-end gap-2">
                    <Button variant="ghost" @click="showNewAccount = false">Anulo</Button>
                    <Button :disabled="account.processing || !account.name" @click="submitAccount">Krijo llogarinë</Button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
