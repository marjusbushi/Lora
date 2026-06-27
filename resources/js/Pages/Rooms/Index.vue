<script setup>
import { ref, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
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
    filters: Object,
    stats: Object,
});

const toasts = ref(null);
const showCreateModal = ref(false);
const showEditModal = ref(false);
const showDeleteModal = ref(false);
const selectedRoom = ref(null);

const page = usePage();
const userPerms = computed(() => page.props.auth.user?.permissions || []);
const canCreate = computed(() => userPerms.value.includes('create_rooms'));
const canUpdate = computed(() => userPerms.value.includes('update_rooms'));
const canDelete = computed(() => userPerms.value.includes('delete_rooms'));

import { usePage } from '@inertiajs/vue3';

const roomTypeOptions = props.roomTypes.map((t) => ({ value: t.id, label: `${t.name} (€${t.base_price})` }));
const statusOptions = [
    { value: 'available', label: 'E lire' },
    { value: 'occupied', label: 'E zene' },
    { value: 'cleaning', label: 'Pastrim' },
    { value: 'maintenance', label: 'Mirembajtje' },
];
const floorOptions = [1, 2, 3, 4, 5].map((f) => ({ value: f, label: `Kati ${f}` }));

const statusBadge = {
    available: { variant: 'success', label: 'E lire' },
    occupied: { variant: 'error', label: 'E zene' },
    cleaning: { variant: 'warning', label: 'Pastrim' },
    maintenance: { variant: 'neutral', label: 'Mirembajtje' },
};

const createForm = useForm({
    room_type_id: '',
    room_number: '',
    floor: '',
    status: 'available',
    notes: '',
});

const editForm = useForm({
    room_type_id: '',
    room_number: '',
    floor: '',
    status: '',
    notes: '',
});

// Filters
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
        onSuccess: () => {
            showCreateModal.value = false;
            createForm.reset();
            toasts.value?.success('Dhoma u shtua.');
        },
    });
}

function submitEdit() {
    editForm.put(route('rooms.update', selectedRoom.value.id), {
        onSuccess: () => {
            showEditModal.value = false;
            toasts.value?.success('Dhoma u perditesua.');
        },
    });
}

function submitDelete() {
    router.delete(route('rooms.destroy', selectedRoom.value.id), {
        onSuccess: () => {
            showDeleteModal.value = false;
            toasts.value?.success('Dhoma u fshi.');
        },
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
                <Button v-if="canCreate" variant="primary" @click="showCreateModal = true">+ Shto dhome</Button>
            </template>
        </PageHeader>

        <!-- Stats cards -->
        <div class="mt-6 grid grid-cols-2 sm:grid-cols-5 gap-3">
            <Card v-for="(count, key) in stats" :key="key">
                <div class="text-center">
                    <p class="text-h3 text-primary-900">{{ count }}</p>
                    <p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">
                        {{ key === 'total' ? 'Gjithsej' : statusBadge[key]?.label || key }}
                    </p>
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

        <!-- Room grid -->
        <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            <Card v-for="room in rooms.data" :key="room.id">
                <div class="flex items-start justify-between">
                    <div>
                        <h4 class="text-h4 text-primary-900">{{ room.room_number }}</h4>
                        <p class="text-body-sm text-neutral-500 mt-0.5">{{ room.room_type?.name }} — Kati {{ room.floor }}</p>
                    </div>
                    <Badge :variant="statusBadge[room.status]?.variant" dot>
                        {{ statusBadge[room.status]?.label }}
                    </Badge>
                </div>

                <p v-if="room.room_type" class="text-label text-accent-600 mt-3">
                    €{{ room.room_type.base_price }}/nate
                </p>

                <p v-if="room.notes" class="text-small text-neutral-400 mt-2 line-clamp-2">{{ room.notes }}</p>

                <!-- Quick status buttons -->
                <div v-if="canUpdate" class="mt-4 flex flex-wrap gap-1.5">
                    <button
                        v-for="opt in statusOptions"
                        :key="opt.value"
                        :disabled="room.status === opt.value"
                        :class="[
                            'px-2 py-1 rounded-md text-tiny font-medium transition-colors duration-150',
                            room.status === opt.value
                                ? 'bg-neutral-100 text-neutral-400 cursor-default'
                                : 'bg-neutral-50 text-neutral-600 hover:bg-neutral-200',
                        ]"
                        @click="quickStatus(room, opt.value)"
                    >
                        {{ opt.label }}
                    </button>
                </div>

                <!-- Actions -->
                <div v-if="canUpdate || canDelete" class="mt-3 pt-3 border-t border-neutral-100 flex gap-2">
                    <Button v-if="canUpdate" size="sm" variant="ghost" @click="openEdit(room)">Edito</Button>
                    <Button v-if="canDelete" size="sm" variant="ghost" class="text-error-600" @click="openDelete(room)">Fshi</Button>
                </div>
            </Card>
        </div>

        <!-- Empty state -->
        <div v-if="!rooms.data?.length" class="mt-6 text-center py-12">
            <p class="text-body-sm text-neutral-500">Nuk ka dhoma qe perputhen me filtrat.</p>
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
