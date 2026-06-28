<script setup>
import { ref, computed, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import Select from '@/Components/UI/Select.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import Alert from '@/Components/UI/Alert.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';

const props = defineProps({
    users: Object,
    roles: Array,
    permissionModules: { type: Array, default: () => [] },
    rolesDetailed: { type: Array, default: () => [] },
});

const toasts = ref(null);
const showCreateModal = ref(false);
const showEditModal = ref(false);
const showDeleteModal = ref(false);
const selectedUser = ref(null);

const roleOptions = props.roles.map((r) => ({ value: r, label: r.charAt(0).toUpperCase() + r.slice(1).replace('_', ' ') }));

const createForm = useForm({
    name: '',
    email: '',
    password: '',
    role: '',
});

const editForm = useForm({
    name: '',
    email: '',
    password: '',
    role: '',
});

const roleBadge = {
    admin: 'dark',
    manager: 'accent',
    receptionist: 'info',
    housekeeping: 'warning',
    pos_staff: 'neutral',
};

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
            toasts.value?.success('Perdoruesi u krijua.');
        },
    });
}

function submitEdit() {
    editForm.put(route('users.update', selectedUser.value.id), {
        onSuccess: () => {
            showEditModal.value = false;
            toasts.value?.success('Perdoruesi u perditesua.');
        },
    });
}

function submitDelete() {
    router.delete(route('users.destroy', selectedUser.value.id), {
        onSuccess: () => {
            showDeleteModal.value = false;
            toasts.value?.success('Perdoruesi u deaktivizua.');
        },
    });
}

function restoreUser(id) {
    router.post(route('users.restore', id), {}, {
        onSuccess: () => toasts.value?.success('Perdoruesi u riaktivizua.'),
    });
}

// ===== Roles & per-module CRUD permissions =====
const ALL_ACTIONS = ['view', 'create', 'update', 'delete', 'open', 'close', 'close_any'];
const actionLabel = { view: 'Shiko', create: 'Krijo', update: 'Edito', delete: 'Fshi', open: 'Hap', close: 'Mbyll', close_any: 'Mbyll çdo' };

const selectedRoleId = ref(props.rolesDetailed[0]?.id ?? null);
const selectedRole = computed(() => props.rolesDetailed.find((r) => r.id === selectedRoleId.value) || null);
const checked = ref({});
const savingPerms = ref(false);
const showRoleModal = ref(false);
const roleForm = useForm({ name: '' });

function loadRolePerms(role) {
    const map = {};
    (role?.permissions || []).forEach((p) => { map[p] = true; });
    checked.value = map;
}
watch(selectedRole, (r) => loadRolePerms(r), { immediate: true });

const permName = (moduleKey, action) => `${action}_${moduleKey}`;
function isChecked(moduleKey, action) {
    if (selectedRole.value && !selectedRole.value.editable) return true; // admin = full access
    return !!checked.value[permName(moduleKey, action)];
}
function toggle(moduleKey, action) {
    const k = permName(moduleKey, action);
    checked.value = { ...checked.value, [k]: !checked.value[k] };
}
function saveRolePerms() {
    if (!selectedRole.value?.editable) return;
    savingPerms.value = true;
    const perms = Object.keys(checked.value).filter((k) => checked.value[k]);
    router.put(route('users.roles.permissions', selectedRole.value.id), { permissions: perms }, {
        preserveScroll: true,
        onSuccess: () => toasts.value?.success('Lejet u ruajten.'),
        onError: () => toasts.value?.error('Ruajtja deshtoi.'),
        onFinish: () => { savingPerms.value = false; },
    });
}
function submitRole() {
    roleForm.post(route('users.roles.store'), {
        preserveScroll: true,
        onSuccess: () => { showRoleModal.value = false; roleForm.reset(); toasts.value?.success('Roli u krijua.'); },
    });
}
</script>

<template>
    <AppLayout>
        <PageHeader
            title="Perdoruesit"
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Perdoruesit' }]"
        >
            <template #actions>
                <Button variant="primary" @click="showCreateModal = true">
                    + Shto perdorues
                </Button>
            </template>
        </PageHeader>

        <div class="mt-6">
            <Card :padding="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200">
                        <thead class="bg-neutral-50">
                            <tr>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Emri</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Email</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Roli</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Statusi</th>
                                <th class="px-5 py-3 text-right text-label text-neutral-600">Veprime</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="user in users.data" :key="user.id" class="hover:bg-neutral-50 transition-colors duration-100">
                                <td class="px-5 py-3 text-body-sm text-primary-900 font-medium">{{ user.name }}</td>
                                <td class="px-5 py-3 text-body-sm text-neutral-600">{{ user.email }}</td>
                                <td class="px-5 py-3">
                                    <Badge
                                        v-if="user.roles?.length"
                                        :variant="roleBadge[user.roles[0].name] || 'neutral'"
                                    >
                                        {{ user.roles[0].name }}
                                    </Badge>
                                </td>
                                <td class="px-5 py-3">
                                    <Badge :variant="user.deleted_at ? 'error' : 'success'" dot>
                                        {{ user.deleted_at ? 'Joaktiv' : 'Aktiv' }}
                                    </Badge>
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <template v-if="!user.deleted_at">
                                            <Button size="sm" variant="ghost" @click="openEdit(user)">Edito</Button>
                                            <Button size="sm" variant="ghost" class="text-error-600 hover:text-error-700" @click="openDelete(user)">Fshi</Button>
                                        </template>
                                        <Button v-else size="sm" variant="ghost" class="text-accent-600" @click="restoreUser(user.id)">Riaktivizo</Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Empty state -->
                <div v-if="!users.data?.length" class="px-6 py-12 text-center">
                    <p class="text-body-sm text-neutral-500">Nuk ka perdorues akoma.</p>
                </div>
            </Card>
        </div>

        <!-- Roles & Permissions matrix -->
        <div class="mt-8">
            <Card :padding="false">
                <div class="px-5 py-4 border-b border-neutral-200 flex items-center justify-between gap-3">
                    <div>
                        <h3 class="text-label text-neutral-600 uppercase tracking-wider">Rolet &amp; Lejet</h3>
                        <p class="text-small text-neutral-500 mt-0.5">Çfarë mund të bëjë çdo rol për çdo modul. Përdoruesit i jep një rol këtu lart.</p>
                    </div>
                    <Button size="sm" variant="outline" @click="showRoleModal = true">+ Krijo rol</Button>
                </div>

                <!-- Role selector -->
                <div class="px-5 pt-4 flex flex-wrap gap-2">
                    <button
                        v-for="r in rolesDetailed"
                        :key="r.id"
                        type="button"
                        :class="['px-3 py-1.5 rounded-lg text-body-sm font-medium transition-colors', selectedRoleId === r.id ? 'bg-accent-600 text-white' : 'bg-neutral-100 text-neutral-600 hover:bg-neutral-200']"
                        @click="selectedRoleId = r.id"
                    >
                        {{ r.name }}<span v-if="!r.editable" class="ml-1 opacity-80">🔒</span>
                    </button>
                </div>

                <!-- Matrix -->
                <div class="p-5 overflow-x-auto">
                    <p v-if="selectedRole && !selectedRole.editable" class="mb-3 text-small text-neutral-600 bg-neutral-50 border border-neutral-200 rounded-lg px-3 py-2">
                        🔒 Roli <b>admin</b> ka gjithmonë akses të plotë dhe nuk kufizohet (që të mos mbetesh vetë jashtë sistemit).
                    </p>
                    <table class="min-w-full text-body-sm">
                        <thead>
                            <tr class="text-label text-neutral-500 border-b border-neutral-100">
                                <th class="text-left py-2 pr-4">Moduli</th>
                                <th v-for="a in ALL_ACTIONS" :key="a" class="px-2 py-2 text-center font-medium">{{ actionLabel[a] }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="m in permissionModules" :key="m.key">
                                <td class="py-2.5 pr-4 text-primary-900 font-medium whitespace-nowrap">{{ m.label }}</td>
                                <td v-for="a in ALL_ACTIONS" :key="a" class="px-2 py-2.5 text-center">
                                    <input
                                        v-if="m.actions.includes(a)"
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-neutral-300 text-accent-600 focus:ring-accent-500 cursor-pointer disabled:opacity-50"
                                        :checked="isChecked(m.key, a)"
                                        :disabled="selectedRole && !selectedRole.editable"
                                        @change="toggle(m.key, a)"
                                    />
                                    <span v-else class="text-neutral-300">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="selectedRole && selectedRole.editable" class="px-5 py-4 border-t border-neutral-200 flex justify-end">
                    <Button variant="primary" :loading="savingPerms" @click="saveRolePerms">Ruaj lejet e rolit "{{ selectedRole.name }}"</Button>
                </div>
            </Card>
        </div>

        <!-- Create Modal -->
        <Modal :show="showCreateModal" title="Shto perdorues te ri" @close="showCreateModal = false">
            <form @submit.prevent="submitCreate" class="space-y-4">
                <FormGroup label="Emri i plote" html-for="create-name" :error="createForm.errors.name" required>
                    <TextInput id="create-name" v-model="createForm.name" placeholder="Emri Mbiemri" :error="createForm.errors.name" />
                </FormGroup>
                <FormGroup label="Email" html-for="create-email" :error="createForm.errors.email" required>
                    <TextInput id="create-email" type="email" v-model="createForm.email" placeholder="email@hotel.com" :error="createForm.errors.email" />
                </FormGroup>
                <FormGroup label="Password" html-for="create-password" :error="createForm.errors.password" required>
                    <TextInput id="create-password" type="password" v-model="createForm.password" placeholder="Min. 8 karaktere" :error="createForm.errors.password" />
                </FormGroup>
                <FormGroup label="Roli" html-for="create-role" :error="createForm.errors.role" required>
                    <Select id="create-role" v-model="createForm.role" :options="roleOptions" placeholder="Zgjidh rolin..." :error="createForm.errors.role" />
                </FormGroup>
            </form>
            <template #footer>
                <Button variant="outline" @click="showCreateModal = false">Anulo</Button>
                <Button variant="primary" :loading="createForm.processing" @click="submitCreate">Krijo</Button>
            </template>
        </Modal>

        <!-- Edit Modal -->
        <Modal :show="showEditModal" title="Edito perdoruesin" @close="showEditModal = false">
            <form @submit.prevent="submitEdit" class="space-y-4">
                <FormGroup label="Emri i plote" html-for="edit-name" :error="editForm.errors.name" required>
                    <TextInput id="edit-name" v-model="editForm.name" :error="editForm.errors.name" />
                </FormGroup>
                <FormGroup label="Email" html-for="edit-email" :error="editForm.errors.email" required>
                    <TextInput id="edit-email" type="email" v-model="editForm.email" :error="editForm.errors.email" />
                </FormGroup>
                <FormGroup label="Password (le bosh per te mos ndryshuar)" html-for="edit-password" :error="editForm.errors.password">
                    <TextInput id="edit-password" type="password" v-model="editForm.password" placeholder="Password e re..." :error="editForm.errors.password" />
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

        <!-- Delete Confirmation -->
        <Modal :show="showDeleteModal" title="Deaktivizo perdoruesin" max-width="sm" @close="showDeleteModal = false">
            <p class="text-body-sm text-neutral-600">
                Je i sigurt qe deshiron te deaktivizosh <strong>{{ selectedUser?.name }}</strong>? Perdoruesi nuk do mund te hyje me ne sistem.
            </p>
            <template #footer>
                <Button variant="outline" @click="showDeleteModal = false">Anulo</Button>
                <Button variant="danger" @click="submitDelete">Deaktivizo</Button>
            </template>
        </Modal>

        <!-- Create Role Modal -->
        <Modal :show="showRoleModal" title="Krijo rol të ri" max-width="sm" @close="showRoleModal = false">
            <FormGroup label="Emri i rolit" :error="roleForm.errors.name" required>
                <TextInput v-model="roleForm.name" placeholder="psh. kontabilist" :error="roleForm.errors.name" />
            </FormGroup>
            <p class="text-tiny text-neutral-400 mt-2">Vetëm shkronja të vogla, pa hapësira (p.sh. <code>kontabilist</code>). Pasi ta krijosh, zgjidhe te skedat lart dhe vendos lejet.</p>
            <template #footer>
                <Button variant="outline" @click="showRoleModal = false">Anulo</Button>
                <Button variant="primary" :loading="roleForm.processing" @click="submitRole">Krijo</Button>
            </template>
        </Modal>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
