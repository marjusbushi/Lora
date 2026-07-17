<script setup>
import { getIntlLocale, translate } from '@/i18n';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ChevronRight,
    LockKeyhole,
    Pencil,
    Plus,
    RotateCcw,
    Search,
    ShieldCheck,
    UserRoundCheck,
    UserRoundX,
    UsersRound,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import Select from '@/Components/UI/Select.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';
import ActionMenu from '@/Components/UI/ActionMenu.vue';

const props = defineProps({
    users: { type: Object, default: () => ({ data: [] }) },
    roles: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
    permissionModules: { type: Array, default: () => [] },
    rolesDetailed: { type: Array, default: () => [] },
    embedded: { type: Boolean, default: false },
});

const toasts = ref(null);
const activeTab = ref('users');
const showCreateModal = ref(false);
const showEditModal = ref(false);
const showDeleteModal = ref(false);
const selectedUser = ref(null);
const searchQuery = ref(props.filters.search || '');
const roleFilter = ref(props.filters.role || '');
const statusFilter = ref(props.filters.status || '');

const roleLabels = {
    admin: 'Admin',
    manager: 'Menaxher',
    receptionist: 'Recepsion',
    housekeeping: 'Housekeeping',
    maintenance: 'Mirëmbajtje',
    pos_staff: 'POS',
};
const roleBadge = {
    admin: 'dark',
    manager: 'accent',
    receptionist: 'info',
    housekeeping: 'warning',
    maintenance: 'success',
    pos_staff: 'neutral',
};
const avatarTones = [
    'bg-success-100 text-success-700',
    'bg-info-100 text-info-700',
    'bg-warning-100 text-warning-700',
    'bg-accent-100 text-accent-700',
    'bg-error-50 text-error-700',
];
const menuItemClass = 'flex w-full items-center gap-2.5 px-3 py-2 text-left text-body-sm text-neutral-700 transition-colors hover:bg-neutral-50';

const roleLabel = (role) => roleLabels[role] || String(role || '').replaceAll('_', ' ');
const roleOptions = computed(() => props.roles.map((role) => ({ value: role, label: roleLabel(role) })));
const roleFilterOptions = computed(() => [
    { value: '', label: translate('admin.generated.k_9a8588a65c3c') },
    ...roleOptions.value,
]);
const statusFilterOptions = [
    { value: '', label: translate('admin.generated.k_f57935047277') },
    { value: 'active', label: translate('admin.generated.k_c76599794d01') },
    { value: 'inactive', label: translate('admin.generated.k_19302f912f2f') },
];

const kpis = computed(() => [
    { key: 'total', label: translate('admin.generated.k_0e78058eca4b'), value: props.stats.total ?? 0, icon: UsersRound },
    { key: 'active', label: translate('admin.generated.k_17192b51faea'), value: props.stats.active ?? 0, icon: UserRoundCheck },
    { key: 'inactive', label: translate('admin.generated.k_19302f912f2f'), value: props.stats.inactive ?? 0, icon: UserRoundX },
    { key: 'roles', label: translate('admin.generated.k_373a8af8daa1'), value: props.stats.roles ?? props.roles.length, icon: ShieldCheck },
]);

function initials(name) {
    return String(name || '?')
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('') || '?';
}

function avatarClass(user) {
    return avatarTones[Math.abs(Number(user.id) || 0) % avatarTones.length];
}

function formatDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return date.toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short', year: 'numeric' });
}

function isKpiActive(key) {
    if (key === 'roles') return activeTab.value === 'roles';
    if (activeTab.value !== 'users') return false;
    if (key === 'total') return statusFilter.value === '';
    return statusFilter.value === key;
}

function selectKpi(key) {
    if (key === 'roles') {
        activeTab.value = 'roles';
        return;
    }
    activeTab.value = 'users';
    statusFilter.value = key === 'total' ? '' : key;
}

let searchTimer = null;
function params(extra = {}) {
    const filters = {
        search: searchQuery.value.trim() || undefined,
        role: roleFilter.value || undefined,
        status: statusFilter.value || undefined,
        ...extra,
    };

    if (!props.embedded) return filters;

    return {
        tab: 'users',
        ...Object.fromEntries(Object.entries(filters).map(([key, value]) => [`user_${key}`, value])),
    };
}

function applyFilters() {
    router.get(props.embedded ? route('settings.index') : route('users.index'), params(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: props.embedded ? ['userManagement'] : ['users', 'filters', 'stats'],
    });
}

function pageTo(url) {
    if (!url) return;
    const page = new URL(url, window.location.origin).searchParams.get(props.embedded ? 'user_page' : 'page');
    router.get(props.embedded ? route('settings.index') : route('users.index'), params({ page }), {
        preserveState: true,
        preserveScroll: true,
        only: props.embedded ? ['userManagement'] : ['users', 'filters', 'stats'],
    });
}

watch(searchQuery, () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(applyFilters, 300);
});
watch([roleFilter, statusFilter], applyFilters);
watch(() => props.filters, (value) => {
    searchQuery.value = value?.search || '';
    roleFilter.value = value?.role || '';
    statusFilter.value = value?.status || '';
});
onBeforeUnmount(() => window.clearTimeout(searchTimer));

const createForm = useForm({ name: '', email: '', password: '', role: '' });
const editForm = useForm({ name: '', email: '', password: '', role: '' });

function openEdit(user) {
    selectedUser.value = user;
    editForm.name = user.name;
    editForm.email = user.email;
    editForm.password = '';
    editForm.role = user.roles[0]?.name || '';
    showEditModal.value = true;
}

function openDelete(user) {
    selectedUser.value = user;
    showDeleteModal.value = true;
}

function submitCreate() {
    createForm.post(route('users.store'), {
        onSuccess: () => {
            showCreateModal.value = false;
            createForm.reset();
            toasts.value?.success(translate('admin.generated.k_86d438731808'));
        },
    });
}

function submitEdit() {
    editForm.put(route('users.update', selectedUser.value.id), {
        onSuccess: () => {
            showEditModal.value = false;
            toasts.value?.success(translate('admin.generated.k_b18121d5160b'));
        },
    });
}

function submitDelete() {
    router.delete(route('users.destroy', selectedUser.value.id), {
        onSuccess: () => {
            showDeleteModal.value = false;
            toasts.value?.success(translate('admin.generated.k_3c4b03fa2e51'));
        },
    });
}

function restoreUser(id) {
    router.post(route('users.restore', id), {}, {
        onSuccess: () => toasts.value?.success(translate('admin.generated.k_efa105b8cf35')),
    });
}

// ===== Roles & per-module CRUD permissions =====
const ALL_ACTIONS = ['view', 'create', 'update', 'delete', 'open', 'close', 'close_any'];
const actionLabel = { view: translate('admin.generated.k_43c4e8990712'), create: translate('admin.generated.k_fdf8bf6c2537'), update: translate('admin.generated.k_210e45b02c6e'), delete: translate('admin.generated.k_0c2d2addd5ad'), open: 'Hap', close: translate('admin.generated.k_3db530a81f37'), close_any: translate('admin.generated.k_582aae36fb3b') };
const selectedRoleId = ref(props.rolesDetailed[0]?.id ?? null);
const selectedRole = computed(() => props.rolesDetailed.find((role) => role.id === selectedRoleId.value) || null);
const checked = ref({});
const savingPerms = ref(false);
const showRoleModal = ref(false);
const roleForm = useForm({ name: '' });

function loadRolePerms(role) {
    const map = {};
    (role?.permissions || []).forEach((permission) => { map[permission] = true; });
    checked.value = map;
}
watch(selectedRole, loadRolePerms, { immediate: true });

const permName = (moduleKey, action) => `${action}_${moduleKey}`;
function isChecked(moduleKey, action) {
    if (selectedRole.value && !selectedRole.value.editable) return true;
    return !!checked.value[permName(moduleKey, action)];
}
function toggle(moduleKey, action) {
    const key = permName(moduleKey, action);
    checked.value = { ...checked.value, [key]: !checked.value[key] };
}
function saveRolePerms() {
    if (!selectedRole.value?.editable) return;
    savingPerms.value = true;
    const permissions = Object.keys(checked.value).filter((key) => checked.value[key]);
    router.put(route('users.roles.permissions', selectedRole.value.id), { permissions }, {
        preserveScroll: true,
        onSuccess: () => toasts.value?.success(translate('admin.generated.k_1cdfa9079797')),
        onError: () => toasts.value?.error(translate('admin.generated.k_2737cc8f80b2')),
        onFinish: () => { savingPerms.value = false; },
    });
}
function submitRole() {
    roleForm.post(route('users.roles.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showRoleModal.value = false;
            roleForm.reset();
            toasts.value?.success(translate('admin.generated.k_a1f62674004b'));
        },
    });
}
</script>

<template>
    <component :is="embedded ? 'div' : AppLayout">
        <PageHeader
            v-if="!embedded"
            :title="$t('admin.generated.k_33bf8e325133')"
            :breadcrumbs="[{ label: $t('admin.generated.k_db09aa5de9ce'), href: '/dashboard' }, { label: $t('admin.generated.k_d589a610b5e6') }]"
        >
            <template #actions>
                <Button v-if="activeTab === 'users'" variant="primary" @click="showCreateModal = true">
                    <template #icon-left><Plus class="h-4 w-4" :stroke-width="2" /></template>
{{ $t('admin.generated.k_beae8f440e69') }} </Button>
                <Button v-else variant="outline" @click="showRoleModal = true">
                    <template #icon-left><Plus class="h-4 w-4" :stroke-width="2" /></template>
{{ $t('admin.generated.k_99ca548187bb') }} </Button>
            </template>
        </PageHeader>
        <p v-if="!embedded" class="mt-1 text-body-sm text-neutral-500">{{ $t('admin.generated.k_ec8630017d31') }}</p>

        <div v-else class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-h2 text-neutral-900">{{ $t('admin.generated.k_33bf8e325133') }}</h2>
                <p class="mt-1 text-body-sm text-neutral-500">{{ $t('admin.generated.k_ec8630017d31') }}</p>
            </div>
            <Button v-if="activeTab === 'users'" variant="primary" @click="showCreateModal = true">
                <template #icon-left><Plus class="h-4 w-4" :stroke-width="2" /></template>
                {{ $t('admin.generated.k_beae8f440e69') }}
            </Button>
            <Button v-else variant="outline" @click="showRoleModal = true">
                <template #icon-left><Plus class="h-4 w-4" :stroke-width="2" /></template>
                {{ $t('admin.generated.k_99ca548187bb') }}
            </Button>
        </div>

        <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <button
                v-for="kpi in kpis"
                :key="kpi.key"
                type="button"
                :aria-pressed="isKpiActive(kpi.key)"
                :class="[
                    'flex min-h-24 items-center gap-4 rounded-lg border bg-white px-5 py-4 text-left shadow-card transition-colors focus:outline-none focus:ring-2 focus:ring-accent-500/30',
                    isKpiActive(kpi.key) ? 'border-accent-500 bg-accent-50/40' : 'border-neutral-200 hover:border-neutral-300 hover:bg-neutral-50',
                ]"
                @click="selectKpi(kpi.key)"
            >
                <span :class="['inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full', kpi.key === 'inactive' ? 'bg-error-50 text-error-600' : 'bg-accent-50 text-accent-700']">
                    <component :is="kpi.icon" class="h-5 w-5" :stroke-width="1.8" />
                </span>
                <span>
                    <strong class="block text-h3 font-semibold tabular-nums text-neutral-900">{{ kpi.value }}</strong>
                    <span class="text-label uppercase tracking-wider text-neutral-500">{{ kpi.label }}</span>
                </span>
            </button>
        </div>

        <Card :padding="false" class="mt-5">
            <div class="flex items-center gap-7 border-b border-neutral-200 px-5">
                <button
                    type="button"
                    :class="['inline-flex items-center gap-2 border-b-2 px-1 py-4 text-body-sm font-semibold transition-colors', activeTab === 'users' ? 'border-accent-600 text-neutral-900' : 'border-transparent text-neutral-500 hover:text-neutral-800']"
                    @click="activeTab = 'users'"
                >
                    <UsersRound class="h-[18px] w-[18px]" :stroke-width="1.8" />
{{ $t('admin.generated.k_6c56ec37d82d') }} </button>
                <button
                    type="button"
                    :class="['inline-flex items-center gap-2 border-b-2 px-1 py-4 text-body-sm font-semibold transition-colors', activeTab === 'roles' ? 'border-accent-600 text-neutral-900' : 'border-transparent text-neutral-500 hover:text-neutral-800']"
                    @click="activeTab = 'roles'"
                >
                    <ShieldCheck class="h-[18px] w-[18px]" :stroke-width="1.8" />
{{ $t('admin.generated.k_26cbef15c613') }} </button>
            </div>

            <template v-if="activeTab === 'users'">
                <div class="grid gap-3 border-b border-neutral-200 p-4 md:grid-cols-[minmax(260px,1fr)_220px_190px]">
                    <label class="relative block">
                        <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" :stroke-width="1.8" />
                        <input
                            v-model="searchQuery"
                            type="search"
                            class="h-10 w-full rounded-lg border border-neutral-200 bg-white py-2 pl-10 pr-3 text-body-sm text-neutral-900 placeholder:text-neutral-400 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30"
                            :placeholder="$t('admin.generated.k_de0742b560a6')"
                            :aria-label="$t('admin.generated.k_1138a7dfce7f')"
                        />
                    </label>
                    <Select v-model="roleFilter" :options="roleFilterOptions" :aria-label="$t('admin.generated.k_2648a9965cca')" />
                    <Select v-model="statusFilter" :options="statusFilterOptions" :aria-label="$t('admin.generated.k_7a26d4c6b737')" />
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="border-b border-neutral-200 bg-neutral-50/70">
                            <tr>
                                <th class="px-5 py-3 text-left text-label uppercase tracking-wider text-neutral-500">{{ $t('admin.generated.k_3c9ba602567a') }}</th>
                                <th class="px-5 py-3 text-left text-label uppercase tracking-wider text-neutral-500">{{ $t('admin.generated.k_c4e33aa116f1') }}</th>
                                <th class="px-5 py-3 text-left text-label uppercase tracking-wider text-neutral-500">{{ $t('admin.generated.k_ec418e56d117') }}</th>
                                <th class="px-5 py-3 text-left text-label uppercase tracking-wider text-neutral-500">{{ $t('admin.generated.k_c67fe987b77e') }}</th>
                                <th class="px-5 py-3 text-right text-label uppercase tracking-wider text-neutral-500">{{ $t('admin.generated.k_d1243cdb8852') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="user in users.data" :key="user.id" class="transition-colors hover:bg-neutral-50/80">
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <span :class="['inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-body-sm font-semibold', avatarClass(user)]">
                                            {{ initials(user.name) }}
                                        </span>
                                        <span class="min-w-0">
                                            <strong class="block truncate text-body-sm font-semibold text-neutral-900">{{ user.name }}</strong>
                                            <span class="block truncate text-small text-neutral-500">{{ user.email }}</span>
                                        </span>
                                    </div>
                                </td>
                                <td class="px-5 py-3.5">
                                    <Badge v-if="user.roles?.length" :variant="roleBadge[user.roles[0].name] || 'neutral'">
                                        {{ roleLabel(user.roles[0].name) }}
                                    </Badge>
                                    <span v-else class="text-body-sm text-neutral-400">{{ $t('admin.generated.k_984532532e1a') }}</span>
                                </td>
                                <td class="px-5 py-3.5">
                                    <Badge :variant="user.membership_active ? 'success' : 'error'" dot>
                                        {{ user.membership_active ? $t('admin.generated.k_fec23585f7e9') : $t('admin.generated.k_80c6f856cb0f') }}
                                    </Badge>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-body-sm text-neutral-600">{{ formatDate(user.created_at) }}</td>
                                <td class="px-5 py-3.5 text-right">
                                    <ActionMenu>
                                        <template v-if="user.membership_active">
                                            <button type="button" :class="menuItemClass" role="menuitem" @click="openEdit(user)">
                                                <Pencil class="h-4 w-4 text-neutral-500" :stroke-width="1.8" />
{{ $t('admin.generated.k_d6008fe622cf') }} </button>
                                            <button type="button" :class="[menuItemClass, 'text-error-700 hover:bg-error-50']" role="menuitem" @click="openDelete(user)">
                                                <UserRoundX class="h-4 w-4" :stroke-width="1.8" />
{{ $t('admin.generated.k_4d199f6b9036') }} </button>
                                        </template>
                                        <button v-else type="button" :class="[menuItemClass, 'text-accent-700 hover:bg-accent-50']" role="menuitem" @click="restoreUser(user.id)">
                                            <RotateCcw class="h-4 w-4" :stroke-width="1.8" />
{{ $t('admin.generated.k_9cff5c7a32f0') }} </button>
                                    </ActionMenu>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="!users.data?.length" class="px-6 py-14 text-center">
                    <UsersRound class="mx-auto h-8 w-8 text-neutral-300" :stroke-width="1.5" />
                    <p class="mt-3 text-body-sm font-medium text-neutral-700">{{ $t('admin.generated.k_93ee05053452') }}</p>
                    <p class="mt-1 text-small text-neutral-500">{{ $t('admin.generated.k_3e33f8557228') }}</p>
                </div>

                <div class="flex flex-col gap-3 border-t border-neutral-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-body-sm text-neutral-500">
                        {{ users.from || 0 }}–{{ users.to || 0 }} {{ $t('admin.generated.k_80bd07c2edc9') }} {{ users.total || 0 }} {{ $t('admin.generated.k_b15db26df203') }} </p>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            :disabled="!users.prev_page_url"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-neutral-200 text-neutral-600 transition-colors hover:bg-neutral-50 disabled:cursor-not-allowed disabled:text-neutral-300 disabled:hover:bg-white"
                            :aria-label="$t('admin.generated.k_7344e9c850c8')"
                            @click="pageTo(users.prev_page_url)"
                        >
                            <ChevronLeft class="h-4 w-4" />
                        </button>
                        <button
                            type="button"
                            :disabled="!users.next_page_url"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-neutral-200 text-neutral-600 transition-colors hover:bg-neutral-50 disabled:cursor-not-allowed disabled:text-neutral-300 disabled:hover:bg-white"
                            :aria-label="$t('admin.generated.k_68e96d1c2a56')"
                            @click="pageTo(users.next_page_url)"
                        >
                            <ChevronRight class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </template>

            <template v-else>
                <div class="flex flex-col gap-4 border-b border-neutral-200 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="text-body font-semibold text-neutral-900">{{ $t('admin.generated.k_26cbef15c613') }}</h2>
                        <p class="mt-0.5 text-small text-neutral-500">{{ $t('admin.generated.k_a3e9edce5448') }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="role in rolesDetailed"
                            :key="role.id"
                            type="button"
                            :class="['inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-body-sm font-medium transition-colors', selectedRoleId === role.id ? 'border-accent-600 bg-accent-50 text-accent-800' : 'border-neutral-200 bg-white text-neutral-600 hover:bg-neutral-50']"
                            @click="selectedRoleId = role.id"
                        >
                            {{ roleLabel(role.name) }}
                            <LockKeyhole v-if="!role.editable" class="h-3.5 w-3.5" :stroke-width="1.8" />
                        </button>
                    </div>
                </div>

                <div class="p-5">
                    <div v-if="selectedRole && !selectedRole.editable" class="mb-4 flex items-start gap-2.5 rounded-lg border border-neutral-200 bg-neutral-50 px-3.5 py-3 text-small text-neutral-600">
                        <LockKeyhole class="mt-0.5 h-4 w-4 shrink-0 text-neutral-500" :stroke-width="1.8" />
                        <p>{{ $t('admin.generated.k_c4e33aa116f1') }} <strong>{{ $t('admin.generated.k_8f6b1edc9cef') }}</strong> {{ $t('admin.generated.k_373a459698b5') }}</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-body-sm">
                            <thead>
                                <tr class="border-b border-neutral-200 text-label uppercase tracking-wider text-neutral-500">
                                    <th class="py-3 pr-5 text-left">{{ $t('admin.generated.k_482a03805db5') }}</th>
                                    <th v-for="action in ALL_ACTIONS" :key="action" class="px-3 py-3 text-center font-medium">{{ actionLabel[action] }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-100">
                                <tr v-for="module in permissionModules" :key="module.key" class="hover:bg-neutral-50/60">
                                    <td class="whitespace-nowrap py-3 pr-5 font-medium text-neutral-900">{{ module.label }}</td>
                                    <td v-for="action in ALL_ACTIONS" :key="action" class="px-3 py-3 text-center">
                                        <input
                                            v-if="module.actions.includes(action)"
                                            type="checkbox"
                                            class="h-4 w-4 cursor-pointer rounded border-neutral-300 text-accent-600 focus:ring-accent-500 disabled:cursor-not-allowed disabled:opacity-50"
                                            :checked="isChecked(module.key, action)"
                                            :disabled="selectedRole && !selectedRole.editable"
                                            @change="toggle(module.key, action)"
                                        />
                                        <span v-else class="text-neutral-300">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-if="selectedRole?.editable" class="flex justify-end border-t border-neutral-200 bg-neutral-50 px-5 py-4">
                    <Button variant="primary" :loading="savingPerms" @click="saveRolePerms">
{{ $t('admin.generated.k_14c63cb43e91') }}{{ roleLabel(selectedRole.name) }}”
                    </Button>
                </div>
            </template>
        </Card>

        <Modal :show="showCreateModal" :title="$t('admin.generated.k_293dc9e6dcec')" @close="showCreateModal = false">
            <form class="space-y-4" @submit.prevent="submitCreate">
                <FormGroup :label="$t('admin.generated.k_6c6f3bac4d74')" html-for="create-name" :error="createForm.errors.name" required>
                    <TextInput id="create-name" v-model="createForm.name" :placeholder="$t('admin.generated.k_66aff8687781')" :error="createForm.errors.name" />
                </FormGroup>
                <FormGroup :label="$t('admin.generated.k_6e66e9ca3801')" html-for="create-email" :error="createForm.errors.email" required>
                    <TextInput id="create-email" v-model="createForm.email" type="email" :placeholder="$t('admin.generated.k_974084c68249')" :error="createForm.errors.email" />
                </FormGroup>
                <FormGroup :label="$t('admin.generated.k_0f014ba2fa67')" html-for="create-password" :error="createForm.errors.password" required>
                    <TextInput id="create-password" v-model="createForm.password" type="password" :placeholder="$t('admin.generated.k_55695440090a')" :error="createForm.errors.password" />
                </FormGroup>
                <p class="-mt-2 text-tiny text-neutral-500">{{ $t('admin.generated.k_bbe1ac4be3f7') }}</p>
                <FormGroup :label="$t('admin.generated.k_7f91e3d9af4e')" html-for="create-role" :error="createForm.errors.role" required>
                    <Select id="create-role" v-model="createForm.role" :options="roleOptions" :placeholder="$t('admin.generated.k_885e5e5f1e2f')" :error="createForm.errors.role" />
                </FormGroup>
            </form>
            <template #footer>
                <Button variant="outline" @click="showCreateModal = false">{{ $t('admin.generated.k_f82fdcdd7d40') }}</Button>
                <Button variant="primary" :loading="createForm.processing" @click="submitCreate">{{ $t('admin.generated.k_cd0594581426') }}</Button>
            </template>
        </Modal>

        <Modal :show="showEditModal" :title="$t('admin.generated.k_88da05359142')" @close="showEditModal = false">
            <form class="space-y-4" @submit.prevent="submitEdit">
                <FormGroup :label="$t('admin.generated.k_6c6f3bac4d74')" html-for="edit-name" :error="editForm.errors.name" required>
                    <TextInput id="edit-name" v-model="editForm.name" :error="editForm.errors.name" />
                </FormGroup>
                <FormGroup :label="$t('admin.generated.k_6e66e9ca3801')" html-for="edit-email" :error="editForm.errors.email" required>
                    <TextInput id="edit-email" v-model="editForm.email" type="email" :error="editForm.errors.email" />
                </FormGroup>
                <FormGroup :label="$t('admin.generated.k_972d57b0f423')" html-for="edit-password" :error="editForm.errors.password">
                    <TextInput id="edit-password" v-model="editForm.password" type="password" :placeholder="$t('admin.generated.k_47c71c223106')" :error="editForm.errors.password" />
                </FormGroup>
                <FormGroup :label="$t('admin.generated.k_7f91e3d9af4e')" html-for="edit-role" :error="editForm.errors.role" required>
                    <Select id="edit-role" v-model="editForm.role" :options="roleOptions" :error="editForm.errors.role" />
                </FormGroup>
            </form>
            <template #footer>
                <Button variant="outline" @click="showEditModal = false">{{ $t('admin.generated.k_f82fdcdd7d40') }}</Button>
                <Button variant="primary" :loading="editForm.processing" @click="submitEdit">{{ $t('admin.generated.k_ddfd2663f9a7') }}</Button>
            </template>
        </Modal>

        <Modal :show="showDeleteModal" :title="$t('admin.generated.k_06ee39bc6bbd')" max-width="sm" @close="showDeleteModal = false">
            <p class="text-body-sm text-neutral-600">
{{ $t('admin.generated.k_63a39a44eb87') }} <strong>{{ selectedUser?.name }}</strong> {{ $t('admin.generated.k_2c867fdd9844') }} </p>
            <template #footer>
                <Button variant="outline" @click="showDeleteModal = false">{{ $t('admin.generated.k_f82fdcdd7d40') }}</Button>
                <Button variant="danger" @click="submitDelete">{{ $t('admin.generated.k_4d199f6b9036') }}</Button>
            </template>
        </Modal>

        <Modal :show="showRoleModal" :title="$t('admin.generated.k_b7d642eaa9e7')" max-width="sm" @close="showRoleModal = false">
            <FormGroup :label="$t('admin.generated.k_c05cd7e0b76f')" :error="roleForm.errors.name" required>
                <TextInput v-model="roleForm.name" :placeholder="$t('admin.generated.k_bc5896e5310e')" :error="roleForm.errors.name" />
            </FormGroup>
            <p class="mt-2 text-tiny text-neutral-400">{{ $t('admin.generated.k_c3e51ea63a5c') }}</p>
            <template #footer>
                <Button variant="outline" @click="showRoleModal = false">{{ $t('admin.generated.k_f82fdcdd7d40') }}</Button>
                <Button variant="primary" :loading="roleForm.processing" @click="submitRole">{{ $t('admin.generated.k_cd0594581426') }}</Button>
            </template>
        </Modal>

        <ToastContainer ref="toasts" />
    </component>
</template>
