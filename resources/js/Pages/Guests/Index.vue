<script setup>
import { translate } from '@/i18n';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import InfoTip from '@/Components/UI/InfoTip.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import Select from '@/Components/UI/Select.vue';
import Textarea from '@/Components/UI/Textarea.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';
import ActionMenu from '@/Components/UI/ActionMenu.vue';
import { channelMeta } from '@/channels';
import { countryOptions, countryName } from '@/countries';
import {
    BedDouble,
    CalendarDays,
    CircleAlert,
    Copy,
    Eye,
    Mail,
    Pencil,
    Phone,
    Search,
    Trash2,
    UserPlus,
    Users,
} from 'lucide-vue-next';

const props = defineProps({
    guests: {
        type: Object,
        default: () => ({
            data: [],
            total: 0,
            from: null,
            to: null,
            current_page: 1,
            last_page: 1,
            prev_page_url: null,
            next_page_url: null,
        }),
    },
    filters: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
    permissions: { type: Object, default: () => ({}) },
});

const page = usePage();
const sharedPermissions = computed(() => page.props.auth?.user?.permissions || []);
const canCreate = computed(() => props.permissions.create ?? sharedPermissions.value.includes('create_guests'));
const canUpdate = computed(() => props.permissions.update ?? sharedPermissions.value.includes('update_guests'));
const canDelete = computed(() => props.permissions.delete ?? sharedPermissions.value.includes('delete_guests'));

const toasts = ref(null);
const showCreateModal = ref(false);
const showEditModal = ref(false);
const showDeleteModal = ref(false);
const selectedGuest = ref(null);
const deleting = ref(false);

const searchQuery = ref(props.filters.search || '');
const nationality = ref(props.filters.nationality || '');
const segment = ref(props.filters.segment || 'all');
const sortBy = ref(props.filters.sort || 'last_stay');
let searchTimeout = null;

const menuItemClass = 'flex w-full items-center gap-2.5 px-3 py-2 text-left text-body-sm text-neutral-700 transition-colors hover:bg-neutral-50 no-underline';
const profileLinkClass = 'inline-flex items-center justify-center whitespace-nowrap rounded-md border border-neutral-200 bg-white px-2 py-1.5 text-body-sm font-medium text-neutral-700 no-underline transition-all hover:border-neutral-300 hover:bg-neutral-50 focus:outline-none focus:ring-2 focus:ring-accent-500/40 focus:ring-offset-1';
const hasActiveFilters = computed(() => Boolean(
    searchQuery.value
    || nationality.value
    || segment.value !== 'all'
    || sortBy.value !== 'last_stay',
));

const docTypeOptions = [
    { value: 'id_card', label: translate('admin.generated.k_9edeb34c5008') },
    { value: 'passport', label: translate('admin.generated.k_78716f596fbe') },
    { value: 'drivers_license', label: translate('admin.generated.k_d590ae0df75a') },
];

const nationalityFilterOptions = [
    { value: '', label: translate('admin.generated.k_f6da25f7fa37') },
    ...countryOptions,
];

const sortOptions = [
    { value: 'last_stay', label: translate('admin.generated.k_b79195c5331a') },
    { value: 'next_stay', label: translate('admin.generated.k_8de09a0a891d') },
    { value: 'stays', label: translate('admin.generated.k_85f782701312') },
    { value: 'name', label: translate('admin.generated.k_65a65a4974fd') },
];

const stateMeta = {
    in_house: { label: translate('admin.generated.k_17e41bcc53f0'), variant: 'success' },
    arriving_today: { label: translate('admin.generated.k_e19a4364a716'), variant: 'info' },
    arriving_soon: { label: translate('admin.generated.k_2c3bbb2e77c7'), variant: 'accent' },
    upcoming: { label: translate('admin.generated.k_3e257c51a300'), variant: 'accent' },
    past: { label: translate('admin.generated.k_c103d38f0f65'), variant: 'neutral' },
    new: { label: translate('admin.generated.k_2688685ae535'), variant: 'neutral' },
};

const segmentOptions = computed(() => [
    { value: 'all', label: translate('admin.generated.k_c74e497a03cb'), count: number(props.stats.total) },
    { value: 'in_house', label: translate('admin.generated.k_17e41bcc53f0'), count: number(props.stats.in_house_profiles) },
    { value: 'arriving_7_days', label: translate('admin.generated.k_47415767fa47'), count: number(props.stats.arriving_7_days) },
    { value: 'returning', label: translate('admin.generated.k_9d14592446ea'), count: number(props.stats.returning) },
    { value: 'incomplete', label: translate('admin.generated.k_0a2615079eb7'), count: number(props.stats.incomplete) },
    { value: 'attention', label: translate('admin.generated.k_459c401727be'), count: number(props.stats.attention) },
]);

const metrics = computed(() => [
    {
        key: 'total',
        label: translate('admin.generated.k_16e8b6cf9ffb'),
        value: number(props.stats.total),
        help: translate('shared.guests.activeProfiles'),
        tip: translate('reports360.help.gdTotal'),
        icon: Users,
        iconClass: 'bg-accent-50 text-accent-700',
        segment: 'all',
    },
    {
        key: 'in_house',
        label: translate('admin.generated.k_f63cf80f1ba5'),
        value: number(props.stats.in_house),
        help: translate('shared.guests.inHouseDetail', {
            rooms: number(props.stats.in_house_rooms),
            profiles: number(props.stats.in_house_profiles),
        }),
        tip: translate('reports360.help.gdInside'),
        icon: BedDouble,
        iconClass: 'bg-info-50 text-info-700',
        segment: 'in_house',
    },
    {
        key: 'arriving',
        label: translate('admin.generated.k_cfbfcfa4b6f4'),
        value: number(props.stats.arriving_7_days),
        help: translate('admin.generated.k_724cd811a715', { p0: number(props.stats.arriving_returning) }),
        tip: translate('reports360.help.gdArriving'),
        icon: CalendarDays,
        iconClass: 'bg-primary-50 text-primary-700',
        segment: 'arriving_7_days',
    },
    {
        key: 'incomplete',
        label: translate('admin.generated.k_0f218be47b55'),
        value: number(props.stats.incomplete),
        help: translate('admin.generated.k_d0c1bb2ce171'),
        tip: translate('reports360.help.gdIncomplete'),
        icon: CircleAlert,
        iconClass: 'bg-warning-50 text-warning-700',
        segment: 'incomplete',
    },
]);

const createForm = useForm({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    document_type: '',
    document_number: '',
    nationality: '',
    date_of_birth: '',
    notes: '',
});

const editForm = useForm({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    document_type: '',
    document_number: '',
    nationality: '',
    date_of_birth: '',
    notes: '',
});

function number(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
}

function listParams() {
    return {
        search: searchQuery.value || undefined,
        nationality: nationality.value || undefined,
        segment: segment.value !== 'all' ? segment.value : undefined,
        sort: sortBy.value !== 'last_stay' ? sortBy.value : undefined,
    };
}

function refreshList() {
    router.get(route('guests.index'), listParams(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

watch(searchQuery, (value) => {
    if (value === (props.filters.search || '')) return;
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(refreshList, 350);
});

watch(
    () => props.filters,
    (filters) => {
        clearTimeout(searchTimeout);
        searchQuery.value = filters.search || '';
        nationality.value = filters.nationality || '';
        segment.value = filters.segment || 'all';
        sortBy.value = filters.sort || 'last_stay';
    },
    { deep: true },
);

onBeforeUnmount(() => clearTimeout(searchTimeout));

function selectSegment(value) {
    clearTimeout(searchTimeout);
    segment.value = value;
    refreshList();
}

function showDuplicates() {
    clearTimeout(searchTimeout);
    router.get(route('guests.index'), { segment: 'duplicates' }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clearFilters() {
    clearTimeout(searchTimeout);
    router.get(route('guests.index'), {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function goToPage(url) {
    if (!url) return;
    router.get(url, {}, { preserveState: true, preserveScroll: true, replace: true });
}

function openEdit(guest) {
    if (!guest.edit_data) return;
    selectedGuest.value = guest;
    Object.keys(editForm.data()).forEach((key) => {
        editForm[key] = guest.edit_data[key] ?? '';
    });
    editForm.clearErrors();
    showEditModal.value = true;
}

function openDelete(guest) {
    if (!guest.can_delete) {
        toasts.value?.warning(translate('admin.generated.k_14353f767290'));
        return;
    }
    selectedGuest.value = guest;
    showDeleteModal.value = true;
}

function submitCreate() {
    createForm.post(route('guests.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showCreateModal.value = false;
            createForm.reset();
            toasts.value?.success(translate('admin.generated.k_30061b716a71'));
        },
    });
}

function submitEdit() {
    if (!selectedGuest.value) return;
    editForm.put(route('guests.update', selectedGuest.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            showEditModal.value = false;
            toasts.value?.success(translate('admin.generated.k_536989bb21a7'));
        },
    });
}

function submitDelete() {
    if (!selectedGuest.value || deleting.value) return;
    router.delete(route('guests.destroy', selectedGuest.value.id), {
        preserveScroll: true,
        onStart: () => {
            deleting.value = true;
        },
        onSuccess: () => {
            showDeleteModal.value = false;
            toasts.value?.success(translate('admin.generated.k_0ccd35e8c7a4'));
        },
        onError: (errors) => {
            showDeleteModal.value = false;
            toasts.value?.error(errors.guest || translate('admin.generated.k_58bdc74ed5bb'));
        },
        onFinish: () => {
            deleting.value = false;
        },
    });
}

function initials(guest) {
    return `${guest.first_name?.[0] || ''}${guest.last_name?.[0] || ''}`.toUpperCase() || 'M';
}

function avatarClass(guest) {
    const classes = [
        'bg-accent-50 text-accent-700',
        'bg-info-50 text-info-700',
        'bg-primary-50 text-primary-700',
        'bg-warning-50 text-warning-700',
    ];
    return classes[number(guest.id) % classes.length];
}

function formatDate(value, includeYear = false) {
    if (!value) return '—';
    const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) return '—';
    const months = ['jan', 'shk', 'mar', 'pri', 'maj', 'qer', 'kor', 'gus', 'sht', 'tet', translate('admin.generated.k_7708ca7100af'), 'dhj'];
    const date = `${Number(match[3])} ${months[Number(match[2]) - 1]}`;
    const showYear = includeYear || Number(match[1]) !== new Date().getFullYear();
    return showYear ? `${date} ${match[1]}` : date;
}

function activeStay(guest) {
    return guest.current_stay || guest.next_stay || guest.last_stay || null;
}

function stayTitle(guest) {
    const stay = activeStay(guest);
    if (!stay) return translate('admin.generated.k_7e2a2d48aac3');
    if (stay.room_number) return `Dhoma ${stay.room_number}`;
    return stay.room_type || translate('admin.generated.k_b3390251ed7b');
}

function staySubtitle(guest) {
    const stay = activeStay(guest);
    if (!stay) return 'Ende pa rezervim';
    const channel = channelMeta(stay.channel).label;
    return `${channel} · ${formatDate(stay.check_in_date)}–${formatDate(stay.check_out_date)}`;
}

function stateDetail(guest) {
    if (guest.state === 'in_house') return `Largohet ${formatDate(guest.current_stay?.check_out_date)}`;
    if (guest.state === 'arriving_today') return `Hyrje sot · ${guest.next_stay?.room_number ? `Dhoma ${guest.next_stay.room_number}` : 'dhoma e caktuar'}`;
    if (['arriving_soon', 'upcoming'].includes(guest.state)) return translate('admin.generated.k_eb2b509909ff', { p0: formatDate(guest.next_stay?.check_in_date) });
    if (guest.state === 'past') return `Larguar ${formatDate(guest.last_stay?.check_out_date)}`;
    return `Krijuar ${formatDate(guest.created_at, true)}`;
}

function profileLabel(guest) {
    const labels = {
        email: 'emaili',
        phone: 'telefoni',
        nationality: translate('admin.generated.k_b7287a0f91df'),
    };
    const missing = guest.missing_fields || [];
    if (!missing.length) return translate('admin.generated.k_9e1982834dbd');
    if (missing.length === 1) return `Mungon ${labels[missing[0]]}`;
    return translate('admin.generated.k_3a1e5f11c90b', { p0: missing.length });
}

function historyLabel(guest) {
    const stays = number(guest.completed_stays);
    if (stays === 1) return translate('admin.generated.k_a371458a79f3');
    if (stays > 1) return translate('admin.generated.k_07592003b8c8', { p0: stays });
    if (['in_house', 'arriving_today', 'arriving_soon', 'upcoming'].includes(guest.state)) return translate('admin.generated.k_eccdf0918a2b');
    return translate('admin.generated.k_c9d12638c4cb');
}

function profileBarClass(guest) {
    if (number(guest.profile_completeness) >= 100) return 'bg-success-500';
    if (number(guest.profile_completeness) >= 60) return 'bg-warning-500';
    return 'bg-error-500';
}
</script>

<template>
    <Head :title="$t('admin.generated.k_217989292171')" />

    <AppLayout>
        <PageHeader
            :title="$t('admin.generated.k_217989292171')"
            :breadcrumbs="[{ label: $t('admin.generated.k_452f95ca2fe9'), href: '/dashboard' }, { label: $t('admin.generated.k_b9ab6cf932ab') }]"
        >
            <template #actions>
                <Button v-if="canCreate" variant="primary" @click="showCreateModal = true">
                    <UserPlus class="h-4 w-4" :stroke-width="1.8" />
{{ $t('admin.generated.k_68f0775c9293') }} </Button>
            </template>
        </PageHeader>
        <p class="mt-1 text-body-sm text-neutral-500">
{{ $t('admin.generated.k_873a4442a8af') }} </p>

        <section class="mt-6 grid grid-cols-2 gap-3 xl:grid-cols-4" :aria-label="$t('admin.generated.k_da4e5132ece6')">
            <!-- The card itself is a button (segment filter), so the InfoTip is a
                 positioned SIBLING — interactive elements must never nest. -->
            <div v-for="metric in metrics" :key="metric.key" class="relative">
                <button
                    type="button"
                    class="relative min-h-28 w-full rounded-lg border bg-white px-4 py-4 text-left shadow-card transition hover:-translate-y-0.5 hover:border-accent-200 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-accent-500/30 sm:px-5"
                    :class="segment === metric.segment ? 'border-accent-300 ring-1 ring-accent-100' : 'border-neutral-200'"
                    :aria-label="`${metric.label}: ${metric.value}. ${metric.help}`"
                    :aria-pressed="segment === metric.segment"
                    @click="selectSegment(metric.segment)"
                >
                    <span :class="['absolute right-3 top-3 grid h-8 w-8 place-items-center rounded-lg sm:right-4 sm:top-4', metric.iconClass]">
                        <component :is="metric.icon" class="h-4 w-4" :stroke-width="1.8" />
                    </span>
                    <p class="max-w-[75%] text-tiny font-semibold uppercase tracking-wider text-neutral-500">{{ metric.label }}</p>
                    <p class="mt-2 text-h2 font-semibold leading-none text-primary-900">{{ metric.value }}</p>
                    <p class="mt-2 text-tiny leading-snug text-neutral-500">{{ metric.help }}</p>
                </button>
                <span v-if="metric.tip" class="absolute bottom-3 right-3 sm:bottom-4 sm:right-4">
                    <InfoTip :text="metric.tip" :label="metric.label" />
                </span>
            </div>
        </section>

        <section
            v-if="number(stats.duplicate_profiles) > 0"
            class="mt-4 flex flex-col gap-3 rounded-lg border border-warning-200 bg-warning-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
            :aria-label="$t('admin.generated.k_442d258c031f')"
        >
            <div class="flex items-start gap-3">
                <Copy class="mt-0.5 h-5 w-5 shrink-0 text-warning-700" :stroke-width="1.8" />
                <div>
                    <p class="text-body-sm font-semibold text-warning-800">
                        {{ stats.duplicate_profiles }} {{ $t('admin.generated.k_a16b227de68c') }} </p>
                    <p class="mt-0.5 text-small text-warning-700">{{ $t('admin.generated.k_a155229f2afb') }}</p>
                </div>
            </div>
            <Button size="sm" variant="outline" class="border-warning-300 bg-transparent text-warning-800" @click="showDuplicates">
{{ $t('admin.generated.k_561cfaccc25e') }} </Button>
        </section>

        <section class="mt-4 overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-card">
            <div class="border-b border-neutral-200 px-4 pt-4 sm:px-5">
                <div>
                    <h2 class="text-body font-semibold text-primary-900">{{ $t('admin.generated.k_0f587c037f8d') }}</h2>
                    <p class="mt-1 text-small text-neutral-500">{{ $t('admin.generated.k_a23995c0946d') }}</p>
                </div>

                <nav class="-mb-px mt-4 flex gap-5 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" :aria-label="$t('admin.generated.k_c280132cefb0')">
                    <button
                        v-for="option in segmentOptions"
                        :key="option.value"
                        type="button"
                        :class="[
                            'flex shrink-0 items-center gap-1.5 border-b-2 pb-3 text-small font-medium transition-colors',
                            segment === option.value
                                ? 'border-accent-600 text-accent-700'
                                : 'border-transparent text-neutral-500 hover:text-neutral-700',
                        ]"
                        :aria-pressed="segment === option.value"
                        @click="selectSegment(option.value)"
                    >
                        {{ option.label }}
                        <span
                            :class="[
                                'rounded-full px-1.5 py-0.5 text-[10px]',
                                segment === option.value ? 'bg-accent-50 text-accent-700' : 'bg-neutral-100 text-neutral-500',
                            ]"
                        >
                            {{ option.count }}
                        </span>
                    </button>
                </nav>
            </div>

            <div class="grid gap-2 border-b border-neutral-200 bg-neutral-50/70 px-4 py-3 sm:grid-cols-2 sm:px-5 xl:grid-cols-[minmax(280px,1fr)_210px_210px_auto]">
                <label class="relative sm:col-span-2 xl:col-span-1">
                    <span class="sr-only">{{ $t('admin.generated.k_1c8dfa33a340') }}</span>
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" :stroke-width="1.8" />
                    <TextInput v-model="searchQuery" class="pl-9" :placeholder="$t('admin.generated.k_9bd69cdf42d0')" />
                </label>
                <Select
                    v-model="nationality"
                    :options="nationalityFilterOptions"
                    placeholder=""
                    :aria-label="$t('admin.generated.k_74e4e7d5a9a8')"
                    @change="refreshList"
                />
                <Select
                    v-model="sortBy"
                    :options="sortOptions"
                    placeholder=""
                    :aria-label="$t('admin.generated.k_d57ffb821f4c')"
                    @change="refreshList"
                />
                <div class="flex items-center justify-end gap-3 self-center sm:col-span-2 xl:col-span-1">
                    <button
                        v-if="hasActiveFilters"
                        type="button"
                        class="text-small font-medium text-accent-700 underline-offset-2 hover:underline"
                        @click="clearFilters"
                    >
{{ $t('admin.generated.k_0aec64036fdf') }} </button>
                    <p class="text-right text-small text-neutral-500">
                        <template v-if="guests.total">{{ guests.from }}–{{ guests.to }} {{ $t('admin.generated.k_1260698259a8') }} {{ guests.total }}</template>
                        <template v-else>{{ $t('admin.generated.k_30893807fc56') }}</template>
                    </p>
                </div>
            </div>

            <div v-if="guests.data?.length" class="hidden xl:block">
                <table class="w-full table-fixed">
                    <thead class="bg-neutral-50">
                        <tr class="border-b border-neutral-200">
                            <th class="w-[18%] px-4 py-3 text-left text-tiny font-semibold uppercase tracking-wider text-neutral-500">{{ $t('admin.generated.k_fbae5b8b5dd7') }}</th>
                            <th class="w-[15%] px-3 py-3 text-left text-tiny font-semibold uppercase tracking-wider text-neutral-500">{{ $t('admin.generated.k_9c1e15798ac4') }}</th>
                            <th class="w-[18%] px-3 py-3 text-left text-tiny font-semibold uppercase tracking-wider text-neutral-500">{{ $t('admin.generated.k_097264fd69a0') }}</th>
                            <th class="w-[16%] px-3 py-3 text-left text-tiny font-semibold uppercase tracking-wider text-neutral-500">{{ $t('admin.generated.k_916b06acfc18') }}</th>
                            <th class="w-[10%] px-3 py-3 text-left text-tiny font-semibold uppercase tracking-wider text-neutral-500">{{ $t('admin.generated.k_242fedf08680') }}</th>
                            <th class="w-[10%] px-3 py-3 text-left text-tiny font-semibold uppercase tracking-wider text-neutral-500">{{ $t('admin.generated.k_aeea7e2148ff') }}</th>
                            <th class="w-[13%] px-3 py-3 text-right text-tiny font-semibold uppercase tracking-wider text-neutral-500">{{ $t('admin.generated.k_2d593b4fa500') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="guest in guests.data" :key="guest.id" class="transition-colors hover:bg-neutral-50/80">
                            <td class="px-4 py-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-lg text-small font-semibold', avatarClass(guest)]">
                                        {{ initials(guest) }}
                                    </span>
                                    <div class="min-w-0">
                                        <div class="flex min-w-0 items-center gap-1.5">
                                            <Link :href="route('guests.show', guest.id)" class="truncate text-body-sm font-semibold text-primary-900 no-underline hover:text-accent-700">
                                                {{ guest.first_name }} {{ guest.last_name }}
                                            </Link>
                                            <Badge v-if="guest.completed_stays >= 2" variant="accent" size="sm" class="shrink-0">{{ $t('admin.generated.k_09dacc1c522c') }}</Badge>
                                        </div>
                                        <div class="mt-1 flex items-center gap-1.5 text-tiny text-neutral-400">
                                            <span>{{ guest.nationality_label || (guest.nationality ? countryName(guest.nationality) : $t('admin.generated.k_4e076dab2210')) }}</span>
                                            <Badge v-if="guest.is_duplicate" variant="warning" size="sm">{{ $t('admin.generated.k_3fb8721c9958') }}</Badge>
                                            <Badge v-else-if="guest.is_companion" variant="info" size="sm">{{ $t('shared.guests.companion') }}</Badge>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-3 align-middle">
                                <Badge :variant="stateMeta[guest.state]?.variant || 'neutral'" dot>
                                    {{ stateMeta[guest.state]?.label || $t('admin.generated.k_817bd53a0658') }}
                                </Badge>
                                <p class="mt-1.5 truncate text-tiny text-neutral-500">{{ stateDetail(guest) }}</p>
                            </td>
                            <td class="px-3 py-3">
                                <p class="truncate text-small text-neutral-700">{{ guest.email || $t('admin.generated.k_92c8e3444edd') }}</p>
                                <p :class="['mt-1 truncate text-small', guest.phone ? 'text-neutral-600' : 'text-warning-700']">
                                    {{ guest.phone || $t('admin.generated.k_825d491e3df0') }}
                                </p>
                            </td>
                            <td class="px-3 py-3">
                                <p class="truncate text-body-sm font-semibold text-primary-900">{{ stayTitle(guest) }}</p>
                                <p class="mt-1 truncate text-tiny text-neutral-500">{{ staySubtitle(guest) }}</p>
                            </td>
                            <td class="px-3 py-3">
                                <p class="text-body-sm font-semibold text-primary-900">{{ historyLabel(guest) }}</p>
                                <p class="mt-1 text-tiny text-neutral-500">{{ guest.total_nights }} {{ $t('admin.generated.k_4431f29e4b5f') }}</p>
                            </td>
                            <td class="px-3 py-3">
                                <div class="flex items-center justify-between gap-2 text-tiny">
                                    <span class="truncate text-neutral-600">{{ profileLabel(guest) }}</span>
                                    <strong class="text-primary-900">{{ guest.profile_completeness }}%</strong>
                                </div>
                                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-neutral-100">
                                    <span :class="['block h-full rounded-full', profileBarClass(guest)]" :style="{ width: `${guest.profile_completeness}%` }" />
                                </div>
                            </td>
                            <td class="px-3 py-3 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <Link :href="route('guests.show', guest.id)" :class="profileLinkClass">{{ $t('admin.generated.k_cf07d8096920') }}</Link>
                                    <ActionMenu v-if="canUpdate || (canDelete && guest.can_delete)">
                                        <Link :href="route('guests.show', guest.id)" :class="menuItemClass">
                                            <Eye class="h-4 w-4 text-neutral-400" :stroke-width="1.8" />
{{ $t('admin.generated.k_116bb2ab4d73') }} </Link>
                                        <button v-if="canUpdate && guest.edit_data" type="button" :class="menuItemClass" @click="openEdit(guest)">
                                            <Pencil class="h-4 w-4 text-neutral-400" :stroke-width="1.8" />
{{ $t('admin.generated.k_4e4d83631bf8') }} </button>
                                        <button v-if="canDelete && guest.can_delete" type="button" :class="[menuItemClass, 'text-error-600']" @click="openDelete(guest)">
                                            <Trash2 class="h-4 w-4 text-error-500" :stroke-width="1.8" />
{{ $t('admin.generated.k_68d8443c77a5') }} </button>
                                    </ActionMenu>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="guests.data?.length" class="grid gap-3 bg-neutral-50/70 p-3 xl:hidden">
                <article v-for="guest in guests.data" :key="guest.id" class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-lg text-small font-semibold', avatarClass(guest)]">
                                {{ initials(guest) }}
                            </span>
                            <div class="min-w-0">
                                <Link :href="route('guests.show', guest.id)" class="block truncate text-body-sm font-semibold text-primary-900 no-underline">
                                    {{ guest.first_name }} {{ guest.last_name }}
                                </Link>
                                <div class="mt-1 flex flex-wrap items-center gap-1.5 text-tiny text-neutral-500">
                                    <span>{{ guest.nationality_label || (guest.nationality ? countryName(guest.nationality) : $t('admin.generated.k_4e076dab2210')) }}</span>
                                    <Badge v-if="guest.completed_stays >= 2" variant="accent" size="sm">{{ $t('admin.generated.k_09dacc1c522c') }}</Badge>
                                    <Badge v-if="guest.is_duplicate" variant="warning" size="sm">{{ $t('admin.generated.k_3fb8721c9958') }}</Badge>
                                    <Badge v-else-if="guest.is_companion" variant="info" size="sm">{{ $t('shared.guests.companion') }}</Badge>
                                </div>
                            </div>
                        </div>
                        <Badge :variant="stateMeta[guest.state]?.variant || 'neutral'" dot class="shrink-0">
                            {{ stateMeta[guest.state]?.label || $t('admin.generated.k_817bd53a0658') }}
                        </Badge>
                    </div>

                    <p class="mt-2 text-tiny text-neutral-500">{{ stateDetail(guest) }}</p>

                    <dl class="mt-3 grid grid-cols-2 gap-2">
                        <div class="rounded-md bg-neutral-50 px-3 py-2.5">
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-neutral-400">{{ $t('admin.generated.k_916b06acfc18') }}</dt>
                            <dd class="mt-1 text-small font-medium text-primary-900">{{ stayTitle(guest) }}</dd>
                            <dd class="mt-0.5 text-tiny text-neutral-500">{{ staySubtitle(guest) }}</dd>
                        </div>
                        <div class="rounded-md bg-neutral-50 px-3 py-2.5">
                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-neutral-400">{{ $t('admin.generated.k_242fedf08680') }}</dt>
                            <dd class="mt-1 text-small font-medium text-primary-900">{{ historyLabel(guest) }}</dd>
                            <dd class="mt-0.5 text-tiny text-neutral-500">{{ guest.total_nights }} {{ $t('admin.generated.k_4431f29e4b5f') }}</dd>
                        </div>
                        <div class="min-w-0 rounded-md bg-neutral-50 px-3 py-2.5">
                            <dt class="flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider text-neutral-400">
                                <Mail class="h-3 w-3" :stroke-width="1.8" />
{{ $t('admin.generated.k_727be14dc8c6') }} </dt>
                            <dd :class="['mt-1 truncate text-small', guest.email ? 'text-neutral-700' : 'text-warning-700']">
                                {{ guest.email || $t('admin.generated.k_8c6f8618243d') }}
                            </dd>
                        </div>
                        <div class="min-w-0 rounded-md bg-neutral-50 px-3 py-2.5">
                            <dt class="flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider text-neutral-400">
                                <Phone class="h-3 w-3" :stroke-width="1.8" />
{{ $t('admin.generated.k_4847b0efdb8e') }} </dt>
                            <dd :class="['mt-1 truncate text-small', guest.phone ? 'text-neutral-700' : 'text-warning-700']">
                                {{ guest.phone || $t('admin.generated.k_8c6f8618243d') }}
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-3">
                        <div class="flex items-center justify-between gap-2 text-small">
                            <span class="text-neutral-600">{{ profileLabel(guest) }}</span>
                            <strong class="text-primary-900">{{ guest.profile_completeness }}%</strong>
                        </div>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-neutral-100">
                            <span :class="['block h-full rounded-full', profileBarClass(guest)]" :style="{ width: `${guest.profile_completeness}%` }" />
                        </div>
                    </div>

                    <div class="mt-3 flex items-center gap-2">
                        <Link :href="route('guests.show', guest.id)" :class="[profileLinkClass, 'min-w-0 flex-1']">{{ $t('admin.generated.k_cf07d8096920') }}</Link>
                        <ActionMenu v-if="canUpdate || (canDelete && guest.can_delete)">
                            <Link :href="route('guests.show', guest.id)" :class="menuItemClass">
                                <Eye class="h-4 w-4 text-neutral-400" :stroke-width="1.8" />
{{ $t('admin.generated.k_116bb2ab4d73') }} </Link>
                            <button v-if="canUpdate && guest.edit_data" type="button" :class="menuItemClass" @click="openEdit(guest)">
                                <Pencil class="h-4 w-4 text-neutral-400" :stroke-width="1.8" />
{{ $t('admin.generated.k_4e4d83631bf8') }} </button>
                            <button v-if="canDelete && guest.can_delete" type="button" :class="[menuItemClass, 'text-error-600']" @click="openDelete(guest)">
                                <Trash2 class="h-4 w-4 text-error-500" :stroke-width="1.8" />
{{ $t('admin.generated.k_68d8443c77a5') }} </button>
                        </ActionMenu>
                    </div>
                </article>
            </div>

            <div v-if="!guests.data?.length" class="px-6 py-14 text-center">
                <Users class="mx-auto h-9 w-9 text-neutral-300" :stroke-width="1.5" />
                <p class="mt-3 text-body-sm font-medium text-neutral-700">
                    {{ searchQuery || segment !== 'all' || nationality ? $t('admin.generated.k_f5743ef4d767') : $t('admin.generated.k_3fe179690d97') }}
                </p>
                <p class="mt-1 text-small text-neutral-500">{{ $t('admin.generated.k_db419780a74c') }}</p>
                <Button v-if="hasActiveFilters" size="sm" variant="outline" class="mt-4" @click="clearFilters">{{ $t('admin.generated.k_0aec64036fdf') }}</Button>
            </div>

            <footer
                v-if="guests.total > 0"
                class="flex flex-col gap-3 border-t border-neutral-200 bg-neutral-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5"
            >
                <p class="text-small text-neutral-500">{{ guests.from }}–{{ guests.to }} {{ $t('admin.generated.k_1260698259a8') }} {{ guests.total }} {{ $t('admin.generated.k_46c4969f93cf') }}</p>
                <div class="flex items-center gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="!guests.prev_page_url"
                        :aria-label="$t('admin.generated.k_3bacd2d18f4d')"
                        @click="goToPage(guests.prev_page_url)"
                    >
{{ $t('admin.generated.k_0d876b774e7b') }} </Button>
                    <span class="min-w-16 text-center text-small text-neutral-600">{{ guests.current_page }} / {{ guests.last_page }}</span>
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="!guests.next_page_url"
                        :aria-label="$t('admin.generated.k_9bc9a35115b3')"
                        @click="goToPage(guests.next_page_url)"
                    >
{{ $t('admin.generated.k_c92fdcb63eb1') }} </Button>
                </div>
            </footer>
        </section>

        <Modal :show="showCreateModal" :title="$t('admin.generated.k_a8941b093c41')" max-width="lg" @close="showCreateModal = false">
            <form class="space-y-4" @submit.prevent="submitCreate">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormGroup :label="$t('admin.generated.k_38482ed383e6')" html-for="create-first-name" :error="createForm.errors.first_name" required>
                        <TextInput id="create-first-name" v-model="createForm.first_name" :placeholder="$t('admin.generated.k_38482ed383e6')" autocomplete="given-name" :error="createForm.errors.first_name" />
                    </FormGroup>
                    <FormGroup :label="$t('admin.generated.k_29badb49c546')" html-for="create-last-name" :error="createForm.errors.last_name" required>
                        <TextInput id="create-last-name" v-model="createForm.last_name" :placeholder="$t('admin.generated.k_29badb49c546')" autocomplete="family-name" :error="createForm.errors.last_name" />
                    </FormGroup>
                    <FormGroup :label="$t('admin.generated.k_3704e3d60a4d')" html-for="create-email" :error="createForm.errors.email">
                        <TextInput id="create-email" v-model="createForm.email" type="email" :placeholder="$t('admin.generated.k_1a260b326283')" autocomplete="email" :error="createForm.errors.email" />
                    </FormGroup>
                    <FormGroup :label="$t('admin.generated.k_ca2435f3926c')" html-for="create-phone" :error="createForm.errors.phone">
                        <TextInput id="create-phone" v-model="createForm.phone" placeholder="+355 69…" autocomplete="tel" :error="createForm.errors.phone" />
                    </FormGroup>
                    <FormGroup :label="$t('admin.generated.k_8e39d3fc5cb5')" html-for="create-document-type" :error="createForm.errors.document_type">
                        <Select id="create-document-type" v-model="createForm.document_type" :options="docTypeOptions" :placeholder="$t('admin.generated.k_3555aeb55e32')" :error="createForm.errors.document_type" />
                    </FormGroup>
                    <FormGroup :label="$t('admin.generated.k_b4d40453767d')" html-for="create-document-number" :error="createForm.errors.document_number">
                        <TextInput id="create-document-number" v-model="createForm.document_number" :placeholder="$t('admin.generated.k_b6e083bfd8dd')" :error="createForm.errors.document_number" />
                    </FormGroup>
                    <FormGroup :label="$t('admin.generated.k_3de0940e65af')" html-for="create-nationality" :error="createForm.errors.nationality">
                        <Select id="create-nationality" v-model="createForm.nationality" :options="countryOptions" :placeholder="$t('admin.generated.k_e2cf67b6dd47')" :error="createForm.errors.nationality" />
                    </FormGroup>
                    <FormGroup :label="$t('admin.generated.k_7f9a0b01a9b3')" html-for="create-date-of-birth" :error="createForm.errors.date_of_birth">
                        <DatePicker v-model="createForm.date_of_birth" :input-attrs="{ id: 'create-date-of-birth' }" :aria-label="$t('admin.generated.k_7f9a0b01a9b3')" :error="createForm.errors.date_of_birth" />
                    </FormGroup>
                </div>
                <FormGroup :label="$t('admin.generated.k_fb09e70ce276')" html-for="create-notes" :error="createForm.errors.notes">
                    <Textarea id="create-notes" v-model="createForm.notes" :placeholder="$t('admin.generated.k_c78c256a983c')" :rows="2" />
                </FormGroup>
            </form>
            <template #footer>
                <Button variant="outline" @click="showCreateModal = false">{{ $t('admin.generated.k_cceaaefe021c') }}</Button>
                <Button variant="primary" :loading="createForm.processing" @click="submitCreate">{{ $t('admin.generated.k_5b517ae37b5e') }}</Button>
            </template>
        </Modal>

        <Modal :show="showEditModal" :title="$t('admin.generated.k_b5014763ba76')" max-width="lg" @close="showEditModal = false">
            <form class="space-y-4" @submit.prevent="submitEdit">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormGroup :label="$t('admin.generated.k_38482ed383e6')" html-for="edit-first-name" :error="editForm.errors.first_name" required>
                        <TextInput id="edit-first-name" v-model="editForm.first_name" autocomplete="given-name" :error="editForm.errors.first_name" />
                    </FormGroup>
                    <FormGroup :label="$t('admin.generated.k_29badb49c546')" html-for="edit-last-name" :error="editForm.errors.last_name" required>
                        <TextInput id="edit-last-name" v-model="editForm.last_name" autocomplete="family-name" :error="editForm.errors.last_name" />
                    </FormGroup>
                    <FormGroup :label="$t('admin.generated.k_3704e3d60a4d')" html-for="edit-email" :error="editForm.errors.email">
                        <TextInput id="edit-email" v-model="editForm.email" type="email" autocomplete="email" :error="editForm.errors.email" />
                    </FormGroup>
                    <FormGroup :label="$t('admin.generated.k_ca2435f3926c')" html-for="edit-phone" :error="editForm.errors.phone">
                        <TextInput id="edit-phone" v-model="editForm.phone" autocomplete="tel" :error="editForm.errors.phone" />
                    </FormGroup>
                    <FormGroup :label="$t('admin.generated.k_8e39d3fc5cb5')" html-for="edit-document-type" :error="editForm.errors.document_type">
                        <Select id="edit-document-type" v-model="editForm.document_type" :options="docTypeOptions" :placeholder="$t('admin.generated.k_3555aeb55e32')" :error="editForm.errors.document_type" />
                    </FormGroup>
                    <FormGroup :label="$t('admin.generated.k_b4d40453767d')" html-for="edit-document-number" :error="editForm.errors.document_number">
                        <TextInput id="edit-document-number" v-model="editForm.document_number" :error="editForm.errors.document_number" />
                    </FormGroup>
                    <FormGroup :label="$t('admin.generated.k_3de0940e65af')" html-for="edit-nationality" :error="editForm.errors.nationality">
                        <Select id="edit-nationality" v-model="editForm.nationality" :options="countryOptions" :placeholder="$t('admin.generated.k_e2cf67b6dd47')" :error="editForm.errors.nationality" />
                    </FormGroup>
                    <FormGroup :label="$t('admin.generated.k_7f9a0b01a9b3')" html-for="edit-date-of-birth" :error="editForm.errors.date_of_birth">
                        <DatePicker v-model="editForm.date_of_birth" :input-attrs="{ id: 'edit-date-of-birth' }" :aria-label="$t('admin.generated.k_7f9a0b01a9b3')" :error="editForm.errors.date_of_birth" />
                    </FormGroup>
                </div>
                <FormGroup :label="$t('admin.generated.k_fb09e70ce276')" html-for="edit-notes" :error="editForm.errors.notes">
                    <Textarea id="edit-notes" v-model="editForm.notes" :rows="2" />
                </FormGroup>
            </form>
            <template #footer>
                <Button variant="outline" @click="showEditModal = false">{{ $t('admin.generated.k_cceaaefe021c') }}</Button>
                <Button variant="primary" :loading="editForm.processing" @click="submitEdit">{{ $t('admin.generated.k_c2f369bcec26') }}</Button>
            </template>
        </Modal>

        <Modal :show="showDeleteModal" :title="$t('admin.generated.k_1998f5e083f2')" max-width="sm" @close="showDeleteModal = false">
            <p class="text-body-sm text-neutral-600">
{{ $t('admin.generated.k_2f59991405c2') }} <strong>{{ selectedGuest?.first_name }} {{ selectedGuest?.last_name }}</strong>?
            </p>
            <p class="mt-2 text-small text-neutral-500">{{ $t('admin.generated.k_8ff652ae4fc3') }}</p>
            <template #footer>
                <Button variant="outline" :disabled="deleting" @click="showDeleteModal = false">{{ $t('admin.generated.k_cceaaefe021c') }}</Button>
                <Button variant="danger" :loading="deleting" @click="submitDelete">{{ $t('admin.generated.k_68d8443c77a5') }}</Button>
            </template>
        </Modal>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
