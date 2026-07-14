<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { MapPin, Phone, Mail, Instagram, Facebook } from 'lucide-vue-next';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';

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
const hotelName = computed(() => settings.value.hotel_name || 'Hotel');
const logo = computed(() => settings.value.logo ? `/storage/${settings.value.logo}` : null);

// Contact details → actionable links
const addr = computed(() => settings.value.address || 'Ksamil, Sarande, Shqiperi');
const phone = computed(() => settings.value.phone || '+355 69 000 0000');
const email = computed(() => settings.value.email || 'info@villamucho.com');
const telHref = computed(() => 'tel:' + phone.value.replace(/[^+\d]/g, ''));
const mailHref = computed(() => 'mailto:' + email.value);
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
    { key: 'about', href: '/about' },
    { key: 'contact', href: '/contact' },
];
const navLinks = computed(() => allNavLinks.filter((link) => link.key !== 'book' || bookingEnabled.value));

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
    </div>
</template>
