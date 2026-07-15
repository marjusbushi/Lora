<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Check } from 'lucide-vue-next';

defineProps({
    room: Object,
    search: Object,
    nights: Number,
    dateLabel: Function,
    money: Function,
});
const currencySymbol = computed(() => usePage().props.settings?.currency_symbol || '€');
</script>

<template>
    <aside class="sticky top-24 overflow-hidden rounded-2xl border border-driftwood/20 bg-white shadow-sm">
        <div class="bg-ionian p-6 text-bone">
            <p class="font-serif text-3xl">{{ $t('book.direct.yourStay') }}</p>
            <p class="mt-3 text-body-sm text-bone/80">{{ dateLabel(search.check_in) }} → {{ dateLabel(search.check_out) }}</p>
            <p class="mt-1 text-tiny text-bone/65">{{ nights || 1 }} {{ $t('book.rooms.nights') }} · {{ Number(search.adults) + Number(search.children) }} {{ $t('book.rooms.persons') }}</p>
        </div>
        <div class="p-6">
            <template v-if="room">
                <p class="text-tiny font-semibold uppercase tracking-wider text-ink/40">{{ $t('book.direct.selectedRoom') }}</p>
                <p class="mt-1 font-serif text-xl text-ink">{{ room.room_type }}</p>
                <div class="mt-5 space-y-3 border-y border-driftwood/15 py-5 text-body-sm">
                    <div class="flex justify-between gap-4 text-ink/60"><span>{{ $t('book.direct.smartSubtotal') }}</span><span>{{ currencySymbol }}{{ money(room.smart_total_price) }}</span></div>
                    <div v-if="Number(room.direct_discount_amount) > 0" class="flex justify-between gap-4 font-medium text-success-700"><span>{{ $t('book.direct.discount', { pct: room.direct_discount_pct }) }}</span><span>-{{ currencySymbol }}{{ money(room.direct_discount_amount) }}</span></div>
                </div>
                <div class="flex items-end justify-between gap-4 pt-5"><span class="font-serif text-xl text-ink">{{ $t('book.direct.total') }}</span><span class="font-serif text-3xl text-brass">{{ currencySymbol }}{{ money(room.total_price) }}</span></div>
                <p class="mt-1 text-right text-tiny text-ink/40">{{ $t('book.direct.taxesIncluded') }}</p>
            </template>
            <p v-else class="text-body-sm text-ink/50">{{ $t('book.direct.chooseRoomSummary') }}</p>
            <div class="mt-5 flex gap-3 rounded-xl bg-success-50 p-4 text-success-800">
                <Check class="mt-0.5 h-5 w-5 shrink-0" />
                <div><p class="text-body-sm font-semibold">{{ $t('book.direct.bestPrice') }}</p><p class="mt-0.5 text-tiny text-success-700">{{ $t('book.direct.noHiddenFees') }}</p></div>
            </div>
        </div>
    </aside>
</template>
