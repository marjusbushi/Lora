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

const tint = {
    peak: 'bg-error-50 border-error-200 hover:border-error-300',
    high: 'bg-warning-50 border-warning-200 hover:border-warning-300',
    low: 'bg-info-50 border-info-100 hover:border-info-200',
};
const occTone = { peak: 'text-error-700', high: 'text-warning-700', low: 'text-info-700' };
const barTone = { peak: 'bg-error-500', high: 'bg-warning-500', low: 'bg-info-500' };
const tagTone = { peak: 'bg-error-600', high: 'bg-warning-600', low: 'bg-info-600' };

function pick(d) { if (d.actionable) selected.value = d; }

function apply(d) {
    router.post(route('pricing.smart.apply'), { date: d.date, room_type_id: typeId.value, price: d.suggested_price }, {
        preserveScroll: true,
        onSuccess: () => { toasts.value?.success(`Çmimi u vendos ${props.currency}${d.suggested_price} për ${longDate(d.date)}.`); selected.value = null; },
        onError: () => toasts.value?.error('Diçka shkoi keq. Provoni përsëri.'),
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

                    <!-- calendar grid -->
                    <div class="grid grid-cols-7 gap-2">
                        <div v-for="b in leadingBlanks" :key="'b' + b" />
                        <div
                            v-for="d in days"
                            :key="d.date"
                            :class="[
                                'min-h-[78px] rounded-xl border p-2 relative transition',
                                d.actionable ? [tint[d.kind], 'cursor-pointer hover:-translate-y-0.5 hover:shadow-md'] : 'bg-white border-neutral-100',
                                selected && selected.date === d.date ? 'ring-2 ring-ionian ring-offset-1' : '',
                                d.is_past ? 'opacity-50' : '',
                            ]"
                            @click="pick(d)"
                        >
                            <div class="text-body-sm font-bold text-primary-900">{{ dayNum(d) }}</div>
                            <template v-if="!d.is_past && d.total">
                                <div :class="['mt-1.5 text-tiny font-bold', d.kind ? occTone[d.kind] : 'text-neutral-400']">{{ d.occupancy_pct }}%</div>
                                <div class="mt-1 h-1 rounded-full bg-neutral-100 overflow-hidden">
                                    <i class="block h-full rounded-full" :class="d.kind ? barTone[d.kind] : 'bg-neutral-300'" :style="{ width: Math.max(d.occupancy_pct, 4) + '%' }" />
                                </div>
                            </template>
                            <span v-if="d.actionable" :class="['absolute top-2 right-2 text-tiny font-extrabold text-white px-1.5 rounded', tagTone[d.kind]]">
                                {{ d.adjustment_pct > 0 ? '↑' : '↓' }}
                            </span>
                            <span v-if="d.has_override" class="absolute bottom-1.5 right-2 text-tiny text-neutral-400" title="Çmim i vendosur">●</span>
                        </div>
                    </div>

                    <!-- legend -->
                    <div class="flex flex-wrap gap-x-5 gap-y-2 mt-5 text-tiny text-neutral-500">
                        <span><i class="inline-block w-2.5 h-2.5 rounded-sm bg-error-500 mr-1.5 align-[-1px]" /><b class="text-primary-900">Plot</b> → ngri çmimin</span>
                        <span><i class="inline-block w-2.5 h-2.5 rounded-sm bg-warning-500 mr-1.5 align-[-1px]" /><b class="text-primary-900">Po mbushet</b> → ngri pak</span>
                        <span><i class="inline-block w-2.5 h-2.5 rounded-sm bg-info-500 mr-1.5 align-[-1px]" /><b class="text-primary-900">Bosh &amp; afër</b> → ul çmimin</span>
                        <span><i class="inline-block w-2.5 h-2.5 rounded-sm bg-neutral-300 mr-1.5 align-[-1px]" />Normale → pa veprim</span>
                    </div>

                    <!-- selected day detail -->
                    <div v-if="selected" class="mt-5 border border-neutral-200 rounded-2xl overflow-hidden">
                        <div class="flex items-center justify-between px-4 py-3 bg-neutral-50 border-b border-neutral-200">
                            <span class="text-body-sm font-semibold text-primary-900 capitalize">{{ longDate(selected.date) }}</span>
                            <span class="text-tiny text-neutral-500">Mbushja {{ selected.booked }}/{{ selected.total }} · {{ selected.occupancy_pct }}%</span>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-4 px-4 py-4">
                            <div>
                                <div class="flex items-baseline gap-3">
                                    <span class="text-body-sm text-neutral-400 line-through">{{ currency }}{{ selected.current_price }}</span>
                                    <span class="text-neutral-400">→</span>
                                    <span class="text-h3 font-extrabold text-primary-900">{{ currency }}{{ selected.suggested_price }}</span>
                                    <span :class="['text-small font-bold px-2 py-0.5 rounded-lg', selected.adjustment_pct > 0 ? 'bg-success-50 text-success-700' : 'bg-info-50 text-info-700']">
                                        {{ selected.adjustment_pct > 0 ? '+' : '' }}{{ selected.adjustment_pct }}%
                                    </span>
                                </div>
                                <p class="text-tiny text-neutral-500 mt-1">{{ whyText[selected.kind] }}</p>
                            </div>
                            <div class="flex gap-2.5">
                                <Button variant="primary" @click="apply(selected)">Apliko {{ currency }}{{ selected.suggested_price }}</Button>
                                <Button v-if="selected.has_override" variant="ghost" @click="remove(selected)">Hiq</Button>
                            </div>
                        </div>
                    </div>
                </template>
            </Card>
        </div>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
