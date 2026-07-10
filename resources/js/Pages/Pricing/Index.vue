<script setup>
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

function formatDate(value) {
    if (!value) return '—';
    const [year, month, day] = String(value).split('-').map(Number);
    if (!year || !month || !day) return value;

    return new Intl.DateTimeFormat('sq-AL', {
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

    return new Intl.NumberFormat('sq-AL', {
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
    if (['extend', 'open', 'opening'].includes(action)) return 'Hapen net të reja për rezervim';
    if (['shorten', 'close', 'closing'].includes(action)) return 'Mbyllen netët pas datës së re';
    if (action === 'pin') return 'Data fiksohet dhe nuk lëviz më automatikisht';
    return 'Nuk ndryshon asnjë natë';
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
        otaError.value = apiError(error, 'Nuk u krijua dot kontrolli paraprak. Provo përsëri.');
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
            ? 'Sinkronizimi me Channex po nis; ende nuk quhet i përfunduar.'
            : 'Data ishte tashmë e vendosur dhe nuk u nis një sinkronizim i ri.';
        otaConfirmed.value = false;
        otaPreview.value = null;
        toasts.value?.success(data.queued ? 'Kërkesa për Channex u vendos në radhë.' : 'Nuk kishte ndryshim të ri.');
        router.reload({ only: ['otaWindow'], preserveScroll: true });
    } catch (error) {
        otaError.value = apiError(error, 'Ndryshimi nuk u nis. Rifresko kontrollin paraprak dhe provo përsëri.');
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
        copyError.value = apiError(error, 'Nuk u krijua dot kontrolli paraprak i sezoneve.');
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
            ? 'Sezonet u kopjuan dhe përditësimi për Channex u vendos në radhë.'
            : 'Sezonet u kopjuan në PMS, por sinkronizimi me Channex nuk u vendos në radhë. Përdor “Sinkronizo tani” para se t’i konsiderosh çmimet aktive në OTA.';
        copyConfirmed.value = false;
        copyPreview.value = null;
        if (syncQueued) {
            toasts.value?.success('Sezonet u kopjuan me sukses.');
        } else {
            toasts.value?.warning('Sezonet u ruajtën; sinkronizimi me Channex duhet riprovuar.');
        }
        router.reload({ only: ['seasons', 'seasonCopy'], preserveScroll: true });
    } catch (error) {
        copyError.value = apiError(error, 'Sezonet nuk u kopjuan. Rifresko kontrollin paraprak dhe provo përsëri.');
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
        onSuccess: () => toasts.value?.success('Cmimet u ruajten.'),
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
        onError: () => toasts.value?.error('Sinkronizimi deshtoi.'),
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
        onSuccess: () => { showSeason.value = false; toasts.value?.success('U ruajt.'); },
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
        onSuccess: () => toasts.value?.success('Sezoni u fshi.'),
    });
}

function fmtRange(s) {
    return `${s.start_date} → ${s.end_date}`;
}
</script>

<template>
    <AppLayout>
        <PageHeader
            title="Cmimet"
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Cmimet' }]"
        />

        <div class="mt-6 space-y-6">
            <!-- Channel manager (Channex) -->
            <Card>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <h3 class="text-h4 text-primary-900">Channel Manager (Channex)</h3>
                        <p class="text-small text-neutral-500 mt-0.5">Çmimet dhe dhomat e lira shkojnë vetvetiu te Channex dhe OTA-të. Mund të zgjedhësh datën e fundit që pranon rezervime.</p>
                    </div>
                    <div class="flex shrink-0 flex-col gap-2 sm:flex-row">
                        <Button variant="outline" @click="openOtaWindow">
                            Ndrysho datën e OTA-ve
                        </Button>
                        <Button variant="secondary" :disabled="syncing" @click="syncChannex">
                            {{ syncing ? 'Po sinkronizohet…' : 'Sinkronizo tani' }}
                        </Button>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 rounded-lg border border-neutral-200 bg-neutral-50 p-4 sm:grid-cols-3">
                    <div>
                        <p class="text-tiny font-medium uppercase tracking-wide text-neutral-500">Nata e fundit e konfiguruar</p>
                        <p class="mt-1 text-body-sm font-semibold text-primary-900">{{ formatDate(otaEffectiveUntil) }}</p>
                    </div>
                    <div>
                        <p class="text-tiny font-medium uppercase tracking-wide text-neutral-500">Checkout i fundit i mundshëm</p>
                        <p class="mt-1 text-body-sm font-semibold text-primary-900">{{ formatDate(nextDate(otaEffectiveUntil)) }}</p>
                    </div>
                    <div>
                        <p class="text-tiny font-medium uppercase tracking-wide text-neutral-500">Mënyra</p>
                        <p class="mt-1 text-body-sm font-semibold text-primary-900">
                            {{ otaWindow.configured_until ? 'Datë e zgjedhur nga ti' : 'Dritare automatike' }}
                        </p>
                    </div>
                </div>

                <div
                    v-if="otaSyncPending"
                    class="mt-3 rounded-md border border-warning-200 bg-warning-50 px-3 py-2 text-small text-warning-800"
                    role="status"
                >
                    <strong>Ndryshimi nuk është konfirmuar ende nga procesi i sinkronizimit.</strong>
                    <span v-if="otaWindow.applied_until"> Channex kishte të zbatuar deri më {{ formatDate(otaWindow.applied_until) }}.</span>
                    Mos e konsidero datën e re aktive në OTA derisa ky njoftim të zhduket.
                </div>
                <div class="mt-3 rounded-md border border-warning-200 bg-warning-50 px-3 py-2 text-small text-warning-800">
                    <strong>Channex është LIVE.</strong> Data nuk ndryshon vetëm duke e zgjedhur; fillimisht shikon ndikimin dhe pastaj e konfirmon qartë.
                </div>
            </Card>

            <!-- Seasons -->
            <Card>
                <template #header>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <h3 class="text-h4 text-primary-900">Sezonet</h3>
                            <p class="text-small text-neutral-500 mt-0.5">Periudha datash me çmime të ndryshme. Mund të kopjosh një vit te viti tjetër dhe të shtosh një rritje përqindjeje.</p>
                        </div>
                        <div class="flex shrink-0 flex-col gap-2 sm:flex-row">
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="!sourceYearOptions.length"
                                @click="openSeasonCopy"
                            >
                                Kopjo për vitin tjetër
                            </Button>
                            <Button size="sm" variant="primary" @click="openCreateSeason">+ Shto sezon</Button>
                        </div>
                    </div>
                </template>

                <div class="divide-y divide-neutral-100">
                    <div v-for="s in seasons" :key="s.id" class="py-3 flex items-center gap-4">
                        <div class="flex-1 min-w-0">
                            <p class="text-body-sm font-medium text-primary-900">{{ s.name }}</p>
                            <p class="text-small text-neutral-500">{{ fmtRange(s) }} · prioritet {{ s.priority }}</p>
                        </div>
                        <Button size="sm" variant="ghost" @click="openEditSeason(s)">Edito</Button>
                        <Button size="sm" variant="ghost" class="text-error-600" @click="deleteSeason(s)">Fshi</Button>
                    </div>
                    <div v-if="!seasons.length" class="py-6 text-center text-body-sm text-neutral-500">
                        Asnje sezon. Shtoni nje (p.sh. "Sezoni i larte: 1 Korrik–31 Gusht").
                    </div>
                </div>
            </Card>

            <!-- Price matrix -->
            <Card>
                <template #header>
                    <div>
                        <h3 class="text-h4 text-primary-900">Cmimet sipas tipit dhe sezonit</h3>
                        <p class="text-small text-neutral-500 mt-0.5">Bosh = perdoret cmimi bazё. Cmimi llogaritet natё-pёr-natё sipas datave.</p>
                    </div>
                </template>

                <div class="overflow-x-auto">
                    <table class="w-full text-body-sm">
                        <thead>
                            <tr class="border-b border-neutral-200">
                                <th class="px-3 py-2 text-left text-label text-neutral-600">Tipi i dhomes</th>
                                <th class="px-3 py-2 text-left text-label text-neutral-600 whitespace-nowrap">Cmimi bazё (€)</th>
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
                                    Shto tipe dhomash te Settings se pari.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-end mt-4">
                    <Button variant="primary" :loading="savingRates" @click="saveRates">Ruaj cmimet</Button>
                </div>
            </Card>
        </div>

        <!-- Season modal -->
        <Modal :show="showSeason" :title="editingSeason ? 'Edito sezonin' : 'Sezon i ri'" @close="showSeason = false">
            <form @submit.prevent="submitSeason" class="space-y-4">
                <FormGroup label="Emri" :error="sform.errors.name" required>
                    <TextInput v-model="sform.name" placeholder="psh. Sezoni i larte" :error="sform.errors.name" />
                </FormGroup>
                <div class="grid grid-cols-2 gap-4">
                    <FormGroup label="Nga data" :error="sform.errors.start_date" required>
                        <DatePicker v-model="sform.start_date" :error="sform.errors.start_date" />
                    </FormGroup>
                    <FormGroup label="Deri me" :error="sform.errors.end_date" required>
                        <DatePicker v-model="sform.end_date" :error="sform.errors.end_date" />
                    </FormGroup>
                </div>
                <FormGroup label="Prioriteti" :error="sform.errors.priority" required>
                    <TextInput type="number" v-model="sform.priority" min="0" max="1000" />
                    <p class="text-tiny text-neutral-400 mt-1">Me i larte fiton kur dy sezone mbivendosen (p.sh. 'Fundjavё' > 'Sezon i larte').</p>
                </FormGroup>
            </form>
            <template #footer>
                <Button variant="outline" @click="showSeason = false">Anulo</Button>
                <Button variant="primary" :loading="sform.processing" @click="submitSeason">{{ editingSeason ? 'Ruaj' : 'Shto' }}</Button>
            </template>
        </Modal>

        <!-- OTA sell-window modal -->
        <Modal
            :show="showOtaWindow"
            title="Deri kur të jenë OTA-të të hapura"
            max-width="xl"
            :closeable="!otaPreviewing && !otaApplying"
            @close="closeOtaWindow"
        >
            <div class="space-y-4">
                <div class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-body-sm text-warning-800">
                    <p class="font-semibold">Ky veprim prek Booking.com dhe Expedia LIVE.</p>
                    <p class="mt-1">Data që zgjedh është <strong>nata e fundit që mund të shitet</strong>. Checkout-i mund të bëhet të nesërmen.</p>
                </div>

                <FormGroup label="Nata e fundit e hapur për rezervim" html-for="ota-sell-until" required>
                    <DatePicker
                        id="ota-sell-until"
                        v-model="otaSellUntil"
                        :min="otaWindow.min_date || ''"
                        :max="otaWindow.max_date || ''"
                        :disabled="otaPreviewing || otaApplying"
                    />
                    <p class="mt-1 text-small text-neutral-500">
                        Checkout-i i fundit i mundshëm: <strong>{{ formatDate(nextDate(otaSellUntil)) }}</strong>.
                    </p>
                    <p class="mt-1 text-small text-neutral-500">
                        Kufiri teknik i inventarit në Channex: {{ otaWindow.max_days || 500 }} ditë përpara.
                    </p>
                </FormGroup>

                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-small text-neutral-500">Asgjë nuk ndryshon pa kontrollin dhe konfirmimin tënd.</p>
                    <Button
                        variant="outline"
                        :loading="otaPreviewing"
                        :disabled="otaApplying || !otaSellUntil"
                        @click="previewOtaWindow"
                    >
                        Shiko ndikimin
                    </Button>
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
                    <strong>Në radhë, jo ende i përfunduar.</strong> {{ otaQueuedMessage }}
                </div>

                <section v-if="otaPreview" class="space-y-4" aria-labelledby="ota-preview-title">
                    <div class="border-t border-neutral-200 pt-4">
                        <h4 id="ota-preview-title" class="text-body font-semibold text-primary-900">Kontrolli para konfirmimit</h4>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-md border border-neutral-200 p-3">
                            <p class="text-tiny uppercase tracking-wide text-neutral-500">Aktualisht</p>
                            <p class="mt-1 text-body-sm font-semibold text-primary-900">{{ formatDate(otaPreview.current_until) }}</p>
                        </div>
                        <div class="rounded-md border border-neutral-200 p-3">
                            <p class="text-tiny uppercase tracking-wide text-neutral-500">Pas ndryshimit</p>
                            <p class="mt-1 text-body-sm font-semibold text-primary-900">{{ formatDate(otaPreview.requested_until) }}</p>
                        </div>
                        <div class="rounded-md border border-neutral-200 p-3">
                            <p class="text-tiny uppercase tracking-wide text-neutral-500">Veprimi</p>
                            <p class="mt-1 text-body-sm font-semibold text-primary-900">{{ otaActionLabel }}</p>
                        </div>
                        <div class="rounded-md border border-neutral-200 p-3">
                            <p class="text-tiny uppercase tracking-wide text-neutral-500">Net të prekura</p>
                            <p class="mt-1 text-body-sm font-semibold text-primary-900">{{ otaPreview.nights || 0 }}</p>
                        </div>
                    </div>

                    <div
                        v-if="otaPreview.action === 'pin'"
                        class="rounded-md border border-neutral-200 bg-neutral-50 p-3 text-body-sm text-neutral-700"
                    >
                        Nuk hapet dhe nuk mbyllet asnjë natë tani. Data vetëm fiksohet, që nesër të mos zgjatet automatikisht.
                    </div>
                    <div v-else class="rounded-md border border-neutral-200 bg-neutral-50 p-3 text-body-sm text-neutral-700">
                        <p>
                            Intervali i prekur:
                            <strong>{{ formatDate(otaPreview.range_from) }} → {{ formatDate(otaPreview.range_to) }}</strong>
                        </p>
                        <p class="mt-1">Preken {{ otaPreview.room_type_count || otaWindow.room_type_count || 0 }} tipe dhomash në Channex.</p>
                    </div>

                    <div class="rounded-md border border-primary-100 bg-primary-50 p-3 text-body-sm text-primary-800">
                        Rezervimet ekzistuese nuk anulohen dhe puna brenda PMS-it nuk ndryshon. Ndryshon vetëm mundësia për të marrë rezervime të reja nga OTA-të pas kësaj date.
                    </div>

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
                        <span>E kontrollova datën dhe e kuptoj që ky ndryshim do të dërgohet te Booking.com dhe Expedia live.</span>
                    </label>
                    <p v-else class="rounded-md bg-neutral-100 px-3 py-2 text-body-sm text-neutral-600">
                        Data është e njëjtë me atë aktuale; nuk ka ndryshim për t'u nisur.
                    </p>
                </section>
            </div>

            <template #footer>
                <div class="flex w-full flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <Button variant="outline" :disabled="otaPreviewing || otaApplying" @click="closeOtaWindow">Mbyll</Button>
                    <Button
                        v-if="otaPreview"
                        variant="primary"
                        :loading="otaApplying"
                        :disabled="otaPreviewing || !otaConfirmed || otaPreview.action === 'unchanged'"
                        @click="applyOtaWindow"
                    >
                        Konfirmo dhe nis sinkronizimin
                    </Button>
                </div>
            </template>
        </Modal>

        <!-- Copy seasons modal -->
        <Modal
            :show="showSeasonCopy"
            title="Kopjo sezonet në një vit tjetër"
            max-width="2xl"
            :closeable="!copyPreviewing && !copyApplying"
            @close="closeSeasonCopy"
        >
            <div class="space-y-4">
                <p class="text-body-sm text-neutral-600">
                    Kopjo datat dhe çmimet e një viti, pastaj shto një rritje përqindjeje. Fillimisht shikon çdo çmim; asgjë nuk ruhet pa konfirmim.
                </p>

                <div class="grid gap-4 sm:grid-cols-3">
                    <FormGroup label="Kopjo nga viti" html-for="copy-source-year" required>
                        <select
                            id="copy-source-year"
                            v-model="copySourceYear"
                            class="block w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-body-sm text-neutral-900 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/40"
                            :disabled="copyPreviewing || copyApplying"
                        >
                            <option value="" disabled>Zgjidh vitin</option>
                            <option v-for="year in sourceYearOptions" :key="year" :value="String(year)">{{ year }}</option>
                        </select>
                    </FormGroup>

                    <FormGroup label="Kopjo te viti" html-for="copy-target-year" required>
                        <select
                            id="copy-target-year"
                            v-model="copyTargetYear"
                            class="block w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-body-sm text-neutral-900 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/40"
                            :disabled="copyPreviewing || copyApplying"
                        >
                            <option value="" disabled>Zgjidh vitin</option>
                            <option v-for="year in targetYearOptions" :key="year" :value="String(year)">{{ year }}</option>
                        </select>
                    </FormGroup>

                    <FormGroup label="Rritja e çmimeve (%)" html-for="copy-uplift" required>
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
                    <p class="text-small text-neutral-500">Shembull: 100 € me +7% bëhet 107 €. Lejohet nga -50% deri në +100%.</p>
                    <Button
                        variant="outline"
                        :loading="copyPreviewing"
                        :disabled="copyApplying || !copySourceYear || !copyTargetYear || !copyUpliftValid"
                        @click="previewSeasonCopy"
                    >
                        Shiko sezonet dhe çmimet
                    </Button>
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
                            OTA-të janë aktualisht të hapura deri më {{ formatDate(copyPreview.ota_publish_until) }}.
                        </p>
                    </div>

                    <div
                        v-if="copyPreview.override_count > 0"
                        class="rounded-md border border-warning-200 bg-warning-50 p-3 text-body-sm text-warning-800"
                        role="alert"
                    >
                        <strong>Kontrollo Çmimin Inteligjent:</strong> ka {{ copyPreview.override_count }} çmime ditore të Çmimit Inteligjent në këtë interval. Ato nuk ndryshohen nga kopjimi dhe kanë përparësi ndaj çmimit të sezonit në ato data.
                    </div>

                    <p
                        v-if="copyPreview.state === 'no_changes'"
                        class="rounded-md bg-neutral-100 px-3 py-2 text-body-sm text-neutral-600"
                        role="status"
                    >
                        Të njëjtat sezone dhe çmime janë tashmë në vitin {{ copyPreview.target_year }}; nuk ka asgjë të re për të ruajtur.
                    </p>

                    <div
                        v-if="copyPreview.conflicts?.length"
                        class="rounded-md border border-error-200 bg-error-50 p-3 text-body-sm text-error-800"
                    >
                        <p class="font-semibold">Konflikte për t'u kontrolluar:</p>
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
                                    {{ formatDate(season.start_date) }} → {{ formatDate(season.end_date) }} · prioritet {{ season.priority }}
                                </p>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full min-w-[480px] text-body-sm">
                                    <thead>
                                        <tr class="border-b border-neutral-200 text-left text-label text-neutral-600">
                                            <th class="px-4 py-2">Tipi i dhomës</th>
                                            <th class="px-4 py-2">Çmimi {{ copyPreview.source_year }}</th>
                                            <th class="px-4 py-2">Çmimi {{ copyPreview.target_year }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-neutral-100">
                                        <tr v-for="rate in season.rates" :key="rate.room_type_id">
                                            <td class="px-4 py-2 font-medium text-primary-900">{{ rate.room_type_name }}</td>
                                            <td class="px-4 py-2 text-neutral-700">
                                                {{ formatPrice(rate.source_price) }}
                                                <span class="block text-tiny text-neutral-400">
                                                    {{ rate.source_kind === 'base' ? 'çmim bazë' : 'çmim sezonal' }}
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
                        Nuk u gjet asnjë sezon për vitin {{ copyPreview.source_year }}.
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
                        <span>E kontrollova të gjitha çmimet dhe e kuptoj se ruajtja mund të ndryshojë çmimet në Booking.com dhe Expedia live.</span>
                    </label>
                </section>
            </div>

            <template #footer>
                <div class="flex w-full flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <Button variant="outline" :disabled="copyPreviewing || copyApplying" @click="closeSeasonCopy">Mbyll</Button>
                    <Button
                        v-if="copyPreview?.state === 'ready' && copyPreview?.seasons?.length"
                        variant="primary"
                        :loading="copyApplying"
                        :disabled="copyPreviewing || !copyConfirmed"
                        @click="applySeasonCopy"
                    >
                        Konfirmo kopjimin e sezoneve
                    </Button>
                </div>
            </template>
        </Modal>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
