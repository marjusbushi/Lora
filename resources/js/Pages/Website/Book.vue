<script setup>
import { ref, computed, watch } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { CalendarX2 } from 'lucide-vue-next';
import WebsiteLayout from '@/Layouts/WebsiteLayout.vue';
import AvailabilityCalendar from '@/Components/Website/AvailabilityCalendar.vue';

const { t } = useI18n();

const props = defineProps({
    roomTypes: Array,
    preselectedType: [String, Number],
    hotel: Object,
});

const step = ref(1);
const availableRooms = ref([]);
const selectedRoom = ref(null);
const loading = ref(false);
const searched = ref(false);

const searchForm = ref({
    check_in: '',
    check_out: '',
    room_type_id: props.preselectedType || '',
    adults: 1,
});

const guestForm = useForm({
    room_id: '',
    check_in: '',
    check_out: '',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    notes: '',
    adults: 1,
    website: '', // honeypot — must stay empty
});

const nights = ref(0);
const flashError = computed(() => usePage().props.flash?.error);

const typeOptions = computed(() => props.roomTypes.map(rt => ({ value: rt.id, label: `${rt.name} (${t('home.rooms.priceFrom')} €${rt.base_price}/${t('book.search.perNight')})` })));

// Run the real per-room availability check for the chosen range (does NOT advance
// the step — it shows the live free-room count under the calendar).
async function runCheck() {
    if (!searchForm.value.check_in || !searchForm.value.check_out) return;
    loading.value = true;
    searched.value = false;
    try {
        const response = await axios.post('/book/check', {
            check_in: searchForm.value.check_in,
            check_out: searchForm.value.check_out,
            room_type_id: searchForm.value.room_type_id || null,
        });
        availableRooms.value = response.data.rooms;
        nights.value = response.data.nights;
        searched.value = true;
    } catch (e) {
        alert(e.response?.data?.message || t('book.search.checkError'));
    }
    loading.value = false;
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
    step.value = 3;
}

function submitBooking() {
    guestForm.post('/book', {
        onError: () => {},
    });
}

function goBack(toStep) {
    step.value = toStep;
}
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

                <!-- Steps indicator -->
                <div class="flex items-center justify-center gap-2 mb-10">
                    <div v-for="s in 3" :key="s" :class="['flex items-center gap-2', s < 3 && 'flex-1']">
                        <div :class="['h-8 w-8 rounded-full flex items-center justify-center text-small font-medium shrink-0', step >= s ? 'bg-ink text-bone' : 'bg-limestone text-driftwood']">{{ s }}</div>
                        <span :class="['text-body-sm hidden sm:block', step >= s ? 'text-ink font-medium' : 'text-driftwood']">{{ s === 1 ? $t('book.steps.dates') : s === 2 ? $t('book.steps.room') : $t('book.steps.details') }}</span>
                        <div v-if="s < 3" :class="['flex-1 h-0.5 mx-2', step > s ? 'bg-ink/30' : 'bg-driftwood/20']" />
                    </div>
                </div>

                <!-- Flash error (e.g. room no longer available) -->
                <div v-if="flashError" class="mb-6 p-3 rounded-lg bg-error-50 border border-error-200 text-body-sm text-error-700">
                    {{ flashError }}
                </div>

                <!-- Step 1: Dates -->
                <div v-if="step === 1" class="bg-white rounded-2xl border border-neutral-100 p-6 sm:p-8">
                    <h2 class="text-h3 text-primary-900 mb-6">{{ $t('book.search.heading') }}</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                        <div>
                            <label class="block text-label text-neutral-700 mb-1.5">{{ $t('book.search.roomType') }}</label>
                            <select v-model="searchForm.room_type_id" class="w-full rounded-lg border border-neutral-200 px-3 py-2.5 text-body-sm focus:border-ionian focus:ring-2 focus:ring-ionian/30">
                                <option value="">{{ $t('book.search.allTypes') }}</option>
                                <option v-for="t in roomTypes" :key="t.id" :value="t.id">{{ t.name }} ({{ $t('home.rooms.priceFrom') }} €{{ t.base_price }})</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-label text-neutral-700 mb-1.5">{{ $t('book.search.guests') }}</label>
                            <input type="number" v-model="searchForm.adults" min="1" max="10" class="w-full rounded-lg border border-neutral-200 px-3 py-2.5 text-body-sm focus:border-ionian focus:ring-2 focus:ring-ionian/30" />
                        </div>
                    </div>

                    <!-- Availability calendar: pick check-in → check-out, see which days are free -->
                    <AvailabilityCalendar
                        v-model:checkIn="searchForm.check_in"
                        v-model:checkOut="searchForm.check_out"
                        :room-type-id="searchForm.room_type_id"
                    />

                    <!-- Selected range + live free-room count -->
                    <div v-if="searchForm.check_in" class="mt-5 text-body-sm text-neutral-600">
                        <span class="font-medium text-primary-900">{{ searchForm.check_in }}</span>
                        <span v-if="searchForm.check_out"> → <span class="font-medium text-primary-900">{{ searchForm.check_out }}</span></span>
                        <span v-else class="text-neutral-400"> — zgjidh datën e daljes</span>
                    </div>
                    <div v-if="searchForm.check_in && searchForm.check_out" class="mt-2 text-body-sm">
                        <span v-if="loading" class="text-neutral-500">Duke kontrolluar disponueshmërinë…</span>
                        <span v-else-if="searched && availableRooms.length" class="text-success-700 font-medium">✓ {{ availableRooms.length }} dhoma të lira për {{ nights }} net</span>
                        <span v-else-if="searched" class="text-error-600 font-medium">✗ Asnjë dhomë e lirë për këto data — provo data të tjera</span>
                    </div>

                    <button
                        :disabled="!searched || !availableRooms.length || loading"
                        class="btn-reserve w-full mt-6"
                        @click="proceed"
                    >
                        {{ loading ? $t('book.search.checking') : (searched && availableRooms.length ? `Vazhdo te dhomat (${availableRooms.length}) →` : $t('book.search.checkButton')) }}
                    </button>
                </div>

                <!-- Step 2: Select Room -->
                <div v-if="step === 2">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-h3 text-primary-900">{{ $t('book.rooms.heading') }}</h2>
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
                        <CalendarX2 class="h-10 w-10 mx-auto mb-3 text-driftwood" :stroke-width="1.1" />
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
                                <p class="text-body-sm text-neutral-500">{{ searchForm.check_in }} → {{ searchForm.check_out }} · {{ nights }} {{ $t('book.rooms.nights') }}</p>
                            </div>
                            <p class="text-h3 text-brass">€{{ selectedRoom?.total_price }}</p>
                        </div>
                    </div>

                    <form @submit.prevent="submitBooking" class="space-y-4">
                        <!-- Honeypot: hidden from humans, bots fill it -->
                        <input v-model="guestForm.website" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" class="absolute -left-[9999px] h-0 w-0 opacity-0" />
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-label text-neutral-700 mb-1.5">{{ $t('book.guest.firstName') }}</label>
                                <input v-model="guestForm.first_name" type="text" :placeholder="$t('book.guest.firstNamePlaceholder')" class="w-full rounded-lg border border-neutral-200 px-3 py-2.5 text-body-sm focus:border-ionian focus:ring-2 focus:ring-ionian/30" />
                                <p v-if="guestForm.errors.first_name" class="text-small text-error-600 mt-1">{{ guestForm.errors.first_name }}</p>
                            </div>
                            <div>
                                <label class="block text-label text-neutral-700 mb-1.5">{{ $t('book.guest.lastName') }}</label>
                                <input v-model="guestForm.last_name" type="text" :placeholder="$t('book.guest.lastNamePlaceholder')" class="w-full rounded-lg border border-neutral-200 px-3 py-2.5 text-body-sm focus:border-ionian focus:ring-2 focus:ring-ionian/30" />
                                <p v-if="guestForm.errors.last_name" class="text-small text-error-600 mt-1">{{ guestForm.errors.last_name }}</p>
                            </div>
                            <div>
                                <label class="block text-label text-neutral-700 mb-1.5">{{ $t('book.guest.email') }}</label>
                                <input v-model="guestForm.email" type="email" :placeholder="$t('book.guest.emailPlaceholder')" class="w-full rounded-lg border border-neutral-200 px-3 py-2.5 text-body-sm focus:border-ionian focus:ring-2 focus:ring-ionian/30" />
                                <p v-if="guestForm.errors.email" class="text-small text-error-600 mt-1">{{ guestForm.errors.email }}</p>
                            </div>
                            <div>
                                <label class="block text-label text-neutral-700 mb-1.5">{{ $t('book.guest.phone') }}</label>
                                <input v-model="guestForm.phone" type="tel" :placeholder="$t('book.guest.phonePlaceholder')" class="w-full rounded-lg border border-neutral-200 px-3 py-2.5 text-body-sm focus:border-ionian focus:ring-2 focus:ring-ionian/30" />
                                <p v-if="guestForm.errors.phone" class="text-small text-error-600 mt-1">{{ guestForm.errors.phone }}</p>
                            </div>
                        </div>
                        <div>
                            <label class="block text-label text-neutral-700 mb-1.5">{{ $t('book.guest.specialRequests') }}</label>
                            <textarea v-model="guestForm.notes" rows="3" :placeholder="$t('book.guest.notesPlaceholder')" class="w-full rounded-lg border border-neutral-200 px-3 py-2.5 text-body-sm focus:border-ionian focus:ring-2 focus:ring-ionian/30" />
                        </div>
                        <button
                            type="submit"
                            :disabled="guestForm.processing"
                            class="btn-reserve w-full"
                        >
                            {{ guestForm.processing ? $t('book.guest.submitting') : `${$t('book.guest.confirm')} — €${selectedRoom?.total_price}` }}
                        </button>
                    </form>
                </div>
            </div>
        </section>
    </WebsiteLayout>
</template>
