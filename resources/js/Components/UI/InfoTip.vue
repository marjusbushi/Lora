<script setup>
import { ref } from 'vue';
import { CircleHelp } from 'lucide-vue-next';

// A small "what is this?" affordance for metric labels. Opens on hover AND on
// focus/click (touch), closes on Escape/blur — keyboard- and phone-friendly.
const props = defineProps({
    text: { type: String, required: true },
    label: { type: String, default: '' },
});

const open = ref(false);
</script>

<template>
    <span class="relative inline-flex" @mouseenter="open = true" @mouseleave="open = false">
        <button
            type="button"
            class="inline-flex h-4 w-4 items-center justify-center rounded-full text-neutral-400 transition hover:text-accent-700 focus:outline-none focus:ring-2 focus:ring-accent-500"
            :aria-label="label || text"
            :aria-expanded="open"
            @click.stop.prevent="open = true"
            @focus="open = true"
            @blur="open = false"
            @keydown.escape="open = false"
        >
            <CircleHelp class="h-3.5 w-3.5" :stroke-width="2" />
        </button>
        <span
            v-if="open"
            role="tooltip"
            class="absolute bottom-full left-1/2 z-30 mb-1.5 w-56 -translate-x-1/2 rounded-lg bg-primary-900 px-3 py-2 text-left text-tiny font-normal normal-case tracking-normal text-white shadow-lg"
        >
            {{ text }}
        </span>
    </span>
</template>
