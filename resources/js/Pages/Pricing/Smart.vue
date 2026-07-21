<script setup>
import { getIntlLocale, translate } from '@/i18n';
import { ref, computed, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Modal from '@/Components/UI/Modal.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';

const props = defineProps({
    roomTypes: { type: Array, default: () => [] },       // {id, name, min_price, max_price}
    selectedTypeId: { type: [Number, String], default: null },
    days: { type: Array, default: () => [] },            // engine rows + dow/is_weekend/holiday
    market: { type: Object, default: () => ({}) },       // rate shopping: date => {median,min,max,count}
    marketEnabled: { type: Boolean, default: false },
    month: { type: String, default: '' },
    prevMonth: { type: String, default: '' },
    nextMonth: { type: String, default: '' },
    strategy: { type: String, default: 'balancuar' },
    currency: { type: String, default: '€' },
    roundingPolicy: { type: Object, default: () => ({ mode: 'commercial', currency: 'EUR', label: '' }) },
    aiConfigured: { type: Boolean, default: false },
    lastSyncAt: { type: String, default: null },
    upcomingEvents: { type: Array, default: () => [] },
    latestReport: { type: Object, default: null },
    autopilot: { type: Object, default: () => ({ enabled: false, logs: [] }) },
    otaPrograms: { type: Object, default: () => ({}) },
});

const toasts = ref(null);
const typeId = computed(() => props.selectedTypeId);
const typeLoading = ref(false);
const selected = ref(null);
const manualPrice = ref('');
const applySaving = ref(false);
const removeSaving = ref(false);
const bulkSaving = ref(false);
const revertSavingId = ref(null);
const strategySaving = ref(false);
const priceMutationBusy = computed(() => applySaving.value || removeSaving.value || bulkSaving.value || revertSavingId.value !== null);

const currentType = computed(() => props.roomTypes.find((t) => Number(t.id) === Number(typeId.value)) || null);
const roundingPolicyLabel = computed(() => {
    if (props.roundingPolicy.mode === 'exact') return translate('smartPricing.rounding.exact');
    if (props.roundingPolicy.currency === 'ALL') return translate('smartPricing.rounding.all');
    return translate('smartPricing.rounding.standard');
});

function showServerResult(page, fallback) {
    const flash = page?.props?.flash || {};
    if (flash.error) {
        toasts.value?.error(flash.error);
        return false;
    }
    toasts.value?.success(flash.success || fallback);
    return true;
}

// Inertia replaces the server props after every rule/price mutation. Keep the
// open detail panel attached to the fresh row, never to a stale suggestion.
watch(() => props.days, (days) => {
    if (!selected.value) return;
    selected.value = days.find((day) => day.date === selected.value.date) || null;
    explainText.value = '';
});

// ── Strategjia (një rrëshqitës, tre shpejtësi) ──
const strategies = [
    { key: 'kujdesshem', label: translate('admin.generated.k_be7b43433062'), hint: translate('admin.generated.k_29b65a215a2a') },
    { key: 'balancuar', label: translate('admin.generated.k_6414574f4741'), hint: translate('admin.generated.k_d8882f23f9b6') },
    { key: 'agresiv', label: translate('admin.generated.k_7c3e040216c9'), hint: translate('admin.generated.k_fab34162fe21') },
];
function setStrategy(key) {
    if (key === props.strategy || strategySaving.value || priceMutationBusy.value) return;
    strategySaving.value = true;
    router.post(route('pricing.smart.strategy'), { strategy: key }, {
        preserveScroll: true,
        onSuccess: (page) => showServerResult(page, 'Strategjia u ndryshua — sugjerimet u rifreskuan.'),
        onError: (e) => toasts.value?.error(Object.values(e)[0] || 'Strategjia nuk u ruajt.'),
        onFinish: () => { strategySaving.value = false; },
    });
}

// ── Kufijtë min/max (guardrails) për tipin e zgjedhur ──
const boundsOpen = ref(false);
const boundsMin = ref('');
const boundsMax = ref('');
const boundsSaving = ref(false);
function openBounds() {
    if (priceMutationBusy.value || boundsSaving.value) return;
    boundsMin.value = currentType.value?.min_price ?? '';
    boundsMax.value = currentType.value?.max_price ?? '';
    boundsOpen.value = true;
}
function saveBounds() {
    if (boundsSaving.value) return;
    boundsSaving.value = true;
    router.put(route('pricing.smart.bounds', typeId.value), { min_price: boundsMin.value || null, max_price: boundsMax.value || null }, {
        preserveScroll: true,
        onSuccess: () => { boundsOpen.value = false; toasts.value?.success(translate('admin.generated.k_3cc79ab4967b')); },
        onError: (e) => toasts.value?.error(Object.values(e)[0] || translate('admin.generated.k_a4e8cd64d67c')),
        onFinish: () => { boundsSaving.value = false; },
    });
}
const boundsLabel = computed(() => {
    const t = currentType.value;
    if (!t) return '';
    if (t.min_price == null && t.max_price == null) return 'pa kufij';
    return `${t.min_price != null ? props.currency + fmtPrice(t.min_price) : '—'} deri ${t.max_price != null ? props.currency + fmtPrice(t.max_price) : '—'}`;
});

// ── Apliko në masë (java / muaji) — serveri rillogarit çdo çmim ──
const bulk = ref(null); // { label, date_from, date_to, count }
function weekOf(dateStr) {
    const d = props.days.find((x) => x.date === dateStr);
    if (!d) return null;
    const dt = new Date(dateStr + 'T00:00:00');
    const start = new Date(dt); start.setDate(dt.getDate() - (d.dow - 1));
    const end = new Date(start); end.setDate(start.getDate() + 6);
    const f = (x) => `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`;
    return { from: f(start), to: f(end) };
}
function askBulkWeek() {
    if (!selected.value || priceMutationBusy.value) return;
    const w = weekOf(selected.value.date);
    if (!w) return;
    const count = props.days.filter((d) => d.date >= w.from && d.date <= w.to && d.actionable && !d.is_past).length;
    bulk.value = { label: translate('admin.generated.k_e67142b46d52') + longDate(selected.value.date), date_from: w.from, date_to: w.to, count, room_type_id: Number(props.selectedTypeId) };
}
function askBulkMonth() {
    if (priceMutationBusy.value) return;
    const count = props.days.filter((d) => d.actionable && !d.is_past).length;
    const last = props.days.length ? props.days[props.days.length - 1].date : props.month;
    bulk.value = { label: translate('admin.generated.k_bf4640a5e8de') + monthLabel.value, date_from: props.month, date_to: last, count, room_type_id: Number(props.selectedTypeId) };
}
function confirmBulk() {
    const b = bulk.value;
    if (!b || bulkSaving.value) return;
    bulkSaving.value = true;
    router.post(route('pricing.smart.apply-range'), { room_type_id: b.room_type_id, date_from: b.date_from, date_to: b.date_to }, {
        preserveScroll: true,
        onSuccess: (page) => {
            bulk.value = null;
            if (showServerResult(page, translate('admin.generated.k_a54e3a50f85d'))) selected.value = null;
        },
        onError: (e) => { bulk.value = null; toasts.value?.error(Object.values(e)[0] || translate('admin.generated.k_0f9d09b95e78')); },
        onFinish: () => { bulkSaving.value = false; },
    });
}

// ── Gemini: 4 punët (shpjegon, evente, raport, pyetje) — kurrë numrin ──
const explainText = ref('');
const explainLoading = ref(false);
async function explainDay() {
    if (!selected.value) return;
    explainLoading.value = true; explainText.value = '';
    try {
        const { data } = await axios.post(route('pricing.smart.explain'), { date: selected.value.date, room_type_id: typeId.value });
        explainText.value = data.sentence;
    } catch (e) {
        toasts.value?.error(e.response?.data?.error || 'Shpjegimi s\'u gjenerua dot.');
    }
    explainLoading.value = false;
}

const evSuggestions = ref([]);
const evLoading = ref(false);
const eventEdit = ref(null);
const eventUplift = ref('');
const eventSaving = ref(false);
const eventScopeConfirmed = ref(false);
const eventApprovingKey = ref(null);
const eventDeletingId = ref(null);
const eventBusy = computed(() => eventEdit.value !== null || evLoading.value || eventSaving.value || eventApprovingKey.value !== null || eventDeletingId.value !== null);
function suggestionKey(ev) { return `${ev.name}|${ev.date_from}|${ev.date_to}`; }
const overlappingPricedEvents = computed(() => {
    if (!eventEdit.value) return [];
    return props.upcomingEvents.filter((ev) =>
        ev.id !== eventEdit.value.id
        && ev.affects_price
        && ev.date_from <= eventEdit.value.date_to
        && ev.date_to >= eventEdit.value.date_from
    );
});
const eventImpactPreview = computed(() => {
    if (eventUplift.value === '' || Number(eventUplift.value) === 0) {
        return translate('admin.generated.k_186c07e6e96f');
    }
    const pct = Number(eventUplift.value);
    if (!Number.isFinite(pct) || pct < -50 || pct > 100) return translate('admin.generated.k_1f2f8d023954');
    const factor = (1 + pct / 100).toFixed(3).replace(/0+$/, '').replace(/\.$/, '');
    const example = 100 * (1 + pct / 100);
    return translate('admin.generated.k_b86601d5723f', { p0: factor, p1: props.currency, p2: props.currency, p3: fmtPrice(example) });
});
const normalizedEditedUplift = computed(() => {
    if (eventUplift.value === '' || Number(eventUplift.value) === 0) return null;
    const pct = Number(eventUplift.value);
    return Number.isFinite(pct) ? Math.round(pct * 100) / 100 : null;
});
const normalizedCurrentUplift = computed(() => (
    eventEdit.value?.affects_price ? Math.round(Number(eventEdit.value.uplift_pct) * 100) / 100 : null
));
const eventRuleChanges = computed(() => normalizedEditedUplift.value !== normalizedCurrentUplift.value);
function openEventImpact(ev) {
    if (eventBusy.value) return;
    eventEdit.value = ev;
    eventUplift.value = ev.affects_price ? Number(ev.uplift_pct) : '';
    eventScopeConfirmed.value = false;
}
function saveEventImpact() {
    if (!eventEdit.value || eventSaving.value) return;
    const pct = eventUplift.value === '' ? null : Number(eventUplift.value);
    if (pct !== null && (!Number.isFinite(pct) || pct < -50 || pct > 100)) {
        toasts.value?.error(translate('admin.generated.k_a3d3ec211300'));
        return;
    }
    if (eventRuleChanges.value && !eventScopeConfirmed.value) {
        toasts.value?.error(translate('admin.generated.k_07bf2d3aee35'));
        return;
    }
    const submittedId = eventEdit.value.id;
    eventSaving.value = true;
    router.put(route('pricing.smart.events.update', submittedId), {
        uplift_pct: normalizedEditedUplift.value,
    }, {
        preserveScroll: true,
        onSuccess: (page) => {
            if (showServerResult(page, 'Ndikimi i eventit u ruajt.') && eventEdit.value?.id === submittedId) eventEdit.value = null;
        },
        onError: (e) => toasts.value?.error(Object.values(e)[0] || 'Ndikimi i eventit nuk u ruajt.'),
        onFinish: () => { eventSaving.value = false; },
    });
}
async function suggestEvents() {
    if (eventBusy.value) return;
    evLoading.value = true; evSuggestions.value = [];
    try {
        const { data } = await axios.post(route('pricing.smart.events.suggest'));
        evSuggestions.value = data.events || [];
        if (!evSuggestions.value.length) toasts.value?.success(translate('admin.generated.k_ba8cc528a8a6'));
    } catch (e) {
        toasts.value?.error(e.response?.data?.error || translate('admin.generated.k_bc3948d2c11a'));
    }
    evLoading.value = false;
}
function approveEvent(ev) {
    const key = suggestionKey(ev);
    if (eventBusy.value) return;
    const uplift = ev.uplift_pct == null || Number(ev.uplift_pct) === 0
        ? null
        : Math.round(Number(ev.uplift_pct) * 100) / 100;
    if (uplift !== null) {
        const autopilotWarning = props.autopilot.enabled
            ? translate('admin.generated.k_f155a650c6dd')
            : '';
        if (!window.confirm(translate('admin.generated.k_f3776eda2d6b', { p0: uplift > 0 ? '+' : '', p1: uplift, p2: autopilotWarning }))) return;
    }
    eventApprovingKey.value = key;
    router.post(route('pricing.smart.events.approve'), {
        name: ev.name, date_from: ev.date_from, date_to: ev.date_to, uplift_pct: uplift,
    }, {
        preserveScroll: true,
        onSuccess: (page) => {
            if (showServerResult(page, `"${ev.name}" u shtua.`)) {
                evSuggestions.value = evSuggestions.value.filter((candidate) => suggestionKey(candidate) !== key);
            }
        },
        onError: (e) => toasts.value?.error(Object.values(e)[0] || 'Eventi s\'u shtua.'),
        onFinish: () => { if (eventApprovingKey.value === key) eventApprovingKey.value = null; },
    });
}
function removeEventRow(ev) {
    if (eventBusy.value || !window.confirm(translate('admin.generated.k_9a8aaac3abf3', { p0: ev.name }))) return;
    eventDeletingId.value = ev.id;
    router.delete(route('pricing.smart.events.destroy', ev.id), {
        preserveScroll: true,
        onSuccess: (page) => showServerResult(page, `"${ev.name}" u hoq.`),
        onError: (e) => toasts.value?.error(Object.values(e)[0] || 'Eventi nuk u hoq.'),
        onFinish: () => { if (eventDeletingId.value === ev.id) eventDeletingId.value = null; },
    });
}

const reportLoading = ref(false);
function generateReport() {
    reportLoading.value = true;
    router.post(route('pricing.smart.report'), {}, {
        preserveScroll: true,
        onFinish: () => { reportLoading.value = false; },
    });
}

const askQ = ref('');
const askA = ref('');
const askLoading = ref(false);
async function askAi() {
    const q = askQ.value.trim();
    if (!q) return;
    askLoading.value = true; askA.value = '';
    try {
        const { data } = await axios.post(route('pricing.smart.ask'), { question: q, month: props.month, room_type_id: typeId.value });
        askA.value = data.answer;
    } catch (e) {
        toasts.value?.error(e.response?.data?.error || translate('admin.generated.k_681cc0c6d27c'));
    }
    askLoading.value = false;
}

// ── Autopiloti me kufij (default: I FIKUR — vetëm ti e ndez) ──
const apConfirmOpen = ref(false);
const apForm = ref({});
const autopilotSaving = ref(false);
const navigationLocked = computed(() => priceMutationBusy.value
    || strategySaving.value
    || boundsSaving.value
    || autopilotSaving.value
    || eventSaving.value
    || eventApprovingKey.value !== null
    || eventDeletingId.value !== null);
function openAutopilot(intendEnable) {
    if (navigationLocked.value) return;
    apForm.value = {
        enabled: intendEnable,
        materiality_pct: props.autopilot.materiality_pct ?? 5,
        daily_cap_pct: props.autopilot.daily_cap_pct ?? 15,
        protect_manual_days: props.autopilot.protect_manual_days ?? 3,
        pause_from: props.autopilot.pause_from ?? '',
        pause_to: props.autopilot.pause_to ?? '',
    };
    apConfirmOpen.value = true;
}
function saveAutopilot() {
    if (autopilotSaving.value) return;
    autopilotSaving.value = true;
    router.post(route('pricing.smart.autopilot'), {
        ...apForm.value,
        pause_from: apForm.value.pause_from || null,
        pause_to: apForm.value.pause_to || null,
    }, {
        preserveScroll: true,
        onSuccess: (page) => { if (showServerResult(page, translate('admin.generated.k_ce58825daf1e'))) apConfirmOpen.value = false; },
        onError: (e) => toasts.value?.error(Object.values(e)[0] || translate('admin.generated.k_49024450d90e')),
        onFinish: () => { autopilotSaving.value = false; },
    });
}
function revertAutopilot(log) {
    if (revertSavingId.value) return;
    revertSavingId.value = log.id;
    router.post(route('pricing.smart.autopilot.revert', log.id), {}, {
        preserveScroll: true,
        onSuccess: (page) => showServerResult(page, translate('admin.generated.k_23251263649a')),
        onError: (e) => toasts.value?.error(Object.values(e)[0] || translate('admin.generated.k_67db040e8db1')),
        onFinish: () => { if (revertSavingId.value === log.id) revertSavingId.value = null; },
    });
}

function fmtRange(a, b) {
    const f = (d) => new Date(d + 'T00:00:00').toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short' });
    return a === b ? f(a) : `${f(a)} – ${f(b)}`;
}

// ── Kalendari ──
const dows = [translate('admin.generated.k_156039b2640c'), 'Ma', translate('admin.generated.k_c198630c8786'), 'En', 'Pr', 'Sh', 'Di'];
const monthLabel = computed(() =>
    props.month ? new Date(props.month + 'T00:00:00').toLocaleDateString(getIntlLocale(), { month: 'long', year: 'numeric' }) : '',
);
// Rate shopping: our price vs the market median (competitors' CHEAPEST room).
function marketDelta(d) {
    const m = props.market[d.date];
    if (!m || !d.current_price) return null;
    return Math.round(((d.current_price - m.median) / m.median) * 100);
}
function marketTone(d) {
    const delta = marketDelta(d);
    if (delta === null) return '';
    if (delta > 8) return 'bg-warning-50 text-warning-700 border border-warning-200';
    if (delta < -8) return 'bg-info-50 text-info-700 border border-info-200';
    return 'bg-success-50 text-success-700 border border-success-200';
}
function marketBadge(d) {
    const delta = marketDelta(d);
    if (delta === null) return null;
    if (delta > 8) return { cls: 'bg-warning-100 text-warning-700', text: '▲ ' + delta + '% mbi tregun' };
    if (delta < -8) return { cls: 'bg-info-100 text-info-700', text: '▼ ' + Math.abs(delta) + translate('admin.generated.k_70e45d21986b') };
    return { cls: 'bg-success-100 text-success-700', text: translate('admin.generated.k_cc0382919787') };
}

const leadingBlanks = computed(() => (props.days.length ? props.days[0].dow - 1 : 0));
const actionableCount = computed(() => props.days.filter((d) => d.actionable && !d.is_past).length);

function go(month, roomTypeId = props.selectedTypeId) {
    if (navigationLocked.value) return;
    selected.value = null;
    router.get(route('pricing.smart.index'), { room_type_id: roomTypeId, month }, {
        preserveScroll: true,
        onStart: () => { typeLoading.value = true; },
        onFinish: () => { typeLoading.value = false; },
    });
}
function changeType(event) { go(props.month, event.target.value); }
function dayNum(d) { return parseInt(d.date.slice(8, 10), 10); }
function longDate(date) {
    return new Date(date + 'T00:00:00').toLocaleDateString(getIntlLocale(), { weekday: 'long', day: '2-digit', month: 'long' });
}
function fmtPrice(v) { const n = Number(v) || 0; return n % 1 === 0 ? String(n) : n.toFixed(2); }

// Demand heat: cell background darkens as the night fills (standard hotel code:
// white=free, amber=filling, red=full). Holidays keep the flag, weekends a dot.
function cellTone(d) {
    const occ = d.occupancy_pct || 0;
    if (d.total > 0 && d.booked >= d.total) return 'bg-error-100 border-error-200 hover:border-error-300';
    if (occ >= 70) return 'bg-warning-100 border-warning-200 hover:border-warning-300';
    if (occ >= 40) return 'bg-warning-50 border-warning-100 hover:border-warning-200';
    return 'bg-white border-neutral-100 hover:border-neutral-200';
}

function pick(d) { if (!d.is_past) { selected.value = d; manualPrice.value = ''; explainText.value = ''; } }

function apply(d, price = null, useLatestSuggestion = false) {
    if (applySaving.value) return;
    const payload = { date: d.date, room_type_id: props.selectedTypeId };
    let p = null;
    if (!useLatestSuggestion) {
        p = Number(price);
        if (!p || p <= 0) { toasts.value?.error(translate('admin.generated.k_a2e0f496ad8a')); return; }
        payload.price = p;
    }
    applySaving.value = true;
    router.post(route('pricing.smart.apply'), payload, {
        preserveScroll: true,
        onSuccess: (page) => {
            const fallback = useLatestSuggestion
                ? translate('admin.generated.k_52c961ea7dd3', { p0: longDate(d.date) })
                : translate('admin.generated.k_e6867b8da4e1', { p0: props.currency, p1: fmtPrice(p), p2: longDate(d.date) });
            if (showServerResult(page, fallback)) selected.value = null;
        },
        onError: (e) => toasts.value?.error(Object.values(e)[0] || translate('admin.generated.k_ae66ac67b4f6')),
        onFinish: () => { applySaving.value = false; },
    });
}
function remove(d) {
    if (removeSaving.value) return;
    removeSaving.value = true;
    router.post(route('pricing.smart.remove'), { date: d.date, room_type_id: props.selectedTypeId }, {
        preserveScroll: true,
        onSuccess: (page) => { showServerResult(page, translate('admin.generated.k_d8cc75284320')); selected.value = null; },
        onError: (e) => toasts.value?.error(Object.values(e)[0] || translate('admin.generated.k_a0709e8ee005')),
        onFinish: () => { removeSaving.value = false; },
    });
}

function syncLabel(ts) {
    if (!ts) return 'ende pa sinkronizim';
    return new Date(ts.replace(' ', 'T')).toLocaleString(getIntlLocale(), { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}

</script>

<template>
    <AppLayout>
        <PageHeader
            :title="$t('admin.generated.k_2caed195d969')"
            :breadcrumbs="[{ label: $t('admin.generated.k_b16aefb118f8'), href: '/dashboard' }, { label: $t('admin.generated.k_b9f4b8d8c97f') }]"
        >
            <template #actions>
                <span class="hidden sm:inline-flex items-center gap-1.5 text-tiny text-neutral-500 mr-2" :title="$t('admin.generated.k_f11f36535a92')">
                    <i class="w-2 h-2 rounded-full" :class="lastSyncAt ? 'bg-success-500' : 'bg-neutral-300'" />
{{ $t('admin.generated.k_4c3b98d8c589') }} {{ syncLabel(lastSyncAt) }}
                </span>
                <Link href="/pms/pricing" class="no-underline"><Button variant="outline">{{ $t('admin.generated.k_cba33d0b7d25') }}</Button></Link>
            </template>
        </PageHeader>

        <div class="mt-6">
            <Card :class="typeLoading ? 'pointer-events-none opacity-60' : ''">
                <!-- toolbar: type · bounds · month nav -->
                <div class="flex flex-wrap items-center justify-between gap-4 mb-5">
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="flex items-center gap-2.5">
                            <label class="text-label text-neutral-600">{{ $t('admin.generated.k_ed81100d62cc') }}</label>
                            <select
                                :value="typeId"
                                :disabled="typeLoading || navigationLocked"
                                class="rounded-xl border border-neutral-200 px-3.5 py-2.5 text-body-sm font-medium text-primary-900 focus:border-ionian focus:ring-2 focus:ring-ionian/30 min-w-[200px]"
                                @change="changeType"
                            >
                                <option v-for="t in roomTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
                            </select>
                        </div>

                        <button
                            class="inline-flex items-center gap-1.5 text-tiny font-semibold text-neutral-600 border border-neutral-200 rounded-xl px-3 py-2.5 hover:border-ionian hover:text-primary-900 transition"
                            :disabled="navigationLocked"
                            :class="navigationLocked ? 'opacity-50 cursor-not-allowed' : ''"
                            :title="$t('admin.generated.k_68a72459f378')"
                            @click="openBounds"
                        >
{{ $t('admin.generated.k_e17832ba8bce') }} {{ boundsLabel }}
                        </button>
                    </div>

                    <div class="flex items-center gap-3">
                        <span class="text-body font-semibold text-primary-900 capitalize min-w-[130px] text-center">{{ monthLabel }}</span>
                        <div class="flex gap-1.5">
                            <Button size="sm" variant="outline" :disabled="navigationLocked" @click="go(prevMonth)">‹</Button>
                            <Button size="sm" variant="outline" :disabled="navigationLocked" @click="go(nextMonth)">›</Button>
                        </div>
                    </div>
                </div>

                <!-- strategy slider: the ONE tuning knob -->
                <div class="flex flex-wrap items-center gap-3 mb-5">
                    <span class="text-label text-neutral-600">{{ $t('admin.generated.k_0938c2966a3a') }}</span>
                    <div class="inline-flex rounded-xl border border-neutral-200 p-1 bg-neutral-50">
                        <button
                            v-for="s in strategies"
                            :key="s.key"
                            :class="[
                                'px-3.5 py-1.5 rounded-lg text-small font-semibold transition',
                                strategy === s.key ? 'bg-white shadow text-primary-900 border border-neutral-200' : 'text-neutral-500 hover:text-primary-900',
                                navigationLocked ? 'opacity-50 cursor-not-allowed' : '',
                            ]"
                            :title="s.hint"
                            :disabled="navigationLocked"
                            :aria-busy="strategySaving"
                            @click="setStrategy(s.key)"
                        >
                            {{ s.label }}
                        </button>
                    </div>
                    <span class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-200 bg-white px-2.5 py-1.5 text-tiny text-neutral-500" :title="$t('smartPricing.rounding.tooltip')">
                        <span aria-hidden="true">≈</span>
                        {{ roundingPolicyLabel }}
                    </span>
                    <span v-if="actionableCount" class="inline-flex items-center gap-2 text-tiny text-neutral-500">
                        {{ actionableCount }} {{ $t('admin.generated.k_0fb9c71df27a') }} <Button size="sm" variant="outline" :disabled="navigationLocked" @click="askBulkMonth">{{ $t('admin.generated.k_47b01d6519f6') }}</Button>
                    </span>
                </div>

                <div v-if="otaPrograms.booking || otaPrograms.expedia" class="grid sm:grid-cols-2 gap-3 mb-5">
                    <div v-for="(program, key) in otaPrograms" :key="key" class="rounded-xl border border-neutral-200 bg-neutral-50 px-3 py-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-body-sm font-bold text-primary-900">{{ key === 'booking' ? $t('admin.generated.k_16c93c3b7fc4') : $t('admin.generated.k_d47b7f97166a') }}</span>
                            <span class="text-tiny font-bold text-info-700">{{ $t('admin.generated.k_d532868eb668') }}{{ program.required_modifier_pct }}%</span>
                        </div>
                        <p class="text-tiny text-neutral-500 mt-1">
                            <template v-if="program.discounts.length">{{ program.discounts.map((d) => d.label + ' ' + d.pct + '%').join(' · ') }}</template>
                            <template v-else>{{ $t('admin.generated.k_890e525639bb') }}</template>
                            <template v-if="program.preferred_partner"> {{ $t('admin.generated.k_64defed5a672') }}</template>
                        </p>
                    </div>
                </div>

                <div v-if="!roomTypes.length" class="py-16 text-center text-body-sm text-neutral-500">
{{ $t('admin.generated.k_3bd5e4318173') }} </div>

                <template v-else>
                    <!-- weekday header -->
                    <div class="grid grid-cols-7 gap-1.5 sm:gap-2 mb-2">
                        <span v-for="d in dows" :key="d" class="text-tiny font-bold uppercase tracking-wide text-neutral-400 text-center">{{ d }}</span>
                    </div>

                    <!-- calendar grid: price + VISIBLE suggestion on every cell -->
                    <div class="grid grid-cols-7 gap-1.5 sm:gap-2">
                        <div v-for="b in leadingBlanks" :key="'b' + b" />
                        <div
                            v-for="d in days"
                            :key="d.date"
                            :class="[
                                'min-h-[84px] sm:min-h-[96px] rounded-xl border p-1.5 sm:p-2 relative transition',
                                cellTone(d),
                                d.is_past ? 'opacity-45 pointer-events-none' : 'cursor-pointer hover:-translate-y-0.5 hover:shadow-md',
                                selected && selected.date === d.date ? 'ring-2 ring-ionian ring-offset-1' : '',
                            ]"
                            @click="pick(d)"
                        >
                            <div class="flex items-start justify-between">
                                <span :class="['text-tiny font-bold', d.holiday ? 'text-error-600' : 'text-neutral-400']">
                                    {{ dayNum(d) }}<span v-if="d.is_weekend" class="text-warning-500">·</span>
                                </span>
                                <span v-if="d.holiday" class="text-error-600 text-tiny leading-none" :title="d.holiday">⚑</span>
                            </div>

                            <div class="mt-1 text-body-sm sm:text-h4 font-bold text-primary-900 tabular-nums leading-none">{{ currency }}{{ fmtPrice(d.current_price) }}</div>

                            <!-- THE suggestion, visible on the grid -->
                            <div v-if="d.actionable" class="mt-1 text-tiny font-bold tabular-nums leading-none" :class="d.adjustment_pct > 0 ? 'text-success-700' : 'text-info-700'">
                                {{ d.adjustment_pct > 0 ? '▲' : '▼' }} {{ currency }}{{ fmtPrice(d.suggested_price) }}<span v-if="d.clamped" :title="'I ndalur te kufiri ' + (d.clamped === 'max' ? $t('admin.generated.k_a79d1410fa68') : $t('admin.generated.k_3925eeee4836'))"> 🔒</span>
                            </div>

                            <!-- rate shopping: market median pill, coloured by our position -->
                            <div
                                v-if="market[d.date]"
                                class="mt-1 inline-flex items-center gap-0.5 rounded-md px-1 py-0.5 text-[10px] font-semibold tabular-nums leading-none"
                                :class="marketTone(d)"
                                :title="$t('admin.generated.k_e01aaf18e6e8') + market[d.date].count + $t('admin.generated.k_771f734d54d1') + currency + market[d.date].min + '–' + currency + market[d.date].max"
                            >
                                ⌂ {{ currency }}{{ fmtPrice(market[d.date].median) }}
                            </div>

                            <span v-if="d.total > 0 && d.booked >= d.total" class="absolute bottom-1.5 left-2 text-[10px] font-bold text-white bg-primary-900 rounded px-1.5 py-0.5 leading-none">{{ $t('admin.generated.k_d083746b5f78') }}</span>
                            <span v-if="d.has_override" class="absolute bottom-2 right-2 w-2 h-2 rounded-full bg-info-500" :title="$t('admin.generated.k_53c8a7e2a8b4')" />
                        </div>
                    </div>

                    <!-- legend -->
                    <div class="flex flex-wrap gap-x-5 gap-y-2 mt-5 text-tiny text-neutral-500">
                        <span><span class="text-success-700 font-bold mr-1">▲</span>{{ $t('admin.generated.k_ab198a82455d') }}</span>
                        <span><span class="text-info-700 font-bold mr-1">▼</span>{{ $t('admin.generated.k_4af68492052d') }}</span>
                        <span><span class="font-bold mr-1">🔒</span>{{ $t('admin.generated.k_1a7e22f88381') }}</span>
                        <span><i class="inline-block w-2.5 h-2.5 rounded-sm bg-warning-100 border border-warning-200 mr-1.5 align-[-1px]" />{{ $t('admin.generated.k_18d3d8e875a8') }}</span>
                        <span><i class="inline-block w-2.5 h-2.5 rounded-sm bg-error-100 border border-error-200 mr-1.5 align-[-1px]" />{{ $t('admin.generated.k_da853b04a9bf') }}</span>
                        <span><span class="text-error-600 font-bold mr-1">⚑</span>{{ $t('admin.generated.k_e9b445ba65ca') }}</span>
                        <span v-if="marketEnabled"><span class="font-bold mr-1">⌂</span>{{ $t('admin.generated.k_fbef875466bf') }}</span>
                        <span><i class="inline-block w-2 h-2 rounded-full bg-info-500 mr-1.5 align-[0px]" />{{ $t('admin.generated.k_647217d35b40') }}</span>
                    </div>

                    <!-- selected day: "Pse ky çmim?" -->
                    <div v-if="selected" class="mt-5 border border-neutral-200 rounded-2xl overflow-hidden">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 px-4 py-3 border-b border-neutral-200" :class="selected.holiday ? 'bg-error-50' : 'bg-neutral-50'">
                            <span class="min-w-0 text-body-sm font-semibold text-primary-900 capitalize flex flex-wrap items-center gap-2">
                                <span v-if="selected.holiday" class="text-error-600">⚑</span>
                                {{ longDate(selected.date) }}
                                <span v-if="selected.holiday" class="text-tiny font-semibold text-error-700 bg-error-100 rounded-full px-2 py-0.5">{{ selected.holiday }}</span>
                                <span v-else-if="selected.is_weekend" class="text-tiny font-semibold text-warning-700 bg-warning-100 rounded-full px-2 py-0.5">{{ $t('admin.generated.k_f1f70735e64e') }}</span>
                            </span>
                            <span class="text-tiny text-neutral-500 text-left sm:text-right break-words">
                                {{ selected.booked }}/{{ selected.total }} {{ $t('admin.generated.k_4d619dfcbf7e') }} <b class="text-primary-900">{{ selected.occupancy_type_pct }}%</b>
{{ $t('admin.generated.k_09f293eeeeb9') }} <b class="text-primary-900">{{ selected.occupancy_property_pct }}%</b>
{{ $t('admin.generated.k_2fbd0d6c29b6') }} <b class="text-primary-900">{{ selected.occupancy_pct }}%</b>
                                <template v-if="selected.total > 0 && selected.booked >= selected.total"> · <b class="text-primary-900">{{ $t('admin.generated.k_da853b04a9bf') }}</b></template>
                            </span>
                        </div>

                        <div class="px-4 py-4 space-y-4">
                            <!-- engine suggestion (the truth) -->
                            <div class="flex flex-wrap items-center justify-between gap-4">
                                <div>
                                    <div class="flex items-baseline gap-3">
                                        <span class="text-body-sm text-neutral-400 tabular-nums" :class="selected.actionable ? 'line-through' : ''">{{ currency }}{{ fmtPrice(selected.current_price) }}</span>
                                        <template v-if="selected.actionable">
                                            <span class="text-neutral-400">→</span>
                                            <span class="text-h3 font-extrabold text-primary-900 tabular-nums">{{ currency }}{{ fmtPrice(selected.suggested_price) }}</span>
                                            <span class="text-small font-bold px-2 py-0.5 rounded-lg" :class="selected.adjustment_pct > 0 ? 'bg-success-50 text-success-700' : 'bg-info-50 text-info-700'">
                                                {{ selected.adjustment_pct > 0 ? '+' : '' }}{{ selected.adjustment_pct }}%
                                            </span>
                                        </template>
                                    </div>
                                    <p v-if="!selected.actionable" class="text-tiny text-neutral-400 mt-1">{{ selected.quiet_reason || $t('admin.generated.k_745f1ba16e3f') }}</p>
                                </div>
                                <div class="flex flex-wrap gap-2.5">
                                    <Button v-if="selected.actionable" variant="primary" :loading="applySaving" :disabled="removeSaving" @click="apply(selected, null, true)">{{ $t('admin.generated.k_4a87e4912c53') }} {{ currency }}{{ fmtPrice(selected.suggested_price) }}</Button>
                                    <Button variant="outline" size="sm" :disabled="applySaving || removeSaving" @click="askBulkWeek">{{ $t('admin.generated.k_9fa745cba6c8') }}</Button>
                                    <Button v-if="selected.has_override" variant="ghost" :loading="removeSaving" :disabled="applySaving" @click="remove(selected)">{{ $t('admin.generated.k_1cf57acc3d1f') }}</Button>
                                </div>
                            </div>

                            <div v-if="selected.ota_prices" class="grid sm:grid-cols-2 gap-3">
                                <div v-for="(ota, key) in selected.ota_prices" :key="key" class="rounded-xl border border-info-100 bg-info-50/50 p-3">
                                    <p class="text-tiny font-bold uppercase tracking-wide text-info-700">{{ key === 'booking' ? $t('admin.generated.k_16c93c3b7fc4') : $t('admin.generated.k_d47b7f97166a') }}</p>
                                    <div class="mt-2 space-y-1 text-body-sm">
                                        <div class="flex justify-between gap-3"><span class="text-neutral-500">{{ $t('admin.generated.k_72c51795efd1') }}</span><b class="tabular-nums">{{ currency }}{{ fmtPrice(ota.target_price) }}</b></div>
                                        <div class="flex justify-between gap-3"><span class="text-neutral-500">{{ $t('admin.generated.k_4b37e487f890') }}</span><b class="tabular-nums text-info-700">{{ currency }}{{ fmtPrice(ota.published_price) }}</b></div>
                                        <div class="flex justify-between gap-3"><span class="text-neutral-500">{{ $t('admin.generated.k_8b36140e783d') }}</span><b class="tabular-nums">{{ currency }}{{ fmtPrice(ota.estimated_net) }}</b></div>
                                    </div>
                                </div>
                            </div>

                            <!-- TREGU — competitor entry prices for this date (display only) -->
                            <div v-if="market[selected.date]" class="rounded-xl border border-neutral-200 bg-white p-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-tiny font-bold uppercase tracking-wide text-neutral-400">{{ $t('admin.generated.k_3db3ab7fc5ec') }} {{ market[selected.date].count }} {{ $t('admin.generated.k_97b905d25eeb') }}</p>
                                    <span class="text-tiny text-neutral-400">{{ $t('admin.generated.k_a81913a6ed14') }}</span>
                                </div>
                                <div class="mt-2 flex flex-wrap items-center gap-x-6 gap-y-2">
                                    <div>
                                        <span class="text-tiny text-neutral-500">{{ $t('admin.generated.k_ce140b5da503') }}</span>
                                        <div class="text-h4 font-extrabold text-primary-900 tabular-nums leading-tight">{{ currency }}{{ fmtPrice(market[selected.date].median) }}</div>
                                    </div>
                                    <div>
                                        <span class="text-tiny text-neutral-500">{{ $t('admin.generated.k_7fdb5c5d0dc2') }}</span>
                                        <div class="text-body-sm font-semibold text-neutral-600 tabular-nums leading-tight mt-1">{{ currency }}{{ fmtPrice(market[selected.date].min) }} – {{ currency }}{{ fmtPrice(market[selected.date].max) }}</div>
                                    </div>
                                    <span v-if="marketBadge(selected)" class="text-small font-bold px-2.5 py-1 rounded-lg" :class="marketBadge(selected).cls">
                                        {{ marketBadge(selected).text }}
                                    </span>
                                </div>
                                <p class="text-tiny text-neutral-400 mt-2">{{ $t('admin.generated.k_bebb7a96065e') }}</p>
                            </div>

                            <!-- PSE KY ÇMIM? — the factor breakdown, plain Albanian -->
                            <div v-if="selected.factors && selected.factors.length" class="rounded-xl border border-neutral-100 bg-neutral-50 p-3">
                                <p class="text-tiny font-bold uppercase tracking-wide text-neutral-400 mb-2">{{ $t('admin.generated.k_b2be658767b6') }}</p>
                                <div class="space-y-1.5">
                                    <div class="flex items-center justify-between text-body-sm text-neutral-600">
                                        <span>{{ $t('admin.generated.k_a644a16acd62') }}</span>
                                        <span class="font-semibold tabular-nums text-primary-900">{{ currency }}{{ fmtPrice(selected.reference) }}</span>
                                    </div>
                                    <div v-for="(f, i) in selected.factors" :key="i" class="flex items-center justify-between text-body-sm text-neutral-600">
                                        <span class="min-w-0 break-words">{{ f.label }}</span>
                                        <span class="font-semibold tabular-nums" :class="f.pct > 0 ? 'text-success-700' : 'text-info-700'">{{ f.pct > 0 ? '+' : '' }}{{ f.pct }}%</span>
                                    </div>
                                    <div v-if="selected.rounding?.applied" class="flex items-center justify-between text-body-sm text-neutral-600 border-t border-neutral-200 pt-1.5">
                                        <span>{{ $t('smartPricing.rounding.title') }}</span>
                                        <span class="font-semibold tabular-nums text-primary-900">{{ currency }}{{ fmtPrice(selected.rounding.before) }} → {{ currency }}{{ fmtPrice(selected.rounding.after) }}</span>
                                    </div>
                                    <div v-if="selected.clamped" class="flex items-center justify-between text-body-sm font-semibold text-warning-700 border-t border-neutral-200 pt-1.5">
                                        <span>{{ $t('admin.generated.k_48c822e12681') }} {{ selected.clamped === 'max' ? $t('admin.generated.k_a79d1410fa68') : $t('admin.generated.k_3925eeee4836') }}</span>
                                        <span class="tabular-nums">{{ currency }}{{ fmtPrice(selected.suggested_price) }}</span>
                                    </div>
                                    <p class="text-tiny text-neutral-400 border-t border-neutral-200 pt-1.5">
{{ $t('admin.generated.k_83599e8d6f14') }} </p>
                                </div>
                            </div>

                            <!-- "✦ Shpjegim AI" — the breakdown, told as one warm sentence -->
                            <div v-if="aiConfigured && selected.factors && selected.factors.length" class="rounded-xl border border-accent-100 bg-accent-50/60 p-3">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <span class="text-body-sm font-bold text-accent-700">{{ $t('admin.generated.k_15d2fd9a257c') }}</span>
                                    <Button size="sm" variant="outline" :loading="explainLoading" @click="explainDay">{{ $t('admin.generated.k_31f9cf792025') }}</Button>
                                </div>
                                <p v-if="explainText" class="text-body-sm text-neutral-700 mt-2">{{ explainText }}</p>
                            </div>

                            <!-- manual price — full control on any night -->
                            <div class="flex flex-wrap items-center gap-2.5 pt-3 border-t border-neutral-100">
                                <label class="text-tiny text-neutral-500 shrink-0">{{ $t('admin.generated.k_d9b99556d10d') }}</label>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-body-sm text-neutral-400">{{ currency }}</span>
                                    <input v-model="manualPrice" type="number" min="1" step="1" :placeholder="String(fmtPrice(selected.current_price))"
                                        class="w-28 rounded-lg border border-neutral-200 px-2.5 py-1.5 text-body-sm tabular-nums focus:border-ionian focus:ring-2 focus:ring-ionian/30" />
                                    <Button size="sm" variant="outline" :loading="applySaving" :disabled="removeSaving" @click="apply(selected, manualPrice)">{{ $t('admin.generated.k_955d3a5cd9f6') }}</Button>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </Card>
        </div>

        <!-- Gemini: eventet + raporti javor + pyet -->
        <div class="mt-6 grid lg:grid-cols-2 gap-6 items-start">
            <Card>
                <div class="flex items-center justify-between gap-3 mb-3">
                    <div>
                        <h2 class="text-h4 text-primary-900 leading-tight">{{ $t('admin.generated.k_4f2d557d15ad') }}</h2>
                        <p class="text-tiny text-neutral-500">{{ $t('admin.generated.k_ed8cc7064ae7') }}</p>
                    </div>
                    <Button v-if="aiConfigured" size="sm" variant="outline" :loading="evLoading" :disabled="eventBusy" @click="suggestEvents">{{ $t('admin.generated.k_dcae9b3336d7') }}</Button>
                </div>

                <div v-if="!upcomingEvents.length" class="text-body-sm text-neutral-400 py-3">{{ $t('admin.generated.k_3d1d9cdf89f5') }}</div>
                <div v-else class="space-y-1.5 mb-2">
                    <div v-for="ev in upcomingEvents" :key="ev.id + ev.date_from" class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-body-sm border border-neutral-100 rounded-lg px-3 py-1.5">
                        <span class="text-neutral-700 min-w-0 break-words">
                            <b class="text-primary-900">{{ ev.name }}</b> · {{ fmtRange(ev.date_from, ev.date_to) }}
                            <span v-if="ev.affects_price" class="font-semibold" :class="ev.uplift_pct > 0 ? 'text-success-700' : 'text-info-700'">
                                {{ ev.uplift_pct > 0 ? '+' : '' }}{{ ev.uplift_pct }}{{ $t('admin.generated.k_13398e78f725') }} </span>
                            <span v-else class="text-tiny text-neutral-400 font-semibold">{{ $t('admin.generated.k_8047e753d836') }}</span>
                            <span v-if="ev.recurring" class="text-tiny text-neutral-500 font-semibold">{{ $t('admin.generated.k_8d111b252d66') }}</span>
                            <span v-if="ev.source === 'ai'" class="text-tiny text-accent-600 font-semibold ml-1">✦</span>
                        </span>
                        <div class="flex items-center gap-1.5 shrink-0 self-end sm:self-auto">
                            <Button size="sm" variant="ghost" :disabled="eventBusy" @click="openEventImpact(ev)">{{ $t('admin.generated.k_208d925218bb') }}</Button>
                            <button class="inline-flex h-11 w-11 items-center justify-center rounded-md text-neutral-400 hover:text-error-600 hover:bg-error-50 focus:outline-none focus:ring-2 focus:ring-error-500/40"
                                :disabled="eventBusy" :class="eventDeletingId === ev.id ? 'opacity-50 cursor-wait' : ''"
                                :aria-label="'Hiq eventin ' + ev.name" :title="'Hiq eventin ' + ev.name" @click="removeEventRow(ev)">✕</button>
                        </div>
                    </div>
                </div>

                <div v-if="evSuggestions.length" class="mt-3 border-t border-neutral-100 pt-3">
                    <p class="text-tiny font-bold uppercase tracking-wide text-neutral-400 mb-2">{{ $t('admin.generated.k_a104c3d13673') }}</p>
                    <div v-for="ev in evSuggestions" :key="`${ev.name}|${ev.date_from}|${ev.date_to}`" class="flex flex-col sm:flex-row items-start justify-between gap-3 border border-accent-100 bg-accent-50/40 rounded-lg px-3 py-2 mb-1.5">
                        <div class="min-w-0 text-body-sm text-neutral-700 break-words">
                            <b class="text-primary-900">{{ ev.name }}</b> · {{ fmtRange(ev.date_from, ev.date_to) }}
                            <span v-if="ev.uplift_pct" class="font-semibold" :class="ev.uplift_pct > 0 ? 'text-success-700' : 'text-info-700'">
                                {{ ev.uplift_pct > 0 ? '+' : '' }}{{ ev.uplift_pct }}%
                            </span>
                            <span v-else class="text-tiny text-neutral-400 font-semibold">{{ $t('admin.generated.k_8047e753d836') }}</span>
                            <p class="text-tiny text-neutral-500">{{ ev.reason }}</p>
                        </div>
                        <div class="flex gap-1.5 shrink-0 self-end sm:self-auto">
                            <Button size="sm" variant="primary" :loading="eventApprovingKey === suggestionKey(ev)" :disabled="eventBusy" @click="approveEvent(ev)">{{ $t('admin.generated.k_fb7873d0253a') }}</Button>
                            <Button size="sm" variant="ghost" :disabled="eventBusy" @click="evSuggestions = evSuggestions.filter((candidate) => suggestionKey(candidate) !== suggestionKey(ev))">{{ $t('admin.generated.k_fb1d7398cfeb') }}</Button>
                        </div>
                    </div>
                </div>
            </Card>

            <Card>
                <div class="flex items-center justify-between gap-3 mb-3">
                    <div>
                        <h2 class="text-h4 text-primary-900 leading-tight">{{ $t('admin.generated.k_cef373b3cb2d') }}</h2>
                        <p class="text-tiny text-neutral-500">{{ $t('admin.generated.k_f1715a0be999') }}</p>
                    </div>
                    <Button v-if="aiConfigured" size="sm" variant="outline" :loading="reportLoading" @click="generateReport">{{ $t('admin.generated.k_cd4e49a668fe') }}</Button>
                </div>

                <div v-if="!aiConfigured" class="p-3 rounded-lg bg-warning-50 border border-warning-200 text-body-sm text-warning-800">
{{ $t('admin.generated.k_38fa26a8c12e') }} <b>{{ $t('admin.generated.k_706b10594075') }}</b>.
                </div>
                <template v-else-if="latestReport">
                    <p class="text-body-sm font-bold text-primary-900">{{ latestReport.title }}</p>
                    <p class="text-body-sm text-neutral-700 mt-1.5 whitespace-pre-line">{{ latestReport.body }}</p>
                    <ul v-if="latestReport.highlights && latestReport.highlights.length" class="mt-2.5 space-y-1">
                        <li v-for="(h, i) in latestReport.highlights" :key="i" class="text-body-sm text-neutral-600 flex gap-2"><span class="text-success-600 shrink-0">•</span>{{ h }}</li>
                    </ul>
                </template>
                <p v-else class="text-body-sm text-neutral-400 py-3">{{ $t('admin.generated.k_2654f1bfcd00') }}</p>

                <div v-if="aiConfigured" class="mt-4 border-t border-neutral-100 pt-3">
                    <label class="block text-tiny font-bold uppercase tracking-wide text-neutral-400 mb-1.5">{{ $t('admin.generated.k_e659a5f3c3f3') }}</label>
                    <div class="flex gap-1.5">
                        <input v-model="askQ" type="text" :placeholder="$t('admin.generated.k_32293f97bee8')" maxlength="500"
                            class="flex-1 rounded-lg border border-neutral-200 px-3 py-1.5 text-body-sm focus:border-ionian focus:ring-2 focus:ring-ionian/30" @keyup.enter="askAi" />
                        <Button size="sm" variant="primary" :loading="askLoading" @click="askAi">{{ $t('admin.generated.k_2330aa02e8fb') }}</Button>
                    </div>
                    <p v-if="askA" class="text-body-sm text-neutral-700 mt-2.5 bg-neutral-50 border border-neutral-100 rounded-lg p-2.5">{{ askA }}</p>
                </div>
            </Card>
        </div>

        <!-- Autopiloti me kufij -->
        <Card class="mt-6">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                <div>
                    <h2 class="text-h4 text-primary-900 leading-tight">
{{ $t('admin.generated.k_e0795c36239b') }} <span :class="['text-tiny font-bold px-2 py-0.5 rounded-full align-[2px] ml-1', autopilot.enabled ? 'bg-success-50 text-success-700' : 'bg-neutral-100 text-neutral-500']">
                            {{ autopilot.enabled ? $t('admin.generated.k_e68ca2d73e14') : $t('admin.generated.k_1b880ad4606f') }}
                        </span>
                    </h2>
                    <p class="text-tiny text-neutral-500">{{ $t('admin.generated.k_6377c2f815e8') }}</p>
                </div>
                <Button :variant="autopilot.enabled ? 'outline' : 'primary'" :disabled="navigationLocked" @click="openAutopilot(!autopilot.enabled)">
                    {{ autopilot.enabled ? $t('admin.generated.k_71c8257b165b') : $t('admin.generated.k_f537b29e7b51') }}
                </Button>
            </div>

            <div v-if="autopilot.enabled" class="flex flex-wrap gap-x-5 gap-y-1 text-tiny text-neutral-500 mb-3">
                <span>{{ $t('admin.generated.k_a95320451973') }} <b class="text-primary-900">{{ autopilot.materiality_pct }}%</b></span>
                <span>{{ $t('admin.generated.k_8bfd048eeb32') }} <b class="text-primary-900">±{{ autopilot.daily_cap_pct }}%</b></span>
                <span>{{ $t('admin.generated.k_7a5451b678d4') }} <b class="text-primary-900">{{ autopilot.protect_manual_days }} {{ $t('admin.generated.k_bc5ae2005140') }}</b></span>
                <span v-if="autopilot.pause_from">{{ $t('admin.generated.k_ce2b00bb654b') }} <b class="text-primary-900">{{ fmtRange(autopilot.pause_from, autopilot.pause_to) }}</b></span>
            </div>

            <template v-if="autopilot.logs && autopilot.logs.length">
                <p class="text-tiny font-bold uppercase tracking-wide text-neutral-400 mb-2">{{ $t('admin.generated.k_f22d1c7e8087') }}</p>
                <div class="space-y-1.5">
                    <div v-for="l in autopilot.logs" :key="l.id" class="flex items-center justify-between gap-2 text-body-sm border border-neutral-100 rounded-lg px-3 py-1.5" :class="l.reverted ? 'opacity-50' : ''">
                        <span class="text-neutral-700">
                            {{ fmtRange(l.date, l.date) }} · {{ l.room_type }} ·
                            <span class="text-neutral-400 tabular-nums">{{ l.old_price !== null ? currency + fmtPrice(l.old_price) : $t('admin.generated.k_4f0b089e3274') }}</span>
                            <span class="text-neutral-400">→</span>
                            <b class="text-primary-900 tabular-nums">{{ currency }}{{ fmtPrice(l.new_price) }}</b>
                        </span>
                        <Button v-if="!l.reverted" size="sm" variant="ghost" :loading="revertSavingId === l.id" :disabled="!!revertSavingId" @click="revertAutopilot(l)">{{ $t('admin.generated.k_767191e0325d') }}</Button>
                        <span v-else class="text-tiny text-neutral-400 shrink-0">{{ $t('admin.generated.k_0f8748d20f6f') }}</span>
                    </div>
                </div>
            </template>
            <p v-else class="text-body-sm text-neutral-400">{{ $t('admin.generated.k_6f6d8cae3c88') }}</p>
        </Card>

        <!-- event impact: editing the rule never publishes a price by itself -->
        <Modal :show="!!eventEdit" :title="$t('admin.generated.k_4160491f175f')" :closeable="!eventSaving" @close="eventEdit = null">
            <template v-if="eventEdit">
                <p class="text-body-sm text-neutral-600 mb-4">
                    <b class="break-words">{{ eventEdit.name }}</b> · {{ fmtRange(eventEdit.date_from, eventEdit.date_to) }}{{ $t('admin.generated.k_2eacc03c7b21') }} <span v-if="eventEdit.recurring" class="font-semibold">{{ $t('admin.generated.k_4cb759f7d21e') }}</span>
                </p>
                <label for="event-uplift" class="block text-label text-neutral-600 mb-1">{{ $t('admin.generated.k_b2b2ac3ad733') }}</label>
                <input id="event-uplift" v-model="eventUplift" type="number" min="-50" max="100" step="1" :placeholder="$t('admin.generated.k_6a89f0cd9c3f')"
                    class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm tabular-nums focus:border-ionian focus:ring-2 focus:ring-ionian/30" />
                <div class="mt-3 rounded-lg border border-neutral-100 bg-neutral-50 p-3 text-body-sm text-neutral-700">
                    {{ eventImpactPreview }}
                    <p class="text-tiny text-neutral-500 mt-1">
{{ $t('admin.generated.k_f75b904a9070') }} <b>{{ $t('admin.generated.k_d641b4773882') }}</b><template v-if="eventEdit.recurring"> {{ $t('admin.generated.k_993ab54ebaa5') }}</template>{{ $t('admin.generated.k_72bd8ef353de') }} </p>
                    <p v-if="autopilot.enabled && eventRuleChanges" class="text-tiny text-warning-700 mt-1 font-semibold">
{{ $t('admin.generated.k_b86d96fdff0f') }} </p>
                </div>
                <div v-if="eventUplift !== '' && Number(eventUplift) !== 0 && overlappingPricedEvents.length"
                    class="mt-3 rounded-lg border border-warning-200 bg-warning-50 p-3 text-body-sm text-warning-800 break-words">
{{ $t('admin.generated.k_2a3dad985bad') }} <b>{{ overlappingPricedEvents.map((ev) => ev.name).join(', ') }}</b>{{ $t('admin.generated.k_16164cb05ee3') }} </div>
                <label v-if="eventRuleChanges" class="mt-3 flex items-start gap-2 text-body-sm text-neutral-700 cursor-pointer">
                    <input v-model="eventScopeConfirmed" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-accent-600 focus:ring-accent-500" />
                    <span>{{ $t('admin.generated.k_fdda571fabaf') }}</span>
                </label>
                <div class="flex justify-end gap-2.5 mt-5">
                    <Button variant="ghost" :disabled="eventSaving" @click="eventEdit = null">{{ $t('admin.generated.k_e6be55f7b99b') }}</Button>
                    <Button variant="primary" :loading="eventSaving" :disabled="eventRuleChanges && !eventScopeConfirmed" @click="saveEventImpact">{{ $t('admin.generated.k_c4ea0a01c3af') }}</Button>
                </div>
            </template>
        </Modal>

        <!-- autopilot confirm -->
        <Modal :show="apConfirmOpen" :title="$t('admin.generated.k_0a72a26f2748')" :closeable="!autopilotSaving" @close="apConfirmOpen = false">
            <p v-if="apForm.enabled" class="text-body-sm text-neutral-600 mb-4">
{{ $t('admin.generated.k_248469af8d52') }} <b>{{ $t('admin.generated.k_8c71dfb86f92') }}</b> {{ $t('admin.generated.k_6f88b745ab61') }} <b>{{ $t('admin.generated.k_110250154435') }}</b> {{ $t('admin.generated.k_ca9afd275bdc') }} </p>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-label text-neutral-600 mb-1">{{ $t('admin.generated.k_ac9f4f91fad2') }}</label>
                    <input v-model="apForm.materiality_pct" type="number" min="1" max="50" :disabled="autopilotSaving" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm" />
                </div>
                <div>
                    <label class="block text-label text-neutral-600 mb-1">{{ $t('admin.generated.k_d9e80c9ee18e') }}</label>
                    <input v-model="apForm.daily_cap_pct" type="number" min="1" max="50" :disabled="autopilotSaving" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm" />
                </div>
                <div>
                    <label class="block text-label text-neutral-600 mb-1">{{ $t('admin.generated.k_ab077725eefe') }}</label>
                    <input v-model="apForm.protect_manual_days" type="number" min="0" max="30" :disabled="autopilotSaving" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm" />
                </div>
                <div class="col-span-2 grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-label text-neutral-600 mb-1">{{ $t('admin.generated.k_fbb42ae4802f') }}</label>
                        <input v-model="apForm.pause_from" type="date" :disabled="autopilotSaving" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm" />
                    </div>
                    <div>
                        <label class="block text-label text-neutral-600 mb-1">{{ $t('admin.generated.k_aa592d4c6917') }}</label>
                        <input v-model="apForm.pause_to" type="date" :disabled="autopilotSaving" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm" />
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-2.5 mt-5">
                <Button variant="ghost" :disabled="autopilotSaving" @click="apConfirmOpen = false">{{ $t('admin.generated.k_e6be55f7b99b') }}</Button>
                <Button variant="primary" :loading="autopilotSaving" @click="saveAutopilot">{{ apForm.enabled ? $t('admin.generated.k_e3780c6079d6') : $t('admin.generated.k_266b98cb1029') }}</Button>
            </div>
        </Modal>

        <!-- bounds drawer -->
        <Modal :show="boundsOpen" :title="$t('admin.generated.k_768f7deff500')" :closeable="!boundsSaving" @close="boundsOpen = false">
            <p class="text-body-sm text-neutral-600 mb-4">
{{ $t('admin.generated.k_978adff2c151') }} <b>{{ $t('admin.generated.k_5857da1dd924') }}</b> {{ $t('admin.generated.k_9381a6f6e1ba') }} <b>{{ currentType?.name }}</b>{{ $t('admin.generated.k_a0bf0e7b50f4') }} </p>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-label text-neutral-600 mb-1">{{ $t('admin.generated.k_b9e60e040da6') }}{{ currency }}{{ $t('admin.generated.k_22b6d7e2c40b') }}</label>
                    <input v-model="boundsMin" type="number" min="0.01" step="1" :disabled="boundsSaving" :placeholder="$t('admin.generated.k_f44b4f058a29')"
                        class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm tabular-nums focus:border-ionian focus:ring-2 focus:ring-ionian/30" />
                </div>
                <div>
                    <label class="block text-label text-neutral-600 mb-1">{{ $t('admin.generated.k_ec05d203f404') }}{{ currency }}{{ $t('admin.generated.k_22b6d7e2c40b') }}</label>
                    <input v-model="boundsMax" type="number" min="0.01" step="1" :disabled="boundsSaving" :placeholder="$t('admin.generated.k_3617bc856b09')"
                        class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm tabular-nums focus:border-ionian focus:ring-2 focus:ring-ionian/30" />
                </div>
            </div>
            <div class="flex justify-end gap-2.5 mt-5">
                <Button variant="ghost" :disabled="boundsSaving" @click="boundsOpen = false">{{ $t('admin.generated.k_e6be55f7b99b') }}</Button>
                <Button variant="primary" :loading="boundsSaving" @click="saveBounds">{{ $t('admin.generated.k_dc0e3c59dd30') }}</Button>
            </div>
        </Modal>

        <!-- bulk apply confirm -->
        <Modal :show="!!bulk" :title="$t('admin.generated.k_563852f9e849')" :closeable="!bulkSaving" @close="bulk = null">
            <template v-if="bulk">
                <p class="text-body-sm text-neutral-600">
{{ $t('admin.generated.k_67b7e8d65d43') }} <b>{{ bulk.label }}</b>
                    ({{ currentType?.name }}{{ $t('admin.generated.k_79f15fd3feae') }} <b>{{ bulk.count }}</b> {{ $t('admin.generated.k_941039d7d8fe') }} </p>
                <div class="flex justify-end gap-2.5 mt-5">
                    <Button variant="ghost" :disabled="bulkSaving" @click="bulk = null">{{ $t('admin.generated.k_e6be55f7b99b') }}</Button>
                    <Button variant="primary" :loading="bulkSaving" @click="confirmBulk">{{ $t('admin.generated.k_a00222635f84') }}</Button>
                </div>
            </template>
        </Modal>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
