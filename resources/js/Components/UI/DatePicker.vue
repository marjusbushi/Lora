<script setup>
import { translate } from '@/i18n';
import { computed } from 'vue';
import { VueDatePicker } from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';

// Drop-in replacement for <input type="date">: v-model stays a 'YYYY-MM-DD'
// string (in AND out), so every form/validation/back-end contract is unchanged.
const props = defineProps({
    modelValue: { type: String, default: '' },
    min: { type: String, default: '' },
    max: { type: String, default: '' },
    placeholder: { type: String, default: 'dd/mm/vvvv' },
    disabled: { type: Boolean, default: false },
    error: { type: String, default: '' },
    inputAttrs: { type: Object, default: () => ({}) },
    ariaLabel: { type: String, default: translate('admin.generated.k_63d1bbbb8ca7') },
});
const emit = defineEmits(['update:modelValue']);

// Parse/format as LOCAL date parts — never toISOString() (UTC off-by-one).
function toDate(str) {
    if (!str || typeof str !== 'string') return null;
    const [y, m, d] = str.split('-').map(Number);
    if (!y || !m || !d) return null;
    return new Date(y, m - 1, d);
}
function toStr(date) {
    const d = Array.isArray(date) ? date[0] : date;
    if (!(d instanceof Date) || isNaN(d)) return '';
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${dd}`;
}

const inner = computed({
    get: () => toDate(props.modelValue),
    set: (v) => emit('update:modelValue', toStr(v)),
});
const minDate = computed(() => toDate(props.min) || undefined);
const maxDate = computed(() => toDate(props.max) || undefined);
const resolvedInputAttrs = computed(() => ({ ...props.inputAttrs }));
const ariaLabels = computed(() => ({ input: props.ariaLabel }));
</script>

<template>
    <!-- @vuepic v14 grouped the old flat props: enable-time-picker lives in
         time-config, the input format in formats, and the popup is positioned
         by floating-ui via `floating` — the old flat names are silently
         IGNORED (that regression shipped datetime pickers app-wide). -->
    <VueDatePicker
        v-model="inner"
        :time-config="{ enableTimePicker: false }"
        :formats="{ input: 'dd/MM/yyyy' }"
        :floating="{ placement: 'bottom-start' }"
        :min-date="minDate"
        :max-date="maxDate"
        :placeholder="placeholder"
        :input-attrs="resolvedInputAttrs"
        :aria-labels="ariaLabels"
        :disabled="disabled"
        :auto-apply="true"
        :teleport="true"
        :six-weeks="'append'"
        week-start="1"
        :class="{ 'dp-has-error': !!error }"
    />
</template>

<!-- Global (not scoped) so the teleported calendar popup is themed too. -->
<style>
:root {
    --dp-font-family: inherit;
    --dp-border-radius: 10px;
    --dp-cell-border-radius: 8px;
    --dp-cell-size: 36px;
    --dp-menu-min-width: 270px;
    --dp-primary-color: #2d6a4f;
    --dp-primary-text-color: #ffffff;
    --dp-secondary-color: #8a8f98;
    --dp-text-color: #1f2937;
    --dp-border-color: #d8dce1;
    --dp-menu-border-color: #e5e7eb;
    --dp-border-color-hover: #2d6a4f;
    --dp-hover-color: #f0fdf6;
    --dp-hover-text-color: #143328;
    --dp-icon-color: #9aa0a8;
    --dp-background-color: #ffffff;
    --dp-disabled-color: #f3f4f6;
}

/* Make the trigger input match the app's TextInput look. */
.dp__input {
    border-radius: 0.5rem;
    border-color: #d8dce1;
    padding-top: 0.6rem;
    padding-bottom: 0.6rem;
    font-size: 0.875rem;
    color: #1f2937;
    min-height: 42px;
}
.dp__input::placeholder { color: #aab0b8; }
.dp__input:hover { border-color: #b9bec5; }
.dp__input_focus {
    border-color: #2d6a4f;
    box-shadow: 0 0 0 2px rgba(45, 106, 79, 0.22);
}
.dp__input_icon { color: #9aa0a8; }
.dp-has-error .dp__input { border-color: #dc2626; }
.dp__menu { box-shadow: 0 12px 30px -8px rgba(31, 29, 26, 0.18); }

/* Public site (Ionian Calm): warmer teal/brass accent, calmer surface. */
.site .dp__input { border-radius: 0; }
.site {
    --dp-primary-color: #2E6E72;
    --dp-border-color-hover: #2E6E72;
    --dp-hover-color: #EFE9DE;
    --dp-hover-text-color: #1F1D1A;
    --dp-text-color: #1F1D1A;
}
</style>
