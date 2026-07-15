<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';
import { Users, BedDouble, Coffee } from 'lucide-vue-next';
import WebsiteLayout from '@/Layouts/WebsiteLayout.vue';
import RoomGallery from '@/Components/Website/RoomGallery.vue';
import { amenityIcon } from '@/Components/Website/amenities';

const brandName = computed(() => usePage().props.settings?.hotel_name || 'Hotel');
const currencySymbol = computed(() => usePage().props.settings?.currency_symbol || '€');

const { t } = useI18n();
const bookingEnabled = computed(() => usePage().props.modules?.booking_engine === true);

defineProps({ roomTypes: Array });

const hasFromPrice = (room) => room?.from_price !== null
    && room?.from_price !== undefined
    && Number(room.from_price) > 0;
</script>

<template>
    <Head :title="$t('rooms.meta.title', { hotel: brandName })" />
    <WebsiteLayout>
        <section class="py-20">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center mb-14">
                    <span class="eyebrow-brass">{{ $t('rooms.hero.eyebrow') }}</span>
                    <h1 class="text-display text-ink mt-3">{{ $t('rooms.hero.title') }}</h1>
                    <p class="text-lead text-ink/60 mt-3 measure mx-auto">{{ $t('rooms.hero.subtitle') }}</p>
                </div>

                <div class="space-y-10">
                    <div v-for="room in roomTypes" :key="room.id" class="border border-driftwood/20 bg-bone overflow-hidden">
                        <div class="grid grid-cols-1 lg:grid-cols-2">
                            <!-- Gallery -->
                            <RoomGallery
                                :images="room.images"
                                :alt="room.name"
                                aspect="aspect-[4/3] lg:aspect-auto lg:h-full lg:min-h-[360px]"
                            />

                            <!-- Info -->
                            <div class="p-8 lg:p-10 flex flex-col justify-between">
                                <div>
                                    <div class="flex items-start justify-between gap-4">
                                        <h2 class="text-display-sm text-ink">{{ room.name }}</h2>
                                        <div class="text-right shrink-0">
                                            <template v-if="hasFromPrice(room)">
                                                <p class="text-tiny text-ink/40 uppercase tracking-wider" :title="$t('rooms.card.fromPriceHint')">{{ $t('rooms.card.priceFrom') }}</p>
                                                <p class="text-2xl text-brass leading-none">{{ currencySymbol }}{{ room.from_price }}</p>
                                                <p class="text-tiny text-ink/40 uppercase tracking-wider mt-1">{{ $t('rooms.card.perNight') }}</p>
                                            </template>
                                            <p v-else class="max-w-28 text-body-sm font-medium text-ionian">{{ $t('rooms.card.checkDates') }}</p>
                                        </div>
                                    </div>
                                    <p class="text-body text-ink/60 mt-4 leading-relaxed">{{ room.description }}</p>

                                    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 mt-5 text-body-sm text-ink/55">
                                        <span class="inline-flex items-center gap-1.5"><Users class="h-4 w-4 text-ionian" :stroke-width="1.5" /> {{ $t('rooms.card.maxOccupancy', { count: room.max_occupancy }) }}</span>
                                        <span class="inline-flex items-center gap-1.5"><BedDouble class="h-4 w-4 text-ionian" :stroke-width="1.5" /> {{ $t('rooms.card.roomsCount', { count: room.rooms_count }) }}</span>
                                        <span class="text-ionian font-medium">{{ $t('rooms.card.available', { count: room.available_count }) }}</span>
                                    </div>

                                    <div v-if="room.breakfast_included" class="mt-4 inline-flex items-center gap-2.5 px-4 py-2 bg-ionian text-bone text-body-sm font-medium tracking-wide shadow-sm">
                                        <Coffee class="h-5 w-5" :stroke-width="1.75" /> {{ $t('rooms.card.breakfastIncluded') }}
                                    </div>

                                    <!-- Amenity ledger -->
                                    <div class="flex flex-wrap gap-2 mt-5">
                                        <span v-for="a in room.amenities || []" :key="a" class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-driftwood/20 text-body-sm text-ink/70">
                                            <component :is="amenityIcon(a)" class="h-4 w-4 text-ionian" :stroke-width="1.5" /> {{ a }}
                                        </span>
                                    </div>
                                </div>

                                <Link v-if="bookingEnabled" :href="`/book?room_type=${room.id}`" class="btn-reserve mt-8 w-full">
                                    {{ $t('rooms.card.reserve', { name: room.name }) }}
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </WebsiteLayout>
</template>
