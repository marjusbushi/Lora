<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { CalendarDays, Umbrella, ShieldCheck } from 'lucide-vue-next';
import WebsiteLayout from '@/Layouts/WebsiteLayout.vue';
import SunbedSpot from '@/Components/Website/SunbedSpot.vue';

const props = defineProps({
    zones: { type: Array, default: () => [] },
    bookingWindowDays: { type: Number, default: 10 },
    season: { type: Object, default: () => ({ start: '', end: '' }) },
    today: { type: String, required: true },
});

const step = ref(1);
const wizardTop = ref(null);

// Sa herë ndërron hapi: fokusi + scroll-i kthehen në krye të wizard-it,
// që klienti (sidomos në telefon) të mos mbetet i humbur poshtë faqes.
watch(step, async () => {
    await nextTick();
    wizardTop.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    wizardTop.value?.focus({ preventScroll: true });
});
const busyUnitIds = ref([]);
const selectedUnit = ref(null); // {id, number, zoneName, price}
const loading = ref(false);
const checkError = ref('');

function isoAddDays(iso, days) {
    const [y, m, d] = iso.split('-').map(Number);
    const date = new Date(y, m - 1, d + days);
    const mm = String(date.getMonth() + 1).padStart(2, '0');
    const dd = String(date.getDate()).padStart(2, '0');
    return `${date.getFullYear()}-${mm}-${dd}`;
}

// Dritarja publike [sot, sot+window] e prerë me sezonin → datat jashtë
// ÇAKTIVIZOHEN që në date-picker (min/max), jo error pas submit.
const minDate = computed(() => {
    const seasonStart = props.season?.start || '';
    return seasonStart > props.today ? seasonStart : props.today;
});
const maxDate = computed(() => {
    const windowEnd = isoAddDays(props.today, props.bookingWindowDays);
    const seasonEnd = props.season?.end || '';
    return seasonEnd && seasonEnd < windowEnd ? seasonEnd : windowEnd;
});

const dates = ref({ start: '', end: '' });
dates.value.start = minDate.value;
dates.value.end = minDate.value;

const days = computed(() => {
    if (!dates.value.start || !dates.value.end || dates.value.end < dates.value.start) return 0;
    const [y1, m1, d1] = dates.value.start.split('-').map(Number);
    const [y2, m2, d2] = dates.value.end.split('-').map(Number);
    return Math.round((new Date(y2, m2 - 1, d2) - new Date(y1, m1 - 1, d1)) / 86400000) + 1;
});

async function checkAvailability() {
    checkError.value = '';
    if (!dates.value.start || !dates.value.end || days.value < 1) return;
    loading.value = true;
    try {
        const url = route('website.beach.availability', {
            start_date: dates.value.start,
            end_date: dates.value.end,
        });
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        const data = await response.json();
        if (!response.ok) {
            checkError.value = data.message || Object.values(data.errors ?? {}).flat().join(' ');
            return;
        }
        busyUnitIds.value = data.busy_unit_ids ?? [];
        selectedUnit.value = null;
        step.value = 2;
    } catch {
        checkError.value = 'Provo përsëri.';
    } finally {
        loading.value = false;
    }
}

function pickUnit(zone, unit) {
    if (busyUnitIds.value.includes(unit.id)) return;
    selectedUnit.value = {
        id: unit.id,
        number: unit.number,
        zoneName: zone.name,
        price: Number(zone.price_per_day),
    };
}

const total = computed(() =>
    selectedUnit.value ? (days.value * selectedUnit.value.price).toFixed(2) : null,
);

const form = useForm({
    beach_unit_id: '',
    start_date: '',
    end_date: '',
    guest_name: '',
    guest_phone: '',
    guest_email: '',
    website: '', // honeypot — vizitorët realë s'e mbushin kurrë
});

function submit() {
    form.beach_unit_id = selectedUnit.value?.id ?? '';
    form.start_date = dates.value.start;
    form.end_date = dates.value.end;
    form.post(route('website.beach.submit'));
}
</script>

<template>
    <Head :title="$t('beach.public.headTitle')" />
    <WebsiteLayout>
        <section class="bg-bone py-8 sm:py-12">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <h1 ref="wizardTop" tabindex="-1" class="mb-2 text-2xl sm:text-3xl font-semibold text-ink outline-none">{{ $t('beach.public.title') }}</h1>
                <p class="mb-8 text-body-sm text-driftwood">
                    {{ $t('beach.public.subtitle', { days: bookingWindowDays }) }}
                </p>

                <!-- Hapat -->
                <nav class="mb-8 flex max-w-2xl items-center" :aria-label="$t('beach.public.stepsLabel')">
                    <template v-for="s in 3" :key="s">
                        <button
                            type="button"
                            :disabled="s >= step"
                            :aria-current="step === s ? 'step' : undefined"
                            class="flex shrink-0 items-center gap-2 text-body-sm"
                            :class="step >= s ? 'font-medium text-ink' : 'text-driftwood'"
                            @click="step = s"
                        >
                            <span :class="['flex h-9 w-9 items-center justify-center rounded-full border', step >= s ? 'border-ionian bg-ionian text-bone' : 'border-driftwood/30 bg-bone']">{{ s }}</span>
                            <span class="hidden sm:inline">{{ s === 1 ? $t('beach.public.stepDates') : s === 2 ? $t('beach.public.stepSunbed') : $t('beach.public.stepDetails') }}</span>
                        </button>
                        <div v-if="s < 3" :class="['mx-3 h-px flex-1 sm:mx-6', step > s ? 'bg-ionian' : 'bg-driftwood/25']" />
                    </template>
                </nav>

                <!-- Hapi 1: datat -->
                <div v-if="step === 1" class="rounded-2xl border border-driftwood/20 bg-white p-5 shadow-sm">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label>
                            <span class="mb-2 flex items-center gap-2 text-tiny font-semibold uppercase tracking-wider text-ink/45"><CalendarDays class="h-4 w-4" />{{ $t('beach.public.fromDate') }}</span>
                            <input v-model="dates.start" type="date" :min="minDate" :max="maxDate" class="w-full rounded-xl border-driftwood/30 text-body font-medium text-ink focus:border-ionian focus:ring-ionian" />
                        </label>
                        <label>
                            <span class="mb-2 flex items-center gap-2 text-tiny font-semibold uppercase tracking-wider text-ink/45"><CalendarDays class="h-4 w-4" />{{ $t('beach.public.toDate') }}</span>
                            <input v-model="dates.end" type="date" :min="dates.start || minDate" :max="maxDate" class="w-full rounded-xl border-driftwood/30 text-body font-medium text-ink focus:border-ionian focus:ring-ionian" />
                        </label>
                    </div>
                    <p v-if="days > 0" class="mt-3 text-body-sm text-driftwood">{{ $t('beach.public.daysCount', { days }) }}</p>
                    <p v-if="checkError" role="alert" class="mt-3 rounded-xl border border-error-200 bg-error-50 p-3 text-body-sm text-error-700">{{ checkError }}</p>
                    <button
                        type="button"
                        class="mt-5 w-full rounded-xl bg-ionian px-6 py-3.5 text-body font-semibold text-bone transition hover:opacity-90 disabled:opacity-50 sm:w-auto"
                        :disabled="loading || days < 1"
                        @click="checkAvailability"
                    >
                        {{ loading ? $t('beach.public.checking') : $t('beach.public.seeFree') }}
                    </button>
                </div>

                <!-- Hapi 2: harta e plazhit në një faqe — deti përpara, rreshtat me rrugicë mes tyre -->
                <div v-else-if="step === 2" class="space-y-5">
                    <div class="overflow-hidden rounded-2xl border border-driftwood/20 bg-[#faf3e3] shadow-sm">
                        <!-- Deti -->
                        <div class="relative flex h-16 items-end justify-center bg-gradient-to-b from-ionian to-ionian/50">
                            <span class="mb-1.5 text-tiny font-semibold uppercase tracking-[0.3em] text-bone/90">{{ $t('beach.public.sea') }}</span>
                            <svg class="absolute bottom-0 left-0 w-full text-[#faf3e3]" viewBox="0 0 1200 24" preserveAspectRatio="none" fill="currentColor" aria-hidden="true">
                                <path d="M0 24 L0 14 Q 75 2 150 14 T 300 14 T 450 14 T 600 14 T 750 14 T 900 14 T 1050 14 T 1200 14 L1200 24 Z" />
                            </svg>
                        </div>

                        <!-- Rëra: rreshtat e çadrave, të parët më afër detit -->
                        <div class="p-4 sm:p-6">
                            <template v-for="(zone, index) in zones" :key="zone.id">
                                <!-- Rrugica e kalimit mes rreshtave -->
                                <div v-if="index > 0" class="my-4 border-t-2 border-dashed border-driftwood/30" role="presentation" />
                                <div class="mb-2 flex flex-wrap items-baseline gap-2">
                                    <h2 class="font-semibold text-ink">{{ zone.name }}</h2>
                                    <span class="text-body-sm text-driftwood">{{ $t('beach.public.pricePerDay', { price: zone.price_per_day }) }}</span>
                                </div>
                                <div class="flex flex-wrap justify-center gap-1 sm:gap-2">
                                    <SunbedSpot
                                        v-for="unit in zone.units"
                                        :key="unit.id"
                                        :number="unit.number"
                                        :state="busyUnitIds.includes(unit.id) ? 'busy' : selectedUnit?.id === unit.id ? 'selected' : 'free'"
                                        @pick="pickUnit(zone, unit)"
                                    />
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3 rounded-2xl border border-driftwood/20 bg-white p-5 shadow-sm sm:flex-row sm:items-center">
                        <p v-if="selectedUnit" class="text-body font-medium text-ink">
                            <Umbrella class="mr-1 inline h-4 w-4 text-ionian" />
                            {{ $t('beach.public.selectionSummary', { number: selectedUnit.number, zone: selectedUnit.zoneName, days, total }) }}
                        </p>
                        <p v-else class="text-body-sm text-driftwood">{{ $t('beach.public.pickHint') }}</p>
                        <button
                            type="button"
                            class="rounded-xl bg-ionian px-6 py-3 text-body font-semibold text-bone transition hover:opacity-90 disabled:opacity-50 sm:ml-auto"
                            :disabled="!selectedUnit"
                            @click="step = 3"
                        >
                            {{ $t('beach.public.continue') }}
                        </button>
                    </div>
                </div>

                <!-- Hapi 3: kompakt — përmbledhja + të dhënat + Rezervo në NJË kartë -->
                <form v-else class="mx-auto max-w-md" @submit.prevent="submit">
                    <div class="rounded-2xl border border-driftwood/20 bg-white p-4 shadow-sm sm:p-5">
                        <div v-if="selectedUnit" class="mb-4 rounded-xl bg-ionian/5 px-3 py-2.5 ring-1 ring-ionian/30">
                            <p class="text-body-sm font-semibold text-ink">
                                {{ $t('beach.public.finalSummary', { number: selectedUnit.number, zone: selectedUnit.zoneName, start: dates.start, end: dates.end }) }}
                            </p>
                            <p class="text-lg font-bold text-ink">{{ $t('beach.public.totalLine', { days, total }) }}</p>
                        </div>

                        <div class="space-y-3">
                            <label class="block">
                                <span class="mb-1.5 block text-tiny font-semibold uppercase tracking-wider text-ink/45">{{ $t('beach.public.name') }}</span>
                                <input v-model="form.guest_name" type="text" required class="w-full rounded-xl border-driftwood/30 text-body text-ink focus:border-ionian focus:ring-ionian" />
                                <p v-if="form.errors.guest_name" class="mt-1 text-body-sm text-error-600">{{ form.errors.guest_name }}</p>
                            </label>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <label class="block">
                                    <span class="mb-1.5 block text-tiny font-semibold uppercase tracking-wider text-ink/45">{{ $t('beach.public.phone') }}</span>
                                    <input v-model="form.guest_phone" type="tel" required class="w-full rounded-xl border-driftwood/30 text-body text-ink focus:border-ionian focus:ring-ionian" />
                                    <p v-if="form.errors.guest_phone" class="mt-1 text-body-sm text-error-600">{{ form.errors.guest_phone }}</p>
                                </label>
                                <label class="block">
                                    <span class="mb-1.5 block text-tiny font-semibold uppercase tracking-wider text-ink/45">{{ $t('beach.public.email') }}</span>
                                    <input v-model="form.guest_email" type="email" class="w-full rounded-xl border-driftwood/30 text-body text-ink focus:border-ionian focus:ring-ionian" />
                                    <p v-if="form.errors.guest_email" class="mt-1 text-body-sm text-error-600">{{ form.errors.guest_email }}</p>
                                </label>
                            </div>
                        </div>

                        <!-- Honeypot i padukshëm kundër bot-eve -->
                        <input v-model="form.website" type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true" />
                        <p v-if="form.errors.beach_unit_id" role="alert" class="mt-3 rounded-xl border border-error-200 bg-error-50 p-3 text-body-sm text-error-700">{{ form.errors.beach_unit_id }}</p>
                        <p v-if="form.errors.start_date || form.errors.end_date" role="alert" class="mt-3 rounded-xl border border-error-200 bg-error-50 p-3 text-body-sm text-error-700">{{ form.errors.start_date || form.errors.end_date }}</p>

                        <p class="mt-3 flex items-center gap-1.5 text-body-sm text-driftwood"><ShieldCheck class="h-4 w-4" />{{ $t('beach.public.payOnSite') }}</p>
                        <button
                            type="submit"
                            class="mt-3 w-full rounded-xl bg-ionian px-6 py-3.5 text-body font-semibold text-bone transition hover:opacity-90 disabled:opacity-50"
                            :disabled="form.processing || !selectedUnit"
                        >
                            {{ form.processing ? $t('beach.public.booking') : $t('beach.public.bookNow') }}
                        </button>
                    </div>
                </form>
            </div>
        </section>
    </WebsiteLayout>
</template>
