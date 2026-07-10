<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Bell, Volume2, VolumeX } from 'lucide-vue-next';

const currentUserId = Number(usePage().props.auth.user?.id || 0);
const LAST_ID_KEY = `notif_last_received_reservation_id_v2:${currentUserId}`;
const UNREAD_IDS_KEY = `notif_unread_reservation_ids_v2:${currentUserId}`;
const SOUND_KEY = `notif_reservation_sound_enabled:${currentUserId}`;

const open = ref(false);
const count = ref(0);
const items = ref([]);
const toast = ref(null); // { guest, room }
const soundEnabled = ref(localStorage.getItem(SOUND_KEY) !== '0');
let timer = null;
let toastTimer = null;
let polling = false;

const unreadIds = new Set();

function lastReceived() {
    const raw = localStorage.getItem(LAST_ID_KEY);
    if (raw === null) return null;
    const value = Number(raw);
    return Number.isFinite(value) ? value : 0;
}

function loadUnread() {
    try {
        const arr = JSON.parse(localStorage.getItem(UNREAD_IDS_KEY) || '[]');
        if (Array.isArray(arr)) arr.forEach((id) => unreadIds.add(Number(id)));
    } catch (e) { /* corrupt value — ignore */ }
}
function saveUnread() {
    try { localStorage.setItem(UNREAD_IDS_KEY, JSON.stringify([...unreadIds])); } catch (e) { /* ignore */ }
}
function markSeen(id) {
    if (unreadIds.delete(Number(id))) saveUnread();
}

function getAudioContext() {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return null;
    if (!window.__reservationNotificationAudioContext) {
        window.__reservationNotificationAudioContext = new Ctx();
    }
    return window.__reservationNotificationAudioContext;
}

async function unlockAudio() {
    try {
        const ctx = getAudioContext();
        if (ctx && ctx.state !== 'running') await ctx.resume();
    } catch (e) { /* browser policy / unsupported audio */ }
}

function playDing(ctx) {
    const notes = [880, 1320];
    notes.forEach((freq, i) => {
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
    } catch (e) { /* browser policy / unsupported audio */ }
}

async function toggleSound() {
    soundEnabled.value = !soundEnabled.value;
    localStorage.setItem(SOUND_KEY, soundEnabled.value ? '1' : '0');
    if (soundEnabled.value) {
        await unlockAudio();
        ding();
    }
}

function showToast(res) {
    toast.value = res;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toast.value = null; }, 8000);
}

async function poll() {
    if (polling) return;
    polling = true;
    try {
        const r = await fetch('/pms/notifications/reservations', { headers: { Accept: 'application/json' } });
        if (!r.ok) return;
        const data = await r.json();
        const all = data.reservations || [];

        // Keep a small durable unread set across Inertia navigations and reloads.
        const liveIds = new Set(all.map((x) => x.id));
        let unreadChanged = false;
        unreadIds.forEach((id) => {
            if (!liveIds.has(id)) {
                unreadIds.delete(id);
                unreadChanged = true;
            }
        });

        const maxId = all.length ? Math.max(...all.map((x) => x.id)) : 0;
        const previousMax = lastReceived();
        if (previousMax === null) {
            // First visit establishes a baseline, without ringing for old bookings.
            localStorage.setItem(LAST_ID_KEY, String(maxId));
            if (unreadChanged) saveUnread();
            items.value = all.filter((x) => unreadIds.has(x.id));
            count.value = items.value.length;
            return;
        }

        const fresh = all.filter((x) =>
            x.id > previousMax
            && !(x.channel === 'manual' && Number(x.created_by) === currentUserId)
        );
        if (fresh.length) {
            fresh.forEach((x) => unreadIds.add(x.id));
            unreadChanged = true;
        }
        if (maxId > previousMax) localStorage.setItem(LAST_ID_KEY, String(maxId));
        if (unreadChanged) saveUnread();

        items.value = all.filter((x) => unreadIds.has(x.id));
        count.value = items.value.length;

        if (fresh.length) {
            const newest = fresh.reduce((latest, item) => item.id > latest.id ? item : latest);
            ding();
            showToast(newest);
        }
    } catch (e) { /* offline / ignore */ }
    finally { polling = false; }
}

function goTo(id) {
    // Acknowledge it, then drop it from the bell immediately (optimistic) so it
    // doesn't linger as "new" while we navigate to the reservation.
    markSeen(id);
    items.value = items.value.filter((x) => x.id !== id);
    count.value = items.value.length;
    open.value = false;
    toast.value = null;
    clearTimeout(toastTimer);
    router.visit(`/pms/reservations/${id}`);
}

onMounted(() => {
    loadUnread();
    // Browsers allow later notification sounds after one user gesture resumes audio.
    window.addEventListener('pointerdown', unlockAudio, { capture: true });
    window.addEventListener('keydown', unlockAudio, { capture: true });
    poll();
    timer = setInterval(poll, 10000);
});
onUnmounted(() => {
    clearInterval(timer);
    clearTimeout(toastTimer);
    window.removeEventListener('pointerdown', unlockAudio, true);
    window.removeEventListener('keydown', unlockAudio, true);
});
</script>

<template>
    <div class="relative">
        <button
            type="button"
            class="relative rounded-md p-2 text-neutral-500 hover:text-neutral-700 hover:bg-neutral-100 transition-colors"
            :aria-label="`Rezervime të reja: ${count}`"
            @click="open = !open"
        >
            <Bell class="h-5 w-5" :stroke-width="1.6" />
            <span v-if="count > 0" class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-error-600 text-white text-[10px] font-semibold flex items-center justify-center">
                {{ count > 99 ? '99+' : count }}
            </span>
        </button>

        <!-- Dropdown -->
        <div v-if="open" class="fixed inset-0 z-40" @click="open = false" />
        <div v-if="open" class="absolute right-0 mt-2 w-80 z-50 rounded-lg bg-white shadow-dropdown border border-neutral-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-neutral-100 flex items-center justify-between gap-3">
                <div>
                    <span class="block text-body-sm font-medium text-primary-900">Rezervime të reja</span>
                    <span class="block text-tiny text-neutral-400">{{ count }} pa lexuar</span>
                </div>
                <button
                    type="button"
                    class="rounded-md p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-700"
                    :title="soundEnabled ? 'Çaktivizo tingullin' : 'Aktivizo tingullin'"
                    :aria-label="soundEnabled ? 'Çaktivizo tingullin' : 'Aktivizo tingullin'"
                    @click="toggleSound"
                >
                    <Volume2 v-if="soundEnabled" class="h-4 w-4" :stroke-width="1.7" />
                    <VolumeX v-else class="h-4 w-4" :stroke-width="1.7" />
                </button>
            </div>
            <div class="max-h-80 overflow-y-auto">
                <button
                    v-for="r in items"
                    :key="r.id"
                    class="w-full text-left px-4 py-3 hover:bg-neutral-50 border-b border-neutral-50 transition-colors"
                    @click="goTo(r.id)"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-body-sm font-medium text-primary-900 truncate">{{ r.guest }}</span>
                        <span class="text-tiny text-accent-700 whitespace-nowrap">€{{ r.total }}</span>
                    </div>
                    <div class="text-small text-neutral-500 mt-0.5">
                        Dhoma {{ r.room }} · {{ r.check_in }} → {{ r.check_out }}
                    </div>
                </button>
                <div v-if="!items.length" class="px-4 py-8 text-center text-body-sm text-neutral-400">
                    Asnjë rezervim i ri.
                </div>
            </div>
            <a href="/pms/reservations" class="block px-4 py-2.5 text-center text-body-sm text-accent-700 hover:bg-neutral-50 border-t border-neutral-100 no-underline">
                Shiko të gjitha rezervimet
            </a>
        </div>

        <!-- Live toast on a brand-new reservation -->
        <Teleport to="body">
            <Transition
                enter-active-class="duration-300 ease-out" enter-from-class="opacity-0 translate-y-2" enter-to-class="opacity-100"
                leave-active-class="duration-200 ease-in" leave-from-class="opacity-100" leave-to-class="opacity-0 translate-y-2"
            >
                <button
                    v-if="toast"
                    class="fixed bottom-5 right-5 z-[100] w-80 text-left rounded-lg bg-primary-950 text-white shadow-modal p-4 flex items-start gap-3"
                    @click="goTo(toast.id)"
                >
                    <span class="h-9 w-9 rounded-full bg-accent-600 flex items-center justify-center shrink-0">
                        <Bell class="h-5 w-5" :stroke-width="1.6" />
                    </span>
                    <span class="min-w-0">
                        <span class="block text-body-sm font-semibold">Rezervim i ri!</span>
                        <span class="block text-body-sm text-neutral-300 truncate">{{ toast.guest }} · Dhoma {{ toast.room }}</span>
                        <span class="block text-tiny text-neutral-400 mt-0.5">{{ toast.check_in }} → {{ toast.check_out }} · €{{ toast.total }}</span>
                    </span>
                </button>
            </Transition>
        </Teleport>
    </div>
</template>
