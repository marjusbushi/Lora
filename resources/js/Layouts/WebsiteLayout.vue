<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { MapPin, Phone, Mail, Instagram, Facebook } from 'lucide-vue-next';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import { translate } from '@/i18n';

const props = defineProps({
    // When true the header floats transparent over a full-bleed hero and
    // solidifies on scroll. Pages without a hero (default) get a solid header.
    transparentHeader: { type: Boolean, default: false },
});

const mobileMenu = ref(false);
const menuBtn = ref(null);
const scrolled = ref(false);

// Escape closes the mobile menu and returns focus to the hamburger.
function closeMenu() {
    if (!mobileMenu.value) return;
    mobileMenu.value = false;
    menuBtn.value?.focus();
}
const page = usePage();
const settings = computed(() => page.props.settings || {});
const bookingEnabled = computed(() => page.props.modules?.booking_engine === true);
const beachEnabled = computed(() => page.props.modules?.beach === true);
const hotelName = computed(() => settings.value.hotel_name || 'Hotel');
const logo = computed(() => settings.value.logo ? `/storage/${settings.value.logo}` : null);

// Contact details → actionable links
const addr = computed(() => settings.value.address || 'Ksamil, Sarande, Shqiperi');
const phone = computed(() => settings.value.phone || '+355 69 000 0000');
const email = computed(() => settings.value.email || 'info@villamucho.com');
const telHref = computed(() => 'tel:' + phone.value.replace(/[^+\d]/g, ''));
const mailHref = computed(() => 'mailto:' + email.value);

// Butoni WhatsApp: shfaqet VETËM kur pronari ka vendosur numër te Cilësimet
// (hotel.whatsapp_number). wa.me kërkon vetëm shifra (pa +, pa hapësira);
// teksti paraplotësohet sipas gjuhës së vizitorit.
const whatsappDigits = computed(() => (settings.value.whatsapp_number || '').replace(/\D/g, ''));
const whatsappHref = computed(() => whatsappDigits.value
    ? `https://wa.me/${whatsappDigits.value}?text=${encodeURIComponent(translate('website.whatsappPrefill', { hotel: hotelName.value }))}`
    : null);
const mapsDest = computed(() => {
    const m = (settings.value.maps_url || '').trim();
    return (m && !/^https?:|output=embed|\/maps\/embed/i.test(m)) ? m : addr.value;
});
const directionsHref = computed(() => 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(mapsDest.value));

// Header is "solid" unless we're floating over a hero AND at the top AND the
// mobile menu is closed.
const solid = computed(() => !props.transparentHeader || scrolled.value || mobileMenu.value);

function onScroll() {
    scrolled.value = window.scrollY > 24;
}
onMounted(() => {
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
});
onUnmounted(() => window.removeEventListener('scroll', onScroll));

const allNavLinks = [
    { key: 'home', href: '/' },
    { key: 'rooms', href: '/rooms' },
    { key: 'book', href: '/book' },
    { key: 'sunbeds', href: '/book-sunbeds' },
    { key: 'about', href: '/about' },
    { key: 'contact', href: '/contact' },
];
const navLinks = computed(() => allNavLinks.filter((link) =>
    (link.key !== 'book' || bookingEnabled.value) && (link.key !== 'sunbeds' || beachEnabled.value)));

function isActive(href) {
    if (href === '/') return page.url === '/';
    return page.url.startsWith(href);
}
</script>

<template>
    <div class="site min-h-screen">
        <!-- Keyboard users skip the 6+ header links on every page of the booking flow -->
        <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[60] focus:bg-ink focus:text-bone focus:px-4 focus:py-2 focus:rounded-md">
{{ $t('admin.generated.k_8ee4c60d2bd8') }} </a>
        <!-- Header -->
        <header
            :class="[
                'fixed top-0 left-0 right-0 z-50 transition-colors duration-300',
                solid ? 'bg-bone/90 backdrop-blur-md border-b border-driftwood/15' : 'border-b border-transparent',
            ]"
        >
            <!-- Faint top scrim so light text stays legible over a bright hero -->
            <div v-if="!solid" class="absolute inset-0 -z-10 bg-gradient-to-b from-ink/35 to-transparent pointer-events-none" />

            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-16">
                    <!-- Logo -->
                    <Link href="/" class="flex items-center gap-3 no-underline">
                        <template v-if="logo">
                            <img :src="logo" :alt="hotelName" class="h-10 w-auto max-w-[180px] object-contain" />
                        </template>
                        <template v-else>
                            <div class="h-9 w-9 rounded-none bg-ink flex items-center justify-center shrink-0">
                                <span class="text-bone font-serif text-lg leading-none">{{ hotelName.charAt(0) }}</span>
                            </div>
                            <span :class="['font-serif text-xl tracking-wide hidden sm:block transition-colors duration-300', solid ? 'text-ink' : 'text-bone']">{{ hotelName }}</span>
                        </template>
                    </Link>

                    <!-- Desktop nav -->
                    <nav class="hidden md:flex items-center gap-2">
                        <Link
                            v-for="link in navLinks"
                            :key="link.href"
                            :href="link.href"
                            :class="[
                                'px-3 py-2 text-body-sm font-medium tracking-wide transition-colors duration-200 no-underline',
                                solid
                                    ? (isActive(link.href) ? 'text-ionian' : 'text-ink/70 hover:text-ink')
                                    : (isActive(link.href) ? 'text-bone' : 'text-bone/80 hover:text-bone'),
                            ]"
                        >
                            {{ $t('nav.' + link.key) }}
                        </Link>
                        <Link v-if="bookingEnabled" href="/book" class="btn-reserve ml-3 !px-5 !py-2">
                            {{ $t('nav.reserve') }}
                        </Link>
                        <LanguageSwitcher :class="['ml-3', solid ? 'text-ink' : 'text-bone']" />
                    </nav>

                    <!-- Mobile hamburger -->
                    <button
                        ref="menuBtn"
                        :class="['md:hidden p-2 transition-colors', solid ? 'text-ink' : 'text-bone']"
                        :aria-expanded="mobileMenu"
                        aria-controls="site-mobile-menu"
                        :aria-label="mobileMenu ? $t('sidebar.closeMenu') : $t('sidebar.openMenu')"
                        @click="mobileMenu = !mobileMenu"
                        @keydown.escape="closeMenu"
                    >
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path v-if="!mobileMenu" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6h16M4 12h16M4 18h16" />
                            <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Mobile menu -->
                <div v-if="mobileMenu" id="site-mobile-menu" class="md:hidden pb-4 border-t border-driftwood/15 mt-2 pt-3" @keydown.escape="closeMenu">
                    <Link
                        v-for="link in navLinks"
                        :key="link.href"
                        :href="link.href"
                        :class="[
                            'block px-3 py-2.5 text-body-sm tracking-wide no-underline',
                            isActive(link.href) ? 'text-ionian' : 'text-ink/80 hover:text-ink',
                        ]"
                        @click="mobileMenu = false"
                    >
                        {{ $t('nav.' + link.key) }}
                    </Link>
                    <Link v-if="bookingEnabled" href="/book" class="btn-reserve mt-3 mx-3 flex px-4 py-3 text-center" @click="mobileMenu = false">
                        {{ $t('nav.reserve') }}
                    </Link>
                    <div class="mt-4 px-3 text-ink"><LanguageSwitcher /></div>
                </div>
            </div>
        </header>

        <!-- Content (offset for fixed header only when it's NOT floating over a hero) -->
        <main id="main" tabindex="-1" :class="[transparentHeader ? '' : 'pt-16', 'outline-none']">
            <slot />
        </main>

        <!-- Footer -->
        <footer class="bg-ink text-bone/55 border-t border-bone/10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-10">
                    <!-- Brand -->
                    <div>
                        <div class="flex items-center gap-3 mb-5">
                            <div class="h-8 w-8 rounded-none bg-brass flex items-center justify-center">
                                <span class="text-bone font-serif text-base leading-none">{{ hotelName.charAt(0) }}</span>
                            </div>
                            <span class="font-serif text-lg tracking-wide text-bone">{{ hotelName }}</span>
                        </div>
                        <p class="text-body-sm leading-relaxed text-bone/55 max-w-xs">
                            {{ $t('footer.blurb') }}
                        </p>
                    </div>
                    <!-- Links -->
                    <div>
                        <span class="eyebrow text-bone/40 mb-4">{{ $t('footer.nav') }}</span>
                        <div class="mt-4 space-y-2.5">
                            <Link v-for="link in navLinks" :key="link.href" :href="link.href" class="block text-body-sm text-bone/60 hover:text-brass no-underline transition-colors">
                                {{ $t('nav.' + link.key) }}
                            </Link>
                        </div>
                    </div>
                    <!-- Contact -->
                    <div>
                        <span class="eyebrow text-bone/40 mb-4">{{ $t('footer.contact') }}</span>
                        <div class="mt-4 space-y-2.5 text-body-sm text-bone/60">
                            <p class="text-bone/80">{{ hotelName }}</p>
                            <a :href="directionsHref" target="_blank" rel="noopener" class="flex items-center gap-2.5 text-bone/60 hover:text-brass transition-colors no-underline">
                                <MapPin class="h-4 w-4 text-brass shrink-0" :stroke-width="1.5" /> {{ addr }}
                            </a>
                            <a :href="telHref" class="flex items-center gap-2.5 text-bone/60 hover:text-brass transition-colors no-underline">
                                <Phone class="h-4 w-4 text-brass shrink-0" :stroke-width="1.5" /> {{ phone }}
                            </a>
                            <a :href="mailHref" class="flex items-center gap-2.5 text-bone/60 hover:text-brass transition-colors no-underline">
                                <Mail class="h-4 w-4 text-brass shrink-0" :stroke-width="1.5" /> {{ email }}
                            </a>
                        </div>
                        <div v-if="settings.instagram || settings.facebook" class="mt-5 flex items-center gap-3">
                            <a v-if="settings.instagram" :href="settings.instagram" target="_blank" rel="noopener" :aria-label="$t('admin.generated.k_71eba557ebf7')" class="text-bone/60 hover:text-brass transition-colors">
                                <Instagram class="h-5 w-5" :stroke-width="1.5" />
                            </a>
                            <a v-if="settings.facebook" :href="settings.facebook" target="_blank" rel="noopener" :aria-label="$t('admin.generated.k_293104d9cd23')" class="text-bone/60 hover:text-brass transition-colors">
                                <Facebook class="h-5 w-5" :stroke-width="1.5" />
                            </a>
                        </div>
                    </div>
                </div>
                <div class="border-t border-bone/10 mt-12 pt-8 text-center text-small text-bone/40">
                    © {{ new Date().getFullYear() }} {{ hotelName }}. {{ $t('footer.rights') }}
                </div>
            </div>
        </footer>

        <!-- Butoni WhatsApp — vetëm kur hoteli ka vendosur numër te Cilësimet.
             Hap bisedën në aplikacionin e vizitorit; mesazhet i mbërrijnë
             hotelit te Mesazhet kur numri është i lidhur me urën (ose në
             telefon, kur s'është). -->
        <a
            v-if="whatsappHref"
            :href="whatsappHref"
            target="_blank"
            rel="noopener"
            :aria-label="$t('website.whatsappCta')"
            :title="$t('website.whatsappCta')"
            class="fixed bottom-5 right-5 z-40 grid h-14 w-14 place-items-center rounded-full bg-[#25D366] text-white shadow-lg transition hover:scale-105 hover:shadow-xl focus-visible:outline-offset-4"
        >
            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2.05 22l5.27-1.38c1.45.79 3.08 1.21 4.72 1.21 5.46 0 9.9-4.44 9.9-9.9 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm0 18.15c-1.48 0-2.93-.4-4.19-1.15l-.3-.18-3.12.82.83-3.05-.2-.31a8.26 8.26 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.24-8.24 2.2 0 4.27.86 5.82 2.42a8.18 8.18 0 0 1 2.41 5.83c0 4.54-3.7 8.24-8.23 8.24Zm4.52-6.16c-.25-.13-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.17.25-.64.81-.78.97-.14.17-.29.19-.54.06-.25-.12-1.05-.38-1.99-1.23a7.4 7.4 0 0 1-1.38-1.72c-.14-.25-.02-.38.11-.51.11-.11.25-.29.37-.43.12-.14.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.13-.56-1.34-.76-1.84-.2-.48-.41-.42-.56-.42-.14-.01-.31-.01-.48-.01-.17 0-.43.06-.66.31-.22.25-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.14-1.18-.06-.1-.22-.16-.47-.28Z"/></svg>
        </a>
    </div>
</template>
