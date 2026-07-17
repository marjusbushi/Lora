<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import {
    ArrowRight,
    BedDouble,
    Box,
    CalendarDays,
    CreditCard,
    FileText,
    LoaderCircle,
    Search,
    Sparkles,
    UserRound,
    Utensils,
    Wrench,
} from 'lucide-vue-next';

const props = defineProps({
    quickLinks: { type: Array, default: () => [] },
});

const { t } = useI18n();
const open = ref(false);
const query = ref('');
const groups = ref([]);
const loading = ref(false);
const failed = ref(false);
const activeIndex = ref(0);
const input = ref(null);
let timer = null;
let controller = null;

const icons = {
    reservation: CalendarDays,
    guest: UserRound,
    room: BedDouble,
    invoice: FileText,
    bill: FileText,
    payment: CreditCard,
    housekeeping: Sparkles,
    maintenance: Wrench,
    pos: Utensils,
    inventory: Box,
    page: ArrowRight,
};

const normalizedQuery = computed(() => query.value.trim().toLocaleLowerCase());
const matchingLinks = computed(() => {
    const term = normalizedQuery.value;
    const links = props.quickLinks.filter((item) => !term || item.label.toLocaleLowerCase().includes(term));
    return links.slice(0, term ? 5 : 7);
});
const visibleGroups = computed(() => {
    const pageGroup = matchingLinks.value.length
        ? [{ key: 'pages', results: matchingLinks.value.map((item) => ({ ...item, type: 'page', subtitle: t('globalSearch.openPage') })) }]
        : [];
    return [...pageGroup, ...groups.value];
});
const flatResults = computed(() => visibleGroups.value.flatMap((group) => group.results));

function show() {
    open.value = true;
    activeIndex.value = 0;
    nextTick(() => input.value?.focus());
}

function close() {
    open.value = false;
    query.value = '';
    groups.value = [];
    failed.value = false;
    controller?.abort();
}

function navigate(item) {
    if (!item?.href) return;
    close();
    router.visit(item.href);
}

function move(step) {
    if (!flatResults.value.length) return;
    activeIndex.value = (activeIndex.value + step + flatResults.value.length) % flatResults.value.length;
    nextTick(() => document.querySelector('[data-global-search-active="true"]')?.scrollIntoView({ block: 'nearest' }));
}

function onKeydown(event) {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        open.value ? close() : show();
        return;
    }
    if (!open.value) return;
    if (event.key === 'Escape') close();
}

async function searchRecords(term) {
    controller?.abort();
    controller = new AbortController();
    loading.value = true;
    failed.value = false;

    try {
        const response = await fetch(`${route('global-search')}?q=${encodeURIComponent(term)}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        });
        if (!response.ok) throw new Error(`Search failed (${response.status})`);
        const payload = await response.json();
        if (query.value.trim() === term) groups.value = payload.groups || [];
    } catch (error) {
        if (error.name !== 'AbortError') {
            groups.value = [];
            failed.value = true;
        }
    } finally {
        if (query.value.trim() === term) loading.value = false;
    }
}

watch(query, (value) => {
    clearTimeout(timer);
    activeIndex.value = 0;
    failed.value = false;
    const term = value.trim();
    if (term.length < 2) {
        controller?.abort();
        groups.value = [];
        loading.value = false;
        return;
    }
    timer = setTimeout(() => searchRecords(term), 250);
});

onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown);
    clearTimeout(timer);
    controller?.abort();
});
</script>

<template>
    <div class="min-w-0 lg:w-full lg:max-w-[520px]">
        <button
            type="button"
            class="hidden h-10 w-full items-center gap-2 rounded-xl border border-neutral-200 bg-neutral-50 px-3 text-left text-body-sm text-neutral-500 transition hover:border-neutral-300 hover:bg-white lg:flex"
            :aria-label="t('globalSearch.open')"
            @click="show"
        >
            <Search class="h-4 w-4 shrink-0" />
            <span class="min-w-0 flex-1 truncate">{{ t('globalSearch.placeholder') }}</span>
            <kbd class="rounded-md border border-neutral-200 bg-white px-1.5 py-0.5 text-tiny font-semibold text-neutral-500">{{ t('globalSearch.shortcut') }}</kbd>
        </button>

        <button
            type="button"
            class="grid h-10 w-10 place-items-center rounded-lg text-neutral-500 transition hover:bg-neutral-100 hover:text-neutral-800 lg:hidden"
            :aria-label="t('globalSearch.open')"
            @click="show"
        >
            <Search class="h-5 w-5" />
        </button>

        <Teleport to="body">
            <Transition
                enter-active-class="duration-150 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="duration-100 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-if="open" class="fixed inset-0 z-[100] bg-primary-950/40 px-3 pt-3 backdrop-blur-[2px] sm:pt-[9vh]" @mousedown.self="close">
                    <div class="mx-auto flex max-h-[min(720px,88vh)] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-2xl">
                        <div class="flex items-center gap-3 border-b border-neutral-200 px-4 py-3">
                            <Search class="h-5 w-5 shrink-0 text-neutral-400" />
                            <input
                                ref="input"
                                v-model="query"
                                type="search"
                                autocomplete="off"
                                class="h-10 min-w-0 flex-1 border-0 bg-transparent p-0 text-body text-primary-900 placeholder:text-neutral-400 focus:ring-0"
                                :placeholder="t('globalSearch.inputPlaceholder')"
                                @keydown.down.prevent="move(1)"
                                @keydown.up.prevent="move(-1)"
                                @keydown.enter.prevent="navigate(flatResults[activeIndex])"
                            />
                            <LoaderCircle v-if="loading" class="h-4 w-4 animate-spin text-primary-600" />
                            <button class="rounded-md border border-neutral-200 px-2 py-1 text-tiny font-semibold text-neutral-500 hover:bg-neutral-50" @click="close">ESC</button>
                        </div>

                        <div class="min-h-[240px] overflow-y-auto p-2 sm:min-h-[320px]">
                            <template v-if="visibleGroups.length">
                                <section v-for="group in visibleGroups" :key="group.key" class="mb-2 last:mb-0">
                                    <p class="px-3 pb-1 pt-2 text-tiny font-bold uppercase tracking-[0.12em] text-neutral-400">
                                        {{ t(`globalSearch.groups.${group.key}`) }}
                                    </p>
                                    <button
                                        v-for="item in group.results"
                                        :key="`${item.type}-${item.href}`"
                                        type="button"
                                        class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition"
                                        :class="flatResults[activeIndex] === item ? 'bg-primary-50 text-primary-900' : 'text-neutral-700 hover:bg-neutral-50'"
                                        :data-global-search-active="flatResults[activeIndex] === item"
                                        @mouseenter="activeIndex = flatResults.indexOf(item)"
                                        @click="navigate(item)"
                                    >
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border border-neutral-200 bg-white text-primary-600">
                                            <component :is="icons[item.type] || Search" class="h-4 w-4" />
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <strong class="block truncate text-body-sm font-semibold">{{ item.title || item.label }}</strong>
                                            <small v-if="item.subtitle" class="block truncate text-small text-neutral-500">{{ item.subtitle }}</small>
                                        </span>
                                        <ArrowRight class="h-4 w-4 shrink-0 text-neutral-300" />
                                    </button>
                                </section>
                            </template>

                            <div v-else-if="failed" class="grid min-h-[280px] place-items-center px-6 text-center">
                                <div><p class="font-semibold text-primary-900">{{ t('globalSearch.errorTitle') }}</p><p class="mt-1 text-body-sm text-neutral-500">{{ t('globalSearch.errorHint') }}</p></div>
                            </div>
                            <div v-else-if="query.trim().length >= 2 && !loading" class="grid min-h-[280px] place-items-center px-6 text-center">
                                <div><p class="font-semibold text-primary-900">{{ t('globalSearch.noResults') }}</p><p class="mt-1 text-body-sm text-neutral-500">{{ t('globalSearch.noResultsHint') }}</p></div>
                            </div>
                            <div v-else-if="loading" class="grid min-h-[280px] place-items-center"><LoaderCircle class="h-7 w-7 animate-spin text-primary-600" /></div>
                        </div>

                        <div class="hidden items-center gap-4 border-t border-neutral-100 bg-neutral-50 px-4 py-2 text-tiny text-neutral-500 sm:flex">
                            <span>↑↓ {{ t('globalSearch.navigate') }}</span><span>↵ {{ t('globalSearch.openResult') }}</span><span>ESC {{ t('globalSearch.close') }}</span>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
