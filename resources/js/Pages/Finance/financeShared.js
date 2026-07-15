/** Shared display helpers for the Finance screens. */
export function money(v, currency = 'EUR') {
    return new Intl.NumberFormat('de-DE', {
        style: 'currency',
        currency: currency || 'EUR',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(v || 0));
}

export const sourceLabels = { auto: 'auto', manual: 'manuale' };

export function sourceBadge(p) {
    if (p.source !== 'auto') return { text: 'manuale', cls: 'bg-neutral-100 text-neutral-500' };
    const desc = (p.description || '').toLowerCase();
    if (desc.includes('folio')) return { text: 'auto · Folio', cls: 'bg-info-50 text-info-700' };
    if (desc.includes('pos')) return { text: 'auto · POS', cls: 'bg-info-50 text-info-700' };
    return { text: 'auto', cls: 'bg-info-50 text-info-700' };
}
