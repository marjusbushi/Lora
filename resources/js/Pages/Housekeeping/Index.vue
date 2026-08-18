<script setup>
import { getIntlLocale, translate } from '@/i18n';
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
    pending: { variant: 'warning', label: translate('admin.generated.k_857313d527c8') },
    in_progress: { variant: 'info', label: translate('admin.generated.k_7d59dbbd1be8') },
    completed: { variant: 'success', label: translate('admin.generated.k_7b23152f24ce') },
    inspected: { variant: 'accent', label: translate('admin.generated.k_88d376ae46c5') },
};
const typeBadge = {
    checkout_clean: { variant: 'error', label: translate('admin.generated.k_6151dce7f49f') },
    stayover_clean: { variant: 'info', label: translate('admin.generated.k_6a7209d6cf00') },
    deep_clean: { variant: 'dark', label: translate('admin.generated.k_6dafa95221f3') },
    inspection: { variant: 'neutral', label: translate('admin.generated.k_218c17888641') },
};

// Arrival urgency (Renato 2026-08-18): red = the guest lands TODAY (or is
// overdue), yellow = tomorrow. Colour is never alone — a textual badge with
// the date always accompanies it. Dates compare as local Y-m-d strings, the
// staff's wall-clock day.
function localDate(offsetDays = 0) {
    const d = new Date();
    d.setDate(d.getDate() + offsetDays);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
const todayStr = localDate(0);
const tomorrowStr = localDate(1);
function nextCheckInDate(task) {
    return (task.next_check_in || '').slice(0, 10);
}
function urgency(task) {
    const ci = nextCheckInDate(task);
    if (!ci) return null;
    if (ci <= todayStr) return 'today';
    if (ci === tomorrowStr) return 'tomorrow';
    return null;
}
function urgencyLabel(task) {
    const ci = nextCheckInDate(task);
    if (ci < todayStr) return translate('admin.housekeeping.checkInOverdue');
    if (ci === todayStr) return translate('admin.housekeeping.checkInToday');
    return translate('admin.housekeeping.checkInTomorrow');
}
function fmtDay(value) {
    if (!value) return '';
    const date = new Date(String(value).slice(0, 10) + 'T00:00:00');
    return date.toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short' });
}
const urgencyCardClass = {
    today: 'border-error-300 bg-error-50',
    tomorrow: 'border-warning-300 bg-warning-50',
};
const urgencyBadgeClass = {
    today: 'bg-error-100 text-error-700',
    tomorrow: 'bg-warning-100 text-warning-800',
};
const urgencyRowClass = {
    today: 'bg-error-50/60 hover:bg-error-50',
    tomorrow: 'bg-warning-50/60 hover:bg-warning-50',
};

// Kanban columns (calm — only a small dot is coloured)
const columns = [
    { status: 'pending', label: translate('admin.generated.k_857313d527c8'), dot: 'bg-warning-500' },
    { status: 'in_progress', label: translate('admin.generated.k_7d59dbbd1be8'), dot: 'bg-info-500' },
    { status: 'completed', label: translate('admin.generated.k_7b23152f24ce'), dot: 'bg-success-500' },
    { status: 'inspected', label: translate('admin.generated.k_88d376ae46c5'), dot: 'bg-accent-500' },
];
const tasksByStatus = computed(() => {
    const g = { pending: [], in_progress: [], completed: [], inspected: [] };
    for (const t of props.tasks.data || []) (g[t.status] ??= []).push(t);
    return g;
});

const floorOptions = [1, 2, 3, 4, 5].map((f) => ({ value: f, label: `Kati ${f}` }));
const statusFilterOptions = [
    { value: 'pending', label: translate('admin.generated.k_857313d527c8') },
    { value: 'in_progress', label: translate('admin.generated.k_7d59dbbd1be8') },
    { value: 'completed', label: translate('admin.generated.k_7b23152f24ce') },
    { value: 'inspected', label: translate('admin.generated.k_88d376ae46c5') },
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
// Fillo → start the task (snapshots the checklist server-side), then open the
// full-screen cleaning view with its timer + checklist.
function startCleaning(task) {
    router.patch(route('housekeeping.status', task.id), { status: 'in_progress' }, {
        preserveScroll: true,
        onSuccess: () => router.visit(route('housekeeping.clean', task.id)),
    });
}
// Vazhdo → reopen the cleaning view for a task already in progress (no re-start).
function openClean(task) {
    router.visit(route('housekeeping.clean', task.id));
}
function progressOf(task) {
    const list = task.checklist || [];
    return { done: list.filter((i) => i.done).length, total: list.length };
}
function fmtDateTime(v) {
    if (!v) return '';
    const d = new Date(v);
    if (Number.isNaN(d.getTime())) return '';
    const pad = (n) => String(n).padStart(2, '0');
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
function assignTask(task, userId) {
    router.patch(route('housekeeping.assign', task.id), { assigned_to: userId }, {
        preserveScroll: true,
        onSuccess: () => toasts.value?.success(translate('admin.generated.k_af1b6e2ae46d')),
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
        onSuccess: () => { showIssueModal.value = false; toasts.value?.warning(translate('admin.generated.k_21acc44af2cf')); },
    });
}
const housekeeperOptions = props.housekeepers.map((h) => ({ value: h.id, label: h.name }));
</script>

<template>
    <AppLayout>
        <PageHeader
            :title="$t('admin.generated.k_4b2797b63e74')"
            :breadcrumbs="[{ label: $t('admin.generated.k_8ebed6e60a4d'), href: '/dashboard' }, { label: $t('admin.generated.k_03455a47b3cf') }]"
        >
            <template #actions>
                <div class="inline-flex rounded-lg border border-neutral-200 bg-white p-0.5">
                    <button
                        :class="['px-3 py-1.5 rounded-md text-body-sm font-medium transition-colors', viewMode === 'kanban' ? 'bg-primary-900 text-white' : 'text-neutral-500 hover:text-neutral-800']"
                        @click="viewMode = 'kanban'"
                    >{{ $t('admin.generated.k_aa74142c562b') }}</button>
                    <button
                        :class="['px-3 py-1.5 rounded-md text-body-sm font-medium transition-colors', viewMode === 'table' ? 'bg-primary-900 text-white' : 'text-neutral-500 hover:text-neutral-800']"
                        @click="viewMode = 'table'"
                    >{{ $t('admin.generated.k_454b49b04adf') }}</button>
                </div>
            </template>
        </PageHeader>

        <!-- Stats (calm) -->
        <div class="mt-6 grid grid-cols-2 sm:grid-cols-4 gap-3">
            <Card>
                <div class="flex items-center gap-2.5">
                    <span class="h-2 w-2 rounded-full bg-warning-500 shrink-0"></span>
                    <div><p class="text-h3 text-primary-900 leading-none">{{ stats.pending }}</p><p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">{{ $t('admin.generated.k_a885340278ad') }}</p></div>
                </div>
            </Card>
            <Card>
                <div class="flex items-center gap-2.5">
                    <span class="h-2 w-2 rounded-full bg-info-500 shrink-0"></span>
                    <div><p class="text-h3 text-primary-900 leading-none">{{ stats.in_progress }}</p><p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">{{ $t('admin.generated.k_1ffc8d893dc9') }}</p></div>
                </div>
            </Card>
            <Card>
                <div class="flex items-center gap-2.5">
                    <span class="h-2 w-2 rounded-full bg-success-500 shrink-0"></span>
                    <div><p class="text-h3 text-primary-900 leading-none">{{ stats.completed }}</p><p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">{{ $t('admin.generated.k_4fa6b5946180') }}</p></div>
                </div>
            </Card>
            <Card>
                <div class="flex items-center gap-2.5">
                    <span class="h-2 w-2 rounded-full bg-error-500 shrink-0"></span>
                    <div><p class="text-h3 text-primary-900 leading-none">{{ stats.urgent }}</p><p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">{{ $t('admin.generated.k_1fe1f61389cd') }}</p></div>
                </div>
            </Card>
        </div>

        <!-- Filters -->
        <div class="mt-6 flex flex-wrap items-end gap-3">
            <div class="w-40">
                <Select v-model="filterStatus" :options="statusFilterOptions" :placeholder="$t('admin.generated.k_92cc6ddac2b0')" @change="applyFilters" />
            </div>
            <div class="w-36">
                <Select v-model="filterFloor" :options="floorOptions" :placeholder="$t('admin.generated.k_a24c33e1893d')" @change="applyFilters" />
            </div>
            <Button v-if="filterStatus || filterFloor" variant="ghost" size="sm" @click="clearFilters">{{ $t('admin.generated.k_65d4a063eb13') }}</Button>
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
                        class="rounded-lg border shadow-card p-3"
                        :class="urgencyCardClass[urgency(task)] || 'border-neutral-200 bg-white'"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-h4 text-primary-900 leading-none">{{ $t('admin.generated.k_33533ce43e5f') }} {{ task.room?.room_number }}</p>
                            <span v-if="task.priority === 'urgent'" class="text-tiny font-medium text-error-600 shrink-0">{{ $t('admin.generated.k_8d9b6a0a288e') }}</span>
                        </div>
                        <p v-if="urgency(task)" class="mt-1.5">
                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-tiny font-bold" :class="urgencyBadgeClass[urgency(task)]">
                                {{ urgencyLabel(task) }} · {{ fmtDay(task.next_check_in) }}
                            </span>
                        </p>
                        <p class="text-small text-neutral-500 mt-1.5">{{ task.room?.room_type?.name }} {{ $t('admin.generated.k_c0164cc28c7b') }} {{ task.room?.floor }}</p>
                        <div class="flex items-center gap-1.5 mt-1">
                            <Badge :variant="typeBadge[task.type]?.variant" size="sm">{{ typeBadge[task.type]?.label }}</Badge>
                            <span class="text-tiny" :class="task.assigned_user ? 'text-neutral-500' : 'text-error-500'">
                                {{ task.assigned_user ? task.assigned_user.name : $t('admin.generated.k_ab5d50806d6c') }}
                            </span>
                        </div>
                        <p v-if="task.issue_reported" class="text-tiny text-error-600 mt-1.5">⚠ {{ task.issue_reported }}</p>
                        <p v-if="task.status === 'inspected' && task.inspected_by" class="text-tiny text-neutral-500 mt-1.5">
{{ $t('admin.generated.k_c9cf3f167672') }} {{ task.inspected_by.name }}<span v-if="task.inspected_at"> · {{ fmtDateTime(task.inspected_at) }}</span>
                        </p>

                        <!-- Actions (always visible) -->
                        <div v-if="canUpdate" class="mt-2.5 pt-2.5 border-t border-neutral-100 flex items-center gap-1.5">
                            <Button v-if="task.status === 'pending'" size="sm" variant="primary" @click="startCleaning(task)">{{ $t('admin.generated.k_159a76d13f1a') }}</Button>
                            <template v-else-if="task.status === 'in_progress'">
                                <Button size="sm" variant="primary" @click="openClean(task)">{{ $t('admin.generated.k_1f991e4176c0') }}</Button>
                                <span class="text-tiny text-neutral-500">{{ progressOf(task).done }}/{{ progressOf(task).total }}</span>
                            </template>
                            <Button v-else-if="task.status === 'completed'" size="sm" variant="outline" @click="changeStatus(task, 'inspected')">{{ $t('admin.generated.k_170790589c04') }}</Button>
                            <button v-if="['in_progress', 'completed'].includes(task.status)" class="ml-auto text-tiny text-error-600 hover:underline" @click="openIssue(task)">{{ $t('admin.generated.k_2518f1d4b411') }}</button>
                        </div>

                        <!-- Assign (only when unassigned + actionable) -->
                        <div v-if="canUpdate && !task.assigned_to && ['pending', 'in_progress'].includes(task.status) && housekeeperOptions.length" class="mt-2">
                            <Select :model-value="''" :options="housekeeperOptions" :placeholder="$t('admin.generated.k_5cb47b275190')" @update:model-value="(val) => assignTask(task, val)" />
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
                                <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_33533ce43e5f') }}</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_4af0e1144534') }}</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_6c78ebe8553c') }}</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_e406bfe77192') }}</th>
                                <th class="px-5 py-3 text-right text-label text-neutral-600">{{ $t('admin.generated.k_1aee87b61afc') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="task in tasks.data" :key="task.id" :class="urgencyRowClass[urgency(task)] || 'hover:bg-neutral-50'">
                                <td class="px-5 py-3 text-body-sm text-primary-900 font-medium">
                                    {{ task.room?.room_number }}
                                    <span v-if="urgency(task)" class="ml-2 inline-flex items-center rounded px-1.5 py-0.5 text-tiny font-bold" :class="urgencyBadgeClass[urgency(task)]">
                                        {{ urgencyLabel(task) }} · {{ fmtDay(task.next_check_in) }}
                                    </span>
                                </td>
                                <td class="px-5 py-3"><Badge :variant="typeBadge[task.type]?.variant" size="sm">{{ typeBadge[task.type]?.label }}</Badge></td>
                                <td class="px-5 py-3 text-body-sm" :class="task.assigned_user ? 'text-neutral-600' : 'text-error-500'">{{ task.assigned_user?.name || $t('admin.generated.k_ab5d50806d6c') }}</td>
                                <td class="px-5 py-3"><Badge :variant="statusBadge[task.status]?.variant" dot>{{ statusBadge[task.status]?.label }}</Badge></td>
                                <td class="px-5 py-3 text-right">
                                    <Button v-if="canUpdate && task.status === 'pending'" size="sm" variant="primary" @click="startCleaning(task)">{{ $t('admin.generated.k_159a76d13f1a') }}</Button>
                                    <Button v-else-if="canUpdate && task.status === 'in_progress'" size="sm" variant="primary" @click="openClean(task)">{{ $t('admin.generated.k_1f991e4176c0') }}</Button>
                                    <Button v-else-if="canUpdate && task.status === 'completed'" size="sm" variant="outline" @click="changeStatus(task, 'inspected')">{{ $t('admin.generated.k_170790589c04') }}</Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="!tasks.data?.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">{{ $t('admin.generated.k_54e3aabbcdc9') }}</div>
            </Card>
        </div>

        <!-- Issue Report Modal -->
        <Modal :show="showIssueModal" :title="$t('admin.generated.k_0e387c7ee768')" max-width="md" @close="showIssueModal = false">
            <div class="space-y-4">
                <FormGroup :label="$t('admin.generated.k_117804117180')" required>
                    <Textarea v-model="issueText" :placeholder="$t('admin.generated.k_c73819c64713')" :rows="3" />
                </FormGroup>
                <label class="flex items-center gap-2 text-body-sm text-neutral-700">
                    <input type="checkbox" v-model="setMaintenance" class="h-4 w-4 rounded border-neutral-300 text-accent-600" />
{{ $t('admin.generated.k_a476d1a8a4c5') }} </label>
            </div>
            <template #footer>
                <Button variant="outline" @click="showIssueModal = false">{{ $t('admin.generated.k_410184619bcb') }}</Button>
                <Button variant="danger" :disabled="!issueText" @click="submitIssue">{{ $t('admin.generated.k_2645f2f310dd') }}</Button>
            </template>
        </Modal>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
