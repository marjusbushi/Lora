<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { Waves, UtensilsCrossed } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import WebsiteLayout from '@/Layouts/WebsiteLayout.vue';

const brandName = computed(() => usePage().props.settings?.hotel_name || 'Hotel');

const props = defineProps({ hotel: Object, about: Object });

const { t, locale } = useI18n();

// Owner-managed content (Settings → "Faqja: Rreth Nesh"), with i18n fallback so
// an unconfigured page still renders fully. Bilingual keys are `<key>_<sq|en>`;
// fall back to the Albanian value, then to the built-in translated default.
function txt(key, fallbackKey) {
    const a = props.about || {};
    const v = a[`${key}_${locale.value}`] ?? a[`${key}_sq`];
    return (v && String(v).trim()) ? v : t(fallbackKey);
}
function val(key, fallback) {
    const v = props.about?.[key];
    return (v !== undefined && v !== null && String(v).trim()) ? v : fallback;
}
function img(key) {
    const p = props.about?.[key];
    return p ? `/storage/${p}` : null;
}

const HERO_FALLBACK = 'https://images.unsplash.com/photo-1520250497591-112f2f40a3f4?w=1920&q=80';
const heroBg = computed(() => img('hero_image') || HERO_FALLBACK);
const storyImage = computed(() => img('story_image'));
const staffImage = computed(() => img('staff_image'));
</script>

<template>
    <Head :title="$t('about.meta.title', { hotel: brandName })" />
    <WebsiteLayout>
        <!-- Hero -->
        <section class="relative h-[40vh] min-h-[300px] flex items-center justify-center bg-primary-950">
            <div class="absolute inset-0 bg-gradient-to-b from-primary-950/60 to-primary-950/90 z-10" />
            <div class="absolute inset-0 bg-cover bg-center" :style="{ backgroundImage: `url(${heroBg})` }" />
            <h1 class="relative z-20 text-4xl sm:text-5xl font-bold text-white">{{ txt('hero_title', 'about.hero.title') }}</h1>
        </section>

        <section class="py-16">
            <div class="max-w-4xl mx-auto px-4 sm:px-6">
                <div class="prose prose-lg max-w-none">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-12 items-center mb-16">
                        <div>
                            <h2 class="text-h2 text-primary-900 mb-4">{{ txt('story_title', 'about.story.title') }}</h2>
                            <p class="text-body text-neutral-600 mb-3 whitespace-pre-line">
                                {{ txt('story_p1', 'about.story.paragraph1') }}
                            </p>
                            <p class="text-body text-neutral-600 whitespace-pre-line">
                                {{ txt('story_p2', 'about.story.paragraph2') }}
                            </p>
                        </div>
                        <div class="relative h-64 md:h-80 overflow-hidden bg-limestone">
                            <img v-if="storyImage" :src="storyImage" :alt="txt('story_title', 'about.story.title')" class="absolute inset-0 h-full w-full object-cover" />
                            <div v-else class="absolute inset-0 flex flex-col items-center justify-center gap-2.5 text-driftwood">
                                <Waves class="h-9 w-9" :stroke-width="1.1" />
                                <span class="eyebrow text-driftwood/80">{{ $t('about.photoSoon') }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-16">
                        <div v-if="val('stat1_value', '15+')" class="text-center p-8 bg-limestone/40 border border-driftwood/15">
                            <p class="text-h1 text-brass">{{ val('stat1_value', '15+') }}</p>
                            <p class="text-body-sm text-neutral-500 mt-1">{{ txt('stat1_label', 'about.stats.rooms') }}</p>
                        </div>
                        <div v-if="val('stat2_value', '500+')" class="text-center p-8 bg-limestone/40 border border-driftwood/15">
                            <p class="text-h1 text-brass">{{ val('stat2_value', '500+') }}</p>
                            <p class="text-body-sm text-neutral-500 mt-1">{{ txt('stat2_label', 'about.stats.guests') }}</p>
                        </div>
                        <div v-if="val('stat3_value', '4.8')" class="text-center p-8 bg-limestone/40 border border-driftwood/15">
                            <p class="text-h1 text-brass">{{ val('stat3_value', '4.8') }}</p>
                            <p class="text-body-sm text-neutral-500 mt-1">{{ txt('stat3_label', 'about.stats.rating') }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-12 items-center">
                        <div class="relative h-64 md:h-80 overflow-hidden bg-limestone order-2 md:order-1">
                            <img v-if="staffImage" :src="staffImage" :alt="txt('staff_title', 'about.staff.title')" class="absolute inset-0 h-full w-full object-cover" />
                            <div v-else class="absolute inset-0 flex flex-col items-center justify-center gap-2.5 text-driftwood">
                                <UtensilsCrossed class="h-9 w-9" :stroke-width="1.1" />
                                <span class="eyebrow text-driftwood/80">{{ $t('about.photoSoon') }}</span>
                            </div>
                        </div>
                        <div class="order-1 md:order-2">
                            <h2 class="text-h2 text-primary-900 mb-4">{{ txt('staff_title', 'about.staff.title') }}</h2>
                            <p class="text-body text-neutral-600 mb-3 whitespace-pre-line">
                                {{ txt('staff_p1', 'about.staff.paragraph1') }}
                            </p>
                            <p class="text-body text-neutral-600 whitespace-pre-line">
                                {{ txt('staff_p2', 'about.staff.paragraph2') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </WebsiteLayout>
</template>
