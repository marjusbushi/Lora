<script setup>
import { ref, computed, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';

const props = defineProps({
    roomTypes: { type: Array, default: () => [] },
    selectedTypeId: { type: [Number, String], default: null },
    days: { type: Array, default: () => [] },
    month: { type: String, default: '' },
    prevMonth: { type: String, default: '' },
    nextMonth: { type: String, default: '' },
    settings: { type: Object, default: () => ({}) },
    currency: { type: String, default: '€' },
    aiConfigured: { type: Boolean, default: false },
});

const toasts = ref(null);
const typeId = ref(props.selectedTypeId);
const selected = ref(null);

// ── AI Pricing Assistant ──
const aiEvents = ref([]);
const aiEventInput = ref('');
const aiLoading = ref(false);
const aiPlan = ref(null);      // { summary, recommendations: [...] }
const aiError = ref('');
const applied = ref({});       // index -> true once a recommendation is applied

function addEvent() {
    const v = aiEventInput.value.trim();
    if (v) { aiEvents.value.push(v); aiEventInput.value = ''; }
}
function removeEvent(i) { aiEvents.value.splice(i, 1); }

async function generatePlan() {
    aiLoading.value = true; aiError.value = ''; aiPlan.value = null; applied.value = {};
    try {
        const { data } = await axios.post(route('pricing.smart.ai-plan'), { month: props.month, events: aiEvents.value });
        aiPlan.value = data;
    } catch (e) {
        aiError.value = e.response?.data?.error || 'Asistenti AI s\'u përgjigj. Provoni përsëri.';
    }
    aiLoading.value = false;
}

function applyRec(rec, i, opts = {}) {
    if (!rec.prices?.length) { toasts.value?.error(`"${rec.label}" s'ka çmime për të aplikuar.`); opts.onDone?.(); return; }
    router.post(route('pricing.smart.apply-plan'), { date_from: rec.date_from, date_to: rec.date_to, prices: rec.prices }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { applied.value = { ...applied.value, [i]: true }; if (!opts.silent) toasts.value?.success(`U aplikua: ${rec.label}.`); },
        onError: (errors) => toasts.value?.error(`Nuk u aplikua "${rec.label}": ${Object.values(errors)[0] || 'të dhëna të pavlefshme'}.`),
        onFinish: () => opts.onDone?.(),
    });
}
// Apply recommendations ONE AT A TIME — concurrent Inertia visits cancel each other, so a
// naive forEach would only ever apply the last one. Chain via onDone, then report a summary.
function applyAll() {
    const queue = (aiPlan.value?.recommendations || [])
        .map((rec, i) => ({ rec, i }))
        .filter(x => x.rec.action !== 'hold');
    if (!queue.length) { toasts.value?.error('Asnjë rekomandim për të aplikuar.'); return; }
    let idx = 0;
    const next = () => {
        if (idx >= queue.length) { toasts.value?.success(`U përpunuan ${queue.length} rekomandime.`); return; }
        const { rec, i } = queue[idx++];
        applyRec(rec, i, { silent: true, onDone: next });
    };
    next();
}

const actionTone = {
    raise: 'bg-success-50 text-success-700',
    lower: 'bg-info-50 text-info-700',
    hold: 'bg-neutral-100 text-neutral-500',
};
const recBorder = { raise: 'border-l-success-500', lower: 'border-l-info-500', hold: 'border-l-neutral-300' };
function fmtRange(a, b) {
    const f = (d) => new Date(d + 'T00:00:00').toLocaleDateString('sq-AL', { day: '2-digit', month: 'short' });
    return a === b ? f(a) : `${f(a)} – ${f(b)}`;
}

const dows = ['Hë', 'Ma', 'Më', 'En', 'Pr', 'Sh', 'Di'];
const whyText = {
    peak: 'Plot — kërkesa e lartë, mund të fitosh më shumë.',
    high: 'Po mbushet shpejt — ngrije pak.',
    low: 'Bosh dhe afër — ule që të mos humbasë dhoma.',
};

const monthLabel = computed(() =>
    props.month ? new Date(props.month + 'T00:00:00').toLocaleDateString('sq-AL', { month: 'long', year: 'numeric' }) : '',
);
const leadingBlanks = computed(() => (props.days.length ? props.days[0].dow - 1 : 0));

function go(month) {
    selected.value = null;
    router.get(route('pricing.smart.index'), { room_type_id: typeId.value, month }, { preserveScroll: true });
}
function dayNum(d) { return parseInt(d.date.slice(8, 10), 10); }
function longDate(date) {
    return new Date(date + 'T00:00:00').toLocaleDateString('sq-AL', { weekday: 'long', day: '2-digit', month: 'long' });
}

const manualPrice = ref('');

// date -> AI-suggested { price, label, reason } for the SELECTED room type, built from the
// generated plan above, so the calendar can overlay the AI's price (✦) on the days it covers.
const aiByDate = computed(() => {
    const map = {};
    const tid = Number(typeId.value);
    const fmt = (dt) => `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`;
    for (const rec of (aiPlan.value?.recommendations || [])) {
        const p = (rec.prices || []).find((x) => Number(x.room_type_id) === tid);
        if (!p || !rec.date_from || !rec.date_to) continue;
        const [ys, ms, ds] = rec.date_from.split('-').map(Number);
        const [ye, me, de] = rec.date_to.split('-').map(Number);
        let cur = new Date(ys, ms - 1, ds);              // local date, no UTC drift
        const last = new Date(ye, me - 1, de);
        while (cur <= last) { map[fmt(cur)] = { price: p.suggested, label: rec.label, reason: rec.reason }; cur.setDate(cur.getDate() + 1); }
    }
    return map;
});

const selAi = computed(() => (selected.value ? aiByDate.value[selected.value.date] : null) || null);
const selSuggested = computed(() => {
    if (selAi.value) return selAi.value.price;               // AI plan wins
    if (selected.value?.actionable) return selected.value.suggested_price; // else occupancy hint
    return null;
});

function fmtPrice(v) { const n = Number(v) || 0; return n % 1 === 0 ? String(n) : n.toFixed(2); }
function cellTone(d) {
    if (d.holiday) return 'bg-error-50 border-error-100 hover:border-error-200';
    if (d.is_weekend) return 'bg-warning-50 border-warning-100 hover:border-warning-200';
    return 'bg-white border-neutral-100 hover:border-neutral-200';
}

// Any non-past day is clickable now (it's a price calendar — the owner can price any night).
function pick(d) { if (!d.is_past) { selected.value = d; manualPrice.value = ''; } }

function apply(d, price) {
    const p = Number(price);
    if (!p || p <= 0) { toasts.value?.error('Vendos një çmim të vlefshëm.'); return; }
    router.post(route('pricing.smart.apply'), { date: d.date, room_type_id: typeId.value, price: p }, {
        preserveScroll: true,
        onSuccess: () => { toasts.value?.success(`Çmimi u vendos ${props.currency}${fmtPrice(p)} për ${longDate(d.date)}.`); selected.value = null; },
        onError: (e) => toasts.value?.error(Object.values(e)[0] || 'Diçka shkoi keq. Provoni përsëri.'),
    });
}
function remove(d) {
    router.post(route('pricing.smart.remove'), { date: d.date, room_type_id: typeId.value }, {
        preserveScroll: true,
        onSuccess: () => { toasts.value?.success('Çmimi u rikthye te tarifa normale.'); selected.value = null; },
    });
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
                <Link href="/pms/pricing" class="no-underline"><Button variant="outline">Çmimet</Button></Link>
            </template>
        </PageHeader>

        <!-- AI Pricing Assistant -->
        <Card class="mt-6">
            <div class="flex items-center gap-2.5 mb-3">
                <span class="grid place-items-center w-9 h-9 rounded-xl text-white text-lg shrink-0" style="background:linear-gradient(135deg,#16734e,#0f766e)">✦</span>
                <div>
                    <h2 class="text-h4 text-primary-900 leading-tight">Asistent Çmimesh me AI</h2>
                    <p class="text-tiny text-neutral-500">Lexon historikun tënd + eventet që di ti, dhe sugjeron çmimet me arsyetim.</p>
                </div>
            </div>

            <div v-if="!aiConfigured" class="p-3 rounded-lg bg-warning-50 border border-warning-200 text-body-sm text-warning-800">
                Asistenti AI s'është aktivizuar ende. Shto çelësin Gemini te <b>Settings → Asistenti AI</b> që të punojë.
            </div>

            <template v-else>
                <label class="block text-label text-neutral-600 mb-1.5">Evente që di ti (festa, festivale) — opsionale</label>
                <div class="flex flex-wrap items-center gap-2 mb-3">
                    <span v-for="(e, i) in aiEvents" :key="i" class="inline-flex items-center gap-1.5 bg-success-50 text-success-700 border border-success-100 text-small font-medium px-2.5 py-1 rounded-full">
                        {{ e }} <button class="opacity-50 hover:opacity-100" @click="removeEvent(i)">✕</button>
                    </span>
                    <input v-model="aiEventInput" type="text" placeholder="p.sh. 15 Gush · Festa e Sarandës" class="rounded-lg border border-neutral-200 px-3 py-1.5 text-body-sm min-w-[220px] focus:border-ionian focus:ring-2 focus:ring-ionian/30" @keyup.enter="addEvent" />
                    <Button size="sm" variant="outline" @click="addEvent">+ Shto</Button>
                </div>
                <Button variant="primary" :loading="aiLoading" @click="generatePlan">✦ Gjenero planin për {{ monthLabel }}</Button>

                <p v-if="aiError" class="mt-3 text-body-sm text-error-600">{{ aiError }}</p>

                <div v-if="aiPlan" class="mt-5">
                    <p v-if="aiPlan.summary" class="text-body-sm text-neutral-700 mb-3"><span class="font-semibold text-primary-900">AI:</span> {{ aiPlan.summary }}</p>

                    <div v-for="(rec, i) in aiPlan.recommendations" :key="i" :class="['border border-neutral-200 border-l-4 rounded-xl p-4 mb-3 bg-white', recBorder[rec.action] || 'border-l-neutral-300']">
                        <div class="flex items-start justify-between gap-3">
                            <div class="text-body-sm font-bold text-primary-900 capitalize">{{ fmtRange(rec.date_from, rec.date_to) }} · {{ rec.label }}</div>
                            <span :class="['text-tiny font-bold px-2 py-0.5 rounded-lg whitespace-nowrap', actionTone[rec.action] || 'bg-neutral-100 text-neutral-500']">
                                {{ rec.action === 'raise' ? '↑ Ngri' : rec.action === 'lower' ? '↓ Ul' : 'Mbaj' }}<span v-if="rec.adjustment_pct"> {{ rec.adjustment_pct > 0 ? '+' : '' }}{{ rec.adjustment_pct }}%</span>
                            </span>
                        </div>
                        <div class="flex flex-wrap gap-x-5 gap-y-1 my-2.5">
                            <span v-for="(p, j) in rec.prices" :key="j" class="text-body-sm text-neutral-700">
                                {{ p.room_type_name }}
                                <span v-if="p.current" class="text-neutral-400 line-through ml-1">{{ currency }}{{ p.current }}</span>
                                <span class="font-bold text-primary-900 ml-1">{{ currency }}{{ p.suggested }}</span>
                            </span>
                        </div>
                        <div class="flex gap-2 text-body-sm text-neutral-600 bg-neutral-50 border border-neutral-100 rounded-lg p-2.5">
                            <span class="shrink-0">💡</span><span>{{ rec.reason }}<template v-if="rec.projected_extra"> <b class="text-success-700">{{ rec.projected_extra }}</b></template></span>
                        </div>
                        <div class="flex justify-end mt-3">
                            <Button v-if="rec.action !== 'hold'" size="sm" :variant="applied[i] ? 'ghost' : 'primary'" :disabled="!!applied[i]" @click="applyRec(rec, i)">
                                {{ applied[i] ? '✓ U aplikua' : 'Apliko' }}
                            </Button>
                            <span v-else class="text-tiny text-neutral-400 self-center">s'ka ndryshim</span>
                        </div>
                    </div>

                    <div v-if="aiPlan.recommendations && aiPlan.recommendations.some(r => r.action !== 'hold')" class="flex justify-end">
                        <Button variant="outline" @click="applyAll">Apliko të gjitha</Button>
                    </div>
                </div>
            </template>
        </Card>

        <div class="mt-6">
            <Card>
                <!-- room type dropdown + month nav -->
                <div class="flex flex-wrap items-center justify-between gap-4 mb-5">
                    <div class="flex items-center gap-2.5">
                        <label class="text-label text-neutral-600">Tipi i dhomës</label>
                        <select
                            v-model="typeId"
                            class="rounded-xl border border-neutral-200 px-3.5 py-2.5 text-body-sm font-medium text-primary-900 focus:border-ionian focus:ring-2 focus:ring-ionian/30 min-w-[240px]"
                            @change="go(month)"
                        >
                            <option v-for="t in roomTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-body font-semibold text-primary-900 capitalize min-w-[130px] text-center">{{ monthLabel }}</span>
                        <div class="flex gap-1.5">
                            <Button size="sm" variant="outline" @click="go(prevMonth)">‹</Button>
                            <Button size="sm" variant="outline" @click="go(nextMonth)">›</Button>
                        </div>
                    </div>
                </div>

                <div v-if="!roomTypes.length" class="py-16 text-center text-body-sm text-neutral-500">
                    Shto fillimisht tipet e dhomave te "Dhomat".
                </div>

                <template v-else>
                    <!-- weekday header -->
                    <div class="grid grid-cols-7 gap-2 mb-2">
                        <span v-for="d in dows" :key="d" class="text-tiny font-bold uppercase tracking-wide text-neutral-400 text-center">{{ d }}</span>
                    </div>

                    <!-- calendar grid (price-first) -->
                    <div class="grid grid-cols-7 gap-2">
                        <div v-for="b in leadingBlanks" :key="'b' + b" />
                        <div
                            v-for="d in days"
                            :key="d.date"
                            :class="[
                                'min-h-[92px] rounded-xl border p-2 relative transition',
                                cellTone(d),
                                d.is_past ? 'opacity-45 pointer-events-none' : 'cursor-pointer hover:-translate-y-0.5 hover:shadow-md',
                                selected && selected.date === d.date ? 'ring-2 ring-ionian ring-offset-1' : '',
                            ]"
                            @click="pick(d)"
                        >
                            <div class="flex items-start justify-between">
                                <span :class="['text-tiny font-bold', d.holiday ? 'text-error-600' : 'text-neutral-400']">{{ dayNum(d) }}</span>
                                <span v-if="d.holiday" class="text-error-600 text-tiny leading-none" :title="d.holiday">⚑</span>
                            </div>

                            <div class="mt-1 text-h4 font-bold text-primary-900 tabular-nums leading-none">{{ currency }}{{ fmtPrice(d.current_price) }}</div>

                            <div v-if="aiByDate[d.date]" class="mt-1 text-tiny font-bold text-accent-600 tabular-nums leading-none">✦ {{ currency }}{{ fmtPrice(aiByDate[d.date].price) }}</div>
                            <div v-else-if="d.holiday" class="mt-1 text-tiny font-semibold text-error-700 truncate leading-none">{{ d.holiday }}</div>

                            <span v-if="d.total > 0 && d.booked >= d.total" class="absolute bottom-1.5 left-2 text-[10px] font-bold text-white bg-primary-900 rounded px-1.5 py-0.5 leading-none">plot</span>
                            <span v-if="d.has_override" class="absolute bottom-2 right-2 w-2 h-2 rounded-full bg-info-500" title="Çmim i vendosur nga ti" />
                        </div>
                    </div>

                    <!-- legend -->
                    <div class="flex flex-wrap gap-x-5 gap-y-2 mt-5 text-tiny text-neutral-500">
                        <span><i class="inline-block w-2.5 h-2.5 rounded-sm bg-warning-100 border border-warning-200 mr-1.5 align-[-1px]" />Fundjavë</span>
                        <span><span class="text-error-600 font-bold mr-1">⚑</span>Festë (p.sh. Ferragosto)</span>
                        <span><span class="text-accent-600 font-bold mr-1">✦</span>Sugjerim i AI-së</span>
                        <span><span class="inline-block text-white bg-primary-900 rounded px-1 text-[9px] font-bold mr-1 align-[1px]">plot</span>Dhomat e zëna</span>
                        <span><i class="inline-block w-2 h-2 rounded-full bg-info-500 mr-1.5 align-[0px]" />Çmim i vendosur nga ti</span>
                    </div>

                    <!-- selected day detail -->
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
                            <!-- current price → suggestion (AI wins, else occupancy) -->
                            <div class="flex flex-wrap items-center justify-between gap-4">
                                <div>
                                    <div class="flex items-baseline gap-3">
                                        <span class="text-body-sm text-neutral-400 tabular-nums" :class="selSuggested ? 'line-through' : ''">{{ currency }}{{ fmtPrice(selected.current_price) }}</span>
                                        <template v-if="selSuggested">
                                            <span class="text-neutral-400">→</span>
                                            <span class="text-h3 font-extrabold text-primary-900 tabular-nums">{{ currency }}{{ fmtPrice(selSuggested) }}</span>
                                            <span v-if="selAi" class="text-small font-bold px-2 py-0.5 rounded-lg bg-accent-50 text-accent-700">✦ AI</span>
                                        </template>
                                    </div>
                                    <p v-if="selAi" class="text-tiny text-neutral-500 mt-1">{{ selAi.reason || selAi.label }}</p>
                                    <p v-else-if="selected.actionable" class="text-tiny text-neutral-500 mt-1">{{ whyText[selected.kind] }}</p>
                                    <p v-else class="text-tiny text-neutral-400 mt-1">Çmimi aktual për këtë natë.</p>
                                </div>
                                <div class="flex gap-2.5">
                                    <Button v-if="selSuggested" variant="primary" @click="apply(selected, selSuggested)">Apliko {{ currency }}{{ fmtPrice(selSuggested) }}</Button>
                                    <Button v-if="selected.has_override" variant="ghost" @click="remove(selected)">Hiq</Button>
                                </div>
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

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
