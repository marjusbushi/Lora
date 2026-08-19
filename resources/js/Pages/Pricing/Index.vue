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
import Badge from '@/Components/UI/Badge.vue';
import Select from '@/Components/UI/Select.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';
import PricingTabs from '@/Components/Pricing/PricingTabs.vue';

const props = defineProps({
    roomTypes: { type: Array, default: () => [] },
    seasons: { type: Array, default: () => [] },
    offers: { type: Array, default: () => [] },
    otaWindow: { type: Object, default: () => ({}) },
    seasonCopy: { type: Object, default: () => ({}) },
    smartModule: { type: Object, default: () => ({}) },
});

const toasts = ref(null);
const currencyCode = usePage().props.settings?.pricing_currency || usePage().props.tenant?.currency || 'EUR';
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
        currency: currencyCode,
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
watch(() => [props.roomTypes, props.seasons], () => { buildMatrix(); snapMatrix(); });

const savingRates = ref(false);
function saveRates() {
    savingRates.value = true;
    router.post(route('pricing.rates.save'), { base, rates }, {
        preserveScroll: true,
        onSuccess: () => { toasts.value?.success(translate('admin.generated.k_50b93c41a367')); snapMatrix(); },
        onFinish: () => { savingRates.value = false; },
    });
}

// ── Redesign (approved mockup 2026-07-24): year timeline, gaps, dirty bar ──
const DAY_MS = 86400000;
// Muted, system-matching tones (Marjus 2026-07-24: solid poster colours
// clashed with the app): soft tinted fill + deep text of the same hue +
// a mid-shade edge accent — the same language as the app's status badges.
const SEASON_TONES = [
    { bg: '#e7eef7', text: '#2f5578', edge: '#8fb0d1' }, // sea blue
    { bg: '#f8eddc', text: '#7d5316', edge: '#d9ad62' }, // amber
    { bg: '#e6f0ea', text: '#2c5d45', edge: '#8db8a2' }, // green
    { bg: '#f8e7e3', text: '#8c3d2e', edge: '#d69182' }, // terracotta
    { bg: '#ece8f6', text: '#4f3d80', edge: '#a795d6' }, // violet
    { bg: '#e2f0ef', text: '#275f5c', edge: '#85b7b3' }, // teal
    { bg: '#f7e7ef', text: '#82365f', edge: '#cf8fb0' }, // rose
    { bg: '#f0f1de', text: '#5c5f22', edge: '#b3b766' }, // olive
];
const toUtc = (d) => { const [y, m, dd] = String(d).split('-').map(Number); return Date.UTC(y, m - 1, dd); };
const isoOf = (utc) => new Date(utc).toISOString().slice(0, 10);
const now = new Date();
const todayIso = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
const year = ref(now.getFullYear());
const yearStartUtc = computed(() => Date.UTC(year.value, 0, 1));
const yearDays = computed(() => Math.round((Date.UTC(year.value + 1, 0, 1) - yearStartUtc.value) / DAY_MS));
const dayIdx = (d) => Math.round((toUtc(d) - yearStartUtc.value) / DAY_MS);
const clampIdx = (i) => Math.min(Math.max(i, 0), yearDays.value - 1);
const monthNames = Array.from({ length: 12 }, (_, i) => translate(`pricingIndex.monthsShort.${i}`));
const shortDate = (d) => {
    const [, m, dd] = String(d).split('-').map(Number);
    return `${dd} ${m >= 1 && m <= 12 ? translate(`pricingIndex.monthsTiny.${m - 1}`) : ''}`;
};

// Stable colour per season (keyed by id, so edits never reshuffle the palette).
const seasonColor = computed(() => {
    const map = {};
    [...props.seasons].sort((a, b) => a.id - b.id).forEach((s, i) => { map[s.id] = SEASON_TONES[i % SEASON_TONES.length]; });
    return map;
});
const yearSeasons = computed(() => props.seasons
    .filter((s) => dayIdx(s.start_date) <= yearDays.value - 1 && dayIdx(s.end_date) >= 0)
    .sort((a, b) => String(a.start_date).localeCompare(String(b.start_date))));

// Low-priority first so the winner paints on top (its ★ marks the raise).
const segments = computed(() => [...yearSeasons.value]
    .sort((a, b) => a.priority - b.priority)
    .map((s) => {
        const from = clampIdx(dayIdx(s.start_date));
        const to = clampIdx(dayIdx(s.end_date));
        const raised = yearSeasons.value.some((o) => o.id !== s.id
            && String(o.start_date) <= String(s.end_date)
            && String(o.end_date) >= String(s.start_date)
            && o.priority < s.priority);
        return {
            season: s,
            left: (from / yearDays.value) * 100,
            width: ((to - from + 1) / yearDays.value) * 100,
            tone: seasonColor.value[s.id],
            raised,
        };
    }));

const gaps = computed(() => {
    const len = yearDays.value;
    const intervals = yearSeasons.value
        .map((s) => [clampIdx(dayIdx(s.start_date)), clampIdx(dayIdx(s.end_date))])
        .sort((a, b) => a[0] - b[0]);
    const out = [];
    let cursor = 0;
    intervals.forEach(([a, b]) => {
        if (a > cursor) out.push([cursor, a - 1]);
        cursor = Math.max(cursor, b + 1);
    });
    if (cursor < len) out.push([cursor, len - 1]);
    return out.map(([a, b]) => ({
        from: isoOf(yearStartUtc.value + a * DAY_MS),
        to: isoOf(yearStartUtc.value + b * DAY_MS),
        days: b - a + 1,
        left: (a / len) * 100,
        width: ((b - a + 1) / len) * 100,
    }));
});
const todayPct = computed(() => {
    const i = dayIdx(todayIso);
    return i >= 0 && i < yearDays.value ? ((i + 0.5) / yearDays.value) * 100 : null;
});
const nextYearEmpty = computed(() => !props.seasons.some((s) => Number(String(s.end_date).slice(0, 4)) >= year.value + 1));

// Inline season editor under the band (creation keeps the modal).
const inlineOpen = ref(false);
function editSeasonInline(s) {
    editingSeason.value = s;
    sform.name = s.name;
    sform.start_date = s.start_date;
    sform.end_date = s.end_date;
    sform.priority = s.priority;
    sform.clearErrors();
    inlineOpen.value = true;
}
function closeInline() { inlineOpen.value = false; editingSeason.value = null; }
function saveInline() {
    sform.put(route('pricing.seasons.update', editingSeason.value.id), {
        preserveScroll: true,
        onSuccess: () => { closeInline(); toasts.value?.success(translate('admin.generated.k_d79213323d87')); },
    });
}
const inlineOverlaps = computed(() => {
    if (!editingSeason.value) return [];
    return props.seasons.filter((o) => o.id !== editingSeason.value.id
        && String(o.start_date) <= String(sform.end_date)
        && String(o.end_date) >= String(sform.start_date));
});
function coverGap(g) {
    editingSeason.value = null;
    sform.reset();
    sform.clearErrors();
    sform.start_date = g.from;
    sform.end_date = g.to;
    showSeason.value = true;
}

// Dirty tracking: the save bar appears only when the matrix differs from the
// last loaded/saved snapshot, and Anulo restores it.
const matrixSnap = ref('');
function snapMatrix() { matrixSnap.value = JSON.stringify({ base, rates }); }
snapMatrix();
const dirtyCount = computed(() => {
    let n = 0;
    let snap;
    try { snap = JSON.parse(matrixSnap.value || '{}'); } catch { return 0; }
    props.roomTypes.forEach((t) => { if (String(snap.base?.[t.id] ?? '') !== String(base[t.id] ?? '')) n += 1; });
    props.seasons.forEach((s) => props.roomTypes.forEach((t) => {
        if (String(snap.rates?.[s.id]?.[t.id] ?? '') !== String(rates[s.id]?.[t.id] ?? '')) n += 1;
    }));
    return n;
});
function resetMatrix() {
    let snap;
    try { snap = JSON.parse(matrixSnap.value || '{}'); } catch { return; }
    props.roomTypes.forEach((t) => { base[t.id] = snap.base?.[t.id] ?? ''; });
    props.seasons.forEach((s) => {
        rates[s.id] = rates[s.id] || {};
        props.roomTypes.forEach((t) => { rates[s.id][t.id] = snap.rates?.[s.id]?.[t.id] ?? ''; });
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
            else toasts.value?.success(flash.success || translate('pricingIndex.syncStarted'));
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
    if (!confirm(translate('pricingIndex.deleteSeasonConfirm', { name: s.name }))) return;
    router.delete(route('pricing.seasons.destroy', s.id), {
        preserveScroll: true,
        onSuccess: () => { closeInline(); toasts.value?.success(translate('admin.generated.k_6031e3e37904')); },
    });
}

// ===== Ofertat OTA (kompensimi i fushatave të extranet-it) =====
const showOffer = ref(false);
const editingOffer = ref(null);
const oform = useForm({ name: '', channel: 'booking', discount_pct: null, starts_on: '', ends_on: '', active: true });
const offerChannelLabels = { booking: 'Booking.com', expedia: 'Expedia', airbnb: 'Airbnb' };
const offerChannelOptions = Object.entries(offerChannelLabels).map(([value, label]) => ({ value, label }));

// The transparency line: what the OTA shows vs what the guest pays, from the
// cheapest base price as the worked example.
const offerPreview = computed(() => {
    const pct = Number(oform.discount_pct);
    if (!(pct > 0) || pct > 70) return null;
    const base = Math.min(...(props.roomTypes || []).map((t) => Number(t.base_price)).filter((p) => p > 0));
    if (!Number.isFinite(base)) return null;
    const pushed = Math.round((base / (1 - pct / 100)) * 100) / 100;
    return { base: base.toFixed(2), pushed: pushed.toFixed(2) };
});

function openCreateOffer() {
    editingOffer.value = null;
    oform.reset();
    oform.clearErrors();
    showOffer.value = true;
}
function openEditOffer(o) {
    editingOffer.value = o;
    oform.name = o.name;
    oform.channel = o.channel;
    oform.discount_pct = o.discount_pct;
    oform.starts_on = o.starts_on;
    oform.ends_on = o.ends_on;
    oform.active = o.active;
    oform.clearErrors();
    showOffer.value = true;
}
function submitOffer() {
    const opts = {
        preserveScroll: true,
        onSuccess: () => { showOffer.value = false; toasts.value?.success(translate('pricingIndex.offers.saved')); },
    };
    if (editingOffer.value) {
        oform.put(route('pricing.offers.update', editingOffer.value.id), opts);
    } else {
        oform.post(route('pricing.offers.store'), opts);
    }
}
function deleteOffer(o) {
    if (!confirm(translate('pricingIndex.offers.deleteConfirm', { name: o.name }))) return;
    router.delete(route('pricing.offers.destroy', o.id), {
        preserveScroll: true,
        onSuccess: () => toasts.value?.success(translate('pricingIndex.offers.deleted')),
    });
}
function offerStatus(o) {
    const today = new Date().toISOString().slice(0, 10);
    if (!o.active) return 'inactive';
    if (o.ends_on < today) return 'expired';
    if (o.starts_on > today) return 'upcoming';
    return 'active';
}
const offerStatusBadge = {
    active: { variant: 'success', key: 'pricingIndex.offers.statusActive' },
    upcoming: { variant: 'info', key: 'pricingIndex.offers.statusUpcoming' },
    expired: { variant: 'neutral', key: 'pricingIndex.offers.statusExpired' },
    inactive: { variant: 'warning', key: 'pricingIndex.offers.statusInactive' },
};

function fmtRange(s) {
    return `${s.start_date} → ${s.end_date}`;
}
</script>

<template>
    <AppLayout>
        <PageHeader
            :title="$t('admin.generated.k_41ef039c5194')"
            :breadcrumbs="[{ label: $t('admin.generated.k_f226794ce976'), href: '/dashboard' }, { label: $t('admin.generated.k_9cccd7468e78') }]"
        >
            <template #actions>
                <PricingTabs active="seasons" :smart-price-cents="Number(smartModule?.priceCents) || 0" />
                <div class="inline-flex items-center rounded-xl border border-neutral-200 bg-white shadow-sm overflow-hidden">
                    <button class="px-3 py-2 text-neutral-500 hover:text-primary-900 hover:bg-neutral-50 font-bold" @click="year -= 1">‹</button>
                    <b class="px-1.5 text-body font-bold text-primary-900 tabular-nums">{{ year }}</b>
                    <button class="relative px-3 py-2 text-neutral-500 hover:text-primary-900 hover:bg-neutral-50 font-bold" @click="year += 1">
                        ›
                        <span v-if="nextYearEmpty" class="absolute -top-0.5 right-0.5 text-[8px] font-extrabold bg-warning-500 text-white rounded px-1 leading-tight">{{ $t('pricingIndex.emptyBadge') }}</span>
                    </button>
                </div>
            </template>
        </PageHeader>

        <div class="mt-6 space-y-5">
            <!-- Channel manager (Channex): one calm strip, banners only when real -->
            <div v-if="channelManagerEnabled" class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-neutral-200 bg-white px-4 py-2.5 text-body-sm shadow-sm">
                <span class="relative flex h-2.5 w-2.5">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-60" :class="otaSyncPending ? 'bg-warning-400' : 'bg-success-400'" />
                    <span class="relative inline-flex h-2.5 w-2.5 rounded-full" :class="otaSyncPending ? 'bg-warning-500' : 'bg-success-500'" />
                </span>
                <span class="font-bold text-primary-900">{{ $t('admin.generated.k_5786b449b811') }}</span>
                <span class="text-neutral-500">{{ $t('admin.generated.k_bcbc2e972c63') }} <b class="text-primary-900">{{ formatDate(otaEffectiveUntil) }}</b></span>
                <span v-if="otaSyncPending" class="rounded-full bg-warning-50 border border-warning-200 px-2.5 py-0.5 text-tiny font-bold text-warning-800">
                    {{ $t('admin.generated.k_ae248b23fa5d') }}<template v-if="otaWindow.applied_until"> · {{ formatDate(otaWindow.applied_until) }}</template>
                </span>
                <span class="flex-1" />
                <Button size="sm" variant="outline" @click="openOtaWindow">{{ $t('admin.generated.k_3c46c19034c7') }}</Button>
                <Button size="sm" variant="secondary" :disabled="syncing" @click="syncChannex">
                    {{ syncing ? $t('admin.generated.k_270ec5ff9530') : $t('admin.generated.k_bb12cb3fbb4c') }}
                </Button>
            </div>

            <!-- Seasons: the year timeline -->
            <Card>
                <template #header>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <h3 class="text-h4 text-primary-900">{{ $t('admin.generated.k_b393cba9fdfc') }}</h3>
                            <p class="text-small text-neutral-500 mt-0.5">{{ $t('pricingIndex.seasonsHint') }}</p>
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
                            <Button size="sm" variant="outline" @click="openCreateOffer">{{ $t('pricingIndex.offers.add') }}</Button>
                        </div>
                    </div>
                </template>

                <div class="grid grid-cols-12 mb-1.5 text-[10px] font-bold uppercase tracking-widest text-neutral-400">
                    <span v-for="m in monthNames" :key="m" class="text-center">{{ m }}</span>
                </div>
                <div class="relative h-14 rounded-xl border border-neutral-200 overflow-visible"
                    style="background:repeating-linear-gradient(-45deg,#faf5dd,#faf5dd 7px,#f3ecc9 7px,#f3ecc9 14px)">
                    <div class="absolute inset-0 grid grid-cols-12 pointer-events-none rounded-xl overflow-hidden">
                        <i v-for="m in 12" :key="m" class="border-r border-black/5 last:border-0" />
                    </div>

                    <button
                        v-for="seg in segments"
                        :key="seg.season.id"
                        type="button"
                        class="absolute rounded-lg font-extrabold text-tiny leading-tight px-1.5 overflow-hidden whitespace-nowrap transition hover:-translate-y-0.5 hover:shadow-md"
                        :class="[
                            seg.raised ? 'top-0 bottom-0 z-[2] shadow-md' : 'top-1.5 bottom-1.5',
                            editingSeason && editingSeason.id === seg.season.id && inlineOpen ? 'ring-2 ring-primary-900 ring-offset-1 z-[3]' : '',
                        ]"
                        :style="{
                            left: seg.left + '%',
                            width: seg.width + '%',
                            background: seg.tone.bg,
                            color: seg.tone.text,
                            boxShadow: seg.raised
                                ? `inset 0 0 0 2px ${seg.tone.edge}, inset 0 -3px 0 ${seg.tone.edge}`
                                : `inset 0 -3px 0 ${seg.tone.edge}`,
                        }"
                        :title="seg.season.name + ' · ' + fmtRange(seg.season) + ' · ' + $t('admin.generated.k_c0857a9c44ab') + ' ' + seg.season.priority"
                        @click="editSeasonInline(seg.season)"
                    >
                        <span v-if="seg.raised" class="absolute top-0.5 right-1 text-[9px]">★</span>
                        {{ seg.season.name }}
                    </button>

                    <button
                        v-for="g in gaps"
                        :key="g.from"
                        type="button"
                        class="absolute top-1.5 bottom-1.5 rounded-lg border-2 border-dashed border-warning-400 text-warning-800 text-[10px] font-extrabold leading-tight px-1 hover:bg-warning-50/80"
                        :style="{ left: g.left + '%', width: g.width + '%' }"
                        :title="shortDate(g.from) + ' – ' + shortDate(g.to) + ': ' + $t('pricingIndex.gapTitle', { days: g.days })"
                        @click="coverGap(g)"
                    >
                        <template v-if="g.width > 6">⚠ {{ g.days }} {{ $t('pricingIndex.days') }}<br>+ {{ $t('pricingIndex.cover') }}</template>
                        <template v-else>⚠</template>
                    </button>

                    <div v-if="todayPct !== null" class="absolute -top-2 -bottom-2 w-0.5 bg-primary-950 z-[4] pointer-events-none" :style="{ left: todayPct + '%' }">
                        <span class="absolute -top-4 left-1/2 -translate-x-1/2 rounded-full bg-primary-950 px-1.5 py-0.5 text-[8px] font-extrabold tracking-widest text-white">{{ $t('pricingIndex.today') }}</span>
                    </div>
                </div>

                <!-- inline season editor -->
                <div v-if="inlineOpen && editingSeason" class="mt-4 rounded-xl border border-neutral-200 overflow-hidden">
                    <div class="flex items-center gap-2 px-4 py-2.5 font-bold" :style="{ background: seasonColor[editingSeason.id].bg, color: seasonColor[editingSeason.id].text, boxShadow: `inset 0 -2px 0 ${seasonColor[editingSeason.id].edge}` }">
                        ✎ {{ editingSeason.name }}
                        <button type="button" class="ml-auto h-6 w-6 rounded-md bg-black/5 font-extrabold hover:bg-black/10" @click="closeInline">✕</button>
                    </div>
                    <div class="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
                        <FormGroup :label="$t('admin.generated.k_51a0c7aadeb7')" :error="sform.errors.name" required>
                            <TextInput v-model="sform.name" :error="sform.errors.name" />
                        </FormGroup>
                        <FormGroup :label="$t('admin.generated.k_8b9d783e781c')" :error="sform.errors.start_date" required>
                            <DatePicker v-model="sform.start_date" :error="sform.errors.start_date" />
                        </FormGroup>
                        <FormGroup :label="$t('admin.generated.k_15cdee011901')" :error="sform.errors.end_date" required>
                            <DatePicker v-model="sform.end_date" :error="sform.errors.end_date" />
                        </FormGroup>
                        <FormGroup :label="$t('admin.generated.k_2bd87c03f9dd')" :error="sform.errors.priority" required>
                            <TextInput type="number" v-model="sform.priority" min="0" max="1000" />
                        </FormGroup>
                    </div>
                    <div v-if="inlineOverlaps.length" class="mx-4 mb-3 rounded-lg bg-primary-50 border border-primary-100 px-3 py-2 text-body-sm text-primary-800">
                        💡 {{ $t('pricingIndex.overlapsWith') }}
                        <template v-for="(o, i) in inlineOverlaps" :key="o.id"><template v-if="i > 0">, </template><b>{{ o.name }}</b> ({{ $t('admin.generated.k_c0857a9c44ab') }} {{ o.priority }})</template>.
                        {{ $t('pricingIndex.overlapsNote') }}
                    </div>
                    <div class="flex items-center justify-between gap-3 px-4 pb-4">
                        <button type="button" class="text-body-sm font-bold text-error-600 hover:underline" @click="deleteSeason(editingSeason)">{{ $t('admin.generated.k_315a0d2b6347') }}</button>
                        <div class="flex gap-2">
                            <Button size="sm" variant="outline" :disabled="sform.processing" @click="closeInline">{{ $t('admin.generated.k_b59ae1e356c9') }}</Button>
                            <Button size="sm" variant="primary" :loading="sform.processing" @click="saveInline">{{ $t('admin.generated.k_4e4180955a51') }}</Button>
                        </div>
                    </div>
                </div>
                <p v-else-if="!yearSeasons.length" class="mt-3 text-body-sm text-neutral-500">
                    {{ $t('admin.generated.k_1974bcb2ef98') }}<template v-if="sourceYearOptions.length"> {{ $t('pricingIndex.copyHintPrefix') }} <b>{{ $t('admin.generated.k_05a62a33ee40') }}</b> {{ $t('pricingIndex.copyHintSuffix') }}</template>
                </p>
            </Card>

            <!-- OTA offers: compensation for extranet campaigns -->
            <Card>
                <template #header>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h3 class="text-h4 text-primary-900">{{ $t('pricingIndex.offers.title') }}</h3>
                            <p class="text-small text-neutral-500 mt-0.5">{{ $t('pricingIndex.offers.subtitle') }}</p>
                        </div>
                        <Button size="sm" variant="primary" @click="openCreateOffer">{{ $t('pricingIndex.offers.add') }}</Button>
                    </div>
                </template>

                <div v-if="!offers.length" class="px-2 py-6 text-center">
                    <p class="text-body-sm font-medium text-neutral-700">{{ $t('pricingIndex.offers.emptyTitle') }}</p>
                    <p class="mt-1 text-small text-neutral-500">{{ $t('pricingIndex.offers.emptyHint') }}</p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-body-sm">
                        <thead>
                            <tr class="border-b border-neutral-200 text-label uppercase tracking-wider text-neutral-500">
                                <th class="py-2.5 pr-4 text-left">{{ $t('pricingIndex.offers.colName') }}</th>
                                <th class="py-2.5 pr-4 text-left">{{ $t('pricingIndex.offers.colChannel') }}</th>
                                <th class="py-2.5 pr-4 text-right">{{ $t('pricingIndex.offers.colDiscount') }}</th>
                                <th class="py-2.5 pr-4 text-left">{{ $t('pricingIndex.offers.colWindow') }}</th>
                                <th class="py-2.5 pr-4 text-left">{{ $t('pricingIndex.offers.colStatus') }}</th>
                                <th class="py-2.5 text-right">{{ $t('pricingIndex.offers.colActions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="o in offers" :key="o.id" class="hover:bg-neutral-50/70">
                                <td class="py-2.5 pr-4 font-medium text-neutral-900 max-w-[220px] truncate" :title="o.name">{{ o.name }}</td>
                                <td class="py-2.5 pr-4 text-neutral-600">{{ offerChannelLabels[o.channel] || o.channel }}</td>
                                <td class="py-2.5 pr-4 text-right font-semibold tabular-nums text-neutral-900">−{{ o.discount_pct }}%</td>
                                <td class="py-2.5 pr-4 whitespace-nowrap text-neutral-600">{{ formatDate(o.starts_on) }} – {{ formatDate(o.ends_on) }}</td>
                                <td class="py-2.5 pr-4">
                                    <Badge :variant="offerStatusBadge[offerStatus(o)].variant" size="sm" dot>{{ $t(offerStatusBadge[offerStatus(o)].key) }}</Badge>
                                </td>
                                <td class="py-2.5 text-right whitespace-nowrap">
                                    <Button size="sm" variant="ghost" @click="openEditOffer(o)">{{ $t('pricingIndex.offers.edit') }}</Button>
                                    <button type="button" class="ml-1 text-tiny font-bold text-error-600 hover:underline" @click="deleteOffer(o)">{{ $t('pricingIndex.offers.delete') }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="mt-3 text-tiny text-neutral-500">
                    💡 {{ $t('pricingIndex.offers.extranetNote') }}
                </p>
            </Card>

            <!-- Price matrix -->
            <Card>
                <template #header>
                    <div>
                        <h3 class="text-h4 text-primary-900">{{ $t('admin.generated.k_26e2b820adef') }} <span class="text-small font-normal text-neutral-400">({{ currencyCode }} / {{ $t('pricingIndex.night') }})</span></h3>
                        <p class="text-small text-neutral-500 mt-0.5">{{ $t('pricingIndex.matrixHint') }}</p>
                    </div>
                </template>

                <div class="overflow-x-auto">
                    <table class="w-full text-body-sm">
                        <thead>
                            <tr>
                                <th class="px-3 pb-2 text-left text-label text-neutral-600 align-bottom">{{ $t('admin.generated.k_a6aa7eff1daa') }}</th>
                                <th class="px-3 pb-2 text-left align-bottom">
                                    <span class="inline-block rounded-lg bg-primary-950 px-2.5 py-1.5 text-tiny font-extrabold leading-tight text-white shadow-sm">
                                        {{ $t('pricingIndex.basePrice') }} ({{ currencyCode }})
                                        <small class="block text-[9px] font-bold opacity-80">{{ $t('pricingIndex.basePriceSub') }}</small>
                                    </span>
                                </th>
                                <th v-for="s in yearSeasons" :key="s.id" class="px-3 pb-2 text-left align-bottom">
                                    <span class="inline-block rounded-lg px-2.5 py-1.5 text-tiny font-extrabold leading-tight" :style="{ background: seasonColor[s.id].bg, color: seasonColor[s.id].text, boxShadow: `inset 0 0 0 1px ${seasonColor[s.id].edge}55, inset 0 -2px 0 ${seasonColor[s.id].edge}` }">
                                        {{ s.name }}
                                        <small class="block text-[9px] font-bold opacity-85">{{ shortDate(s.start_date) }} – {{ shortDate(s.end_date) }}</small>
                                    </span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="t in roomTypes" :key="t.id" class="hover:bg-neutral-50/60">
                                <td class="px-3 py-2 font-semibold text-primary-900 whitespace-nowrap">{{ t.name }}</td>
                                <td class="px-3 py-2">
                                    <input v-model="base[t.id]" type="number" min="0" step="1"
                                        class="w-24 rounded-lg border border-primary-200 bg-primary-50 px-2 py-1.5 text-right text-body-sm font-bold tabular-nums text-primary-900 focus:border-accent-500 focus:ring-2 focus:ring-accent-500/40" />
                                </td>
                                <td v-for="s in yearSeasons" :key="s.id" class="px-3 py-2">
                                    <input v-if="rates[s.id]" v-model="rates[s.id][t.id]" type="number" min="0" step="1"
                                        :placeholder="String(base[t.id] ?? '')"
                                        class="w-24 rounded-lg border border-neutral-300 px-2 py-1.5 text-right text-body-sm font-semibold tabular-nums placeholder:font-normal placeholder:text-neutral-300 focus:border-accent-500 focus:ring-2 focus:ring-accent-500/40" />
                                </td>
                            </tr>
                            <tr v-if="!roomTypes.length">
                                <td :colspan="2 + yearSeasons.length" class="px-3 py-6 text-center text-neutral-500">
{{ $t('admin.generated.k_62e357276d24') }} </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-tiny text-neutral-400">{{ $t('pricingIndex.footPrefix') }} <a :href="route('pricing.smart.index')" class="font-bold text-primary-900 no-underline hover:underline">{{ $t('pricingIndex.smartPricingName') }}</a> {{ $t('pricingIndex.footSuffix') }}</p>
            </Card>
        </div>

        <!-- sticky save bar: appears only when the matrix has unsaved edits -->
        <div v-if="dirtyCount" class="fixed inset-x-0 bottom-0 z-40 flex justify-center px-4 pb-5 pointer-events-none">
            <div class="pointer-events-auto flex items-center gap-3 rounded-xl bg-primary-950 py-2.5 pl-5 pr-3 text-body-sm text-white shadow-2xl">
                <span><b class="tabular-nums text-warning-300">{{ dirtyCount }}</b> {{ dirtyCount === 1 ? $t('pricingIndex.unsavedOne') : $t('pricingIndex.unsavedMany') }}</span>
                <Button size="sm" variant="outline" class="!border-neutral-600 !text-neutral-200" :disabled="savingRates" @click="resetMatrix">{{ $t('admin.generated.k_b59ae1e356c9') }}</Button>
                <Button size="sm" variant="primary" :loading="savingRates" @click="saveRates">{{ $t('admin.generated.k_f5e21afe68a5') }}</Button>
            </div>
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

        <!-- OTA offer modal -->
        <Modal :show="showOffer" :title="editingOffer ? $t('pricingIndex.offers.editTitle') : $t('pricingIndex.offers.addTitle')" @close="showOffer = false">
            <form class="space-y-4" @submit.prevent="submitOffer">
                <FormGroup :label="$t('pricingIndex.offers.fieldName')" :error="oform.errors.name" required>
                    <TextInput v-model="oform.name" :placeholder="$t('pricingIndex.offers.namePlaceholder')" :error="oform.errors.name" />
                </FormGroup>
                <div class="grid grid-cols-2 gap-4">
                    <FormGroup :label="$t('pricingIndex.offers.fieldChannel')" :error="oform.errors.channel" required>
                        <Select v-model="oform.channel" :options="offerChannelOptions" :error="oform.errors.channel" />
                    </FormGroup>
                    <FormGroup :label="$t('pricingIndex.offers.fieldDiscount')" :error="oform.errors.discount_pct" required>
                        <TextInput type="number" v-model="oform.discount_pct" min="1" max="70" step="0.5" :error="oform.errors.discount_pct" />
                    </FormGroup>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <FormGroup :label="$t('pricingIndex.offers.fieldFrom')" :error="oform.errors.starts_on" required>
                        <DatePicker v-model="oform.starts_on" :error="oform.errors.starts_on" />
                    </FormGroup>
                    <FormGroup :label="$t('pricingIndex.offers.fieldTo')" :error="oform.errors.ends_on" required>
                        <DatePicker v-model="oform.ends_on" :error="oform.errors.ends_on" />
                    </FormGroup>
                </div>

                <div v-if="offerPreview" class="rounded-lg border border-info-200 bg-info-50 px-3.5 py-3 text-small text-info-900">
                    {{ $t('pricingIndex.offers.preview', { pushed: offerPreview.pushed, base: offerPreview.base, currency: currencyCode }) }}
                </div>

                <p class="rounded-lg border border-warning-200 bg-warning-50 px-3.5 py-3 text-small text-warning-800">
                    ⚠ {{ $t('pricingIndex.offers.extranetNote') }}
                </p>
            </form>
            <template #footer>
                <Button variant="outline" @click="showOffer = false">{{ $t('admin.generated.k_b59ae1e356c9') }}</Button>
                <Button variant="primary" :loading="oform.processing" @click="submitOffer">{{ editingOffer ? $t('pricingIndex.offers.save') : $t('pricingIndex.offers.create') }}</Button>
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
