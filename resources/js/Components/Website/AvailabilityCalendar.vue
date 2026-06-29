<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

// Public availability calendar for /book. Shows per-day free rooms for the
// selected room type (green = free w/ count, grey = full, dim = past), and lets
// the guest pick a check-in→check-out range (a range that crosses a full night
// is rejected). Two-way binds checkIn / checkOut as 'YYYY-MM-DD'.
const props = defineProps({
    roomTypeId: { type: [String, Number], default: '' },
    checkIn: { type: String, default: '' },
    checkOut: { type: String, default: '' },
});
const emit = defineEmits(['update:checkIn', 'update:checkOut']);

const WEEKDAYS = ['Hën', 'Mar', 'Mër', 'Enj', 'Pre', 'Sht', 'Die'];

const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const todayStr = ymd(new Date());

const days = ref({});       // 'YYYY-MM-DD' -> free count
const loading = ref(false);
const message = ref('');

const now = new Date();
const viewMonth = ref(new Date(now.getFullYear(), now.getMonth(), 1));
const minMonth = new Date(now.getFullYear(), now.getMonth(), 1);
const maxMonth = new Date(now.getFullYear(), now.getMonth() + 6, 1); // bookable window ~6 months out

const monthLabel = computed(() => viewMonth.value.toLocaleDateString('sq-AL', { month: 'long', year: 'numeric' }));
const canPrev = computed(() => viewMonth.value > minMonth);
const canNext = computed(() => viewMonth.value < maxMonth);

const cells = computed(() => {
    const first = viewMonth.value;
    const year = first.getFullYear();
    const month = first.getMonth();
    const lead = (first.getDay() + 6) % 7; // Monday-first
    const total = new Date(year, month + 1, 0).getDate();
    const arr = [];
    for (let i = 0; i < lead; i++) arr.push(null);
    for (let d = 1; d <= total; d++) arr.push(ymd(new Date(year, month, d)));
    return arr;
});

async function fetchAvailability() {
    loading.value = true;
    const first = viewMonth.value;
    const monthStart = ymd(first);
    const monthEnd = ymd(new Date(first.getFullYear(), first.getMonth() + 1, 0));
    const from = monthStart < todayStr ? todayStr : monthStart;
    try {
        const params = { from, to: monthEnd };
        if (props.roomTypeId) params.room_type_id = props.roomTypeId; // omit when "all types" so the exists rule isn't hit with ''
        const { data } = await axios.get('/book/availability', { params });
        days.value = { ...days.value, ...data.days };
    } catch (e) {
        // leave days as-is on failure
    }
    loading.value = false;
}

watch(() => props.roomTypeId, () => { days.value = {}; fetchAvailability(); });
watch(viewMonth, fetchAvailability);
onMounted(fetchAvailability);

function free(dateStr) {
    return days.value[dateStr];
}
function isPast(dateStr) {
    return dateStr < todayStr;
}
function isFull(dateStr) {
    return free(dateStr) === 0;
}
function selectingCheckIn() {
    return !props.checkIn || (props.checkIn && props.checkOut);
}
function clickable(dateStr) {
    if (isPast(dateStr)) return false;
    if (selectingCheckIn()) return (free(dateStr) ?? 0) > 0;
    // choosing a check-out: any day after check-in (validated on click); or restart on a free earlier/same day
    return dateStr > props.checkIn ? true : (free(dateStr) ?? 0) > 0;
}
function inRange(dateStr) {
    return props.checkIn && props.checkOut && dateStr > props.checkIn && dateStr < props.checkOut;
}

function pick(dateStr) {
    message.value = '';
    if (!clickable(dateStr)) return;

    if (selectingCheckIn()) {
        emit('update:checkIn', dateStr);
        emit('update:checkOut', '');
        return;
    }
    // have check-in, choosing check-out
    if (dateStr <= props.checkIn) {
        emit('update:checkIn', dateStr);
        emit('update:checkOut', '');
        return;
    }
    // validate every NIGHT in [checkIn, dateStr) has a free room
    let cur = new Date(props.checkIn + 'T00:00:00');
    const end = new Date(dateStr + 'T00:00:00');
    while (cur < end) {
        const ds = ymd(cur);
        if ((free(ds) ?? 0) <= 0) {
            message.value = 'Ka net të zëna në këtë interval — zgjidh data të tjera.';
            emit('update:checkIn', dateStr);
            emit('update:checkOut', '');
            return;
        }
        cur.setDate(cur.getDate() + 1);
    }
    emit('update:checkOut', dateStr);
}

function prevMonth() {
    if (!canPrev.value) return;
    viewMonth.value = new Date(viewMonth.value.getFullYear(), viewMonth.value.getMonth() - 1, 1);
}
function nextMonth() {
    if (!canNext.value) return;
    viewMonth.value = new Date(viewMonth.value.getFullYear(), viewMonth.value.getMonth() + 1, 1);
}
</script>

<template>
    <div class="select-none">
        <!-- Header -->
        <div class="flex items-center justify-between mb-3">
            <button type="button" :disabled="!canPrev" class="h-8 w-8 inline-flex items-center justify-center rounded-md text-ink/60 hover:bg-limestone disabled:opacity-30 disabled:cursor-not-allowed" @click="prevMonth">
                <ChevronLeft class="h-5 w-5" />
            </button>
            <span class="text-body font-medium text-ink capitalize">{{ monthLabel }}</span>
            <button type="button" :disabled="!canNext" class="h-8 w-8 inline-flex items-center justify-center rounded-md text-ink/60 hover:bg-limestone disabled:opacity-30 disabled:cursor-not-allowed" @click="nextMonth">
                <ChevronRight class="h-5 w-5" />
            </button>
        </div>

        <!-- Weekday row -->
        <div class="grid grid-cols-7 gap-1 mb-1">
            <span v-for="w in WEEKDAYS" :key="w" class="text-center text-tiny text-ink/40 uppercase tracking-wide py-1">{{ w }}</span>
        </div>

        <!-- Days -->
        <div class="grid grid-cols-7 gap-1" :class="loading && 'opacity-60'">
            <template v-for="(c, i) in cells" :key="i">
                <div v-if="!c" />
                <button
                    v-else
                    type="button"
                    :disabled="!clickable(c)"
                    class="relative h-12 rounded-lg text-body-sm flex flex-col items-center justify-center transition-colors"
                    :class="[
                        c === checkIn || c === checkOut ? 'bg-ionian text-bone font-semibold'
                          : inRange(c) ? 'bg-ionian/15 text-ink'
                          : isPast(c) ? 'text-ink/25 cursor-not-allowed'
                          : isFull(c) ? 'bg-limestone/60 text-ink/30 line-through cursor-not-allowed'
                          : 'bg-success-50 text-success-800 hover:bg-success-100 cursor-pointer',
                    ]"
                    @click="pick(c)"
                >
                    <span>{{ Number(c.slice(-2)) }}</span>
                    <span v-if="!isPast(c) && free(c) > 0 && c !== checkIn && c !== checkOut" class="text-[9px] leading-none mt-0.5 opacity-70">{{ free(c) }}</span>
                </button>
            </template>
        </div>

        <!-- Legend + message -->
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-3 text-tiny text-ink/50">
            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded bg-success-100 ring-1 ring-success-200" /> E lirë (numri = dhoma)</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded bg-limestone/60" /> E zënë</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded bg-ionian" /> Zgjedhja jote</span>
        </div>
        <p v-if="message" class="mt-2 text-small text-error-600">{{ message }}</p>
    </div>
</template>
