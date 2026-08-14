<script setup>
import { onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Loader2, MapPin } from 'lucide-vue-next';

// The device's sales-outlet picker (Restorant / Bar / Beach Bar). The pick is
// remembered per device (localStorage) and sent to the server (?outlet=...),
// which re-filters the menu and tables. Renders nothing when the property has
// no outlets — single-POS behaviour stays untouched.
const props = defineProps({
    outlets: { type: Array, default: () => [] },
    currentOutletId: { type: Number, default: null },
    dense: { type: Boolean, default: false },
    // Non-empty = ask before a MANUAL switch (e.g. items in the cart would be
    // lost); the silent localStorage restore on load never needs it.
    confirmBeforeSwitch: { type: String, default: '' },
});

const STORAGE_KEY = 'pos.outlet_id';
const switchingTo = ref(null);

function switchTo(outletId, options = {}) {
    if (switchingTo.value || outletId === props.currentOutletId) return;
    if (!options.replace && props.confirmBeforeSwitch && !window.confirm(props.confirmBeforeSwitch)) return;
    switchingTo.value = outletId;
    try { localStorage.setItem(STORAGE_KEY, String(outletId)); } catch { /* private mode */ }
    // Keep the rest of the query string (order_id/action/table…) — the switch
    // changes the outlet, not where the user stands in a flow.
    const query = Object.fromEntries(new URLSearchParams(window.location.search));
    router.get(window.location.pathname, { ...query, outlet: outletId }, {
        preserveScroll: true,
        replace: Boolean(options.replace),
        onFinish: () => { switchingTo.value = null; },
    });
}

onMounted(() => {
    if (!props.outlets.length) return;
    let stored = null;
    try { stored = Number(localStorage.getItem(STORAGE_KEY)) || null; } catch { /* private mode */ }
    if (stored && stored !== props.currentOutletId && props.outlets.some((outlet) => outlet.id === stored)) {
        // The device remembers its outlet — follow it silently on load.
        switchTo(stored, { replace: true });
    } else if (props.currentOutletId) {
        try { localStorage.setItem(STORAGE_KEY, String(props.currentOutletId)); } catch { /* private mode */ }
    }
});

watch(() => props.currentOutletId, (outletId) => {
    if (!outletId) return;
    try { localStorage.setItem(STORAGE_KEY, String(outletId)); } catch { /* private mode */ }
});
</script>

<template>
    <div
        v-if="outlets.length"
        :class="dense
            ? 'inline-flex h-12 shrink-0 items-center gap-1.5 rounded-lg border border-neutral-200 bg-white px-2 whitespace-nowrap'
            : 'rounded-xl border border-neutral-200 bg-white px-3 py-2 shadow-card'"
    >
        <p
            class="flex items-center gap-1 text-tiny font-semibold uppercase tracking-wide text-neutral-400"
            :class="dense && 'mr-0.5'"
        >
            <MapPin class="h-3.5 w-3.5" />
            <span v-if="!dense">{{ $t('posOutlets.label') }}</span>
        </p>
        <div class="flex flex-wrap items-center gap-1.5" :class="!dense && 'mt-1'">
            <button
                v-for="outlet in outlets"
                :key="outlet.id"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-body-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-accent-500/30"
                :class="outlet.id === currentOutletId
                    ? 'bg-accent-600 text-white shadow'
                    : 'bg-neutral-100 text-neutral-600 hover:bg-accent-50 hover:text-accent-700'"
                :disabled="Boolean(switchingTo)"
                :aria-pressed="outlet.id === currentOutletId"
                @click="switchTo(outlet.id)"
            >
                <Loader2 v-if="switchingTo === outlet.id" class="h-3.5 w-3.5 animate-spin" />
                {{ outlet.name }}
            </button>
        </div>
    </div>
</template>
