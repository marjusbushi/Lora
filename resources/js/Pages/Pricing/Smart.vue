<script setup>
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
    month: { type: String, default: '' },
    prevMonth: { type: String, default: '' },
    nextMonth: { type: String, default: '' },
    strategy: { type: String, default: 'balancuar' },
    currency: { type: String, default: '€' },
    aiConfigured: { type: Boolean, default: false },
    lastSyncAt: { type: String, default: null },
    upcomingEvents: { type: Array, default: () => [] },
    latestReport: { type: Object, default: null },
    autopilot: { type: Object, default: () => ({ enabled: false, logs: [] }) },
});

const toasts = ref(null);
const typeId = ref(props.selectedTypeId);
const selected = ref(null);
const manualPrice = ref('');

const currentType = computed(() => props.roomTypes.find((t) => Number(t.id) === Number(typeId.value)) || null);

// ── Strategjia (një rrëshqitës, tre shpejtësi) ──
const strategies = [
    { key: 'kujdesshem', label: 'I kujdesshëm', hint: 'ndryshime të vogla' },
    { key: 'balancuar', label: 'I balancuar', hint: 'rekomandohet' },
    { key: 'agresiv', label: 'Agresiv', hint: 'ndryshime të forta' },
];
function setStrategy(key) {
    if (key === props.strategy) return;
    router.post(route('pricing.smart.strategy'), { strategy: key }, {
        preserveScroll: true,
        onSuccess: () => toasts.value?.success('Strategjia u ndryshua — sugjerimet u rifreskuan.'),
    });
}

// ── Kufijtë min/max (guardrails) për tipin e zgjedhur ──
const boundsOpen = ref(false);
const boundsMin = ref('');
const boundsMax = ref('');
function openBounds() {
    boundsMin.value = currentType.value?.min_price ?? '';
    boundsMax.value = currentType.value?.max_price ?? '';
    boundsOpen.value = true;
}
function saveBounds() {
    router.put(route('pricing.smart.bounds', typeId.value), { min_price: boundsMin.value || null, max_price: boundsMax.value || null }, {
        preserveScroll: true,
        onSuccess: () => { boundsOpen.value = false; toasts.value?.success('Kufijtë u ruajtën.'); },
        onError: (e) => toasts.value?.error(Object.values(e)[0] || 'Kufij të pavlefshëm.'),
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
    if (!selected.value) return;
    const w = weekOf(selected.value.date);
    if (!w) return;
    const count = props.days.filter((d) => d.date >= w.from && d.date <= w.to && d.actionable && !d.is_past).length;
    bulk.value = { label: 'javën e ' + longDate(selected.value.date), date_from: w.from, date_to: w.to, count };
}
function askBulkMonth() {
    const count = props.days.filter((d) => d.actionable && !d.is_past).length;
    const last = props.days.length ? props.days[props.days.length - 1].date : props.month;
    bulk.value = { label: 'gjithë ' + monthLabel.value, date_from: props.month, date_to: last, count };
}
function confirmBulk() {
    const b = bulk.value;
    if (!b) return;
    router.post(route('pricing.smart.apply-range'), { room_type_id: typeId.value, date_from: b.date_from, date_to: b.date_to }, {
        preserveScroll: true,
        onSuccess: () => { bulk.value = null; selected.value = null; toasts.value?.success('Sugjerimet u aplikuan — po dërgohen te OTA-t.'); },
        onError: (e) => { bulk.value = null; toasts.value?.error(Object.values(e)[0] || 'S\'u aplikua asgjë.'); },
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
async function suggestEvents() {
    evLoading.value = true; evSuggestions.value = [];
    try {
        const { data } = await axios.post(route('pricing.smart.events.suggest'));
        evSuggestions.value = data.events || [];
        if (!evSuggestions.value.length) toasts.value?.success('S\'u gjet asnjë event i ri — kalendari duket i plotë.');
    } catch (e) {
        toasts.value?.error(e.response?.data?.error || 'Sugjerimet s\'u morën dot.');
    }
    evLoading.value = false;
}
function approveEvent(ev, i) {
    router.post(route('pricing.smart.events.approve'), {
        name: ev.name, date_from: ev.date_from, date_to: ev.date_to, uplift_pct: ev.uplift_pct ?? null,
    }, {
        preserveScroll: true,
        onSuccess: () => { evSuggestions.value.splice(i, 1); toasts.value?.success(`"${ev.name}" u shtua.`); },
        onError: (e) => toasts.value?.error(Object.values(e)[0] || 'Eventi s\'u shtua.'),
    });
}
function removeEventRow(ev) {
    router.delete(route('pricing.smart.events.destroy', ev.id), {
        preserveScroll: true,
        onSuccess: () => toasts.value?.success(`"${ev.name}" u hoq.`),
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
        toasts.value?.error(e.response?.data?.error || 'Përgjigja s\'u mor dot.');
    }
    askLoading.value = false;
}

// ── Autopiloti me kufij (default: I FIKUR — vetëm ti e ndez) ──
const apConfirmOpen = ref(false);
const apForm = ref({});
function openAutopilot(intendEnable) {
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
    router.post(route('pricing.smart.autopilot'), {
        ...apForm.value,
        pause_from: apForm.value.pause_from || null,
        pause_to: apForm.value.pause_to || null,
    }, {
        preserveScroll: true,
        onSuccess: () => { apConfirmOpen.value = false; },
        onError: (e) => toasts.value?.error(Object.values(e)[0] || 'Cilësimet s\'u ruajtën.'),
    });
}
function revertAutopilot(log) {
    router.post(route('pricing.smart.autopilot.revert', log.id), {}, { preserveScroll: true });
}

function fmtRange(a, b) {
    const f = (d) => new Date(d + 'T00:00:00').toLocaleDateString('sq-AL', { day: '2-digit', month: 'short' });
    return a === b ? f(a) : `${f(a)} – ${f(b)}`;
}

// ── Kalendari ──
const dows = ['Hë', 'Ma', 'Më', 'En', 'Pr', 'Sh', 'Di'];
const monthLabel = computed(() =>
    props.month ? new Date(props.month + 'T00:00:00').toLocaleDateString('sq-AL', { month: 'long', year: 'numeric' }) : '',
);
const leadingBlanks = computed(() => (props.days.length ? props.days[0].dow - 1 : 0));
const actionableCount = computed(() => props.days.filter((d) => d.actionable && !d.is_past).length);

function go(month) {
    selected.value = null;
    router.get(route('pricing.smart.index'), { room_type_id: typeId.value, month }, { preserveScroll: true });
}
function dayNum(d) { return parseInt(d.date.slice(8, 10), 10); }
function longDate(date) {
    return new Date(date + 'T00:00:00').toLocaleDateString('sq-AL', { weekday: 'long', day: '2-digit', month: 'long' });
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

function apply(d, price) {
    const p = Number(price);
    if (!p || p <= 0) { toasts.value?.error('Vendos një çmim të vlefshëm.'); return; }
    router.post(route('pricing.smart.apply'), { date: d.date, room_type_id: typeId.value, price: p }, {
        preserveScroll: true,
        onSuccess: () => { toasts.value?.success(`Çmimi u vendos ${props.currency}${fmtPrice(p)} për ${longDate(d.date)} — po dërgohet te OTA-t.`); selected.value = null; },
        onError: (e) => toasts.value?.error(Object.values(e)[0] || 'Diçka shkoi keq. Provoni përsëri.'),
    });
}
function remove(d) {
    router.post(route('pricing.smart.remove'), { date: d.date, room_type_id: typeId.value }, {
        preserveScroll: true,
        onSuccess: () => { toasts.value?.success('Çmimi u rikthye te tarifa normale.'); selected.value = null; },
    });
}

function syncLabel(ts) {
    if (!ts) return 'ende pa sinkronizim';
    return new Date(ts.replace(' ', 'T')).toLocaleString('sq-AL', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}

watch(() => props.selectedTypeId, (v) => { typeId.value = v; });
</script>

<template>
    <AppLayout>
        <PageHeader
            title="Çmim Inteligjent"
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Çmim Inteligjent' }]"
        >
            <template #actions>
                <span class="hidden sm:inline-flex items-center gap-1.5 text-tiny text-neutral-500 mr-2" :title="'Push-i i fundit i suksesshëm drejt OTA-ve'">
                    <i class="w-2 h-2 rounded-full" :class="lastSyncAt ? 'bg-success-500' : 'bg-neutral-300'" />
                    OTA sync: {{ syncLabel(lastSyncAt) }}
                </span>
                <Link href="/pms/pricing" class="no-underline"><Button variant="outline">Çmimet</Button></Link>
            </template>
        </PageHeader>

        <div class="mt-6">
            <Card>
                <!-- toolbar: type · bounds · month nav -->
                <div class="flex flex-wrap items-center justify-between gap-4 mb-5">
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="flex items-center gap-2.5">
                            <label class="text-label text-neutral-600">Tipi i dhomës</label>
                            <select
                                v-model="typeId"
                                class="rounded-xl border border-neutral-200 px-3.5 py-2.5 text-body-sm font-medium text-primary-900 focus:border-ionian focus:ring-2 focus:ring-ionian/30 min-w-[200px]"
                                @change="go(month)"
                            >
                                <option v-for="t in roomTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
                            </select>
                        </div>

                        <button
                            class="inline-flex items-center gap-1.5 text-tiny font-semibold text-neutral-600 border border-neutral-200 rounded-xl px-3 py-2.5 hover:border-ionian hover:text-primary-900 transition"
                            :title="'Motori sugjeron vetëm brenda këtyre kufijve'"
                            @click="openBounds"
                        >
                            🔒 Kufijtë: {{ boundsLabel }}
                        </button>
                    </div>

                    <div class="flex items-center gap-3">
                        <span class="text-body font-semibold text-primary-900 capitalize min-w-[130px] text-center">{{ monthLabel }}</span>
                        <div class="flex gap-1.5">
                            <Button size="sm" variant="outline" @click="go(prevMonth)">‹</Button>
                            <Button size="sm" variant="outline" @click="go(nextMonth)">›</Button>
                        </div>
                    </div>
                </div>

                <!-- strategy slider: the ONE tuning knob -->
                <div class="flex flex-wrap items-center gap-3 mb-5">
                    <span class="text-label text-neutral-600">Strategjia</span>
                    <div class="inline-flex rounded-xl border border-neutral-200 p-1 bg-neutral-50">
                        <button
                            v-for="s in strategies"
                            :key="s.key"
                            :class="[
                                'px-3.5 py-1.5 rounded-lg text-small font-semibold transition',
                                strategy === s.key ? 'bg-white shadow text-primary-900 border border-neutral-200' : 'text-neutral-500 hover:text-primary-900',
                            ]"
                            :title="s.hint"
                            @click="setStrategy(s.key)"
                        >
                            {{ s.label }}
                        </button>
                    </div>
                    <span v-if="actionableCount" class="inline-flex items-center gap-2 text-tiny text-neutral-500">
                        {{ actionableCount }} sugjerime këtë muaj
                        <Button size="sm" variant="outline" @click="askBulkMonth">Apliko muajin</Button>
                    </span>
                </div>

                <div v-if="!roomTypes.length" class="py-16 text-center text-body-sm text-neutral-500">
                    Shto fillimisht tipet e dhomave te "Dhomat".
                </div>

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
                                {{ d.adjustment_pct > 0 ? '▲' : '▼' }} {{ currency }}{{ fmtPrice(d.suggested_price) }}<span v-if="d.clamped" :title="'I ndalur te kufiri ' + (d.clamped === 'max' ? 'maksimal' : 'minimal')"> 🔒</span>
                            </div>

                            <span v-if="d.total > 0 && d.booked >= d.total" class="absolute bottom-1.5 left-2 text-[10px] font-bold text-white bg-primary-900 rounded px-1.5 py-0.5 leading-none">plot</span>
                            <span v-if="d.has_override" class="absolute bottom-2 right-2 w-2 h-2 rounded-full bg-info-500" title="Çmim i vendosur nga ti" />
                        </div>
                    </div>

                    <!-- legend -->
                    <div class="flex flex-wrap gap-x-5 gap-y-2 mt-5 text-tiny text-neutral-500">
                        <span><span class="text-success-700 font-bold mr-1">▲</span>Sugjerim ngritjeje</span>
                        <span><span class="text-info-700 font-bold mr-1">▼</span>Sugjerim uljeje</span>
                        <span><span class="font-bold mr-1">🔒</span>I ndalur te kufiri yt</span>
                        <span><i class="inline-block w-2.5 h-2.5 rounded-sm bg-warning-100 border border-warning-200 mr-1.5 align-[-1px]" />Po mbushet</span>
                        <span><i class="inline-block w-2.5 h-2.5 rounded-sm bg-error-100 border border-error-200 mr-1.5 align-[-1px]" />Plot</span>
                        <span><span class="text-error-600 font-bold mr-1">⚑</span>Festë</span>
                        <span><i class="inline-block w-2 h-2 rounded-full bg-info-500 mr-1.5 align-[0px]" />Çmim i vendosur nga ti</span>
                    </div>

                    <!-- selected day: "Pse ky çmim?" -->
                    <div v-if="selected" class="mt-5 border border-neutral-200 rounded-2xl overflow-hidden">
                        <div class="flex items-center justify-between px-4 py-3 border-b border-neutral-200" :class="selected.holiday ? 'bg-error-50' : 'bg-neutral-50'">
                            <span class="text-body-sm font-semibold text-primary-900 capitalize flex items-center gap-2">
                                <span v-if="selected.holiday" class="text-error-600">⚑</span>
                                {{ longDate(selected.date) }}
                                <span v-if="selected.holiday" class="text-tiny font-semibold text-error-700 bg-error-100 rounded-full px-2 py-0.5">{{ selected.holiday }}</span>
                                <span v-else-if="selected.is_weekend" class="text-tiny font-semibold text-warning-700 bg-warning-100 rounded-full px-2 py-0.5">Fundjavë</span>
                            </span>
                            <span class="text-tiny text-neutral-500">
                                {{ selected.booked }}/{{ selected.total }} dhoma<template v-if="selected.total > 0 && selected.booked >= selected.total"> · <b class="text-primary-900">Plot</b></template>
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
                                    <p v-if="!selected.actionable" class="text-tiny text-neutral-400 mt-1">{{ selected.quiet_reason || "Çmimi aktual për këtë natë — s'ka sugjerim ndryshimi." }}</p>
                                </div>
                                <div class="flex flex-wrap gap-2.5">
                                    <Button v-if="selected.actionable" variant="primary" @click="apply(selected, selected.suggested_price)">Apliko {{ currency }}{{ fmtPrice(selected.suggested_price) }}</Button>
                                    <Button variant="outline" size="sm" @click="askBulkWeek">Apliko javën</Button>
                                    <Button v-if="selected.has_override" variant="ghost" @click="remove(selected)">Hiq</Button>
                                </div>
                            </div>

                            <!-- PSE KY ÇMIM? — the factor breakdown, plain Albanian -->
                            <div v-if="selected.factors && selected.factors.length" class="rounded-xl border border-neutral-100 bg-neutral-50 p-3">
                                <p class="text-tiny font-bold uppercase tracking-wide text-neutral-400 mb-2">Pse ky çmim?</p>
                                <div class="space-y-1.5">
                                    <div class="flex items-center justify-between text-body-sm text-neutral-600">
                                        <span>Çmimi bazë / sezonal</span>
                                        <span class="font-semibold tabular-nums text-primary-900">{{ currency }}{{ fmtPrice(selected.reference) }}</span>
                                    </div>
                                    <div v-for="(f, i) in selected.factors" :key="i" class="flex items-center justify-between text-body-sm text-neutral-600">
                                        <span>{{ f.label }}</span>
                                        <span class="font-semibold tabular-nums" :class="f.pct > 0 ? 'text-success-700' : 'text-info-700'">{{ f.pct > 0 ? '+' : '' }}{{ f.pct }}%</span>
                                    </div>
                                    <div v-if="selected.clamped" class="flex items-center justify-between text-body-sm font-semibold text-warning-700 border-t border-neutral-200 pt-1.5">
                                        <span>🔒 U ndal te kufiri yt {{ selected.clamped === 'max' ? 'maksimal' : 'minimal' }}</span>
                                        <span class="tabular-nums">{{ currency }}{{ fmtPrice(selected.suggested_price) }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- "✦ Shpjegim AI" — the breakdown, told as one warm sentence -->
                            <div v-if="aiConfigured && selected.factors && selected.factors.length" class="rounded-xl border border-accent-100 bg-accent-50/60 p-3">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <span class="text-body-sm font-bold text-accent-700">✦ Shpjegimi i AI-së</span>
                                    <Button size="sm" variant="outline" :loading="explainLoading" @click="explainDay">Shpjego</Button>
                                </div>
                                <p v-if="explainText" class="text-body-sm text-neutral-700 mt-2">{{ explainText }}</p>
                            </div>

                            <!-- manual price — full control on any night -->
                            <div class="flex flex-wrap items-center gap-2.5 pt-3 border-t border-neutral-100">
                                <label class="text-tiny text-neutral-500 shrink-0">Ose vendos çmimin tënd:</label>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-body-sm text-neutral-400">{{ currency }}</span>
                                    <input v-model="manualPrice" type="number" min="1" step="1" :placeholder="String(fmtPrice(selected.current_price))"
                                        class="w-28 rounded-lg border border-neutral-200 px-2.5 py-1.5 text-body-sm tabular-nums focus:border-ionian focus:ring-2 focus:ring-ionian/30" />
                                    <Button size="sm" variant="outline" @click="apply(selected, manualPrice)">Vendos</Button>
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
                        <h2 class="text-h4 text-primary-900 leading-tight">Eventet e kërkesës</h2>
                        <p class="text-tiny text-neutral-500">Festa e evente që motori i llogarit në çmim. Ruhen një herë — s'ke pse i rishkruan.</p>
                    </div>
                    <Button v-if="aiConfigured" size="sm" variant="outline" :loading="evLoading" @click="suggestEvents">✦ Sugjero me AI</Button>
                </div>

                <div v-if="!upcomingEvents.length" class="text-body-sm text-neutral-400 py-3">S'ka evente të ardhshme në 90 ditët e para.</div>
                <div v-else class="space-y-1.5 mb-2">
                    <div v-for="ev in upcomingEvents" :key="ev.id + ev.date_from" class="flex items-center justify-between gap-2 text-body-sm border border-neutral-100 rounded-lg px-3 py-1.5">
                        <span class="text-neutral-700">
                            <b class="text-primary-900">{{ ev.name }}</b> · {{ fmtRange(ev.date_from, ev.date_to) }}
                            <span v-if="ev.uplift_pct" class="text-success-700 font-semibold">+{{ ev.uplift_pct }}%</span>
                            <span v-if="ev.source === 'ai'" class="text-tiny text-accent-600 font-semibold ml-1">✦</span>
                        </span>
                        <button class="text-neutral-300 hover:text-error-600 shrink-0" title="Hiq eventin" @click="removeEventRow(ev)">✕</button>
                    </div>
                </div>

                <div v-if="evSuggestions.length" class="mt-3 border-t border-neutral-100 pt-3">
                    <p class="text-tiny font-bold uppercase tracking-wide text-neutral-400 mb-2">Sugjerime — prano vetëm ç'të duhen</p>
                    <div v-for="(ev, i) in evSuggestions" :key="i" class="flex items-start justify-between gap-3 border border-accent-100 bg-accent-50/40 rounded-lg px-3 py-2 mb-1.5">
                        <div class="text-body-sm text-neutral-700">
                            <b class="text-primary-900">{{ ev.name }}</b> · {{ fmtRange(ev.date_from, ev.date_to) }}
                            <span v-if="ev.uplift_pct" class="text-success-700 font-semibold">+{{ ev.uplift_pct }}%</span>
                            <p class="text-tiny text-neutral-500">{{ ev.reason }}</p>
                        </div>
                        <div class="flex gap-1.5 shrink-0">
                            <Button size="sm" variant="primary" @click="approveEvent(ev, i)">Prano</Button>
                            <Button size="sm" variant="ghost" @click="evSuggestions.splice(i, 1)">Jo</Button>
                        </div>
                    </div>
                </div>
            </Card>

            <Card>
                <div class="flex items-center justify-between gap-3 mb-3">
                    <div>
                        <h2 class="text-h4 text-primary-900 leading-tight">Raporti javor i çmimeve</h2>
                        <p class="text-tiny text-neutral-500">Çdo të hënë në 07:00 — çfarë po ndodh dhe çfarë ia vlen të bësh.</p>
                    </div>
                    <Button v-if="aiConfigured" size="sm" variant="outline" :loading="reportLoading" @click="generateReport">Gjenero tani</Button>
                </div>

                <div v-if="!aiConfigured" class="p-3 rounded-lg bg-warning-50 border border-warning-200 text-body-sm text-warning-800">
                    Asistenti AI s'është aktivizuar ende. Shto çelësin Gemini te <b>Settings → Asistenti AI</b>.
                </div>
                <template v-else-if="latestReport">
                    <p class="text-body-sm font-bold text-primary-900">{{ latestReport.title }}</p>
                    <p class="text-body-sm text-neutral-700 mt-1.5 whitespace-pre-line">{{ latestReport.body }}</p>
                    <ul v-if="latestReport.highlights && latestReport.highlights.length" class="mt-2.5 space-y-1">
                        <li v-for="(h, i) in latestReport.highlights" :key="i" class="text-body-sm text-neutral-600 flex gap-2"><span class="text-success-600 shrink-0">•</span>{{ h }}</li>
                    </ul>
                </template>
                <p v-else class="text-body-sm text-neutral-400 py-3">Ende s'ka raport — kliko "Gjenero tani".</p>

                <div v-if="aiConfigured" class="mt-4 border-t border-neutral-100 pt-3">
                    <label class="block text-tiny font-bold uppercase tracking-wide text-neutral-400 mb-1.5">Pyet AI-në për kalendarin</label>
                    <div class="flex gap-1.5">
                        <input v-model="askQ" type="text" placeholder="p.sh. Pse ky çmim më 15 gusht?" maxlength="500"
                            class="flex-1 rounded-lg border border-neutral-200 px-3 py-1.5 text-body-sm focus:border-ionian focus:ring-2 focus:ring-ionian/30" @keyup.enter="askAi" />
                        <Button size="sm" variant="primary" :loading="askLoading" @click="askAi">Pyet</Button>
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
                        Autopilot me kufij
                        <span :class="['text-tiny font-bold px-2 py-0.5 rounded-full align-[2px] ml-1', autopilot.enabled ? 'bg-success-50 text-success-700' : 'bg-neutral-100 text-neutral-500']">
                            {{ autopilot.enabled ? 'NDEZUR' : 'FIKUR' }}
                        </span>
                    </h2>
                    <p class="text-tiny text-neutral-500">Çdo natë në 03:45 aplikon vetë sugjerimet e motorit — vetëm brenda kufijve të tu, kurrë mbi çmimet që ke vënë ti me dorë.</p>
                </div>
                <Button :variant="autopilot.enabled ? 'outline' : 'primary'" @click="openAutopilot(!autopilot.enabled)">
                    {{ autopilot.enabled ? 'Fike / ndrysho kufijtë' : 'Ndize autopilotin' }}
                </Button>
            </div>

            <div v-if="autopilot.enabled" class="flex flex-wrap gap-x-5 gap-y-1 text-tiny text-neutral-500 mb-3">
                <span>Ndryshim minimal: <b class="text-primary-900">{{ autopilot.materiality_pct }}%</b></span>
                <span>Tavan ditor: <b class="text-primary-900">±{{ autopilot.daily_cap_pct }}%</b></span>
                <span>Mbron çmimet e tua: <b class="text-primary-900">{{ autopilot.protect_manual_days }} ditë</b></span>
                <span v-if="autopilot.pause_from">Pauzë: <b class="text-primary-900">{{ fmtRange(autopilot.pause_from, autopilot.pause_to) }}</b></span>
            </div>

            <template v-if="autopilot.logs && autopilot.logs.length">
                <p class="text-tiny font-bold uppercase tracking-wide text-neutral-400 mb-2">Ndryshimet e fundit</p>
                <div class="space-y-1.5">
                    <div v-for="l in autopilot.logs" :key="l.id" class="flex items-center justify-between gap-2 text-body-sm border border-neutral-100 rounded-lg px-3 py-1.5" :class="l.reverted ? 'opacity-50' : ''">
                        <span class="text-neutral-700">
                            {{ fmtRange(l.date, l.date) }} · {{ l.room_type }} ·
                            <span class="text-neutral-400 tabular-nums">{{ l.old_price !== null ? currency + fmtPrice(l.old_price) : 'sezonal' }}</span>
                            <span class="text-neutral-400">→</span>
                            <b class="text-primary-900 tabular-nums">{{ currency }}{{ fmtPrice(l.new_price) }}</b>
                        </span>
                        <Button v-if="!l.reverted" size="sm" variant="ghost" @click="revertAutopilot(l)">Kthe</Button>
                        <span v-else class="text-tiny text-neutral-400 shrink-0">u kthye</span>
                    </div>
                </div>
            </template>
            <p v-else class="text-body-sm text-neutral-400">Ende asnjë ndryshim automatik.</p>
        </Card>

        <!-- autopilot confirm -->
        <Modal :show="apConfirmOpen" title="Autopilot me kufij" @close="apConfirmOpen = false">
            <p v-if="apForm.enabled" class="text-body-sm text-neutral-600 mb-4">
                Duke e ndezur, sistemi do të <b>aplikojë vetë</b> sugjerimet e motorit çdo natë — por VETËM brenda
                kufijve min/max të çdo tipi dhome, vetëm ndryshime ≥ pragut, me tavan ditor, dhe <b>kurrë</b> mbi
                çmimet që ke vënë ti kohët e fundit. Çdo ndryshim regjistrohet dhe kthehet me një klik.
            </p>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-label text-neutral-600 mb-1">Ndryshimi minimal (%)</label>
                    <input v-model="apForm.materiality_pct" type="number" min="1" max="50" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm" />
                </div>
                <div>
                    <label class="block text-label text-neutral-600 mb-1">Tavani ditor (±%)</label>
                    <input v-model="apForm.daily_cap_pct" type="number" min="1" max="50" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm" />
                </div>
                <div>
                    <label class="block text-label text-neutral-600 mb-1">Mbro çmimet e mia (ditë)</label>
                    <input v-model="apForm.protect_manual_days" type="number" min="0" max="30" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm" />
                </div>
                <div class="col-span-2 grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-label text-neutral-600 mb-1">Pauzë nga (ops.)</label>
                        <input v-model="apForm.pause_from" type="date" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm" />
                    </div>
                    <div>
                        <label class="block text-label text-neutral-600 mb-1">deri më</label>
                        <input v-model="apForm.pause_to" type="date" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm" />
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-2.5 mt-5">
                <Button variant="ghost" @click="apConfirmOpen = false">Anulo</Button>
                <Button variant="primary" @click="saveAutopilot">{{ apForm.enabled ? 'Po, ndize' : 'Ruaj (i fikur)' }}</Button>
            </div>
        </Modal>

        <!-- bounds drawer -->
        <Modal :show="boundsOpen" title="Kufijtë e çmimit" @close="boundsOpen = false">
            <p class="text-body-sm text-neutral-600 mb-4">
                Motori sugjeron (dhe autopiloti do të aplikojë) çmime <b>vetëm brenda këtyre kufijve</b> për
                <b>{{ currentType?.name }}</b>. Bosh = pa kufi.
            </p>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-label text-neutral-600 mb-1">Minimal ({{ currency }}/natë)</label>
                    <input v-model="boundsMin" type="number" min="0" step="1" placeholder="p.sh. 60"
                        class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm tabular-nums focus:border-ionian focus:ring-2 focus:ring-ionian/30" />
                </div>
                <div>
                    <label class="block text-label text-neutral-600 mb-1">Maksimal ({{ currency }}/natë)</label>
                    <input v-model="boundsMax" type="number" min="0" step="1" placeholder="p.sh. 200"
                        class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm tabular-nums focus:border-ionian focus:ring-2 focus:ring-ionian/30" />
                </div>
            </div>
            <div class="flex justify-end gap-2.5 mt-5">
                <Button variant="ghost" @click="boundsOpen = false">Anulo</Button>
                <Button variant="primary" @click="saveBounds">Ruaj kufijtë</Button>
            </div>
        </Modal>

        <!-- bulk apply confirm -->
        <Modal :show="!!bulk" title="Apliko sugjerimet në masë" @close="bulk = null">
            <template v-if="bulk">
                <p class="text-body-sm text-neutral-600">
                    Do të aplikohen sugjerimet e motorit për <b>{{ bulk.label }}</b>
                    ({{ currentType?.name }}). Në kalendarin e dukshëm janë <b>{{ bulk.count }}</b> ditë me sugjerim —
                    llogaritja përfundimtare bëhet në server, gjithmonë brenda kufijve të tu, dhe dërgohet vetë te OTA-t.
                </p>
                <div class="flex justify-end gap-2.5 mt-5">
                    <Button variant="ghost" @click="bulk = null">Anulo</Button>
                    <Button variant="primary" @click="confirmBulk">Po, apliko</Button>
                </div>
            </template>
        </Modal>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
