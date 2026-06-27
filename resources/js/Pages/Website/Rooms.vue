<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { Users, BedDouble, Coffee } from 'lucide-vue-next';
import WebsiteLayout from '@/Layouts/WebsiteLayout.vue';
import RoomGallery from '@/Components/Website/RoomGallery.vue';
import { amenityIcon } from '@/Components/Website/amenities';

defineProps({ roomTypes: Array });
</script>

<template>
    <Head title="Dhomat — Villa Mucho" />
    <WebsiteLayout>
        <section class="py-20">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center mb-14">
                    <span class="eyebrow-brass">Akomodimi</span>
                    <h1 class="text-display text-ink mt-3">Dhomat &amp; Suitat</h1>
                    <p class="text-lead text-ink/60 mt-3 measure mx-auto">Cdo dhome eshte projektuar per qetesine dhe pamjen nga deti.</p>
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
                                            <p class="text-2xl text-brass leading-none">€{{ room.base_price }}</p>
                                            <p class="text-tiny text-ink/40 uppercase tracking-wider mt-1">/ nate</p>
                                        </div>
                                    </div>
                                    <p class="text-body text-ink/60 mt-4 leading-relaxed">{{ room.description }}</p>

                                    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 mt-5 text-body-sm text-ink/55">
                                        <span class="inline-flex items-center gap-1.5"><Users class="h-4 w-4 text-ionian" :stroke-width="1.5" /> Max {{ room.max_occupancy }} persona</span>
                                        <span class="inline-flex items-center gap-1.5"><BedDouble class="h-4 w-4 text-ionian" :stroke-width="1.5" /> {{ room.rooms_count }} dhoma</span>
                                        <span class="text-ionian font-medium">{{ room.available_count }} te lira</span>
                                    </div>

                                    <div v-if="room.breakfast_included" class="mt-4 inline-flex items-center gap-2 px-3 py-1.5 bg-ionian/10 text-ionian text-body-sm">
                                        <Coffee class="h-4 w-4" :stroke-width="1.5" /> Mengjesi i perfshire
                                    </div>

                                    <!-- Amenity ledger -->
                                    <div class="flex flex-wrap gap-2 mt-5">
                                        <span v-for="a in room.amenities || []" :key="a" class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-driftwood/20 text-body-sm text-ink/70">
                                            <component :is="amenityIcon(a)" class="h-4 w-4 text-ionian" :stroke-width="1.5" /> {{ a }}
                                        </span>
                                    </div>
                                </div>

                                <Link :href="`/book?room_type=${room.id}`" class="btn-reserve mt-8 w-full">
                                    Rezervo {{ room.name }}
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </WebsiteLayout>
</template>
