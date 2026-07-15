<script setup>
import QRCode from 'qrcode';
import { ref, watch } from 'vue';

const props = defineProps({
    value: { type: String, default: '' },
    size: { type: Number, default: 116 },
});

const source = ref('');

watch(
    () => [props.value, props.size],
    async ([value, size]) => {
        if (!value) {
            source.value = '';
            return;
        }

        try {
            source.value = await QRCode.toDataURL(value, {
                errorCorrectionLevel: 'M',
                margin: 0,
                width: size,
                color: { dark: '#111827', light: '#ffffff' },
            });
        } catch {
            source.value = '';
        }
    },
    { immediate: true },
);
</script>

<template>
    <img v-if="source" :src="source" alt="QR verifikimi i faturës" :width="size" :height="size" />
</template>
