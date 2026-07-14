<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ArrowRight, BedDouble, CalendarDays, CalendarX2, ChevronDown, Coffee, ShieldCheck, Users } from 'lucide-vue-next';
import WebsiteLayout from '@/Layouts/WebsiteLayout.vue';
import BookingSummary from '@/Components/Website/BookingSummary.vue';
import RoomGallery from '@/Components/Website/RoomGallery.vue';
import { countryOptions, PRIORITY_COUNTRIES } from '@/countries';

const { t, locale } = useI18n();
const brandName = computed(() => usePage().props.settings?.hotel_name || 'Hotel');

const props = defineProps({
    roomTypes: Array,
    preselectedType: [String, Number],
    hotel: Object,
    directPricing: { type: Object, default: () => ({ enabled: true, discount_pct: 10 }) },
    paymentRequired: { type: Boolean, default: false },
});

const today = new Date();
const tomorrow = new Date(today);
tomorrow.setDate(today.getDate() + 1);
const isoDate = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};

const step = ref(1);
const availableRooms = ref([]);
const selectedRoom = ref(null);
const loading = ref(false);
const checkError = ref('');
const nights = ref(0);
const step2Heading = ref(null);
const roomErrorBox = ref(null);

const searchForm = ref({
    check_in: isoDate(today),
    check_out: isoDate(tomorrow),
    room_type_id: props.preselectedType || '',
    adults: 2,
    children: 0,
});

const guestForm = useForm({
    room_id: '',
    check_in: '',
    check_out: '',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    nationality: 'AL',
    notes: '',
    adults: 2,
    children: 0,
    website: '',
});

const flashError = computed(() => usePage().props.flash?.error);
const selectedType = computed(() => props.roomTypes.find((room) => String(room.id) === String(searchForm.value.room_type_id)) || null);
const maxOcc = computed(() => selectedType.value?.max_occupancy || 8);
const adultsOptions = computed(() => Array.from({ length: maxOcc.value }, (_, index) => index + 1));
const childrenOptions = computed(() => Array.from({ length: maxOcc.value }, (_, index) => index));
const summaryRoom = computed(() => selectedRoom.value || availableRooms.value[0] || null);
const displayRoomTypes = computed(() => selectedType.value ? [selectedType.value] : props.roomTypes);
const previewByType = computed(() => availableRooms.value.reduce((prices, room) => {
    if (!prices[room.room_type_id]) prices[room.room_type_id] = room;
    return prices;
}, {}));
const priorityCountries = PRIORITY_COUNTRIES.map((code) => countryOptions.find((country) => country.value === code)).filter(Boolean);
const otherCountries = countryOptions.filter((country) => !PRIORITY_COUNTRIES.includes(country.value));

const money = (value) => Number(value || 0).toFixed(2);
const previewFor = (roomType) => previewByType.value[roomType.id] || null;
const dateLabel = (value) => value
    ? new Intl.DateTimeFormat(locale.value === 'sq' ? 'sq-AL' : 'en-GB', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(`${value}T12:00:00`))
    : '—';

watch(selectedType, () => {
    if (searchForm.value.adults > maxOcc.value) searchForm.value.adults = maxOcc.value;
    if (searchForm.value.children > maxOcc.value - 1) searchForm.value.children = Math.max(0, maxOcc.value - 1);
});

watch(() => searchForm.value.check_in, (value) => {
    if (value && (!searchForm.value.check_out || searchForm.value.check_out <= value)) {
        const next = new Date(`${value}T12:00:00`);
        next.setDate(next.getDate() + 1);
        searchForm.value.check_out = isoDate(next);
    }
});

let checkSeq = 0;
async function runCheck({ advance = false } = {}) {
    if (!searchForm.value.check_in || !searchForm.value.check_out) return;
    const seq = ++checkSeq;
    loading.value = true;
    checkError.value = '';
    try {
        const response = await axios.post('/book/check', {
            check_in: searchForm.value.check_in,
            check_out: searchForm.value.check_out,
            room_type_id: searchForm.value.room_type_id || null,
        });
        if (seq !== checkSeq) return;
        availableRooms.value = response.data.rooms;
        nights.value = response.data.nights;
        selectedRoom.value = response.data.rooms[0] || null;
        if (advance) step.value = 2;
    } catch (error) {
        if (seq !== checkSeq) return;
        checkError.value = error.response?.data?.message || t('book.search.checkError');
    } finally {
        if (seq === checkSeq) loading.value = false;
    }
}

watch(
    () => [searchForm.value.check_in, searchForm.value.check_out, searchForm.value.room_type_id],
    () => runCheck(),
);
onMounted(() => runCheck());

function selectRoom(room) {
    selectedRoom.value = room;
    guestForm.room_id = room.id;
    guestForm.check_in = searchForm.value.check_in;
    guestForm.check_out = searchForm.value.check_out;
    guestForm.adults = searchForm.value.adults;
    guestForm.children = searchForm.value.children;
    step.value = 3;
}

function goBack(toStep) {
    if (toStep < step.value) step.value = toStep;
}

const FIELD_IDS = { first_name: 'bk-first-name', last_name: 'bk-last-name', email: 'bk-email', phone: 'bk-phone', nationality: 'bk-nationality' };
function focusId(id) { document.getElementById(id)?.focus(); }

function submitBooking() {
    guestForm.post('/book', {
        onError: (errors) => nextTick(() => {
            if (errors.room_id) {
                roomErrorBox.value?.focus({ preventScroll: true });
                roomErrorBox.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            const field = Object.keys(FIELD_IDS).find((key) => errors[key]);
            const element = field && document.getElementById(FIELD_IDS[field]);
            element?.focus({ preventScroll: true });
            element?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }),
    });
}

async function chooseAnotherRoom() {
    guestForm.clearErrors('room_id');
    await runCheck();
    step.value = 2;
}

watch(step, (current) => nextTick(() => {
    const target = current === 3 ? document.getElementById('bk-first-name') : current === 2 ? step2Heading.value : document.getElementById('bk-check-in');
    target?.focus({ preventScroll: true });
    window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
}));
</script>

<template>
    <Head :title="$t('book.head.title')" />
    <WebsiteLayout>
        <section class="bg-bone py-8 sm:py-12">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <nav :aria-label="$t('book.steps.navLabel')" class="mx-auto mb-8 flex max-w-4xl items-center">
                    <template v-for="s in 3" :key="s">
                        <button
                            type="button"
                            :disabled="s >= step"
                            :aria-current="step === s ? 'step' : undefined"
                            class="flex shrink-0 items-center gap-2 text-body-sm"
                            :class="step >= s ? 'font-medium text-ink' : 'text-driftwood'"
                            @click="goBack(s)"
                        >
                            <span :class="['flex h-9 w-9 items-center justify-center rounded-full border', step >= s ? 'border-ionian bg-ionian text-bone' : 'border-driftwood/30 bg-bone']">{{ s }}</span>
                            <span class="hidden sm:inline">{{ s === 1 ? $t('book.steps.dates') : s === 2 ? $t('book.steps.room') : $t('book.direct.detailsPay') }}</span>
                        </button>
                        <div v-if="s < 3" :class="['mx-3 h-px flex-1 sm:mx-6', step > s ? 'bg-ionian' : 'bg-driftwood/25']" />
                    </template>
                </nav>

                <div v-if="flashError" role="alert" class="mb-6 rounded-xl border border-error-200 bg-error-50 p-4 text-body-sm text-error-700">{{ flashError }}</div>

                <form class="mb-8 grid gap-0 overflow-hidden rounded-2xl border border-driftwood/20 bg-white shadow-sm lg:grid-cols-[1fr_1fr_.8fr_1.1fr_auto]" @submit.prevent="runCheck({ advance: true })">
                    <label class="border-b border-driftwood/15 p-4 lg:border-b-0 lg:border-r">
                        <span class="mb-2 flex items-center gap-2 text-tiny font-semibold uppercase tracking-wider text-ink/45"><CalendarDays class="h-4 w-4" />{{ $t('book.search.checkIn') }}</span>
                        <input id="bk-check-in" v-model="searchForm.check_in" type="date" :min="isoDate(today)" class="w-full border-0 bg-transparent p-0 text-body font-medium text-ink focus:ring-0" />
                    </label>
                    <label class="border-b border-driftwood/15 p-4 lg:border-b-0 lg:border-r">
                        <span class="mb-2 flex items-center gap-2 text-tiny font-semibold uppercase tracking-wider text-ink/45"><CalendarDays class="h-4 w-4" />{{ $t('book.search.checkOut') }}</span>
                        <input v-model="searchForm.check_out" type="date" :min="searchForm.check_in" class="w-full border-0 bg-transparent p-0 text-body font-medium text-ink focus:ring-0" />
                    </label>
                    <label class="border-b border-driftwood/15 p-4 lg:border-b-0 lg:border-r">
                        <span class="mb-2 flex items-center gap-2 text-tiny font-semibold uppercase tracking-wider text-ink/45"><Users class="h-4 w-4" />{{ $t('book.search.guests') }}</span>
                        <div class="flex gap-3">
                            <div class="relative min-w-0 flex-1">
                                <select v-model.number="searchForm.adults" :aria-label="$t('book.search.adults')" class="w-full appearance-none border-0 bg-transparent py-0 pl-0 pr-7 text-body font-medium text-ink focus:ring-0">
                                    <option v-for="n in adultsOptions" :key="n" :value="n">{{ n }} {{ $t('book.direct.adults') }}</option>
                                </select>
                                <ChevronDown class="pointer-events-none absolute right-0 top-1/2 h-4 w-4 -translate-y-1/2 text-ink/55" aria-hidden="true" />
                            </div>
                            <div class="relative min-w-0 flex-1">
                                <select v-model.number="searchForm.children" :aria-label="$t('book.search.children')" class="w-full appearance-none border-0 bg-transparent py-0 pl-0 pr-7 text-body font-medium text-ink focus:ring-0">
                                    <option v-for="n in childrenOptions" :key="n" :value="n">{{ n }} {{ $t('book.direct.children') }}</option>
                                </select>
                                <ChevronDown class="pointer-events-none absolute right-0 top-1/2 h-4 w-4 -translate-y-1/2 text-ink/55" aria-hidden="true" />
                            </div>
                        </div>
                    </label>
                    <label class="border-b border-driftwood/15 p-4 lg:border-b-0 lg:border-r">
                        <span class="mb-2 flex items-center gap-2 text-tiny font-semibold uppercase tracking-wider text-ink/45"><BedDouble class="h-4 w-4" />{{ $t('book.search.roomType') }}</span>
                        <select id="bk-room-type" v-model="searchForm.room_type_id" class="w-full border-0 bg-transparent p-0 text-body font-medium text-ink focus:ring-0">
                            <option value="">{{ $t('book.search.allTypes') }}</option>
                            <option v-for="room in roomTypes" :key="room.id" :value="room.id">{{ room.name }}</option>
                        </select>
                    </label>
                    <div class="flex items-center p-3">
                        <button type="submit" :disabled="loading" class="btn-reserve h-full min-h-14 w-full whitespace-nowrap px-7 lg:w-auto">
                            {{ loading ? $t('book.search.checking') : $t('book.search.checkButton') }}
                        </button>
                    </div>
                </form>

                <div v-if="checkError" role="alert" class="mb-6 rounded-xl border border-error-200 bg-error-50 p-4 text-body-sm text-error-700">{{ checkError }}</div>

                <template v-if="step === 1">
                    <div class="mb-7 text-center">
                        <span class="eyebrow-brass">{{ $t('book.direct.smartEyebrow') }}</span>
                        <h1 class="mt-2 text-display-sm text-ink">{{ $t('book.header.title') }}</h1>
                        <p class="mx-auto mt-2 max-w-2xl text-body text-ink/55">{{ $t('book.direct.smartIntro') }}</p>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <article v-for="room in displayRoomTypes" :key="room.id" class="overflow-hidden rounded-2xl border border-driftwood/20 bg-white">
                            <RoomGallery :images="room.images" :alt="room.name" aspect="aspect-[16/9]" />
                            <div class="p-5">
                                <h2 class="text-h3 text-ink">{{ room.name }}</h2>
                                <div class="mt-4 flex items-end justify-between gap-3">
                                    <div>
                                        <p class="flex items-center gap-1.5 text-tiny font-semibold text-success-700"><span class="h-2 w-2 rounded-full bg-success-500" />{{ $t('book.direct.liveSmartPrice') }}</p>
                                        <p v-if="previewFor(room)?.direct_discount_pct > 0" class="mt-1 text-body-sm text-ink/35 line-through">€{{ money(previewFor(room).smart_price_per_night) }}</p>
                                        <p v-if="previewFor(room)" class="font-serif text-3xl text-brass">€{{ money(previewFor(room).price_per_night) }} <span class="font-sans text-tiny text-ink/45">/ {{ $t('book.search.perNight') }}</span></p>
                                        <p v-else class="text-body-sm font-medium text-ionian">{{ $t('book.search.checkDatesForPrice') }}</p>
                                    </div>
                                    <span v-if="previewFor(room)?.direct_discount_pct > 0" class="rounded-lg bg-success-50 px-2.5 py-2 text-tiny font-semibold text-success-800">-{{ previewFor(room).direct_discount_pct }}% {{ $t('book.direct.direct') }}</span>
                                </div>
                            </div>
                        </article>
                    </div>
                </template>

                <template v-else-if="step === 2">
                    <div class="grid items-start gap-7 lg:grid-cols-[minmax(0,1fr)_340px]">
                        <div>
                            <div class="mb-5 flex items-end justify-between gap-4">
                                <div>
                                    <h1 ref="step2Heading" tabindex="-1" class="text-display-sm text-ink focus:outline-none">{{ $t('book.rooms.heading') }}</h1>
                                    <p class="mt-1 text-body-sm text-ink/50">{{ dateLabel(searchForm.check_in) }} → {{ dateLabel(searchForm.check_out) }} · {{ nights }} {{ $t('book.rooms.nights') }}</p>
                                </div>
                                <button type="button" class="text-body-sm font-medium text-ionian" @click="goBack(1)">{{ $t('book.rooms.changeDates') }}</button>
                            </div>

                            <div v-if="availableRooms.length" class="space-y-5">
                                <article v-for="room in availableRooms" :key="room.id" :class="['overflow-hidden rounded-2xl border bg-white transition', selectedRoom?.id === room.id ? 'border-ionian shadow-md' : 'border-driftwood/20']">
                                    <div class="grid md:grid-cols-[280px_1fr]">
                                        <RoomGallery :images="room.images" :alt="room.room_type" aspect="aspect-[16/10] md:aspect-auto md:min-h-[280px]" />
                                        <div class="flex flex-col justify-between p-5 sm:p-6">
                                            <div>
                                                <h2 class="text-display-sm text-ink">{{ room.room_type }}</h2>
                                                <p class="mt-2 text-body-sm text-ink/55">{{ $t('book.rooms.room') }} {{ room.room_number }} · {{ $t('book.rooms.floor') }} {{ room.floor }} · {{ $t('book.rooms.max') }} {{ room.max_occupancy }} {{ $t('book.rooms.persons') }}</p>
                                                <p v-if="room.description" class="mt-4 line-clamp-2 text-body-sm leading-relaxed text-ink/55">{{ room.description }}</p>
                                                <div class="mt-4 flex flex-wrap gap-2 text-tiny text-ink/60">
                                                    <span v-for="amenity in (room.amenities || []).slice(0, 4)" :key="amenity" class="rounded-full border border-driftwood/20 px-2.5 py-1">{{ amenity }}</span>
                                                    <span v-if="room.breakfast_included" class="inline-flex items-center gap-1 rounded-full bg-success-50 px-2.5 py-1 text-success-800"><Coffee class="h-3.5 w-3.5" />{{ $t('book.direct.breakfast') }}</span>
                                                </div>
                                            </div>
                                            <div class="mt-6 flex flex-wrap items-end justify-between gap-4 border-t border-driftwood/15 pt-5">
                                                <div class="flex items-end gap-4">
                                                    <span v-if="room.direct_discount_pct > 0" class="rounded-lg bg-success-50 px-2.5 py-2 text-tiny font-semibold text-success-800">-{{ room.direct_discount_pct }}% {{ $t('book.direct.direct') }}</span>
                                                    <div>
                                                        <p class="flex items-center gap-1.5 text-tiny font-semibold text-success-700"><span class="h-2 w-2 rounded-full bg-success-500" />{{ $t('book.direct.liveSmartPrice') }}</p>
                                                        <p v-if="room.direct_discount_pct > 0" class="text-body-sm text-ink/35 line-through">€{{ money(room.smart_price_per_night) }}</p>
                                                        <p class="font-serif text-3xl text-ink">€{{ money(room.price_per_night) }} <span class="font-sans text-tiny text-ink/45">/ {{ $t('book.search.perNight') }}</span></p>
                                                    </div>
                                                </div>
                                                <button type="button" class="rounded-lg border border-ionian px-5 py-3 text-body-sm font-medium text-ionian transition hover:bg-ionian hover:text-bone" @click="selectRoom(room)">{{ $t('book.direct.selectRoom') }}</button>
                                            </div>
                                        </div>
                                    </div>
                                </article>
                            </div>
                            <div v-else class="rounded-2xl border border-driftwood/20 bg-white p-12 text-center">
                                <CalendarX2 class="mx-auto h-10 w-10 text-driftwood" :stroke-width="1.2" />
                                <p class="mt-3 text-body text-ink/65">{{ $t('book.rooms.emptyTitle') }}</p>
                                <button class="mt-3 text-body-sm font-medium text-ionian" @click="goBack(1)">{{ $t('book.rooms.tryOtherDates') }}</button>
                            </div>
                        </div>
                        <BookingSummary :room="summaryRoom" :search="searchForm" :nights="nights" :date-label="dateLabel" :money="money" />
                    </div>
                </template>

                <template v-else>
                    <div class="grid items-start gap-7 lg:grid-cols-[minmax(0,1fr)_340px]">
                        <div class="rounded-2xl border border-driftwood/20 bg-white p-6 sm:p-8">
                            <div class="mb-6 flex items-center justify-between gap-4">
                                <div><span class="eyebrow-brass">{{ $t('book.direct.finalStep') }}</span><h1 class="mt-1 text-display-sm text-ink">{{ $t('book.guest.heading') }}</h1></div>
                                <button class="text-body-sm font-medium text-ionian" @click="goBack(2)">{{ $t('book.guest.changeRoom') }}</button>
                            </div>
                            <div v-if="guestForm.errors.room_id" ref="roomErrorBox" role="alert" tabindex="-1" class="mb-6 rounded-xl border border-error-200 bg-error-50 p-4 focus:outline-none">
                                <p class="text-body-sm text-error-700">{{ guestForm.errors.room_id }}</p>
                                <button type="button" class="mt-2 text-body-sm font-medium text-ionian underline" @click="chooseAnotherRoom">{{ $t('book.guest.chooseOther') }}</button>
                            </div>
                            <form class="space-y-4" @submit.prevent="submitBooking">
                                <input v-model="guestForm.website" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" class="absolute -left-[9999px] h-0 w-0 opacity-0" />
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <label class="block"><span class="mb-1.5 block text-label text-neutral-700">{{ $t('book.guest.firstName') }}</span><input id="bk-first-name" v-model="guestForm.first_name" type="text" autocomplete="given-name" :class="['w-full rounded-lg border px-3 py-3 text-body-sm focus:border-ionian focus:ring-ionian/30', guestForm.errors.first_name ? 'border-error-300' : 'border-neutral-200']" @keydown.enter.prevent="focusId('bk-last-name')" /><span v-if="guestForm.errors.first_name" class="mt-1 block text-small text-error-600">{{ guestForm.errors.first_name }}</span></label>
                                    <label class="block"><span class="mb-1.5 block text-label text-neutral-700">{{ $t('book.guest.lastName') }}</span><input id="bk-last-name" v-model="guestForm.last_name" type="text" autocomplete="family-name" :class="['w-full rounded-lg border px-3 py-3 text-body-sm focus:border-ionian focus:ring-ionian/30', guestForm.errors.last_name ? 'border-error-300' : 'border-neutral-200']" @keydown.enter.prevent="focusId('bk-email')" /><span v-if="guestForm.errors.last_name" class="mt-1 block text-small text-error-600">{{ guestForm.errors.last_name }}</span></label>
                                    <label class="block"><span class="mb-1.5 block text-label text-neutral-700">{{ $t('book.guest.email') }}</span><input id="bk-email" v-model="guestForm.email" type="email" autocomplete="email" :class="['w-full rounded-lg border px-3 py-3 text-body-sm focus:border-ionian focus:ring-ionian/30', guestForm.errors.email ? 'border-error-300' : 'border-neutral-200']" @keydown.enter.prevent="focusId('bk-phone')" /><span v-if="guestForm.errors.email" class="mt-1 block text-small text-error-600">{{ guestForm.errors.email }}</span></label>
                                    <label class="block"><span class="mb-1.5 block text-label text-neutral-700">{{ $t('book.guest.phone') }}</span><input id="bk-phone" v-model="guestForm.phone" type="tel" autocomplete="tel" :class="['w-full rounded-lg border px-3 py-3 text-body-sm focus:border-ionian focus:ring-ionian/30', guestForm.errors.phone ? 'border-error-300' : 'border-neutral-200']" @keydown.enter.prevent="focusId('bk-nationality')" /><span v-if="guestForm.errors.phone" class="mt-1 block text-small text-error-600">{{ guestForm.errors.phone }}</span></label>
                                    <label class="block sm:col-span-2"><span class="mb-1.5 block text-label text-neutral-700">{{ $t('book.guest.nationality') }}</span><select id="bk-nationality" v-model="guestForm.nationality" autocomplete="country" class="w-full rounded-lg border border-neutral-200 bg-white px-3 py-3 text-body-sm focus:border-ionian focus:ring-ionian/30"><option value="">{{ $t('book.guest.nationalityPlaceholder') }}</option><optgroup :label="$t('book.guest.commonCountries')"><option v-for="country in priorityCountries" :key="country.value" :value="country.value">{{ country.label }}</option></optgroup><optgroup :label="$t('book.guest.allCountries')"><option v-for="country in otherCountries" :key="country.value" :value="country.value">{{ country.label }}</option></optgroup></select></label>
                                </div>
                                <label class="block"><span class="mb-1.5 block text-label text-neutral-700">{{ $t('book.guest.specialRequests') }}</span><textarea v-model="guestForm.notes" rows="3" :placeholder="$t('book.guest.notesPlaceholder')" class="w-full rounded-lg border border-neutral-200 px-3 py-3 text-body-sm focus:border-ionian focus:ring-ionian/30" /></label>
                                <button type="submit" :disabled="guestForm.processing" class="btn-reserve flex w-full items-center justify-center gap-2 py-4">{{ guestForm.processing ? $t('book.guest.submitting') : (paymentRequired ? $t('book.guest.continuePay') : $t('book.guest.confirm')) }} <ArrowRight v-if="!guestForm.processing" class="h-4 w-4" /></button>
                                <p class="flex items-center justify-center gap-2 text-center text-body-sm text-ink/55"><ShieldCheck class="h-4 w-4 text-success-700" />{{ paymentRequired ? $t('book.guest.paymentNote') : $t('book.direct.secureBooking') }}</p>
                            </form>
                        </div>
                        <BookingSummary :room="selectedRoom" :search="searchForm" :nights="nights" :date-label="dateLabel" :money="money" />
                    </div>
                </template>
            </div>
        </section>
    </WebsiteLayout>
</template>
