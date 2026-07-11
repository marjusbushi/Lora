<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
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
    pos_staff: 'POS',
};
const roleBadge = {
    admin: 'dark',
    manager: 'accent',
    receptionist: 'info',
    housekeeping: 'warning',
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
    { value: '', label: 'Të gjitha rolet' },
    ...roleOptions.value,
]);
const statusFilterOptions = [
    { value: '', label: 'Çdo status' },
    { value: 'active', label: 'Aktiv' },
    { value: 'inactive', label: 'Joaktiv' },
];

const kpis = computed(() => [
    { key: 'total', label: 'Gjithsej', value: props.stats.total ?? 0, icon: UsersRound },
    { key: 'active', label: 'Aktivë', value: props.stats.active ?? 0, icon: UserRoundCheck },
    { key: 'inactive', label: 'Joaktiv', value: props.stats.inactive ?? 0, icon: UserRoundX },
    { key: 'roles', label: 'Role', value: props.stats.roles ?? props.roles.length, icon: ShieldCheck },
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
    return date.toLocaleDateString('sq-AL', { day: '2-digit', month: 'short', year: 'numeric' });
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
function applyFilters() {
    const params = {};
    if (searchQuery.value.trim()) params.search = searchQuery.value.trim();
    if (roleFilter.value) params.role = roleFilter.value;
    if (statusFilter.value) params.status = statusFilter.value;

    router.get(route('users.index'), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['users', 'filters', 'stats'],
    });
}

watch(searchQuery, () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(applyFilters, 300);
});
watch([roleFilter, statusFilter], applyFilters);
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
            toasts.value?.success('Përdoruesi u krijua.');
        },
    });
}

function submitEdit() {
    editForm.put(route('users.update', selectedUser.value.id), {
        onSuccess: () => {
            showEditModal.value = false;
            toasts.value?.success('Përdoruesi u përditësua.');
        },
    });
}

function submitDelete() {
    router.delete(route('users.destroy', selectedUser.value.id), {
        onSuccess: () => {
            showDeleteModal.value = false;
            toasts.value?.success('Përdoruesi u çaktivizua.');
        },
    });
}

function restoreUser(id) {
    router.post(route('users.restore', id), {}, {
        onSuccess: () => toasts.value?.success('Përdoruesi u riaktivizua.'),
    });
}

// ===== Roles & per-module CRUD permissions =====
const ALL_ACTIONS = ['view', 'create', 'update', 'delete', 'open', 'close', 'close_any'];
const actionLabel = { view: 'Shiko', create: 'Krijo', update: 'Edito', delete: 'Fshi', open: 'Hap', close: 'Mbyll', close_any: 'Mbyll çdo' };
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
        onSuccess: () => toasts.value?.success('Lejet u ruajtën.'),
        onError: () => toasts.value?.error('Ruajtja dështoi.'),
        onFinish: () => { savingPerms.value = false; },
    });
}
function submitRole() {
    roleForm.post(route('users.roles.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showRoleModal.value = false;
            roleForm.reset();
            toasts.value?.success('Roli u krijua.');
        },
    });
}
</script>

<template>
    <AppLayout>
        <PageHeader
            title="Përdoruesit & rolet"
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Përdoruesit' }]"
        >
            <template #actions>
                <Button v-if="activeTab === 'users'" variant="primary" @click="showCreateModal = true">
                    <template #icon-left><Plus class="h-4 w-4" :stroke-width="2" /></template>
                    Shto përdorues
                </Button>
                <Button v-else variant="outline" @click="showRoleModal = true">
                    <template #icon-left><Plus class="h-4 w-4" :stroke-width="2" /></template>
                    Krijo rol
                </Button>
            </template>
        </PageHeader>
        <p class="mt-1 text-body-sm text-neutral-500">Menaxho aksesin e stafit në hotel.</p>

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
                    Përdoruesit
                </button>
                <button
                    type="button"
                    :class="['inline-flex items-center gap-2 border-b-2 px-1 py-4 text-body-sm font-semibold transition-colors', activeTab === 'roles' ? 'border-accent-600 text-neutral-900' : 'border-transparent text-neutral-500 hover:text-neutral-800']"
                    @click="activeTab = 'roles'"
                >
                    <ShieldCheck class="h-[18px] w-[18px]" :stroke-width="1.8" />
                    Rolet &amp; lejet
                </button>
            </div>

            <template v-if="activeTab === 'users'">
                <div class="grid gap-3 border-b border-neutral-200 p-4 md:grid-cols-[minmax(260px,1fr)_220px_190px]">
                    <label class="relative block">
                        <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" :stroke-width="1.8" />
                        <input
                            v-model="searchQuery"
                            type="search"
                            class="h-10 w-full rounded-lg border border-neutral-200 bg-white py-2 pl-10 pr-3 text-body-sm text-neutral-900 placeholder:text-neutral-400 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30"
                            placeholder="Kërko emër ose email…"
                            aria-label="Kërko përdorues"
                        />
                    </label>
                    <Select v-model="roleFilter" :options="roleFilterOptions" aria-label="Filtro sipas rolit" />
                    <Select v-model="statusFilter" :options="statusFilterOptions" aria-label="Filtro sipas statusit" />
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="border-b border-neutral-200 bg-neutral-50/70">
                            <tr>
                                <th class="px-5 py-3 text-left text-label uppercase tracking-wider text-neutral-500">Përdoruesi</th>
                                <th class="px-5 py-3 text-left text-label uppercase tracking-wider text-neutral-500">Roli</th>
                                <th class="px-5 py-3 text-left text-label uppercase tracking-wider text-neutral-500">Statusi</th>
                                <th class="px-5 py-3 text-left text-label uppercase tracking-wider text-neutral-500">Shtuar më</th>
                                <th class="px-5 py-3 text-right text-label uppercase tracking-wider text-neutral-500">Veprime</th>
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
                                    <span v-else class="text-body-sm text-neutral-400">Pa rol</span>
                                </td>
                                <td class="px-5 py-3.5">
                                    <Badge :variant="user.membership_active ? 'success' : 'error'" dot>
                                        {{ user.membership_active ? 'Aktiv' : 'Joaktiv' }}
                                    </Badge>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-body-sm text-neutral-600">{{ formatDate(user.created_at) }}</td>
                                <td class="px-5 py-3.5 text-right">
                                    <ActionMenu>
                                        <template v-if="user.membership_active">
                                            <button type="button" :class="menuItemClass" role="menuitem" @click="openEdit(user)">
                                                <Pencil class="h-4 w-4 text-neutral-500" :stroke-width="1.8" />
                                                Edito përdoruesin
                                            </button>
                                            <button type="button" :class="[menuItemClass, 'text-error-700 hover:bg-error-50']" role="menuitem" @click="openDelete(user)">
                                                <UserRoundX class="h-4 w-4" :stroke-width="1.8" />
                                                Çaktivizo
                                            </button>
                                        </template>
                                        <button v-else type="button" :class="[menuItemClass, 'text-accent-700 hover:bg-accent-50']" role="menuitem" @click="restoreUser(user.id)">
                                            <RotateCcw class="h-4 w-4" :stroke-width="1.8" />
                                            Riaktivizo
                                        </button>
                                    </ActionMenu>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="!users.data?.length" class="px-6 py-14 text-center">
                    <UsersRound class="mx-auto h-8 w-8 text-neutral-300" :stroke-width="1.5" />
                    <p class="mt-3 text-body-sm font-medium text-neutral-700">Nuk u gjet asnjë përdorues.</p>
                    <p class="mt-1 text-small text-neutral-500">Ndrysho kërkimin ose filtrat.</p>
                </div>

                <div class="flex flex-col gap-3 border-t border-neutral-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-body-sm text-neutral-500">
                        {{ users.from || 0 }}–{{ users.to || 0 }} nga {{ users.total || 0 }} përdorues
                    </p>
                    <div class="flex items-center gap-2">
                        <Link
                            v-if="users.prev_page_url"
                            :href="users.prev_page_url"
                            preserve-scroll
                            class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-neutral-200 text-neutral-600 transition-colors hover:bg-neutral-50"
                            aria-label="Faqja e mëparshme"
                        >
                            <ChevronLeft class="h-4 w-4" />
                        </Link>
                        <span v-else class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-neutral-200 text-neutral-300" aria-hidden="true">
                            <ChevronLeft class="h-4 w-4" />
                        </span>
                        <Link
                            v-if="users.next_page_url"
                            :href="users.next_page_url"
                            preserve-scroll
                            class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-neutral-200 text-neutral-600 transition-colors hover:bg-neutral-50"
                            aria-label="Faqja tjetër"
                        >
                            <ChevronRight class="h-4 w-4" />
                        </Link>
                        <span v-else class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-neutral-200 text-neutral-300" aria-hidden="true">
                            <ChevronRight class="h-4 w-4" />
                        </span>
                    </div>
                </div>
            </template>

            <template v-else>
                <div class="flex flex-col gap-4 border-b border-neutral-200 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="text-body font-semibold text-neutral-900">Rolet &amp; lejet</h2>
                        <p class="mt-0.5 text-small text-neutral-500">Përcakto çfarë mund të shohë dhe ndryshojë çdo rol në hotel.</p>
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
                        <p>Roli <strong>Admin</strong> ka gjithmonë akses të plotë dhe nuk mund të kufizohet.</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-body-sm">
                            <thead>
                                <tr class="border-b border-neutral-200 text-label uppercase tracking-wider text-neutral-500">
                                    <th class="py-3 pr-5 text-left">Moduli</th>
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
                        Ruaj lejet e rolit “{{ roleLabel(selectedRole.name) }}”
                    </Button>
                </div>
            </template>
        </Card>

        <Modal :show="showCreateModal" title="Shto përdorues të ri" @close="showCreateModal = false">
            <form class="space-y-4" @submit.prevent="submitCreate">
                <FormGroup label="Emri i plotë" html-for="create-name" :error="createForm.errors.name" required>
                    <TextInput id="create-name" v-model="createForm.name" placeholder="Emri Mbiemri" :error="createForm.errors.name" />
                </FormGroup>
                <FormGroup label="Email" html-for="create-email" :error="createForm.errors.email" required>
                    <TextInput id="create-email" v-model="createForm.email" type="email" placeholder="email@hotel.com" :error="createForm.errors.email" />
                </FormGroup>
                <FormGroup label="Fjalëkalimi (vetëm për llogari të re)" html-for="create-password" :error="createForm.errors.password">
                    <TextInput id="create-password" v-model="createForm.password" type="password" placeholder="Minimumi 8 karaktere" :error="createForm.errors.password" />
                </FormGroup>
                <p class="-mt-2 text-tiny text-neutral-500">Nëse emaili ekziston në një hotel tjetër, lëre bosh: lidhim të njëjtën llogari me këtë hotel.</p>
                <FormGroup label="Roli" html-for="create-role" :error="createForm.errors.role" required>
                    <Select id="create-role" v-model="createForm.role" :options="roleOptions" placeholder="Zgjidh rolin…" :error="createForm.errors.role" />
                </FormGroup>
            </form>
            <template #footer>
                <Button variant="outline" @click="showCreateModal = false">Anulo</Button>
                <Button variant="primary" :loading="createForm.processing" @click="submitCreate">Krijo</Button>
            </template>
        </Modal>

        <Modal :show="showEditModal" title="Edito përdoruesin" @close="showEditModal = false">
            <form class="space-y-4" @submit.prevent="submitEdit">
                <FormGroup label="Emri i plotë" html-for="edit-name" :error="editForm.errors.name" required>
                    <TextInput id="edit-name" v-model="editForm.name" :error="editForm.errors.name" />
                </FormGroup>
                <FormGroup label="Email" html-for="edit-email" :error="editForm.errors.email" required>
                    <TextInput id="edit-email" v-model="editForm.email" type="email" :error="editForm.errors.email" />
                </FormGroup>
                <FormGroup label="Fjalëkalimi (lëre bosh për të mos e ndryshuar)" html-for="edit-password" :error="editForm.errors.password">
                    <TextInput id="edit-password" v-model="editForm.password" type="password" placeholder="Fjalëkalimi i ri…" :error="editForm.errors.password" />
                </FormGroup>
                <FormGroup label="Roli" html-for="edit-role" :error="editForm.errors.role" required>
                    <Select id="edit-role" v-model="editForm.role" :options="roleOptions" :error="editForm.errors.role" />
                </FormGroup>
            </form>
            <template #footer>
                <Button variant="outline" @click="showEditModal = false">Anulo</Button>
                <Button variant="primary" :loading="editForm.processing" @click="submitEdit">Ruaj</Button>
            </template>
        </Modal>

        <Modal :show="showDeleteModal" title="Çaktivizo përdoruesin" max-width="sm" @close="showDeleteModal = false">
            <p class="text-body-sm text-neutral-600">
                Je i sigurt që dëshiron të çaktivizosh <strong>{{ selectedUser?.name }}</strong> në këtë hotel? Aksesi në hotelet e tjera nuk ndryshon.
            </p>
            <template #footer>
                <Button variant="outline" @click="showDeleteModal = false">Anulo</Button>
                <Button variant="danger" @click="submitDelete">Çaktivizo</Button>
            </template>
        </Modal>

        <Modal :show="showRoleModal" title="Krijo rol të ri" max-width="sm" @close="showRoleModal = false">
            <FormGroup label="Emri i rolit" :error="roleForm.errors.name" required>
                <TextInput v-model="roleForm.name" placeholder="p.sh. kontabilist" :error="roleForm.errors.name" />
            </FormGroup>
            <p class="mt-2 text-tiny text-neutral-400">Vetëm shkronja të vogla, pa hapësira. Pas krijimit, zgjidh rolin dhe vendos lejet.</p>
            <template #footer>
                <Button variant="outline" @click="showRoleModal = false">Anulo</Button>
                <Button variant="primary" :loading="roleForm.processing" @click="submitRole">Krijo</Button>
            </template>
        </Modal>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
