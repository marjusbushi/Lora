<script setup>
import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { MapPin, Phone, Mail } from 'lucide-vue-next';
import WebsiteLayout from '@/Layouts/WebsiteLayout.vue';

const brandName = computed(() => usePage().props.settings?.hotel_name || 'Hotel');

const { t } = useI18n();

const props = defineProps({ hotel: Object });

// The map accepts either a ready Google Maps "embed" URL or a plain
// address / place name (e.g. "Villa Mucho, Ksamil") which we turn into a
// keyless embed automatically. Empty → a Ksamil fallback.
const mapSrc = computed(() => {
    const v = (props.hotel?.maps_url || '').trim();
    if (!v) return 'https://www.google.com/maps?q=Ksamil,Sarande,Albania&output=embed';
    if (/output=embed|\/maps\/embed/i.test(v)) return v;
    return `https://www.google.com/maps?q=${encodeURIComponent(v)}&output=embed`;
});

// Contact details → actionable links
const addr = computed(() => props.hotel?.address || '');
const phone = computed(() => props.hotel?.phone || '');
const email = computed(() => props.hotel?.email || '');
const telHref = computed(() => 'tel:' + phone.value.replace(/[^+\d]/g, ''));
const mailHref = computed(() => 'mailto:' + email.value);
const mapsDest = computed(() => {
    const m = (props.hotel?.maps_url || '').trim();
    return (m && !/^https?:|output=embed|\/maps\/embed/i.test(m)) ? m : addr.value;
});
const directionsHref = computed(() => 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(mapsDest.value));

const flash = usePage().props.flash;

const form = useForm({
    name: '',
    email: '',
    message: '',
    website: '', // honeypot — must stay empty
});

function submit() {
    form.post('/contact', {
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head :title="$t('contact.meta.title', { hotel: brandName })" />
    <WebsiteLayout>
        <section class="py-16">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center mb-12">
                    <h1 class="text-h1 text-primary-900">{{ $t('contact.heading.title') }}</h1>
                    <p class="text-body text-neutral-500 mt-2">{{ $t('contact.heading.subtitle') }}</p>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-12">
                    <!-- Form -->
                    <div class="bg-white rounded-2xl border border-neutral-100 p-6 sm:p-8">
                        <div v-if="flash?.success" class="mb-4 p-3 rounded-lg bg-ionian/10 border border-ionian/20 text-body-sm text-ionian-dark">
                            {{ flash.success }}
                        </div>

                        <form @submit.prevent="submit" class="space-y-4">
                            <!-- Honeypot: hidden from humans, bots fill it -->
                            <input v-model="form.website" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" class="absolute -left-[9999px] h-0 w-0 opacity-0" />
                            <div>
                                <label class="block text-label text-neutral-700 mb-1.5">{{ $t('contact.form.name.label') }}</label>
                                <input v-model="form.name" type="text" :placeholder="$t('contact.form.name.placeholder')" class="w-full rounded-lg border border-neutral-200 px-3 py-2.5 text-body-sm focus:border-ionian focus:ring-2 focus:ring-ionian/30" />
                                <p v-if="form.errors.name" class="text-small text-error-600 mt-1">{{ form.errors.name }}</p>
                            </div>
                            <div>
                                <label class="block text-label text-neutral-700 mb-1.5">{{ $t('contact.form.email.label') }}</label>
                                <input v-model="form.email" type="email" :placeholder="$t('contact.form.email.placeholder')" class="w-full rounded-lg border border-neutral-200 px-3 py-2.5 text-body-sm focus:border-ionian focus:ring-2 focus:ring-ionian/30" />
                                <p v-if="form.errors.email" class="text-small text-error-600 mt-1">{{ form.errors.email }}</p>
                            </div>
                            <div>
                                <label class="block text-label text-neutral-700 mb-1.5">{{ $t('contact.form.message.label') }}</label>
                                <textarea v-model="form.message" rows="5" :placeholder="$t('contact.form.message.placeholder')" class="w-full rounded-lg border border-neutral-200 px-3 py-2.5 text-body-sm focus:border-ionian focus:ring-2 focus:ring-ionian/30" />
                                <p v-if="form.errors.message" class="text-small text-error-600 mt-1">{{ form.errors.message }}</p>
                            </div>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="btn-reserve w-full"
                            >
                                {{ form.processing ? $t('contact.form.submitting') : $t('contact.form.submit') }}
                            </button>
                        </form>
                    </div>

                    <!-- Info -->
                    <div class="space-y-6">
                        <div class="bg-neutral-50 rounded-2xl p-6">
                            <h3 class="text-h4 text-primary-900 mb-4">{{ $t('contact.info.title') }}</h3>
                            <div class="space-y-4">
                                <div class="flex items-start gap-3.5">
                                    <MapPin class="h-5 w-5 mt-0.5 text-ionian shrink-0" :stroke-width="1.5" />
                                    <div>
                                        <p class="text-label text-ink/80">{{ $t('contact.info.address') }}</p>
                                        <a :href="directionsHref" target="_blank" rel="noopener" class="text-body-sm text-ionian hover:text-ionian-dark no-underline">{{ addr }}</a>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3.5">
                                    <Phone class="h-5 w-5 mt-0.5 text-ionian shrink-0" :stroke-width="1.5" />
                                    <div>
                                        <p class="text-label text-ink/80">{{ $t('contact.info.phone') }}</p>
                                        <a :href="telHref" class="text-body-sm text-ionian hover:text-ionian-dark no-underline">{{ phone }}</a>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3.5">
                                    <Mail class="h-5 w-5 mt-0.5 text-ionian shrink-0" :stroke-width="1.5" />
                                    <div>
                                        <p class="text-label text-ink/80">{{ $t('contact.info.email') }}</p>
                                        <a :href="mailHref" class="text-body-sm text-ionian hover:text-ionian-dark no-underline">{{ email }}</a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Map -->
                        <div class="overflow-hidden h-64 bg-limestone border border-driftwood/20">
                            <iframe
                                :src="mapSrc"
                                width="100%"
                                height="100%"
                                style="border:0;"
                                allowfullscreen
                                loading="lazy"
                                referrerpolicy="no-referrer-when-downgrade"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </WebsiteLayout>
</template>
