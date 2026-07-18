<script setup>
import { getIntlLocale, translate } from '@/i18n';
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
    presetMode: { type: String, default: 'historical' },
});

const reportMeta = {
    'Pasqyra Ekzekutive': { category: translate('admin.generated.k_74d293d3b604'), description: translate('admin.generated.k_4184602dda1e') },
    'ADR / RevPAR / Mbushja': { category: translate('admin.generated.k_74d293d3b604'), description: translate('admin.generated.k_53dea86c2bad') },
    'Tempo & Pickup': { category: translate('admin.generated.k_74d293d3b604'), description: translate('admin.generated.k_3f54075e41a8') },
    'Prodhimi sipas Kanaleve': { category: translate('admin.generated.k_b8f660853d79'), description: translate('admin.generated.k_679ae4c0d2e6') },
    'Anulime & No-Show': { category: translate('admin.generated.k_b8f660853d79'), description: translate('admin.generated.k_c25bffece3af') },
    'Sjellja e Rezervimit': { category: translate('admin.generated.k_b8f660853d79'), description: translate('admin.generated.k_b376285fe6db') },
    'Manifesti i Mbërritjeve': { category: 'Operacione', description: translate('admin.generated.k_2d7b0b3fe88b') },
    'Manifesti i Nisjeve': { category: 'Operacione', description: translate('admin.generated.k_4f29ce5fbf5f') },
    'Statusi i Dhomave': { category: 'Operacione', description: translate('admin.generated.k_6e37f1b06c08') },
    'Raporti i Pastrimit': { category: 'Operacione', description: translate('admin.generated.k_75a27485ff75') },
    'Mysafirë në Shtëpi': { category: 'Operacione', description: translate('admin.generated.k_af9117e5b47e') },
    'Bilance të Papaguara': { category: translate('admin.generated.k_6ac594d75a8f'), description: translate('admin.generated.k_165ae67e1ab5') },
    'Z-Report / Mbyllje Turni': { category: translate('admin.generated.k_6ac594d75a8f'), description: translate('admin.generated.k_767ddaaac1aa') },
    'Arkëtime & Cash': { category: translate('admin.generated.k_6ac594d75a8f'), description: translate('admin.generated.k_c7ebc39c51fd') },
    'Raport TVSH': { category: translate('admin.generated.k_6ac594d75a8f'), description: translate('admin.generated.k_eb029f3645df') },
    'Zbritje të Dhëna': { category: translate('admin.generated.k_6ac594d75a8f'), description: translate('admin.generated.k_22d9bd68de65') },
    'Direktoria e Mysafirëve': { category: translate('admin.generated.k_5c9435ba6ba2'), description: translate('admin.generated.k_1d5888aa36f4') },
    'Mysafirë Kthyes & Top': { category: translate('admin.generated.k_5c9435ba6ba2'), description: translate('admin.generated.k_2c41845ac1da') },
    'Përbërja sipas Kombësisë': { category: translate('admin.generated.k_5c9435ba6ba2'), description: translate('admin.generated.k_8baf439ecb7e') },
    'Shitjet POS (Kategori & Artikull)': { category: 'Bar & restorant', description: translate('admin.generated.k_b0fc069d75d7') },
    'Shitjet POS sipas Orës & Ditës': { category: 'Bar & restorant', description: translate('admin.generated.k_9ac274eff39b') },
    'Mix i Pagesave POS': { category: 'Bar & restorant', description: translate('admin.generated.k_0b0dd183ecaa') },
    'Anulime & Voids POS': { category: 'Bar & restorant', description: translate('admin.generated.k_85ea219d8b41') },
};

const from = ref(props.filters?.from || '');
const to = ref(props.filters?.to || '');
const reportContent = ref(null);

watch(() => props.filters, (filters) => {
    from.value = filters?.from || '';
    to.value = filters?.to || '';
}, { deep: true });

const meta = computed(() => reportMeta[props.title] || {});
const reportDescription = computed(() => props.description || meta.value.description || translate('admin.generated.k_bf8e042d8dfb'));
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
    return new Intl.DateTimeFormat(getIntlLocale(), { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(year, month - 1, day));
}

const periodLabel = computed(() => {
    if (!props.filters) return 'Pamje aktuale';
    if (from.value && to.value) return `${fmtDate(from.value)} – ${fmtDate(to.value)}`;
    return translate('admin.generated.k_24c45e650481');
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

    if (props.presetMode === 'future') {
        if (preset === '7d') end.setDate(today.getDate() + 6);
        if (preset === '30d') end.setDate(today.getDate() + 29);
        if (preset === '90d') end.setDate(today.getDate() + 89);
    } else {
        if (preset === '7d') start.setDate(today.getDate() - 6);
        if (preset === '30d') start.setDate(today.getDate() - 29);
    }
    if (props.presetMode !== 'future' && preset === 'month') start = new Date(today.getFullYear(), today.getMonth(), 1);
    if (props.presetMode !== 'future' && preset === 'last-month') {
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
        lines.push([translate('admin.generated.k_ef3203cb18bd')]);
    }

    const csv = `\uFEFF${lines.map((row) => row.map(csvCell).join(',')).join('\n')}`;
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `${props.title.toLocaleLowerCase(getIntlLocale()).replaceAll(/[^a-z0-9ëç]+/gi, '-')}.csv`;
    link.click();
    URL.revokeObjectURL(url);
}
</script>

<template>
    <AppLayout>
        <div class="print:hidden">
            <Link :href="route('reports.index')" class="mb-3 inline-flex items-center gap-1.5 text-body-sm font-medium text-neutral-500 no-underline hover:text-accent-700">
                <ArrowLeft class="h-4 w-4" />
{{ $t('admin.generated.k_9bf606e78dc5') }} </Link>

            <PageHeader
                :title="title"
                :breadcrumbs="[{ label: $t('admin.generated.k_da1d439be81a'), href: '/dashboard' }, { label: $t('admin.generated.k_b0c9134a46ba'), href: route('reports.index') }, { label: title }]"
            >
                <template #actions>
                    <Button variant="outline" @click="exportCsv">
                        <Download class="h-4 w-4" :stroke-width="1.75" />
{{ $t('admin.generated.k_2343a0abe892') }} </Button>
                    <Button variant="ghost" @click="doPrint">
                        <Printer class="h-4 w-4" :stroke-width="1.75" />
{{ $t('admin.generated.k_4f3c70b101b8') }} </Button>
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
                        <p class="text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_211f9abe3ab2') }}</p>
                        <p class="text-tiny text-neutral-500">{{ periodLabel }}</p>
                    </div>
                </div>
                <div v-if="presetMode === 'future'" class="flex flex-wrap gap-1.5">
                    <button type="button" class="report-preset" @click="setPreset('today')">{{ $t('admin.generated.k_d424d0615255') }}</button>
                    <button type="button" class="report-preset" @click="setPreset('7d')">{{ $t('reports360.pickupPace.next7') }}</button>
                    <button type="button" class="report-preset" @click="setPreset('30d')">{{ $t('reports360.pickupPace.next30') }}</button>
                    <button type="button" class="report-preset" @click="setPreset('90d')">{{ $t('reports360.pickupPace.next90') }}</button>
                </div>
                <div v-else class="flex flex-wrap gap-1.5">
                    <button type="button" class="report-preset" @click="setPreset('today')">{{ $t('admin.generated.k_d424d0615255') }}</button>
                    <button type="button" class="report-preset" @click="setPreset('7d')">{{ $t('admin.generated.k_1d2401a568d7') }}</button>
                    <button type="button" class="report-preset" @click="setPreset('30d')">{{ $t('admin.generated.k_233faf245b46') }}</button>
                    <button type="button" class="report-preset" @click="setPreset('month')">{{ $t('admin.generated.k_6f77604cdc8b') }}</button>
                    <button type="button" class="report-preset" @click="setPreset('last-month')">{{ $t('admin.generated.k_e13daaa6f8d5') }}</button>
                </div>
            </div>

            <div class="flex flex-wrap items-end gap-3 px-4 py-4">
                <div class="min-w-[170px] flex-1 sm:flex-none">
                    <label class="mb-1.5 block text-label text-neutral-600">{{ $t('admin.generated.k_d1d3be5f5f55') }}</label>
                    <DatePicker v-model="from" />
                </div>
                <div class="min-w-[170px] flex-1 sm:flex-none">
                    <label class="mb-1.5 block text-label text-neutral-600">{{ $t('admin.generated.k_fba5e34338ce') }}</label>
                    <DatePicker v-model="to" />
                </div>
                <Button variant="primary" @click="apply">{{ $t('admin.generated.k_27a903a22611') }}</Button>
                <Button variant="ghost" @click="reset">
                    <RotateCcw class="h-4 w-4" />
{{ $t('admin.generated.k_0b3a5b7ac194') }} </Button>
                <slot name="filters" />
            </div>
        </div>

        <div v-else class="mt-5 flex items-center gap-2 rounded-lg border border-neutral-200 bg-white px-4 py-3 shadow-card print:hidden">
            <FileBarChart class="h-4 w-4 text-accent-600" />
            <span class="text-body-sm font-medium text-primary-900">{{ periodLabel }}</span>
            <span class="text-body-sm text-neutral-500">{{ $t('admin.generated.k_d6ab5c628071') }}</span>
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
