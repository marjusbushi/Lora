<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import { Bell } from 'lucide-vue-next';

const open = ref(false);
const count = ref(0);
const items = ref([]);
const toast = ref(null); // { guest, room }
let timer = null;
let toastTimer = null;
let first = true;

const LS_KEY = 'notif_last_seen_reservation_id';

function lastSeen() {
    const v = Number(localStorage.getItem(LS_KEY));
    return Number.isFinite(v) ? v : 0;
}

function ding() {
    try {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return;
        const ctx = new Ctx();
        const notes = [880, 1320];
        notes.forEach((freq, i) => {
            const o = ctx.createOscillator();
            const g = ctx.createGain();
            o.connect(g); g.connect(ctx.destination);
            o.type = 'sine';
            o.frequency.value = freq;
            const t = ctx.currentTime + i * 0.16;
            g.gain.setValueAtTime(0.0001, t);
            g.gain.exponentialRampToValueAtTime(0.18, t + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, t + 0.32);
            o.start(t);
            o.stop(t + 0.34);
        });
    } catch (e) { /* sound blocked until user interacts — fine */ }
}

function showToast(res) {
    toast.value = res;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toast.value = null; }, 8000);
}

async function poll() {
    try {
        const r = await fetch('/pms/notifications/reservations', { headers: { Accept: 'application/json' } });
        if (!r.ok) return;
        const data = await r.json();
        count.value = data.count || 0;
        items.value = data.reservations || [];

        const maxId = items.value.length ? Math.max(...items.value.map((x) => x.id)) : 0;
        if (first) {
            // baseline — don't alert for reservations that already existed
            if (maxId > lastSeen()) localStorage.setItem(LS_KEY, String(maxId));
            first = false;
            return;
        }
        if (maxId > lastSeen()) {
            const fresh = items.value.find((x) => x.id === maxId);
            localStorage.setItem(LS_KEY, String(maxId));
            ding();
            if (fresh) showToast(fresh);
        }
    } catch (e) { /* offline / ignore */ }
}

function goTo(id) {
    open.value = false;
    router.visit(`/pms/reservations/${id}`);
}

onMounted(() => {
    poll();
    timer = setInterval(poll, 20000);
});
onUnmounted(() => {
    clearInterval(timer);
    clearTimeout(toastTimer);
});
</script>

<template>
    <div class="relative">
        <button
            type="button"
            class="relative rounded-md p-2 text-neutral-500 hover:text-neutral-700 hover:bg-neutral-100 transition-colors"
            :aria-label="`Rezervime te reja: ${count}`"
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
            <div class="px-4 py-3 border-b border-neutral-100 flex items-center justify-between">
                <span class="text-body-sm font-medium text-primary-900">Rezervime te reja</span>
                <span class="text-tiny text-neutral-400">{{ count }} ne pritje</span>
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
                    Asnje rezervim i ri.
                </div>
            </div>
            <a href="/pms/reservations" class="block px-4 py-2.5 text-center text-body-sm text-accent-700 hover:bg-neutral-50 border-t border-neutral-100 no-underline">
                Shiko te gjitha rezervimet
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
