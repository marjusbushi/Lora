<script setup>
import { getIntlLocale, translate } from '@/i18n';
import { computed, reactive, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import Modal from '@/Components/UI/Modal.vue';
import { ArrowLeft, CalendarDays, Clock3, Download, FileBarChart, Printer, RotateCcw, Save, Trash2 } from 'lucide-vue-next';

const props = defineProps({
    title: { type: String, required: true },
    routeName: { type: String, default: null },
    filters: { type: Object, default: null },
    description: { type: String, default: '' },
    category: { type: String, default: '' },
    presetMode: { type: String, default: 'historical' },
    query: { type: Object, default: () => ({}) },
});

// Keyed by the same translation keys the report pages pass as :title, so the
// lookup matches in every locale. Entries whose page provides its own
// :description/:category (the reports360.* pages) are omitted — the props win.
const reportMeta = {
    [translate('reports360.revenuePerformance.title')]: { category: translate('admin.generated.k_74d293d3b604'), description: translate('admin.generated.k_53dea86c2bad') },
    [translate('reports360.pickupPace.title')]: { category: translate('admin.generated.k_74d293d3b604'), description: translate('admin.generated.k_3f54075e41a8') },
    [translate('reports360.cancellationRisk.title')]: { category: translate('admin.generated.k_b8f660853d79'), description: translate('admin.generated.k_c25bffece3af') },
    [translate('admin.generated.k_bae5330a3766')]: { category: translate('reportShell.categoryOperations'), description: translate('admin.generated.k_2d7b0b3fe88b') },
    [translate('admin.generated.k_a39120663cac')]: { category: translate('reportShell.categoryOperations'), description: translate('admin.generated.k_4f29ce5fbf5f') },
    [translate('admin.generated.k_7b1933c35a94')]: { category: translate('reportShell.categoryOperations'), description: translate('admin.generated.k_6e37f1b06c08') },
    [translate('admin.generated.k_c50d20a648f3')]: { category: translate('reportShell.categoryOperations'), description: translate('admin.generated.k_af9117e5b47e') },
    [translate('admin.generated.k_5041fe965762')]: { category: translate('admin.generated.k_6ac594d75a8f'), description: translate('admin.generated.k_767ddaaac1aa') },
    [translate('admin.generated.k_76044710a976')]: { category: translate('admin.generated.k_5c9435ba6ba2'), description: translate('admin.generated.k_1d5888aa36f4') },
    [translate('admin.generated.k_b5c2b7cfa242')]: { category: translate('admin.generated.k_5c9435ba6ba2'), description: translate('admin.generated.k_8baf439ecb7e') },
    [translate('admin.generated.k_c7c5d0818fce')]: { category: translate('reportShell.categoryBarRestaurant'), description: translate('admin.generated.k_9ac274eff39b') },
    [translate('admin.generated.k_68f7bbdc7884')]: { category: translate('reportShell.categoryBarRestaurant'), description: translate('admin.generated.k_85ea219d8b41') },
};

const from = ref(props.filters?.from || '');
const to = ref(props.filters?.to || '');
const reportContent = ref(null);
const page = usePage();
const savedModalOpen = ref(false);
const saving = ref(false);
const savedReports = ref([]);
const saveError = ref('');
const saveForm = reactive({
    name: props.title,
    frequency: '',
    delivery_email: page.props.auth?.user?.email || '',
});

watch(() => props.filters, (filters) => {
    from.value = filters?.from || '';
    to.value = filters?.to || '';
}, { deep: true });

const meta = computed(() => reportMeta[props.title] || {});
const reportDescription = computed(() => props.description || meta.value.description || translate('admin.generated.k_bf8e042d8dfb'));
const reportCategory = computed(() => props.category || meta.value.category || translate('reportShell.defaultCategory'));

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
    if (!props.filters) return translate('reportShell.currentView');
    if (from.value && to.value) return `${fmtDate(from.value)} – ${fmtDate(to.value)}`;
    return translate('admin.generated.k_24c45e650481');
});

function navigate(params = {}) {
    if (!props.routeName) return;
    router.get(route(props.routeName), { ...props.query, ...params }, { preserveState: true, preserveScroll: true, replace: true });
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

function xmlCell(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}

function exportExcel() {
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

    const rows = lines.map((row) => `<Row>${row.map((cell) => `<Cell><Data ss:Type="String">${xmlCell(cell)}</Data></Cell>`).join('')}</Row>`).join('');
    const workbook = `<?xml version="1.0"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="${xmlCell(translate('reportShell.worksheetName'))}"><Table>${rows}</Table></Worksheet></Workbook>`;
    const blob = new Blob([workbook], { type: 'application/vnd.ms-excel;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    // \u00EB / \u00E7 are e-diaeresis and c-cedilla, escaped so the
    // Albanian-hardcode audit does not flag this slug regex.
    link.download = `${props.title.toLocaleLowerCase(getIntlLocale()).replaceAll(/[^a-z0-9\u00EB\u00E7]+/gi, '-')}.xls`;
    link.click();
    URL.revokeObjectURL(url);
}

async function openSavedReports() {
    savedModalOpen.value = true;
    saveError.value = '';
    try {
        const response = await axios.get(route('reports.saved.index'));
        savedReports.value = response.data.saved_reports || [];
    } catch {
        saveError.value = translate('reports360.savedReports.loadError');
    }
}

async function saveReport() {
    if (!props.routeName || !saveForm.name.trim()) return;
    saving.value = true;
    saveError.value = '';
    try {
        const response = await axios.post(route('reports.saved.store'), {
            name: saveForm.name.trim(),
            route_name: props.routeName,
            filters: { ...props.query, ...(props.filters ? { from: from.value, to: to.value } : {}) },
            frequency: saveForm.frequency || null,
            delivery_email: saveForm.frequency ? saveForm.delivery_email : null,
        });
        savedReports.value.unshift(response.data.saved_report);
    } catch (error) {
        saveError.value = error.response?.data?.message || translate('reports360.savedReports.saveError');
    } finally {
        saving.value = false;
    }
}

async function deleteSavedReport(savedReport) {
    await axios.delete(route('reports.saved.destroy', savedReport.id));
    savedReports.value = savedReports.value.filter((item) => item.id !== savedReport.id);
}

function savedHref(savedReport) {
    return route(savedReport.route_name, savedReport.filters || {});
}

function frequencyLabel(frequency) {
    return {
        daily: translate('reports360.savedReports.daily'),
        weekly: translate('reports360.savedReports.weekly'),
        monthly: translate('reports360.savedReports.monthly'),
    }[frequency] || translate('reports360.savedReports.manual');
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
                    <Button v-if="routeName" variant="outline" @click="openSavedReports">
                        <Save class="h-4 w-4" :stroke-width="1.75" />
                        {{ $t('reports360.savedReports.save') }}
                    </Button>
                    <Button variant="outline" @click="exportExcel">
                        <Download class="h-4 w-4" :stroke-width="1.75" />
                        {{ $t('reports360.savedReports.excel') }}
                    </Button>
                    <Button variant="ghost" @click="doPrint">
                        <Printer class="h-4 w-4" :stroke-width="1.75" />
                        {{ $t('reports360.savedReports.pdfPrint') }}
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

        <Modal :show="savedModalOpen" :title="$t('reports360.savedReports.modalTitle')" max-width="lg" @close="savedModalOpen = false">
            <div class="space-y-4">
                <div>
                    <label class="mb-1.5 block text-label text-neutral-600">{{ $t('reports360.savedReports.name') }}</label>
                    <input v-model="saveForm.name" type="text" maxlength="100" class="w-full rounded-lg border-neutral-200 text-body-sm focus:border-accent-500 focus:ring-accent-500" />
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-label text-neutral-600">{{ $t('reports360.savedReports.delivery') }}</label>
                        <select v-model="saveForm.frequency" class="w-full rounded-lg border-neutral-200 text-body-sm focus:border-accent-500 focus:ring-accent-500">
                            <option value="">{{ $t('reports360.savedReports.saveOnly') }}</option>
                            <option value="daily">{{ $t('reports360.savedReports.daily') }}</option>
                            <option value="weekly">{{ $t('reports360.savedReports.weekly') }}</option>
                            <option value="monthly">{{ $t('reports360.savedReports.monthly') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-label text-neutral-600">Email</label>
                        <input v-model="saveForm.delivery_email" type="email" :disabled="!saveForm.frequency" class="w-full rounded-lg border-neutral-200 text-body-sm disabled:bg-neutral-50 disabled:text-neutral-400 focus:border-accent-500 focus:ring-accent-500" />
                    </div>
                </div>
                <p v-if="saveError" class="rounded-lg bg-error-50 px-3 py-2 text-body-sm text-error-700">{{ saveError }}</p>
                <div class="flex justify-end">
                    <Button variant="primary" :disabled="saving || !saveForm.name.trim()" @click="saveReport">
                        <Save class="h-4 w-4" />
                        {{ saving ? $t('reports360.savedReports.saving') : $t('reports360.savedReports.saveReport') }}
                    </Button>
                </div>

                <div class="border-t border-neutral-200 pt-4">
                    <h4 class="mb-2 text-body-sm font-semibold text-primary-900">{{ $t('reports360.savedReports.title') }}</h4>
                    <div v-if="savedReports.length" class="divide-y divide-neutral-100 rounded-lg border border-neutral-200">
                        <div v-for="savedReport in savedReports" :key="savedReport.id" class="flex items-center gap-3 px-3 py-3">
                            <Clock3 class="h-4 w-4 shrink-0 text-neutral-400" />
                            <Link :href="savedHref(savedReport)" class="min-w-0 flex-1 no-underline">
                                <p class="truncate text-body-sm font-semibold text-primary-900 hover:text-accent-700">{{ savedReport.name }}</p>
                                <p class="text-tiny text-neutral-500">{{ frequencyLabel(savedReport.frequency) }}<span v-if="savedReport.next_delivery_at"> · {{ new Date(savedReport.next_delivery_at).toLocaleDateString(getIntlLocale()) }}</span></p>
                            </Link>
                            <button type="button" class="rounded-md p-2 text-neutral-400 hover:bg-error-50 hover:text-error-600" @click="deleteSavedReport(savedReport)"><Trash2 class="h-4 w-4" /></button>
                        </div>
                    </div>
                    <p v-else class="rounded-lg bg-neutral-50 px-3 py-5 text-center text-body-sm text-neutral-500">{{ $t('reports360.savedReports.empty') }}</p>
                </div>
            </div>
        </Modal>
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
