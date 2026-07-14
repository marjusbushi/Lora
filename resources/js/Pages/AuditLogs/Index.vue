<script setup>
import { translate } from '@/i18n';
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import Select from '@/Components/UI/Select.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import AuditTimeline from '@/Components/AuditTimeline.vue';
import SettingsSidebar from '@/Components/SettingsSidebar.vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

const props = defineProps({
    logs: Object,
    filters: Object,
    embedded: { type: Boolean, default: false },
});
const search = ref(props.filters?.search || '');
const category = ref(props.filters?.category || 'all');
const source = ref(props.filters?.source || 'all');
const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');

const categoryOptions = [
    { value: 'all', label: translate('admin.generated.k_be8c5686fe44') },
    { value: 'reservation', label: translate('admin.generated.k_9b91353dbc0c') },
    { value: 'guest', label: translate('admin.generated.k_e0b504b2f412') },
    { value: 'payment', label: translate('admin.generated.k_e0183a401ff2') },
    { value: 'folio', label: translate('admin.generated.k_b2b4b4c62157') },
    { value: 'housekeeping', label: translate('admin.generated.k_0f343160f2b1') },
    { value: 'pos', label: translate('admin.generated.k_92b40d67e4d0') },
    { value: 'user', label: translate('admin.generated.k_8ae804fff951') },
    { value: 'pricing', label: translate('admin.generated.k_4bd41929ee94') },
    { value: 'channex', label: translate('admin.generated.k_27c5f3383b4b') },
];
const sourceOptions = [
    { value: 'all', label: translate('admin.generated.k_830d24415ee9') },
    { value: 'staff', label: translate('admin.generated.k_31735c066a59') },
    { value: 'channex', label: translate('admin.generated.k_5213cdb70e3a') },
    { value: 'website', label: translate('admin.generated.k_6d24f521f735') },
    { value: 'import', label: translate('admin.generated.k_115ae7090ce2') },
    { value: 'system', label: translate('admin.generated.k_0dd277f5d8bb') },
];

function params(extra = {}) {
    const filters = {
        search: search.value || undefined,
        category: category.value,
        source: source.value,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
        ...extra,
    };

    if (!props.embedded) return filters;

    return {
        tab: 'history',
        ...Object.fromEntries(Object.entries(filters).map(([key, value]) => [`audit_${key}`, value])),
    };
}
function applyFilters() {
    router.get(props.embedded ? route('settings.index') : route('audit-logs.index'), params(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: props.embedded ? ['auditHistory'] : ['logs', 'filters'],
    });
}
function clearFilters() {
    search.value = ''; category.value = 'all'; source.value = 'all'; dateFrom.value = ''; dateTo.value = '';
    applyFilters();
}
function pageTo(url) {
    if (!url) return;
    const page = new URL(url, window.location.origin).searchParams.get(props.embedded ? 'audit_page' : 'page');
    router.get(props.embedded ? route('settings.index') : route('audit-logs.index'), params({ page }), {
        preserveState: true,
        preserveScroll: true,
        only: props.embedded ? ['auditHistory'] : ['logs', 'filters'],
    });
}

watch(() => props.filters, (value) => {
    search.value = value?.search || '';
    category.value = value?.category || 'all';
    source.value = value?.source || 'all';
    dateFrom.value = value?.date_from || '';
    dateTo.value = value?.date_to || '';
});
</script>

<template>
    <component :is="embedded ? 'div' : AppLayout">
        <PageHeader v-if="!embedded" :title="$t('accountCenter.auditHistory')" :breadcrumbs="[{ label: $t('admin.sidebar.dashboard'), href: '/dashboard' }, { label: $t('admin.audit.history') }]" />
        <p v-if="!embedded" class="mt-1 text-body-sm text-neutral-500">{{ $t('admin.audit.subtitle') }}</p>

        <div :class="embedded ? '' : 'mt-6 flex flex-col gap-6 lg:flex-row'">
            <SettingsSidebar v-if="!embedded" active-item="history" />

            <div class="min-w-0 flex-1">
        <Card>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-6 xl:items-end">
                <div class="xl:col-span-2"><TextInput v-model="search" :placeholder="$t('admin.generated.k_fb4efe685e9f')" @keyup.enter="applyFilters" /></div>
                <Select v-model="category" :options="categoryOptions" @change="applyFilters" />
                <Select v-model="source" :options="sourceOptions" @change="applyFilters" />
                <DatePicker v-model="dateFrom" :placeholder="$t('admin.generated.k_d944f7828459')" />
                <DatePicker v-model="dateTo" :placeholder="$t('admin.generated.k_bce04ecb70ad')" />
            </div>
            <div class="mt-3 flex gap-2">
                <Button size="sm" variant="primary" @click="applyFilters">{{ $t('admin.generated.k_1b4ec57e607a') }}</Button>
                <Button size="sm" variant="ghost" @click="clearFilters">{{ $t('admin.generated.k_e88530b91d75') }}</Button>
            </div>
        </Card>

        <Card class="mt-6" :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4">
                <h2 class="text-label uppercase tracking-wider text-neutral-600">{{ logs.total }} {{ $t('admin.generated.k_0a1db12d60f5') }}</h2>
                <p class="mt-0.5 text-tiny text-neutral-400">{{ $t('admin.generated.k_d48b8eaa652a') }}</p>
            </div>
            <AuditTimeline :entries="logs.data" show-ip />
            <div v-if="logs.total" class="flex items-center justify-between border-t border-neutral-200 bg-neutral-50 px-5 py-3">
                <p class="text-small text-neutral-500">{{ logs.from }}–{{ logs.to }} {{ $t('admin.generated.k_478f70055b60') }} {{ logs.total }}</p>
                <div class="flex items-center gap-2">
                    <Button size="sm" variant="outline" :disabled="!logs.prev_page_url" @click="pageTo(logs.prev_page_url)"><ChevronLeft class="h-4 w-4" /> {{ $t('admin.generated.k_ab2b38915e4e') }}</Button>
                    <span class="text-small text-neutral-500">{{ logs.current_page }} / {{ logs.last_page }}</span>
                    <Button size="sm" variant="outline" :disabled="!logs.next_page_url" @click="pageTo(logs.next_page_url)">{{ $t('admin.generated.k_efc3b198bf86') }} <ChevronRight class="h-4 w-4" /></Button>
                </div>
            </div>
        </Card>
            </div>
        </div>
    </component>
</template>
