import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

// The tenant's BASE currency, shared on every Inertia page by the backend
// (BaseCurrency::symbol via HandleInertiaRequests). Use THIS instead of a
// hardcoded '€' or 'EUR' anywhere a price label or amount is rendered —
// a hotel operating in ALL must never see euro signs on its own money.
export function useCurrency() {
    const page = usePage();
    const code = computed(() => page.props.settings?.pricing_currency || 'EUR');
    const symbol = computed(() => page.props.settings?.pricing_currency_symbol || '€');

    return { code, symbol };
}
