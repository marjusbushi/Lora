<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { ArrowLeft, CalendarDays, Download, FileBarChart, Printer, RotateCcw } from 'lucide-vue-next';

const props = defineProps({
    title: { type: String, required: true },
    routeName: { type: String, default: null },
    filters: { type: Object, default: null },
    description: { type: String, default: '' },
    category: { type: String, default: '' },
});

const reportMeta = {
    'Pasqyra Ekzekutive': { category: 'Të ardhura & performanca', description: 'Pamja e plotë e performancës financiare dhe operative për periudhën.' },
    'ADR / RevPAR / Mbushja': { category: 'Të ardhura & performanca', description: 'Krahaso mbushjen, çmimin mesatar dhe të ardhurat për dhomë.' },
    'Tempo & Pickup': { category: 'Të ardhura & performanca', description: 'Shiko ritmin me të cilin po hyjnë rezervimet e ardhshme.' },
    'Prodhimi sipas Kanaleve': { category: 'Rezervime & kanale', description: 'Krahaso prodhimin, komisionin dhe vlerën neto të çdo kanali.' },
    'Anulime & No-Show': { category: 'Rezervime & kanale', description: 'Monitoro anulimet dhe rezervimet që kërkojnë verifikim.' },
    'Sjellja e Rezervimit': { category: 'Rezervime & kanale', description: 'Analizo sa herët rezervojnë dhe sa gjatë qëndrojnë mysafirët.' },
    'Manifesti i Mbërritjeve': { category: 'Operacione', description: 'Fleta operative e mysafirëve që priten të mbërrijnë.' },
    'Manifesti i Nisjeve': { category: 'Operacione', description: 'Kontrollo nisjet, balancat dhe detyrat që duhen mbyllur.' },
    'Statusi i Dhomave': { category: 'Operacione', description: 'Pamja aktuale e disponueshmërisë dhe gjendjes së çdo dhome.' },
    'Raporti i Pastrimit': { category: 'Operacione', description: 'Ngarkesa, progresi dhe produktiviteti i ekipit të pastrimit.' },
    'Mysafirë në Shtëpi': { category: 'Operacione', description: 'Lista e plotë e mysafirëve që ndodhen aktualisht në hotel.' },
    'Bilance të Papaguara': { category: 'Financë & arka', description: 'Qëndrimet me detyrime të hapura që kërkojnë ndjekje.' },
    'Z-Report / Mbyllje Turni': { category: 'Financë & arka', description: 'Pajtimi i arkës dhe pagesave për çdo turn të mbyllur.' },
    'Arkëtime & Cash': { category: 'Financë & arka', description: 'Paratë e arkëtuara sipas metodës, ditës dhe stafit.' },
    'Raport TVSH': { category: 'Financë & arka', description: 'Përmbledhja e TVSH-së së përfshirë në shitje për periudhën.' },
    'Zbritje të Dhëna': { category: 'Financë & arka', description: 'Monitoro çdo zbritje dhe vlerën e të ardhurave të lëshuara.' },
    'Direktoria e Mysafirëve': { category: 'Mysafirë', description: 'Historiku dhe vlera e çdo mysafiri gjatë gjithë marrëdhënies.' },
    'Mysafirë Kthyes & Top': { category: 'Mysafirë', description: 'Identifiko mysafirët besnikë dhe ata me vlerën më të lartë.' },
    'Përbërja sipas Kombësisë': { category: 'Mysafirë', description: 'Analizo netët dhe të ardhurat sipas vendit të origjinës.' },
    'Shitjet POS (Kategori & Artikull)': { category: 'Bar & restorant', description: 'Krahaso shitjet sipas kategorive dhe artikujve.' },
    'Shitjet POS sipas Orës & Ditës': { category: 'Bar & restorant', description: 'Gjej oraret dhe ditët me ngarkesën më të lartë.' },
    'Mix i Pagesave POS': { category: 'Bar & restorant', description: 'Shiko si janë paguar shitjet në kesh, kartë ose folio.' },
    'Anulime & Voids POS': { category: 'Bar & restorant', description: 'Kontrollo porositë e anuluara dhe ndikimin e tyre financiar.' },
};

const from = ref(props.filters?.from || '');
const to = ref(props.filters?.to || '');
const reportContent = ref(null);

watch(() => props.filters, (filters) => {
    from.value = filters?.from || '';
    to.value = filters?.to || '';
}, { deep: true });

const meta = computed(() => reportMeta[props.title] || {});
const reportDescription = computed(() => props.description || meta.value.description || 'Të dhëna të përmbledhura për vendimmarrje më të shpejtë.');
const reportCategory = computed(() => props.category || meta.value.category || 'Raporte');

function toYmd(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function fmtDate(value) {
    if (!value) return '';
    const [year, month, day] = value.split('-').map(Number);
    return new Intl.DateTimeFormat('sq-AL', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(year, month - 1, day));
}

const periodLabel = computed(() => {
    if (!props.filters) return 'Pamje aktuale';
    if (from.value && to.value) return `${fmtDate(from.value)} – ${fmtDate(to.value)}`;
    return 'Të gjitha datat';
});

function navigate(params = {}) {
    if (!props.routeName) return;
    router.get(route(props.routeName), params, { preserveState: true, preserveScroll: true, replace: true });
}

function apply() {
    navigate({ from: from.value, to: to.value });
}

function reset() {
    navigate();
}

function setPreset(preset) {
    const today = new Date();
    let start = new Date(today);
    let end = new Date(today);

    if (preset === '7d') start.setDate(today.getDate() - 6);
    if (preset === '30d') start.setDate(today.getDate() - 29);
    if (preset === 'month') start = new Date(today.getFullYear(), today.getMonth(), 1);
    if (preset === 'last-month') {
        start = new Date(today.getFullYear(), today.getMonth() - 1, 1);
        end = new Date(today.getFullYear(), today.getMonth(), 0);
    }

    from.value = toYmd(start);
    to.value = toYmd(end);
    apply();
}

function doPrint() {
    window.print();
}

function csvCell(value) {
    return `"${String(value ?? '').replaceAll('"', '""')}"`;
}

function exportCsv() {
    const tables = reportContent.value?.querySelectorAll('table') || [];
    const lines = [[props.title], [periodLabel.value], []];

    tables.forEach((table, index) => {
        if (index > 0) lines.push([]);
        table.querySelectorAll('tr').forEach((row) => {
            const cells = [...row.querySelectorAll('th, td')].map((cell) => cell.innerText.trim());
            if (cells.length) lines.push(cells);
        });
    });

    if (!tables.length) {
        lines.push(['Raporti nuk përmban tabelë për eksport.']);
    }

    const csv = `\uFEFF${lines.map((row) => row.map(csvCell).join(',')).join('\n')}`;
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `${props.title.toLocaleLowerCase('sq-AL').replaceAll(/[^a-z0-9ëç]+/gi, '-')}.csv`;
    link.click();
    URL.revokeObjectURL(url);
}
</script>

<template>
    <AppLayout>
        <div class="print:hidden">
            <Link :href="route('reports.index')" class="mb-3 inline-flex items-center gap-1.5 text-body-sm font-medium text-neutral-500 no-underline hover:text-accent-700">
                <ArrowLeft class="h-4 w-4" />
                Të gjitha raportet
            </Link>

            <PageHeader
                :title="title"
                :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Raporte', href: route('reports.index') }, { label: title }]"
            >
                <template #actions>
                    <Button variant="outline" @click="exportCsv">
                        <Download class="h-4 w-4" :stroke-width="1.75" />
                        Eksporto CSV
                    </Button>
                    <Button variant="ghost" @click="doPrint">
                        <Printer class="h-4 w-4" :stroke-width="1.75" />
                        Printo
                    </Button>
                </template>
            </PageHeader>

            <div class="mt-2 flex flex-wrap items-center gap-2">
                <span class="rounded-full bg-accent-50 px-2.5 py-1 text-tiny font-semibold uppercase tracking-wide text-accent-700">{{ reportCategory }}</span>
                <span class="hidden text-neutral-300 sm:inline">·</span>
                <p class="text-body-sm text-neutral-500">{{ reportDescription }}</p>
            </div>
        </div>

        <div v-if="filters" class="mt-6 rounded-lg border border-neutral-200 bg-white shadow-card print:hidden">
            <div class="flex flex-col gap-3 border-b border-neutral-200 px-4 py-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-center gap-2">
                    <CalendarDays class="h-4 w-4 text-accent-600" />
                    <div>
                        <p class="text-body-sm font-semibold text-primary-900">Periudha e raportit</p>
                        <p class="text-tiny text-neutral-500">{{ periodLabel }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <button type="button" class="report-preset" @click="setPreset('today')">Sot</button>
                    <button type="button" class="report-preset" @click="setPreset('7d')">7 ditë</button>
                    <button type="button" class="report-preset" @click="setPreset('30d')">30 ditë</button>
                    <button type="button" class="report-preset" @click="setPreset('month')">Ky muaj</button>
                    <button type="button" class="report-preset" @click="setPreset('last-month')">Muaji kaluar</button>
                </div>
            </div>

            <div class="flex flex-wrap items-end gap-3 px-4 py-4">
                <div class="min-w-[170px] flex-1 sm:flex-none">
                    <label class="mb-1.5 block text-label text-neutral-600">Nga data</label>
                    <DatePicker v-model="from" />
                </div>
                <div class="min-w-[170px] flex-1 sm:flex-none">
                    <label class="mb-1.5 block text-label text-neutral-600">Deri më</label>
                    <DatePicker v-model="to" />
                </div>
                <Button variant="primary" @click="apply">Apliko periudhën</Button>
                <Button variant="ghost" @click="reset">
                    <RotateCcw class="h-4 w-4" />
                    Rivendos
                </Button>
                <slot name="filters" />
            </div>
        </div>

        <div v-else class="mt-5 flex items-center gap-2 rounded-lg border border-neutral-200 bg-white px-4 py-3 shadow-card print:hidden">
            <FileBarChart class="h-4 w-4 text-accent-600" />
            <span class="text-body-sm font-medium text-primary-900">{{ periodLabel }}</span>
            <span class="text-body-sm text-neutral-500">· përditësohet me të dhënat aktuale të PMS-it</span>
        </div>

        <div ref="reportContent" class="report-content mt-5" data-report-content>
            <slot />
        </div>
    </AppLayout>
</template>

<style scoped>
.report-preset {
    @apply rounded-md border border-neutral-200 bg-white px-2.5 py-1.5 text-tiny font-medium text-neutral-600 transition hover:border-accent-300 hover:bg-accent-50 hover:text-accent-700;
}

.report-content :deep(table) {
    @apply w-full;
}

.report-content :deep(thead th) {
    @apply whitespace-nowrap;
}

.report-content :deep(tbody tr) {
    @apply transition-colors;
}

.report-content :deep(.grid > .rounded-lg > div > .text-center) {
    @apply text-left;
}

@media print {
    .report-content {
        margin-top: 0;
    }
}
</style>
