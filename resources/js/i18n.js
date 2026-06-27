import { createI18n } from 'vue-i18n';
import sq from './locales/sq.json';
import en from './locales/en.json';

const SUPPORTED = ['sq', 'en'];
const DEFAULT = 'sq';

function initialLocale() {
    try {
        const stored = localStorage.getItem('locale');
        if (stored && SUPPORTED.includes(stored)) return stored;
    } catch (e) { /* ignore */ }
    return DEFAULT;
}

const locale = initialLocale();
if (typeof document !== 'undefined') document.documentElement.lang = locale;

export const i18n = createI18n({
    legacy: false,
    globalInjection: true, // enables $t in templates
    locale,
    fallbackLocale: DEFAULT,
    messages: { sq, en },
});

export function setLocale(next) {
    if (!SUPPORTED.includes(next)) return;
    i18n.global.locale.value = next;
    try { localStorage.setItem('locale', next); } catch (e) { /* ignore */ }
    if (typeof document !== 'undefined') document.documentElement.lang = next;
}
