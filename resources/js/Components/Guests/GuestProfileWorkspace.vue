<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { getIntlLocale, translate } from '@/i18n';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Select from '@/Components/UI/Select.vue';
import AuditTimeline from '@/Components/AuditTimeline.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';
import { countryName } from '@/countries';
import {
    AlertCircle,
    BedDouble,
    CalendarDays,
    CheckCircle2,
    Clock3,
    Eye,
    FileScan,
    FileText,
    Mail,
    Pencil,
    Phone,
    ShieldCheck,
    Sparkles,
    Trash2,
    UploadCloud,
    UserRound,
    Wallet,
    X,
} from 'lucide-vue-next';

const props = defineProps({
    initialGuest: { type: Object, required: true },
    stats: { type: Object, required: true },
    stays: { type: Array, default: () => [] },
    documents: { type: Array, default: () => [] },
    activities: { type: Array, default: () => [] },
    history: { type: Array, default: () => [] },
    duplicates: { type: Array, default: () => [] },
    canUpdate: { type: Boolean, default: false },
    canMerge: { type: Boolean, default: false },
    aiConfigured: { type: Boolean, default: false },
    demo: { type: Boolean, default: false },
});

const guest = reactive({ ...props.initialGuest });
const localDocuments = ref(props.documents.map(normalizeDocument));
const toasts = ref(null);
const showAiReview = ref(false);
const analyzingDocumentId = ref(null);
const applying = ref(false);
const reviewDocument = ref(null);
const selectedFields = ref([]);
const uploadInput = ref(null);
const uploadForm = useForm({ type: 'passport', file: null });
let analysisTimer = null;

const stayStatusVariants = {
    pending: 'warning',
    confirmed: 'info',
    checked_in: 'success',
    checked_out: 'neutral',
    cancelled: 'error',
};

const mockExtraction = {
    fields: {
        first_name: { value: 'Marjus', confidence: 99 },
        last_name: { value: 'Bushi', confidence: 99 },
        nationality: { value: 'ALB', confidence: 98 },
        date_of_birth: { value: '1992-04-18', confidence: 97 },
        document_type: { value: 'passport', confidence: 99 },
        document_number: { value: 'BA1234567', confidence: 96 },
    },
};

const documentTypeOptions = computed(() => [
    { value: 'passport', label: translate('admin.guestProfileDesign.documentTypes.passport') },
    { value: 'id_card', label: translate('admin.guestProfileDesign.documentTypes.id_card') },
    { value: 'drivers_license', label: translate('admin.guestProfileDesign.documentTypes.drivers_license') },
]);

const aiRows = computed(() => {
    const fields = reviewDocument.value?.ai_extraction?.fields || {};
    return ['first_name', 'last_name', 'nationality', 'date_of_birth', 'document_type', 'document_number'].map((key) => {
        const item = fields[key] || { value: null, confidence: 0 };
        return {
            key,
            current: displayValue(key, guest[key]),
            extracted: displayValue(key, item.value),
            rawValue: item.value,
            confidence: Number(item.confidence || 0),
        };
    });
});

const completedIdentityFields = computed(() => [
    guest.first_name,
    guest.last_name,
    guest.email,
    guest.phone,
    guest.nationality,
    guest.date_of_birth,
    guest.document_number,
].filter(Boolean).length);
const profileCompletion = computed(() => Math.round((completedIdentityFields.value / 7) * 100));
const initials = computed(() => `${guest.first_name?.[0] || ''}${guest.last_name?.[0] || ''}`.toUpperCase());
const latestStay = computed(() => props.stats.last_stay || (props.stays[0]
    ? `${formatDate(stayValue(props.stays[0], 'check_in'))} – ${formatDate(stayValue(props.stays[0], 'check_out'))}`
    : '—'));

watch(() => props.initialGuest, (value) => Object.assign(guest, value), { deep: true });
watch(() => props.documents, (value) => { localDocuments.value = value.map(normalizeDocument); }, { deep: true });

function normalizeDocument(document) {
    return {
        ...document,
        name: document.name || document.original_name,
        uploaded_at: document.uploaded_at || document.created_at,
        size_label: document.size_label || fileSize(document.size),
        ai_status: document.ai_status || (document.status === 'verified' ? 'reviewed' : document.status === 'ready' ? 'ready' : 'pending'),
    };
}

function fileSize(bytes) {
    if (!bytes) return '';
    const kb = Number(bytes) / 1024;
    return kb < 1024 ? `${Math.round(kb)} KB` : `${(kb / 1024).toFixed(1)} MB`;
}

function stayValue(stay, field) {
    return stay[field] || stay[`${field}_date`];
}

function formatDate(value) {
    if (!value) return '—';
    return new Date(`${value}T00:00:00`).toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short', year: 'numeric' });
}

function displayValue(key, value) {
    if (!value) return '—';
    if (key === 'nationality') return countryName(value);
    if (key === 'document_type') return translate(`admin.guestProfileDesign.documentTypes.${value}`);
    if (key === 'date_of_birth') return formatDate(value);
    return value;
}

function canAnalyze(document) {
    return ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'].includes(document.mime)
        && Number(document.size || 0) <= 10 * 1024 * 1024;
}

function openReview(document) {
    reviewDocument.value = document;
    selectedFields.value = Object.entries(document.ai_extraction?.fields || {})
        .filter(([, item]) => item?.value !== null && item?.value !== '')
        .map(([key]) => key);
    showAiReview.value = true;
}

async function startAiReview(document) {
    if (document.ai_status === 'ready' && document.ai_extraction) {
        openReview(document);
        return;
    }

    if (props.demo) {
        analyzingDocumentId.value = document.id;
        clearTimeout(analysisTimer);
        analysisTimer = setTimeout(() => {
            document.ai_status = 'ready';
            document.ai_extraction = mockExtraction;
            analyzingDocumentId.value = null;
            openReview(document);
        }, 700);
        return;
    }

    if (!props.aiConfigured) {
        toasts.value?.warning(translate('admin.guestProfileDesign.aiNotConfigured'));
        return;
    }

    analyzingDocumentId.value = document.id;
    try {
        const { data } = await axios.post(route('guests.documents.analyze', [guest.id, document.id]));
        Object.assign(document, data.document);
        openReview(document);
    } catch (error) {
        document.ai_status = 'failed';
        document.ai_error = error.response?.data?.message;
        toasts.value?.error(document.ai_error || translate('admin.guestProfileDesign.aiFailed'));
    } finally {
        analyzingDocumentId.value = null;
    }
}

function toggleField(key) {
    selectedFields.value = selectedFields.value.includes(key)
        ? selectedFields.value.filter((field) => field !== key)
        : [...selectedFields.value, key];
}

async function applyAiData() {
    if (!selectedFields.value.length || !reviewDocument.value) return;

    if (props.demo) {
        for (const row of aiRows.value) {
            if (selectedFields.value.includes(row.key) && row.rawValue) guest[row.key] = row.rawValue;
        }
        reviewDocument.value.ai_status = 'reviewed';
        showAiReview.value = false;
        toasts.value?.success(translate('admin.guestProfileDesign.appliedDemo'));
        return;
    }

    applying.value = true;
    try {
        const { data } = await axios.put(
            route('guests.documents.apply-ai', [guest.id, reviewDocument.value.id]),
            { fields: selectedFields.value },
        );
        Object.assign(guest, data.guest);
        Object.assign(reviewDocument.value, data.document);
        showAiReview.value = false;
        toasts.value?.success(translate('admin.guestProfileDesign.appliedReal'));
    } catch (error) {
        toasts.value?.error(error.response?.data?.message || translate('admin.guestProfileDesign.applyFailed'));
    } finally {
        applying.value = false;
    }
}

function onFile(event) {
    uploadForm.file = event.target.files?.[0] || null;
}

function uploadDocument() {
    if (!uploadForm.file) return;
    if (props.demo) {
        localDocuments.value.unshift(normalizeDocument({
            id: `mock-${Date.now()}`,
            type: uploadForm.type,
            name: uploadForm.file.name,
            size_label: fileSize(uploadForm.file.size),
            size: uploadForm.file.size,
            mime: uploadForm.file.type,
            uploaded_at: new Date().toISOString().slice(0, 10),
            ai_status: 'pending',
        }));
        uploadForm.reset('file');
        if (uploadInput.value) uploadInput.value.value = '';
        return;
    }

    uploadForm.post(route('guests.documents.store', guest.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            uploadForm.reset('file');
            if (uploadInput.value) uploadInput.value.value = '';
            toasts.value?.success(translate('admin.guestProfileDesign.uploaded'));
        },
    });
}

function deleteDocument(document) {
    if (!confirm(translate('admin.guestProfileDesign.deleteConfirm', { name: document.name }))) return;
    router.delete(route('guests.documents.destroy', document.id), {
        preserveScroll: true,
        onSuccess: () => toasts.value?.success(translate('admin.guestProfileDesign.deleted')),
    });
}

onBeforeUnmount(() => clearTimeout(analysisTimer));
</script>

<template>
    <div class="pb-10">
        <PageHeader
            :title="`${guest.first_name} ${guest.last_name}`"
            :breadcrumbs="[
                { label: $t('admin.guestProfileDesign.dashboard'), href: '/dashboard' },
                { label: $t('admin.guestProfileDesign.guests'), href: route('guests.index') },
                { label: demo ? $t('admin.guestProfileDesign.preview') : `${guest.first_name} ${guest.last_name}` },
            ]"
        >
            <template #actions>
                <Badge v-if="demo" variant="warning">{{ $t('admin.guestProfileDesign.mockData') }}</Badge>
                <Link :href="route('guests.index')" class="no-underline">
                    <Button variant="outline">{{ $t('admin.guestProfileDesign.back') }}</Button>
                </Link>
                <Button v-if="demo" variant="outline" @click="toasts?.success($t('admin.guestProfileDesign.mockAction'))">
                    <Pencil class="h-4 w-4" />{{ $t('admin.guestProfileDesign.editProfile') }}
                </Button>
            </template>
        </PageHeader>

        <section class="mt-5 overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-card">
            <div class="flex flex-col gap-5 bg-gradient-to-r from-primary-950 via-primary-900 to-accent-900 px-5 py-5 text-white lg:flex-row lg:items-center lg:justify-between">
                <div class="flex min-w-0 items-center gap-4">
                    <div class="grid h-16 w-16 shrink-0 place-items-center rounded-2xl bg-white/10 text-h2 font-extrabold ring-1 ring-white/20">{{ initials }}</div>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="truncate text-h2 text-white">{{ guest.first_name }} {{ guest.last_name }}</h1>
                            <Badge v-if="stats.total_stays > 1" variant="success" dot>{{ $t('admin.guestProfileDesign.returningGuest') }}</Badge>
                            <Badge :variant="profileCompletion === 100 ? 'success' : 'warning'">{{ profileCompletion }}% {{ $t('admin.guestProfileDesign.complete') }}</Badge>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-body-sm text-primary-100">
                            <span v-if="guest.email" class="inline-flex items-center gap-1.5"><Mail class="h-4 w-4" />{{ guest.email }}</span>
                            <span v-if="guest.phone" class="inline-flex items-center gap-1.5"><Phone class="h-4 w-4" />{{ guest.phone }}</span>
                            <span class="inline-flex items-center gap-1.5"><UserRound class="h-4 w-4" />ID #{{ guest.id }}</span>
                        </div>
                    </div>
                </div>
                <div class="w-full max-w-xs rounded-xl bg-white/10 p-3 ring-1 ring-white/15">
                    <div class="flex items-center justify-between text-tiny"><span>{{ $t('admin.guestProfileDesign.identityCompletion') }}</span><strong>{{ profileCompletion }}%</strong></div>
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-white/15"><div class="h-full rounded-full bg-success-400 transition-all" :style="{ width: `${profileCompletion}%` }" /></div>
                    <p v-if="profileCompletion < 100" class="mt-2 text-[11px] text-primary-100">{{ $t('admin.guestProfileDesign.completionHelp') }}</p>
                </div>
            </div>
        </section>

        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Card><div class="flex items-center justify-between"><div><p class="text-tiny text-neutral-500">{{ $t('admin.guestProfileDesign.stays') }}</p><p class="mt-1 text-h3 text-primary-900">{{ stats.total_stays }}</p></div><span class="grid h-10 w-10 place-items-center rounded-xl bg-accent-50 text-accent-700"><BedDouble class="h-5 w-5" /></span></div></Card>
            <Card><div class="flex items-center justify-between"><div><p class="text-tiny text-neutral-500">{{ $t('admin.guestProfileDesign.nights') }}</p><p class="mt-1 text-h3 text-primary-900">{{ stats.total_nights }}</p></div><span class="grid h-10 w-10 place-items-center rounded-xl bg-info-50 text-info-700"><CalendarDays class="h-5 w-5" /></span></div></Card>
            <Card><div class="flex items-center justify-between"><div><p class="text-tiny text-neutral-500">{{ $t('admin.guestProfileDesign.value') }}</p><p class="mt-1 text-h3 text-primary-900">€{{ Number(stats.lifetime_spend).toFixed(2) }}</p></div><span class="grid h-10 w-10 place-items-center rounded-xl bg-success-50 text-success-700"><Wallet class="h-5 w-5" /></span></div></Card>
            <Card><div class="flex items-center justify-between"><div><p class="text-tiny text-neutral-500">{{ $t('admin.guestProfileDesign.lastStay') }}</p><p class="mt-1 text-body-sm font-extrabold text-primary-900">{{ latestStay }}</p></div><span class="grid h-10 w-10 place-items-center rounded-xl bg-warning-50 text-warning-700"><Clock3 class="h-5 w-5" /></span></div></Card>
        </div>

        <div class="mt-4 grid gap-4 xl:grid-cols-12">
            <div class="space-y-4 xl:col-span-4">
                <Card :padding="false">
                    <template #header><div class="flex items-center justify-between"><div><h2 class="text-body font-bold text-primary-900">{{ $t('admin.guestProfileDesign.identity') }}</h2><p class="text-tiny text-neutral-400">{{ $t('admin.guestProfileDesign.identitySubtitle') }}</p></div><ShieldCheck class="h-5 w-5 text-accent-600" /></div></template>
                    <dl class="divide-y divide-neutral-100 px-5">
                        <div class="flex justify-between gap-4 py-3"><dt class="text-tiny text-neutral-500">{{ $t('admin.guestProfileDesign.fullName') }}</dt><dd class="text-right text-body-sm font-semibold text-primary-900">{{ guest.first_name }} {{ guest.last_name }}</dd></div>
                        <div class="flex justify-between gap-4 py-3"><dt class="text-tiny text-neutral-500">{{ $t('admin.guestProfileDesign.nationality') }}</dt><dd class="text-right text-body-sm font-semibold text-primary-900">{{ guest.nationality ? countryName(guest.nationality) : '—' }}</dd></div>
                        <div class="flex justify-between gap-4 py-3"><dt class="text-tiny text-neutral-500">{{ $t('admin.guestProfileDesign.birthDate') }}</dt><dd class="text-right text-body-sm font-semibold" :class="guest.date_of_birth ? 'text-primary-900' : 'text-warning-700'">{{ guest.date_of_birth ? formatDate(guest.date_of_birth) : $t('admin.guestProfileDesign.missing') }}</dd></div>
                        <div class="flex justify-between gap-4 py-3"><dt class="text-tiny text-neutral-500">{{ $t('admin.guestProfileDesign.document') }}</dt><dd class="text-right text-body-sm font-semibold" :class="guest.document_number ? 'text-primary-900' : 'text-warning-700'">{{ guest.document_number || $t('admin.guestProfileDesign.missing') }}</dd></div>
                        <div v-if="guest.notes" class="py-3"><dt class="text-tiny text-neutral-500">{{ $t('admin.guestProfileDesign.notes') }}</dt><dd class="mt-1 text-body-sm text-primary-900">{{ guest.notes }}</dd></div>
                    </dl>
                </Card>

                <Card v-if="duplicates.length" :padding="false">
                    <template #header><h2 class="text-body font-bold text-warning-800">{{ $t('admin.guestProfileDesign.possibleDuplicates') }}</h2></template>
                    <div class="space-y-2 p-4">
                        <div v-for="duplicate in duplicates" :key="duplicate.id" class="rounded-lg bg-warning-50 px-3 py-2">
                            <Link :href="route('guests.show', duplicate.id)" class="block text-body-sm font-semibold text-warning-800 no-underline hover:underline">{{ duplicate.first_name }} {{ duplicate.last_name }}<span v-if="duplicate.email" class="block text-tiny font-normal">{{ duplicate.email }}</span></Link>
                            <Link v-if="canMerge" :href="route('guests.merge.show', [guest.id, duplicate.id])" class="mt-2 inline-flex no-underline"><Button size="sm" variant="outline"><Sparkles class="h-4 w-4" />{{ $t('admin.guestMerge.start') }}</Button></Link>
                        </div>
                    </div>
                </Card>

                <Card :padding="false">
                    <template #header><div class="flex items-center justify-between"><div><h2 class="text-body font-bold text-primary-900">{{ $t('admin.guestProfileDesign.documents') }}</h2><p class="text-tiny text-neutral-400">{{ $t('admin.guestProfileDesign.documentsSubtitle') }}</p></div><FileText class="h-5 w-5 text-neutral-400" /></div></template>
                    <div class="space-y-3 p-4">
                        <article v-for="document in localDocuments" :key="document.id" class="rounded-xl border border-neutral-200 p-3">
                            <div class="flex items-start gap-3">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-info-50 text-info-700"><FileScan class="h-4 w-4" /></span>
                                <div class="min-w-0 flex-1"><p class="truncate text-body-sm font-bold text-primary-900">{{ document.name }}</p><p class="mt-0.5 text-[10px] text-neutral-400">{{ document.size_label }} · {{ document.uploaded_at }}</p></div>
                                <Badge :variant="document.ai_status === 'reviewed' ? 'success' : document.ai_status === 'failed' ? 'error' : 'warning'" dot>{{ $t(`admin.guestProfileDesign.aiStatuses.${document.ai_status || 'pending'}`) }}</Badge>
                            </div>
                            <p v-if="document.ai_error" class="mt-2 text-tiny text-error-700">{{ document.ai_error }}</p>
                            <p v-else-if="(canUpdate || demo) && document.ai_status !== 'reviewed' && !canAnalyze(document)" class="mt-2 text-tiny text-warning-700">{{ $t('admin.guestProfileDesign.aiUnsupported') }}</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <Button v-if="(canUpdate || demo) && document.ai_status !== 'reviewed' && canAnalyze(document)" size="sm" :loading="analyzingDocumentId === document.id" @click="startAiReview(document)"><Sparkles class="h-4 w-4" />{{ document.ai_status === 'ready' ? $t('admin.guestProfileDesign.openReview') : $t('admin.guestProfileDesign.reviewWithAi') }}</Button>
                                <a v-if="document.url" :href="document.url" target="_blank" rel="noopener" class="no-underline"><Button size="sm" variant="outline"><Eye class="h-4 w-4" />{{ $t('admin.guestProfileDesign.view') }}</Button></a>
                                <Button v-if="canUpdate && !demo" size="sm" variant="ghost" class="text-error-600" @click="deleteDocument(document)"><Trash2 class="h-4 w-4" /></Button>
                            </div>
                        </article>
                        <p v-if="!localDocuments.length" class="py-4 text-center text-body-sm text-neutral-500">{{ $t('admin.guestProfileDesign.noDocuments') }}</p>

                        <div v-if="canUpdate || demo" class="rounded-xl border border-dashed border-accent-300 bg-accent-50/40 p-3">
                            <Select v-model="uploadForm.type" :options="documentTypeOptions" />
                            <label class="mt-2 flex cursor-pointer flex-col items-center rounded-lg px-3 py-4 text-center hover:bg-accent-50">
                                <UploadCloud class="h-6 w-6 text-accent-700" /><span class="mt-2 text-body-sm font-bold text-accent-800">{{ uploadForm.file?.name || $t('admin.guestProfileDesign.chooseDocument') }}</span><span class="mt-1 text-[10px] text-neutral-500">JPG, PNG, WEBP, PDF · max 25MB</span><input ref="uploadInput" type="file" class="sr-only" accept="image/jpeg,image/png,image/webp,.pdf" @change="onFile" />
                            </label>
                            <p v-if="uploadForm.errors.file" class="mb-2 text-tiny text-error-600">{{ uploadForm.errors.file }}</p>
                            <Button class="w-full justify-center" size="sm" :loading="uploadForm.processing" :disabled="!uploadForm.file" @click="uploadDocument"><UploadCloud class="h-4 w-4" />{{ $t('admin.guestProfileDesign.uploadDocument') }}</Button>
                        </div>
                    </div>
                </Card>
            </div>

            <div class="space-y-4 xl:col-span-8">
                <Card :padding="false">
                    <template #header><div><h2 class="text-body font-bold text-primary-900">{{ $t('admin.guestProfileDesign.stayHistory') }}</h2><p class="text-tiny text-neutral-400">{{ $t('admin.guestProfileDesign.staySubtitle') }}</p></div></template>
                    <div class="overflow-x-auto"><table class="min-w-full"><thead class="bg-neutral-50 text-left text-tiny uppercase tracking-wide text-neutral-500"><tr><th class="px-5 py-3">{{ $t('admin.guestProfileDesign.room') }}</th><th class="px-5 py-3">{{ $t('admin.guestProfileDesign.period') }}</th><th class="px-5 py-3">{{ $t('admin.guestProfileDesign.status') }}</th><th class="px-5 py-3 text-right">{{ $t('admin.guestProfileDesign.total') }}</th></tr></thead><tbody class="divide-y divide-neutral-100"><tr v-for="stay in stays" :key="stay.id" class="hover:bg-neutral-50"><td class="px-5 py-3"><Link v-if="!demo" :href="route('reservations.show', stay.id)" class="text-body-sm font-bold text-primary-900 no-underline hover:underline">{{ stay.room }}</Link><p v-else class="text-body-sm font-bold text-primary-900">{{ stay.room }}</p><p class="text-[10px] text-neutral-400">{{ stay.room_type }}</p></td><td class="whitespace-nowrap px-5 py-3 text-body-sm text-neutral-600">{{ formatDate(stayValue(stay, 'check_in')) }} → {{ formatDate(stayValue(stay, 'check_out')) }}</td><td class="px-5 py-3"><Badge :variant="stayStatusVariants[stay.status] || 'neutral'" dot>{{ $t(`admin.guestProfileDesign.statuses.${stay.status}`) }}</Badge></td><td class="px-5 py-3 text-right text-body-sm font-bold text-primary-900">€{{ Number(stay.total ?? stay.total_amount).toFixed(2) }}</td></tr></tbody></table></div>
                    <p v-if="!stays.length" class="px-5 py-10 text-center text-body-sm text-neutral-500">{{ $t('admin.guestProfileDesign.noStays') }}</p>
                </Card>

                <Card v-if="history.length" :padding="false">
                    <template #header><div><h2 class="text-body font-bold text-primary-900">{{ $t('admin.guestProfileDesign.activity') }}</h2><p class="text-tiny text-neutral-400">{{ $t('admin.guestProfileDesign.activitySubtitle') }}</p></div></template>
                    <AuditTimeline :entries="history" />
                </Card>
                <Card v-else-if="activities.length" :padding="false">
                    <template #header><div><h2 class="text-body font-bold text-primary-900">{{ $t('admin.guestProfileDesign.activity') }}</h2><p class="text-tiny text-neutral-400">{{ $t('admin.guestProfileDesign.activitySubtitle') }}</p></div></template>
                    <div class="divide-y divide-neutral-100"><article v-for="activity in activities" :key="activity.id" class="flex gap-3 px-5 py-3"><span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-full" :class="activity.tone === 'success' ? 'bg-success-50 text-success-700' : 'bg-info-50 text-info-700'"><CheckCircle2 class="h-4 w-4" /></span><div><p class="text-body-sm font-semibold text-primary-900">{{ $t(`admin.guestProfileDesign.activities.${activity.title_key}`) }}</p><p class="mt-0.5 text-tiny text-neutral-400">{{ activity.meta }}</p></div></article></div>
                </Card>
            </div>
        </div>

        <Teleport to="body">
            <button v-if="showAiReview" type="button" class="fixed inset-0 z-40 cursor-default bg-primary-950/25 backdrop-blur-[1px]" :aria-label="$t('admin.guestProfileDesign.close')" @click="showAiReview = false" />
            <aside v-if="showAiReview" class="fixed inset-y-0 right-0 z-50 flex w-full max-w-2xl flex-col border-l border-neutral-200 bg-neutral-50 shadow-2xl" role="dialog" aria-modal="true" :aria-label="$t('admin.guestProfileDesign.aiReview')">
                <header class="border-b border-neutral-200 bg-white px-5 py-4"><div class="flex items-start justify-between gap-4"><div class="flex items-start gap-3"><span class="grid h-10 w-10 place-items-center rounded-xl bg-accent-100 text-accent-700"><Sparkles class="h-5 w-5" /></span><div><h2 class="text-h3 text-primary-900">{{ $t('admin.guestProfileDesign.aiReview') }}</h2><p class="mt-1 text-body-sm text-neutral-500">{{ $t('admin.guestProfileDesign.aiReviewSubtitle') }}</p></div></div><button type="button" class="grid h-9 w-9 place-items-center rounded-lg text-neutral-400 hover:bg-neutral-100" @click="showAiReview = false"><X class="h-5 w-5" /></button></div></header>
                <div class="min-h-0 flex-1 overflow-y-auto p-5">
                    <div class="flex items-start gap-3 rounded-xl border border-info-100 bg-info-50 p-3 text-body-sm text-info-800"><AlertCircle class="mt-0.5 h-4 w-4 shrink-0" /><p>{{ $t('admin.guestProfileDesign.aiSafety') }}</p></div>
                    <div class="mt-4 overflow-hidden rounded-xl border border-neutral-200 bg-white">
                        <div class="grid grid-cols-[36px_1fr_1fr_70px] gap-3 border-b border-neutral-200 bg-neutral-50 px-4 py-2 text-[10px] font-bold uppercase tracking-wide text-neutral-500"><span /><span>{{ $t('admin.guestProfileDesign.current') }}</span><span>{{ $t('admin.guestProfileDesign.extracted') }}</span><span>{{ $t('admin.guestProfileDesign.confidence') }}</span></div>
                        <label v-for="row in aiRows" :key="row.key" class="grid cursor-pointer grid-cols-[36px_1fr_1fr_70px] gap-3 border-b border-neutral-100 px-4 py-3 last:border-0 hover:bg-neutral-50" :class="!row.rawValue && 'opacity-50'">
                            <input type="checkbox" class="mt-1 rounded border-neutral-300 text-accent-600 focus:ring-accent-500" :disabled="!row.rawValue" :checked="selectedFields.includes(row.key)" @change="toggleField(row.key)" />
                            <div><p class="text-[10px] font-bold uppercase text-neutral-400">{{ $t(`admin.guestProfileDesign.fields.${row.key}`) }}</p><p class="mt-1 break-words text-body-sm text-neutral-600">{{ row.current }}</p></div>
                            <div><p class="text-[10px] font-bold uppercase text-accent-600">AI</p><p class="mt-1 break-words text-body-sm font-bold text-primary-900">{{ row.extracted }}</p></div>
                            <Badge :variant="row.confidence >= 98 ? 'success' : row.confidence >= 90 ? 'warning' : 'error'">{{ row.confidence }}%</Badge>
                        </label>
                    </div>
                </div>
                <footer class="flex flex-col-reverse gap-2 border-t border-neutral-200 bg-white p-4 sm:flex-row sm:justify-end"><Button variant="outline" @click="showAiReview = false">{{ $t('admin.guestProfileDesign.cancel') }}</Button><Button :loading="applying" :disabled="!selectedFields.length" @click="applyAiData"><CheckCircle2 class="h-4 w-4" />{{ $t('admin.guestProfileDesign.applySelected', { count: selectedFields.length }) }}</Button></footer>
            </aside>
        </Teleport>

        <ToastContainer ref="toasts" />
    </div>
</template>
