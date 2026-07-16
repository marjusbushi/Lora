<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    AlertTriangle,
    CheckCircle2,
    FileImage,
    FileSearch,
    PackageCheck,
    PackagePlus,
    Sparkles,
    UploadCloud,
} from 'lucide-vue-next';
import Modal from '@/Components/UI/Modal.vue';
import Button from '@/Components/UI/Button.vue';
import { money } from '../financeShared.js';

const props = defineProps({
    show: Boolean,
    aiConfigured: Boolean,
    canCreateItems: Boolean,
    baseCurrency: String,
});

const emit = defineEmits(['close', 'apply']);
const { t } = useI18n();
const fileInput = ref(null);
const documentFile = ref(null);
const analyzing = ref(false);
const result = ref(null);
const error = ref('');

const newItems = computed(() => Number(result.value?.summary?.new_items || 0));
const matchedItems = computed(() => Number(result.value?.summary?.matched_items || 0));
const canApply = computed(() => result.value && (props.canCreateItems || newItems.value === 0));

watch(() => props.show, (show) => {
    if (!show) return;
    documentFile.value = null;
    result.value = null;
    error.value = '';
});

function chooseFile(event) {
    const file = event.target.files?.[0] || null;
    result.value = null;
    error.value = '';

    if (!file) {
        documentFile.value = null;
        return;
    }
    if (file.size > 10 * 1024 * 1024) {
        error.value = t('admin.finance.billAiImport.errors.file_too_large');
        event.target.value = '';
        return;
    }

    documentFile.value = file;
}

function errorMessage(errorCode) {
    const known = ['ai_not_configured', 'unsupported_file', 'file_too_large', 'no_readable_lines', 'analysis_failed'];
    const code = known.includes(errorCode) ? errorCode : 'analysis_failed';

    return t(`admin.finance.billAiImport.errors.${code}`);
}

async function analyze() {
    if (!documentFile.value || analyzing.value) return;

    analyzing.value = true;
    error.value = '';
    const payload = new FormData();
    payload.append('document', documentFile.value);

    try {
        const { data } = await axios.post(route('finance.bills.import-ai.analyze'), payload, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        result.value = data;
    } catch (exception) {
        error.value = errorMessage(exception.response?.data?.error_code);
    } finally {
        analyzing.value = false;
    }
}

function apply() {
    if (!canApply.value) return;
    emit('apply', result.value);
}
</script>

<template>
    <Modal :show="show" :title="$t('admin.finance.billAiImport.title')" max-width="4xl" :closeable="!analyzing" @close="emit('close')">
        <div class="space-y-5">
            <div v-if="!aiConfigured" class="flex gap-3 rounded-lg border border-warning-200 bg-warning-50 p-4 text-warning-800">
                <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0" />
                <div>
                    <strong class="block text-body-sm">{{ $t('admin.finance.billAiImport.notConfiguredTitle') }}</strong>
                    <p class="mt-1 text-tiny leading-relaxed">{{ $t('admin.finance.billAiImport.notConfiguredBody') }}</p>
                </div>
            </div>

            <template v-else-if="!result">
                <button
                    type="button"
                    class="group flex w-full flex-col items-center rounded-xl border-2 border-dashed border-neutral-200 bg-neutral-50/70 px-6 py-10 text-center transition hover:border-accent-300 hover:bg-accent-50/50"
                    @click="fileInput?.click()"
                >
                    <span class="grid h-12 w-12 place-items-center rounded-xl bg-accent-100 text-accent-700 transition group-hover:scale-105"><UploadCloud class="h-6 w-6" /></span>
                    <strong class="mt-4 text-body-sm text-primary-900">{{ documentFile?.name || $t('admin.finance.billAiImport.chooseDocument') }}</strong>
                    <span class="mt-1 text-tiny text-neutral-400">{{ $t('admin.finance.billAiImport.fileHelp') }}</span>
                    <input ref="fileInput" type="file" class="hidden" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" @change="chooseFile" />
                </button>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="flex items-start gap-3 rounded-lg border border-neutral-200 p-3">
                        <FileSearch class="mt-0.5 h-4 w-4 shrink-0 text-accent-700" />
                        <p class="text-tiny leading-relaxed text-neutral-500"><b class="block text-neutral-700">{{ $t('admin.finance.billAiImport.stepReadTitle') }}</b>{{ $t('admin.finance.billAiImport.stepReadBody') }}</p>
                    </div>
                    <div class="flex items-start gap-3 rounded-lg border border-neutral-200 p-3">
                        <PackageCheck class="mt-0.5 h-4 w-4 shrink-0 text-accent-700" />
                        <p class="text-tiny leading-relaxed text-neutral-500"><b class="block text-neutral-700">{{ $t('admin.finance.billAiImport.stepMatchTitle') }}</b>{{ $t('admin.finance.billAiImport.stepMatchBody') }}</p>
                    </div>
                    <div class="flex items-start gap-3 rounded-lg border border-neutral-200 p-3">
                        <CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0 text-accent-700" />
                        <p class="text-tiny leading-relaxed text-neutral-500"><b class="block text-neutral-700">{{ $t('admin.finance.billAiImport.stepReviewTitle') }}</b>{{ $t('admin.finance.billAiImport.stepReviewBody') }}</p>
                    </div>
                </div>
            </template>

            <template v-else>
                <div class="grid gap-3 lg:grid-cols-3">
                    <article class="rounded-lg border border-neutral-200 p-4">
                        <span class="text-tiny font-bold uppercase tracking-wide text-neutral-400">{{ $t('admin.finance.billAiImport.supplier') }}</span>
                        <strong class="mt-1 block truncate text-body-sm text-primary-900">{{ result.supplier.name || '—' }}</strong>
                        <span v-if="result.supplier.match" class="mt-2 inline-flex items-center gap-1 rounded-full bg-accent-50 px-2 py-1 text-tiny font-bold text-accent-700"><CheckCircle2 class="h-3 w-3" />{{ $t('admin.finance.billAiImport.matched') }}</span>
                        <span v-else class="mt-2 inline-flex items-center gap-1 rounded-full bg-warning-50 px-2 py-1 text-tiny font-bold text-warning-700"><AlertTriangle class="h-3 w-3" />{{ $t('admin.finance.billAiImport.selectManually') }}</span>
                    </article>
                    <article class="rounded-lg border border-neutral-200 p-4">
                        <span class="text-tiny font-bold uppercase tracking-wide text-neutral-400">{{ $t('admin.finance.billAiImport.document') }}</span>
                        <strong class="mt-1 block text-body-sm text-primary-900">{{ result.invoice.number || $t('admin.finance.billCreate.noNumber') }}</strong>
                        <span class="mt-2 block text-tiny text-neutral-500">{{ result.invoice.issue_date || '—' }} · {{ result.invoice.currency }}</span>
                    </article>
                    <article class="rounded-lg border border-neutral-200 p-4">
                        <span class="text-tiny font-bold uppercase tracking-wide text-neutral-400">{{ $t('admin.finance.billAiImport.total') }}</span>
                        <strong class="mt-1 block text-h3 text-accent-700">{{ money(result.invoice.grand_total, result.invoice.currency || baseCurrency) }}</strong>
                        <span class="mt-2 block text-tiny text-neutral-500">{{ $t('admin.finance.billAiImport.confidence', { value: result.confidence }) }}</span>
                    </article>
                </div>

                <div v-if="result.invoice.line_costs_adjusted" class="flex gap-2 rounded-lg border border-info-200 bg-info-50 px-3 py-2.5 text-tiny leading-relaxed text-info-800">
                    <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />{{ $t('admin.finance.billAiImport.costAdjustment') }}
                </div>
                <div v-if="Math.abs(Number(result.invoice.line_total_difference || 0)) > 0.02" class="flex gap-2 rounded-lg border border-warning-200 bg-warning-50 px-3 py-2.5 text-tiny leading-relaxed text-warning-800">
                    <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />{{ $t('admin.finance.billAiImport.totalMismatch', { amount: money(Math.abs(result.invoice.line_total_difference), result.invoice.currency) }) }}
                </div>
                <div v-if="result.invoice.possible_duplicate" class="flex gap-2 rounded-lg border border-error-200 bg-error-50 px-3 py-2.5 text-tiny leading-relaxed text-error-700">
                    <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />{{ $t('admin.finance.billAiImport.possibleDuplicate', { number: result.invoice.possible_duplicate.number }) }}
                </div>

                <div class="overflow-hidden rounded-lg border border-neutral-200">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 bg-neutral-50 px-4 py-3">
                        <strong class="text-body-sm text-primary-900">{{ $t('admin.finance.billAiImport.itemsFound', { count: result.items.length }) }}</strong>
                        <div class="flex gap-2">
                            <span class="inline-flex items-center gap-1 rounded-full bg-accent-50 px-2 py-1 text-tiny font-bold text-accent-700"><PackageCheck class="h-3 w-3" />{{ matchedItems }} {{ $t('admin.finance.billAiImport.existing') }}</span>
                            <span class="inline-flex items-center gap-1 rounded-full bg-warning-50 px-2 py-1 text-tiny font-bold text-warning-700"><PackagePlus class="h-3 w-3" />{{ newItems }} {{ $t('admin.finance.billAiImport.new') }}</span>
                        </div>
                    </div>
                    <div class="max-h-72 divide-y divide-neutral-100 overflow-y-auto">
                        <div v-for="(item, index) in result.items" :key="`${item.description}-${index}`" class="grid items-center gap-2 px-4 py-3 sm:grid-cols-[minmax(0,1fr),180px,120px]">
                            <div class="min-w-0">
                                <strong class="block truncate text-body-sm text-primary-900">{{ item.description }}</strong>
                                <span class="text-tiny text-neutral-400">{{ item.quantity }} {{ $t(`admin.finance.billAiImport.units.${item.unit}`) }}</span>
                            </div>
                            <span v-if="item.match" class="truncate text-tiny font-semibold text-accent-700">→ {{ item.match.name }}</span>
                            <span v-else class="text-tiny font-semibold text-warning-700">{{ $t('admin.finance.billAiImport.willCreate') }}</span>
                            <strong class="text-right text-body-sm tabular-nums text-primary-900">{{ money(item.line_total, result.invoice.currency) }}</strong>
                        </div>
                    </div>
                </div>

                <div v-if="newItems && !canCreateItems" class="flex gap-2 rounded-lg border border-error-200 bg-error-50 px-3 py-2.5 text-tiny text-error-700">
                    <AlertTriangle class="h-4 w-4 shrink-0" />{{ $t('admin.finance.billAiImport.noItemPermission') }}
                </div>
            </template>

            <div v-if="error" class="flex gap-2 rounded-lg border border-error-200 bg-error-50 px-3 py-2.5 text-tiny text-error-700">
                <AlertTriangle class="h-4 w-4 shrink-0" />{{ error }}
            </div>
        </div>

        <template #footer>
            <Button variant="ghost" :disabled="analyzing" @click="emit('close')">{{ $t('admin.finance.billAiImport.cancel') }}</Button>
            <Button v-if="!result" :loading="analyzing" :disabled="!aiConfigured || !documentFile" @click="analyze">
                <Sparkles class="h-4 w-4" />{{ $t('admin.finance.billAiImport.readWithAi') }}
            </Button>
            <Button v-else :disabled="!canApply" @click="apply">
                <FileImage class="h-4 w-4" />{{ $t('admin.finance.billAiImport.useData') }}
            </Button>
        </template>
    </Modal>
</template>
