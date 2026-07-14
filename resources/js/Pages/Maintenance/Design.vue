<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import {
    AlertTriangle, CalendarClock, Check, ChevronRight, CircleAlert,
    Clock3, Hammer, Image, MapPin, Plus, Search,
    SlidersHorizontal, UserRound, Wrench, X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const { t } = useI18n();
const designVariant = ref('list');
const activeStatus = ref('all');
const query = ref('');
const selectedId = ref(1042);
const reportOpen = ref(false);
const drawerOpen = ref(false);

const issues = [
    {
        id: 1042, room: '204', floor: '2', titleKey: 'tvTitle', categoryKey: 'electronics',
        descriptionKey: 'tvDescription', priority: 'high', status: 'new', source: 'housekeeping',
        reporter: 'Elira Hoxha', reportedAt: '10:24', elapsedKey: 'minutesAgo', assigneeKey: 'unassigned',
        asset: 'Samsung Smart TV 43”', assetCode: 'TV-204-01', sla: '1h 36m', guestImpact: true,
    },
    {
        id: 1041, room: '305', floor: '3', titleKey: 'acTitle', categoryKey: 'climate',
        descriptionKey: 'acDescription', priority: 'critical', status: 'progress', source: 'reception',
        reporter: 'Sara Meta', reportedAt: '09:42', elapsedKey: 'hourAgo', assignee: 'Ardit Kola',
        asset: 'Daikin Sensira 12K', assetCode: 'AC-305-01', sla: '42m', guestImpact: true,
    },
    {
        id: 1039, room: 'K1', locationKey: 'floorOneCorridor', floor: '1', titleKey: 'lightTitle', categoryKey: 'electrical',
        descriptionKey: 'lightDescription', priority: 'medium', status: 'assigned', source: 'housekeeping',
        reporter: 'Mirela Dosti', reportedAt: '08:55', elapsedKey: 'twoHoursAgo', assignee: 'Ardit Kola',
        assetKey: 'corridorLighting', assetCode: 'EL-F1-08', sla: '5h 10m', guestImpact: false,
    },
    {
        id: 1037, room: '118', floor: '1', titleKey: 'safeTitle', categoryKey: 'furniture',
        descriptionKey: 'safeDescription', priority: 'low', status: 'resolved', source: 'reception',
        reporter: 'Ina Basha', reportedAtKey: 'yesterdayTime', elapsedKey: 'yesterday', assignee: 'Besnik Leka',
        asset: 'Kasafortë Yale', assetCode: 'SF-118-01', sla: '—', guestImpact: false,
    },
];

const preventive = [
    { id: 1, titleKey: 'boilerCheck', locationKey: 'technicalRoom', dueKey: 'dueToday', tone: 'red', progress: 100 },
    { id: 2, titleKey: 'acFilters', locationKey: 'floorTwoDevices', dueKey: 'dueThreeDays', tone: 'amber', progress: 72 },
    { id: 3, titleKey: 'extinguishers', locationKey: 'allFloors', dueKey: 'dueTwelveDays', tone: 'green', progress: 38 },
];

const statusTabs = [
    { value: 'all', labelKey: 'all', count: 4 },
    { value: 'new', labelKey: 'new', count: 1 },
    { value: 'assigned', labelKey: 'assigned', count: 1 },
    { value: 'progress', labelKey: 'inProgress', count: 1 },
    { value: 'resolved', labelKey: 'resolved', count: 1 },
];

const filteredIssues = computed(() => issues.filter((issue) => {
    const statusMatches = activeStatus.value === 'all' || issue.status === activeStatus.value;
    const search = query.value.trim().toLowerCase();
    const searchMatches = !search || `${issue.room} ${t(`maintenance.mock.${issue.titleKey}`)} ${issue.reporter}`.toLowerCase().includes(search);
    return statusMatches && searchMatches;
}));
const selected = computed(() => issues.find((issue) => issue.id === selectedId.value) || issues[0]);
const kanbanColumns = computed(() => statusTabs.slice(1).map((status) => ({
    ...status,
    issues: issues.filter((issue) => issue.status === status.value),
})));

const priorityClass = (priority) => ({
    critical: 'bg-red-50 text-red-700 ring-red-600/20', high: 'bg-orange-50 text-orange-700 ring-orange-600/20',
    medium: 'bg-amber-50 text-amber-700 ring-amber-600/20', low: 'bg-neutral-100 text-neutral-600 ring-neutral-500/20',
}[priority]);
const statusClass = (status) => ({
    new: 'bg-red-500', assigned: 'bg-amber-500', progress: 'bg-blue-500', resolved: 'bg-emerald-500',
}[status]);
const issueLocation = (issue) => issue.locationKey ? t(`maintenance.mock.${issue.locationKey}`) : `${t('maintenance.room')} ${issue.room}`;
const issueAssignee = (issue) => issue.assigneeKey ? t(`maintenance.${issue.assigneeKey}`) : issue.assignee;
const issueReportedAt = (issue) => issue.reportedAtKey ? t(`maintenance.mock.${issue.reportedAtKey}`) : issue.reportedAt;
const issueAsset = (issue) => issue.assetKey ? t(`maintenance.mock.${issue.assetKey}`) : issue.asset;
function openIssue(issue) {
    selectedId.value = issue.id;
    drawerOpen.value = true;
}
</script>

<template>
    <Head :title="t('maintenance.title')" />
    <AppLayout>
        <div class="mx-auto max-w-[1600px]">
            <div class="rounded-2xl border border-neutral-200 bg-white px-5 py-5 shadow-sm sm:px-7">
                <div class="flex flex-col gap-5 xl:flex-row xl:items-center xl:justify-between">
                    <div class="flex items-start gap-4">
                        <div class="hidden h-12 w-12 items-center justify-center rounded-xl bg-emerald-950 text-white sm:flex"><Wrench class="h-6 w-6" /></div>
                        <div><div class="flex items-center gap-2"><h1 class="text-2xl font-bold tracking-tight text-neutral-950">{{ t('maintenance.title') }}</h1><span class="rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-emerald-700">{{ t('maintenance.demoShort') }}</span></div><p class="mt-1 text-sm text-neutral-500">{{ t('maintenance.subtitle') }}</p></div>
                    </div>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <div class="grid grid-cols-2 rounded-xl border border-neutral-200 bg-neutral-50 p-1">
                            <button class="rounded-lg px-4 py-2 text-left transition" :class="designVariant === 'list' ? 'bg-white shadow-sm ring-1 ring-neutral-200' : 'text-neutral-500'" @click="designVariant = 'list'"><span class="block text-xs font-bold" :class="designVariant === 'list' ? 'text-emerald-900' : ''">{{ t('maintenance.variantOne') }}</span><span class="mt-0.5 block text-[11px]">{{ t('maintenance.operationalList') }}</span></button>
                            <button class="rounded-lg px-4 py-2 text-left transition" :class="designVariant === 'kanban' ? 'bg-white shadow-sm ring-1 ring-neutral-200' : 'text-neutral-500'" @click="designVariant = 'kanban'"><span class="block text-xs font-bold" :class="designVariant === 'kanban' ? 'text-emerald-900' : ''">{{ t('maintenance.variantTwo') }}</span><span class="mt-0.5 block text-[11px]">{{ t('maintenance.kanbanBoard') }}</span></button>
                        </div>
                        <button class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-emerald-900 px-4 text-sm font-semibold text-white hover:bg-emerald-950" @click="reportOpen = true"><Plus class="h-4 w-4" />{{ t('maintenance.reportIssue') }}</button>
                    </div>
                </div>
                <div class="mt-5 flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-neutral-100 pt-4 text-sm"><span class="font-semibold text-neutral-900">3 {{ t('maintenance.openIssues').toLowerCase() }}</span><span class="h-1 w-1 rounded-full bg-neutral-300"></span><span class="inline-flex items-center gap-1.5 font-semibold text-red-700"><CircleAlert class="h-4 w-4" />1 {{ t('maintenance.urgent').toLowerCase() }}</span><span class="h-1 w-1 rounded-full bg-neutral-300"></span><span class="text-neutral-500">1 {{ t('maintenance.blockedRooms').toLowerCase() }}</span><span class="h-1 w-1 rounded-full bg-neutral-300"></span><span class="text-neutral-500">3 {{ t('maintenance.preventiveDue').toLowerCase() }}</span></div>
            </div>

            <div class="mt-5 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="overflow-x-auto"><div class="flex min-w-max gap-2"><button v-for="tab in statusTabs" :key="tab.value" class="rounded-full border px-3.5 py-2 text-xs font-semibold transition" :class="activeStatus === tab.value ? 'border-emerald-900 bg-emerald-900 text-white' : 'border-neutral-200 bg-white text-neutral-600 hover:border-neutral-300'" @click="activeStatus = tab.value">{{ t(`maintenance.${tab.labelKey}`) }} <span class="ml-1 opacity-70">{{ tab.count }}</span></button></div></div>
                <div class="flex gap-2"><label class="relative min-w-0 flex-1 sm:w-72"><Search class="pointer-events-none absolute left-3 top-3 h-4 w-4 text-neutral-400" /><input v-model="query" class="h-10 w-full rounded-xl border-neutral-200 bg-white pl-9 text-sm focus:border-emerald-800 focus:ring-emerald-800" :placeholder="t('maintenance.search')" /></label><button class="inline-flex h-10 items-center gap-2 rounded-xl border border-neutral-200 bg-white px-3 text-sm font-medium text-neutral-600"><SlidersHorizontal class="h-4 w-4" />{{ t('maintenance.filters') }}</button></div>
            </div>

            <!-- VARIANT 1: dense operational table -->
            <section v-if="designVariant === 'list'" class="mt-4 overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4"><div><h2 class="font-bold text-neutral-950">{{ t('maintenance.operationalList') }}</h2><p class="mt-0.5 text-xs text-neutral-500">{{ t('maintenance.listDescription') }}</p></div><span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">{{ t('maintenance.recommended') }}</span></div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[980px] text-left">
                        <thead class="bg-neutral-50 text-[11px] font-bold uppercase tracking-wider text-neutral-400"><tr><th class="px-5 py-3">{{ t('maintenance.problem') }}</th><th class="px-4 py-3">{{ t('maintenance.roomLocation') }}</th><th class="px-4 py-3">{{ t('maintenance.priority') }}</th><th class="px-4 py-3">{{ t('maintenance.status') }}</th><th class="px-4 py-3">{{ t('maintenance.assignee') }}</th><th class="px-4 py-3">SLA</th><th class="w-12 px-4 py-3"></th></tr></thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="issue in filteredIssues" :key="issue.id" class="cursor-pointer transition hover:bg-emerald-50/40" @click="openIssue(issue)">
                                <td class="px-5 py-4"><div class="flex items-center gap-3"><span class="h-9 w-1 rounded-full" :class="issue.priority === 'critical' ? 'bg-red-500' : issue.priority === 'high' ? 'bg-orange-400' : issue.priority === 'medium' ? 'bg-amber-400' : 'bg-neutral-300'"></span><div><div class="flex items-center gap-2"><span class="font-semibold text-neutral-950">{{ t(`maintenance.mock.${issue.titleKey}`) }}</span><span v-if="issue.source === 'housekeeping'" class="rounded bg-violet-50 px-1.5 py-0.5 text-[10px] font-semibold text-violet-700">HK</span></div><p class="mt-1 text-xs text-neutral-400">#MNT-{{ issue.id }} · {{ issue.reporter }} · {{ issueReportedAt(issue) }}</p></div></div></td>
                                <td class="px-4 py-4 text-sm font-medium text-neutral-700">{{ issueLocation(issue) }}<span class="mt-0.5 block text-xs font-normal text-neutral-400">{{ t(`maintenance.mock.${issue.categoryKey}`) }}</span></td>
                                <td class="px-4 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-bold ring-1 ring-inset" :class="priorityClass(issue.priority)">{{ t(`maintenance.${issue.priority}`) }}</span></td>
                                <td class="px-4 py-4"><span class="inline-flex items-center gap-2 text-sm font-medium text-neutral-700"><span class="h-2 w-2 rounded-full" :class="statusClass(issue.status)"></span>{{ t(`maintenance.${issue.status === 'progress' ? 'inProgress' : issue.status}`) }}</span></td>
                                <td class="px-4 py-4 text-sm text-neutral-600">{{ issueAssignee(issue) }}</td><td class="px-4 py-4 text-sm font-semibold" :class="issue.priority === 'critical' ? 'text-red-700' : 'text-neutral-600'">{{ issue.sla }}</td><td class="px-4 py-4"><ChevronRight class="h-4 w-4 text-neutral-300" /></td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="filteredIssues.length === 0" class="p-12 text-center text-sm text-neutral-500">{{ t('maintenance.noResults') }}</div>
                </div>
                <div class="grid border-t border-neutral-200 bg-stone-50/70 md:grid-cols-3"><div v-for="task in preventive" :key="task.id" class="flex items-center gap-3 border-b border-neutral-200 px-5 py-3 last:border-b-0 md:border-b-0 md:border-r md:last:border-r-0"><CalendarClock class="h-4 w-4 shrink-0 text-emerald-700" /><div class="min-w-0 flex-1"><p class="truncate text-xs font-semibold text-neutral-800">{{ t(`maintenance.mock.${task.titleKey}`) }}</p><p class="text-[11px] text-neutral-400">{{ t(`maintenance.mock.${task.locationKey}`) }}</p></div><span class="text-[11px] font-semibold" :class="task.tone === 'red' ? 'text-red-600' : 'text-amber-600'">{{ t(`maintenance.mock.${task.dueKey}`) }}</span></div></div>
            </section>

            <!-- VARIANT 2: workflow kanban -->
            <section v-else class="mt-4">
                <div class="mb-4 flex items-center justify-between"><div><h2 class="font-bold text-neutral-950">{{ t('maintenance.kanbanBoard') }}</h2><p class="mt-0.5 text-xs text-neutral-500">{{ t('maintenance.kanbanDescription') }}</p></div><span class="text-xs font-medium text-neutral-400">{{ issues.length }} {{ t('maintenance.issues').toLowerCase() }}</span></div>
                <div class="grid gap-4 lg:grid-cols-2 2xl:grid-cols-4">
                    <div v-for="column in kanbanColumns" :key="column.value" class="min-h-[520px] rounded-2xl border border-neutral-200 bg-neutral-100/70 p-3">
                        <div class="mb-3 flex items-center px-1"><span class="h-2.5 w-2.5 rounded-full" :class="statusClass(column.value)"></span><h3 class="ml-2 text-sm font-bold text-neutral-800">{{ t(`maintenance.${column.labelKey}`) }}</h3><span class="ml-auto rounded-full bg-white px-2 py-0.5 text-xs font-semibold text-neutral-500">{{ column.issues.length }}</span></div>
                        <div class="space-y-3">
                            <button v-for="issue in column.issues" :key="issue.id" class="w-full rounded-xl border border-neutral-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md" @click="openIssue(issue)">
                                <div class="flex items-start justify-between gap-2"><span class="text-[11px] font-bold text-neutral-400">#MNT-{{ issue.id }}</span><span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset" :class="priorityClass(issue.priority)">{{ t(`maintenance.${issue.priority}`) }}</span></div>
                                <h4 class="mt-3 text-sm font-bold leading-5 text-neutral-950">{{ t(`maintenance.mock.${issue.titleKey}`) }}</h4><p class="mt-1.5 flex items-center gap-1 text-xs text-neutral-500"><MapPin class="h-3.5 w-3.5" />{{ issueLocation(issue) }}</p>
                                <div v-if="issue.guestImpact" class="mt-3 flex items-center gap-1.5 rounded-lg bg-red-50 px-2.5 py-2 text-[11px] font-semibold text-red-700"><AlertTriangle class="h-3.5 w-3.5" />{{ t('maintenance.guestImpact') }}</div>
                                <div class="mt-4 flex items-center justify-between border-t border-neutral-100 pt-3"><span class="flex items-center gap-1.5 text-xs text-neutral-500"><UserRound class="h-3.5 w-3.5" />{{ issueAssignee(issue) }}</span><span class="text-[11px] text-neutral-400">{{ t(`maintenance.mock.${issue.elapsedKey}`) }}</span></div>
                            </button>
                            <div v-if="column.issues.length === 0" class="rounded-xl border border-dashed border-neutral-300 p-8 text-center text-xs text-neutral-400">{{ t('maintenance.emptyColumn') }}</div>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- Shared reservation-style detail drawer for both variants -->
        <Teleport to="body">
            <div v-if="drawerOpen" class="fixed inset-0 z-50 bg-neutral-950/30" @click.self="drawerOpen = false">
                <aside class="absolute inset-y-0 right-0 w-full max-w-[520px] overflow-y-auto bg-white shadow-2xl">
                    <div class="sticky top-0 z-10 flex items-center justify-between border-b border-neutral-200 bg-white/95 px-5 py-4 backdrop-blur"><div class="flex items-center gap-2"><span class="text-xs font-bold text-neutral-400">#MNT-{{ selected.id }}</span><span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset" :class="priorityClass(selected.priority)">{{ t(`maintenance.${selected.priority}`) }}</span></div><button class="rounded-lg p-2 text-neutral-400 hover:bg-neutral-100" @click="drawerOpen = false"><X class="h-5 w-5" /></button></div>
                    <div class="p-5 sm:p-6"><h2 class="text-2xl font-bold text-neutral-950">{{ t(`maintenance.mock.${selected.titleKey}`) }}</h2><p class="mt-1 text-sm text-neutral-500">{{ issueLocation(selected) }} · {{ t(`maintenance.mock.${selected.categoryKey}`) }}</p>
                        <div v-if="selected.guestImpact" class="mt-5 flex gap-3 rounded-xl border border-red-200 bg-red-50 p-3"><AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-red-600" /><div><p class="text-sm font-semibold text-red-900">{{ t('maintenance.guestImpact') }}</p><p class="mt-0.5 text-xs text-red-700">{{ t('maintenance.guestImpactHint') }}</p></div></div>
                        <div class="mt-5 grid grid-cols-2 gap-3"><div class="rounded-xl border border-neutral-200 p-3"><span class="text-[10px] font-bold uppercase tracking-wider text-neutral-400">{{ t('maintenance.assignee') }}</span><p class="mt-1.5 flex items-center gap-1.5 text-sm font-semibold"><UserRound class="h-4 w-4 text-neutral-400" />{{ issueAssignee(selected) }}</p></div><div class="rounded-xl border border-neutral-200 p-3"><span class="text-[10px] font-bold uppercase tracking-wider text-neutral-400">SLA</span><p class="mt-1.5 flex items-center gap-1.5 text-sm font-semibold"><Clock3 class="h-4 w-4 text-neutral-400" />{{ selected.sla }}</p></div></div>
                        <div class="mt-6"><h3 class="text-xs font-bold uppercase tracking-wider text-neutral-400">{{ t('maintenance.report') }}</h3><p class="mt-2 text-sm leading-6 text-neutral-700">{{ t(`maintenance.mock.${selected.descriptionKey}`) }}</p><div class="mt-3 flex h-24 items-center justify-center rounded-xl border border-dashed border-neutral-300 bg-neutral-50 text-xs text-neutral-400"><Image class="mr-2 h-4 w-4" />{{ t('maintenance.photoFromReport') }}</div></div>
                        <div class="mt-6 rounded-xl border border-neutral-200 p-4"><span class="text-[10px] font-bold uppercase tracking-wider text-neutral-400">{{ t('maintenance.asset') }}</span><p class="mt-1 text-sm font-semibold text-neutral-900">{{ issueAsset(selected) }}</p><p class="text-xs text-neutral-500">{{ selected.assetCode }}</p></div>
                        <div class="mt-6"><h3 class="text-xs font-bold uppercase tracking-wider text-neutral-400">{{ t('maintenance.timeline') }}</h3><div class="mt-3 border-l-2 border-emerald-100 pl-4"><p class="text-sm font-semibold text-neutral-800">{{ t('maintenance.issueReported') }}</p><p class="mt-0.5 text-xs text-neutral-500">{{ selected.reporter }} · {{ issueReportedAt(selected) }}</p></div></div>
                        <div class="mt-8 grid grid-cols-2 gap-2"><button class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-neutral-300 text-sm font-semibold text-neutral-700"><UserRound class="h-4 w-4" />{{ t('maintenance.assign') }}</button><button class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-emerald-900 text-sm font-semibold text-white"><Hammer class="h-4 w-4" />{{ t('maintenance.startWork') }}</button></div>
                    </div>
                </aside>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="reportOpen" class="fixed inset-0 z-50 flex items-end justify-center bg-neutral-950/50 p-0 sm:items-center sm:p-4" @click.self="reportOpen = false">
                <div class="w-full max-w-lg rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl">
                    <div class="flex items-center justify-between border-b border-neutral-200 p-5"><div><h2 class="text-lg font-bold text-neutral-950">{{ t('maintenance.reportIssue') }}</h2><p class="mt-1 text-sm text-neutral-500">{{ t('maintenance.reportHint') }}</p></div><button class="rounded-lg p-2 text-neutral-400 hover:bg-neutral-100" @click="reportOpen = false"><X class="h-5 w-5" /></button></div>
                    <div class="space-y-4 p-5"><div class="grid grid-cols-2 gap-3"><label class="text-sm font-semibold text-neutral-700">{{ t('maintenance.roomLocation') }}<select class="mt-1.5 w-full rounded-lg border-neutral-200 text-sm focus:border-emerald-700 focus:ring-emerald-700"><option>{{ t('maintenance.room') }} 204</option><option>{{ t('maintenance.room') }} 305</option><option>{{ t('maintenance.mock.floorOneCorridor') }}</option></select></label><label class="text-sm font-semibold text-neutral-700">{{ t('maintenance.priority') }}<select class="mt-1.5 w-full rounded-lg border-neutral-200 text-sm focus:border-emerald-700 focus:ring-emerald-700"><option>{{ t('maintenance.medium') }}</option><option>{{ t('maintenance.high') }}</option><option>{{ t('maintenance.critical') }}</option></select></label></div><label class="block text-sm font-semibold text-neutral-700">{{ t('maintenance.problem') }}<input class="mt-1.5 w-full rounded-lg border-neutral-200 text-sm focus:border-emerald-700 focus:ring-emerald-700" :placeholder="t('maintenance.problemPlaceholder')" /></label><label class="block text-sm font-semibold text-neutral-700">{{ t('maintenance.description') }}<textarea rows="3" class="mt-1.5 w-full rounded-lg border-neutral-200 text-sm focus:border-emerald-700 focus:ring-emerald-700" :placeholder="t('maintenance.descriptionPlaceholder')"></textarea></label><button class="flex h-20 w-full items-center justify-center rounded-lg border border-dashed border-neutral-300 text-sm text-neutral-500"><Image class="mr-2 h-5 w-5" />{{ t('maintenance.addPhoto') }}</button><label class="flex items-start gap-3 rounded-lg bg-red-50 p-3"><input type="checkbox" class="mt-0.5 rounded border-red-300 text-red-600 focus:ring-red-500" /><span><strong class="block text-sm text-red-900">{{ t('maintenance.blockRoom') }}</strong><span class="text-xs text-red-700">{{ t('maintenance.blockRoomHint') }}</span></span></label></div>
                    <div class="flex justify-end gap-2 border-t border-neutral-200 p-5"><button class="h-10 rounded-lg px-4 text-sm font-semibold text-neutral-600 hover:bg-neutral-100" @click="reportOpen = false">{{ t('maintenance.cancel') }}</button><button class="inline-flex h-10 items-center gap-2 rounded-lg bg-emerald-800 px-4 text-sm font-semibold text-white" @click="reportOpen = false"><Check class="h-4 w-4" />{{ t('maintenance.submitReport') }}</button></div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
