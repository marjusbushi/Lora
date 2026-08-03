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

    const baseCode = computed(() => page.props.settings?.currency || 'EUR');
    const baseSymbol = computed(() => page.props.settings?.currency_symbol || '€');
    const pricingCode = computed(() => page.props.settings?.pricing_currency || baseCode.value);
    const pricingSymbol = computed(() => page.props.settings?.pricing_currency_symbol || baseSymbol.value);

    return { baseCode, baseSymbol, pricingCode, pricingSymbol };
}
