<script setup>
import { ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Modal from '@/Components/UI/Modal.vue';
import Select from '@/Components/UI/Select.vue';
import Textarea from '@/Components/UI/Textarea.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';

const props = defineProps({
    tasks: Object,
    housekeepers: Array,
    filters: Object,
    stats: Object,
});

const toasts = ref(null);
const showIssueModal = ref(false);
const selectedTask = ref(null);
const issueText = ref('');
const setMaintenance = ref(false);

const perms = usePage().props.auth.user?.permissions || [];
const canUpdate = perms.includes('update_housekeeping');

const statusBadge = {
    pending: { variant: 'warning', label: 'Ne pritje' },
    in_progress: { variant: 'info', label: 'Duke pastruar' },
    completed: { variant: 'success', label: 'Perfunduar' },
    inspected: { variant: 'accent', label: 'Inspektuar' },
};

const typeBadge = {
    checkout_clean: { variant: 'error', label: 'Check-out' },
    stayover_clean: { variant: 'info', label: 'Ditor' },
    deep_clean: { variant: 'dark', label: 'Thellesi' },
    inspection: { variant: 'neutral', label: 'Inspektim' },
};

const priorityBadge = {
    urgent: { variant: 'error', label: 'Urgjent' },
    normal: { variant: 'neutral', label: 'Normal' },
};

const statusFilterOptions = [
    { value: 'pending', label: 'Ne pritje' },
    { value: 'in_progress', label: 'Duke pastruar' },
    { value: 'completed', label: 'Perfunduar' },
    { value: 'inspected', label: 'Inspektuar' },
];

const floorOptions = [1, 2, 3, 4, 5].map((f) => ({ value: f, label: `Kati ${f}` }));
const filterStatus = ref(props.filters?.status || '');
const filterFloor = ref(props.filters?.floor || '');

function applyFilters() {
    const params = {};
    if (filterStatus.value) params.status = filterStatus.value;
    if (filterFloor.value) params.floor = filterFloor.value;
    router.get(route('housekeeping.index'), params, { preserveState: true });
}

function clearFilters() {
    filterStatus.value = '';
    filterFloor.value = '';
    router.get(route('housekeeping.index'), {}, { preserveState: true });
}

function changeStatus(task, status) {
    router.patch(route('housekeeping.status', task.id), { status }, {
        preserveScroll: true,
        onSuccess: () => toasts.value?.success(`Dhoma ${task.room?.room_number}: ${statusBadge[status].label}`),
    });
}

function assignTask(task, userId) {
    router.patch(route('housekeeping.assign', task.id), { assigned_to: userId }, {
        preserveScroll: true,
        onSuccess: () => toasts.value?.success('Detyra u caktua.'),
    });
}

function openIssue(task) {
    selectedTask.value = task;
    issueText.value = '';
    setMaintenance.value = false;
    showIssueModal.value = true;
}

function submitIssue() {
    router.post(route('housekeeping.issue', selectedTask.value.id), {
        issue_reported: issueText.value,
        set_maintenance: setMaintenance.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showIssueModal.value = false;
            toasts.value?.warning('Problemi u raportua.');
        },
    });
}

const housekeeperOptions = props.housekeepers.map((h) => ({ value: h.id, label: h.name }));
</script>

<template>
    <AppLayout>
        <PageHeader
            title="Housekeeping"
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Housekeeping' }]"
        />

        <!-- Stats -->
        <div class="mt-6 grid grid-cols-2 sm:grid-cols-4 gap-3">
            <Card>
                <div class="text-center">
                    <p class="text-h3 text-warning-600">{{ stats.pending }}</p>
                    <p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">Ne pritje</p>
                </div>
            </Card>
            <Card>
                <div class="text-center">
                    <p class="text-h3 text-info-600">{{ stats.in_progress }}</p>
                    <p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">Duke pastruar</p>
                </div>
            </Card>
            <Card>
                <div class="text-center">
                    <p class="text-h3 text-success-600">{{ stats.completed }}</p>
                    <p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">Sot perfunduar</p>
                </div>
            </Card>
            <Card>
                <div class="text-center">
                    <p class="text-h3 text-error-600">{{ stats.urgent }}</p>
                    <p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">Urgjente</p>
                </div>
            </Card>
        </div>

        <!-- Filters -->
        <div class="mt-6 flex flex-wrap items-end gap-3">
            <div class="w-40">
                <Select v-model="filterStatus" :options="statusFilterOptions" placeholder="Statusi..." @change="applyFilters" />
            </div>
            <div class="w-36">
                <Select v-model="filterFloor" :options="floorOptions" placeholder="Kati..." @change="applyFilters" />
            </div>
            <Button v-if="filterStatus || filterFloor" variant="ghost" size="sm" @click="clearFilters">Pastro</Button>
        </div>

        <!-- Task list -->
        <div class="mt-6 space-y-3">
            <Card v-for="task in tasks.data" :key="task.id">
                <div class="flex flex-col sm:flex-row sm:items-center gap-4">
                    <!-- Room + type info -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h4 class="text-h4 text-primary-900">Dhoma {{ task.room?.room_number }}</h4>
                            <Badge :variant="typeBadge[task.type]?.variant" size="sm">{{ typeBadge[task.type]?.label }}</Badge>
                            <Badge :variant="priorityBadge[task.priority]?.variant" size="sm">{{ priorityBadge[task.priority]?.label }}</Badge>
                        </div>
                        <p class="text-body-sm text-neutral-500 mt-1">
                            {{ task.room?.room_type?.name }} — Kati {{ task.room?.floor }}
                            <span v-if="task.assigned_user" class="ml-2 text-accent-600">· {{ task.assigned_user.name }}</span>
                            <span v-else class="ml-2 text-error-500">· Pa caktuar</span>
                        </p>
                        <p v-if="task.notes" class="text-small text-neutral-400 mt-1">{{ task.notes }}</p>
                        <p v-if="task.issue_reported" class="text-small text-error-600 mt-1">⚠ {{ task.issue_reported }}</p>
                    </div>

                    <!-- Status badge -->
                    <div class="shrink-0">
                        <Badge :variant="statusBadge[task.status]?.variant" dot>
                            {{ statusBadge[task.status]?.label }}
                        </Badge>
                    </div>

                    <!-- Actions -->
                    <div v-if="canUpdate" class="shrink-0 flex flex-wrap items-center gap-1.5">
                        <Button v-if="task.status === 'pending'" size="sm" variant="primary" @click="changeStatus(task, 'in_progress')">Fillo</Button>
                        <Button v-if="task.status === 'in_progress'" size="sm" variant="primary" @click="changeStatus(task, 'completed')">Perfundo</Button>
                        <Button v-if="task.status === 'completed'" size="sm" variant="outline" @click="changeStatus(task, 'inspected')">Inspekto</Button>

                        <!-- Assign dropdown -->
                        <div v-if="['pending', 'in_progress'].includes(task.status) && housekeeperOptions.length" class="w-36">
                            <Select
                                :model-value="task.assigned_to || ''"
                                :options="housekeeperOptions"
                                placeholder="Cakto..."
                                @update:model-value="(val) => assignTask(task, val)"
                            />
                        </div>

                        <Button v-if="['in_progress', 'completed'].includes(task.status)" size="sm" variant="ghost" class="text-error-600" @click="openIssue(task)">Raporto problem</Button>
                    </div>
                </div>
            </Card>

            <div v-if="!tasks.data?.length" class="text-center py-12">
                <p class="text-body-sm text-neutral-500">Nuk ka detyra pastrimi.</p>
            </div>
        </div>

        <!-- Issue Report Modal -->
        <Modal :show="showIssueModal" title="Raporto problem" max-width="md" @close="showIssueModal = false">
            <div class="space-y-4">
                <FormGroup label="Pershkrimi i problemit" required>
                    <Textarea v-model="issueText" placeholder="Pershkruaj problemin e gjetur..." :rows="3" />
                </FormGroup>
                <label class="flex items-center gap-2 text-body-sm text-neutral-700">
                    <input type="checkbox" v-model="setMaintenance" class="h-4 w-4 rounded border-neutral-300 text-accent-600" />
                    Vendos dhomen ne mirembajtje
                </label>
            </div>
            <template #footer>
                <Button variant="outline" @click="showIssueModal = false">Anulo</Button>
                <Button variant="danger" :disabled="!issueText" @click="submitIssue">Raporto</Button>
            </template>
        </Modal>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
