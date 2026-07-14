<script setup>
import { getIntlLocale, translate } from '@/i18n';
import axios from 'axios';
import { computed, ref, reactive, watch } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';

const props = defineProps({
    roomTypes: { type: Array, default: () => [] },
    seasons: { type: Array, default: () => [] },
    otaWindow: { type: Object, default: () => ({}) },
    seasonCopy: { type: Object, default: () => ({}) },
});

const toasts = ref(null);
const channelManagerEnabled = computed(() => usePage().props.modules?.channel_manager === true);

function formatDate(value) {
    if (!value) return '—';
    const [year, month, day] = String(value).split('-').map(Number);
    if (!year || !month || !day) return value;

    return new Intl.DateTimeFormat(getIntlLocale(), {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    }).format(new Date(year, month - 1, day));
}

function nextDate(value) {
    if (!value) return '';
    const [year, month, day] = String(value).split('-').map(Number);
    const date = new Date(year, month - 1, day);
    date.setDate(date.getDate() + 1);

    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-');
}

function apiError(error, fallback) {
    const errors = error?.response?.data?.errors;
    const firstError = errors && Object.values(errors).flat()[0];

    return firstError || error?.response?.data?.message || fallback;
}

function formatPrice(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) return '—';

    return new Intl.NumberFormat(getIntlLocale(), {
        style: 'currency',
        currency: 'EUR',
        minimumFractionDigits: Number.isInteger(number) ? 0 : 2,
    }).format(number);
}

// ---- OTA sell window ----
const showOtaWindow = ref(false);
const otaSellUntil = ref('');
const otaPreview = ref(null);
const otaPreviewing = ref(false);
const otaApplying = ref(false);
const otaConfirmed = ref(false);
const otaError = ref('');
const otaQueuedMessage = ref('');

const otaEffectiveUntil = computed(() => (
    props.otaWindow?.effective_until
    || props.otaWindow?.configured_until
    || props.otaWindow?.default_until
    || ''
));

const otaSyncPending = computed(() => Boolean(
    props.otaWindow?.configured_until
    && props.otaWindow?.applied_until !== props.otaWindow.configured_until
));

const otaActionLabel = computed(() => {
    const action = otaPreview.value?.action;
    if (['extend', 'open', 'opening'].includes(action)) return translate('admin.generated.k_b4c8a1084a37');
    if (['shorten', 'close', 'closing'].includes(action)) return translate('admin.generated.k_2b995441b5b2');
    if (action === 'pin') return translate('admin.generated.k_1f99602c48b2');
    return translate('admin.generated.k_441280c023cc');
});

function openOtaWindow() {
    otaSellUntil.value = otaEffectiveUntil.value;
    otaPreview.value = null;
    otaConfirmed.value = false;
    otaError.value = '';
    otaQueuedMessage.value = '';
    showOtaWindow.value = true;
}

function closeOtaWindow() {
    if (otaPreviewing.value || otaApplying.value) return;
    showOtaWindow.value = false;
}

async function previewOtaWindow() {
    if (otaPreviewing.value || otaApplying.value || !otaSellUntil.value) return;

    otaPreviewing.value = true;
    otaError.value = '';
    otaQueuedMessage.value = '';
    otaConfirmed.value = false;
    try {
        const { data } = await axios.post(route('channex.sell-window.preview'), {
            sell_until_date: otaSellUntil.value,
            expected_version: props.otaWindow?.version,
        });
        otaPreview.value = data.preview || data;
    } catch (error) {
        otaPreview.value = null;
        otaError.value = apiError(error, translate('admin.generated.k_da0913ef737a'));
    } finally {
        otaPreviewing.value = false;
    }
}

async function applyOtaWindow() {
    if (otaApplying.value || otaPreviewing.value || !otaPreview.value || !otaConfirmed.value) return;

    otaApplying.value = true;
    otaError.value = '';
    try {
        const { data } = await axios.put(route('channex.sell-window.update'), {
            sell_until_date: otaPreview.value.requested_until,
            expected_version: otaPreview.value.version,
            confirmed: true,
        });
        otaQueuedMessage.value = data.queued
            ? translate('admin.generated.k_692424e8f8e9')
            : translate('admin.generated.k_01d18896cdcd');
        otaConfirmed.value = false;
        otaPreview.value = null;
        toasts.value?.success(data.queued ? translate('admin.generated.k_d89c7da2c3ed') : translate('admin.generated.k_f664cdee4af2'));
        router.reload({ only: ['otaWindow'], preserveScroll: true });
    } catch (error) {
        otaError.value = apiError(error, translate('admin.generated.k_f6bf47397ab3'));
        if (error?.response?.status === 409) {
            otaPreview.value = null;
            otaConfirmed.value = false;
        }
    } finally {
        otaApplying.value = false;
    }
}

watch(otaSellUntil, () => {
    otaPreview.value = null;
    otaConfirmed.value = false;
    otaError.value = '';
    otaQueuedMessage.value = '';
});

// ---- Copy seasons to another year ----
const showSeasonCopy = ref(false);
const copySourceYear = ref('');
const copyTargetYear = ref('');
const copyUplift = ref(0);
const copyPreview = ref(null);
const copyPreviewing = ref(false);
const copyApplying = ref(false);
const copyConfirmed = ref(false);
const copyError = ref('');
const copyAppliedMessage = ref('');
const copyUpliftValid = computed(() => {
    const value = Number(copyUplift.value);

    return copyUplift.value !== '' && Number.isFinite(value) && value >= -50 && value <= 100;
});

const sourceYearOptions = computed(() => {
    const years = props.seasonCopy?.source_years?.length
        ? props.seasonCopy.source_years
        : props.seasons.map((season) => Number(String(season.start_date).slice(0, 4)));

    return [...new Set(years.map(Number).filter(Number.isFinite))].sort((a, b) => b - a);
});

const targetYearOptions = computed(() => {
    const years = new Set();
    const currentYear = new Date().getFullYear();
    const defaultTarget = Number(props.seasonCopy?.default_target_year);
    if (Number.isFinite(defaultTarget)) years.add(defaultTarget);
    sourceYearOptions.value.forEach((year) => years.add(year + 1));
    for (let year = currentYear; year <= currentYear + 5; year += 1) years.add(year);

    return [...years].sort((a, b) => a - b);
});

function openSeasonCopy() {
    copySourceYear.value = String(
        props.seasonCopy?.default_source_year
        || sourceYearOptions.value[0]
        || '',
    );
    copyTargetYear.value = String(
        props.seasonCopy?.default_target_year
        || (Number(copySourceYear.value) + 1)
        || '',
    );
    copyUplift.value = 0;
    copyPreview.value = null;
    copyConfirmed.value = false;
    copyError.value = '';
    copyAppliedMessage.value = '';
    showSeasonCopy.value = true;
}

function closeSeasonCopy() {
    if (copyPreviewing.value || copyApplying.value) return;
    showSeasonCopy.value = false;
}

async function previewSeasonCopy() {
    if (copyPreviewing.value || copyApplying.value || !copySourceYear.value || !copyTargetYear.value) return;

    copyPreviewing.value = true;
    copyError.value = '';
    copyAppliedMessage.value = '';
    copyConfirmed.value = false;
    try {
        const { data } = await axios.post(route('pricing.seasons.copy.preview'), {
            source_year: Number(copySourceYear.value),
            target_year: Number(copyTargetYear.value),
            uplift_pct: Number(copyUplift.value || 0),
        });
        copyPreview.value = data.preview || data;
    } catch (error) {
        copyPreview.value = null;
        copyError.value = apiError(error, translate('admin.generated.k_26d25079cd20'));
    } finally {
        copyPreviewing.value = false;
    }
}

async function applySeasonCopy() {
    if (
        copyApplying.value
        || copyPreviewing.value
        || copyPreview.value?.state !== 'ready'
        || !copyConfirmed.value
    ) return;

    copyApplying.value = true;
    copyError.value = '';
    try {
        const { data } = await axios.post(route('pricing.seasons.copy.apply'), {
            source_year: copyPreview.value.source_year,
            target_year: copyPreview.value.target_year,
            uplift_pct: copyPreview.value.uplift_pct,
            rules_version: copyPreview.value.rules_version,
            preview_hash: copyPreview.value.preview_hash,
            confirmed: true,
        });
        const syncQueued = data.sync_queued !== false;
        copyAppliedMessage.value = syncQueued
            ? translate('admin.generated.k_1cd31cc43a6e')
            : translate('admin.generated.k_affa8b0d31e6');
        copyConfirmed.value = false;
        copyPreview.value = null;
        if (syncQueued) {
            toasts.value?.success(translate('admin.generated.k_56aec18a067c'));
        } else {
            toasts.value?.warning(translate('admin.generated.k_a92dd97d5553'));
        }
        router.reload({ only: ['seasons', 'seasonCopy'], preserveScroll: true });
    } catch (error) {
        copyError.value = apiError(error, translate('admin.generated.k_fb3087b9a19f'));
        if ([409, 422].includes(error?.response?.status)) {
            copyPreview.value = null;
            copyConfirmed.value = false;
        }
    } finally {
        copyApplying.value = false;
    }
}

watch([copySourceYear, copyTargetYear, copyUplift], () => {
    copyPreview.value = null;
    copyConfirmed.value = false;
    copyError.value = '';
    copyAppliedMessage.value = '';
});

// ---- Price matrix (base + per-season) ----
const base = reactive({});
const rates = reactive({});

function buildMatrix() {
    props.roomTypes.forEach((t) => { base[t.id] = t.base_price ?? ''; });
    props.seasons.forEach((s) => {
        rates[s.id] = rates[s.id] || {};
        props.roomTypes.forEach((t) => {
            const v = s.rates?.[t.id];
            rates[s.id][t.id] = (v === undefined || v === null) ? '' : v;
        });
    });
    // drop seasons that no longer exist
    Object.keys(rates).forEach((sid) => {
        if (!props.seasons.some((s) => String(s.id) === String(sid))) delete rates[sid];
    });
}
buildMatrix();
watch(() => [props.roomTypes, props.seasons], buildMatrix);

const savingRates = ref(false);
function saveRates() {
    savingRates.value = true;
    router.post(route('pricing.rates.save'), { base, rates }, {
        preserveScroll: true,
        onSuccess: () => toasts.value?.success(translate('admin.generated.k_50b93c41a367')),
        onFinish: () => { savingRates.value = false; },
    });
}

// ---- Seasons CRUD ----
const showSeason = ref(false);
const editingSeason = ref(null);
const syncing = ref(false);
function syncChannex() {
    syncing.value = true;
    router.post(route('channex.sync'), {}, {
        preserveScroll: true,
        onSuccess: () => {
            const flash = usePage().props.flash || {};
            if (flash.error) toasts.value?.error(flash.error);
            else toasts.value?.success(flash.success || 'Sinkronizimi u nis.');
        },
        onError: () => toasts.value?.error(translate('admin.generated.k_a770c9461aa5')),
        onFinish: () => { syncing.value = false; },
    });
}

const sform = useForm({ name: '', start_date: '', end_date: '', priority: 0 });

function openCreateSeason() {
    editingSeason.value = null;
    sform.reset();
    sform.clearErrors();
    showSeason.value = true;
}
function openEditSeason(s) {
    editingSeason.value = s;
    sform.name = s.name;
    sform.start_date = s.start_date;
    sform.end_date = s.end_date;
    sform.priority = s.priority;
    sform.clearErrors();
    showSeason.value = true;
}
function submitSeason() {
    const opts = {
        preserveScroll: true,
        onSuccess: () => { showSeason.value = false; toasts.value?.success(translate('admin.generated.k_d79213323d87')); },
    };
    if (editingSeason.value) {
        sform.put(route('pricing.seasons.update', editingSeason.value.id), opts);
    } else {
        sform.post(route('pricing.seasons.store'), opts);
    }
}
function deleteSeason(s) {
    if (!confirm(`Fshi sezonin "${s.name}"? (cmimet e tij do hiqen)`)) return;
    router.delete(route('pricing.seasons.destroy', s.id), {
        preserveScroll: true,
        onSuccess: () => toasts.value?.success(translate('admin.generated.k_6031e3e37904')),
    });
}

function fmtRange(s) {
    return `${s.start_date} → ${s.end_date}`;
}
</script>

<template>
    <AppLayout>
        <PageHeader
            :title="$t('admin.generated.k_41ef039c5194')"
            :breadcrumbs="[{ label: $t('admin.generated.k_f226794ce976'), href: '/dashboard' }, { label: $t('admin.generated.k_9cccd7468e78') }]"
        />

        <div class="mt-6 space-y-6">
            <!-- Channel manager (Channex) -->
            <Card v-if="channelManagerEnabled">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <h3 class="text-h4 text-primary-900">{{ $t('admin.generated.k_5786b449b811') }}</h3>
                        <p class="text-small text-neutral-500 mt-0.5">{{ $t('admin.generated.k_18d8a171be34') }}</p>
                    </div>
                    <div class="flex shrink-0 flex-col gap-2 sm:flex-row">
                        <Button variant="outline" @click="openOtaWindow">
{{ $t('admin.generated.k_3c46c19034c7') }} </Button>
                        <Button variant="secondary" :disabled="syncing" @click="syncChannex">
                            {{ syncing ? $t('admin.generated.k_270ec5ff9530') : $t('admin.generated.k_bb12cb3fbb4c') }}
                        </Button>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 rounded-lg border border-neutral-200 bg-neutral-50 p-4 sm:grid-cols-3">
                    <div>
                        <p class="text-tiny font-medium uppercase tracking-wide text-neutral-500">{{ $t('admin.generated.k_bcbc2e972c63') }}</p>
                        <p class="mt-1 text-body-sm font-semibold text-primary-900">{{ formatDate(otaEffectiveUntil) }}</p>
                    </div>
                    <div>
                        <p class="text-tiny font-medium uppercase tracking-wide text-neutral-500">{{ $t('admin.generated.k_e74c4fbdf8d7') }}</p>
                        <p class="mt-1 text-body-sm font-semibold text-primary-900">{{ formatDate(nextDate(otaEffectiveUntil)) }}</p>
                    </div>
                    <div>
                        <p class="text-tiny font-medium uppercase tracking-wide text-neutral-500">{{ $t('admin.generated.k_51ddb459ca05') }}</p>
                        <p class="mt-1 text-body-sm font-semibold text-primary-900">
                            {{ otaWindow.configured_until ? $t('admin.generated.k_85bf551587e9') : $t('admin.generated.k_ac3136e75c30') }}
                        </p>
                    </div>
                </div>

                <div
                    v-if="otaSyncPending"
                    class="mt-3 rounded-md border border-warning-200 bg-warning-50 px-3 py-2 text-small text-warning-800"
                    role="status"
                >
                    <strong>{{ $t('admin.generated.k_ae248b23fa5d') }}</strong>
                    <span v-if="otaWindow.applied_until"> {{ $t('admin.generated.k_4f0dd3b2e4ca') }} {{ formatDate(otaWindow.applied_until) }}.</span>
{{ $t('admin.generated.k_c82352eecc28') }} </div>
                <div class="mt-3 rounded-md border border-warning-200 bg-warning-50 px-3 py-2 text-small text-warning-800">
                    <strong>{{ $t('admin.generated.k_edc684fd7b7a') }}</strong> {{ $t('admin.generated.k_86cf0aa1def8') }} </div>
            </Card>

            <!-- Seasons -->
            <Card>
                <template #header>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <h3 class="text-h4 text-primary-900">{{ $t('admin.generated.k_b393cba9fdfc') }}</h3>
                            <p class="text-small text-neutral-500 mt-0.5">{{ $t('admin.generated.k_d2d5790eb313') }}</p>
                        </div>
                        <div class="flex shrink-0 flex-col gap-2 sm:flex-row">
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="!sourceYearOptions.length"
                                @click="openSeasonCopy"
                            >
{{ $t('admin.generated.k_05a62a33ee40') }} </Button>
                            <Button size="sm" variant="primary" @click="openCreateSeason">{{ $t('admin.generated.k_b5b2a800444e') }}</Button>
                        </div>
                    </div>
                </template>

                <div class="divide-y divide-neutral-100">
                    <div v-for="s in seasons" :key="s.id" class="py-3 flex items-center gap-4">
                        <div class="flex-1 min-w-0">
                            <p class="text-body-sm font-medium text-primary-900">{{ s.name }}</p>
                            <p class="text-small text-neutral-500">{{ fmtRange(s) }} {{ $t('admin.generated.k_c0857a9c44ab') }} {{ s.priority }}</p>
                        </div>
                        <Button size="sm" variant="ghost" @click="openEditSeason(s)">{{ $t('admin.generated.k_0814d8529e68') }}</Button>
                        <Button size="sm" variant="ghost" class="text-error-600" @click="deleteSeason(s)">{{ $t('admin.generated.k_315a0d2b6347') }}</Button>
                    </div>
                    <div v-if="!seasons.length" class="py-6 text-center text-body-sm text-neutral-500">
{{ $t('admin.generated.k_1974bcb2ef98') }} </div>
                </div>
            </Card>

            <!-- Price matrix -->
            <Card>
                <template #header>
                    <div>
                        <h3 class="text-h4 text-primary-900">{{ $t('admin.generated.k_26e2b820adef') }}</h3>
                        <p class="text-small text-neutral-500 mt-0.5">{{ $t('admin.generated.k_36dda3961dee') }}</p>
                    </div>
                </template>

                <div class="overflow-x-auto">
                    <table class="w-full text-body-sm">
                        <thead>
                            <tr class="border-b border-neutral-200">
                                <th class="px-3 py-2 text-left text-label text-neutral-600">{{ $t('admin.generated.k_a6aa7eff1daa') }}</th>
                                <th class="px-3 py-2 text-left text-label text-neutral-600 whitespace-nowrap">{{ $t('admin.generated.k_17da06e5a5dd') }}</th>
                                <th v-for="s in seasons" :key="s.id" class="px-3 py-2 text-left text-label text-neutral-600 whitespace-nowrap">
                                    {{ s.name }} (€)
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="t in roomTypes" :key="t.id">
                                <td class="px-3 py-2 font-medium text-primary-900 whitespace-nowrap">{{ t.name }}</td>
                                <td class="px-3 py-2">
                                    <input v-model="base[t.id]" type="number" min="0" step="1"
                                        class="w-24 rounded-md border border-neutral-300 px-2 py-1.5 text-body-sm focus:border-accent-500 focus:ring-2 focus:ring-accent-500/40" />
                                </td>
                                <td v-for="s in seasons" :key="s.id" class="px-3 py-2">
                                    <input v-if="rates[s.id]" v-model="rates[s.id][t.id]" type="number" min="0" step="1"
                                        :placeholder="String(base[t.id] ?? '')"
                                        class="w-24 rounded-md border border-neutral-300 px-2 py-1.5 text-body-sm focus:border-accent-500 focus:ring-2 focus:ring-accent-500/40" />
                                </td>
                            </tr>
                            <tr v-if="!roomTypes.length">
                                <td :colspan="2 + seasons.length" class="px-3 py-6 text-center text-neutral-500">
{{ $t('admin.generated.k_62e357276d24') }} </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-end mt-4">
                    <Button variant="primary" :loading="savingRates" @click="saveRates">{{ $t('admin.generated.k_f5e21afe68a5') }}</Button>
                </div>
            </Card>
        </div>

        <!-- Season modal -->
        <Modal :show="showSeason" :title="editingSeason ? $t('admin.generated.k_2b26c6dc5ef0') : $t('admin.generated.k_654a85c2d207')" @close="showSeason = false">
            <form @submit.prevent="submitSeason" class="space-y-4">
                <FormGroup :label="$t('admin.generated.k_51a0c7aadeb7')" :error="sform.errors.name" required>
                    <TextInput v-model="sform.name" :placeholder="$t('admin.generated.k_ba5b2b430ece')" :error="sform.errors.name" />
                </FormGroup>
                <div class="grid grid-cols-2 gap-4">
                    <FormGroup :label="$t('admin.generated.k_8b9d783e781c')" :error="sform.errors.start_date" required>
                        <DatePicker v-model="sform.start_date" :error="sform.errors.start_date" />
                    </FormGroup>
                    <FormGroup :label="$t('admin.generated.k_15cdee011901')" :error="sform.errors.end_date" required>
                        <DatePicker v-model="sform.end_date" :error="sform.errors.end_date" />
                    </FormGroup>
                </div>
                <FormGroup :label="$t('admin.generated.k_2bd87c03f9dd')" :error="sform.errors.priority" required>
                    <TextInput type="number" v-model="sform.priority" min="0" max="1000" />
                    <p class="text-tiny text-neutral-400 mt-1">{{ $t('admin.generated.k_90569615eda2') }}</p>
                </FormGroup>
            </form>
            <template #footer>
                <Button variant="outline" @click="showSeason = false">{{ $t('admin.generated.k_b59ae1e356c9') }}</Button>
                <Button variant="primary" :loading="sform.processing" @click="submitSeason">{{ editingSeason ? $t('admin.generated.k_4e4180955a51') : $t('admin.generated.k_fa00ecca163b') }}</Button>
            </template>
        </Modal>

        <!-- OTA sell-window modal -->
        <Modal
            :show="showOtaWindow"
            :title="$t('admin.generated.k_d1e9a2a6b2ca')"
            max-width="xl"
            :closeable="!otaPreviewing && !otaApplying"
            @close="closeOtaWindow"
        >
            <div class="space-y-4">
                <div class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-body-sm text-warning-800">
                    <p class="font-semibold">{{ $t('admin.generated.k_93ba7f15c952') }}</p>
                    <p class="mt-1">{{ $t('admin.generated.k_1234a2426913') }} <strong>{{ $t('admin.generated.k_19445548ff03') }}</strong>{{ $t('admin.generated.k_ae22191a964b') }}</p>
                </div>

                <FormGroup :label="$t('admin.generated.k_8d1115767601')" html-for="ota-sell-until" required>
                    <DatePicker
                        id="ota-sell-until"
                        v-model="otaSellUntil"
                        :min="otaWindow.min_date || ''"
                        :max="otaWindow.max_date || ''"
                        :disabled="otaPreviewing || otaApplying"
                    />
                    <p class="mt-1 text-small text-neutral-500">
{{ $t('admin.generated.k_a368fdc8bbca') }} <strong>{{ formatDate(nextDate(otaSellUntil)) }}</strong>.
                    </p>
                    <p class="mt-1 text-small text-neutral-500">
{{ $t('admin.generated.k_422cefa4e409') }} {{ otaWindow.max_days || 500 }} {{ $t('admin.generated.k_144850fc6e94') }} </p>
                </FormGroup>

                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-small text-neutral-500">{{ $t('admin.generated.k_f64af9b008ee') }}</p>
                    <Button
                        variant="outline"
                        :loading="otaPreviewing"
                        :disabled="otaApplying || !otaSellUntil"
                        @click="previewOtaWindow"
                    >
{{ $t('admin.generated.k_4c7056fa08e1') }} </Button>
                </div>

                <p v-if="otaError" class="rounded-md bg-error-50 px-3 py-2 text-body-sm text-error-700" role="alert">
                    {{ otaError }}
                </p>
                <div
                    v-if="otaQueuedMessage"
                    class="rounded-md border border-success-200 bg-success-50 px-3 py-2 text-body-sm text-success-800"
                    role="status"
                    aria-live="polite"
                >
                    <strong>{{ $t('admin.generated.k_7df7c73cf05e') }}</strong> {{ otaQueuedMessage }}
                </div>

                <section v-if="otaPreview" class="space-y-4" aria-labelledby="ota-preview-title">
                    <div class="border-t border-neutral-200 pt-4">
                        <h4 id="ota-preview-title" class="text-body font-semibold text-primary-900">{{ $t('admin.generated.k_0b9aded31b54') }}</h4>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-md border border-neutral-200 p-3">
                            <p class="text-tiny uppercase tracking-wide text-neutral-500">{{ $t('admin.generated.k_28b692a38c73') }}</p>
                            <p class="mt-1 text-body-sm font-semibold text-primary-900">{{ formatDate(otaPreview.current_until) }}</p>
                        </div>
                        <div class="rounded-md border border-neutral-200 p-3">
                            <p class="text-tiny uppercase tracking-wide text-neutral-500">{{ $t('admin.generated.k_55d3739014b5') }}</p>
                            <p class="mt-1 text-body-sm font-semibold text-primary-900">{{ formatDate(otaPreview.requested_until) }}</p>
                        </div>
                        <div class="rounded-md border border-neutral-200 p-3">
                            <p class="text-tiny uppercase tracking-wide text-neutral-500">{{ $t('admin.generated.k_f15aab88ce38') }}</p>
                            <p class="mt-1 text-body-sm font-semibold text-primary-900">{{ otaActionLabel }}</p>
                        </div>
                        <div class="rounded-md border border-neutral-200 p-3">
                            <p class="text-tiny uppercase tracking-wide text-neutral-500">{{ $t('admin.generated.k_0a87fff64845') }}</p>
                            <p class="mt-1 text-body-sm font-semibold text-primary-900">{{ otaPreview.nights || 0 }}</p>
                        </div>
                    </div>

                    <div
                        v-if="otaPreview.action === 'pin'"
                        class="rounded-md border border-neutral-200 bg-neutral-50 p-3 text-body-sm text-neutral-700"
                    >
{{ $t('admin.generated.k_3475d84a38ae') }} </div>
                    <div v-else class="rounded-md border border-neutral-200 bg-neutral-50 p-3 text-body-sm text-neutral-700">
                        <p>
{{ $t('admin.generated.k_c906b2573e50') }} <strong>{{ formatDate(otaPreview.range_from) }} → {{ formatDate(otaPreview.range_to) }}</strong>
                        </p>
                        <p class="mt-1">{{ $t('admin.generated.k_6ac5de0acc4f') }} {{ otaPreview.room_type_count || otaWindow.room_type_count || 0 }} {{ $t('admin.generated.k_3db1f498adfd') }}</p>
                    </div>

                    <div class="rounded-md border border-primary-100 bg-primary-50 p-3 text-body-sm text-primary-800">
{{ $t('admin.generated.k_a62b5521162d') }} </div>

                    <label
                        v-if="otaPreview.action !== 'unchanged'"
                        for="confirm-ota-window"
                        class="flex cursor-pointer items-start gap-3 rounded-md border border-neutral-200 p-3 text-body-sm text-neutral-700"
                    >
                        <input
                            id="confirm-ota-window"
                            v-model="otaConfirmed"
                            type="checkbox"
                            class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-accent-600 focus:ring-accent-500"
                            :disabled="otaApplying"
                        />
                        <span>{{ $t('admin.generated.k_e4d1640ce3ac') }}</span>
                    </label>
                    <p v-else class="rounded-md bg-neutral-100 px-3 py-2 text-body-sm text-neutral-600">
{{ $t('admin.generated.k_0dd0bd8718e5') }} </p>
                </section>
            </div>

            <template #footer>
                <div class="flex w-full flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <Button variant="outline" :disabled="otaPreviewing || otaApplying" @click="closeOtaWindow">{{ $t('admin.generated.k_c46815d171c9') }}</Button>
                    <Button
                        v-if="otaPreview"
                        variant="primary"
                        :loading="otaApplying"
                        :disabled="otaPreviewing || !otaConfirmed || otaPreview.action === 'unchanged'"
                        @click="applyOtaWindow"
                    >
{{ $t('admin.generated.k_d002089c394f') }} </Button>
                </div>
            </template>
        </Modal>

        <!-- Copy seasons modal -->
        <Modal
            :show="showSeasonCopy"
            :title="$t('admin.generated.k_12318b964d42')"
            max-width="2xl"
            :closeable="!copyPreviewing && !copyApplying"
            @close="closeSeasonCopy"
        >
            <div class="space-y-4">
                <p class="text-body-sm text-neutral-600">
{{ $t('admin.generated.k_e33a2246f9b6') }} </p>

                <div class="grid gap-4 sm:grid-cols-3">
                    <FormGroup :label="$t('admin.generated.k_2edd8048f300')" html-for="copy-source-year" required>
                        <select
                            id="copy-source-year"
                            v-model="copySourceYear"
                            class="block w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-body-sm text-neutral-900 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/40"
                            :disabled="copyPreviewing || copyApplying"
                        >
                            <option value="" disabled>{{ $t('admin.generated.k_724357c69c7e') }}</option>
                            <option v-for="year in sourceYearOptions" :key="year" :value="String(year)">{{ year }}</option>
                        </select>
                    </FormGroup>

                    <FormGroup :label="$t('admin.generated.k_e601ec61e4a0')" html-for="copy-target-year" required>
                        <select
                            id="copy-target-year"
                            v-model="copyTargetYear"
                            class="block w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-body-sm text-neutral-900 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/40"
                            :disabled="copyPreviewing || copyApplying"
                        >
                            <option value="" disabled>{{ $t('admin.generated.k_724357c69c7e') }}</option>
                            <option v-for="year in targetYearOptions" :key="year" :value="String(year)">{{ year }}</option>
                        </select>
                    </FormGroup>

                    <FormGroup :label="$t('admin.generated.k_a192f9ca0360')" html-for="copy-uplift" required>
                        <TextInput
                            id="copy-uplift"
                            v-model="copyUplift"
                            type="number"
                            min="-50"
                            max="100"
                            step="0.1"
                            :disabled="copyPreviewing || copyApplying"
                        />
                    </FormGroup>
                </div>

                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-small text-neutral-500">{{ $t('admin.generated.k_f66c095dd7dc') }}</p>
                    <Button
                        variant="outline"
                        :loading="copyPreviewing"
                        :disabled="copyApplying || !copySourceYear || !copyTargetYear || !copyUpliftValid"
                        @click="previewSeasonCopy"
                    >
{{ $t('admin.generated.k_0d1aafac5a6a') }} </Button>
                </div>

                <p v-if="copyError" class="rounded-md bg-error-50 px-3 py-2 text-body-sm text-error-700" role="alert">
                    {{ copyError }}
                </p>
                <p
                    v-if="copyAppliedMessage"
                    class="rounded-md border border-success-200 bg-success-50 px-3 py-2 text-body-sm text-success-800"
                    role="status"
                    aria-live="polite"
                >
                    {{ copyAppliedMessage }}
                </p>

                <section v-if="copyPreview" class="space-y-4" aria-labelledby="season-copy-preview-title">
                    <div class="border-t border-neutral-200 pt-4">
                        <h4 id="season-copy-preview-title" class="text-body font-semibold text-primary-900">
                            {{ copyPreview.source_year }} → {{ copyPreview.target_year }}
                            <span class="font-normal text-neutral-500">({{ Number(copyPreview.uplift_pct) >= 0 ? '+' : '' }}{{ copyPreview.uplift_pct }}%)</span>
                        </h4>
                        <p class="mt-1 text-small text-neutral-500">
{{ $t('admin.generated.k_d041f1d66f84') }} {{ formatDate(copyPreview.ota_publish_until) }}.
                        </p>
                    </div>

                    <div
                        v-if="copyPreview.override_count > 0"
                        class="rounded-md border border-warning-200 bg-warning-50 p-3 text-body-sm text-warning-800"
                        role="alert"
                    >
                        <strong>{{ $t('admin.generated.k_b0e6552ff225') }}</strong> {{ $t('admin.generated.k_5381f98b2fa9') }} {{ copyPreview.override_count }} {{ $t('admin.generated.k_d382781187ec') }} </div>

                    <p
                        v-if="copyPreview.state === 'no_changes'"
                        class="rounded-md bg-neutral-100 px-3 py-2 text-body-sm text-neutral-600"
                        role="status"
                    >
{{ $t('admin.generated.k_140af4abe277') }} {{ copyPreview.target_year }}{{ $t('admin.generated.k_3c8a6f37a655') }} </p>

                    <div
                        v-if="copyPreview.conflicts?.length"
                        class="rounded-md border border-error-200 bg-error-50 p-3 text-body-sm text-error-800"
                    >
                        <p class="font-semibold">{{ $t('admin.generated.k_e3e3e800bb90') }}</p>
                        <ul class="mt-1 list-disc space-y-1 pl-5">
                            <li v-for="(conflict, index) in copyPreview.conflicts" :key="index">{{ conflict }}</li>
                        </ul>
                    </div>

                    <div v-if="copyPreview.seasons?.length" class="space-y-3">
                        <article
                            v-for="season in copyPreview.seasons"
                            :key="season.source_season_id"
                            class="overflow-hidden rounded-lg border border-neutral-200"
                        >
                            <div class="bg-neutral-50 px-4 py-3">
                                <p class="text-body-sm font-semibold text-primary-900">
                                    {{ season.source_name }} → {{ season.target_name }}
                                </p>
                                <p class="mt-0.5 text-small text-neutral-500">
                                    {{ formatDate(season.start_date) }} → {{ formatDate(season.end_date) }} {{ $t('admin.generated.k_c0857a9c44ab') }} {{ season.priority }}
                                </p>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full min-w-[480px] text-body-sm">
                                    <thead>
                                        <tr class="border-b border-neutral-200 text-left text-label text-neutral-600">
                                            <th class="px-4 py-2">{{ $t('admin.generated.k_5c01262e5337') }}</th>
                                            <th class="px-4 py-2">{{ $t('admin.generated.k_72d62739f21f') }} {{ copyPreview.source_year }}</th>
                                            <th class="px-4 py-2">{{ $t('admin.generated.k_72d62739f21f') }} {{ copyPreview.target_year }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-neutral-100">
                                        <tr v-for="rate in season.rates" :key="rate.room_type_id">
                                            <td class="px-4 py-2 font-medium text-primary-900">{{ rate.room_type_name }}</td>
                                            <td class="px-4 py-2 text-neutral-700">
                                                {{ formatPrice(rate.source_price) }}
                                                <span class="block text-tiny text-neutral-400">
                                                    {{ rate.source_kind === 'base' ? $t('admin.generated.k_caf380b323f3') : $t('admin.generated.k_afee019b2ecb') }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-2 font-semibold text-primary-900">{{ formatPrice(rate.target_price) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </article>
                    </div>
                    <p v-else class="rounded-md bg-neutral-100 px-3 py-2 text-body-sm text-neutral-600">
{{ $t('admin.generated.k_725effbdcb04') }} {{ copyPreview.source_year }}.
                    </p>

                    <label
                        v-if="copyPreview.state === 'ready' && copyPreview.seasons?.length"
                        for="confirm-season-copy"
                        class="flex cursor-pointer items-start gap-3 rounded-md border border-warning-200 bg-warning-50 p-3 text-body-sm text-warning-900"
                    >
                        <input
                            id="confirm-season-copy"
                            v-model="copyConfirmed"
                            type="checkbox"
                            class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-accent-600 focus:ring-accent-500"
                            :disabled="copyApplying"
                        />
                        <span>{{ $t('admin.generated.k_706bd8877d36') }}</span>
                    </label>
                </section>
            </div>

            <template #footer>
                <div class="flex w-full flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <Button variant="outline" :disabled="copyPreviewing || copyApplying" @click="closeSeasonCopy">{{ $t('admin.generated.k_c46815d171c9') }}</Button>
                    <Button
                        v-if="copyPreview?.state === 'ready' && copyPreview?.seasons?.length"
                        variant="primary"
                        :loading="copyApplying"
                        :disabled="copyPreviewing || !copyConfirmed"
                        @click="applySeasonCopy"
                    >
{{ $t('admin.generated.k_6c319fb9adff') }} </Button>
                </div>
            </template>
        </Modal>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
