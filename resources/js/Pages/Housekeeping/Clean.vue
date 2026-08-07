<script setup>
import { getIntlLocale, translate } from '@/i18n';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    BedDouble,
    Check,
    ChevronRight,
    Clock3,
    House,
    Save,
    ShieldCheck,
    ShowerHead,
    UserRound,
} from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Modal from '@/Components/UI/Modal.vue';
import Textarea from '@/Components/UI/Textarea.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';

const props = defineProps({
    task: Object,
});

const typeLabel = {
    checkout_clean: 'Check-out',
    stayover_clean: 'Ditor',
    deep_clean: translate('admin.generated.k_a0d531b7e261'),
    inspection: translate('shared.housekeepingClean.inspection'),
};

// Optimistic local copy: a tap responds instantly, then saves after a short pause.
const items = ref((props.task.checklist || []).map((item) => ({ ...item })));
const doneCount = computed(() => items.value.filter((item) => item.done).length);
const total = computed(() => items.value.length);
const allDone = computed(() => total.value === 0 || doneCount.value === total.value);
const pct = computed(() => total.value === 0 ? 100 : Math.round((doneCount.value / total.value) * 100));

const groupDefinitions = [
    {
        key: 'linen',
        label: translate('admin.generated.k_212eda01297e'),
        icon: BedDouble,
        matches: /\u00E7ar\u00E7af|krevat|dyshek|peshqir|tekst/i,
    },
    {
        key: 'bathroom',
        label: translate('admin.generated.k_94d7c2d7af96'),
        icon: ShowerHead,
        matches: /banj|wc|dush|lavaman|sapun|shampo|higjen|amenit/i,
    },
    {
        key: 'final',
        label: translate('admin.generated.k_45dc76b2bd0a'),
        icon: ShieldCheck,
        matches: /kontroll|d\u00EBmt|demt|pajis|televiz|\btv\b|\bac\b|drit|minibar|harruar/i,
    },
];

const groupedItems = computed(() => {
    const groups = [
        ...groupDefinitions.map((group) => ({ ...group, items: [] })),
        { key: 'room', label: translate('admin.generated.k_0c61f9b6d7d1'), icon: House, items: [] },
    ];

    items.value.forEach((item, index) => {
        const enriched = { ...item, index };
        const matchingGroup = groups.find((group) => group.matches?.test(item.label || ''));
        (matchingGroup || groups.find((group) => group.key === 'room')).items.push(enriched);
    });

    const order = ['linen', 'bathroom', 'room', 'final'];
    return groups.filter((group) => group.items.length).sort((a, b) => order.indexOf(a.key) - order.indexOf(b.key));
});

function itemTitle(label) {
    return String(label || '').replace(/\s*\([^)]*\)\s*$/, '').trim();
}

function itemDetail(label) {
    return String(label || '').match(/\(([^)]*)\)\s*$/)?.[1] || '';
}

let saveTimer = null;
function persist() {
    router.patch(
        route('housekeeping.checklist', props.task.id),
        { items: items.value.map((item) => ({ label: item.label, done: item.done })) },
        { preserveScroll: true, preserveState: true },
    );
}

function toggle(index) {
    items.value[index].done = !items.value[index].done;
    clearTimeout(saveTimer);
    saveTimer = setTimeout(persist, 400);
}

// Live timer comes from the server and survives refresh/reopen.
const startedMs = props.task.started_at ? Date.parse(props.task.started_at) : null;
const now = ref(Date.now());
let ticker = null;
onMounted(() => {
    ticker = setInterval(() => (now.value = Date.now()), 1000);
});
onUnmounted(() => {
    clearInterval(ticker);
    clearTimeout(saveTimer);
});

const elapsed = computed(() => {
    if (!startedMs) return '00:00';
    let seconds = Math.max(0, Math.floor((now.value - startedMs) / 1000));
    const hours = Math.floor(seconds / 3600);
    seconds -= hours * 3600;
    const minutes = Math.floor(seconds / 60);
    const remaining = seconds - minutes * 60;
    const pad = (number) => String(number).padStart(2, '0');
    return hours > 0 ? `${hours}:${pad(minutes)}:${pad(remaining)}` : `${pad(minutes)}:${pad(remaining)}`;
});

const startedTime = computed(() => {
    if (!startedMs) return '—';
    return new Date(startedMs).toLocaleTimeString(getIntlLocale(), { hour: '2-digit', minute: '2-digit' });
});

const finishing = ref(false);
function finish() {
    if (!allDone.value) return;
    clearTimeout(saveTimer);
    finishing.value = true;
    router.patch(
        route('housekeeping.checklist', props.task.id),
        { items: items.value.map((item) => ({ label: item.label, done: item.done })) },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                router.patch(
                    route('housekeeping.status', props.task.id),
                    { status: 'completed' },
                    {
                        onSuccess: () => router.visit(route('housekeeping.index')),
                        onFinish: () => (finishing.value = false),
                    },
                );
            },
            onError: () => (finishing.value = false),
        },
    );
}

const showIssue = ref(false);
const issueText = ref('');
const setMaintenance = ref(false);
function submitIssue() {
    router.post(
        route('housekeeping.issue', props.task.id),
        { issue_reported: issueText.value, set_maintenance: setMaintenance.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                showIssue.value = false;
                issueText.value = '';
                setMaintenance.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="`Pastrim — Dhoma ${task.room?.room_number ?? ''}`" />

    <div class="min-h-screen bg-neutral-50 pb-24 text-primary-900">
        <header class="sticky top-0 z-20 border-b border-neutral-200 bg-white/95 backdrop-blur">
            <div class="mx-auto flex h-[72px] max-w-6xl items-center gap-3 px-4 sm:px-6">
                <Link
                    :href="route('housekeeping.index')"
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-neutral-200 text-neutral-500 no-underline transition-colors hover:bg-neutral-50"
                    :aria-label="$t('admin.generated.k_a20afb15e92b')"
                >
                    <ArrowLeft class="h-5 w-5" :stroke-width="2" />
                </Link>

                <div class="min-w-0">
                    <h1 class="truncate text-h4 font-semibold leading-none text-primary-900">{{ $t('admin.generated.k_98a2941a8f74') }} {{ task.room?.room_number || '—' }}</h1>
                    <p class="mt-1 truncate text-small text-neutral-500">
                        {{ task.room?.room_type || $t('admin.generated.k_786323a8a1d4') }}<span v-if="task.room?.floor"> {{ $t('admin.generated.k_20e72de54dbf') }} {{ task.room.floor }}</span>
                    </p>
                </div>

                <div class="ml-auto flex shrink-0 items-center gap-2">
                    <span v-if="task.priority === 'urgent'" class="hidden items-center gap-1.5 rounded-full border border-warning-200 bg-warning-50 px-2.5 py-1.5 text-tiny font-semibold uppercase tracking-wide text-warning-700 sm:inline-flex">
                        <span class="h-1.5 w-1.5 rounded-full bg-warning-500" /> {{ $t('admin.generated.k_41d5c3257de4') }} </span>
                    <span class="rounded-full border px-2.5 py-1.5 text-tiny font-semibold uppercase tracking-wide"
                        :class="task.type === 'checkout_clean' ? 'border-error-200 bg-error-50 text-error-700' : 'border-info-200 bg-info-50 text-info-700'">
                        {{ typeLabel[task.type] || task.type }}
                    </span>
                </div>
            </div>
        </header>

        <main class="mx-auto grid max-w-6xl gap-5 p-3 sm:p-6 lg:grid-cols-[320px_minmax(0,1fr)]">
            <aside class="self-start overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm lg:sticky lg:top-24">
                <div class="bg-gradient-to-br from-primary-900 to-primary-700 px-5 py-5 text-white sm:px-6 sm:py-6">
                    <div class="flex items-center justify-between gap-4 lg:block">
                        <div>
                            <p class="text-tiny font-semibold uppercase tracking-[0.16em] text-primary-200">{{ $t('admin.generated.k_dce9ac3412d3') }}</p>
                            <p class="mt-2 text-4xl font-semibold leading-none tabular-nums sm:text-5xl">{{ elapsed }}</p>
                        </div>
                        <div class="inline-flex items-center gap-2 rounded-xl bg-white/10 px-3 py-2 text-small text-primary-100 lg:mt-4">
                            <span class="h-2 w-2 rounded-full bg-success-300 ring-4 ring-success-300/10" /> {{ $t('admin.generated.k_f2e249d56d29') }} </div>
                    </div>
                </div>

                <div class="px-5 py-4 sm:px-6 sm:py-5">
                    <div class="flex items-end justify-between">
                        <p class="text-body-sm font-semibold text-primary-900">{{ $t('admin.generated.k_82ebcb63fd91') }}</p>
                        <p class="text-h3 font-semibold text-success-700">{{ doneCount }}/{{ total }}</p>
                    </div>
                    <div class="mt-2.5 h-2.5 overflow-hidden rounded-full bg-neutral-200">
                        <div class="h-full rounded-full bg-gradient-to-r from-success-600 to-success-400 transition-all duration-300" :style="{ width: `${pct}%` }" />
                    </div>

                    <div class="mt-5 hidden space-y-3 border-t border-neutral-100 pt-4 lg:block">
                        <div class="flex items-center gap-3 text-small text-neutral-600">
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-neutral-100"><UserRound class="h-4 w-4" /></span>
                            <span>{{ $t('admin.generated.k_ac86c5e2c90f') }} <b class="text-neutral-800">{{ task.assigned_to || task.started_by || $t('admin.generated.k_08eeebda65bc') }}</b></span>
                        </div>
                        <div class="flex items-center gap-3 text-small text-neutral-600">
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-neutral-100"><Clock3 class="h-4 w-4" /></span>
                            <span>{{ $t('admin.generated.k_a94e0b9eff48') }} <b class="text-neutral-800">{{ startedTime }}</b></span>
                        </div>
                        <div class="flex items-center gap-3 text-small text-neutral-600">
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-neutral-100"><Save class="h-4 w-4" /></span>
                            <span>{{ $t('admin.generated.k_e21bd00f595d') }}</span>
                        </div>
                    </div>
                </div>
            </aside>

            <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm">
                <div class="flex items-center justify-between gap-4 border-b border-neutral-200 px-4 py-4 sm:px-5">
                    <div>
                        <h2 class="text-h4 font-semibold text-primary-900">{{ $t('admin.generated.k_cfa19a5a227b') }}</h2>
                        <p class="mt-0.5 text-small text-neutral-500">{{ $t('admin.generated.k_188d0eaf25f6') }}</p>
                    </div>
                    <span class="rounded-lg bg-success-50 px-2.5 py-1.5 text-small font-semibold text-success-700">{{ pct }}%</span>
                </div>

                <div v-if="total" class="pb-3">
                    <div v-for="group in groupedItems" :key="group.key" class="px-3 pt-4 sm:px-5 sm:pt-5">
                        <div class="mb-2.5 flex items-center gap-2 text-tiny font-semibold uppercase tracking-[0.12em] text-neutral-600">
                            <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-success-50 text-success-700">
                                <component :is="group.icon" class="h-4 w-4" :stroke-width="1.8" />
                            </span>
                            {{ group.label }}
                        </div>

                        <button
                            v-for="item in group.items"
                            :key="item.index"
                            type="button"
                            class="mb-2 flex min-h-16 w-full items-center gap-3 rounded-xl border px-3 py-3 text-left transition-all active:scale-[0.995] sm:px-4"
                            :class="item.done ? 'border-success-200 bg-success-50/70' : 'border-neutral-200 bg-white hover:border-neutral-300 hover:bg-neutral-50'"
                            @click="toggle(item.index)"
                        >
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-[10px] border-2 transition-colors"
                                :class="item.done ? 'border-success-600 bg-success-600 text-white' : 'border-neutral-300 bg-white text-transparent'">
                                <Check class="h-4 w-4" :stroke-width="3" />
                            </span>
                            <span class="min-w-0">
                                <span class="block text-body-sm font-medium" :class="item.done ? 'text-neutral-500 line-through' : 'text-primary-900'">{{ itemTitle(item.label) }}</span>
                                <span v-if="itemDetail(item.label)" class="mt-0.5 block text-small" :class="item.done ? 'text-neutral-400 line-through' : 'text-neutral-500'">{{ itemDetail(item.label) }}</span>
                            </span>
                        </button>
                    </div>
                </div>

                <div v-else class="px-6 py-14 text-center">
                    <ShieldCheck class="mx-auto h-9 w-9 text-success-500" :stroke-width="1.6" />
                    <p class="mt-3 text-body-sm font-medium text-primary-900">{{ $t('admin.generated.k_321b138830ef') }}</p>
                    <p class="mt-1 text-small text-neutral-500">{{ $t('admin.generated.k_75818e1d03f6') }}</p>
                </div>

                <div v-if="task.issue_reported" class="mx-3 mb-4 flex items-start gap-2.5 rounded-xl border border-error-200 bg-error-50 px-3 py-3 text-small text-error-700 sm:mx-5">
                    <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                    <span><b>{{ $t('admin.generated.k_7a1f0b6f2ac6') }}</b> {{ task.issue_reported }}</span>
                </div>
            </section>
        </main>

        <footer class="fixed inset-x-0 bottom-0 z-20 border-t border-neutral-200 bg-white/95 backdrop-blur">
            <div class="mx-auto flex min-h-[78px] max-w-6xl items-center gap-3 px-3 py-3 sm:px-6">
                <button type="button" class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-error-200 bg-error-50 text-error-700 sm:w-auto sm:px-4" @click="showIssue = true">
                    <AlertTriangle class="h-4 w-4" :stroke-width="2" />
                    <span class="ml-2 hidden text-body-sm font-semibold sm:inline">{{ $t('admin.generated.k_112172e502d7') }}</span>
                </button>

                <p class="hidden text-small text-neutral-500 md:block">{{ $t('admin.generated.k_7fd5845b61fb') }}</p>

                <button
                    type="button"
                    class="ml-auto flex h-12 flex-1 items-center justify-center gap-2 rounded-xl bg-success-700 px-4 text-body-sm font-semibold text-white shadow-sm transition-colors hover:bg-success-800 disabled:cursor-not-allowed disabled:bg-neutral-300 disabled:text-neutral-500 sm:max-w-xs"
                    :disabled="!allDone || finishing"
                    @click="finish"
                >
                    <span v-if="finishing">{{ $t('admin.generated.k_b90181e69eb2') }}</span>
                    <template v-else>
{{ $t('admin.generated.k_46c233d9d106') }} <span v-if="!allDone" class="text-tiny font-medium opacity-80">{{ doneCount }}/{{ total }}</span>
                        <ChevronRight class="h-4 w-4" :stroke-width="2.3" />
                    </template>
                </button>
            </div>
        </footer>

        <Modal :show="showIssue" :title="$t('admin.generated.k_20a978599f7a')" max-width="md" @close="showIssue = false">
            <div class="space-y-4">
                <FormGroup :label="$t('admin.generated.k_a48c07c5cada')" required>
                    <Textarea v-model="issueText" :placeholder="$t('admin.generated.k_771f4e699c79')" :rows="3" />
                </FormGroup>
                <label class="flex items-center gap-2 text-body-sm text-neutral-700">
                    <input v-model="setMaintenance" type="checkbox" class="h-4 w-4 rounded border-neutral-300 text-accent-600" />
{{ $t('admin.generated.k_c45c1e2bbafa') }} </label>
            </div>
            <template #footer>
                <Button variant="outline" @click="showIssue = false">{{ $t('admin.generated.k_5263953371b8') }}</Button>
                <Button variant="danger" :disabled="!issueText.trim()" @click="submitIssue">{{ $t('admin.generated.k_28886d1b60ab') }}</Button>
            </template>
        </Modal>
    </div>
</template>
