<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import {
    ArrowLeft, BedDouble, BookOpen, Check, ExternalLink, Facebook, Globe, House,
    Instagram, Mail, MapPin, MessageCircle, Palette, Phone,
} from 'lucide-vue-next';

const props = defineProps({
    publicUrl: { type: String, default: null },
    home: { type: Object, required: true },
    brand: { type: Object, required: true },
    contact: { type: Object, required: true },
    about: { type: Object, required: true },
    hotelName: { type: String, default: '' },
    roomTypes: { type: Array, default: () => [] },
    completeness: { type: Object, default: () => ({}) },
});

const page = usePage();
const { t } = useI18n();

// ── Sajti publik: domain-i dhe linku "Hap faqen" ────────────────────────────
// I SERVERIT (domain-et e regjistruara të tenant-it — Codex PR #564); heqja e
// 'admin.' nga host-i mbetet vetëm fallback për mjediset dev pa domain.
const publicOrigin = computed(() => {
    if (props.publicUrl) return props.publicUrl;
    const host = window.location.hostname.replace(/^admin\./, '');
    const port = window.location.port ? `:${window.location.port}` : '';

    return `${window.location.protocol}//${host}${port}`;
});
const publicDomain = computed(() => publicOrigin.value.replace(/^https?:\/\//, ''));

// ── Gjuha e fushave dygjuhëshe (një toggle për gjithçka) ────────────────────
const editLang = ref('sq');

// ── Format — një për çdo faqe; ruhet vetëm seksioni aktiv ───────────────────
const homeForm = useForm({
    hero_eyebrow_sq: props.home.hero_eyebrow_sq || '',
    hero_eyebrow_en: props.home.hero_eyebrow_en || '',
    hero_title_sq: props.home.hero_title_sq || '',
    hero_title_en: props.home.hero_title_en || '',
    hero_subtitle_sq: props.home.hero_subtitle_sq || '',
    hero_subtitle_en: props.home.hero_subtitle_en || '',
    hero_image: null,
});
const brandForm = useForm({ logo: null });
const contactForm = useForm({
    address: props.contact.address || '',
    phone: props.contact.phone || '',
    email: props.contact.email || '',
    whatsapp_number: props.contact.whatsapp_number || '',
    instagram: props.contact.instagram || '',
    facebook: props.contact.facebook || '',
    maps_url: props.contact.maps_url || '',
});
const aboutForm = useForm({
    hero_title_sq: props.about.hero_title_sq || '',
    hero_title_en: props.about.hero_title_en || '',
    hero_image: null,
    story_title_sq: props.about.story_title_sq || '',
    story_title_en: props.about.story_title_en || '',
    story_p1_sq: props.about.story_p1_sq || '',
    story_p1_en: props.about.story_p1_en || '',
    story_p2_sq: props.about.story_p2_sq || '',
    story_p2_en: props.about.story_p2_en || '',
    story_image: null,
    staff_title_sq: props.about.staff_title_sq || '',
    staff_title_en: props.about.staff_title_en || '',
    staff_p1_sq: props.about.staff_p1_sq || '',
    staff_p1_en: props.about.staff_p1_en || '',
    staff_p2_sq: props.about.staff_p2_sq || '',
    staff_p2_en: props.about.staff_p2_en || '',
    staff_image: null,
    // Shifrat e faqes About — DUHET të udhëtojnë me formën: endpoint-i i
    // shkruan të gjithë çelësat e vet dhe një fushë e munguar do i fshinte
    // vlerat e personalizuara (gjetje Codex P1, PR #562).
    stat1_value: props.about.stat1_value || '',
    stat1_label_sq: props.about.stat1_label_sq || '',
    stat1_label_en: props.about.stat1_label_en || '',
    stat2_value: props.about.stat2_value || '',
    stat2_label_sq: props.about.stat2_label_sq || '',
    stat2_label_en: props.about.stat2_label_en || '',
    stat3_value: props.about.stat3_value || '',
    stat3_label_sq: props.about.stat3_label_sq || '',
    stat3_label_en: props.about.stat3_label_en || '',
});

const activeSection = ref('home');
const SECTION_FORMS = { home: homeForm, brand: brandForm, contact: contactForm, about: aboutForm };
const activeForm = computed(() => SECTION_FORMS[activeSection.value] || null);

function save() {
    if (activeSection.value === 'home') {
        homeForm.post(route('web-studio.home'), { preserveScroll: true, forceFormData: true, onSuccess: () => { homeForm.hero_image = null; } });
    } else if (activeSection.value === 'brand') {
        brandForm.post(route('web-studio.brand'), { preserveScroll: true, forceFormData: true, onSuccess: () => { brandForm.logo = null; } });
    } else if (activeSection.value === 'contact') {
        contactForm.put(route('web-studio.contact'), { preserveScroll: true });
    } else if (activeSection.value === 'about') {
        aboutForm.post(route('settings.about'), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => { aboutForm.hero_image = null; aboutForm.story_image = null; aboutForm.staff_image = null; },
        });
    }
}

// ── Parapamje imazhesh: file i sapo-zgjedhur > i ruajturi > fallback ────────
// Çelësi i parapamjes mban EDHE seksionin ('home.hero_image', 'about.hero_image')
// — dy forma me të njëjtin emër fushe s'e mbishkruajnë njëra-tjetrën
// (gjetje Codex P2, PR #562).
const previews = ref({});

function pickImage(form, field, event, previewKey) {
    const file = event.target.files?.[0];
    if (!file) return;
    form[field] = file;
    if (previews.value[previewKey]) URL.revokeObjectURL(previews.value[previewKey]);
    previews.value[previewKey] = URL.createObjectURL(file);
}

function imageUrl(previewKey, storedPath) {
    if (previews.value[previewKey]) return previews.value[previewKey];

    return storedPath ? `/storage/${storedPath}` : null;
}

// ── Tekstet e hero-s me fallback-un IDENTIK të faqes publike (Home.vue) ─────
const HERO_DEFAULTS = {
    eyebrow: { sq: 'Ksamil · Bregu Jon', en: 'Ksamil · Ionian Shore' },
    title: { sq: 'Nje shtepi e madhe mbi detin Jon', en: 'A grand house above the Ionian Sea' },
    subtitle: {
        sq: 'Qetesi, gur i bardhe dhe mikpritje e vertete ne brigjet e Ksamilit.',
        en: 'Calm, white stone and true hospitality on the shores of Ksamil.',
    },
};

function heroPreviewText(field) {
    const lc = editLang.value;

    return homeForm[`hero_${field}_${lc}`] || homeForm[`hero_${field}_sq`] || HERO_DEFAULTS[field][lc] || HERO_DEFAULTS[field].sq;
}

const heroImageUrl = computed(() => imageUrl('home.hero_image', props.home.hero_image));
const logoUrl = computed(() => imageUrl('brand.logo', props.brand.logo));
const priceSymbol = computed(() => page.props.settings?.pricing_currency_symbol || '€');

const sections = computed(() => [
    { id: 'home', icon: House, labelKey: 'webStudio.sectionHome', hintKey: 'webStudio.sectionHomeHint', ok: props.completeness.home },
    { id: 'rooms', icon: BedDouble, labelKey: 'webStudio.sectionRooms', hint: `${props.roomTypes.length} ${t('webStudio.roomTypesCount')}`, ok: props.roomTypes.length > 0 },
    { id: 'about', icon: BookOpen, labelKey: 'webStudio.sectionAbout', hintKey: 'webStudio.sectionAboutHint', ok: props.completeness.about },
    { id: 'contact', icon: Phone, labelKey: 'webStudio.sectionContact', hintKey: 'webStudio.sectionContactHint', ok: props.completeness.contact },
    { id: 'brand', icon: Palette, labelKey: 'webStudio.sectionBrand', hintKey: 'webStudio.sectionBrandHint', ok: props.completeness.brand },
]);

// Fushë dygjuhëshe: emri i fushës sipas gjuhës aktive të editimit.
const lf = (base) => `${base}_${editLang.value}`;
</script>

<template>
    <Head title="Web Studio" />
    <!-- immersive: fsheh sidebar-in + topbar-in e adminit, POR mban poller-in e
         mesazheve të AppLayout (zilja/titulli kur shkruan mysafiri) — gjetje
         Codex P1, PR #563: focus mode s'duhet të të bëjë të humbasësh mesazhet. -->
    <AppLayout immersive>
    <div class="flex h-full min-h-0 flex-col bg-neutral-50">
        <!-- Shiriti i studios — i vetmi chrome: dil, identiteti, gjuha, hap faqen -->
        <header class="z-30 shrink-0 border-b border-neutral-200 bg-white/95 shadow-sm backdrop-blur">
            <div class="mx-auto flex max-w-[1480px] flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5 sm:px-6">
                <Link :href="route('settings.index')" class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-semibold text-neutral-500 transition hover:bg-neutral-100 hover:text-neutral-800">
                    <ArrowLeft class="h-4 w-4" /> {{ $t('webStudio.backToSettings') }}
                </Link>
                <span class="hidden h-5 w-px bg-neutral-200 sm:block" />
                <div class="flex min-w-0 items-center gap-2.5">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-accent-50 text-accent-700"><Globe class="h-5 w-5" :stroke-width="1.8" /></span>
                    <div class="min-w-0 leading-tight">
                        <b class="block truncate text-sm font-extrabold tracking-tight text-neutral-900">{{ hotelName || 'Web Studio' }}</b>
                        <span class="flex items-center gap-2 text-xs text-neutral-500">
                            <span class="truncate">{{ publicDomain }}</span>
                            <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700"><span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500" /> Live</span>
                        </span>
                    </div>
                </div>
                <div class="ml-auto flex items-center gap-3">
                    <div class="flex rounded-lg bg-neutral-100 p-0.5">
                        <button type="button" class="rounded-md px-3.5 py-1.5 text-xs font-bold transition" :class="editLang === 'sq' ? 'bg-white text-accent-800 shadow-sm' : 'text-neutral-500'" @click="editLang = 'sq'">Shqip</button>
                        <button type="button" class="rounded-md px-3.5 py-1.5 text-xs font-bold transition" :class="editLang === 'en' ? 'bg-white text-accent-800 shadow-sm' : 'text-neutral-500'" @click="editLang = 'en'">English</button>
                    </div>
                    <a :href="publicOrigin" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-lg border border-neutral-300 bg-white px-3.5 py-1.5 text-sm font-semibold text-neutral-700 transition hover:border-accent-500 hover:text-accent-700">
                        {{ $t('webStudio.openSite') }} <ExternalLink class="h-4 w-4" />
                    </a>
                </div>
            </div>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto">
        <main class="pms-settings-shell mx-auto w-full max-w-[1480px] px-4 pb-10 pt-5 sm:px-6">
                    <div class="grid items-start gap-4 xl:grid-cols-[230px_minmax(0,1fr)_400px]">

                        <!-- RAIL: faqet e sajtit -->
                        <nav data-ui="card" class="border border-neutral-200 bg-white p-2.5">
                            <p class="px-2 pb-1.5 pt-1 text-tiny font-bold uppercase tracking-wide text-neutral-400">{{ $t('webStudio.sitePages') }}</p>
                            <button v-for="section in sections" :key="section.id" type="button" class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2.5 text-left transition" :class="activeSection === section.id ? 'bg-accent-50 text-accent-800' : 'text-neutral-600 hover:bg-neutral-50'" @click="activeSection = section.id">
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg" :class="activeSection === section.id ? 'bg-white text-accent-700' : 'bg-neutral-50 text-neutral-500'"><component :is="section.icon" class="h-4 w-4" /></span>
                                <span class="min-w-0 flex-1">
                                    <b class="block truncate text-sm font-semibold">{{ $t(section.labelKey) }}</b>
                                    <small class="block truncate text-tiny font-medium text-neutral-400">{{ section.hintKey ? $t(section.hintKey) : section.hint }}</small>
                                </span>
                                <span class="h-2 w-2 shrink-0 rounded-full" :class="section.ok ? 'bg-emerald-500' : 'bg-amber-400'" :title="section.ok ? $t('webStudio.complete') : $t('webStudio.incomplete')" />
                            </button>
                        </nav>

                        <!-- FORMA E SEKSIONIT -->
                        <form class="min-w-0 space-y-4" @submit.prevent="save">

                            <!-- KREU -->
                            <section v-if="activeSection === 'home'" data-ui="card" class="settings-card-pad border border-neutral-200 bg-white">
                                <h2 data-ui="card-title">{{ $t('webStudio.homeTitle') }}</h2>
                                <p class="mt-1 text-body-sm text-neutral-500">{{ $t('webStudio.homeSubtitle') }}</p>

                                <div class="mt-4">
                                    <p class="mb-2 text-tiny font-bold uppercase tracking-wide text-neutral-400">{{ $t('webStudio.heroPhoto') }}</p>
                                    <div class="flex flex-wrap items-center gap-4">
                                        <div class="h-24 w-40 shrink-0 overflow-hidden rounded-xl border border-neutral-200 bg-neutral-100">
                                            <img v-if="heroImageUrl" :src="heroImageUrl" alt="" class="h-full w-full object-cover">
                                            <div v-else class="grid h-full w-full place-items-center text-tiny text-neutral-400">{{ $t('webStudio.noPhoto') }}</div>
                                        </div>
                                        <div>
                                            <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-neutral-300 px-3.5 py-2 text-sm font-semibold text-neutral-700 hover:border-accent-500 hover:text-accent-700">
                                                {{ $t('webStudio.changePhoto') }}
                                                <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="pickImage(homeForm, 'hero_image', $event, 'home.hero_image')">
                                            </label>
                                            <p class="mt-2 text-xs text-neutral-400">{{ $t('webStudio.heroPhotoHint') }}</p>
                                            <p v-if="homeForm.errors.hero_image" class="mt-1 text-xs font-semibold text-red-600">{{ homeForm.errors.hero_image }}</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-5 border-t border-neutral-100 pt-4">
                                    <p class="mb-2 text-tiny font-bold uppercase tracking-wide text-neutral-400">{{ $t('webStudio.heroTexts') }} — {{ editLang === 'sq' ? 'Shqip' : 'English' }}</p>
                                    <div class="space-y-3">
                                        <label class="block">
                                            <span class="text-xs font-bold text-neutral-500">{{ $t('webStudio.eyebrow') }}</span>
                                            <input v-model="homeForm[lf('hero_eyebrow')]" type="text" maxlength="120" class="mt-1 w-full rounded-lg border-neutral-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-bold text-neutral-500">{{ $t('webStudio.bigTitle') }}</span>
                                            <input v-model="homeForm[lf('hero_title')]" type="text" maxlength="200" class="mt-1 w-full rounded-lg border-neutral-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-bold text-neutral-500">{{ $t('webStudio.subtitleField') }}</span>
                                            <input v-model="homeForm[lf('hero_subtitle')]" type="text" maxlength="400" class="mt-1 w-full rounded-lg border-neutral-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                                        </label>
                                    </div>
                                    <p class="mt-2 text-xs text-neutral-400">{{ $t('webStudio.emptyMeansDefault') }}</p>
                                </div>
                            </section>

                            <!-- DHOMAT (read-only + link) -->
                            <section v-else-if="activeSection === 'rooms'" data-ui="card" class="settings-card-pad border border-neutral-200 bg-white">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h2 data-ui="card-title">{{ $t('webStudio.roomsTitle') }}</h2>
                                        <p class="mt-1 text-body-sm text-neutral-500">{{ $t('webStudio.roomsSubtitle') }}</p>
                                    </div>
                                    <a data-ui="button" :href="route('settings.index', { tab: 'room-types' })" class="inline-flex items-center gap-2 bg-accent-700 px-4 text-white hover:bg-accent-800">
                                        {{ $t('webStudio.editRooms') }} <ExternalLink class="h-4 w-4" />
                                    </a>
                                </div>
                                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                    <div v-for="type in roomTypes" :key="type.id" class="overflow-hidden rounded-xl border border-neutral-200">
                                        <div class="h-24 bg-neutral-100">
                                            <img v-if="type.image" :src="`/storage/${type.image}`" alt="" class="h-full w-full object-cover">
                                            <div v-else class="grid h-full w-full place-items-center text-tiny text-amber-600">{{ $t('webStudio.roomNoPhoto') }}</div>
                                        </div>
                                        <div class="flex items-center justify-between gap-2 px-3 py-2.5">
                                            <b class="min-w-0 flex-1 truncate text-sm font-semibold text-neutral-900">{{ type.name }}</b>
                                            <span v-if="type.from_price !== null" class="shrink-0 rounded-md bg-accent-50 px-2 py-0.5 text-xs font-bold text-accent-800">{{ $t('webStudio.fromPrice') }} {{ priceSymbol }}{{ type.from_price }}</span><span v-else class="shrink-0 text-tiny text-neutral-400">{{ $t('webStudio.noAvailability') }}</span>
                                        </div>
                                    </div>
                                </div>
                                <p v-if="!roomTypes.length" class="mt-4 text-sm text-neutral-500">{{ $t('webStudio.noRooms') }}</p>
                            </section>

                            <!-- RRETH NESH -->
                            <section v-else-if="activeSection === 'about'" data-ui="card" class="settings-card-pad border border-neutral-200 bg-white">
                                <h2 data-ui="card-title">{{ $t('webStudio.aboutTitle') }}</h2>
                                <p class="mt-1 text-body-sm text-neutral-500">{{ $t('webStudio.aboutSubtitle') }} — {{ editLang === 'sq' ? 'Shqip' : 'English' }}</p>

                                <div class="mt-4 space-y-5">
                                    <div>
                                        <p class="mb-2 text-tiny font-bold uppercase tracking-wide text-neutral-400">1 · {{ $t('webStudio.aboutHero') }}</p>
                                        <label class="block">
                                            <span class="text-xs font-bold text-neutral-500">{{ $t('webStudio.titleField') }}</span>
                                            <input v-model="aboutForm[lf('hero_title')]" type="text" maxlength="200" class="mt-1 w-full rounded-lg border-neutral-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                                        </label>
                                        <label class="mt-2 inline-flex cursor-pointer items-center gap-2 rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-semibold text-neutral-600 hover:border-accent-500 hover:text-accent-700">
                                            {{ $t('webStudio.changePhoto') }}
                                            <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="pickImage(aboutForm, 'hero_image', $event, 'about.hero_image')">
                                        </label>
                                        <span v-if="aboutForm.hero_image || about.hero_image" class="ml-2 align-middle text-tiny text-emerald-600"><Check class="inline h-3.5 w-3.5" /> {{ $t('webStudio.photoSet') }}</span>
                                    </div>

                                    <div class="border-t border-neutral-100 pt-4">
                                        <p class="mb-2 text-tiny font-bold uppercase tracking-wide text-neutral-400">2 · {{ $t('webStudio.aboutStory') }}</p>
                                        <div class="space-y-3">
                                            <label class="block">
                                                <span class="text-xs font-bold text-neutral-500">{{ $t('webStudio.titleField') }}</span>
                                                <input v-model="aboutForm[lf('story_title')]" type="text" maxlength="200" class="mt-1 w-full rounded-lg border-neutral-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                                            </label>
                                            <label class="block">
                                                <span class="text-xs font-bold text-neutral-500">{{ $t('webStudio.paragraph') }} 1</span>
                                                <textarea v-model="aboutForm[lf('story_p1')]" rows="3" maxlength="1500" class="mt-1 w-full rounded-lg border-neutral-300 text-sm focus:border-accent-500 focus:ring-accent-500"></textarea>
                                            </label>
                                            <label class="block">
                                                <span class="text-xs font-bold text-neutral-500">{{ $t('webStudio.paragraph') }} 2</span>
                                                <textarea v-model="aboutForm[lf('story_p2')]" rows="3" maxlength="1500" class="mt-1 w-full rounded-lg border-neutral-300 text-sm focus:border-accent-500 focus:ring-accent-500"></textarea>
                                            </label>
                                            <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-semibold text-neutral-600 hover:border-accent-500 hover:text-accent-700">
                                                {{ $t('webStudio.changePhoto') }}
                                                <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="pickImage(aboutForm, 'story_image', $event, 'about.story_image')">
                                            </label>
                                            <span v-if="aboutForm.story_image || about.story_image" class="ml-2 align-middle text-tiny text-emerald-600"><Check class="inline h-3.5 w-3.5" /> {{ $t('webStudio.photoSet') }}</span>
                                        </div>
                                    </div>

                                    <div class="border-t border-neutral-100 pt-4">
                                        <p class="mb-2 text-tiny font-bold uppercase tracking-wide text-neutral-400">3 · {{ $t('webStudio.aboutStaff') }}</p>
                                        <div class="space-y-3">
                                            <label class="block">
                                                <span class="text-xs font-bold text-neutral-500">{{ $t('webStudio.titleField') }}</span>
                                                <input v-model="aboutForm[lf('staff_title')]" type="text" maxlength="200" class="mt-1 w-full rounded-lg border-neutral-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                                            </label>
                                            <label class="block">
                                                <span class="text-xs font-bold text-neutral-500">{{ $t('webStudio.paragraph') }} 1</span>
                                                <textarea v-model="aboutForm[lf('staff_p1')]" rows="3" maxlength="1500" class="mt-1 w-full rounded-lg border-neutral-300 text-sm focus:border-accent-500 focus:ring-accent-500"></textarea>
                                            </label>
                                            <label class="block">
                                                <span class="text-xs font-bold text-neutral-500">{{ $t('webStudio.paragraph') }} 2</span>
                                                <textarea v-model="aboutForm[lf('staff_p2')]" rows="3" maxlength="1500" class="mt-1 w-full rounded-lg border-neutral-300 text-sm focus:border-accent-500 focus:ring-accent-500"></textarea>
                                            </label>
                                            <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-semibold text-neutral-600 hover:border-accent-500 hover:text-accent-700">
                                                {{ $t('webStudio.changePhoto') }}
                                                <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="pickImage(aboutForm, 'staff_image', $event, 'about.staff_image')">
                                            </label>
                                            <span v-if="aboutForm.staff_image || about.staff_image" class="ml-2 align-middle text-tiny text-emerald-600"><Check class="inline h-3.5 w-3.5" /> {{ $t('webStudio.photoSet') }}</span>
                                        </div>
                                    </div>

                                    <div class="border-t border-neutral-100 pt-4">
                                        <p class="mb-2 text-tiny font-bold uppercase tracking-wide text-neutral-400">4 · {{ $t('webStudio.aboutStats') }}</p>
                                        <div class="grid gap-3 sm:grid-cols-3">
                                            <div v-for="n in 3" :key="n" class="rounded-xl border border-neutral-200 p-3">
                                                <label class="block">
                                                    <span class="text-xs font-bold text-neutral-500">{{ $t('webStudio.statValue') }}</span>
                                                    <input v-model="aboutForm[`stat${n}_value`]" type="text" maxlength="30" placeholder="15+" class="mt-1 w-full rounded-lg border-neutral-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                                                </label>
                                                <label class="mt-2 block">
                                                    <span class="text-xs font-bold text-neutral-500">{{ $t('webStudio.statLabel') }}</span>
                                                    <input v-model="aboutForm[lf(`stat${n}_label`)]" type="text" maxlength="200" class="mt-1 w-full rounded-lg border-neutral-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                                                </label>
                                            </div>
                                        </div>
                                        <p class="mt-2 text-xs text-neutral-400">{{ $t('webStudio.emptyMeansDefault') }}</p>
                                    </div>
                                </div>
                            </section>

                            <!-- KONTAKT & RRJETET -->
                            <section v-else-if="activeSection === 'contact'" data-ui="card" class="settings-card-pad border border-neutral-200 bg-white">
                                <h2 data-ui="card-title">{{ $t('webStudio.contactTitle') }}</h2>
                                <p class="mt-1 text-body-sm text-neutral-500">{{ $t('webStudio.contactSubtitle') }}</p>
                                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                    <label class="block sm:col-span-2">
                                        <span class="text-xs font-bold text-neutral-500">{{ $t('webStudio.address') }}</span>
                                        <input v-model="contactForm.address" type="text" maxlength="500" class="mt-1 w-full rounded-lg border-neutral-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-bold text-neutral-500">{{ $t('webStudio.phone') }}</span>
                                        <input v-model="contactForm.phone" type="text" maxlength="30" class="mt-1 w-full rounded-lg border-neutral-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-bold text-neutral-500">Email</span>
                                        <input v-model="contactForm.email" type="email" maxlength="255" class="mt-1 w-full rounded-lg border-neutral-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                                        <span v-if="contactForm.errors.email" class="mt-1 block text-xs font-semibold text-red-600">{{ contactForm.errors.email }}</span>
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-bold text-neutral-500">WhatsApp</span>
                                        <input v-model="contactForm.whatsapp_number" type="text" maxlength="30" placeholder="+355 69 ..." class="mt-1 w-full rounded-lg border-neutral-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                                        <span class="mt-1 block text-tiny text-neutral-400">{{ $t('webStudio.whatsappHint') }}</span>
                                        <span v-if="contactForm.errors.whatsapp_number" class="mt-1 block text-xs font-semibold text-red-600">{{ contactForm.errors.whatsapp_number }}</span>
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-bold text-neutral-500">Instagram (URL)</span>
                                        <input v-model="contactForm.instagram" type="text" maxlength="255" class="mt-1 w-full rounded-lg border-neutral-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-bold text-neutral-500">Facebook (URL)</span>
                                        <input v-model="contactForm.facebook" type="text" maxlength="255" class="mt-1 w-full rounded-lg border-neutral-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                                    </label>
                                    <label class="block sm:col-span-2">
                                        <span class="text-xs font-bold text-neutral-500">{{ $t('webStudio.mapsUrl') }}</span>
                                        <input v-model="contactForm.maps_url" type="text" maxlength="2000" class="mt-1 w-full rounded-lg border-neutral-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                                    </label>
                                </div>
                            </section>

                            <!-- MARKA -->
                            <section v-else-if="activeSection === 'brand'" data-ui="card" class="settings-card-pad border border-neutral-200 bg-white">
                                <h2 data-ui="card-title">{{ $t('webStudio.brandTitle') }}</h2>
                                <p class="mt-1 text-body-sm text-neutral-500">{{ $t('webStudio.brandSubtitle') }}</p>
                                <div class="mt-4 flex flex-wrap items-center gap-4">
                                    <div class="grid h-24 w-40 shrink-0 place-items-center overflow-hidden rounded-xl border border-neutral-200 bg-neutral-900 p-3">
                                        <img v-if="logoUrl" :src="logoUrl" alt="" class="max-h-full max-w-full object-contain">
                                        <span v-else class="font-serif text-sm tracking-[.25em] text-neutral-300">{{ (hotelName || 'LOGO').toUpperCase() }}</span>
                                    </div>
                                    <div>
                                        <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-neutral-300 px-3.5 py-2 text-sm font-semibold text-neutral-700 hover:border-accent-500 hover:text-accent-700">
                                            {{ $t('webStudio.changeLogo') }}
                                            <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="pickImage(brandForm, 'logo', $event, 'brand.logo')">
                                        </label>
                                        <p class="mt-2 text-xs text-neutral-400">{{ $t('webStudio.logoHint') }}</p>
                                        <p v-if="brandForm.errors.logo" class="mt-1 text-xs font-semibold text-red-600">{{ brandForm.errors.logo }}</p>
                                    </div>
                                </div>
                            </section>

                            <!-- SHIRITI I RUAJTJES -->
                            <div v-if="activeForm" class="sticky bottom-4 z-20 flex items-center justify-between gap-3 rounded-xl border border-neutral-200 bg-white/95 px-4 py-3 shadow-lg backdrop-blur">
                                <span class="flex items-center gap-2 text-body-sm" :class="activeForm.isDirty ? 'font-semibold text-amber-700' : 'text-neutral-400'">
                                    <span class="h-2 w-2 rounded-full" :class="activeForm.isDirty ? 'bg-amber-500' : 'bg-neutral-300'" />
                                    {{ activeForm.isDirty ? $t('webStudio.unsavedChanges') : $t('webStudio.allSaved') }}
                                </span>
                                <button data-ui="button" type="submit" :disabled="activeForm.processing" class="bg-accent-700 px-6 text-white hover:bg-accent-800 disabled:opacity-50">
                                    {{ activeForm.processing ? $t('webStudio.saving') : $t('webStudio.saveChanges') }}
                                </button>
                            </div>
                        </form>

                        <!-- PARAPAMJA LIVE -->
                        <aside class="xl:sticky xl:top-4">
                            <div data-ui="card" class="overflow-hidden border border-neutral-200 bg-white">
                                <div class="flex items-center gap-2 border-b border-neutral-200 bg-neutral-100 px-3.5 py-2">
                                    <span class="h-2.5 w-2.5 rounded-full bg-red-400" /><span class="h-2.5 w-2.5 rounded-full bg-amber-400" /><span class="h-2.5 w-2.5 rounded-full bg-emerald-400" />
                                    <span class="flex-1 rounded-md bg-white px-3 py-1 text-center text-tiny text-neutral-500">{{ publicDomain }}</span>
                                </div>

                                <!-- Kreu -->
                                <div v-if="activeSection === 'home' || activeSection === 'brand'" class="bg-[#fdfcf9]">
                                    <div class="flex items-center justify-between px-4 py-3">
                                        <img v-if="logoUrl" :src="logoUrl" alt="" class="h-6 max-w-[110px] object-contain">
                                        <span v-else class="font-serif text-sm tracking-[.22em] text-[#1d3229]">{{ (hotelName || 'HOTELI').toUpperCase() }}</span>
                                        <div class="flex gap-2.5 text-[9px] uppercase tracking-wide text-neutral-500">
                                            <span>{{ $t('webStudio.pvNavHome') }}</span><span>{{ $t('webStudio.pvNavRooms') }}</span><span>{{ $t('webStudio.pvNavAbout') }}</span>
                                        </div>
                                    </div>
                                    <div class="relative flex h-56 flex-col items-center justify-center overflow-hidden px-4 text-center text-white">
                                        <img v-if="heroImageUrl" :src="heroImageUrl" alt="" class="absolute inset-0 h-full w-full object-cover">
                                        <div v-else class="absolute inset-0 bg-gradient-to-b from-[#f7b267] via-[#c96342] to-[#8c4a3c]" />
                                        <div class="absolute inset-0 bg-black/35" />
                                        <p class="relative text-[9px] uppercase tracking-[.3em] opacity-95">{{ heroPreviewText('eyebrow') }}</p>
                                        <h3 class="relative mt-2 max-w-[290px] font-serif text-2xl font-medium leading-tight">{{ heroPreviewText('title') }}</h3>
                                        <p class="relative mt-1.5 text-[11px] tracking-wider opacity-90">{{ heroPreviewText('subtitle') }}</p>
                                        <span class="relative mt-3.5 bg-[#fdfcf9] px-4 py-1.5 text-[9px] font-bold uppercase tracking-[.18em] text-[#1d3229]">{{ $t('webStudio.pvBookNow') }}</span>
                                    </div>
                                </div>

                                <!-- Dhomat -->
                                <div v-else-if="activeSection === 'rooms'" class="space-y-2.5 bg-[#fdfcf9] p-3.5">
                                    <p class="text-center font-serif text-lg text-[#1d3229]">{{ $t('webStudio.pvRoomsTitle') }}</p>
                                    <div v-for="type in roomTypes.slice(0, 3)" :key="type.id" class="overflow-hidden rounded-md bg-white shadow-sm">
                                        <div class="h-16 bg-neutral-200">
                                            <img v-if="type.image" :src="`/storage/${type.image}`" alt="" class="h-full w-full object-cover">
                                        </div>
                                        <div class="flex items-center justify-between px-2.5 py-1.5">
                                            <span class="truncate text-[11px] font-semibold text-[#1d3229]">{{ type.name }}</span>
                                            <span v-if="type.from_price !== null" class="text-[10px] font-bold text-[#1d3229]">{{ priceSymbol }}{{ type.from_price }}</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Rreth Nesh -->
                                <div v-else-if="activeSection === 'about'" class="bg-[#fdfcf9] p-4">
                                    <p class="text-center font-serif text-xl text-[#1d3229]">{{ aboutForm[lf('hero_title')] || $t('webStudio.pvAboutDefault') }}</p>
                                    <div class="mt-3 rounded-md bg-white p-3 shadow-sm">
                                        <p class="font-serif text-sm text-[#1d3229]">{{ aboutForm[lf('story_title')] || $t('webStudio.aboutStory') }}</p>
                                        <p class="mt-1 line-clamp-3 text-[10px] leading-relaxed text-neutral-500">{{ aboutForm[lf('story_p1')] || $t('webStudio.pvEmptyParagraph') }}</p>
                                    </div>
                                    <div class="mt-2 rounded-md bg-white p-3 shadow-sm">
                                        <p class="font-serif text-sm text-[#1d3229]">{{ aboutForm[lf('staff_title')] || $t('webStudio.aboutStaff') }}</p>
                                        <p class="mt-1 line-clamp-3 text-[10px] leading-relaxed text-neutral-500">{{ aboutForm[lf('staff_p1')] || $t('webStudio.pvEmptyParagraph') }}</p>
                                    </div>
                                </div>

                                <!-- Kontakti -->
                                <div v-else-if="activeSection === 'contact'" class="bg-[#1d3229] p-4 text-[#e8dfc9]">
                                    <p class="font-serif text-base tracking-[.18em]">{{ (hotelName || 'HOTELI').toUpperCase() }}</p>
                                    <div class="mt-3 space-y-1.5 text-[11px] opacity-90">
                                        <p v-if="contactForm.address" class="flex items-center gap-2"><MapPin class="h-3 w-3 shrink-0" /> {{ contactForm.address }}</p>
                                        <p v-if="contactForm.phone" class="flex items-center gap-2"><Phone class="h-3 w-3 shrink-0" /> {{ contactForm.phone }}</p>
                                        <p v-if="contactForm.email" class="flex items-center gap-2"><Mail class="h-3 w-3 shrink-0" /> {{ contactForm.email }}</p>
                                    </div>
                                    <div class="mt-3 flex items-center gap-2">
                                        <span v-if="contactForm.instagram" class="grid h-7 w-7 place-items-center rounded-full border border-[#e8dfc9]/40"><Instagram class="h-3.5 w-3.5" /></span>
                                        <span v-if="contactForm.facebook" class="grid h-7 w-7 place-items-center rounded-full border border-[#e8dfc9]/40"><Facebook class="h-3.5 w-3.5" /></span>
                                        <span v-if="contactForm.whatsapp_number" class="inline-flex items-center gap-1.5 rounded-full bg-emerald-600 px-2.5 py-1 text-[9px] font-bold text-white"><MessageCircle class="h-3 w-3" /> WhatsApp</span>
                                        <span v-if="!contactForm.instagram && !contactForm.facebook && !contactForm.whatsapp_number" class="text-[10px] opacity-60">{{ $t('webStudio.pvNoSocial') }}</span>
                                    </div>
                                </div>

                                <p class="flex items-center gap-1.5 border-t border-neutral-200 px-3.5 py-2.5 text-tiny text-neutral-400">
                                    {{ $t('webStudio.previewNote') }}
                                </p>
                            </div>
                        </aside>
                    </div>
        </main>
        </div>
    </div>
    </AppLayout>
</template>
