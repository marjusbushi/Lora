<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Waves, Wifi, Coffee, Wine } from 'lucide-vue-next';
import WebsiteLayout from '@/Layouts/WebsiteLayout.vue';
import RoomGallery from '@/Components/Website/RoomGallery.vue';
import { amenityIcon } from '@/Components/Website/amenities';

const brandName = computed(() => usePage().props.settings?.hotel_name || 'Hotel');
const currencySymbol = computed(() => usePage().props.settings?.currency_symbol || '€');

const props = defineProps({
    roomTypes: Array,
    hotel: Object,
});
const bookingEnabled = computed(() => usePage().props.modules?.booking_engine === true);

// Present the rooms as a graded collection, not identical tiles: the "Suite"
// (or, failing that, the priciest type) gets a wider featured treatment.
const featured = computed(() => {
    const list = props.roomTypes || [];
    return list.find(r => /suite/i.test(r.name))
        || [...list].sort((a, b) => Number(b.base_price) - Number(a.base_price))[0]
        || null;
});
const standardRooms = computed(() => (props.roomTypes || []).filter(r => r.id !== featured.value?.id));
const hasFromPrice = (room) => room?.from_price !== null
    && room?.from_price !== undefined
    && Number(room.from_price) > 0;

const specRow = (room) => {
    const parts = [t('home.rooms.maxOccupancy', { count: room.max_occupancy }), ...(room.amenities || []).slice(0, 2)];
    return parts.join(' · ');
};

// Hero photo is driven by Settings (hotel.hero_image); falls back to a bundled
// licensed Ionian-coast placeholder so the site reads premium on day one.
// (Replace the fallback by uploading a real Villa Mucho photo in Settings.)
const HERO_FALLBACK_LG = '/images/hero-ionian-1920.jpg';
const HERO_FALLBACK_SM = '/images/hero-ionian-960.jpg';
const heroFromSettings = computed(() => props.hotel?.hero_image);
const heroSrc = computed(() => heroFromSettings.value ? `/storage/${heroFromSettings.value}` : HERO_FALLBACK_LG);
const heroSrcset = computed(() => heroFromSettings.value ? null : `${HERO_FALLBACK_SM} 960w, ${HERO_FALLBACK_LG} 1920w`);

// Hero TEXT is owner-editable per language in Settings → Faqja Web. Fallback chain:
// chosen language → Albanian value → built-in default for the language → Albanian default.
const { t, locale } = useI18n();
const HERO_DEFAULTS = {
    eyebrow: { sq: 'Ksamil · Bregu Jon', en: 'Ksamil · Ionian Shore' },
    title: { sq: 'Nje shtepi e madhe mbi detin Jon', en: 'A grand house above the Ionian Sea' },
    subtitle: {
        sq: 'Qetesi, gur i bardhe dhe mikpritje e vertete ne brigjet e Ksamilit.',
        en: 'Calm, white stone and true hospitality on the shores of Ksamil.',
    },
};
function heroText(field) {
    const h = props.hotel || {};
    const lc = locale.value;
    return h[`hero_${field}_${lc}`] || h[`hero_${field}_sq`] || HERO_DEFAULTS[field][lc] || HERO_DEFAULTS[field].sq;
}
const heroEyebrow = computed(() => heroText('eyebrow'));
const heroTitle = computed(() => heroText('title'));
const heroSubtitle = computed(() => heroText('subtitle'));

const features = computed(() => [
    { icon: Waves, title: t('home.features.sea.title'), desc: t('home.features.sea.desc') },
    { icon: Coffee, title: t('home.features.breakfast.title'), desc: t('home.features.breakfast.desc') },
    { icon: Wifi, title: t('home.features.wifi.title'), desc: t('home.features.wifi.desc') },
    { icon: Wine, title: t('home.features.bar.title'), desc: t('home.features.bar.desc') },
]);
</script>

<template>
    <Head :title="$t('home.meta.title', { hotel: brandName })" />
    <WebsiteLayout :transparent-header="true">
        <!-- Hero -->
        <section class="relative h-[90vh] min-h-[560px] w-full overflow-hidden">
            <img
                :src="heroSrc"
                :srcset="heroSrcset"
                sizes="100vw"
                :alt="$t('home.hero.imageAlt', { hotel: brandName })"
                fetchpriority="high"
                class="absolute inset-0 h-full w-full object-cover hero-kenburns"
            />
            <!-- Subtle bottom-up scrim (never a flat black wash) -->
            <div class="absolute inset-0 pointer-events-none"
                 style="background: linear-gradient(to top, rgba(31,29,26,0.72) 0%, rgba(31,29,26,0.30) 42%, rgba(31,29,26,0.05) 72%, transparent 100%);" />

            <!-- Content sits low, inside the scrim, for legibility -->
            <div class="relative z-10 flex h-full flex-col items-center justify-end text-center px-4 pb-24 sm:pb-28 [text-shadow:0_2px_28px_rgba(31,29,26,0.5)]">
                <span class="eyebrow text-bone/90">{{ heroEyebrow }}</span>
                <span class="mt-4 h-px w-12 bg-brass" />
                <h1 class="text-hero text-bone mt-6 max-w-4xl">{{ heroTitle }}</h1>
                <p class="text-lead text-bone/85 mt-5 max-w-xl">
                    {{ heroSubtitle }}
                </p>
                <div class="flex flex-col sm:flex-row items-center justify-center gap-3 mt-9">
                    <Link v-if="bookingEnabled" href="/book" class="btn-reserve">{{ $t('home.hero.bookNow') }}</Link>
                    <Link href="/rooms" class="inline-flex items-center justify-center px-7 py-3 border border-bone/40 text-bone text-sm font-medium tracking-wide hover:bg-bone/10 transition-colors no-underline">
                        {{ $t('home.hero.viewRooms') }}
                    </Link>
                </div>
            </div>
        </section>

        <!-- Features -->
        <section class="py-24 bg-limestone/40">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center mb-14">
                    <span class="eyebrow-brass">{{ $t('home.features.eyebrow') }}</span>
                    <h2 class="text-display text-ink mt-3">{{ $t('home.features.heading') }}</h2>
                    <p class="text-lead text-ink/60 mt-3 measure mx-auto">{{ $t('home.features.subheading') }}</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-px bg-driftwood/15 border border-driftwood/15">
                    <div v-for="f in features" :key="f.title" class="bg-bone p-8 text-center">
                        <component :is="f.icon" class="h-7 w-7 mx-auto mb-4 text-ionian" :stroke-width="1.25" />
                        <h3 class="text-xl text-ink mb-1.5">{{ f.title }}</h3>
                        <p class="text-body-sm text-ink/55 leading-relaxed">{{ f.desc }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Room Types -->
        <section class="py-24">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center mb-14">
                    <span class="eyebrow-brass">{{ $t('home.rooms.eyebrow') }}</span>
                    <h2 class="text-display text-ink mt-3">{{ $t('home.rooms.heading') }}</h2>
                    <p class="text-lead text-ink/60 mt-3 measure mx-auto">{{ $t('home.rooms.subheading') }}</p>
                </div>

                <!-- Featured suite -->
                <div v-if="featured" class="grid grid-cols-1 lg:grid-cols-2 border border-driftwood/20 mb-8">
                    <RoomGallery :images="featured.images" :alt="featured.name" aspect="aspect-[4/3] lg:aspect-auto lg:h-full lg:min-h-[420px]" />
                    <div class="p-8 lg:p-12 flex flex-col justify-center">
                        <span class="eyebrow-brass">{{ $t('home.rooms.suiteLabel') }}</span>
                        <h3 class="text-display-sm text-ink mt-3">{{ featured.name }}</h3>
                        <p class="text-body text-ink/60 mt-4 leading-relaxed">{{ featured.description }}</p>
                        <div class="flex flex-wrap gap-x-5 gap-y-2 mt-6">
                            <span v-for="a in (featured.amenities || [])" :key="a" class="inline-flex items-center gap-1.5 text-body-sm text-ink/65">
                                <component :is="amenityIcon(a)" class="h-4 w-4 text-ionian" :stroke-width="1.5" /> {{ a }}
                            </span>
                        </div>
                        <div v-if="featured.breakfast_included" class="mt-6 inline-flex items-center gap-2.5 self-start px-4 py-2 bg-ionian text-bone text-body-sm font-medium tracking-wide shadow-sm">
                            <Coffee class="h-5 w-5" :stroke-width="1.75" /> {{ $t('home.rooms.breakfastIncluded') }}
                        </div>
                        <div class="flex items-center justify-between mt-8 pt-6 border-t border-driftwood/15">
                            <p class="text-body-sm text-ink/55" :title="$t('home.rooms.fromPriceHint')">
                                <template v-if="hasFromPrice(featured)">
                                    {{ $t('home.rooms.priceFrom') }} <span class="text-brass text-lg">{{ currencySymbol }}{{ featured.from_price }}</span> {{ $t('home.rooms.perNight') }}
                                </template>
                                <span v-else>{{ $t('home.rooms.checkDates') }}</span>
                            </p>
                            <Link v-if="bookingEnabled" :href="`/book?room_type=${featured.id}`" class="btn-reserve">{{ $t('home.rooms.reserve') }}</Link>
                        </div>
                    </div>
                </div>

                <!-- Standard rooms -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <div v-for="room in standardRooms" :key="room.id" class="group border border-driftwood/20 bg-bone flex flex-col">
                        <RoomGallery :images="room.images" :alt="room.name" />
                        <div class="p-6 flex flex-col flex-1">
                            <div class="flex items-baseline justify-between gap-3">
                                <h3 class="text-2xl text-ink">{{ room.name }}</h3>
                                <p class="text-body-sm text-ink/55 whitespace-nowrap" :title="$t('home.rooms.fromPriceHint')">
                                    <template v-if="hasFromPrice(room)">
                                        {{ $t('home.rooms.priceFrom') }} <span class="text-brass">{{ currencySymbol }}{{ room.from_price }}</span>
                                    </template>
                                    <span v-else>{{ $t('home.rooms.checkDates') }}</span>
                                </p>
                            </div>
                            <p class="text-body-sm text-ink/55 mt-2 line-clamp-2">{{ room.description }}</p>
                            <p class="eyebrow text-driftwood mt-4">{{ $t('home.rooms.maxOccupancy', { count: room.max_occupancy }) }}</p>

                            <!-- All amenities -->
                            <div v-if="(room.amenities || []).length" class="flex flex-wrap gap-1.5 mt-3">
                                <span v-for="a in room.amenities" :key="a" class="inline-flex items-center gap-1 px-2.5 py-1 border border-driftwood/20 text-tiny text-ink/65">
                                    <component :is="amenityIcon(a)" class="h-3.5 w-3.5 text-ionian" :stroke-width="1.5" /> {{ a }}
                                </span>
                            </div>

                            <div v-if="room.breakfast_included" class="mt-3 inline-flex items-center gap-2 px-3 py-1.5 bg-ionian text-bone text-body-sm font-medium tracking-wide self-start">
                                <Coffee class="h-4 w-4" :stroke-width="1.75" /> {{ $t('home.rooms.breakfastIncluded') }}
                            </div>

                            <div class="mt-auto pt-5 border-t border-driftwood/15">
                                <Link v-if="bookingEnabled" :href="`/book?room_type=${room.id}`" class="btn-reserve w-full">
                                    {{ $t('home.rooms.reserve') }}
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="py-24 bg-ink">
            <div class="max-w-3xl mx-auto px-4 text-center">
                <span class="eyebrow text-brass-light">{{ $t('home.cta.eyebrow') }}</span>
                <h2 class="text-display text-bone mt-3">{{ $t('home.cta.heading') }}</h2>
                <p class="text-lead text-bone/60 mt-3">{{ $t('home.cta.subheading') }}</p>
                <Link v-if="bookingEnabled" href="/book" class="btn-reserve-light mt-8 px-8 py-3.5">
                    {{ $t('home.cta.bookNow') }}
                </Link>
            </div>
        </section>
    </WebsiteLayout>
</template>
