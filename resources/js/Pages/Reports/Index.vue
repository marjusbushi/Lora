<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
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

const categories = [
    { key: 'all', label: 'Të gjitha' },
    { key: 'finance', label: 'Financë' },
    { key: 'reservations', label: 'Rezervime' },
    { key: 'operations', label: 'Operacione' },
    { key: 'guests', label: 'Mysafirë' },
    { key: 'pos', label: 'POS' },
];

const groups = [
    {
        key: 'revenue',
        name: 'Të ardhura & performanca',
        category: 'finance',
        icon: ChartNoAxesCombined,
        reports: [
            { name: 'Pasqyra ekzekutive', desc: 'Pamja e plotë e të ardhurave dhe performancës.', to: 'reports.executive', icon: BarChart3 },
            { name: 'ADR / RevPAR / Mbushja', desc: 'Çmimi mesatar, të ardhurat dhe mbushja.', to: 'reports.performance', icon: TrendingUp },
            { name: 'Tempo & Pickup', desc: 'Ritmi i rezervimeve për ditët në vijim.', to: 'reports.pace', icon: Clock3 },
        ],
    },
    {
        key: 'channels',
        name: 'Rezervime & kanale',
        category: 'reservations',
        icon: CalendarCheck,
        reports: [
            { name: 'Prodhimi sipas kanaleve', desc: 'Rezervime, të ardhura dhe kosto sipas kanalit.', to: 'reports.channels', icon: ChartNoAxesCombined },
            { name: 'Anulime & No-Show', desc: 'Anulimet dhe rezervimet me rrezik mosparaqitjeje.', to: 'reports.cancellations', icon: CalendarX },
            { name: 'Sjellja e rezervimit', desc: 'Lead time dhe kohëzgjatja sipas kanalit.', to: 'reports.bookingBehavior', icon: ClipboardList },
        ],
    },
    {
        key: 'operations',
        name: 'Operacione',
        category: 'operations',
        icon: BedDouble,
        reports: [
            { name: 'Manifesti i mbërritjeve', desc: 'Mysafirët që priten të mbërrijnë.', to: 'reports.arrivalsManifest', icon: UserRoundCheck },
            { name: 'Manifesti i nisjeve', desc: 'Nisjet dhe balancat që duhen mbyllur.', to: 'reports.departuresManifest', icon: CalendarCheck },
            { name: 'Statusi i dhomave', desc: 'Gjendja aktuale e çdo dhome.', to: 'reports.roomStatus', icon: BedDouble },
            { name: 'Raporti i pastrimit', desc: 'Ngarkesa dhe produktiviteti i housekeeping.', to: 'reports.housekeepingReport', icon: Sparkles },
            { name: 'Mysafirë në hotel', desc: 'Lista e mysafirëve aktualisht brenda.', to: 'reports.inHouse', icon: House },
        ],
    },
    {
        key: 'finance',
        name: 'Financë & arka',
        category: 'finance',
        icon: Banknote,
        reports: [
            { name: 'Bilance të papaguara', desc: 'Qëndrimet me detyrim ende të hapur.', to: 'reports.outstanding', icon: ReceiptText },
            { name: 'Z-Report / Mbyllje turni', desc: 'Pajtimi i arkës për çdo turn.', to: 'reports.shifts', icon: WalletCards },
            { name: 'Arkëtime & Cash', desc: 'Paratë e mbledhura sipas metodës dhe ditës.', to: 'reports.payments', icon: HandCoins },
            { name: 'Raport TVSH', desc: 'TVSH-ja sipas burimit për periudhën.', to: 'reports.vat', icon: Percent },
            { name: 'Zbritje të dhëna', desc: 'Zbritjet dhe vlera e të ardhurave të lëshuara.', to: 'reports.discounts', icon: CircleDollarSign },
        ],
    },
    {
        key: 'guests',
        name: 'Mysafirë',
        category: 'guests',
        icon: Users,
        reports: [
            { name: 'Direktoria e mysafirëve', desc: 'Statistikat e qëndrimeve për çdo mysafir.', to: 'reports.guests', icon: Users },
            { name: 'Kthyes & top sipas vlerës', desc: 'Mysafirët besnikë dhe më me vlerë.', to: 'reports.repeatGuests', icon: UserRoundCheck },
            { name: 'Përbërja sipas kombësisë', desc: 'Netët dhe të ardhurat sipas vendit.', to: 'reports.nationality', icon: FileBarChart },
        ],
    },
    {
        key: 'pos',
        name: 'Bar & restorant',
        category: 'pos',
        icon: Utensils,
        reports: [
            { name: 'Shitjet sipas kategorisë', desc: 'Të ardhurat dhe sasitë sipas artikullit.', to: 'reports.posSales', icon: ShoppingBasket },
            { name: 'Shitjet sipas orës & ditës', desc: 'Oraret dhe ditët më të ngarkuara.', to: 'reports.posHourly', icon: Clock3 },
            { name: 'Mix i pagesave POS', desc: 'Kesh, kartë dhe pagesa në folio.', to: 'reports.posPaymentMix', icon: CreditCard },
            { name: 'Anulime & Voids POS', desc: 'Porositë e anuluara dhe vlera e humbur.', to: 'reports.posVoids', icon: CalendarX },
        ],
    },
];

const allReports = groups.flatMap((group) => group.reports.map((report) => ({ ...report, category: group.category })));
const quickRouteNames = ['reports.executive', 'reports.arrivalsManifest', 'reports.departuresManifest', 'reports.outstanding'];
const query = ref('');
const activeCategory = ref('all');
const recentRouteNames = ref([]);

const normalizedQuery = computed(() => query.value.trim().toLocaleLowerCase('sq-AL'));

const visibleGroups = computed(() => groups
    .filter((group) => activeCategory.value === 'all' || group.category === activeCategory.value)
    .map((group) => ({
        ...group,
        reports: group.reports.filter((report) => {
            if (!normalizedQuery.value) return true;
            return `${report.name} ${report.desc}`.toLocaleLowerCase('sq-AL').includes(normalizedQuery.value);
        }),
    }))
    .filter((group) => group.reports.length));

const quickReports = computed(() => quickRouteNames
    .map((routeName) => allReports.find((report) => report.to === routeName))
    .filter(Boolean));

const recentReports = computed(() => {
    const routes = recentRouteNames.value.length ? recentRouteNames.value : quickRouteNames;
    return routes.map((routeName) => allReports.find((report) => report.to === routeName)).filter(Boolean).slice(0, 4);
});

function rememberReport(report) {
    const next = [report.to, ...recentRouteNames.value.filter((routeName) => routeName !== report.to)].slice(0, 4);
    recentRouteNames.value = next;
    window.localStorage.setItem('pms-recent-reports', JSON.stringify(next));
}

onMounted(() => {
    try {
        const stored = JSON.parse(window.localStorage.getItem('pms-recent-reports') || '[]');
        if (Array.isArray(stored)) recentRouteNames.value = stored.filter((routeName) => allReports.some((report) => report.to === routeName));
    } catch {
        recentRouteNames.value = [];
    }
});
</script>

<template>
    <AppLayout>
        <PageHeader title="Qendra e raporteve" :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Raporte' }]" />
        <p class="mt-1 text-body-sm text-neutral-500">Gjej shpejt informacionin që të duhet për hotelin.</p>

        <div :class="['mt-6 grid gap-4', !normalizedQuery && activeCategory === 'all' && 'xl:grid-cols-[minmax(0,1fr)_280px]']">
            <section class="rounded-lg border border-neutral-200 bg-white shadow-card">
                <div class="flex flex-col gap-3 border-b border-neutral-200 p-4 lg:flex-row lg:items-center">
                    <label class="relative min-w-0 flex-1">
                        <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                        <input
                            v-model="query"
                            type="search"
                            aria-label="Kërko raport"
                            placeholder="Kërko raport..."
                            class="w-full rounded-md border border-neutral-200 bg-white py-2.5 pl-10 pr-3 text-body-sm text-neutral-900 outline-none transition focus:border-accent-500 focus:ring-2 focus:ring-accent-500/20"
                        />
                    </label>
                    <div class="flex flex-wrap gap-2" aria-label="Filtro raportet sipas kategorisë">
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
                    <h2 class="text-body-sm font-semibold text-primary-900">Raportet më të përdorura</h2>
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
                <h2 class="text-body-sm font-semibold text-primary-900">Të përdorura së fundmi</h2>
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
            <h2 class="mt-3 text-body font-semibold text-primary-900">Nuk u gjet asnjë raport</h2>
            <p class="mt-1 text-body-sm text-neutral-500">Provo një fjalë tjetër ose zgjidh një kategori tjetër.</p>
        </div>
    </AppLayout>
</template>
