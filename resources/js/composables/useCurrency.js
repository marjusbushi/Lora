import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

// The tenant's TWO currencies, shared on every Inertia page by the backend
// (HandleInertiaRequests / BaseCurrency / PricingCurrency):
//
// - BASE (functional): what operational amounts are stored in — POS menu
//   prices, inventory costs, finance. A Lek hotel's Cola costs L200.
// - PRICING (commercial): what room rates and reservation totals mean —
//   Smart Pricing, web, OTA. A Lek hotel may still sell rooms in €.
//
// Pick the one matching the money you are rendering; there is no single
// "currency symbol" — that assumption is exactly what painted € on Lek
// prices at Saturn.
export function useCurrency() {
    const page = usePage();

    // Read the DEDICATED shared key, never `settings`: pages like Settings
    // pass their own `settings` prop which shadows the shared one in Inertia,
    // and the lookup would silently fall back to € (the Saturn POS-menu bug).
    const currencies = computed(() => page.props.currencies || {});

    const baseCode = computed(() => currencies.value.base_code || 'EUR');
    const baseSymbol = computed(() => currencies.value.base_symbol || '€');
    const pricingCode = computed(() => currencies.value.pricing_code || baseCode.value);
    const pricingSymbol = computed(() => currencies.value.pricing_symbol || baseSymbol.value);

    return { baseCode, baseSymbol, pricingCode, pricingSymbol };
}
