<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { CircleHelp } from 'lucide-vue-next';

// A small "what is this?" affordance for metric labels. Opens on hover AND on
// focus/click (touch), closes on blur/Escape/scroll — keyboard- and
// phone-friendly. The panel TELEPORTS to <body> with fixed positioning so an
// overflow-x-auto table wrapper can never clip it (the "black pill" bug).
const props = defineProps({
    text: { type: String, required: true },
    label: { type: String, default: '' },
});

const PANEL_WIDTH = 224; // matches w-56
const open = ref(false);
const trigger = ref(null);
const panelStyle = ref({});

const place = () => {
    const rect = trigger.value?.getBoundingClientRect();
    if (!rect) {
        return;
    }
    const left = Math.min(
        Math.max(8, rect.left + rect.width / 2 - PANEL_WIDTH / 2),
        window.innerWidth - PANEL_WIDTH - 8,
    );
    // Above the trigger via CSS `bottom` (no height measuring needed); flip
    // below when the trigger sits too close to the viewport top.
    panelStyle.value = rect.top > 120
        ? { left: `${left}px`, bottom: `${window.innerHeight - rect.top + 6}px` }
        : { left: `${left}px`, top: `${rect.bottom + 6}px` };
};

// Any scroll would detach the fixed panel from its trigger — just close.
const closeOnMove = () => hide();

const show = () => {
    if (open.value) {
        return;
    }
    place();
    open.value = true;
    window.addEventListener('scroll', closeOnMove, { capture: true, passive: true });
    window.addEventListener('resize', closeOnMove, { passive: true });
};

const hide = () => {
    open.value = false;
    window.removeEventListener('scroll', closeOnMove, { capture: true });
    window.removeEventListener('resize', closeOnMove);
};

onBeforeUnmount(hide);
</script>

<template>
    <span class="relative inline-flex" @mouseenter="show" @mouseleave="hide">
        <button
            ref="trigger"
            type="button"
            class="inline-flex h-4 w-4 items-center justify-center rounded-full text-neutral-400 transition hover:text-accent-700 focus:outline-none focus:ring-2 focus:ring-accent-500"
            :aria-label="label || text"
            :aria-expanded="open"
            @click.stop.prevent="show"
            @focus="show"
            @blur="hide"
            @keydown.escape="hide"
        >
            <CircleHelp class="h-3.5 w-3.5" :stroke-width="2" />
        </button>
        <Teleport to="body">
            <span
                v-if="open"
                role="tooltip"
                class="fixed z-50 w-56 rounded-lg bg-primary-900 px-3 py-2 text-left text-tiny font-normal normal-case tracking-normal text-white shadow-lg"
                :style="panelStyle"
            >
                {{ text }}
            </span>
        </Teleport>
    </span>
</template>
