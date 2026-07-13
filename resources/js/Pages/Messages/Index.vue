<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    threads: Array,
    selected: Object,
    quickReplies: Array,
});

const replyForm = useForm({ body: '' });
const filter = ref('all'); // all | unread | booking.com | airbnb

// Sound alert on/off (read by AppLayout's poll via localStorage).
const soundMuted = ref(typeof window !== 'undefined' && localStorage.getItem('msgSoundMuted') === '1');
function toggleSound() {
    soundMuted.value = !soundMuted.value;
    localStorage.setItem('msgSoundMuted', soundMuted.value ? '1' : '0');
}

// Guest/reservation side panel — collapsed by default, choice remembered.
const panelOpen = ref(typeof window !== 'undefined' && localStorage.getItem('msgPanelOpen') === '1');
function togglePanel() {
    panelOpen.value = !panelOpen.value;
    localStorage.setItem('msgPanelOpen', panelOpen.value ? '1' : '0');
}

// The page is a fixed frame; only the chat scrolls — so jump to the latest
// message whenever a thread opens or a new message lands.
const chatBox = ref(null);
watch(
    () => [props.selected?.id, props.selected?.messages?.length],
    () => {
        if (chatBox.value) chatBox.value.scrollTop = chatBox.value.scrollHeight;
    },
    { immediate: true, flush: 'post' },
);

const CHANNELS = {
    'booking.com': { label: 'Booking', badge: 'bg-[#eaf0fb] text-[#1a4fa0]', grad: 'linear-gradient(145deg,#2f6fd0,#1a4fa0)' },
    booking: { label: 'Booking', badge: 'bg-[#eaf0fb] text-[#1a4fa0]', grad: 'linear-gradient(145deg,#2f6fd0,#1a4fa0)' },
    airbnb: { label: 'Airbnb', badge: 'bg-[#fdeded] text-[#e0565b]', grad: 'linear-gradient(145deg,#ec7a7e,#e0565b)' },
    expedia: { label: 'Expedia', badge: 'bg-[#f9f1da] text-[#a9790a]', grad: 'linear-gradient(145deg,#caa031,#a9790a)' },
    agoda: { label: 'Agoda', badge: 'bg-neutral-100 text-neutral-600', grad: 'linear-gradient(145deg,#7c8b85,#556059)' },
};
function chan(c) {
    return CHANNELS[c] || { label: c || 'OTA', badge: 'bg-neutral-100 text-neutral-600', grad: 'linear-gradient(145deg,#7c8b85,#556059)' };
}
function initials(name) {
    return (name || 'M').split(' ').filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
}

// Quick-reply templates (WhatsApp-style): a ⚡ button beside the composer opens
// the list; picking one drops the text into the input. Templates are the
// hotel's own — managed in a modal and saved per-tenant on the server.
const quickOpen = ref(false);
const manageOpen = ref(false);
const manageForm = useForm({ replies: [] });
function openManage() {
    manageForm.replies = (props.quickReplies || []).map((q) => ({ ...q }));
    manageForm.clearErrors();
    manageOpen.value = true;
    quickOpen.value = false;
}
function addManageRow() {
    manageForm.replies.push({ label: '', text: '' });
}
function removeManageRow(i) {
    manageForm.replies.splice(i, 1);
}
function saveManage() {
    manageForm.post(route('messages.quick-replies'), {
        preserveScroll: true,
        onSuccess: () => (manageOpen.value = false),
    });
}
function pickQuick(text) {
    replyForm.body = replyForm.body ? replyForm.body.trimEnd() + ' ' + text : text;
    quickOpen.value = false;
}

// Active / Closed tabs, mirroring Channex: a finished conversation is closed
// (synced to Channex) and moves to the "Të mbyllura" tab.
const statusTab = ref('open'); // open | closed
function closeThread() {
    if (!props.selected) return;
    router.post(route('messages.close', props.selected.id), {}, { preserveScroll: true });
}
function reopenThread() {
    if (!props.selected) return;
    router.post(route('messages.reopen', props.selected.id), {}, { preserveScroll: true });
}

// Mobile is master-detail like WhatsApp: the list OR the chat, never stacked.
const mobileChatOpen = ref(false);

// The app topbar is hidden on this page (hide-header) — the hamburger in our
// own header opens the nav drawer through the layout's exposed method (a
// template ref, NOT inject: this page is the layout's parent, so inject
// would never resolve the layout's provide).
const layoutRef = ref(null);
function openMobileMenu() {
    layoutRef.value?.openMobileMenu?.();
}

const filteredThreads = computed(() => {
    const inTab = props.threads.filter((t) =>
        statusTab.value === 'closed' ? t.status === 'closed' : t.status !== 'closed');
    if (filter.value === 'all') return inTab;
    if (filter.value === 'unread') return inTab.filter((t) => t.unread > 0);
    return inTab.filter((t) => (t.channel || '').startsWith(filter.value));
});

function time(value) {
    if (!value) return '';
    const d = new Date(value);
    const sameDay = d.toDateString() === new Date().toDateString();
    return sameDay
        ? new Intl.DateTimeFormat('sq-AL', { hour: '2-digit', minute: '2-digit' }).format(d)
        : new Intl.DateTimeFormat('sq-AL', { day: '2-digit', month: 'short' }).format(d);
}
function clock(value) {
    return value ? new Intl.DateTimeFormat('sq-AL', { hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : '';
}
function dayLabel(value) {
    if (!value) return '';
    const d = new Date(value);
    const y = new Date(); y.setDate(y.getDate() - 1);
    if (d.toDateString() === new Date().toDateString()) return 'Sot';
    if (d.toDateString() === y.toDateString()) return 'Dje';
    return new Intl.DateTimeFormat('sq-AL', { day: '2-digit', month: 'long', year: 'numeric' }).format(d);
}
function fdate(value) {
    return value ? new Intl.DateTimeFormat('sq-AL', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(new Date(value)) : '—';
}
function money(v) {
    return new Intl.NumberFormat('sq-AL', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(v || 0);
}

const messageRows = computed(() => {
    const rows = [];
    let last = null;
    for (const m of props.selected?.messages || []) {
        const day = m.sent_at ? new Date(m.sent_at).toDateString() : '';
        if (day !== last) { rows.push({ sep: dayLabel(m.sent_at), key: 's' + m.id }); last = day; }
        rows.push({ ...m, key: 'm' + m.id });
    }
    return rows;
});

function openThread(id) {
    mobileChatOpen.value = true;
    router.get(route('messages.index'), { thread: id }, { preserveState: true, preserveScroll: true });
}
function backToList() {
    mobileChatOpen.value = false;
}
function sendReply() {
    if (!props.selected || !replyForm.body.trim()) return;
    replyForm.post(route('messages.reply', props.selected.id), {
        preserveScroll: true,
        onSuccess: () => replyForm.reset('body'),
    });
}
function statusLabel(s) {
    return { confirmed: 'Konfirmuar', checked_in: 'Në hotel', checked_out: 'Larguar', pending: 'Në pritje', cancelled: 'Anuluar' }[s] || s;
}
</script>

<template>
    <Head title="Mesazhet" />

    <!-- The chat brings its own header; the empty state keeps the normal chrome
         (without it a phone with zero threads would have no navigation at all). -->
    <AppLayout ref="layoutRef" :hide-header="threads.length > 0">
        <div v-if="!threads.length" class="rounded-2xl border border-neutral-200 bg-white px-6 py-20 text-center">
            <p class="text-base font-semibold text-neutral-800">Ende asnjë bisedë</p>
            <p class="mt-1 text-sm text-neutral-500">Kur një mysafir të shkruajë nga Booking, Airbnb ose Expedia, biseda do të shfaqet këtu.</p>
        </div>

        <!-- On phones the chat is PINNED over the whole viewport (fixed inset-0):
             the page itself can no longer scroll, so no gesture ever drags the
             header away. z-30 stays under the nav drawer (z-40/50). -->
        <div v-else class="fixed inset-0 z-30 overflow-hidden bg-white sm:static sm:z-auto sm:rounded-2xl sm:border sm:border-neutral-200 sm:shadow-sm">
            <div class="grid h-full grid-cols-1 sm:h-[calc(100dvh-3.1rem)]"
                :class="selected && panelOpen ? 'lg:grid-cols-[300px_1fr_300px]' : 'lg:grid-cols-[300px_1fr]'">
                <!-- Thread list (on mobile: hidden while a chat is open, like WhatsApp) -->
                <div class="min-h-0 flex-col border-r border-neutral-200" :class="mobileChatOpen ? 'hidden lg:flex' : 'flex'">
                    <div class="px-4 pt-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-1.5">
                                <button type="button" @click="openMobileMenu" title="Menu"
                                    class="-ml-1.5 grid h-8 w-8 place-items-center rounded-lg text-neutral-500 transition hover:bg-neutral-100 lg:hidden">
                                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" /></svg>
                                </button>
                                <h2 class="text-[15px] font-bold tracking-tight text-neutral-900">Mesazhet</h2>
                            </div>
                            <button type="button" @click="toggleSound"
                                :title="soundMuted ? 'Tingulli është i heshtur — kliko për ta ndezur' : 'Tingulli është ndezur — kliko për ta heshtur'"
                                class="grid h-8 w-8 place-items-center rounded-lg border border-neutral-200 text-neutral-500 transition hover:bg-neutral-50"
                                :class="soundMuted ? 'text-neutral-400' : 'text-[#15855c]'">
                                <svg v-if="!soundMuted" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3.75a.75.75 0 00-1.264-.546L5.203 6.5H3.167a.75.75 0 00-.7.48A6.985 6.985 0 002 9.5c0 .887.165 1.737.468 2.52.111.29.39.48.7.48h2.035l3.533 3.296A.75.75 0 0010 15.25V3.75zM12.293 7.293a1 1 0 011.414 0L15 8.586l1.293-1.293a1 1 0 111.414 1.414L16.414 10l1.293 1.293a1 1 0 01-1.414 1.414L15 11.414l-1.293 1.293a1 1 0 01-1.414-1.414L13.586 10l-1.293-1.293a1 1 0 010-1.414z"/></svg>
                                <svg v-else class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 3.75a.75.75 0 00-1.264-.546L5.203 6.5H3.167a.75.75 0 00-.7.48A6.985 6.985 0 002 9.5c0 .887.165 1.737.468 2.52.111.29.39.48.7.48h2.035l3.533 3.296A.75.75 0 0010 15.25V3.75zM13.28 7.22a.75.75 0 10-1.06 1.06L13.94 10l-1.72 1.72a.75.75 0 101.06 1.06L15 11.06l1.72 1.72a.75.75 0 101.06-1.06L16.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L15 8.94l-1.72-1.72z" clip-rule="evenodd" opacity=".4"/></svg>
                            </button>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-1 rounded-lg bg-neutral-100 p-1">
                            <button v-for="tab in [['open','Aktive'],['closed','Të mbyllura']]" :key="tab[0]" type="button"
                                class="rounded-md py-1.5 text-[12px] font-semibold transition"
                                :class="statusTab === tab[0] ? 'bg-white text-neutral-900 shadow-sm' : 'text-neutral-500 hover:text-neutral-700'"
                                @click="statusTab = tab[0]">{{ tab[1] }}</button>
                        </div>
                        <div class="relative mt-3">
                            <svg class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd" /></svg>
                            <input placeholder="Kërko mysafir…" class="w-full rounded-lg border-neutral-200 bg-neutral-50 py-1.5 pl-9 pr-3 text-[12.5px]" />
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-1.5 px-4 py-3">
                        <button v-for="f in [['all','Të gjitha'],['unread','Të palexuara'],['booking.com','Booking'],['airbnb','Airbnb']]" :key="f[0]"
                            class="rounded-full border px-2.5 py-0.5 text-[11px] font-semibold transition"
                            :class="filter === f[0] ? 'border-[#0f3b30] bg-[#0f3b30] text-white' : 'border-neutral-200 text-neutral-500 hover:border-neutral-300'"
                            @click="filter = f[0]">{{ f[1] }}</button>
                    </div>
                    <div class="flex-1 overflow-y-auto overscroll-contain px-2.5 pb-3">
                        <button v-for="t in filteredThreads" :key="t.id" type="button"
                            class="relative mb-0.5 flex w-full gap-2.5 rounded-xl p-2.5 text-left transition"
                            :class="selected && selected.id === t.id ? 'bg-[#eaf5ef]' : 'hover:bg-neutral-50'"
                            @click="openThread(t.id)">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-[12.5px] font-bold text-white" :style="{ background: chan(t.channel).grad }">{{ initials(t.guest_name) }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-2">
                                    <span class="truncate text-[13px] font-semibold tracking-tight text-neutral-900">{{ t.guest_name }}</span>
                                    <span class="ml-auto shrink-0 text-[10.5px] text-neutral-400">{{ time(t.last_message_at) }}</span>
                                </span>
                                <span class="mt-0.5 flex items-center gap-2">
                                    <span class="shrink-0 rounded px-1.5 py-0.5 text-[9.5px] font-bold" :class="chan(t.channel).badge">{{ chan(t.channel).label }}</span>
                                    <span class="truncate text-[11.5px]" :class="t.unread > 0 ? 'font-semibold text-neutral-800' : 'text-neutral-500'">{{ t.preview }}</span>
                                </span>
                            </span>
                            <span v-if="t.unread > 0" class="absolute bottom-2.5 right-2.5 grid h-[17px] min-w-[17px] place-items-center rounded-full bg-[#15855c] px-1.5 text-[10px] font-bold text-white">{{ t.unread }}</span>
                        </button>
                    </div>
                </div>

                <!-- Conversation (on mobile: full screen only when a chat is open) -->
                <div class="min-h-0 min-w-0 flex-col bg-[#f5f8f6]" :class="mobileChatOpen ? 'flex' : 'hidden lg:flex'">
                    <template v-if="selected">
                        <div class="flex items-center gap-2.5 border-b border-neutral-200 bg-white px-3.5 py-2.5 sm:px-5">
                            <button type="button" @click="backToList" class="-ml-1 grid h-8 w-8 shrink-0 place-items-center rounded-lg text-neutral-500 transition hover:bg-neutral-100 lg:hidden" title="Kthehu te lista">
                                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M17 10a.75.75 0 01-.75.75H5.612l4.158 3.96a.75.75 0 11-1.04 1.08l-5.5-5.25a.75.75 0 010-1.08l5.5-5.25a.75.75 0 111.04 1.08L5.612 9.25H16.25A.75.75 0 0117 10z" clip-rule="evenodd" /></svg>
                            </button>
                            <span class="grid h-8 w-8 place-items-center rounded-lg text-[12.5px] font-bold text-white" :style="{ background: chan(selected.channel).grad }">{{ initials(selected.guest_name) }}</span>
                            <div class="min-w-0">
                                <p class="truncate text-[13px] font-bold tracking-tight text-neutral-900">{{ selected.guest_name || 'Mysafir' }}</p>
                                <p class="mt-0.5 flex items-center gap-2 text-[11px] text-neutral-400">
                                    <span class="rounded px-1.5 py-0.5 text-[9.5px] font-bold" :class="chan(selected.channel).badge">{{ chan(selected.channel).label }}</span>
                                    <span v-if="selected.reservation">· {{ selected.reservation.ref }}</span>
                                    <span v-if="selected.status === 'closed'" class="rounded bg-neutral-200 px-1.5 py-0.5 text-[9.5px] font-bold text-neutral-600">E MBYLLUR</span>
                                </p>
                            </div>
                            <div class="ml-auto flex shrink-0 items-center gap-2">
                            <button v-if="selected.status !== 'closed'" type="button" @click="closeThread" title="Mbyll bisedën — kalon te 'Të mbyllura'"
                                class="grid h-9 w-9 place-items-center rounded-lg border border-neutral-200 text-neutral-500 transition hover:border-[#83dcb2] hover:bg-[#f2faf6] hover:text-[#0c5a3e]">
                                <svg class="h-[18px] w-[18px]" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" /></svg>
                            </button>
                            <button v-else type="button" @click="reopenThread" title="Rihap bisedën"
                                class="grid h-9 w-9 place-items-center rounded-lg border border-amber-200 bg-amber-50 text-amber-600 transition hover:bg-amber-100">
                                <svg class="h-[18px] w-[18px]" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 01-9.201 2.466l-.312-.311h2.433a.75.75 0 000-1.5H4.233a.75.75 0 00-.75.75v4a.75.75 0 001.5 0v-2.146l.312.311a7 7 0 0011.712-3.138.75.75 0 00-1.449-.39zm1.455-8.174a.75.75 0 00-1.5 0v2.146l-.312-.311a7 7 0 00-11.712 3.138.75.75 0 001.449.39 5.5 5.5 0 019.201-2.466l.312.311h-2.433a.75.75 0 000 1.5h3.999a.75.75 0 00.75-.75v-4z" clip-rule="evenodd" /></svg>
                            </button>
                            <button type="button" @click="togglePanel"
                                :title="panelOpen ? 'Mbyll panelin e mysafirit' : 'Hap panelin e mysafirit'"
                                class="hidden h-9 shrink-0 items-center gap-2 rounded-lg border px-3 text-xs font-semibold transition lg:inline-flex"
                                :class="panelOpen ? 'border-[#83dcb2] bg-[#f2faf6] text-[#0c5a3e]' : 'border-neutral-200 text-neutral-500 hover:bg-neutral-50'">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z" clip-rule="evenodd" /></svg>
                                Mysafiri
                            </button>
                            </div>
                        </div>

                        <div ref="chatBox" class="flex-1 space-y-2 overflow-y-auto overscroll-contain px-5 py-5">
                            <template v-for="row in messageRows" :key="row.key">
                                <div v-if="row.sep" class="my-3 flex justify-center">
                                    <span class="rounded-full border border-neutral-200 bg-white px-3 py-0.5 text-[10px] font-semibold text-neutral-400">{{ row.sep }}</span>
                                </div>
                                <div v-else class="flex" :class="row.sender === 'host' ? 'justify-end' : 'justify-start'">
                                    <div class="max-w-[78%] rounded-2xl px-3 py-2 text-[13px] leading-relaxed"
                                        :class="row.sender === 'host' ? 'rounded-br-md bg-[#15855c] text-white shadow-[0_4px_12px_-4px_rgba(21,133,92,0.5)]' : 'rounded-bl-md border border-neutral-200 bg-white text-neutral-800'">
                                        <p class="whitespace-pre-wrap break-words">{{ row.body }}</p>
                                        <p class="mt-1 flex items-center gap-1 text-[9.5px]" :class="row.sender === 'host' ? 'justify-end text-emerald-100' : 'text-neutral-400'">
                                            {{ clock(row.sent_at) }}
                                            <svg v-if="row.sender === 'host'" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.2a.75.75 0 01.1 1.05l-8 10.5a.75.75 0 01-1.13.07l-4.5-4.5a.75.75 0 011.06-1.06l3.9 3.9 7.48-9.82a.75.75 0 011.05-.14z" clip-rule="evenodd" /></svg>
                                        </p>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <div v-if="selected.status === 'closed'" class="flex items-center justify-between gap-3 border-t border-neutral-200 bg-neutral-50 px-4 py-3">
                            <p class="text-[12px] text-neutral-500">Bisedë e mbyllur.</p>
                            <button type="button" @click="reopenThread"
                                class="rounded-lg bg-[#15855c] px-3.5 py-2 text-[12px] font-semibold text-white transition hover:bg-[#0c5a3e]">Rihap bisedën</button>
                        </div>
                        <template v-else-if="selected.can_reply">
                            <form class="relative flex items-end gap-2 border-t border-neutral-200 bg-white p-2.5" @submit.prevent="sendReply">
                                <!-- Quick-reply picker (WhatsApp-style, above the composer) -->
                                <div v-if="quickOpen" class="fixed inset-0 z-10" @click="quickOpen = false" />
                                <div v-if="quickOpen" class="absolute bottom-full left-2.5 z-20 mb-2 w-[calc(100%-1.25rem)] max-w-sm overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-xl">
                                    <div class="max-h-64 overflow-y-auto py-1">
                                        <button v-for="q in quickReplies" :key="q.label" type="button"
                                            class="block w-full px-3.5 py-2 text-left transition hover:bg-[#f2faf6]"
                                            @click="pickQuick(q.text)">
                                            <span class="block text-[12px] font-semibold text-neutral-800">{{ q.label }}</span>
                                            <span class="block truncate text-[11px] text-neutral-400">{{ q.text }}</span>
                                        </button>
                                        <p v-if="!quickReplies?.length" class="px-3.5 py-3 text-[11.5px] text-neutral-400">Ende asnjë përgjigje e shpejtë.</p>
                                    </div>
                                    <button type="button" @click="openManage"
                                        class="flex w-full items-center gap-2 border-t border-neutral-100 px-3.5 py-2.5 text-[11.5px] font-semibold text-[#0c5a3e] transition hover:bg-[#f2faf6]">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M2.695 14.763l-1.262 3.154a.5.5 0 00.65.65l3.155-1.262a4 4 0 001.343-.885L17.5 5.5a2.121 2.121 0 00-3-3L3.58 13.42a4 4 0 00-.885 1.343z" /></svg>
                                        Menaxho përgjigjet e shpejta
                                    </button>
                                </div>

                                <button type="button" @click="quickOpen = !quickOpen"
                                    title="Përgjigjet e shpejta"
                                    class="grid h-[42px] w-[42px] shrink-0 place-items-center rounded-xl border transition"
                                    :class="quickOpen ? 'border-[#83dcb2] bg-[#f2faf6] text-[#0c5a3e]' : 'border-neutral-200 text-neutral-500 hover:bg-neutral-50'">
                                    <svg class="h-[18px] w-[18px]" viewBox="0 0 20 20" fill="currentColor"><path d="M11.983 1.907a.75.75 0 00-1.292-.657l-8.5 9.5A.75.75 0 002.75 12h4.716l-1.449 6.093a.75.75 0 001.292.657l8.5-9.5A.75.75 0 0015.25 8h-4.716l1.449-6.093z" /></svg>
                                </button>
                                <textarea v-model="replyForm.body" rows="1" placeholder="Shkruaj përgjigjen…"
                                    class="min-h-[42px] flex-1 resize-none rounded-xl border-neutral-200 px-3 py-2.5 text-[13px] focus:border-[#83dcb2] focus:ring-[#83dcb2]"
                                    @keydown.enter.exact.prevent="sendReply" />
                                <button type="submit" :disabled="replyForm.processing || !replyForm.body.trim()"
                                    class="grid h-[42px] w-[42px] shrink-0 place-items-center rounded-xl bg-[#15855c] text-white transition hover:bg-[#0c5a3e] disabled:opacity-50"
                                    title="Dërgo">
                                    <svg class="h-[18px] w-[18px]" viewBox="0 0 20 20" fill="currentColor"><path d="M3.105 2.289a.75.75 0 00-.826.95l1.414 4.926A1.5 1.5 0 005.135 9.25h6.115a.75.75 0 010 1.5H5.135a1.5 1.5 0 00-1.442 1.085l-1.414 4.926a.75.75 0 00.826.95 28.897 28.897 0 0015.293-7.155.75.75 0 000-1.114A28.897 28.897 0 003.105 2.289z" /></svg>
                                </button>
                            </form>
                        </template>
                        <p v-else class="border-t border-neutral-200 bg-white px-5 py-3 text-xs text-neutral-400">Kjo bisedë s'lejon përgjigje.</p>
                    </template>
                    <div v-else class="flex flex-1 items-center justify-center text-[12.5px] text-neutral-400">Zgjidh një bisedë majtas.</div>
                </div>

                <!-- Context panel -->
                <aside v-if="selected && panelOpen" class="hidden min-h-0 flex-col gap-5 overflow-y-auto overscroll-contain border-l border-neutral-200 bg-white p-5 lg:flex">
                    <div>
                        <h3 class="text-[10.5px] font-bold uppercase tracking-widest text-neutral-400">Mysafiri</h3>
                        <div class="mt-2.5 flex items-center gap-3">
                            <span class="grid h-10 w-10 place-items-center rounded-xl text-sm font-bold text-white" :style="{ background: chan(selected.channel).grad }">{{ initials(selected.guest_name) }}</span>
                            <div class="min-w-0">
                                <p class="truncate text-[13.5px] font-bold tracking-tight text-neutral-900">{{ selected.guest_name || 'Mysafir' }}</p>
                                <p v-if="selected.guest_email" class="truncate text-[11.5px] text-neutral-500">{{ selected.guest_email }}</p>
                            </div>
                        </div>
                    </div>

                    <div v-if="selected.reservation">
                        <h3 class="text-[10.5px] font-bold uppercase tracking-widest text-neutral-400">Rezervimi</h3>
                        <div class="mt-2.5 overflow-hidden rounded-2xl border border-neutral-200">
                            <div class="flex items-center justify-between border-b border-neutral-200 bg-neutral-50/70 px-3 py-2 text-[11.5px] font-semibold">
                                <span>{{ selected.reservation.ref || ('#' + selected.reservation.id) }}</span>
                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10.5px] font-medium text-emerald-700">{{ statusLabel(selected.reservation.status) }}</span>
                            </div>
                            <div v-if="selected.reservation.room" class="flex justify-between gap-3 border-b border-neutral-100 px-3 py-2 text-[11.5px]"><span class="text-neutral-400">Dhoma</span><span class="text-right font-semibold">{{ selected.reservation.room }}</span></div>
                            <div class="flex justify-between gap-3 border-b border-neutral-100 px-3 py-2 text-[11.5px]"><span class="text-neutral-400">Check-in</span><span class="font-semibold tabular-nums">{{ fdate(selected.reservation.check_in) }}</span></div>
                            <div class="flex justify-between gap-3 border-b border-neutral-100 px-3 py-2 text-[11.5px]"><span class="text-neutral-400">Check-out</span><span class="font-semibold tabular-nums">{{ fdate(selected.reservation.check_out) }}</span></div>
                            <div class="flex justify-between gap-3 border-b border-neutral-100 px-3 py-2 text-[11.5px]"><span class="text-neutral-400">Netë · persona</span><span class="font-semibold tabular-nums">{{ selected.reservation.nights }} · {{ selected.reservation.adults }}</span></div>
                            <div class="flex justify-between gap-3 px-3 py-2 text-[11.5px]"><span class="text-neutral-400">Total</span><span class="font-semibold tabular-nums">{{ money(selected.reservation.total) }}</span></div>
                        </div>
                        <Link :href="route('reservations.index')" class="mt-2.5 flex items-center justify-center gap-2 rounded-xl border border-neutral-200 py-2.5 text-xs font-semibold text-[#0c5a3e] no-underline transition hover:border-[#83dcb2] hover:bg-[#f2faf6]">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.25 5.5a.75.75 0 00-.75.75v8.5c0 .414.336.75.75.75h8.5a.75.75 0 00.75-.75v-4a.75.75 0 011.5 0v4A2.25 2.25 0 0112.75 17h-8.5A2.25 2.25 0 012 14.75v-8.5A2.25 2.25 0 014.25 4h4a.75.75 0 010 1.5h-4z" clip-rule="evenodd" /><path fill-rule="evenodd" d="M6.194 12.753a.75.75 0 001.06.053L16.5 4.44v2.81a.75.75 0 001.5 0v-4.5a.75.75 0 00-.75-.75h-4.5a.75.75 0 000 1.5h2.553l-9.056 8.194a.75.75 0 00-.053 1.06z" clip-rule="evenodd" /></svg>
                            Hap rezervimet
                        </Link>
                    </div>
                    <div v-else>
                        <h3 class="text-[10.5px] font-bold uppercase tracking-widest text-neutral-400">Rezervimi</h3>
                        <p class="mt-2.5 rounded-2xl border border-neutral-200 bg-neutral-50/60 px-3.5 py-4 text-xs text-neutral-500">
                            Kjo bisedë s'u lidh dot me një rezervim. Do të lidhet automatikisht kur rezervimi i OTA-s të mbërrijë.
                        </p>
                    </div>
                </aside>
            </div>
        </div>

        <!-- Manage quick replies -->
        <div v-if="manageOpen" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4" @click.self="manageOpen = false">
            <div class="flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-neutral-200 px-4 py-3">
                    <h3 class="text-[13.5px] font-bold tracking-tight text-neutral-900">Përgjigjet e shpejta</h3>
                    <button type="button" @click="manageOpen = false" class="grid h-8 w-8 place-items-center rounded-lg text-neutral-400 transition hover:bg-neutral-100">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                    </button>
                </div>
                <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-3">
                    <div v-for="(row, i) in manageForm.replies" :key="i" class="rounded-xl border border-neutral-200 p-2.5">
                        <div class="flex items-center gap-2">
                            <input v-model="row.label" maxlength="40" placeholder="Titulli (p.sh. Wifi)"
                                class="flex-1 rounded-lg border-neutral-200 px-2.5 py-1.5 text-[12.5px] font-semibold focus:border-[#83dcb2] focus:ring-[#83dcb2]" />
                            <button type="button" @click="removeManageRow(i)" title="Fshi"
                                class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-neutral-400 transition hover:bg-red-50 hover:text-red-600">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 006 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 10.23 1.482l.149-.022.841 10.518A2.75 2.75 0 007.596 19h4.807a2.75 2.75 0 002.742-2.53l.841-10.52.149.023a.75.75 0 00.23-1.482A41.03 41.03 0 0014 4.193V3.75A2.75 2.75 0 0011.25 1h-2.5zM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4zM8.58 7.72a.75.75 0 00-1.5.06l.3 7.5a.75.75 0 101.5-.06l-.3-7.5zm4.34.06a.75.75 0 10-1.5-.06l-.3 7.5a.75.75 0 101.5.06l.3-7.5z" clip-rule="evenodd" /></svg>
                            </button>
                        </div>
                        <textarea v-model="row.text" rows="2" maxlength="1000" placeholder="Teksti që i dërgohet mysafirit…"
                            class="mt-2 w-full resize-none rounded-lg border-neutral-200 px-2.5 py-1.5 text-[12.5px] focus:border-[#83dcb2] focus:ring-[#83dcb2]" />
                        <p v-if="manageForm.errors[`replies.${i}.label`] || manageForm.errors[`replies.${i}.text`]" class="mt-1 text-[11px] text-red-600">
                            {{ manageForm.errors[`replies.${i}.label`] || manageForm.errors[`replies.${i}.text`] }}
                        </p>
                    </div>
                    <button type="button" @click="addManageRow"
                        class="flex w-full items-center justify-center gap-2 rounded-xl border border-dashed border-neutral-300 py-2.5 text-[12px] font-semibold text-neutral-500 transition hover:border-[#83dcb2] hover:text-[#0c5a3e]">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" /></svg>
                        Shto përgjigje
                    </button>
                </div>
                <div class="flex items-center justify-end gap-2 border-t border-neutral-200 px-4 py-3">
                    <button type="button" @click="manageOpen = false" class="rounded-xl border border-neutral-200 px-3.5 py-2 text-[12.5px] font-semibold text-neutral-600 transition hover:bg-neutral-50">Anulo</button>
                    <button type="button" @click="saveManage" :disabled="manageForm.processing"
                        class="rounded-xl bg-[#15855c] px-4 py-2 text-[12.5px] font-semibold text-white transition hover:bg-[#0c5a3e] disabled:opacity-50">Ruaj</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
