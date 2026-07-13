<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    threads: Array,
    selected: Object,
});

const replyForm = useForm({ body: '' });
const filter = ref('all'); // all | unread | booking.com | airbnb

// Sound alert on/off (read by AppLayout's poll via localStorage).
const soundMuted = ref(typeof window !== 'undefined' && localStorage.getItem('msgSoundMuted') === '1');
function toggleSound() {
    soundMuted.value = !soundMuted.value;
    localStorage.setItem('msgSoundMuted', soundMuted.value ? '1' : '0');
}

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

const QUICK_REPLIES = [
    { label: 'Orari i check-in', text: 'Check-in-i standard është ora 14:00 dhe check-out-i ora 11:00. Nëse ju nevojitet ndryshe, na thoni dhe do të mundohemi t\'ju akomodojmë.' },
    { label: 'Parking & aksesi', text: 'Kemi parking privat falas brenda oborrit. Adresa e saktë dhe udhëzimet do t\'jua dërgojmë një ditë para mbërritjes.' },
    { label: 'Orari i mëngjesit', text: 'Mëngjesi shërbehet çdo ditë nga ora 08:00 deri në 10:30 në restorantin tonë me pamje nga deti.' },
    { label: 'Faleminderit!', text: 'Faleminderit shumë! Presim t\'ju mirëpresim. Mirë se vini në Villa Mucho.' },
];

const filteredThreads = computed(() => {
    if (filter.value === 'all') return props.threads;
    if (filter.value === 'unread') return props.threads.filter((t) => t.unread > 0);
    return props.threads.filter((t) => (t.channel || '').startsWith(filter.value));
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
    router.get(route('messages.index'), { thread: id }, { preserveState: true, preserveScroll: true });
}
function useQuick(text) {
    replyForm.body = replyForm.body ? replyForm.body + ' ' + text : text;
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

    <AppLayout>
        <div v-if="!threads.length" class="rounded-2xl border border-neutral-200 bg-white px-6 py-20 text-center">
            <p class="text-base font-semibold text-neutral-800">Ende asnjë bisedë</p>
            <p class="mt-1 text-sm text-neutral-500">Kur një mysafir të shkruajë nga Booking, Airbnb ose Expedia, biseda do të shfaqet këtu.</p>
        </div>

        <div v-else class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm">
            <div class="grid min-h-[72vh] grid-cols-1 lg:grid-cols-[300px_1fr_300px]">
                <!-- Thread list -->
                <div class="flex min-h-0 flex-col border-r border-neutral-200">
                    <div class="px-4 pt-4">
                        <div class="flex items-center justify-between">
                            <h2 class="text-lg font-bold tracking-tight text-neutral-900">Mesazhet</h2>
                            <button type="button" @click="toggleSound"
                                :title="soundMuted ? 'Tingulli është i heshtur — kliko për ta ndezur' : 'Tingulli është ndezur — kliko për ta heshtur'"
                                class="grid h-8 w-8 place-items-center rounded-lg border border-neutral-200 text-neutral-500 transition hover:bg-neutral-50"
                                :class="soundMuted ? 'text-neutral-400' : 'text-[#15855c]'">
                                <svg v-if="!soundMuted" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3.75a.75.75 0 00-1.264-.546L5.203 6.5H3.167a.75.75 0 00-.7.48A6.985 6.985 0 002 9.5c0 .887.165 1.737.468 2.52.111.29.39.48.7.48h2.035l3.533 3.296A.75.75 0 0010 15.25V3.75zM12.293 7.293a1 1 0 011.414 0L15 8.586l1.293-1.293a1 1 0 111.414 1.414L16.414 10l1.293 1.293a1 1 0 01-1.414 1.414L15 11.414l-1.293 1.293a1 1 0 01-1.414-1.414L13.586 10l-1.293-1.293a1 1 0 010-1.414z"/></svg>
                                <svg v-else class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 3.75a.75.75 0 00-1.264-.546L5.203 6.5H3.167a.75.75 0 00-.7.48A6.985 6.985 0 002 9.5c0 .887.165 1.737.468 2.52.111.29.39.48.7.48h2.035l3.533 3.296A.75.75 0 0010 15.25V3.75zM13.28 7.22a.75.75 0 10-1.06 1.06L13.94 10l-1.72 1.72a.75.75 0 101.06 1.06L15 11.06l1.72 1.72a.75.75 0 101.06-1.06L16.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L15 8.94l-1.72-1.72z" clip-rule="evenodd" opacity=".4"/></svg>
                            </button>
                        </div>
                        <div class="relative mt-3">
                            <svg class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd" /></svg>
                            <input placeholder="Kërko mysafir…" class="w-full rounded-lg border-neutral-200 bg-neutral-50 py-2 pl-9 pr-3 text-sm" />
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-1.5 px-4 py-3">
                        <button v-for="f in [['all','Të gjitha'],['unread','Të palexuara'],['booking.com','Booking'],['airbnb','Airbnb']]" :key="f[0]"
                            class="rounded-full border px-2.5 py-1 text-xs font-semibold transition"
                            :class="filter === f[0] ? 'border-[#0f3b30] bg-[#0f3b30] text-white' : 'border-neutral-200 text-neutral-500 hover:border-neutral-300'"
                            @click="filter = f[0]">{{ f[1] }}</button>
                    </div>
                    <div class="flex-1 overflow-y-auto px-2.5 pb-3">
                        <button v-for="t in filteredThreads" :key="t.id" type="button"
                            class="relative mb-0.5 flex w-full gap-3 rounded-xl p-3 text-left transition"
                            :class="selected && selected.id === t.id ? 'bg-[#eaf5ef]' : 'hover:bg-neutral-50'"
                            @click="openThread(t.id)">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl text-sm font-bold text-white" :style="{ background: chan(t.channel).grad }">{{ initials(t.guest_name) }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-2">
                                    <span class="truncate text-sm font-semibold tracking-tight text-neutral-900">{{ t.guest_name }}</span>
                                    <span class="ml-auto shrink-0 text-[11px] text-neutral-400">{{ time(t.last_message_at) }}</span>
                                </span>
                                <span class="mt-0.5 flex items-center gap-2">
                                    <span class="shrink-0 rounded px-1.5 py-0.5 text-[9.5px] font-bold" :class="chan(t.channel).badge">{{ chan(t.channel).label }}</span>
                                    <span class="truncate text-xs" :class="t.unread > 0 ? 'font-semibold text-neutral-800' : 'text-neutral-500'">{{ t.preview }}</span>
                                </span>
                            </span>
                            <span v-if="t.unread > 0" class="absolute bottom-3 right-3 grid h-[19px] min-w-[19px] place-items-center rounded-full bg-[#15855c] px-1.5 text-[10.5px] font-bold text-white">{{ t.unread }}</span>
                        </button>
                    </div>
                </div>

                <!-- Conversation -->
                <div class="flex min-w-0 flex-col bg-[#f5f8f6]">
                    <template v-if="selected">
                        <div class="flex items-center gap-3 border-b border-neutral-200 bg-white px-5 py-3">
                            <span class="grid h-9 w-9 place-items-center rounded-xl text-sm font-bold text-white" :style="{ background: chan(selected.channel).grad }">{{ initials(selected.guest_name) }}</span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold tracking-tight text-neutral-900">{{ selected.guest_name || 'Mysafir' }}</p>
                                <p class="mt-0.5 flex items-center gap-2 text-xs text-neutral-400">
                                    <span class="rounded px-1.5 py-0.5 text-[9.5px] font-bold" :class="chan(selected.channel).badge">{{ chan(selected.channel).label }}</span>
                                    <span v-if="selected.reservation">· {{ selected.reservation.ref }}</span>
                                </p>
                            </div>
                        </div>

                        <div class="flex-1 space-y-2 overflow-y-auto px-5 py-5">
                            <template v-for="row in messageRows" :key="row.key">
                                <div v-if="row.sep" class="my-3 flex justify-center">
                                    <span class="rounded-full border border-neutral-200 bg-white px-3.5 py-1 text-[10.5px] font-semibold text-neutral-400">{{ row.sep }}</span>
                                </div>
                                <div v-else class="flex" :class="row.sender === 'host' ? 'justify-end' : 'justify-start'">
                                    <div class="max-w-[72%] rounded-2xl px-3.5 py-2.5 text-sm"
                                        :class="row.sender === 'host' ? 'rounded-br-md bg-[#15855c] text-white shadow-[0_4px_12px_-4px_rgba(21,133,92,0.5)]' : 'rounded-bl-md border border-neutral-200 bg-white text-neutral-800'">
                                        <p class="whitespace-pre-wrap break-words">{{ row.body }}</p>
                                        <p class="mt-1.5 flex items-center gap-1 text-[10px]" :class="row.sender === 'host' ? 'justify-end text-emerald-100' : 'text-neutral-400'">
                                            {{ clock(row.sent_at) }}
                                            <svg v-if="row.sender === 'host'" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.2a.75.75 0 01.1 1.05l-8 10.5a.75.75 0 01-1.13.07l-4.5-4.5a.75.75 0 011.06-1.06l3.9 3.9 7.48-9.82a.75.75 0 011.05-.14z" clip-rule="evenodd" /></svg>
                                        </p>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <template v-if="selected.can_reply">
                            <div class="flex flex-wrap gap-2 px-5 pb-2">
                                <button v-for="q in QUICK_REPLIES" :key="q.label" type="button"
                                    class="rounded-full border border-neutral-200 bg-white px-3 py-1.5 text-[11.5px] font-medium text-[#0c5a3e] transition hover:border-[#83dcb2] hover:bg-[#f2faf6]"
                                    @click="useQuick(q.text)">{{ q.label }}</button>
                            </div>
                            <form class="flex items-end gap-2.5 border-t border-neutral-200 bg-white p-3" @submit.prevent="sendReply">
                                <textarea v-model="replyForm.body" rows="1" placeholder="Shkruaj përgjigjen…  (Enter për të dërguar)"
                                    class="min-h-[46px] flex-1 resize-none rounded-xl border-neutral-200 px-3.5 py-3 text-sm focus:border-[#83dcb2] focus:ring-[#83dcb2]"
                                    @keydown.enter.exact.prevent="sendReply" />
                                <button type="submit" :disabled="replyForm.processing || !replyForm.body.trim()"
                                    class="inline-flex h-[46px] items-center gap-2 rounded-xl bg-[#15855c] px-4 text-sm font-semibold text-white transition hover:bg-[#0c5a3e] disabled:opacity-50">
                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M3.105 2.289a.75.75 0 00-.826.95l1.414 4.926A1.5 1.5 0 005.135 9.25h6.115a.75.75 0 010 1.5H5.135a1.5 1.5 0 00-1.442 1.085l-1.414 4.926a.75.75 0 00.826.95 28.897 28.897 0 0015.293-7.155.75.75 0 000-1.114A28.897 28.897 0 003.105 2.289z" /></svg>
                                    Dërgo
                                </button>
                            </form>
                        </template>
                        <p v-else class="border-t border-neutral-200 bg-white px-5 py-3 text-xs text-neutral-400">Kjo bisedë s'lejon përgjigje.</p>
                    </template>
                    <div v-else class="flex flex-1 items-center justify-center text-sm text-neutral-400">Zgjidh një bisedë majtas.</div>
                </div>

                <!-- Context panel -->
                <aside v-if="selected" class="hidden flex-col gap-5 border-l border-neutral-200 bg-white p-5 lg:flex">
                    <div>
                        <h3 class="text-[10.5px] font-bold uppercase tracking-widest text-neutral-400">Mysafiri</h3>
                        <div class="mt-2.5 flex items-center gap-3">
                            <span class="grid h-12 w-12 place-items-center rounded-2xl text-base font-bold text-white" :style="{ background: chan(selected.channel).grad }">{{ initials(selected.guest_name) }}</span>
                            <div class="min-w-0">
                                <p class="truncate text-[15px] font-bold tracking-tight text-neutral-900">{{ selected.guest_name || 'Mysafir' }}</p>
                                <p v-if="selected.guest_email" class="truncate text-xs text-neutral-500">{{ selected.guest_email }}</p>
                            </div>
                        </div>
                    </div>

                    <div v-if="selected.reservation">
                        <h3 class="text-[10.5px] font-bold uppercase tracking-widest text-neutral-400">Rezervimi</h3>
                        <div class="mt-2.5 overflow-hidden rounded-2xl border border-neutral-200">
                            <div class="flex items-center justify-between border-b border-neutral-200 bg-neutral-50/70 px-3.5 py-2.5 text-xs font-semibold">
                                <span>{{ selected.reservation.ref || ('#' + selected.reservation.id) }}</span>
                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10.5px] font-medium text-emerald-700">{{ statusLabel(selected.reservation.status) }}</span>
                            </div>
                            <div v-if="selected.reservation.room" class="flex justify-between gap-3 border-b border-neutral-100 px-3.5 py-2.5 text-xs"><span class="text-neutral-400">Dhoma</span><span class="text-right font-semibold">{{ selected.reservation.room }}</span></div>
                            <div class="flex justify-between gap-3 border-b border-neutral-100 px-3.5 py-2.5 text-xs"><span class="text-neutral-400">Check-in</span><span class="font-semibold tabular-nums">{{ fdate(selected.reservation.check_in) }}</span></div>
                            <div class="flex justify-between gap-3 border-b border-neutral-100 px-3.5 py-2.5 text-xs"><span class="text-neutral-400">Check-out</span><span class="font-semibold tabular-nums">{{ fdate(selected.reservation.check_out) }}</span></div>
                            <div class="flex justify-between gap-3 border-b border-neutral-100 px-3.5 py-2.5 text-xs"><span class="text-neutral-400">Netë · persona</span><span class="font-semibold tabular-nums">{{ selected.reservation.nights }} · {{ selected.reservation.adults }}</span></div>
                            <div class="flex justify-between gap-3 px-3.5 py-2.5 text-xs"><span class="text-neutral-400">Total</span><span class="font-semibold tabular-nums">{{ money(selected.reservation.total) }}</span></div>
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
    </AppLayout>
</template>
