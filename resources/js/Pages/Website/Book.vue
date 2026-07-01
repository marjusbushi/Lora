<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { CalendarX2 } from 'lucide-vue-next';
import WebsiteLayout from '@/Layouts/WebsiteLayout.vue';
import AvailabilityCalendar from '@/Components/Website/AvailabilityCalendar.vue';
import { countryOptions, PRIORITY_COUNTRIES } from '@/countries';

const { t } = useI18n();

const props = defineProps({
    roomTypes: Array,
    preselectedType: [String, Number],
    hotel: Object,
    paymentRequired: { type: Boolean, default: false }, // POK configured → a card step follows
});

const step = ref(1);
const availableRooms = ref([]);
const selectedRoom = ref(null);
const loading = ref(false);
const searched = ref(false);
const checkError = ref('');

const searchForm = ref({
    check_in: '',
    check_out: '',
    room_type_id: props.preselectedType || '',
    adults: 1,
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
    nationality: 'AL', // most guests are Albanian; editable + feeds the card form's country pre-fill
    notes: '',
    adults: 1,
    children: 0,
    website: '', // honeypot — must stay empty
});

const nights = ref(0);
const flashError = computed(() => usePage().props.flash?.error);

// Country dropdown: pinned common origins first, then everything (Albanian labels).
const priorityCountries = PRIORITY_COUNTRIES
    .map((code) => countryOptions.find((c) => c.value === code))
    .filter(Boolean);
const otherCountries = countryOptions.filter((c) => !PRIORITY_COUNTRIES.includes(c.value));

// Selected room type → show its "from" price nicely + size the guest dropdowns.
const selectedType = computed(() => props.roomTypes.find(rt => String(rt.id) === String(searchForm.value.room_type_id)) || null);
const maxOcc = computed(() => selectedType.value?.max_occupancy || 8);
const adultsOptions = computed(() => Array.from({ length: maxOcc.value }, (_, i) => i + 1));      // 1..max
const childrenOptions = computed(() => Array.from({ length: maxOcc.value }, (_, i) => i));         // 0..max-1

// Keep the guest counts within the chosen type's capacity when it changes.
watch(selectedType, () => {
    if (searchForm.value.adults > maxOcc.value) searchForm.value.adults = maxOcc.value;
    if (searchForm.value.children > maxOcc.value - 1) searchForm.value.children = Math.max(0, maxOcc.value - 1);
});

const proceedBtn = ref(null);

// Run the real per-room availability check for the chosen range (does NOT advance
// the step — it shows the live free-room count under the calendar).
// A sequence token guards against out-of-order responses when dates change quickly:
// only the LATEST request may write results, so stale availability can't be booked.
let checkSeq = 0;
async function runCheck() {
    if (!searchForm.value.check_in || !searchForm.value.check_out) return;
    const seq = ++checkSeq;
    loading.value = true;
    searched.value = false;
    checkError.value = '';
    try {
        const response = await axios.post('/book/check', {
            check_in: searchForm.value.check_in,
            check_out: searchForm.value.check_out,
            room_type_id: searchForm.value.room_type_id || null,
        });
        if (seq !== checkSeq) return; // a newer check superseded this one
        availableRooms.value = response.data.rooms;
        nights.value = response.data.nights;
        searched.value = true;
        // Dates picked + rooms free → put the guest's finger/Enter on the continue button.
        if (availableRooms.value.length) {
            nextTick(() => {
                proceedBtn.value?.focus({ preventScroll: true });
                proceedBtn.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });
        }
    } catch (e) {
        if (seq !== checkSeq) return;
        checkError.value = e.response?.data?.message || t('book.search.checkError');
    } finally {
        if (seq === checkSeq) loading.value = false;
    }
}

function proceed() {
    if (availableRooms.value.length) step.value = 2;
}

// Re-check whenever the range or room type changes; reset prior results.
watch(
    () => [searchForm.value.check_in, searchForm.value.check_out, searchForm.value.room_type_id],
    () => {
        searched.value = false;
        availableRooms.value = [];
        if (searchForm.value.check_in && searchForm.value.check_out) runCheck();
    }
);

function selectRoom(room) {
    selectedRoom.value = room;
    guestForm.room_id = room.id;
    guestForm.check_in = searchForm.value.check_in;
    guestForm.check_out = searchForm.value.check_out;
    guestForm.adults = searchForm.value.adults;
    guestForm.children = searchForm.value.children;
    step.value = 3;
}

// Where to send focus when the server rejects a field — first invalid input wins.
const FIELD_IDS = { first_name: 'bk-first-name', last_name: 'bk-last-name', email: 'bk-email', phone: 'bk-phone', nationality: 'bk-nationality' };
const roomErrorBox = ref(null);

function submitBooking() {
    guestForm.post('/book', {
        onError: (errors) => nextTick(() => {
            // Room-level failure (taken/capacity/payment-down) → the recovery banner.
            if (errors.room_id) {
                roomErrorBox.value?.focus({ preventScroll: true });
                roomErrorBox.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            const first = Object.keys(FIELD_IDS).find((f) => errors[f]);
            const el = first && document.getElementById(FIELD_IDS[first]);
            if (el) {
                el.focus({ preventScroll: true });
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }),
    });
}

// The chosen room was lost (taken/capacity) → refresh the list and reselect, keeping all typed data.
async function chooseAnotherRoom() {
    guestForm.clearErrors('room_id');
    await runCheck();
    step.value = 2;
}

function goBack(toStep) {
    if (toStep < step.value) step.value = toStep;
}

// Enter advances field-by-field (the phone keyboard's "next"), never submits half-empty.
function focusId(id) {
    document.getElementById(id)?.focus();
}

// When the wizard step changes: put FOCUS exactly where the guest continues (first input of
// the new step — on phones this also opens the keyboard), then bring the step into view.
// Focus first with preventScroll so the browser's own focus-scroll doesn't fight the smooth scroll.
const step2Heading = ref(null);
const prefersReducedMotion = typeof window !== 'undefined'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
watch(step, (s) => {
    nextTick(() => {
        const target = s === 3
            ? document.getElementById('bk-first-name')
            : s === 2
                ? step2Heading.value // heading, NOT a room card — a stray Enter must never book a room
                : document.getElementById('bk-room-type');
        target?.focus({ preventScroll: true });
        window.scrollTo({ top: 0, behavior: prefersReducedMotion ? 'auto' : 'smooth' });
    });
});
</script>

<template>
    <Head :title="$t('book.head.title')" />
    <WebsiteLayout>
        <section class="py-16">
            <div class="max-w-3xl mx-auto px-4 sm:px-6">
                <div class="text-center mb-10">
                    <h1 class="text-h1 text-primary-900">{{ $t('book.header.title') }}</h1>
                    <p class="text-body text-neutral-500 mt-2">{{ $t('book.header.subtitle') }}</p>
                </div>

                <!-- Steps indicator: completed steps are tappable (go back without losing data) -->
                <nav :aria-label="$t('book.steps.navLabel')" class="flex items-center justify-center gap-2 mb-10">
                    <div v-for="s in 3" :key="s" :class="['flex items-center gap-2', s < 3 && 'flex-1']">
                        <button
                            type="button"
                            :disabled="s >= step"
                            :aria-current="step === s ? 'step' : undefined"
                            :aria-label="$t('book.steps.backTo', { step: s })"
                            :class="['h-8 w-8 rounded-full flex items-center justify-center text-small font-medium shrink-0', step >= s ? 'bg-ink text-bone' : 'bg-limestone text-driftwood', s < step && 'cursor-pointer hover:ring-2 hover:ring-ionian/40']"
                            @click="goBack(s)"
                        >{{ s }}</button>
                        <span :class="[step === s ? 'block' : 'hidden sm:block', 'text-body-sm', step >= s ? 'text-ink font-medium' : 'text-driftwood']">{{ s === 1 ? $t('book.steps.dates') : s === 2 ? $t('book.steps.room') : $t('book.steps.details') }}</span>
                        <div v-if="s < 3" aria-hidden="true" :class="['flex-1 h-0.5 mx-2', step > s ? 'bg-ink/30' : 'bg-driftwood/20']" />
                    </div>
                </nav>

                <!-- Flash error (e.g. room no longer available) -->
                <div v-if="flashError" role="alert" class="mb-6 p-3 rounded-lg bg-error-50 border border-error-200 text-body-sm text-error-700">
                    {{ flashError }}
                </div>

                <!-- Step 1: Dates (a form so Enter advances) -->
                <form v-if="step === 1" class="bg-white rounded-2xl border border-neutral-100 p-6 sm:p-8" @submit.prevent="proceed">
                    <h2 class="text-h3 text-primary-900 mb-6">{{ $t('book.search.heading') }}</h2>
                    <div class="space-y-4 mb-6">
                        <div>
                            <label for="bk-room-type" class="block text-label text-neutral-700 mb-1.5">{{ $t('book.search.roomType') }}</label>
                            <select id="bk-room-type" v-model="searchForm.room_type_id" class="w-full rounded-lg border border-neutral-200 px-3 py-2.5 text-body-sm focus:border-ionian focus:ring-2 focus:ring-ionian/30">
                                <option value="">{{ $t('book.search.allTypes') }}</option>
                                <option v-for="t in roomTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
                            </select>
                            <p v-if="selectedType" class="mt-1.5 text-body-sm text-ionian font-medium">{{ $t('home.rooms.priceFrom') }} €{{ selectedType.base_price }} / {{ $t('book.search.perNight') }}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label for="bk-adults" class="block text-label text-neutral-700 mb-1.5">{{ $t('book.search.adults') }}</label>
                                <select id="bk-adults" v-model.number="searchForm.adults" class="w-full rounded-lg border border-neutral-200 px-3 py-2.5 text-body-sm focus:border-ionian focus:ring-2 focus:ring-ionian/30">
                                    <option v-for="n in adultsOptions" :key="n" :value="n">{{ n }}</option>
                                </select>
                            </div>
                            <div>
                                <label for="bk-children" class="block text-label text-neutral-700 mb-1.5">{{ $t('book.search.children') }}</label>
                                <select id="bk-children" v-model.number="searchForm.children" class="w-full rounded-lg border border-neutral-200 px-3 py-2.5 text-body-sm focus:border-ionian focus:ring-2 focus:ring-ionian/30">
                                    <option v-for="n in childrenOptions" :key="n" :value="n">{{ n }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Availability calendar: pick check-in → check-out, see which days are free -->
                    <AvailabilityCalendar
                        v-model:checkIn="searchForm.check_in"
                        v-model:checkOut="searchForm.check_out"
                        :room-type-id="searchForm.room_type_id"
                    />

                    <!-- Selected range + live free-room count (persistent live region so results are announced) -->
                    <div role="status" aria-live="polite">
                        <div v-if="searchForm.check_in" class="mt-5 text-body-sm text-neutral-600">
                            <span class="font-medium text-primary-900">{{ searchForm.check_in }}</span>
                            <span v-if="searchForm.check_out"> → <span class="font-medium text-primary-900">{{ searchForm.check_out }}</span></span>
                            <span v-else class="text-neutral-400"> {{ $t('book.search.pickCheckout') }}</span>
                        </div>
                        <div v-if="searchForm.check_in && searchForm.check_out" class="mt-2 text-body-sm">
                            <span v-if="loading" class="text-neutral-500">{{ $t('book.search.checking') }}</span>
                            <span v-else-if="checkError" class="text-error-600 font-medium">
                                {{ checkError }}
                                <button type="button" class="underline ml-1" @click="runCheck">{{ $t('book.search.retry') }}</button>
                            </span>
                            <span v-else-if="searched && availableRooms.length" class="text-success-700 font-medium"><span aria-hidden="true">✓</span> {{ $t('book.search.freeRooms', { count: availableRooms.length, nights }) }}</span>
                            <span v-else-if="searched" class="text-error-600 font-medium"><span aria-hidden="true">✗</span> {{ $t('book.search.noRooms') }}</span>
                        </div>
                    </div>

                    <button
                        ref="proceedBtn"
                        type="submit"
                        :disabled="!searched || !availableRooms.length || loading"
                        class="btn-reserve w-full mt-6"
                    >
                        {{ loading ? $t('book.search.checking') : (searched && availableRooms.length ? $t('book.search.continueRooms', { count: availableRooms.length }) : $t('book.search.checkButton')) }}
                    </button>
                    <p v-if="!searchForm.check_in" class="mt-2 text-center text-body-sm text-ink/60">{{ $t('book.search.pickDatesHint') }}</p>
                </form>

                <!-- Step 2: Select Room -->
                <div v-if="step === 2">
                    <div class="flex items-center justify-between mb-4">
                        <h2 ref="step2Heading" tabindex="-1" class="text-h3 text-primary-900 focus:outline-none">{{ $t('book.rooms.heading') }}</h2>
                        <button class="text-body-sm text-ionian hover:text-ionian-dark" @click="goBack(1)">{{ $t('book.rooms.changeDates') }}</button>
                    </div>
                    <p class="text-body-sm text-neutral-500 mb-6">{{ searchForm.check_in }} → {{ searchForm.check_out }} · {{ nights }} {{ $t('book.rooms.nights') }}</p>

                    <div v-if="availableRooms.length" class="space-y-3">
                        <button
                            v-for="room in availableRooms"
                            :key="room.id"
                            class="w-full bg-bone border border-driftwood/20 p-5 text-left hover:border-ionian/50 transition-colors"
                            @click="selectRoom(room)"
                        >
                            <div class="flex items-start justify-between">
                                <div>
                                    <h3 class="text-h4 text-primary-900">{{ $t('book.rooms.room') }} {{ room.room_number }}</h3>
                                    <p class="text-body-sm text-neutral-500">{{ room.room_type }} · {{ $t('book.rooms.floor') }} {{ room.floor }} · {{ $t('book.rooms.max') }} {{ room.max_occupancy }} {{ $t('book.rooms.persons') }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-h4 text-brass">€{{ room.total_price }}</p>
                                    <p class="text-tiny text-neutral-400">{{ nights }} {{ $t('book.rooms.nights') }} × €{{ room.price_per_night }}</p>
                                </div>
                            </div>
                        </button>
                    </div>

                    <div v-else class="bg-bone border border-driftwood/20 p-10 text-center">
                        <CalendarX2 class="h-10 w-10 mx-auto mb-3 text-driftwood" :stroke-width="1.1" aria-hidden="true" />
                        <p class="text-body text-ink/70">{{ $t('book.rooms.emptyTitle') }}</p>
                        <button class="mt-3 text-body-sm text-ionian hover:text-ionian-dark" @click="goBack(1)">{{ $t('book.rooms.tryOtherDates') }}</button>
                    </div>
                </div>

                <!-- Step 3: Guest Info -->
                <div v-if="step === 3" class="bg-white rounded-2xl border border-neutral-100 p-6 sm:p-8">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-h3 text-primary-900">{{ $t('book.guest.heading') }}</h2>
                        <button class="text-body-sm text-ionian hover:text-ionian-dark" @click="goBack(2)">{{ $t('book.guest.changeRoom') }}</button>
                    </div>

                    <!-- Summary -->
                    <div class="bg-neutral-50 rounded-lg p-4 mb-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-label text-primary-900">{{ $t('book.rooms.room') }} {{ selectedRoom?.room_number }} — {{ selectedRoom?.room_type }}</p>
                                <p class="text-body-sm text-neutral-500">{{ searchForm.check_in }} → {{ searchForm.check_out }} · {{ nights }} {{ $t('book.rooms.nights') }} · {{ searchForm.adults }} {{ $t('book.guest.adultsShort') }}<template v-if="searchForm.children">, {{ searchForm.children }} {{ $t('book.guest.childrenShort') }}</template></p>
                            </div>
                            <p class="text-h3 text-brass">€{{ selectedRoom?.total_price }}</p>
                        </div>
                    </div>

                    <!-- Room-level failure (taken / capacity / payment down) → recover without losing anything typed -->
                    <div
                        v-if="guestForm.errors.room_id"
                        ref="roomErrorBox"
                        role="alert"
                        tabindex="-1"
                        class="mb-6 p-4 rounded-lg bg-error-50 border border-error-200 focus:outline-none"
                    >
                        <p class="text-body-sm text-error-700">{{ guestForm.errors.room_id }}</p>
                        <button type="button" class="mt-2 text-body-sm font-medium text-ionian underline" @click="chooseAnotherRoom">
                            {{ $t('book.guest.chooseOther') }}
                        </button>
                    </div>

                    <form @submit.prevent="submitBooking" class="space-y-4">
                        <!-- Honeypot: hidden from humans, bots fill it -->
                        <input v-model="guestForm.website" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" class="absolute -left-[9999px] h-0 w-0 opacity-0" />
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="bk-first-name" class="block text-label text-neutral-700 mb-1.5">{{ $t('book.guest.firstName') }}</label>
                                <input
                                    id="bk-first-name" v-model="guestForm.first_name" type="text" name="first_name"
                                    autocomplete="given-name" autocapitalize="words" enterkeyhint="next"
                                    :placeholder="$t('book.guest.firstNamePlaceholder')"
                                    :aria-invalid="!!guestForm.errors.first_name" :aria-describedby="guestForm.errors.first_name ? 'bk-first-name-error' : undefined"
                                    :class="['w-full rounded-lg border px-3 py-2.5 text-body-sm focus:border-ionian focus:ring-2 focus:ring-ionian/30', guestForm.errors.first_name ? 'border-error-300' : 'border-neutral-200']"
                                    @keydown.enter.prevent="focusId('bk-last-name')"
                                />
                                <p v-if="guestForm.errors.first_name" id="bk-first-name-error" class="text-small text-error-600 mt-1">{{ guestForm.errors.first_name }}</p>
                            </div>
                            <div>
                                <label for="bk-last-name" class="block text-label text-neutral-700 mb-1.5">{{ $t('book.guest.lastName') }}</label>
                                <input
                                    id="bk-last-name" v-model="guestForm.last_name" type="text" name="last_name"
                                    autocomplete="family-name" autocapitalize="words" enterkeyhint="next"
                                    :placeholder="$t('book.guest.lastNamePlaceholder')"
                                    :aria-invalid="!!guestForm.errors.last_name" :aria-describedby="guestForm.errors.last_name ? 'bk-last-name-error' : undefined"
                                    :class="['w-full rounded-lg border px-3 py-2.5 text-body-sm focus:border-ionian focus:ring-2 focus:ring-ionian/30', guestForm.errors.last_name ? 'border-error-300' : 'border-neutral-200']"
                                    @keydown.enter.prevent="focusId('bk-email')"
                                />
                                <p v-if="guestForm.errors.last_name" id="bk-last-name-error" class="text-small text-error-600 mt-1">{{ guestForm.errors.last_name }}</p>
                            </div>
                            <div>
                                <label for="bk-email" class="block text-label text-neutral-700 mb-1.5">{{ $t('book.guest.email') }}</label>
                                <input
                                    id="bk-email" v-model="guestForm.email" type="email" name="email"
                                    autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" enterkeyhint="next"
                                    :placeholder="$t('book.guest.emailPlaceholder')"
                                    :aria-invalid="!!guestForm.errors.email" :aria-describedby="guestForm.errors.email ? 'bk-email-error' : undefined"
                                    :class="['w-full rounded-lg border px-3 py-2.5 text-body-sm focus:border-ionian focus:ring-2 focus:ring-ionian/30', guestForm.errors.email ? 'border-error-300' : 'border-neutral-200']"
                                    @keydown.enter.prevent="focusId('bk-phone')"
                                />
                                <p v-if="guestForm.errors.email" id="bk-email-error" class="text-small text-error-600 mt-1">{{ guestForm.errors.email }}</p>
                            </div>
                            <div>
                                <label for="bk-phone" class="block text-label text-neutral-700 mb-1.5">{{ $t('book.guest.phone') }}</label>
                                <input
                                    id="bk-phone" v-model="guestForm.phone" type="tel" name="phone"
                                    autocomplete="tel" inputmode="tel" enterkeyhint="next"
                                    :placeholder="$t('book.guest.phonePlaceholder')"
                                    :aria-invalid="!!guestForm.errors.phone" :aria-describedby="guestForm.errors.phone ? 'bk-phone-error' : undefined"
                                    :class="['w-full rounded-lg border px-3 py-2.5 text-body-sm focus:border-ionian focus:ring-2 focus:ring-ionian/30', guestForm.errors.phone ? 'border-error-300' : 'border-neutral-200']"
                                    @keydown.enter.prevent="focusId('bk-nationality')"
                                />
                                <p v-if="guestForm.errors.phone" id="bk-phone-error" class="text-small text-error-600 mt-1">{{ guestForm.errors.phone }}</p>
                            </div>
                            <div>
                                <label for="bk-nationality" class="block text-label text-neutral-700 mb-1.5">{{ $t('book.guest.nationality') }}</label>
                                <select
                                    id="bk-nationality" v-model="guestForm.nationality" name="nationality" autocomplete="country"
                                    :aria-invalid="!!guestForm.errors.nationality" :aria-describedby="guestForm.errors.nationality ? 'bk-nationality-error' : undefined"
                                    :class="['w-full rounded-lg border px-3 py-2.5 text-body-sm bg-white focus:border-ionian focus:ring-2 focus:ring-ionian/30', guestForm.errors.nationality ? 'border-error-300' : 'border-neutral-200']"
                                >
                                    <option value="">{{ $t('book.guest.nationalityPlaceholder') }}</option>
                                    <optgroup :label="$t('book.guest.commonCountries')">
                                        <option v-for="c in priorityCountries" :key="c.value" :value="c.value">{{ c.label }}</option>
                                    </optgroup>
                                    <optgroup :label="$t('book.guest.allCountries')">
                                        <option v-for="c in otherCountries" :key="c.value" :value="c.value">{{ c.label }}</option>
                                    </optgroup>
                                </select>
                                <p v-if="guestForm.errors.nationality" id="bk-nationality-error" class="text-small text-error-600 mt-1">{{ guestForm.errors.nationality }}</p>
                            </div>
                        </div>
                        <div>
                            <label for="bk-notes" class="block text-label text-neutral-700 mb-1.5">{{ $t('book.guest.specialRequests') }}</label>
                            <textarea id="bk-notes" v-model="guestForm.notes" rows="3" :placeholder="$t('book.guest.notesPlaceholder')" class="w-full rounded-lg border border-neutral-200 px-3 py-2.5 text-body-sm focus:border-ionian focus:ring-2 focus:ring-ionian/30" />
                        </div>
                        <button
                            type="submit"
                            :disabled="guestForm.processing"
                            class="btn-reserve w-full"
                        >
                            {{ guestForm.processing ? $t('book.guest.submitting') : `${paymentRequired ? $t('book.guest.continuePay') : $t('book.guest.confirm')} — €${selectedRoom?.total_price}` }}
                        </button>
                        <p v-if="paymentRequired" class="text-center text-body-sm text-ink/60">{{ $t('book.guest.paymentNote') }}</p>
                    </form>
                </div>
            </div>
        </section>
    </WebsiteLayout>
</template>
