<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Waves, UtensilsCrossed, Wifi, SquareParking, ArrowRight, Coffee } from 'lucide-vue-next';
import WebsiteLayout from '@/Layouts/WebsiteLayout.vue';
import RoomGallery from '@/Components/Website/RoomGallery.vue';
import { amenityIcon } from '@/Components/Website/amenities';

const props = defineProps({
    roomTypes: Array,
    hotel: Object,
});

// Present the rooms as a graded collection, not identical tiles: the "Suite"
// (or, failing that, the priciest type) gets a wider featured treatment.
const featured = computed(() => {
    const list = props.roomTypes || [];
    return list.find(r => /suite/i.test(r.name))
        || [...list].sort((a, b) => Number(b.base_price) - Number(a.base_price))[0]
        || null;
});
const standardRooms = computed(() => (props.roomTypes || []).filter(r => r.id !== featured.value?.id));

const specRow = (room) => {
    const parts = [`Max ${room.max_occupancy} persona`, ...(room.amenities || []).slice(0, 2)];
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

const features = [
    { icon: Waves, title: 'Afer Detit', desc: 'Vetem 2 minuta ne kembe nga plazhi i Ksamilit' },
    { icon: UtensilsCrossed, title: 'Restorant', desc: 'Kuzhine mesdhetare me produkte lokale te fresketa' },
    { icon: Wifi, title: 'WiFi Falas', desc: 'Internet i shpejte ne te gjitha ambientet' },
    { icon: SquareParking, title: 'Parking', desc: 'Parking privat falas per te gjithe mysafiret' },
];
</script>

<template>
    <Head title="Home — Villa Mucho" />
    <WebsiteLayout :transparent-header="true">
        <!-- Hero -->
        <section class="relative h-[90vh] min-h-[560px] w-full overflow-hidden">
            <img
                :src="heroSrc"
                :srcset="heroSrcset"
                sizes="100vw"
                alt="Villa Mucho — Ksamil, bregu i Jonit"
                fetchpriority="high"
                class="absolute inset-0 h-full w-full object-cover hero-kenburns"
            />
            <!-- Subtle bottom-up scrim (never a flat black wash) -->
            <div class="absolute inset-0 pointer-events-none"
                 style="background: linear-gradient(to top, rgba(31,29,26,0.72) 0%, rgba(31,29,26,0.30) 42%, rgba(31,29,26,0.05) 72%, transparent 100%);" />

            <!-- Content sits low, inside the scrim, for legibility -->
            <div class="relative z-10 flex h-full flex-col items-center justify-end text-center px-4 pb-24 sm:pb-28 [text-shadow:0_2px_28px_rgba(31,29,26,0.5)]">
                <span class="eyebrow text-bone/90">Ksamil · Bregu Jon</span>
                <span class="mt-4 h-px w-12 bg-brass" />
                <h1 class="text-hero text-bone mt-6 max-w-4xl">Nje shtepi e madhe mbi detin Jon</h1>
                <p class="text-lead text-bone/85 mt-5 max-w-xl">
                    Qetesi, gur i bardhe dhe mikpritje e vertete ne brigjet e Ksamilit.
                </p>
                <div class="flex flex-col sm:flex-row items-center justify-center gap-3 mt-9">
                    <Link href="/book" class="btn-reserve">Rezervo Tani</Link>
                    <Link href="/rooms" class="inline-flex items-center justify-center px-7 py-3 border border-bone/40 text-bone text-sm font-medium tracking-wide hover:bg-bone/10 transition-colors no-underline">
                        Shiko Dhomat
                    </Link>
                </div>
            </div>
        </section>

        <!-- Features -->
        <section class="py-24 bg-limestone/40">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center mb-14">
                    <span class="eyebrow-brass">Pervoja</span>
                    <h2 class="text-display text-ink mt-3">Pse Villa Mucho?</h2>
                    <p class="text-lead text-ink/60 mt-3 measure mx-auto">Cdo detaj eshte menduar per komoditetin tuaj</p>
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
                    <span class="eyebrow-brass">Akomodimi</span>
                    <h2 class="text-display text-ink mt-3">Dhomat &amp; Suitat</h2>
                    <p class="text-lead text-ink/60 mt-3 measure mx-auto">Nje koleksion i vogel suitash, secila me pamjen e vet nga Joni.</p>
                </div>

                <!-- Featured suite -->
                <div v-if="featured" class="grid grid-cols-1 lg:grid-cols-2 border border-driftwood/20 mb-8">
                    <RoomGallery :images="featured.images" :alt="featured.name" aspect="aspect-[4/3] lg:aspect-auto lg:h-full lg:min-h-[420px]" />
                    <div class="p-8 lg:p-12 flex flex-col justify-center">
                        <span class="eyebrow-brass">Suite</span>
                        <h3 class="text-display-sm text-ink mt-3">{{ featured.name }}</h3>
                        <p class="text-body text-ink/60 mt-4 leading-relaxed">{{ featured.description }}</p>
                        <div class="flex flex-wrap gap-x-5 gap-y-2 mt-6">
                            <span v-for="a in (featured.amenities || []).slice(0, 5)" :key="a" class="inline-flex items-center gap-1.5 text-body-sm text-ink/65">
                                <component :is="amenityIcon(a)" class="h-4 w-4 text-ionian" :stroke-width="1.5" /> {{ a }}
                            </span>
                        </div>
                        <div v-if="featured.breakfast_included" class="mt-5 inline-flex items-center gap-2 self-start px-3 py-1.5 bg-ionian/10 text-ionian text-body-sm">
                            <Coffee class="h-4 w-4" :stroke-width="1.5" /> Mengjesi i perfshire
                        </div>
                        <div class="flex items-center justify-between mt-8 pt-6 border-t border-driftwood/15">
                            <p class="text-body-sm text-ink/55">Nga <span class="text-brass text-lg">€{{ featured.base_price }}</span> / nate</p>
                            <Link :href="`/book?room_type=${featured.id}`" class="btn-reserve">Rezervo</Link>
                        </div>
                    </div>
                </div>

                <!-- Standard rooms -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <div v-for="room in standardRooms" :key="room.id" class="group border border-driftwood/20 bg-bone">
                        <RoomGallery :images="room.images" :alt="room.name" />
                        <div class="p-6">
                            <div class="flex items-baseline justify-between gap-3">
                                <h3 class="text-2xl text-ink">{{ room.name }}</h3>
                                <p class="text-body-sm text-ink/55 whitespace-nowrap">Nga <span class="text-brass">€{{ room.base_price }}</span></p>
                            </div>
                            <p class="text-body-sm text-ink/55 mt-2 line-clamp-2">{{ room.description }}</p>
                            <p class="eyebrow text-driftwood mt-4">{{ specRow(room) }}</p>
                            <p v-if="room.breakfast_included" class="mt-3 inline-flex items-center gap-1.5 text-tiny text-ionian">
                                <Coffee class="h-3.5 w-3.5" :stroke-width="1.5" /> Mengjesi i perfshire
                            </p>
                            <Link :href="`/book?room_type=${room.id}`" class="group/lnk mt-5 inline-flex items-center gap-1.5 text-body-sm text-ionian no-underline">
                                Shiko Dhomen <ArrowRight class="h-4 w-4 transition-transform group-hover/lnk:translate-x-1" :stroke-width="1.5" />
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="py-24 bg-ink">
            <div class="max-w-3xl mx-auto px-4 text-center">
                <span class="eyebrow text-brass-light">Rezervo Direkt</span>
                <h2 class="text-display text-bone mt-3">Gati per pushime?</h2>
                <p class="text-lead text-bone/60 mt-3">Cmimi me i mire i garantuar kur rezervon drejtperdrejt me ne.</p>
                <Link href="/book" class="btn-reserve-light mt-8 px-8 py-3.5">
                    Rezervo Tani
                </Link>
            </div>
        </section>
    </WebsiteLayout>
</template>
