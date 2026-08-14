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
    currency: { type: String, default: '€' },
    paymentMode: { type: String, default: 'both' }, // cash | online | both
});

const payNoteKey = computed(() => ({
    cash: 'beach.public.payOnSite',
    online: 'beach.public.payOnlineRequired',
    both: 'beach.public.payChoice',
}[props.paymentMode] ?? 'beach.public.payChoice'));

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
// Çmimet reale per zonë për datat e zgjedhura (nga serveri): {total, min_daily, max_daily}
const zonePricing = ref({});
const selectedUnit = ref(null); // {id, number, zoneId, zoneName, price}
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
        zonePricing.value = data.zone_pricing ?? {};
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
        zoneId: zone.id,
        zoneName: zone.name,
        price: Number(zone.price_per_day),
    };
}

const trimPrice = (value) => String(Math.round(Number(value) * 100) / 100);

// Etiketa e çmimit të zonës për DATAT e zgjedhura: "€12" ose "€7–12" kur
// intervali kap dy nivele çmimi (sezone). Pa të dhëna nga serveri → baza.
function zonePriceLabel(zone) {
    const pricing = zonePricing.value[zone.id];
    if (!pricing) return props.currency + zone.price_per_day;
    const min = trimPrice(pricing.min_daily);
    const max = trimPrice(pricing.max_daily);
    return min === max ? props.currency + min : `${props.currency}${min}–${max}`;
}

// Totali i shfaqur = ai i serverit për zonën (i njëjti resolver sezonal që
// ruan rezervimin); fallback te llogaritja bazë nëse mungon në përgjigje.
const total = computed(() => {
    if (!selectedUnit.value) return null;
    const pricing = zonePricing.value[selectedUnit.value.zoneId];
    if (pricing) return Number(pricing.total).toFixed(2);
    return (days.value * selectedUnit.value.price).toFixed(2);
});

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

                <!-- Hapi 2: harta e plazhit në një faqe — deti me valë të gjalla, rreshtat me rrugicë mes tyre -->
                <div v-else-if="step === 2" class="space-y-5">
                    <div class="beach-card overflow-hidden rounded-2xl border border-driftwood/20 shadow-md">
                        <!-- Deti: gradient thellësie + 4 shtresa valësh me parallax; vala e përparme puqet me rërën -->
                        <div class="beach-sea relative h-24 sm:h-32">
                            <span class="absolute left-1/2 top-[32%] -translate-x-1/2 -translate-y-1/2 text-tiny font-semibold uppercase tracking-[0.35em] text-bone/80">{{ $t('beach.public.sea') }}</span>
                            <div class="wave wave-back" aria-hidden="true">
                                <svg viewBox="0 0 2400 60" preserveAspectRatio="none"><path d="M0 30 Q100 14 200 30 T400 30 T600 30 T800 30 T1000 30 T1200 30 T1400 30 T1600 30 T1800 30 T2000 30 T2200 30 T2400 30 V60 H0 Z" /></svg>
                            </div>
                            <div class="wave wave-mid" aria-hidden="true">
                                <svg viewBox="0 0 2400 60" preserveAspectRatio="none"><path d="M0 30 Q100 14 200 30 T400 30 T600 30 T800 30 T1000 30 T1200 30 T1400 30 T1600 30 T1800 30 T2000 30 T2200 30 T2400 30 V60 H0 Z" /></svg>
                            </div>
                            <div class="wave wave-foam" aria-hidden="true">
                                <svg viewBox="0 0 2400 60" preserveAspectRatio="none"><path d="M0 30 Q75 8 150 30 T300 30 T450 30 T600 30 T750 30 T900 30 T1050 30 T1200 30 T1350 30 T1500 30 T1650 30 T1800 30 T1950 30 T2100 30 T2250 30 T2400 30 V60 H0 Z" /></svg>
                            </div>
                            <div class="wave wave-front" aria-hidden="true">
                                <svg viewBox="0 0 2400 60" preserveAspectRatio="none"><path d="M0 30 Q75 8 150 30 T300 30 T450 30 T600 30 T750 30 T900 30 T1050 30 T1200 30 T1350 30 T1500 30 T1650 30 T1800 30 T1950 30 T2100 30 T2250 30 T2400 30 V60 H0 Z" /></svg>
                            </div>
                        </div>

                        <!-- Rëra: rreshtat e çadrave, të parët më afër detit -->
                        <div class="beach-sand p-4 sm:p-6">
                            <div class="mb-4 flex flex-wrap items-center justify-center gap-x-5 gap-y-1 text-body-sm text-driftwood">
                                <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-ionian" />{{ $t('beach.public.legendFree') }}</span>
                                <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-driftwood/30" />{{ $t('beach.public.legendBusy') }}</span>
                                <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-brass" />{{ $t('beach.public.legendSelected') }}</span>
                            </div>
                            <template v-for="(zone, index) in zones" :key="zone.id">
                                <!-- Rrugica e kalimit mes rreshtave -->
                                <div v-if="index > 0" class="walkway my-5" role="presentation" />
                                <div class="mb-3 flex flex-wrap items-center gap-2.5">
                                    <h2 class="font-serif text-xl font-medium text-ink">{{ zone.name }}</h2>
                                    <span class="rounded-full bg-white/80 px-3 py-0.5 text-body-sm font-semibold text-brass-dark ring-1 ring-brass/25">{{ $t('beach.public.pricePerDay', { price: zonePriceLabel(zone) }) }}</span>
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
                            {{ $t('beach.public.selectionSummary', { number: selectedUnit.number, zone: selectedUnit.zoneName, days, total: currency + total }) }}
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
                            <p class="text-lg font-bold text-ink">{{ $t('beach.public.totalLine', { days, total: currency + total }) }}</p>
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

                        <p class="mt-3 flex items-center gap-1.5 text-body-sm text-driftwood"><ShieldCheck class="h-4 w-4" />{{ $t(payNoteKey) }}</p>
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

<style scoped>
/* Ngjyrat këtu janë nga paleta "Ionian Calm" (tailwind.config.js):
   ionian #2E6E72 / dark #244F52 / light #3E8589 · bone #FAF7F1 · driftwood #8A8276 */

.beach-card {
    animation: beach-rise 0.45s ease-out both;
}
@keyframes beach-rise {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}

.beach-sea {
    overflow: hidden;
    background: linear-gradient(180deg, #1c4245 0%, #244f52 34%, #2e6e72 70%, #3e8589 100%);
}
/* Shkëlqimi i diellit mbi ujë */
.beach-sea::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(120% 90% at 72% 0%, rgba(250, 247, 241, 0.18), transparent 55%);
}

.wave {
    position: absolute;
    left: 0;
    width: 200%;
    animation-name: wave-drift;
    animation-timing-function: linear;
    animation-iteration-count: infinite;
}
.wave svg {
    display: block;
    width: 100%;
    height: 100%;
}
/* Shtresat: e pasmja shkëlqim i lehtë, e mesmja thellësi, shkuma e bardhë pak
   mbi valën e përparme (me ritëm tjetër, që kreshta të marrë frymë), e përparmja
   ka ngjyrën e rërës së lagur — deti derdhet në rërë pa vijë ndarëse. */
.wave-back  { bottom: 16px; height: 30px; fill: rgba(250, 247, 241, 0.14); animation-duration: 19s; }
.wave-mid   { bottom: 7px;  height: 26px; fill: rgba(62, 133, 137, 0.6);  animation-duration: 13s; animation-direction: reverse; }
.wave-foam  { bottom: 2px;  height: 22px; fill: rgba(255, 255, 255, 0.7); animation-duration: 9.5s; }
.wave-front { bottom: -1px; height: 20px; fill: #f3e9d2;                  animation-duration: 7.5s; }
@keyframes wave-drift {
    from { transform: translateX(0); }
    to   { transform: translateX(-50%); }
}

.beach-sand {
    /* Rërë e lagur afër detit → rërë e ngrohtë poshtë + teksturë kokrrizash */
    background-color: #faf3e3;
    background-image:
        radial-gradient(rgba(138, 130, 118, 0.10) 1px, transparent 1.4px),
        linear-gradient(180deg, #f3e9d2 0%, #faf3e3 26%, #fbf6ea 100%);
    background-size: 13px 13px, 100% 100%;
}

/* Rrugica e kalimit mes rreshtave — dërrasa të pjerrëta, jo vijë e thyer */
.walkway {
    height: 10px;
    border-radius: 9999px;
    background: repeating-linear-gradient(-55deg, rgba(138, 130, 118, 0.16) 0 5px, transparent 5px 11px);
}

@media (prefers-reduced-motion: reduce) {
    .beach-card,
    .wave { animation: none; }
}
</style>
