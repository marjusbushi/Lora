<script setup>
import { getIntlLocale, translate } from '@/i18n';
import { computed, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import {
    ArrowRight,
    Banknote,
    BarChart3,
    BedDouble,
    CalendarCheck,
    CalendarX,
    ChartNoAxesCombined,
    CircleDollarSign,
    ClipboardList,
    Clock3,
    CreditCard,
    FileBarChart,
    HandCoins,
    House,
    Percent,
    ReceiptText,
    Search,
    ShoppingBasket,
    Sparkles,
    TrendingUp,
    UserRoundCheck,
    Users,
    Utensils,
    WalletCards,
} from 'lucide-vue-next';

defineProps({ currency: { type: String, default: '€' } });

const page = usePage();
const activeModules = computed(() => page.props.modules || {});
const hasModule = (module) => !module || activeModules.value[module] === true;

const allCategories = [
    { key: 'all', label: translate('admin.reports.catalog.all') },
    { key: 'finance', label: translate('admin.reports.catalog.finance') },
    { key: 'reservations', label: translate('admin.reports.catalog.reservations') },
    { key: 'operations', label: translate('admin.reports.catalog.operations') },
    { key: 'guests', label: translate('admin.reports.catalog.guests') },
    { key: 'pos', label: 'POS' },
];
const categories = computed(() => allCategories.filter((category) => category.key !== 'pos' || hasModule('pos')));

const groups = [
    {
        key: 'revenue',
        name: translate('admin.generated.k_9487e3b868dd'),
        category: 'finance',
        icon: ChartNoAxesCombined,
        reports: [
            { name: translate('admin.reports.catalog.executive'), desc: translate('admin.generated.k_ae504df14db4'), to: 'reports.executive', icon: BarChart3 },
            { name: translate('admin.reports.catalog.performance'), desc: translate('admin.generated.k_ad4fb73d0b26'), to: 'reports.performance', icon: TrendingUp },
            { name: translate('admin.reports.catalog.pace'), desc: translate('admin.generated.k_cce552012b85'), to: 'reports.pace', icon: Clock3 },
        ],
    },
    {
        key: 'channels',
        name: translate('admin.generated.k_bddfc1890264'),
        category: 'reservations',
        icon: CalendarCheck,
        reports: [
            { name: translate('admin.reports.catalog.channels'), desc: translate('admin.generated.k_6d6dfc8c5f41'), to: 'reports.channels', icon: ChartNoAxesCombined },
            { name: translate('admin.reports.catalog.cancellations'), desc: translate('admin.generated.k_c895712ad4ee'), to: 'reports.cancellations', icon: CalendarX },
            { name: translate('admin.reports.catalog.bookingBehavior'), desc: translate('admin.generated.k_2d1fcb13fa71'), to: 'reports.bookingBehavior', icon: ClipboardList },
        ],
    },
    {
        key: 'operations',
        name: translate('admin.reports.catalog.operations'),
        category: 'operations',
        icon: BedDouble,
        reports: [
            { name: translate('admin.reports.catalog.arrivals'), desc: translate('admin.reports.catalog.arrivalsDesc'), to: 'reports.arrivalsManifest', icon: UserRoundCheck },
            { name: translate('admin.reports.catalog.departures'), desc: translate('admin.reports.catalog.departuresDesc'), to: 'reports.departuresManifest', icon: CalendarCheck },
            { name: translate('admin.reports.catalog.roomStatus'), desc: translate('admin.reports.catalog.roomStatusDesc'), to: 'reports.roomStatus', icon: BedDouble },
            { name: translate('admin.reports.catalog.housekeeping'), desc: translate('admin.reports.catalog.housekeepingDesc'), to: 'reports.housekeepingReport', icon: Sparkles, module: 'housekeeping' },
            { name: translate('admin.reports.catalog.inHouse'), desc: translate('admin.reports.catalog.inHouseDesc'), to: 'reports.inHouse', icon: House },
        ],
    },
    {
        key: 'finance',
        name: translate('admin.generated.k_e4080b3d1c44'),
        category: 'finance',
        icon: Banknote,
        reports: [
            { name: translate('admin.generated.k_9510fd30116d'), desc: translate('admin.generated.k_958f73cee249'), to: 'reports.outstanding', icon: ReceiptText },
            { name: translate('admin.reports.catalog.zReport'), desc: translate('admin.generated.k_bf9a4f411063'), to: 'reports.shifts', icon: WalletCards },
            { name: translate('admin.generated.k_176dd4832014'), desc: translate('admin.generated.k_d0dd07049135'), to: 'reports.payments', icon: HandCoins },
            { name: translate('admin.reports.catalog.vat'), desc: translate('admin.generated.k_a1af7e68c583'), to: 'reports.vat', icon: Percent },
            { name: translate('admin.generated.k_c1b454fb69dd'), desc: translate('admin.generated.k_c2bfd6b01875'), to: 'reports.discounts', icon: CircleDollarSign },
        ],
    },
    {
        key: 'guests',
        name: translate('admin.generated.k_86478512913f'),
        category: 'guests',
        icon: Users,
        reports: [
            { name: translate('admin.generated.k_584ca6441f0e'), desc: translate('admin.generated.k_b87974bf40e5'), to: 'reports.guests', icon: Users },
            { name: translate('admin.generated.k_ca09b5b0faab'), desc: translate('admin.generated.k_fc6ec82726f1'), to: 'reports.repeatGuests', icon: UserRoundCheck },
            { name: translate('admin.generated.k_71151c9f50b0'), desc: translate('admin.generated.k_261a465a8096'), to: 'reports.nationality', icon: FileBarChart },
        ],
    },
    {
        key: 'pos',
        name: translate('admin.reports.catalog.barRestaurant'),
        category: 'pos',
        module: 'pos',
        icon: Utensils,
        reports: [
            { name: translate('admin.generated.k_443dc45fa745'), desc: translate('admin.generated.k_f8476b1b0151'), to: 'reports.posSales', icon: ShoppingBasket },
            { name: translate('admin.generated.k_598ef37ef3c9'), desc: translate('admin.generated.k_5c43b8be2406'), to: 'reports.posHourly', icon: Clock3 },
            { name: translate('admin.reports.catalog.posPaymentMix'), desc: translate('admin.generated.k_fe6e775c6227'), to: 'reports.posPaymentMix', icon: CreditCard },
            { name: translate('admin.reports.catalog.posVoids'), desc: translate('admin.generated.k_22c51f5e6a35'), to: 'reports.posVoids', icon: CalendarX },
        ],
    },
];

const allReports = computed(() => groups
    .filter((group) => hasModule(group.module))
    .flatMap((group) => group.reports
        .filter((report) => hasModule(report.module))
        .map((report) => ({ ...report, category: group.category }))));
const quickRouteNames = ['reports.executive', 'reports.arrivalsManifest', 'reports.departuresManifest', 'reports.outstanding'];
const query = ref('');
const activeCategory = ref('all');
const recentRouteNames = ref([]);

const normalizedQuery = computed(() => query.value.trim().toLocaleLowerCase(getIntlLocale()));

const visibleGroups = computed(() => groups
    .filter((group) => hasModule(group.module))
    .filter((group) => activeCategory.value === 'all' || group.category === activeCategory.value)
    .map((group) => ({
        ...group,
        reports: group.reports.filter((report) => {
            if (!hasModule(report.module)) return false;
            if (!normalizedQuery.value) return true;
            return `${report.name} ${report.desc}`.toLocaleLowerCase(getIntlLocale()).includes(normalizedQuery.value);
        }),
    }))
    .filter((group) => group.reports.length));

const quickReports = computed(() => quickRouteNames
    .map((routeName) => allReports.value.find((report) => report.to === routeName))
    .filter(Boolean));

const recentReports = computed(() => {
    const routes = recentRouteNames.value.length ? recentRouteNames.value : quickRouteNames;
    return routes.map((routeName) => allReports.value.find((report) => report.to === routeName)).filter(Boolean).slice(0, 4);
});

function rememberReport(report) {
    const next = [report.to, ...recentRouteNames.value.filter((routeName) => routeName !== report.to)].slice(0, 4);
    recentRouteNames.value = next;
    window.localStorage.setItem('pms-recent-reports', JSON.stringify(next));
}

onMounted(() => {
    try {
        const stored = JSON.parse(window.localStorage.getItem('pms-recent-reports') || '[]');
        if (Array.isArray(stored)) recentRouteNames.value = stored.filter((routeName) => allReports.value.some((report) => report.to === routeName));
    } catch {
        recentRouteNames.value = [];
    }
});
</script>

<template>
    <AppLayout>
        <PageHeader :title="$t('admin.generated.k_1477015f4b65')" :breadcrumbs="[{ label: $t('admin.generated.k_6f271027930d'), href: '/dashboard' }, { label: $t('admin.generated.k_6126cf019460') }]" />
        <p class="mt-1 text-body-sm text-neutral-500">{{ $t('admin.generated.k_35ab85603dcf') }}</p>

        <div :class="['mt-6 grid gap-4', !normalizedQuery && activeCategory === 'all' && 'xl:grid-cols-[minmax(0,1fr)_280px]']">
            <section class="rounded-lg border border-neutral-200 bg-white shadow-card">
                <div class="flex flex-col gap-3 border-b border-neutral-200 p-4 lg:flex-row lg:items-center">
                    <label class="relative min-w-0 flex-1">
                        <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                        <input
                            v-model="query"
                            type="search"
                            :aria-label="$t('admin.generated.k_9d9f9f376c62')"
                            :placeholder="$t('admin.generated.k_35c4f0cc1bd2')"
                            class="w-full rounded-md border border-neutral-200 bg-white py-2.5 pl-10 pr-3 text-body-sm text-neutral-900 outline-none transition focus:border-accent-500 focus:ring-2 focus:ring-accent-500/20"
                        />
                    </label>
                    <div class="flex flex-wrap gap-2" :aria-label="$t('admin.generated.k_0d55dde24525')">
                        <button
                            v-for="category in categories"
                            :key="category.key"
                            type="button"
                            :aria-pressed="activeCategory === category.key"
                            class="rounded-md border px-3 py-2 text-body-sm font-medium transition"
                            :class="activeCategory === category.key
                                ? 'border-accent-600 bg-accent-50 text-accent-700'
                                : 'border-neutral-200 bg-white text-neutral-600 hover:border-neutral-300 hover:bg-neutral-50'"
                            @click="activeCategory = category.key"
                        >
                            {{ category.label }}
                        </button>
                    </div>
                </div>

                <div v-if="!normalizedQuery && activeCategory === 'all'" class="p-4">
                    <h2 class="text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_cc3ecb6c63b2') }}</h2>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <Link
                            v-for="report in quickReports"
                            :key="report.to"
                            :href="route(report.to)"
                            class="group rounded-lg border border-neutral-200 p-4 no-underline transition hover:border-accent-300 hover:bg-accent-50/40 hover:shadow-sm"
                            @click="rememberReport(report)"
                        >
                            <component :is="report.icon" class="h-6 w-6 text-accent-600" :stroke-width="1.7" />
                            <p class="mt-3 text-body-sm font-semibold text-primary-900 group-hover:text-accent-700">{{ report.name }}</p>
                            <p class="mt-1 line-clamp-2 text-tiny leading-relaxed text-neutral-500">{{ report.desc }}</p>
                        </Link>
                    </div>
                </div>
            </section>

            <aside v-if="!normalizedQuery && activeCategory === 'all'" class="rounded-lg border border-neutral-200 bg-white p-4 shadow-card">
                <h2 class="text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_d4e05bd183e3') }}</h2>
                <div class="mt-3 divide-y divide-neutral-100">
                    <Link
                        v-for="report in recentReports"
                        :key="report.to"
                        :href="route(report.to)"
                        class="group flex items-center gap-3 py-3 no-underline first:pt-1 last:pb-1"
                        @click="rememberReport(report)"
                    >
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-accent-50 text-accent-700">
                            <component :is="report.icon" class="h-4 w-4" :stroke-width="1.7" />
                        </span>
                        <span class="min-w-0 flex-1 truncate text-body-sm font-medium text-neutral-700 group-hover:text-accent-700">{{ report.name }}</span>
                        <ArrowRight class="h-4 w-4 shrink-0 text-neutral-300 group-hover:text-accent-500" />
                    </Link>
                </div>
            </aside>
        </div>

        <div v-if="visibleGroups.length" :class="['grid gap-4 xl:grid-cols-2', !normalizedQuery && activeCategory === 'all' ? 'mt-5' : 'mt-6']">
            <section
                v-for="group in visibleGroups"
                :key="group.key"
                class="overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-card"
            >
                <div class="flex items-center gap-2 border-b border-neutral-200 px-4 py-3">
                    <component :is="group.icon" class="h-5 w-5 text-accent-600" :stroke-width="1.7" />
                    <h2 class="text-body font-semibold text-primary-900">{{ group.name }}</h2>
                    <span class="ml-auto rounded-full bg-neutral-100 px-2 py-0.5 text-tiny font-medium text-neutral-500">{{ group.reports.length }}</span>
                </div>
                <div class="divide-y divide-neutral-100 px-3">
                    <Link
                        v-for="report in group.reports"
                        :key="report.to"
                        :href="route(report.to)"
                        class="group flex items-center gap-3 rounded-md px-2 py-3 no-underline transition hover:bg-neutral-50"
                        @click="rememberReport(report)"
                    >
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-accent-50 text-accent-700">
                            <component :is="report.icon" class="h-4 w-4" :stroke-width="1.7" />
                        </span>
                        <span class="min-w-0 flex-1 lg:grid lg:grid-cols-[minmax(160px,0.75fr)_1.25fr] lg:items-center lg:gap-4">
                            <span class="block truncate text-body-sm font-medium text-primary-900 group-hover:text-accent-700">{{ report.name }}</span>
                            <span class="mt-0.5 block truncate text-tiny text-neutral-500 lg:mt-0">{{ report.desc }}</span>
                        </span>
                        <ArrowRight class="h-4 w-4 shrink-0 text-neutral-300 transition group-hover:translate-x-0.5 group-hover:text-accent-500" />
                    </Link>
                </div>
            </section>
        </div>

        <div v-else class="mt-5 rounded-lg border border-dashed border-neutral-300 bg-white px-6 py-14 text-center">
            <FileBarChart class="mx-auto h-9 w-9 text-neutral-300" :stroke-width="1.5" />
            <h2 class="mt-3 text-body font-semibold text-primary-900">{{ $t('admin.generated.k_028cf07eeaef') }}</h2>
            <p class="mt-1 text-body-sm text-neutral-500">{{ $t('admin.generated.k_282255730dc3') }}</p>
        </div>
    </AppLayout>
</template>
