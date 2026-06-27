<script setup>
import { ref, computed } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import Select from '@/Components/UI/Select.vue';
import Textarea from '@/Components/UI/Textarea.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';

const props = defineProps({
    rooms: Object,
    roomTypes: Array,
    floors: { type: Array, default: () => [] },
    filters: Object,
    stats: Object,
});

const toasts = ref(null);
const showCreateModal = ref(false);
const showEditModal = ref(false);
const showDeleteModal = ref(false);
const selectedRoom = ref(null);
const viewMode = ref('grid');

const page = usePage();
const userPerms = computed(() => page.props.auth.user?.permissions || []);
const canCreate = computed(() => userPerms.value.includes('create_rooms'));
const canUpdate = computed(() => userPerms.value.includes('update_rooms'));
const canDelete = computed(() => userPerms.value.includes('delete_rooms'));

const roomTypeOptions = props.roomTypes.map((t) => ({ value: t.id, label: `${t.name} (€${t.base_price})` }));
const statusOptions = [
    { value: 'available', label: 'E lire' },
    { value: 'occupied', label: 'E zene' },
    { value: 'cleaning', label: 'Pastrim' },
    { value: 'maintenance', label: 'Mirembajtje' },
];
// Floors come from Settings → Katet; fall back to 1-5 if none configured yet.
const floorOptions = (props.floors && props.floors.length)
    ? props.floors.map((f) => ({ value: f.number, label: f.name }))
    : [1, 2, 3, 4, 5].map((f) => ({ value: f, label: `Kati ${f}` }));
const floorNameMap = Object.fromEntries((props.floors || []).map((f) => [f.number, f.name]));
const floorName = (number) => floorNameMap[number] || `Kati ${number}`;

// Hotel-standard room-rack colour code: green=vacant clean, yellow=vacant dirty,
// red=occupied, grey=out of order. (Cloudbeds / Oracle Opera convention.)
const statusBadge = {
    available: { variant: 'success', label: 'E lire' },
    occupied: { variant: 'error', label: 'E zene' },
    cleaning: { variant: 'warning', label: 'Pastrim' },
    maintenance: { variant: 'neutral', label: 'Mirembajtje' },
};
// Only the dot is coloured — everything else stays calm/neutral.
const dotColor = {
    available: 'bg-success-500',
    occupied: 'bg-error-500',
    cleaning: 'bg-warning-500',
    maintenance: 'bg-neutral-400',
};
const labelColor = {
    available: 'text-success-700',
    occupied: 'text-error-700',
    cleaning: 'text-warning-700',
    maintenance: 'text-neutral-600',
};

const roomsByFloor = computed(() => {
    const groups = {};
    for (const room of props.rooms.data || []) {
        (groups[room.floor] ??= []).push(room);
    }
    return Object.keys(groups)
        .sort((a, b) => Number(a) - Number(b))
        .map((floor) => ({ floor, rooms: groups[floor] }));
});

const createForm = useForm({ room_type_id: '', room_number: '', floor: '', status: 'available', notes: '' });
const editForm = useForm({ room_type_id: '', room_number: '', floor: '', status: '', notes: '' });

const filterStatus = ref(props.filters?.status || '');
const filterFloor = ref(props.filters?.floor || '');
const filterType = ref(props.filters?.room_type_id || '');

function applyFilters() {
    const params = {};
    if (filterStatus.value) params.status = filterStatus.value;
    if (filterFloor.value) params.floor = filterFloor.value;
    if (filterType.value) params.room_type_id = filterType.value;
    router.get(route('rooms.index'), params, { preserveState: true });
}
function clearFilters() {
    filterStatus.value = '';
    filterFloor.value = '';
    filterType.value = '';
    router.get(route('rooms.index'), {}, { preserveState: true });
}
function openEdit(room) {
    selectedRoom.value = room;
    editForm.room_type_id = room.room_type_id;
    editForm.room_number = room.room_number;
    editForm.floor = room.floor;
    editForm.status = room.status;
    editForm.notes = room.notes || '';
    showEditModal.value = true;
}
function openDelete(room) {
    selectedRoom.value = room;
    showDeleteModal.value = true;
}
function submitCreate() {
    createForm.post(route('rooms.store'), {
        onSuccess: () => { showCreateModal.value = false; createForm.reset(); toasts.value?.success('Dhoma u shtua.'); },
    });
}
function submitEdit() {
    editForm.put(route('rooms.update', selectedRoom.value.id), {
        onSuccess: () => { showEditModal.value = false; toasts.value?.success('Dhoma u perditesua.'); },
    });
}
function submitDelete() {
    router.delete(route('rooms.destroy', selectedRoom.value.id), {
        onSuccess: () => { showDeleteModal.value = false; toasts.value?.success('Dhoma u fshi.'); },
    });
}
function quickStatus(room, status) {
    router.patch(route('rooms.status', room.id), { status }, {
        preserveScroll: true,
        onSuccess: () => toasts.value?.success(`Dhoma ${room.room_number}: ${statusBadge[status].label}`),
    });
}
</script>

<template>
    <AppLayout>
        <PageHeader
            title="Dhomat"
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Dhomat' }]"
        >
            <template #actions>
                <div class="inline-flex rounded-lg border border-neutral-200 bg-white p-0.5">
                    <button
                        :class="['px-3 py-1.5 rounded-md text-body-sm font-medium transition-colors', viewMode === 'grid' ? 'bg-primary-900 text-white' : 'text-neutral-500 hover:text-neutral-800']"
                        @click="viewMode = 'grid'"
                    >Rrjete</button>
                    <button
                        :class="['px-3 py-1.5 rounded-md text-body-sm font-medium transition-colors', viewMode === 'table' ? 'bg-primary-900 text-white' : 'text-neutral-500 hover:text-neutral-800']"
                        @click="viewMode = 'table'"
                    >Tabele</button>
                </div>
                <Button v-if="canCreate" variant="primary" @click="showCreateModal = true">+ Shto dhome</Button>
            </template>
        </PageHeader>

        <!-- Stats (calm: neutral numbers, a small status dot) -->
        <div class="mt-6 grid grid-cols-2 sm:grid-cols-5 gap-3">
            <Card v-for="(count, key) in stats" :key="key">
                <div class="flex items-center gap-2.5">
                    <span v-if="key !== 'total'" class="h-2 w-2 rounded-full shrink-0" :class="dotColor[key]"></span>
                    <div>
                        <p class="text-h3 text-primary-900 leading-none">{{ count }}</p>
                        <p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">
                            {{ key === 'total' ? 'Gjithsej' : statusBadge[key]?.label || key }}
                        </p>
                    </div>
                </div>
            </Card>
        </div>

        <!-- Filters -->
        <div class="mt-6 flex flex-wrap items-end gap-3">
            <div class="w-40">
                <Select v-model="filterStatus" :options="statusOptions" placeholder="Statusi..." @change="applyFilters" />
            </div>
            <div class="w-36">
                <Select v-model="filterFloor" :options="floorOptions" placeholder="Kati..." @change="applyFilters" />
            </div>
            <div class="w-48">
                <Select v-model="filterType" :options="roomTypeOptions" placeholder="Tipi..." @change="applyFilters" />
            </div>
            <Button v-if="filterStatus || filterFloor || filterType" variant="ghost" size="sm" @click="clearFilters">
                Pastro filtrat
            </Button>
        </div>

        <!-- GRID view: calm white cards grouped by floor -->
        <div v-if="viewMode === 'grid'" class="mt-6 space-y-8">
            <section v-for="group in roomsByFloor" :key="group.floor">
                <div class="flex items-center gap-3 mb-3">
                    <h3 class="text-label text-neutral-600 uppercase tracking-wider">{{ floorName(group.floor) }}</h3>
                    <span class="h-px flex-1 bg-neutral-200"></span>
                    <span class="text-tiny text-neutral-400">{{ group.rooms.length }} dhoma</span>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-3">
                    <div
                        v-for="room in group.rooms"
                        :key="room.id"
                        class="group relative rounded-lg border border-neutral-200 bg-white shadow-card hover:shadow-md transition-shadow duration-150 p-4"
                    >
                        <div class="flex items-start justify-between">
                            <p class="text-h3 text-primary-900 leading-none">{{ room.room_number }}</p>
                            <span class="h-2.5 w-2.5 rounded-full mt-1 shrink-0" :class="dotColor[room.status]" :title="statusBadge[room.status]?.label"></span>
                        </div>
                        <p class="text-small text-neutral-500 mt-2 truncate">{{ room.room_type?.name }}</p>
                        <div class="flex items-center justify-between mt-1">
                            <span class="text-tiny font-medium" :class="labelColor[room.status]">{{ statusBadge[room.status]?.label }}</span>
                            <span v-if="room.room_type" class="text-small text-neutral-400">€{{ room.room_type.base_price }}</span>
                        </div>

                        <!-- Controls (always visible, calm): quick status dots + edit/delete -->
                        <div v-if="canUpdate || canDelete" class="mt-3 pt-3 border-t border-neutral-100 flex items-center justify-between">
                            <div v-if="canUpdate" class="flex gap-2">
                                <button
                                    v-for="opt in statusOptions"
                                    :key="opt.value"
                                    :disabled="room.status === opt.value"
                                    :title="opt.label"
                                    class="h-4 w-4 rounded-full transition-transform hover:scale-110 disabled:cursor-default"
                                    :class="[dotColor[opt.value], room.status === opt.value ? 'ring-2 ring-offset-1 ring-neutral-400' : 'opacity-40 hover:opacity-100']"
                                    @click="quickStatus(room, opt.value)"
                                />
                            </div>
                            <div class="flex gap-1">
                                <button v-if="canUpdate" class="text-neutral-400 hover:text-neutral-800" title="Edito" @click="openEdit(room)">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M2.695 14.762l-1.262 3.155a.5.5 0 00.65.65l3.155-1.262a4 4 0 001.343-.886L17.5 5.501a2.121 2.121 0 00-3-3L3.58 13.419a4 4 0 00-.885 1.343z"/></svg>
                                </button>
                                <button v-if="canDelete" class="text-neutral-400 hover:text-error-600" title="Fshi" @click="openDelete(room)">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 006 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 10.23 1.482l.149-.022.841 10.518A2.75 2.75 0 007.596 19h4.807a2.75 2.75 0 002.742-2.53l.841-10.52.149.023a.75.75 0 00.23-1.482A41.03 41.03 0 0014 4.193V3.75A2.75 2.75 0 0011.25 1h-2.5zM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4z" clip-rule="evenodd"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- TABLE view -->
        <div v-else class="mt-6">
            <Card :padding="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200">
                        <thead class="bg-neutral-50">
                            <tr>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Dhoma</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Tipi</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Kati</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Statusi</th>
                                <th class="px-5 py-3 text-right text-label text-neutral-600">Cmimi</th>
                                <th class="px-5 py-3 text-right text-label text-neutral-600">Veprime</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="room in rooms.data" :key="room.id" class="hover:bg-neutral-50">
                                <td class="px-5 py-3 text-body-sm text-primary-900 font-medium">{{ room.room_number }}</td>
                                <td class="px-5 py-3 text-body-sm text-neutral-600">{{ room.room_type?.name }}</td>
                                <td class="px-5 py-3 text-body-sm text-neutral-600">{{ floorName(room.floor) }}</td>
                                <td class="px-5 py-3"><Badge :variant="statusBadge[room.status]?.variant" dot>{{ statusBadge[room.status]?.label }}</Badge></td>
                                <td class="px-5 py-3 text-right text-body-sm text-neutral-500">€{{ room.room_type?.base_price }}</td>
                                <td class="px-5 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <Button v-if="canUpdate" size="sm" variant="ghost" @click="openEdit(room)">Edito</Button>
                                        <Button v-if="canDelete" size="sm" variant="ghost" class="text-error-600" @click="openDelete(room)">Fshi</Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </div>

        <!-- Empty state -->
        <div v-if="!rooms.data?.length" class="mt-6">
            <Card>
                <div class="text-center py-12">
                    <p class="text-body-sm text-neutral-500">Nuk ka dhoma qe perputhen me filtrat.</p>
                    <Button v-if="canCreate" variant="outline" size="sm" class="mt-3" @click="showCreateModal = true">+ Shto dhome</Button>
                </div>
            </Card>
        </div>

        <!-- Create Modal -->
        <Modal :show="showCreateModal" title="Shto dhome te re" @close="showCreateModal = false">
            <form @submit.prevent="submitCreate" class="space-y-4">
                <FormGroup label="Numri i dhomes" :error="createForm.errors.room_number" required>
                    <TextInput v-model="createForm.room_number" placeholder="psh. 106" :error="createForm.errors.room_number" />
                </FormGroup>
                <FormGroup label="Tipi" :error="createForm.errors.room_type_id" required>
                    <Select v-model="createForm.room_type_id" :options="roomTypeOptions" :error="createForm.errors.room_type_id" />
                </FormGroup>
                <FormGroup label="Kati" :error="createForm.errors.floor" required>
                    <Select v-model="createForm.floor" :options="floorOptions" :error="createForm.errors.floor" />
                </FormGroup>
                <FormGroup label="Shenime">
                    <Textarea v-model="createForm.notes" placeholder="Shenime opsionale..." :rows="2" />
                </FormGroup>
            </form>
            <template #footer>
                <Button variant="outline" @click="showCreateModal = false">Anulo</Button>
                <Button variant="primary" :loading="createForm.processing" @click="submitCreate">Shto</Button>
            </template>
        </Modal>

        <!-- Edit Modal -->
        <Modal :show="showEditModal" title="Edito dhomen" @close="showEditModal = false">
            <form @submit.prevent="submitEdit" class="space-y-4">
                <FormGroup label="Numri i dhomes" :error="editForm.errors.room_number" required>
                    <TextInput v-model="editForm.room_number" :error="editForm.errors.room_number" />
                </FormGroup>
                <FormGroup label="Tipi" :error="editForm.errors.room_type_id" required>
                    <Select v-model="editForm.room_type_id" :options="roomTypeOptions" :error="editForm.errors.room_type_id" />
                </FormGroup>
                <div class="grid grid-cols-2 gap-4">
                    <FormGroup label="Kati" :error="editForm.errors.floor" required>
                        <Select v-model="editForm.floor" :options="floorOptions" :error="editForm.errors.floor" />
                    </FormGroup>
                    <FormGroup label="Statusi" :error="editForm.errors.status" required>
                        <Select v-model="editForm.status" :options="statusOptions" :error="editForm.errors.status" />
                    </FormGroup>
                </div>
                <FormGroup label="Shenime">
                    <Textarea v-model="editForm.notes" :rows="2" />
                </FormGroup>
            </form>
            <template #footer>
                <Button variant="outline" @click="showEditModal = false">Anulo</Button>
                <Button variant="primary" :loading="editForm.processing" @click="submitEdit">Ruaj</Button>
            </template>
        </Modal>

        <!-- Delete Confirmation -->
        <Modal :show="showDeleteModal" title="Fshi dhomen" max-width="sm" @close="showDeleteModal = false">
            <p class="text-body-sm text-neutral-600">
                Je i sigurt qe deshiron te fshish dhomen <strong>{{ selectedRoom?.room_number }}</strong>?
            </p>
            <template #footer>
                <Button variant="outline" @click="showDeleteModal = false">Anulo</Button>
                <Button variant="danger" @click="submitDelete">Fshi</Button>
            </template>
        </Modal>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
