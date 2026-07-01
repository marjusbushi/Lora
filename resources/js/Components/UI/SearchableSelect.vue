<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';

// A <select> with a real-time search box. v-model contract matches Select.vue
// (modelValue + update:modelValue); options are { value, label }.
const props = defineProps({
    modelValue: { type: [String, Number, null], default: '' },
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Zgjidh...' },
    searchPlaceholder: { type: String, default: 'Kërko…' },
    error: { type: String, default: null },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const open = ref(false);
const q = ref('');
const rootEl = ref(null);
const searchEl = ref(null);

const selected = computed(() => props.options.find((o) => o.value === props.modelValue) || null);
const filtered = computed(() => {
    const s = q.value.trim().toLowerCase();
    if (!s) return props.options;
    return props.options.filter((o) => String(o.label).toLowerCase().includes(s));
});

function toggle() {
    if (props.disabled) return;
    open.value = !open.value;
    if (open.value) {
        q.value = '';
        nextTick(() => searchEl.value?.focus());
    }
}
function pick(o) {
    emit('update:modelValue', o.value);
    open.value = false;
}
function onDocPointer(e) {
    if (rootEl.value && !rootEl.value.contains(e.target)) open.value = false;
}
watch(open, (v) => {
    if (v) document.addEventListener('mousedown', onDocPointer);
    else document.removeEventListener('mousedown', onDocPointer);
});
onBeforeUnmount(() => document.removeEventListener('mousedown', onDocPointer));
</script>

<template>
    <div ref="rootEl" class="relative">
        <button
            type="button"
            :disabled="disabled"
            :class="[
                'flex w-full items-center justify-between rounded-md border px-3 py-2 text-body-sm text-left transition-colors duration-150',
                'focus:outline-none focus:ring-2 focus:ring-offset-0',
                error ? 'border-error-300 focus:border-error-500 focus:ring-error-500/40' : 'border-neutral-200 focus:border-accent-500 focus:ring-accent-500/40',
                disabled && 'bg-neutral-100 cursor-not-allowed opacity-60',
            ]"
            @click="toggle"
        >
            <span :class="selected ? 'text-neutral-900 truncate' : 'text-neutral-400 truncate'">{{ selected?.label || placeholder }}</span>
            <svg class="h-4 w-4 text-neutral-400 shrink-0 ml-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
            </svg>
        </button>

        <div v-if="open" class="absolute z-50 mt-1 w-full rounded-md border border-neutral-200 bg-white shadow-lg">
            <div class="p-2 border-b border-neutral-100">
                <input
                    ref="searchEl"
                    v-model="q"
                    type="text"
                    :placeholder="searchPlaceholder"
                    class="w-full rounded-md border border-neutral-200 px-2.5 py-1.5 text-body-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-accent-500/40 focus:border-accent-500"
                    @keydown.esc.prevent="open = false"
                />
            </div>
            <div class="max-h-56 overflow-y-auto py-1">
                <button
                    v-for="o in filtered"
                    :key="o.value"
                    type="button"
                    :class="[
                        'block w-full text-left px-3 py-1.5 text-body-sm transition-colors hover:bg-accent-50',
                        o.value === modelValue ? 'text-accent-700 font-medium bg-accent-50/60' : 'text-neutral-700',
                    ]"
                    @click="pick(o)"
                >
                    {{ o.label }}
                </button>
                <p v-if="!filtered.length" class="px-3 py-2 text-small text-neutral-400">Asnjë rezultat.</p>
            </div>
        </div>
    </div>
</template>
