<script setup>
import Button from '@/Components/UI/Button.vue';

const props = defineProps({
    shift: { type: Object, default: null },
    canOpen: { type: Boolean, default: false },
    canClose: { type: Boolean, default: false },
    currency: { type: String, default: 'EUR' },
});

defineEmits(['open', 'close']);

function money(v) {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: props.currency }).format(Number(v ?? 0));
}
</script>

<template>
    <!-- OPEN: green strip with live drawer chips -->
    <div
        v-if="shift"
        class="rounded-xl border border-success-200 bg-gradient-to-r from-success-50 to-white px-4 py-3 flex flex-col sm:flex-row sm:items-center gap-3"
    >
        <div class="flex items-center gap-2 shrink-0">
            <span class="h-2.5 w-2.5 rounded-full bg-success-500 animate-pulse"></span>
            <span class="text-body-sm font-semibold text-success-800">
{{ $t('admin.generated.k_6be355fbed8c') }} {{ shift.user_name }}
            </span>
            <span class="text-small text-neutral-500">⏱️ {{ shift.opened_at }}</span>
        </div>

        <!-- live money chips -->
        <div class="flex flex-wrap items-center gap-2 sm:ml-auto">
            <span class="px-2.5 py-1 rounded-lg bg-success-100 text-success-800 text-small font-medium">{{ $t('admin.generated.k_e27360174a29') }} {{ money(shift.cash_sales) }}</span>
            <span class="px-2.5 py-1 rounded-lg bg-info-100 text-info-800 text-small font-medium">{{ $t('admin.generated.k_fb9a941a0ba9') }} {{ money(shift.card_sales) }}</span>
            <span class="px-2.5 py-1 rounded-lg bg-warning-100 text-warning-800 text-small font-medium">{{ $t('admin.generated.k_207f7bc282f0') }} {{ money(shift.room_charge_sales) }}</span>
            <span class="px-2.5 py-1 rounded-lg bg-neutral-100 text-neutral-600 text-small font-medium">🧾 {{ shift.completed_orders }} {{ $t('admin.generated.k_6adb47292535') }}</span>
            <Button v-if="canClose" variant="outline" size="sm" class="!border-error-300 !text-error-600 hover:!bg-error-50" @click="$emit('close')">
{{ $t('admin.generated.k_7ec8269c393b') }} </Button>
        </div>
    </div>

    <!-- CLOSED: amber call-to-action -->
    <div
        v-else
        class="rounded-xl border border-warning-200 bg-gradient-to-r from-warning-50 to-white px-4 py-3 flex flex-col sm:flex-row sm:items-center gap-3"
    >
        <div class="flex items-center gap-2">
            <span class="h-2.5 w-2.5 rounded-full bg-error-400"></span>
            <span class="text-body-sm font-semibold text-warning-900">{{ $t('admin.generated.k_914a1a881835') }}</span>
            <span class="text-small text-neutral-500">{{ $t('admin.generated.k_f86ba575d9be') }}</span>
        </div>
        <div class="sm:ml-auto">
            <Button v-if="canOpen" variant="primary" size="sm" @click="$emit('open')">{{ $t('admin.generated.k_9353bb1634a5') }}</Button>
            <span v-else class="text-small text-neutral-400">{{ $t('admin.generated.k_dafbd9e90f26') }}</span>
        </div>
    </div>
</template>
