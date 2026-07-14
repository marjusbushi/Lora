import { createI18n } from 'vue-i18n';
import sq from './locales/sq.json';
import en from './locales/en.json';
import marketingSq from './locales/marketing-sq.json';
import marketingEn from './locales/marketing-en.json';

const SUPPORTED = ['sq', 'en'];
const DEFAULT = 'sq';

function initialLocale() {
    try {
        const stored = localStorage.getItem('locale');
        if (stored && SUPPORTED.includes(stored)) return stored;
    } catch (e) { /* ignore */ }

    if (typeof document !== 'undefined') {
        const cookieLocale = document.cookie
            .split('; ')
            .find((entry) => entry.startsWith('locale='))
            ?.split('=')[1];
        if (cookieLocale && SUPPORTED.includes(cookieLocale)) return cookieLocale;
    }

    return DEFAULT;
}

const locale = initialLocale();
if (typeof document !== 'undefined') document.documentElement.lang = locale;

export const i18n = createI18n({
    legacy: false,
    globalInjection: true, // enables $t in templates
    locale,
    fallbackLocale: DEFAULT,
    messages: {
        sq: { ...sq, marketing: marketingSq },
        en: { ...en, marketing: marketingEn },
    },
});

export function setLocale(next) {
    if (!SUPPORTED.includes(next)) return;
    const changed = i18n.global.locale.value !== next;
    i18n.global.locale.value = next;
    try { localStorage.setItem('locale', next); } catch (e) { /* ignore */ }
    if (typeof document !== 'undefined') {
        document.cookie = `locale=${next}; path=/; max-age=31536000; SameSite=Lax`;
        document.documentElement.lang = next;
    }
}

/** Translate outside Vue templates and setup hooks. */
export function translate(key, params = {}) {
    return i18n.global.t(key, params);
}

/** Return a browser Intl-compatible locale for the active UI language. */
export function getIntlLocale() {
    return i18n.global.locale.value === 'en' ? 'en-GB' : 'sq-AL';
}
