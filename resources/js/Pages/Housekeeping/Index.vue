<script setup>
import { ref, computed } from 'vue';
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
const viewMode = ref('kanban');

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

// Kanban columns (calm — only a small dot is coloured)
const columns = [
    { status: 'pending', label: 'Ne pritje', dot: 'bg-warning-500' },
    { status: 'in_progress', label: 'Duke pastruar', dot: 'bg-info-500' },
    { status: 'completed', label: 'Perfunduar', dot: 'bg-success-500' },
    { status: 'inspected', label: 'Inspektuar', dot: 'bg-accent-500' },
];
const tasksByStatus = computed(() => {
    const g = { pending: [], in_progress: [], completed: [], inspected: [] };
    for (const t of props.tasks.data || []) (g[t.status] ??= []).push(t);
    return g;
});

const floorOptions = [1, 2, 3, 4, 5].map((f) => ({ value: f, label: `Kati ${f}` }));
const statusFilterOptions = [
    { value: 'pending', label: 'Ne pritje' },
    { value: 'in_progress', label: 'Duke pastruar' },
    { value: 'completed', label: 'Perfunduar' },
    { value: 'inspected', label: 'Inspektuar' },
];
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
        onSuccess: () => { showIssueModal.value = false; toasts.value?.warning('Problemi u raportua.'); },
    });
}
const housekeeperOptions = props.housekeepers.map((h) => ({ value: h.id, label: h.name }));
</script>

<template>
    <AppLayout>
        <PageHeader
            title="Housekeeping"
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Housekeeping' }]"
        >
            <template #actions>
                <div class="inline-flex rounded-lg border border-neutral-200 bg-white p-0.5">
                    <button
                        :class="['px-3 py-1.5 rounded-md text-body-sm font-medium transition-colors', viewMode === 'kanban' ? 'bg-primary-900 text-white' : 'text-neutral-500 hover:text-neutral-800']"
                        @click="viewMode = 'kanban'"
                    >Bord</button>
                    <button
                        :class="['px-3 py-1.5 rounded-md text-body-sm font-medium transition-colors', viewMode === 'table' ? 'bg-primary-900 text-white' : 'text-neutral-500 hover:text-neutral-800']"
                        @click="viewMode = 'table'"
                    >Liste</button>
                </div>
            </template>
        </PageHeader>

        <!-- Stats (calm) -->
        <div class="mt-6 grid grid-cols-2 sm:grid-cols-4 gap-3">
            <Card>
                <div class="flex items-center gap-2.5">
                    <span class="h-2 w-2 rounded-full bg-warning-500 shrink-0"></span>
                    <div><p class="text-h3 text-primary-900 leading-none">{{ stats.pending }}</p><p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">Ne pritje</p></div>
                </div>
            </Card>
            <Card>
                <div class="flex items-center gap-2.5">
                    <span class="h-2 w-2 rounded-full bg-info-500 shrink-0"></span>
                    <div><p class="text-h3 text-primary-900 leading-none">{{ stats.in_progress }}</p><p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">Duke pastruar</p></div>
                </div>
            </Card>
            <Card>
                <div class="flex items-center gap-2.5">
                    <span class="h-2 w-2 rounded-full bg-success-500 shrink-0"></span>
                    <div><p class="text-h3 text-primary-900 leading-none">{{ stats.completed }}</p><p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">Sot perfunduar</p></div>
                </div>
            </Card>
            <Card>
                <div class="flex items-center gap-2.5">
                    <span class="h-2 w-2 rounded-full bg-error-500 shrink-0"></span>
                    <div><p class="text-h3 text-primary-900 leading-none">{{ stats.urgent }}</p><p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">Urgjente</p></div>
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

        <!-- KANBAN board -->
        <div v-if="viewMode === 'kanban'" class="mt-6 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
            <div v-for="col in columns" :key="col.status" class="rounded-lg bg-neutral-50 border border-neutral-200 p-3">
                <div class="flex items-center gap-2 mb-3 px-1">
                    <span class="h-2 w-2 rounded-full" :class="col.dot"></span>
                    <h3 class="text-label text-neutral-700">{{ col.label }}</h3>
                    <span class="ml-auto text-tiny text-neutral-400">{{ tasksByStatus[col.status].length }}</span>
                </div>

                <div class="space-y-2.5">
                    <div
                        v-for="task in tasksByStatus[col.status]"
                        :key="task.id"
                        class="rounded-lg border border-neutral-200 bg-white shadow-card p-3"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-h4 text-primary-900 leading-none">Dhoma {{ task.room?.room_number }}</p>
                            <span v-if="task.priority === 'urgent'" class="text-tiny font-medium text-error-600 shrink-0">Urgjent</span>
                        </div>
                        <p class="text-small text-neutral-500 mt-1.5">{{ task.room?.room_type?.name }} · Kati {{ task.room?.floor }}</p>
                        <div class="flex items-center gap-1.5 mt-1">
                            <Badge :variant="typeBadge[task.type]?.variant" size="sm">{{ typeBadge[task.type]?.label }}</Badge>
                            <span class="text-tiny" :class="task.assigned_user ? 'text-neutral-500' : 'text-error-500'">
                                {{ task.assigned_user ? task.assigned_user.name : 'Pa caktuar' }}
                            </span>
                        </div>
                        <p v-if="task.issue_reported" class="text-tiny text-error-600 mt-1.5">⚠ {{ task.issue_reported }}</p>

                        <!-- Actions (always visible) -->
                        <div v-if="canUpdate" class="mt-2.5 pt-2.5 border-t border-neutral-100 flex items-center gap-1.5">
                            <Button v-if="task.status === 'pending'" size="sm" variant="primary" @click="changeStatus(task, 'in_progress')">Fillo</Button>
                            <Button v-else-if="task.status === 'in_progress'" size="sm" variant="primary" @click="changeStatus(task, 'completed')">Perfundo</Button>
                            <Button v-else-if="task.status === 'completed'" size="sm" variant="outline" @click="changeStatus(task, 'inspected')">Inspekto</Button>
                            <button v-if="['in_progress', 'completed'].includes(task.status)" class="ml-auto text-tiny text-error-600 hover:underline" @click="openIssue(task)">Problem</button>
                        </div>

                        <!-- Assign (only when unassigned + actionable) -->
                        <div v-if="canUpdate && !task.assigned_to && ['pending', 'in_progress'].includes(task.status) && housekeeperOptions.length" class="mt-2">
                            <Select :model-value="''" :options="housekeeperOptions" placeholder="Cakto pastrues..." @update:model-value="(val) => assignTask(task, val)" />
                        </div>
                    </div>

                    <p v-if="!tasksByStatus[col.status].length" class="text-tiny text-neutral-400 text-center py-6">—</p>
                </div>
            </div>
        </div>

        <!-- LIST view -->
        <div v-else class="mt-6">
            <Card :padding="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200">
                        <thead class="bg-neutral-50">
                            <tr>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Dhoma</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Tipi</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Pastrues</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Statusi</th>
                                <th class="px-5 py-3 text-right text-label text-neutral-600">Veprim</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="task in tasks.data" :key="task.id" class="hover:bg-neutral-50">
                                <td class="px-5 py-3 text-body-sm text-primary-900 font-medium">{{ task.room?.room_number }}</td>
                                <td class="px-5 py-3"><Badge :variant="typeBadge[task.type]?.variant" size="sm">{{ typeBadge[task.type]?.label }}</Badge></td>
                                <td class="px-5 py-3 text-body-sm" :class="task.assigned_user ? 'text-neutral-600' : 'text-error-500'">{{ task.assigned_user?.name || 'Pa caktuar' }}</td>
                                <td class="px-5 py-3"><Badge :variant="statusBadge[task.status]?.variant" dot>{{ statusBadge[task.status]?.label }}</Badge></td>
                                <td class="px-5 py-3 text-right">
                                    <Button v-if="canUpdate && task.status === 'pending'" size="sm" variant="primary" @click="changeStatus(task, 'in_progress')">Fillo</Button>
                                    <Button v-else-if="canUpdate && task.status === 'in_progress'" size="sm" variant="primary" @click="changeStatus(task, 'completed')">Perfundo</Button>
                                    <Button v-else-if="canUpdate && task.status === 'completed'" size="sm" variant="outline" @click="changeStatus(task, 'inspected')">Inspekto</Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="!tasks.data?.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">Nuk ka detyra pastrimi.</div>
            </Card>
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
