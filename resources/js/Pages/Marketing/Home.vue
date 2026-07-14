<script setup>
import { Head, Link } from '@inertiajs/vue3';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import {
    ArrowRight,
    BarChart3,
    Building2,
    CalendarDays,
    Check,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Globe2,
    Hotel,
    Menu,
    Minus,
    Plus,
    Rocket,
    ShieldCheck,
    Sparkles,
    Store,
    UsersRound,
    UtensilsCrossed,
    WalletCards,
    X,
    Zap,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t, tm, locale } = useI18n();

const demoMail = computed(() => `mailto:hello@lorapms.com?subject=${encodeURIComponent(`${t('marketing.common.bookDemo')} — Lora PMS`)}`);

const mobileOpen = ref(false);
const annualBilling = ref(false);
const rooms = ref(10);
const posPoints = ref(1);
const carouselReverse = ref(false);
const activeProduct = ref('reservations');
const openFaq = ref(0);

const modules = ref({
    channel: true,
    booking: true,
    housekeeping: true,
    pos: true,
    smartPricing: true,
    finance: true,
});

const navigation = computed(() => [
    { label: t('marketing.nav.features'), href: '#funksionet' },
    { label: t('marketing.nav.integrations'), href: '#integrimet' },
    { label: t('marketing.nav.pricing'), href: '#cmimet' },
    { label: t('marketing.nav.hotels'), href: '#per-hotelet' },
]);

const otaChannels = [
    { name: 'Booking.com', mark: 'B.', color: '#003b95' },
    { name: 'Expedia', mark: '↗', color: '#f6b800' },
    { name: 'Airbnb', mark: 'A', color: '#ff385c' },
    { name: 'Agoda', mark: '●', color: '#7b4ce2' },
    { name: 'Hotels.com', mark: 'H', color: '#d71920' },
    { name: 'Vrbo', mark: 'V', color: '#245abc' },
    { name: 'Trip.com', mark: 'T', color: '#287dfa' },
    { name: 'Hostelworld', mark: 'HW', color: '#f25621' },
    { name: 'Google Hotel Ads', mark: 'G', color: '#4285f4' },
    { name: 'Tripadvisor', mark: '◎', color: '#00aa6c' },
];

const featureIcons = [Rocket, CalendarDays, UsersRound, BarChart3];
const featureTones = ['emerald', 'sand', 'mint', 'blue'];
const featureGroups = computed(() => {
    locale.value;
    return tm('marketing.features.groups').map((group, index) => ({
        ...group,
        icon: featureIcons[index],
        tone: featureTones[index],
    }));
});

const pricingCards = computed(() => {
    const cards = tm('marketing.pricing.cards');
    return [
        { ...cards.core, monthlyPrice: 29, icon: ShieldCheck },
        { ...cards.channel, monthlyPrice: 7, icon: Zap },
        { ...cards.booking, displayPrice: '1%', icon: Globe2 },
        { ...cards.housekeeping, monthlyPrice: 9, icon: UsersRound },
        { ...cards.pos, monthlyPrice: 19, icon: Store },
        { ...cards.smart, monthlyPrice: 19, icon: Sparkles },
        { ...cards.finance, monthlyPrice: 19, icon: WalletCards },
    ];
});

const productTabs = computed(() => ['reservations', 'housekeeping', 'pos', 'pricing'].map((key) => ({
    key,
    label: t(`marketing.product.tabs.${key}`),
})));
const productCopy = computed(() => tm('marketing.product.copy'));
const faqs = computed(() => {
    locale.value;
    return tm('marketing.faq.items');
});

const dashboardNav = computed(() => {
    locale.value;
    return tm('marketing.dashboard.nav');
});
const dashboardMetrics = computed(() => tm('marketing.dashboard.metrics').map((label, index) => ({
    label,
    value: ['72%', '14', '16 / 28', '€2,845'][index],
    change: ['+8%', '+2', '57%', '+12%'][index],
})));
const arrivals = computed(() => tm('marketing.dashboard.roomTypes').map((roomType, index) => ({
    time: ['15:00', '15:30', '16:00'][index],
    guest: ['Luca Rossi', 'Ana Petrović', 'Marko Jovanović'][index],
    roomType,
})));
const reservationRows = computed(() => [
    ['#12541', 'Luca Rossi', '204', `24 ${t('marketing.product.may')}`, `27 ${t('marketing.product.may')}`, t('marketing.product.confirmed'), 'Booking.com', '€540'],
    ['#12540', 'Ana Petrović', '302', `24 ${t('marketing.product.may')}`, `25 ${t('marketing.product.may')}`, t('marketing.product.confirmed'), t('marketing.product.website'), '€210'],
    ['#12539', 'Marko Jovanović', '201', `24 ${t('marketing.product.may')}`, `26 ${t('marketing.product.may')}`, 'Check-in', 'Expedia', '€330'],
    ['#12537', 'James Smith', '404', `24 ${t('marketing.product.may')}`, `28 ${t('marketing.product.may')}`, t('marketing.product.confirmed'), 'Booking.com', '€720'],
]);
const housekeepingRooms = computed(() => tm('marketing.product.housekeepingRooms').map((room, index) => ({
    ...room,
    n: ['101', '204', '302'][index],
    tone: ['bg-[#fff0e6] text-[#cb5e1b]', 'bg-[#e8f3ff] text-[#2b6fa9]', 'bg-[#e7f6ee] text-[#16875d]'][index],
})));
const posItems = computed(() => tm('marketing.product.posItems').map((name, index) => ({
    name,
    price: ['€2.00', '€7.00', '€8.50', '€11.00', '€1.50', '€4.50'][index],
})));
const onboardingSteps = computed(() => {
    const icons = [Building2, Zap, CheckCircle2];
    return tm('marketing.onboarding.steps').map((step, index) => ({ ...step, icon: icons[index] }));
});
const calculatorModules = computed(() => [
    { key: 'channel', label: 'Channel Manager' },
    { key: 'booking', label: 'Booking Online' },
    { key: 'housekeeping', label: 'Housekeeping' },
    { key: 'pos', label: 'POS' },
    { key: 'smartPricing', label: t('marketing.pricing.smartPricing') },
    { key: 'finance', label: t('marketing.pricing.finance') },
]);

const normalizedRooms = computed(() => Math.min(300, Math.max(1, Number(rooms.value) || 1)));
const normalizedPosPoints = computed(() => Math.min(30, Math.max(0, Number(posPoints.value) || 0)));

const channelCost = computed(() => {
    if (!modules.value.channel) return 0;

    const count = normalizedRooms.value;
    return Math.min(count, 50) * 7 + Math.max(count - 50, 0) * 5;
});

const monthlyFixed = computed(() => {
    return 29
        + channelCost.value
        + (modules.value.housekeeping ? 9 : 0)
        + (modules.value.pos ? normalizedPosPoints.value * 19 : 0)
        + (modules.value.smartPricing ? 19 : 0)
        + (modules.value.finance ? 19 : 0);
});

const annualMonthly = computed(() => monthlyFixed.value * 0.8);
const annualInvoice = computed(() => annualMonthly.value * 12);
const annualSavings = computed(() => monthlyFixed.value * 12 * 0.2);
const displayedMonthly = computed(() => annualBilling.value ? annualMonthly.value : monthlyFixed.value);

const money = (value) => new Intl.NumberFormat('en-IE', {
    style: 'currency',
    currency: 'EUR',
    minimumFractionDigits: Number.isInteger(value) ? 0 : 2,
    maximumFractionDigits: 2,
}).format(value);

const cardPrice = (card) => card.displayPrice
    ?? money(card.monthlyPrice * (annualBilling.value ? 0.8 : 1));

const cardNote = (card) => annualBilling.value && card.annualNote
    ? card.annualNote
    : card.note;

const adjust = (target, amount) => {
    if (target === 'rooms') rooms.value = Math.min(300, Math.max(1, normalizedRooms.value + amount));
    if (target === 'pos') posPoints.value = Math.min(30, Math.max(0, normalizedPosPoints.value + amount));
};
</script>

<template>
    <Head :title="t('marketing.meta.title')">
        <meta head-key="description" name="description" :content="t('marketing.meta.description')" />
        <meta head-key="og-title" property="og:title" :content="t('marketing.meta.title')" />
        <meta head-key="og-description" property="og:description" :content="t('marketing.meta.ogDescription')" />
    </Head>

    <div class="lora-marketing min-h-screen overflow-x-hidden bg-[#fbfaf6] text-[#17201d]">
        <header class="sticky top-0 z-50 border-b border-[#123d32]/10 bg-[#fbfaf6]/95 backdrop-blur-xl">
            <div class="mx-auto flex h-20 max-w-7xl items-center justify-between px-5 sm:px-8 lg:px-10">
                <a href="#" class="group flex items-center gap-3 text-[#123d32] no-underline" :aria-label="t('marketing.nav.homeLabel')">
                    <span class="relative grid h-10 w-10 place-items-center rounded-2xl bg-[#123d32] text-white shadow-sm">
                        <span class="absolute left-2 top-2 h-4 w-2 rotate-[-28deg] rounded-full bg-[#7ed6ad]" />
                        <span class="absolute bottom-2 right-2 h-5 w-2 rotate-[26deg] rounded-full bg-white" />
                    </span>
                    <span class="text-xl font-semibold tracking-[-0.03em] sm:text-2xl">Lora PMS</span>
                </a>

                <nav class="hidden items-center gap-8 lg:flex" :aria-label="t('marketing.nav.mainLabel')">
                    <a v-for="item in navigation" :key="item.label" :href="item.href" class="text-sm font-medium text-[#53605b] no-underline transition hover:text-[#123d32]">
                        {{ item.label }}
                    </a>
                </nav>

                <div class="hidden items-center gap-3 sm:flex">
                    <LanguageSwitcher class="mr-1 text-[#53605b]" />
                    <Link href="/login" class="rounded-xl border border-[#123d32]/15 px-5 py-2.5 text-sm font-semibold text-[#123d32] no-underline hover:bg-white">
                        {{ t('marketing.common.login') }}
                    </Link>
                    <a :href="demoMail" class="rounded-xl bg-[#16875d] px-5 py-2.5 text-sm font-semibold text-white no-underline shadow-sm transition hover:bg-[#116f4c]">
                        {{ t('marketing.common.bookDemo') }}
                    </a>
                </div>

                <button class="grid h-11 w-11 place-items-center rounded-xl border border-[#123d32]/15 text-[#123d32] sm:hidden" type="button" :aria-expanded="mobileOpen" :aria-label="mobileOpen ? t('marketing.nav.closeMenu') : t('marketing.nav.openMenu')" @click="mobileOpen = !mobileOpen">
                    <X v-if="mobileOpen" class="h-5 w-5" />
                    <Menu v-else class="h-5 w-5" />
                </button>
            </div>

            <div v-if="mobileOpen" class="border-t border-[#123d32]/10 bg-[#fbfaf6] px-5 pb-6 pt-4 sm:hidden">
                <nav class="grid gap-2">
                    <a v-for="item in navigation" :key="item.label" :href="item.href" class="rounded-xl px-4 py-3 font-medium text-[#33413c] no-underline hover:bg-white" @click="mobileOpen = false">
                        {{ item.label }}
                    </a>
                    <LanguageSwitcher class="px-4 py-2 text-[#53605b]" />
                    <Link href="/login" class="mt-2 rounded-xl border border-[#123d32]/15 px-4 py-3 text-center font-semibold text-[#123d32] no-underline">{{ t('marketing.common.login') }}</Link>
                    <a :href="demoMail" class="rounded-xl bg-[#16875d] px-4 py-3 text-center font-semibold text-white no-underline">{{ t('marketing.common.bookDemo') }}</a>
                </nav>
            </div>
        </header>

        <main>
            <section class="relative overflow-hidden border-b border-[#123d32]/10">
                <div class="pointer-events-none absolute -left-24 bottom-0 h-64 w-64 rounded-full bg-[#bfe7d4]/40 blur-3xl" />
                <div class="pointer-events-none absolute right-[-8rem] top-10 h-80 w-80 rounded-full bg-[#e9dcc1]/45 blur-3xl" />

                <div class="mx-auto grid max-w-7xl items-center gap-12 px-5 py-16 sm:px-8 sm:py-24 lg:grid-cols-[0.82fr_1.18fr] lg:px-10 lg:py-28">
                    <div class="relative z-10">
                        <div class="mb-7 inline-flex items-center gap-2 rounded-full border border-[#16875d]/20 bg-white/80 px-3.5 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-[#16875d]">
                            <Sparkles class="h-3.5 w-3.5" />
                            {{ t('marketing.hero.eyebrow') }}
                        </div>
                        <h1 class="font-display max-w-xl text-[3.45rem] font-medium leading-[0.95] tracking-[-0.055em] text-[#123d32] sm:text-[5rem] lg:text-[5.6rem]">
                            {{ t('marketing.hero.title1') }}<br />{{ t('marketing.hero.title2') }}
                        </h1>
                        <p class="mt-7 max-w-xl text-base leading-7 text-[#5b6662] sm:text-lg sm:leading-8">
                            {{ t('marketing.hero.body') }}
                        </p>
                        <div class="mt-9 flex flex-col gap-3 sm:flex-row">
                            <a :href="demoMail" class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#16875d] px-6 py-3.5 text-sm font-semibold text-white no-underline shadow-[0_14px_30px_-14px_rgba(22,135,93,.7)] transition hover:-translate-y-0.5 hover:bg-[#116f4c]">
                                {{ t('marketing.common.bookDemo') }} <ArrowRight class="h-4 w-4" />
                            </a>
                            <a href="#tur-produkti" class="inline-flex items-center justify-center gap-2 rounded-xl border border-[#123d32]/20 bg-white px-6 py-3.5 text-sm font-semibold text-[#123d32] no-underline transition hover:border-[#16875d]/40 hover:text-[#16875d]">
                                <span class="grid h-5 w-5 place-items-center rounded-full border border-current text-[10px]">▶</span>
                                {{ t('marketing.hero.seeHow') }}
                            </a>
                        </div>
                        <div class="mt-8 flex flex-wrap gap-x-6 gap-y-3 text-sm text-[#65706b]">
                            <span class="inline-flex items-center gap-2"><CheckCircle2 class="h-4 w-4 text-[#16875d]" /> {{ t('marketing.hero.assisted') }}</span>
                            <span class="inline-flex items-center gap-2"><CheckCircle2 class="h-4 w-4 text-[#16875d]" /> {{ t('marketing.hero.noContract') }}</span>
                        </div>
                    </div>

                    <div class="relative">
                        <div class="absolute -inset-6 rounded-[2.5rem] bg-gradient-to-br from-[#d8eee3]/70 to-[#f1e6d1]/40 blur-2xl" />
                        <div class="relative overflow-hidden rounded-[1.75rem] border border-[#123d32]/12 bg-white p-3 shadow-[0_32px_80px_-36px_rgba(18,61,50,.38)] sm:p-5">
                            <div class="flex items-center justify-between border-b border-[#123d32]/8 pb-4">
                                <div class="flex items-center gap-2.5">
                                    <span class="grid h-8 w-8 place-items-center rounded-xl bg-[#123d32] text-xs font-bold text-white">L</span>
                                    <span class="text-sm font-semibold text-[#123d32]">Lora PMS</span>
                                </div>
                                <div class="hidden w-44 items-center gap-2 rounded-lg bg-[#f5f7f5] px-3 py-2 text-[10px] text-[#85908b] sm:flex">
                                    <span>⌕</span> {{ t('marketing.dashboard.search') }}
                                </div>
                                <span class="rounded-lg bg-[#edf7f1] px-2.5 py-1.5 text-[10px] font-semibold text-[#16875d]">{{ t('marketing.dashboard.hotel') }}⌄</span>
                            </div>

                            <div class="grid gap-4 pt-4 md:grid-cols-[7.5rem_1fr]">
                                <div class="hidden space-y-1.5 rounded-xl bg-[#f7f8f5] p-2 md:block">
                                    <div class="mb-3 rounded-lg bg-[#e2f2e9] px-2.5 py-2 text-[10px] font-semibold text-[#126b4a]">▦ {{ t('marketing.dashboard.overview') }}</div>
                                    <div v-for="item in dashboardNav" :key="item" class="px-2.5 py-1.5 text-[9px] font-medium text-[#66716c]">
                                        {{ item }}
                                    </div>
                                </div>

                                <div>
                                    <div class="mb-3 flex items-center justify-between">
                                        <h2 class="text-sm font-semibold text-[#22302b]">{{ t('marketing.dashboard.overview') }}</h2>
                                        <span class="text-[9px] text-[#8a948f]">{{ t('marketing.dashboard.today') }}</span>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                        <div v-for="metric in dashboardMetrics" :key="metric.label" class="rounded-xl border border-[#123d32]/8 p-3">
                                            <p class="text-[8px] font-medium text-[#78837e]">{{ metric.label }}</p>
                                            <p class="mt-1 text-base font-semibold tracking-tight text-[#17201d]">{{ metric.value }}</p>
                                            <p class="mt-1 text-[8px] font-semibold text-[#16875d]">{{ metric.change }} {{ t('marketing.dashboard.yesterday') }}</p>
                                        </div>
                                    </div>

                                    <div class="mt-2 grid gap-2 sm:grid-cols-[1fr_.85fr]">
                                        <div class="rounded-xl border border-[#123d32]/8 p-3">
                                            <div class="flex items-center justify-between">
                                                <p class="text-[9px] font-semibold text-[#38443f]">{{ t('marketing.dashboard.arrivals') }}</p>
                                                <span class="text-[8px] text-[#8a948f]">{{ t('marketing.dashboard.viewAll') }}</span>
                                            </div>
                                            <div class="mt-2 space-y-2">
                                                <div v-for="arrival in arrivals" :key="arrival.guest" class="grid grid-cols-[2.2rem_1fr] items-center gap-2 border-b border-[#123d32]/5 pb-1.5 last:border-0">
                                                    <span class="text-[8px] font-medium text-[#7c8782]">{{ arrival.time }}</span>
                                                    <span class="text-[8px] font-semibold text-[#34413c]">{{ arrival.guest }} <small class="block font-normal text-[#8a948f]">{{ arrival.roomType }}</small></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="rounded-xl border border-[#123d32]/8 p-3">
                                            <p class="text-[9px] font-semibold text-[#38443f]">Housekeeping</p>
                                            <div class="mx-auto mt-3 grid h-24 w-24 place-items-center rounded-full" style="background: conic-gradient(#16875d 0 62%, #8ed9b8 62% 82%, #ead7ad 82% 100%)">
                                                <div class="grid h-[4.5rem] w-[4.5rem] place-items-center rounded-full bg-white text-center">
                                                    <span class="text-lg font-semibold text-[#24312c]">28<small class="block text-[8px] font-medium text-[#89938e]">{{ t('marketing.dashboard.rooms') }}</small></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mt-2 rounded-xl border border-[#123d32]/8 p-3">
                                        <div class="flex items-end justify-between gap-4">
                                            <div>
                                                <p class="text-[8px] text-[#7d8883]">{{ t('marketing.dashboard.revenue') }}</p>
                                                <p class="mt-1 text-lg font-semibold text-[#18231f]">€68,540</p>
                                            </div>
                                            <svg viewBox="0 0 220 55" class="h-12 flex-1" aria-hidden="true">
                                                <path d="M2 46 C20 43, 22 32, 39 35 S64 43, 80 27 S105 31, 119 20 S142 27, 154 13 S179 20, 218 4" fill="none" stroke="#16875d" stroke-width="3" stroke-linecap="round" />
                                                <path d="M2 46 C20 43, 22 32, 39 35 S64 43, 80 27 S105 31, 119 20 S142 27, 154 13 S179 20, 218 4 L218 55 L2 55 Z" fill="url(#heroChart)" opacity=".35" />
                                                <defs><linearGradient id="heroChart" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#62c89a" /><stop offset="1" stop-color="#fff" /></linearGradient></defs>
                                            </svg>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="integrimet" class="border-b border-[#123d32]/10 bg-white py-8 sm:py-10">
                <div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10">
                    <p class="mb-6 text-center text-sm font-medium text-[#6a746f]">{{ t('marketing.integrations.caption') }}</p>
                    <div class="relative">
                        <button type="button" class="absolute -left-3 top-1/2 z-20 grid h-9 w-9 -translate-y-1/2 place-items-center rounded-full border border-[#123d32]/10 bg-white text-[#123d32] shadow-sm sm:left-0" :aria-label="t('marketing.integrations.left')" @click="carouselReverse = false">
                            <ChevronLeft class="h-4 w-4" />
                        </button>
                        <button type="button" class="absolute -right-3 top-1/2 z-20 grid h-9 w-9 -translate-y-1/2 place-items-center rounded-full border border-[#123d32]/10 bg-white text-[#123d32] shadow-sm sm:right-0" :aria-label="t('marketing.integrations.right')" @click="carouselReverse = true">
                            <ChevronRight class="h-4 w-4" />
                        </button>
                        <div class="ota-viewport mx-4 overflow-hidden sm:mx-10">
                            <div class="ota-track" :class="{ 'ota-track-reverse': carouselReverse }">
                                <div v-for="copy in 2" :key="copy" class="ota-set" :aria-hidden="copy === 2">
                                    <div v-for="channel in otaChannels" :key="`${copy}-${channel.name}`" class="flex min-w-[10.5rem] items-center justify-center gap-2.5 rounded-xl border border-[#123d32]/10 bg-[#fbfaf6] px-5 py-4 shadow-[0_4px_18px_-16px_rgba(18,61,50,.5)]">
                                        <span class="grid h-7 min-w-7 place-items-center rounded-lg bg-white px-1.5 text-xs font-bold shadow-sm" :style="{ color: channel.color }">{{ channel.mark }}</span>
                                        <span class="whitespace-nowrap text-sm font-semibold text-[#29352f]">{{ channel.name }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center justify-center gap-2 text-[11px] font-medium text-[#87918d]">
                        <span class="h-1.5 w-8 rounded-full bg-[#16875d]" />
                        <span class="h-1.5 w-1.5 rounded-full bg-[#d8ddda]" />
                        <span class="h-1.5 w-1.5 rounded-full bg-[#d8ddda]" />
                        <ArrowRight class="ml-2 h-3.5 w-3.5" />
                    </div>
                </div>
            </section>

            <section id="funksionet" class="py-20 sm:py-28">
                <div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10">
                    <div class="mx-auto mb-12 max-w-2xl text-center">
                        <p class="text-xs font-bold uppercase tracking-[0.2em] text-[#16875d]">{{ t('marketing.features.eyebrow') }}</p>
                        <h2 class="font-display mt-4 text-4xl font-medium tracking-[-0.04em] text-[#123d32] sm:text-5xl">{{ t('marketing.features.title') }}</h2>
                        <p class="mt-5 text-base leading-7 text-[#66716d]">{{ t('marketing.features.body') }}</p>
                    </div>

                    <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                        <article v-for="feature in featureGroups" :key="feature.title" class="group rounded-[1.5rem] border border-[#123d32]/10 bg-white p-6 shadow-[0_18px_45px_-38px_rgba(18,61,50,.45)] transition duration-300 hover:-translate-y-1 hover:shadow-[0_24px_60px_-36px_rgba(18,61,50,.35)]">
                            <span class="mb-6 grid h-12 w-12 place-items-center rounded-2xl" :class="{
                                'bg-[#e2f3ea] text-[#16875d]': feature.tone === 'emerald',
                                'bg-[#fbecdc] text-[#e87c32]': feature.tone === 'sand',
                                'bg-[#e4f5ed] text-[#3da979]': feature.tone === 'mint',
                                'bg-[#e6f0f0] text-[#257b79]': feature.tone === 'blue',
                            }">
                                <component :is="feature.icon" class="h-6 w-6" />
                            </span>
                            <h3 class="text-xl font-semibold tracking-[-0.02em] text-[#1d2a25]">{{ feature.title }}</h3>
                            <p class="mt-3 min-h-[4.5rem] text-sm leading-6 text-[#6b7571]">{{ feature.description }}</p>
                            <ul class="mt-5 space-y-3">
                                <li v-for="item in feature.items" :key="item" class="flex items-center gap-2.5 text-sm font-medium text-[#48534f]">
                                    <span class="grid h-5 w-5 place-items-center rounded-full bg-[#edf7f1] text-[#16875d]"><Check class="h-3 w-3" /></span>
                                    {{ item }}
                                </li>
                            </ul>
                            <a href="#tur-produkti" class="mt-7 inline-flex items-center gap-2 text-sm font-semibold text-[#16875d] no-underline">{{ t('marketing.common.discover') }} <ArrowRight class="h-4 w-4 transition group-hover:translate-x-1" /></a>
                        </article>
                    </div>
                </div>
            </section>

            <section id="tur-produkti" class="border-y border-[#123d32]/10 bg-white py-20 sm:py-28">
                <div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10">
                    <div class="grid items-center gap-12 lg:grid-cols-[.68fr_1.32fr]">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-[#16875d]">{{ t('marketing.product.eyebrow') }}</p>
                            <h2 class="font-display mt-4 text-4xl font-medium tracking-[-0.04em] text-[#123d32] sm:text-5xl">{{ productCopy[activeProduct].title }}</h2>
                            <p class="mt-5 max-w-md text-base leading-7 text-[#68736e]">{{ productCopy[activeProduct].body }}</p>
                            <div class="mt-8 flex flex-wrap gap-2 lg:grid lg:max-w-xs lg:grid-cols-2">
                                <button v-for="tab in productTabs" :key="tab.key" type="button" class="rounded-xl border px-4 py-3 text-sm font-semibold transition" :class="activeProduct === tab.key ? 'border-[#16875d] bg-[#eaf6ef] text-[#126b4a]' : 'border-[#123d32]/10 bg-[#fbfaf6] text-[#65706b] hover:border-[#16875d]/30'" @click="activeProduct = tab.key">
                                    {{ tab.label }}
                                </button>
                            </div>
                        </div>

                        <div class="overflow-hidden rounded-[1.75rem] border border-[#123d32]/10 bg-[#f6f7f4] p-3 shadow-[0_28px_70px_-45px_rgba(18,61,50,.48)] sm:p-5">
                            <div class="rounded-[1.2rem] border border-[#123d32]/8 bg-white p-4 sm:p-6">
                                <div class="mb-5 flex items-center justify-between gap-4">
                                    <div class="flex items-center gap-2">
                                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-[#123d32] text-xs font-bold text-white">L</span>
                                        <div><p class="text-xs font-semibold text-[#26332e]">Lora PMS</p><p class="text-[9px] text-[#8a948f]">{{ productCopy[activeProduct].eyebrow }}</p></div>
                                    </div>
                                    <button class="rounded-lg bg-[#16875d] px-3 py-2 text-[10px] font-semibold text-white">{{ t('marketing.product.newAction') }}</button>
                                </div>

                                <div v-if="activeProduct === 'reservations'" class="overflow-x-auto">
                                    <table class="w-full min-w-[34rem] text-left text-[10px]">
                                        <thead class="bg-[#f7f8f5] text-[#7a8580]"><tr><th v-for="head in tm('marketing.product.reservationHeaders')" :key="head" class="px-3 py-3 font-semibold">{{ head }}</th></tr></thead>
                                        <tbody class="divide-y divide-[#123d32]/6 text-[#46514c]">
                                            <tr v-for="row in reservationRows" :key="row[0]"><td v-for="(cell, index) in row" :key="index" class="whitespace-nowrap px-3 py-3" :class="index === 5 ? 'font-semibold text-[#16875d]' : ''">{{ cell }}</td></tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div v-else-if="activeProduct === 'housekeeping'" class="grid gap-3 sm:grid-cols-3">
                                    <div v-for="room in housekeepingRooms" :key="room.n" class="rounded-xl border border-[#123d32]/8 p-4">
                                        <div class="flex items-center justify-between"><span class="text-xl font-semibold text-[#26332e]">{{ room.n }}</span><span class="rounded-full px-2 py-1 text-[8px] font-bold" :class="room.tone">{{ room.state }}</span></div>
                                        <p class="mt-1 text-[9px] text-[#8a948f]">{{ t('marketing.product.deluxeFloor') }}</p>
                                        <div class="my-4 h-1.5 overflow-hidden rounded-full bg-[#edf0ee]"><div class="h-full w-3/5 rounded-full bg-[#16875d]" /></div>
                                        <p class="text-[10px] font-medium text-[#59645f]">{{ room.detail }}</p>
                                    </div>
                                </div>

                                <div v-else-if="activeProduct === 'pos'" class="grid gap-4 sm:grid-cols-[1.15fr_.85fr]">
                                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                        <div v-for="item in posItems" :key="item.name" class="rounded-xl border border-[#123d32]/8 bg-[#fbfaf6] p-3"><UtensilsCrossed class="mb-3 h-4 w-4 text-[#16875d]" /><p class="text-[10px] font-semibold text-[#34413c]">{{ item.name }}</p><p class="mt-1 text-[10px] text-[#7b8681]">{{ item.price }}</p></div>
                                    </div>
                                    <div class="rounded-xl bg-[#123d32] p-4 text-white"><p class="text-xs font-semibold">{{ t('marketing.product.order') }}</p><div class="mt-4 space-y-2 text-[10px] text-white/70"><p class="flex justify-between"><span>2 × Espresso</span><span>€4.00</span></p><p class="flex justify-between"><span>1 × Club Sandwich</span><span>€8.50</span></p><p class="flex justify-between"><span>1 × {{ posItems[4].name }}</span><span>€1.50</span></p></div><div class="mt-5 border-t border-white/15 pt-4"><p class="flex justify-between text-sm font-semibold"><span>{{ t('marketing.product.total') }}</span><span>€14.00</span></p><button class="mt-4 w-full rounded-lg bg-[#38b985] py-2.5 text-[10px] font-semibold">{{ t('marketing.product.payOrRoom') }}</button></div></div>
                                </div>

                                <div v-else class="grid gap-4 sm:grid-cols-[1fr_.8fr]">
                                    <div class="rounded-xl border border-[#123d32]/8 p-4"><div class="flex items-center justify-between"><p class="text-xs font-semibold text-[#34413c]">{{ t('marketing.product.occupancy') }}</p><span class="rounded-full bg-[#e7f6ee] px-2 py-1 text-[9px] font-bold text-[#16875d]">+12%</span></div><div class="mt-5 flex h-36 items-end gap-2"><div v-for="height in [35, 48, 42, 66, 72, 58, 86, 76, 91, 82, 96, 88]" :key="height" class="flex-1 rounded-t-md bg-[#74caa4]" :style="{ height: `${height}%`, opacity: .45 + height / 180 }" /></div></div>
                                    <div class="rounded-xl bg-[#f1f7f3] p-4"><Sparkles class="h-5 w-5 text-[#16875d]" /><p class="mt-3 text-xs font-semibold text-[#2c3934]">{{ t('marketing.product.suggestion') }}</p><p class="mt-2 text-3xl font-semibold tracking-tight text-[#123d32]">€128</p><p class="mt-2 text-[10px] leading-5 text-[#69746f]">{{ t('marketing.product.suggestionBody') }}</p><button class="mt-5 w-full rounded-lg bg-[#16875d] py-2.5 text-[10px] font-semibold text-white">{{ t('marketing.product.applyPrice') }}</button></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="cmimet" class="py-20 sm:py-28">
                <div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10">
                    <div class="flex flex-col items-start justify-between gap-8 md:flex-row md:items-end">
                        <div class="max-w-2xl">
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-[#16875d]">{{ t('marketing.pricing.eyebrow') }}</p>
                            <h2 class="font-display mt-4 text-4xl font-medium tracking-[-0.04em] text-[#123d32] sm:text-5xl">{{ t('marketing.pricing.title') }}</h2>
                            <p class="mt-5 text-base leading-7 text-[#68736e]">{{ t('marketing.pricing.body') }}</p>
                        </div>
                        <div class="inline-flex rounded-xl border border-[#123d32]/10 bg-white p-1.5 shadow-sm" role="group" :aria-label="t('marketing.pricing.billingPeriod')">
                            <button type="button" class="rounded-lg px-4 py-2.5 text-sm font-semibold transition" :class="!annualBilling ? 'bg-[#123d32] text-white' : 'text-[#66716d]'" @click="annualBilling = false">{{ t('marketing.pricing.monthly') }}</button>
                            <button type="button" class="flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold transition" :class="annualBilling ? 'bg-[#123d32] text-white' : 'text-[#66716d]'" @click="annualBilling = true">{{ t('marketing.pricing.annual') }} <span class="rounded-full bg-[#dff4e8] px-2 py-0.5 text-[10px] font-bold text-[#16875d]">−20%</span></button>
                        </div>
                    </div>

                    <div class="mt-10 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                        <article v-for="card in pricingCards" :key="card.title" class="flex min-h-[13.5rem] flex-col rounded-2xl border border-[#123d32]/10 bg-white p-5 shadow-[0_15px_40px_-38px_rgba(18,61,50,.5)]">
                            <div class="flex items-start justify-between gap-2">
                                <span class="grid h-10 w-10 place-items-center rounded-xl bg-[#edf7f1] text-[#16875d]"><component :is="card.icon" class="h-5 w-5" /></span>
                                <span v-if="annualBilling && card.monthlyPrice" class="rounded-full bg-[#dff4e8] px-2 py-1 text-[10px] font-bold text-[#16875d]">−20%</span>
                            </div>
                            <h3 class="mt-5 min-h-[2.5rem] text-sm font-semibold leading-5 text-[#33403b]">{{ card.title }}</h3>
                            <div class="mt-3 flex items-baseline gap-1"><strong class="text-3xl font-semibold tracking-[-0.04em] text-[#14221c]">{{ cardPrice(card) }}</strong><span class="text-[10px] font-medium text-[#77827d]">{{ card.unit }}</span></div>
                            <p v-if="annualBilling && card.monthlyPrice" class="mt-1 text-[10px] text-[#8a948f]"><span class="line-through">{{ money(card.monthlyPrice) }}</span> {{ t('marketing.common.monthlyPayment') }}</p>
                            <p class="mt-auto pt-4 text-[10px] leading-4 text-[#7b8581]">{{ cardNote(card) }}</p>
                        </article>
                    </div>

                    <div class="mt-8 overflow-hidden rounded-[1.75rem] border border-[#d8b77b]/45 bg-white shadow-[0_28px_70px_-48px_rgba(18,61,50,.45)]">
                        <div class="grid lg:grid-cols-[1.1fr_.9fr]">
                            <div class="p-5 sm:p-8 lg:p-10">
                                <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                                    <div><p class="text-xs font-bold uppercase tracking-[0.18em] text-[#16875d]">{{ t('marketing.pricing.calculatorEyebrow') }}</p><h3 class="font-display mt-2 text-3xl font-medium text-[#123d32]">{{ t('marketing.pricing.calculatorTitle') }}</h3></div>
                                    <span class="rounded-full bg-[#f5efe3] px-3 py-1.5 text-xs font-semibold text-[#8a6b35]">{{ t('marketing.pricing.changeModules') }}</span>
                                </div>

                                <div class="mt-8 grid gap-7 md:grid-cols-[1fr_.95fr]">
                                    <div class="space-y-2.5">
                                        <div class="flex items-center justify-between rounded-xl bg-[#f7f8f5] px-4 py-3">
                                            <span class="text-sm font-semibold text-[#34413c]">Lora Core</span>
                                            <span class="relative h-6 w-11 rounded-full bg-[#16875d] opacity-80" :aria-label="t('marketing.pricing.coreAlwaysActive')"><span class="absolute right-1 top-1 h-4 w-4 rounded-full bg-white shadow" /></span>
                                        </div>
                                        <label v-for="module in calculatorModules" :key="module.key" class="flex cursor-pointer items-center justify-between rounded-xl bg-[#f7f8f5] px-4 py-3">
                                            <span class="text-sm font-medium text-[#4a5651]">{{ module.label }}</span>
                                            <input v-model="modules[module.key]" type="checkbox" class="peer sr-only" />
                                            <span class="relative h-6 w-11 rounded-full bg-[#dfe4e1] transition peer-checked:bg-[#16875d] peer-focus-visible:ring-2 peer-focus-visible:ring-[#16875d]/30"><span class="absolute left-1 top-1 h-4 w-4 rounded-full bg-white shadow transition" :class="modules[module.key] ? 'translate-x-5' : ''" /></span>
                                        </label>
                                    </div>

                                    <div class="space-y-5">
                                        <div>
                                            <label for="rooms" class="mb-2 block text-xs font-semibold text-[#58635e]">{{ t('marketing.pricing.rooms') }}</label>
                                            <div class="grid grid-cols-[2.75rem_1fr_2.75rem] overflow-hidden rounded-xl border border-[#123d32]/10 bg-white">
                                                <button type="button" class="grid place-items-center text-[#65706b] hover:bg-[#f4f7f5]" :aria-label="t('marketing.pricing.removeRoom')" @click="adjust('rooms', -1)"><Minus class="h-4 w-4" /></button>
                                                <input id="rooms" v-model.number="rooms" type="number" min="1" max="300" class="h-12 border-x border-y-0 border-[#123d32]/10 bg-transparent p-0 text-center text-sm font-semibold text-[#26332e] focus:border-[#16875d] focus:ring-0" />
                                                <button type="button" class="grid place-items-center text-[#65706b] hover:bg-[#f4f7f5]" :aria-label="t('marketing.pricing.addRoom')" @click="adjust('rooms', 1)"><Plus class="h-4 w-4" /></button>
                                            </div>
                                        </div>
                                        <div>
                                            <label for="pos-points" class="mb-2 block text-xs font-semibold text-[#58635e]">{{ t('marketing.pricing.posPoints') }}</label>
                                            <div class="grid grid-cols-[2.75rem_1fr_2.75rem] overflow-hidden rounded-xl border border-[#123d32]/10 bg-white" :class="!modules.pos ? 'opacity-45' : ''">
                                                <button type="button" class="grid place-items-center text-[#65706b] hover:bg-[#f4f7f5]" :disabled="!modules.pos" :aria-label="t('marketing.pricing.removePos')" @click="adjust('pos', -1)"><Minus class="h-4 w-4" /></button>
                                                <input id="pos-points" v-model.number="posPoints" type="number" min="0" max="30" :disabled="!modules.pos" class="h-12 border-x border-y-0 border-[#123d32]/10 bg-transparent p-0 text-center text-sm font-semibold text-[#26332e] focus:border-[#16875d] focus:ring-0" />
                                                <button type="button" class="grid place-items-center text-[#65706b] hover:bg-[#f4f7f5]" :disabled="!modules.pos" :aria-label="t('marketing.pricing.addPos')" @click="adjust('pos', 1)"><Plus class="h-4 w-4" /></button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="relative flex flex-col justify-center overflow-hidden border-t border-[#123d32]/8 bg-[#f6f2e8] p-7 sm:p-10 lg:border-l lg:border-t-0">
                                <div class="absolute -bottom-20 -right-16 h-64 w-64 rounded-full border-[2rem] border-white/60" />
                                <div class="relative">
                                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#74807a]">{{ annualBilling ? t('marketing.pricing.totalAnnual') : t('marketing.pricing.totalMonthly') }}</p>
                                    <div class="mt-4 flex flex-wrap items-baseline gap-2">
                                        <strong class="text-5xl font-semibold tracking-[-0.055em] text-[#123d32] sm:text-6xl">{{ money(displayedMonthly) }}</strong>
                                        <span class="text-base font-semibold text-[#46524d]">{{ t('marketing.common.month') }}</span>
                                    </div>
                                    <p v-if="modules.booking" class="mt-2 text-sm font-medium text-[#52605a]">{{ t('marketing.pricing.directBookings') }}</p>

                                    <div class="mt-7 rounded-2xl border border-[#d8b77b]/50 bg-white/80 p-5">
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="text-sm font-semibold text-[#34413c]">{{ t('marketing.pricing.annualPayment') }}</span>
                                            <span class="rounded-full bg-[#dff4e8] px-2.5 py-1 text-xs font-bold text-[#16875d]">{{ t('marketing.pricing.save20') }}</span>
                                        </div>
                                        <p class="mt-3 text-2xl font-semibold tracking-tight text-[#123d32]">{{ money(annualMonthly) }} <small class="text-xs font-medium text-[#6e7974]">{{ t('marketing.common.month') }}</small></p>
                                        <p class="mt-1 text-xs text-[#6f7975]">{{ t('marketing.pricing.annualInvoice', { invoice: money(annualInvoice), savings: money(annualSavings) }) }}</p>
                                    </div>

                                    <p class="mt-4 text-[11px] leading-5 text-[#79837f]">{{ t('marketing.pricing.annualNote') }}</p>
                                    <a :href="demoMail" class="mt-7 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#16875d] px-5 py-3.5 text-sm font-semibold text-white no-underline transition hover:bg-[#116f4c]">{{ t('marketing.pricing.start') }} <ArrowRight class="h-4 w-4" /></a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="per-hotelet" class="border-y border-[#123d32]/10 bg-white py-20 sm:py-24">
                <div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10">
                    <div class="grid gap-10 lg:grid-cols-[.7fr_1.3fr] lg:items-center">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-[#16875d]">{{ t('marketing.onboarding.eyebrow') }}</p>
                            <h2 class="font-display mt-4 text-4xl font-medium tracking-[-0.04em] text-[#123d32]">{{ t('marketing.onboarding.title') }}</h2>
                            <p class="mt-5 text-base leading-7 text-[#68736e]">{{ t('marketing.onboarding.body') }}</p>
                        </div>
                        <div class="grid gap-4 md:grid-cols-3">
                            <article v-for="(step, index) in onboardingSteps" :key="step.title" class="relative rounded-2xl border border-[#123d32]/10 bg-[#fbfaf6] p-6">
                                <span class="absolute right-4 top-4 grid h-6 w-6 place-items-center rounded-full bg-[#16875d] text-[10px] font-bold text-white">{{ index + 1 }}</span>
                                <component :is="step.icon" class="h-8 w-8 text-[#123d32]" />
                                <h3 class="mt-6 text-lg font-semibold text-[#26332e]">{{ step.title }}</h3>
                                <p class="mt-3 text-sm leading-6 text-[#6d7772]">{{ step.body }}</p>
                            </article>
                        </div>
                    </div>
                </div>
            </section>

            <section class="py-20 sm:py-28">
                <div class="mx-auto grid max-w-7xl gap-6 px-5 sm:px-8 lg:grid-cols-[.78fr_1.22fr] lg:px-10">
                    <div class="rounded-[1.75rem] border border-[#123d32]/10 bg-white p-6 sm:p-8">
                        <p class="text-xs font-bold uppercase tracking-[0.2em] text-[#16875d]">{{ t('marketing.faq.eyebrow') }}</p>
                        <h2 class="font-display mt-4 text-4xl font-medium tracking-[-0.04em] text-[#123d32]">{{ t('marketing.faq.title') }}</h2>
                        <div class="mt-8 divide-y divide-[#123d32]/8">
                            <div v-for="(faq, index) in faqs" :key="faq.q" class="py-1">
                                <button type="button" class="flex w-full items-center justify-between gap-4 py-4 text-left text-sm font-semibold text-[#34413c]" :aria-expanded="openFaq === index" @click="openFaq = openFaq === index ? -1 : index">
                                    {{ faq.q }}
                                    <ChevronDown class="h-4 w-4 shrink-0 text-[#16875d] transition" :class="openFaq === index ? 'rotate-180' : ''" />
                                </button>
                                <p v-if="openFaq === index" class="pb-5 pr-8 text-sm leading-6 text-[#6b7571]">{{ faq.a }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="relative flex min-h-[30rem] flex-col justify-center overflow-hidden rounded-[1.75rem] bg-[#123d32] p-8 text-white sm:p-12">
                        <div class="absolute -right-20 -top-24 h-72 w-72 rounded-full border-[2.5rem] border-white/5" />
                        <div class="absolute -bottom-20 right-10 h-56 w-56 rotate-12 opacity-30">
                            <span class="absolute left-20 top-0 h-32 w-8 rotate-[-18deg] rounded-[100%] bg-[#9cd6b8]" />
                            <span class="absolute left-7 top-16 h-28 w-7 rotate-[-48deg] rounded-[100%] bg-[#d8b77b]" />
                            <span class="absolute left-32 top-24 h-32 w-8 rotate-[38deg] rounded-[100%] bg-white" />
                        </div>
                        <div class="relative max-w-xl">
                            <span class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-2 text-xs font-semibold uppercase tracking-[0.16em] text-[#a8dfc2]"><Hotel class="h-4 w-4" /> Lora PMS</span>
                            <h2 class="font-display mt-7 text-5xl font-medium leading-[1.02] tracking-[-0.04em] text-white sm:text-6xl">{{ t('marketing.cta.title') }}</h2>
                            <p class="mt-6 max-w-lg text-base leading-7 text-white/65">{{ t('marketing.cta.body') }}</p>
                            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                                <a :href="demoMail" class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#38b985] px-6 py-3.5 text-sm font-semibold text-white no-underline hover:bg-[#42c891]">{{ t('marketing.common.bookDemo') }} <ArrowRight class="h-4 w-4" /></a>
                                <a href="mailto:hello@lorapms.com" class="inline-flex items-center justify-center rounded-xl border border-white/25 px-6 py-3.5 text-sm font-semibold text-white no-underline hover:bg-white/10">{{ t('marketing.common.contact') }}</a>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-[#123d32]/10 bg-white">
            <div class="mx-auto grid max-w-7xl gap-10 px-5 py-12 sm:px-8 md:grid-cols-[1.4fr_1fr_1fr_1fr] lg:px-10">
                <div>
                    <div class="flex items-center gap-2.5 text-[#123d32]"><span class="grid h-9 w-9 place-items-center rounded-xl bg-[#123d32] text-sm font-bold text-white">L</span><span class="text-xl font-semibold">Lora PMS</span></div>
                    <p class="mt-4 max-w-xs text-sm leading-6 text-[#6d7772]">{{ t('marketing.footer.tagline') }}</p>
                    <p class="mt-5 text-xs font-medium text-[#8a948f]">© 2026 Lora PMS · lorapms.com</p>
                </div>
                <div><h3 class="text-xs font-bold uppercase tracking-[0.16em] text-[#34413c]">{{ t('marketing.footer.product') }}</h3><div class="mt-4 grid gap-3 text-sm"><a href="#funksionet" class="text-[#6d7772] no-underline">{{ t('marketing.nav.features') }}</a><a href="#integrimet" class="text-[#6d7772] no-underline">{{ t('marketing.nav.integrations') }}</a><a href="#cmimet" class="text-[#6d7772] no-underline">{{ t('marketing.nav.pricing') }}</a></div></div>
                <div><h3 class="text-xs font-bold uppercase tracking-[0.16em] text-[#34413c]">{{ t('marketing.footer.support') }}</h3><div class="mt-4 grid gap-3 text-sm"><a href="mailto:hello@lorapms.com" class="text-[#6d7772] no-underline">{{ t('marketing.footer.help') }}</a><a :href="demoMail" class="text-[#6d7772] no-underline">{{ t('marketing.common.bookDemo') }}</a><a href="mailto:hello@lorapms.com" class="text-[#6d7772] no-underline">{{ t('marketing.footer.contact') }}</a></div></div>
                <div><h3 class="text-xs font-bold uppercase tracking-[0.16em] text-[#34413c]">{{ t('marketing.footer.legal') }}</h3><div class="mt-4 grid gap-3 text-sm"><span class="text-[#6d7772]">{{ t('marketing.footer.terms') }}</span><span class="text-[#6d7772]">{{ t('marketing.footer.privacy') }}</span><span class="text-[#6d7772]">{{ t('marketing.footer.cookies') }}</span></div></div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
.lora-marketing {
    --lora-green: #123d32;
    font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}

.font-display {
    font-family: 'Cormorant Garamond', Georgia, serif;
}

.ota-viewport {
    -webkit-mask-image: linear-gradient(to right, transparent, #000 5%, #000 95%, transparent);
    mask-image: linear-gradient(to right, transparent, #000 5%, #000 95%, transparent);
}

.ota-track {
    display: flex;
    width: max-content;
    animation: lora-marquee 34s linear infinite;
    will-change: transform;
}

.ota-track-reverse {
    animation-direction: reverse;
}

.ota-track:hover {
    animation-play-state: paused;
}

.ota-set {
    display: flex;
    flex-shrink: 0;
    gap: 0.75rem;
    padding-right: 0.75rem;
}

@keyframes lora-marquee {
    from { transform: translateX(0); }
    to { transform: translateX(-50%); }
}

@media (prefers-reduced-motion: reduce) {
    .ota-track { animation: none; }
}
</style>
