<script setup>
// Një vend plazhi: çadër me dy shezllone — ikona SVG vizatohet me currentColor,
// ndaj gjendjet (e lirë / e zënë / e zgjedhur) ndryshojnë vetëm me klasa teksti.
defineProps({
    number: { type: String, required: true },
    state: { type: String, default: 'free' }, // free | busy | selected
});

defineEmits(['pick']);
</script>

<template>
    <button
        type="button"
        :disabled="state === 'busy'"
        class="flex w-16 flex-col items-center rounded-xl p-1.5 transition sm:w-20"
        :class="state === 'busy'
            ? 'cursor-not-allowed text-driftwood/40'
            : state === 'selected'
                ? 'bg-brass/15 text-brass ring-2 ring-brass'
                : 'text-ionian hover:bg-ionian/10'"
        :aria-pressed="state === 'selected'"
        @click="$emit('pick')"
    >
        <svg viewBox="0 0 64 44" class="h-9 w-14 sm:h-10 sm:w-16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <!-- Çadra -->
            <path d="M32 4 C 18 4, 10 12, 8 19 L 56 19 C 54 12, 46 4, 32 4 Z" fill="currentColor" fill-opacity="0.25" />
            <path d="M8 19 Q 14 16, 20 19 Q 26 16, 32 19 Q 38 16, 44 19 Q 50 16, 56 19" />
            <line x1="32" y1="4" x2="32" y2="34" />
            <!-- Shezllonet majtas & djathtas -->
            <path d="M4 34 L 12 28 M12 28 L 26 34" />
            <line x1="4" y1="38" x2="26" y2="38" />
            <line x1="6" y1="38" x2="6" y2="42" />
            <line x1="24" y1="38" x2="24" y2="42" />
            <path d="M60 34 L 52 28 M52 28 L 38 34" />
            <line x1="38" y1="38" x2="60" y2="38" />
            <line x1="40" y1="38" x2="40" y2="42" />
            <line x1="58" y1="38" x2="58" y2="42" />
        </svg>
        <span
            class="mt-0.5 text-body-sm font-bold"
            :class="state === 'busy' ? 'line-through' : state === 'selected' ? 'text-brass' : 'text-ink'"
        >{{ number }}</span>
    </button>
</template>
