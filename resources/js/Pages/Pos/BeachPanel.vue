<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import { getIntlLocale } from '@/i18n';
import { useRealtimeReload } from '@/composables/useRealtimeReload';

// Paneli live i kamarierit të plazhit: porositë e çadrave të grupuara, zile
// kur hyn porosi e re, dhe mbyllje 1-prekje "U dorëzua & u pagua" (cash).
// Një faqe e vetme — listë vertikale në telefon, rrjetë kolonash në desktop.
// Realtime (task #346): porosia QR nga çadra shfaqet vetiu te paneli i plazhit.
useRealtimeReload('pos', '.pos.order.changed', ['groups', 'forgotten', 'stats']);

const props = defineProps({
    configured: { type: Boolean, default: false },
    outletName: { type: String, default: null },
    groups: { type: Array, default: () => [] },
    forgotten: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({ today_count: 0, today_revenue: 0, open_count: 0 }) },
    hasOpenShift: { type: Boolean, default: false },
    canSettle: { type: Boolean, default: false },
    isAdmin: { type: Boolean, default: false },
    currency: { type: String, default: 'EUR' },
});

const { t } = useI18n();
const page = usePage();

function money(value) {
    try {
        return new Intl.NumberFormat(getIntlLocale(), { style: 'currency', currency: props.currency || 'EUR' }).format(Number(value ?? 0));
    } catch {
        return `${Number(value ?? 0).toFixed(2)} ${props.currency}`;
    }
}

// ---- ora / mosha e porosisë -------------------------------------------------
const now = ref(Date.now());
let clockTimer = null;

function ageMinutes(order) {
    return Math.max(0, Math.floor((now.value - new Date(order.created_at).getTime()) / 60000));
}
const isFresh = (order) => ageMinutes(order) < 5;

function ageLabel(order) {
    const minutes = ageMinutes(order);
    if (minutes < 1) return t('posBeach.justNow');
    if (minutes < 60) return t('posBeach.minutesAgo', { count: minutes });
    return t('posBeach.hoursAgo', { count: Math.floor(minutes / 60) });
}
function ageClass(order) {
    const minutes = ageMinutes(order);
    if (minutes < 15) return 'bg-success-50 text-success-700';
    if (minutes < 30) return 'bg-warning-50 text-warning-700';
    return 'bg-error-50 text-error-700';
}
function daysOpen(order) {
    const anchor = order.business_date ? new Date(`${order.business_date}T00:00:00`) : new Date(order.created_at);
    return Math.max(1, Math.round((now.value - anchor.getTime()) / 86400000));
}
function clock(order) {
    return new Intl.DateTimeFormat(getIntlLocale(), { hour: '2-digit', minute: '2-digit' }).format(new Date(order.created_at));
}
function clockFull(order) {
    return new Intl.DateTimeFormat(getIntlLocale(), { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(order.created_at));
}

// ---- seksionet --------------------------------------------------------------
const freshGroups = computed(() => props.groups.filter((group) => group.orders.some(isFresh)));
const pendingGroups = computed(() => props.groups.filter((group) => !group.orders.some(isFresh)));
const allOrders = computed(() => [...props.groups.flatMap((group) => group.orders), ...props.forgotten]);
const freshCount = computed(() => allOrders.value.filter(isFresh).length);

function unitLabel(entity) {
    return entity.unit_number ? t('posBeach.unit', { number: entity.unit_number }) : t('posBeach.counter');
}
function itemsSummary(order) {
    return order.items.map((item) => `${item.quantity}× ${item.name}`).join(', ');
}

// ---- zilja (Web Audio, pa asset — pattern-i i NotificationBell) -------------
const soundEnabled = ref(localStorage.getItem('pos.beach.sound') !== '0');

function getAudioContext() {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return null;
    if (!window.__beachPanelAudioContext) window.__beachPanelAudioContext = new Ctx();
    return window.__beachPanelAudioContext;
}
async function unlockAudio() {
    try {
        const ctx = getAudioContext();
        if (ctx && ctx.state !== 'running') await ctx.resume();
    } catch { /* browser policy / unsupported audio */ }
}
function playDing(ctx) {
    [880, 1320].forEach((freq, i) => {
        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();
        oscillator.connect(gain);
        gain.connect(ctx.destination);
        oscillator.type = 'sine';
        oscillator.frequency.value = freq;
        const start = ctx.currentTime + i * 0.16;
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(0.16, start + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.32);
        oscillator.start(start);
        oscillator.stop(start + 0.34);
    });
}
function ding() {
    if (!soundEnabled.value) return;
    try {
        const ctx = getAudioContext();
        if (!ctx) return;
        if (ctx.state === 'suspended') {
            ctx.resume().then(() => playDing(ctx)).catch(() => {});
            return;
        }
        playDing(ctx);
    } catch { /* browser policy / unsupported audio */ }
}
async function toggleSound() {
    soundEnabled.value = !soundEnabled.value;
    localStorage.setItem('pos.beach.sound', soundEnabled.value ? '1' : '0');
    if (soundEnabled.value) {
        await unlockAudio();
        ding();
    }
}

// ---- polling + zbulimi i porosive të reja ----------------------------------
// Baseline-i i parë s'bie zile (porositë ekzistuese s'janë "të reja") — vetëm
// ID që shfaqen NË RELOAD pas ngarkimit fillestar e ndezin.
const knownIds = new Set(allOrders.value.map((order) => order.id));
let pollTimer = null;

watch(allOrders, (orders) => {
    const incoming = orders.filter((order) => !knownIds.has(order.id));
    orders.forEach((order) => knownIds.add(order.id));
    if (incoming.length) ding();
});

function refresh() {
    router.reload({ only: ['groups', 'forgotten', 'stats', 'hasOpenShift'], preserveScroll: true });
}

onMounted(() => {
    clockTimer = setInterval(() => { now.value = Date.now(); }, 15000);
    pollTimer = setInterval(() => { if (!document.hidden) refresh(); }, 20000);
    document.addEventListener('visibilitychange', onVisible);
    window.addEventListener('pointerdown', unlockAudio, { once: true });
    window.addEventListener('keydown', unlockAudio, { once: true });
});
function onVisible() {
    if (!document.hidden) refresh();
}
onBeforeUnmount(() => {
    clearInterval(clockTimer);
    clearInterval(pollTimer);
    document.removeEventListener('visibilitychange', onVisible);
});

// ---- preview i detajuar -----------------------------------------------------
// Mbajmë vetëm ID-në: pas çdo polling-u porosia rifreskohet nga props, dhe po
// u mbyll diku tjetër, preview-ja mbyllet vetë.
const selectedId = ref(null);
const selectedOrder = computed(() => allOrders.value.find((order) => order.id === selectedId.value) || null);
const showCancel = ref(false);

watch(selectedOrder, (order, previous) => {
    if (!order && previous && selectedId.value) closePreview();
});

function openPreview(order) {
    selectedId.value = order.id;
    showCancel.value = false;
    cancelForm.reason = '';
    cancelForm.clearErrors();
}
function closePreview() {
    selectedId.value = null;
    showCancel.value = false;
}

// ---- veprimet ---------------------------------------------------------------
// Konfirmimi është 2 prekje mbi të njëjtin buton (pa dialog): prekja e parë e
// kthen në "Konfirmo pagesën — X" për 4 sekonda.
const confirmingId = ref(null);
let confirmTimer = null;
const payForm = useForm({});
const payingId = ref(null);

function settle(order) {
    if (!props.canSettle || !props.hasOpenShift || payingId.value) return;
    if (confirmingId.value !== order.id) {
        confirmingId.value = order.id;
        clearTimeout(confirmTimer);
        confirmTimer = setTimeout(() => { confirmingId.value = null; }, 4000);
        return;
    }
    clearTimeout(confirmTimer);
    confirmingId.value = null;
    payingId.value = order.id;
    payForm.post(route('pos.beach.deliver', order.id), {
        preserveScroll: true,
        onSuccess: () => closePreview(),
        onFinish: () => { payingId.value = null; },
    });
}

const cancelForm = useForm({ reason: '' });
function submitCancel(order) {
    cancelForm.post(route('pos.cancel', order.id), {
        preserveScroll: true,
        onSuccess: () => closePreview(),
    });
}

const flashError = computed(() => page.props.flash?.error || null);
</script>

<template>
    <AppLayout>
        <div class="mx-auto max-w-6xl px-4 pb-24 pt-4 sm:px-6">

            <!-- Header i panelit -->
            <div class="rounded-2xl bg-primary-900 px-5 py-4 text-white shadow-md">
                <div class="flex items-center justify-between gap-3">
                    <h1 class="flex items-center gap-2 text-xl font-semibold">
                        ⛱️ {{ $t('posBeach.title') }}
                        <span v-if="outletName" class="rounded-full bg-white/15 px-3 py-0.5 text-tiny font-semibold">{{ outletName }}</span>
                    </h1>
                    <div class="flex items-center gap-3">
                        <button
                            type="button"
                            class="relative rounded-full p-1.5 text-xl leading-none transition hover:bg-white/10"
                            :title="soundEnabled ? $t('posBeach.soundOn') : $t('posBeach.soundOff')"
                            :aria-label="soundEnabled ? $t('posBeach.soundOn') : $t('posBeach.soundOff')"
                            @click="toggleSound"
                        >
                            {{ soundEnabled ? '🔔' : '🔕' }}
                            <span v-if="freshCount" class="absolute -right-1 -top-1 grid h-5 min-w-5 place-items-center rounded-full bg-error-600 px-1 text-tiny font-bold text-white">{{ freshCount }}</span>
                        </button>
                    </div>
                </div>
                <div v-if="configured" class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-body-sm text-white/75">
                    <span>{{ $t('posBeach.statToday') }}: <b class="text-white">{{ stats.today_count }}</b></span>
                    <span>{{ $t('posBeach.statDrawer') }}: <b class="text-white">{{ money(stats.today_revenue) }}</b></span>
                    <span>{{ $t('posBeach.statOpen') }}: <b class="text-white">{{ stats.open_count }}</b></span>
                </div>
            </div>

            <p v-if="flashError" class="mt-4 rounded-xl bg-error-50 px-4 py-3 text-body-sm text-error-700">{{ flashError }}</p>

            <!-- Pa pikë të konfiguruar -->
            <div v-if="!configured" class="mt-10 rounded-2xl border border-dashed border-neutral-300 bg-white px-6 py-14 text-center">
                <p class="text-5xl">⚙️</p>
                <h2 class="mt-3 text-lg font-semibold text-neutral-800">{{ $t('posBeach.notConfiguredTitle') }}</h2>
                <p class="mx-auto mt-2 max-w-md text-body-sm text-neutral-500">{{ $t('posBeach.notConfiguredBody') }}</p>
                <Link v-if="isAdmin" href="/pms/settings" class="mt-5 inline-flex rounded-xl bg-primary-900 px-5 py-2.5 text-body-sm font-semibold text-white hover:bg-primary-800">
                    {{ $t('posBeach.notConfiguredCta') }}
                </Link>
                <p v-else class="mt-4 text-body-sm text-neutral-500">{{ $t('posBeach.notConfiguredAsk') }}</p>
            </div>

            <template v-else>
                <!-- Pa turn të hapur: pagesat s'regjistrohen dot -->
                <div v-if="!hasOpenShift" class="mt-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-warning-500/30 bg-warning-50 px-4 py-3">
                    <div>
                        <p class="text-body-sm font-semibold text-warning-700">{{ $t('posBeach.noShiftTitle') }}</p>
                        <p class="text-tiny text-warning-700/80">{{ $t('posBeach.noShiftBody') }}</p>
                    </div>
                    <Link href="/pms/pos/shifts" class="rounded-lg bg-warning-500 px-4 py-2 text-body-sm font-semibold text-white hover:bg-warning-600">
                        {{ $t('posBeach.openShiftLink') }}
                    </Link>
                </div>

                <!-- Bosh fare -->
                <div v-if="!allOrders.length" class="mt-10 rounded-2xl border border-dashed border-neutral-300 bg-white px-6 py-14 text-center">
                    <p class="text-5xl">🏖️</p>
                    <h2 class="mt-3 text-lg font-semibold text-neutral-800">{{ $t('posBeach.emptyTitle') }}</h2>
                    <p class="mx-auto mt-2 max-w-md text-body-sm text-neutral-500">{{ $t('posBeach.emptyBody') }}</p>
                </div>

                <!-- TË REJA -->
                <template v-if="freshGroups.length">
                    <h2 class="mt-6 flex items-center gap-2 text-tiny font-extrabold uppercase tracking-widest text-neutral-500">
                        {{ $t('posBeach.sectionFresh') }}
                        <span class="grid h-5 min-w-5 place-items-center rounded-full bg-success-600 px-1 text-tiny font-bold text-white">{{ freshGroups.length }}</span>
                    </h2>
                    <div class="mt-3 grid items-start gap-4 lg:grid-cols-2 xl:grid-cols-3">
                        <div v-for="group in freshGroups" :key="group.unit_id ?? 'counter'" class="overflow-hidden rounded-2xl border-2 border-success-600 bg-white shadow-lg shadow-success-600/10">
                            <div class="flex items-center justify-between border-b border-neutral-100 bg-neutral-50 px-4 py-3">
                                <div>
                                    <p class="text-base font-extrabold text-neutral-900">{{ group.unit_number ? '⛱️' : '🏪' }} {{ unitLabel(group) }}</p>
                                    <p v-if="group.zone_name" class="text-tiny font-medium text-neutral-500">{{ group.zone_name }}</p>
                                </div>
                                <span v-if="group.orders.length > 1" class="text-tiny font-semibold text-neutral-500">{{ $t('posBeach.ordersCount', { count: group.orders.length }) }}</span>
                            </div>
                            <div v-for="order in group.orders" :key="order.id" class="cursor-pointer border-b border-neutral-100 px-4 py-3 transition last:border-b-0 hover:bg-neutral-50" @click="openPreview(order)">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-tiny font-bold text-neutral-500">#{{ order.id }} · {{ clock(order) }}</span>
                                    <span v-if="isFresh(order)" class="inline-flex items-center gap-1.5 rounded-full bg-success-50 px-2.5 py-0.5 text-tiny font-bold text-success-700">
                                        <span class="relative flex h-2 w-2"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-success-500 opacity-75"></span><span class="relative inline-flex h-2 w-2 rounded-full bg-success-600"></span></span>
                                        {{ $t('posBeach.newChip') }} · {{ ageLabel(order) }}
                                    </span>
                                    <span v-else class="rounded-full px-2.5 py-0.5 text-tiny font-bold" :class="ageClass(order)">⏱ {{ ageLabel(order) }}</span>
                                </div>
                                <p class="mt-1.5 truncate text-body-sm text-neutral-700">{{ itemsSummary(order) }}</p>
                                <div class="mt-2 flex items-center justify-between gap-2">
                                    <span class="text-body-sm font-extrabold tabular-nums text-neutral-900">{{ money(order.total_amount) }}</span>
                                    <button
                                        v-if="canSettle"
                                        type="button"
                                        class="rounded-xl px-3.5 py-2 text-tiny font-bold text-white transition disabled:cursor-not-allowed disabled:opacity-50"
                                        :class="confirmingId === order.id ? 'bg-warning-500 hover:bg-warning-600' : 'bg-success-600 hover:bg-success-700'"
                                        :disabled="!hasOpenShift || payingId === order.id"
                                        @click.stop="settle(order)"
                                    >
                                        {{ payingId === order.id ? $t('posBeach.paying') : (confirmingId === order.id ? $t('posBeach.confirmPay', { amount: money(order.total_amount) }) : $t('posBeach.deliverPay', { amount: money(order.total_amount) })) }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- NË PRITJE -->
                <template v-if="pendingGroups.length">
                    <h2 class="mt-6 flex items-center gap-2 text-tiny font-extrabold uppercase tracking-widest text-neutral-500">
                        {{ $t('posBeach.sectionPending') }}
                        <span class="grid h-5 min-w-5 place-items-center rounded-full bg-warning-500 px-1 text-tiny font-bold text-white">{{ pendingGroups.reduce((sum, group) => sum + group.orders.length, 0) }}</span>
                    </h2>
                    <div class="mt-3 grid items-start gap-4 lg:grid-cols-2 xl:grid-cols-3">
                        <div v-for="group in pendingGroups" :key="group.unit_id ?? 'counter'" class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm">
                            <div class="flex items-center justify-between border-b border-neutral-100 bg-neutral-50 px-4 py-3">
                                <div>
                                    <p class="text-base font-extrabold text-neutral-900">{{ group.unit_number ? '⛱️' : '🏪' }} {{ unitLabel(group) }}</p>
                                    <p v-if="group.zone_name" class="text-tiny font-medium text-neutral-500">{{ group.zone_name }}</p>
                                </div>
                                <span v-if="group.orders.length > 1" class="text-tiny font-semibold text-neutral-500">{{ $t('posBeach.ordersCount', { count: group.orders.length }) }}</span>
                            </div>
                            <div v-for="order in group.orders" :key="order.id" class="cursor-pointer border-b border-neutral-100 px-4 py-3 transition last:border-b-0 hover:bg-neutral-50" @click="openPreview(order)">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-tiny font-bold text-neutral-500">#{{ order.id }} · {{ clock(order) }}</span>
                                    <span class="rounded-full px-2.5 py-0.5 text-tiny font-bold" :class="ageClass(order)">⏱ {{ ageLabel(order) }}</span>
                                </div>
                                <p class="mt-1.5 truncate text-body-sm text-neutral-700">{{ itemsSummary(order) }}</p>
                                <div class="mt-2 flex items-center justify-between gap-2">
                                    <span class="text-body-sm font-extrabold tabular-nums text-neutral-900">{{ money(order.total_amount) }}</span>
                                    <button
                                        v-if="canSettle"
                                        type="button"
                                        class="rounded-xl px-3.5 py-2 text-tiny font-bold text-white transition disabled:cursor-not-allowed disabled:opacity-50"
                                        :class="confirmingId === order.id ? 'bg-warning-500 hover:bg-warning-600' : 'bg-success-600 hover:bg-success-700'"
                                        :disabled="!hasOpenShift || payingId === order.id"
                                        @click.stop="settle(order)"
                                    >
                                        {{ payingId === order.id ? $t('posBeach.paying') : (confirmingId === order.id ? $t('posBeach.confirmPay', { amount: money(order.total_amount) }) : $t('posBeach.deliverPay', { amount: money(order.total_amount) })) }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- TË HARRUARAT -->
                <template v-if="forgotten.length">
                    <h2 class="mt-6 flex items-center gap-2 text-tiny font-extrabold uppercase tracking-widest text-error-700">
                        {{ $t('posBeach.sectionForgotten') }}
                        <span class="grid h-5 min-w-5 place-items-center rounded-full bg-error-600 px-1 text-tiny font-bold text-white">{{ forgotten.length }}</span>
                    </h2>
                    <div class="mt-3 grid items-start gap-4 lg:grid-cols-2 xl:grid-cols-3">
                        <div v-for="order in forgotten" :key="order.id" class="cursor-pointer rounded-2xl border border-error-600/30 bg-white px-4 py-3 shadow-sm transition hover:bg-error-50/40" @click="openPreview(order)">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-tiny font-bold text-neutral-500">#{{ order.id }} · {{ order.unit_number ? '⛱️' : '🏪' }} {{ unitLabel(order) }} · {{ clockFull(order) }}</span>
                                <span class="rounded-full bg-error-50 px-2.5 py-0.5 text-tiny font-bold text-error-700">⏱ {{ $t('posBeach.daysOpen', { count: daysOpen(order) }) }}</span>
                            </div>
                            <p class="mt-1.5 truncate text-body-sm text-neutral-700">{{ itemsSummary(order) }}</p>
                            <p class="mt-1 text-tiny text-error-700">{{ $t('posBeach.forgottenNote') }}</p>
                            <div class="mt-2 flex items-center justify-between gap-2">
                                <span class="text-body-sm font-extrabold tabular-nums text-neutral-900">{{ money(order.total_amount) }}</span>
                                <button
                                    v-if="canSettle"
                                    type="button"
                                    class="rounded-xl px-3.5 py-2 text-tiny font-bold text-white transition disabled:cursor-not-allowed disabled:opacity-50"
                                    :class="confirmingId === order.id ? 'bg-warning-500 hover:bg-warning-600' : 'bg-success-600 hover:bg-success-700'"
                                    :disabled="!hasOpenShift || payingId === order.id"
                                    @click.stop="settle(order)"
                                >
                                    {{ payingId === order.id ? $t('posBeach.paying') : (confirmingId === order.id ? $t('posBeach.confirmPay', { amount: money(order.total_amount) }) : $t('posBeach.deliverPay', { amount: money(order.total_amount) })) }}
                                </button>
                            </div>
                        </div>
                    </div>
                </template>

                <p class="mt-8 text-center text-tiny text-neutral-400">↻ {{ $t('posBeach.autoRefresh') }}</p>
            </template>
        </div>

        <!-- Preview i detajuar: bottom-sheet në telefon, modal në desktop -->
        <Teleport to="body">
            <div v-if="selectedOrder" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center" @click.self="closePreview">
                <div class="max-h-[88vh] w-full overflow-y-auto rounded-t-3xl bg-white shadow-2xl sm:max-w-md sm:rounded-2xl">
                    <div class="sticky top-0 flex items-start justify-between gap-3 border-b border-neutral-100 bg-white px-5 pb-3 pt-4">
                        <div>
                            <h3 class="text-lg font-bold text-neutral-900">{{ $t('posBeach.previewTitle', { id: selectedOrder.id }) }}</h3>
                            <p class="text-body-sm text-neutral-500">
                                {{ selectedOrder.unit_number ? '⛱️' : '🏪' }} {{ unitLabel(selectedOrder) }}<template v-if="selectedOrder.zone_name"> · {{ selectedOrder.zone_name }}</template>
                            </p>
                        </div>
                        <button type="button" class="rounded-full p-2 text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-600" :aria-label="$t('posBeach.close')" @click="closePreview">✕</button>
                    </div>

                    <div class="px-5 py-4">
                        <div class="grid grid-cols-3 gap-2 text-center">
                            <div class="rounded-xl bg-neutral-50 px-2 py-2.5">
                                <p class="text-tiny uppercase tracking-wide text-neutral-400">{{ $t('posBeach.previewTime') }}</p>
                                <p class="mt-0.5 text-body-sm font-bold text-neutral-800">{{ clockFull(selectedOrder) }}</p>
                            </div>
                            <div class="rounded-xl bg-neutral-50 px-2 py-2.5">
                                <p class="text-tiny uppercase tracking-wide text-neutral-400">{{ $t('posBeach.previewAge') }}</p>
                                <p class="mt-0.5 text-body-sm font-bold" :class="ageMinutes(selectedOrder) >= 30 ? 'text-error-700' : 'text-neutral-800'">{{ ageLabel(selectedOrder) }}</p>
                            </div>
                            <div class="rounded-xl bg-neutral-50 px-2 py-2.5">
                                <p class="text-tiny uppercase tracking-wide text-neutral-400">{{ $t('posBeach.createdBy') }}</p>
                                <p class="mt-0.5 truncate text-body-sm font-bold text-neutral-800" :title="selectedOrder.created_by || ''">{{ selectedOrder.created_by || '—' }}</p>
                            </div>
                        </div>
                        <p class="mt-2 text-center text-tiny text-neutral-400">{{ $t('posBeach.guestOrder') }}</p>

                        <h4 class="mt-4 text-tiny font-extrabold uppercase tracking-widest text-neutral-400">{{ $t('posBeach.itemsTitle') }}</h4>
                        <div class="mt-1.5 divide-y divide-neutral-100 rounded-xl border border-neutral-100">
                            <div v-for="(item, index) in selectedOrder.items" :key="index" class="flex items-center justify-between px-3.5 py-2.5 text-body-sm">
                                <span class="text-neutral-800"><span class="font-semibold text-neutral-500">{{ item.quantity }}×</span> {{ item.name }}</span>
                                <span class="tabular-nums text-neutral-600">{{ money(item.total_price) }}</span>
                            </div>
                        </div>
                        <div class="mt-3 flex items-center justify-between text-base font-extrabold text-neutral-900">
                            <span>{{ $t('posBeach.totalLabel') }}</span>
                            <span class="tabular-nums">{{ money(selectedOrder.total_amount) }}</span>
                        </div>

                        <p v-if="flashError" class="mt-3 rounded-xl bg-error-50 px-4 py-2.5 text-body-sm text-error-700">{{ flashError }}</p>

                        <div class="mt-5 space-y-2 pb-2">
                            <button
                                v-if="canSettle"
                                type="button"
                                class="w-full rounded-xl px-4 py-3.5 text-body-sm font-bold text-white transition disabled:cursor-not-allowed disabled:opacity-50"
                                :class="confirmingId === selectedOrder.id ? 'bg-warning-500 hover:bg-warning-600' : 'bg-success-600 hover:bg-success-700'"
                                :disabled="!hasOpenShift || payingId === selectedOrder.id"
                                @click="settle(selectedOrder)"
                            >
                                {{ payingId === selectedOrder.id ? $t('posBeach.paying') : (confirmingId === selectedOrder.id ? $t('posBeach.confirmPay', { amount: money(selectedOrder.total_amount) }) : $t('posBeach.deliverPay', { amount: money(selectedOrder.total_amount) })) }}
                            </button>
                            <p v-if="canSettle && !hasOpenShift" class="text-center text-tiny text-warning-700">{{ $t('posBeach.noShiftTitle') }} — {{ $t('posBeach.openShiftLink') }}</p>

                            <template v-if="canSettle">
                                <button v-if="!showCancel" type="button" class="w-full py-1.5 text-tiny font-semibold text-neutral-400 underline decoration-neutral-300 underline-offset-4 transition hover:text-error-700" @click="showCancel = true">
                                    {{ $t('posBeach.cancelTitle') }}
                                </button>
                                <div v-else class="rounded-xl border border-error-600/25 bg-error-50/50 p-3">
                                    <input
                                        v-model="cancelForm.reason"
                                        type="text"
                                        class="w-full rounded-lg border-neutral-300 text-body-sm focus:border-error-600 focus:ring-error-600"
                                        :placeholder="$t('posBeach.cancelReasonPlaceholder')"
                                        maxlength="255"
                                    >
                                    <p v-if="cancelForm.errors.reason" class="mt-1 text-tiny text-error-700">{{ cancelForm.errors.reason }}</p>
                                    <div class="mt-2.5 flex gap-2">
                                        <button type="button" class="flex-1 rounded-lg border border-neutral-200 bg-white py-2 text-tiny font-bold text-neutral-600 hover:bg-neutral-50" @click="showCancel = false">
                                            {{ $t('posBeach.cancelKeep') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="flex-1 rounded-lg bg-error-600 py-2 text-tiny font-bold text-white transition hover:bg-error-700 disabled:opacity-50"
                                            :disabled="cancelForm.reason.trim().length < 3 || cancelForm.processing"
                                            @click="submitCancel(selectedOrder)"
                                        >
                                            {{ $t('posBeach.cancelSubmit') }}
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
