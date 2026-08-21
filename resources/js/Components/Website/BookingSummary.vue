<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Check } from 'lucide-vue-next';

// The CART sidebar (Booking.com model): one line per chosen typology × quantity.
// Amounts are in the hotel's PRICING currency (lesson #148 — never the base/accounting one).
defineProps({
    lines: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({ smart: 0, discount: 0, total: 0, discountPct: 0 }) },
    roomsCount: { type: Number, default: 0 },
    search: Object,
    nights: Number,
    dateLabel: Function,
    money: Function,
});
const currencySymbol = computed(() => usePage().props.settings?.pricing_currency_symbol || '€');
</script>

<template>
    <aside class="sticky top-24 overflow-hidden rounded-2xl border border-driftwood/20 bg-white shadow-sm">
        <div class="bg-ionian p-6 text-bone">
            <p class="font-serif text-3xl">{{ $t('book.direct.yourStay') }}</p>
            <p class="mt-3 text-body-sm text-bone/80">{{ dateLabel(search.check_in) }} → {{ dateLabel(search.check_out) }}</p>
            <p class="mt-1 text-tiny text-bone/65">{{ nights || 1 }} {{ $t('book.rooms.nights') }} · {{ Number(search.adults) + Number(search.children) }} {{ $t('book.rooms.persons') }}</p>
        </div>
        <div class="p-6">
            <template v-if="lines.length">
                <p class="text-tiny font-semibold uppercase tracking-wider text-ink/40">{{ $t('book.cart.heading') }}</p>
                <ul class="mt-2 space-y-1.5">
                    <li v-for="line in lines" :key="line.room_type_id" class="flex items-baseline justify-between gap-4">
                        <span class="font-serif text-lg text-ink">{{ $t('book.qty.inCart', { qty: line.qty, name: line.room_type }) }}</span>
                        <span class="whitespace-nowrap text-body-sm text-ink/60">{{ currencySymbol }}{{ money(line.qty * Number(line.total_price)) }}</span>
                    </li>
                </ul>
                <div class="mt-5 space-y-3 border-y border-driftwood/15 py-5 text-body-sm">
                    <div class="flex justify-between gap-4 text-ink/60"><span>{{ $t('book.direct.smartSubtotal') }}</span><span>{{ currencySymbol }}{{ money(totals.smart) }}</span></div>
                    <div v-if="Number(totals.discount) > 0" class="flex justify-between gap-4 font-medium text-success-700"><span>{{ $t('book.direct.discount', { pct: totals.discountPct }) }}</span><span>-{{ currencySymbol }}{{ money(totals.discount) }}</span></div>
                </div>
                <div class="flex items-end justify-between gap-4 pt-5"><span class="font-serif text-xl text-ink">{{ $t('book.direct.total') }}</span><span class="font-serif text-3xl text-brass">{{ currencySymbol }}{{ money(totals.total) }}</span></div>
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
