import { computed } from 'vue';
import { getIntlLocale } from '@/i18n';
import { useCurrency } from '@/composables/useCurrency';

// ONE conversion rule for every report page (Renato's standard): money shows
// in the SELLING currency when it differs from base AND the controller sent
// baseToPricingRate, with the BASE equivalent as a smaller line underneath.
// Symbols and codes come from the tenant's shared currency config and the
// page payload — never hardcoded in a page.
//
// Expects props with: currency (base symbol from the server), baseToPricingRate.
export function useReportCurrency(props) {
    const { baseCode, pricingCode, pricingSymbol } = useCurrency();

    const displayRate = computed(() => {
        const rate = Number(props.baseToPricingRate || 0);

        return rate > 0 && pricingCode.value !== baseCode.value ? rate : null;
    });
    const displaySymbol = computed(() => (displayRate.value ? pricingSymbol.value : props.currency));
    // Base sub-lines only make sense while converting — when the page already
    // shows base there is nothing to duplicate.
    const showBase = computed(() => displayRate.value !== null);

    const format = (value) => Number(value ?? 0).toLocaleString(getIntlLocale(), {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
    const money = (value) => `${displaySymbol.value}${format(Number(value ?? 0) * (displayRate.value ?? 1))}`;
    const moneyBase = (value) => `${props.currency}${format(value)}`;

    return { baseCode, pricingCode, pricingSymbol, displayRate, displaySymbol, showBase, format, money, moneyBase };
}
