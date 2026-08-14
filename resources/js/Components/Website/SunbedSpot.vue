<script>
// Numërues në nivel moduli: çdo instancë merr id unik për clipPath-in e kupolës
// (id-të e SVG-së janë globale në faqe — 28+ çadra pa këtë do përplaseshin).
let uid = 0;
</script>

<script setup>
import { computed } from 'vue';

// Një vend plazhi: çadër e mbushur me panele të alternuara + dy shezllone dhe hije.
// Gjendjet (e lirë / e zënë / e zgjedhur) ndërrojnë paletën fill/stroke të SVG-së.
const props = defineProps({
    number: { type: String, required: true },
    state: { type: String, default: 'free' }, // free | busy | selected
});

defineEmits(['pick']);

const clipId = `sunbed-dome-${++uid}`;

// Kupola: hark i butë me buzë të valëzuar (6 scallops nga x=8 deri x=56).
const dome = 'M32 7 C 20 7, 10 15, 8 26 Q 12 22.5 16 26 Q 20 22.5 24 26 Q 28 22.5 32 26 Q 36 22.5 40 26 Q 44 22.5 48 26 Q 52 22.5 56 26 C 54 15, 44 7, 32 7 Z';

const palettes = {
    free: {
        canopy: 'fill-ionian',
        stripe: 'fill-bone/90',
        edge: 'stroke-ionian-dark/50',
        pole: 'stroke-ionian-dark',
        finial: 'fill-ionian-dark',
        frame: 'stroke-driftwood',
        seat: 'fill-driftwood/70',
    },
    selected: {
        canopy: 'fill-brass',
        stripe: 'fill-bone',
        edge: 'stroke-brass-dark/60',
        pole: 'stroke-brass-dark',
        finial: 'fill-brass-dark',
        frame: 'stroke-driftwood',
        seat: 'fill-driftwood/70',
    },
    busy: {
        canopy: 'fill-driftwood/25',
        stripe: 'fill-white/60',
        edge: 'stroke-driftwood/30',
        pole: 'stroke-driftwood/40',
        finial: 'fill-driftwood/40',
        frame: 'stroke-driftwood/35',
        seat: 'fill-driftwood/25',
    },
};
const p = computed(() => palettes[props.state] ?? palettes.free);
</script>

<template>
    <button
        type="button"
        :disabled="state === 'busy'"
        class="group flex w-16 flex-col items-center rounded-xl p-1.5 transition-all duration-200 sm:w-20"
        :class="state === 'busy'
            ? 'cursor-not-allowed'
            : state === 'selected'
                ? 'bg-brass/10 ring-2 ring-brass'
                : 'hover:-translate-y-0.5 hover:bg-white/60'"
        :aria-pressed="state === 'selected'"
        @click="$emit('pick')"
    >
        <svg viewBox="0 0 64 56" class="h-10 w-14 sm:h-11 sm:w-16" aria-hidden="true">
            <!-- Hija në rërë -->
            <ellipse v-if="state !== 'busy'" cx="32" cy="51.5" rx="20" ry="2.8" class="fill-ink/10" />

            <!-- Shezllonet: mbështetësja e pjerrët nga jashtë, ndenjësja, këmbët -->
            <g :class="p.frame" fill="none" stroke-linecap="round">
                <path d="M6 38.5 L12.5 31.5" stroke-width="2.4" />
                <path d="M58 38.5 L51.5 31.5" stroke-width="2.4" />
                <path d="M8 42.5 L8 47 M23.5 42.5 L23.5 47 M40.5 42.5 L40.5 47 M56 42.5 L56 47" stroke-width="1.8" />
            </g>
            <rect x="5" y="38.5" width="21.5" height="3.4" rx="1.7" :class="p.seat" />
            <rect x="37.5" y="38.5" width="21.5" height="3.4" rx="1.7" :class="p.seat" />

            <!-- Shtiza dhe maja -->
            <line x1="32" y1="7" x2="32" y2="49.5" stroke-width="2" stroke-linecap="round" :class="p.pole" />
            <circle cx="32" cy="5" r="2" :class="p.finial" />

            <!-- Kupola: bazë + panele të çelëta të prera nga forma e kupolës -->
            <clipPath :id="clipId"><path :d="dome" /></clipPath>
            <path :d="dome" :class="p.canopy" />
            <g :clip-path="`url(#${clipId})`" :class="p.stripe">
                <path d="M32 7 L16 28 L24 28 Z" />
                <path d="M32 7 L32 28 L40 28 Z" />
                <path d="M32 7 L48 28 L56 28 Z" />
            </g>
            <path :d="dome" fill="none" stroke-width="1.4" stroke-linejoin="round" :class="p.edge" />
        </svg>
        <span
            class="mt-0.5 text-body-sm font-bold"
            :class="state === 'busy' ? 'text-driftwood/50 line-through' : state === 'selected' ? 'text-brass-dark' : 'text-ink'"
        >{{ number }}</span>
    </button>
</template>
