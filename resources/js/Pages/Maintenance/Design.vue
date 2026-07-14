<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import {
    AlertTriangle, ArrowRight, CalendarClock, Check, ChevronRight, CircleAlert,
    Clock3, Hammer, Image, MapPin, MoreHorizontal, Plus, Search,
    ShieldCheck, SlidersHorizontal, Sparkles, UserRound, Wrench, X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const { t } = useI18n();
const activeTab = ref('issues');
const activeStatus = ref('all');
const query = ref('');
const selectedId = ref(1042);
const reportOpen = ref(false);

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
</script>

<template>
    <Head :title="t('maintenance.title')" />
    <AppLayout>
        <div class="mx-auto max-w-[1600px]">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <div class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.14em] text-emerald-700">
                        <Sparkles class="h-4 w-4" /> {{ t('maintenance.demo') }}
                    </div>
                    <h1 class="text-2xl font-bold tracking-tight text-neutral-950 sm:text-3xl">{{ t('maintenance.title') }}</h1>
                    <p class="mt-1.5 text-sm text-neutral-500">{{ t('maintenance.subtitle') }}</p>
                </div>
                <button class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-emerald-800 px-4 text-sm font-semibold text-white shadow-sm hover:bg-emerald-900" @click="reportOpen = true">
                    <Plus class="h-4 w-4" /> {{ t('maintenance.reportIssue') }}
                </button>
            </div>

            <div class="mt-6 grid grid-cols-2 gap-3 xl:grid-cols-4">
                <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm">
                    <div class="flex items-center justify-between"><span class="text-sm text-neutral-500">{{ t('maintenance.openIssues') }}</span><Wrench class="h-4 w-4 text-neutral-400" /></div>
                    <div class="mt-2 flex items-end gap-2"><strong class="text-2xl text-neutral-950">3</strong><span class="mb-1 text-xs font-medium text-red-600">+1 {{ t('maintenance.today') }}</span></div>
                </div>
                <div class="rounded-xl border border-red-200 bg-red-50/50 p-4 shadow-sm">
                    <div class="flex items-center justify-between"><span class="text-sm text-red-700">{{ t('maintenance.urgent') }}</span><CircleAlert class="h-4 w-4 text-red-600" /></div>
                    <div class="mt-2 flex items-end gap-2"><strong class="text-2xl text-red-950">1</strong><span class="mb-1 text-xs text-red-600">{{ t('maintenance.slaRisk') }}</span></div>
                </div>
                <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm">
                    <div class="flex items-center justify-between"><span class="text-sm text-neutral-500">{{ t('maintenance.blockedRooms') }}</span><ShieldCheck class="h-4 w-4 text-neutral-400" /></div>
                    <div class="mt-2 flex items-end gap-2"><strong class="text-2xl text-neutral-950">1</strong><span class="mb-1 text-xs text-neutral-500">{{ t('maintenance.room') }} 305</span></div>
                </div>
                <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm">
                    <div class="flex items-center justify-between"><span class="text-sm text-neutral-500">{{ t('maintenance.preventiveDue') }}</span><CalendarClock class="h-4 w-4 text-neutral-400" /></div>
                    <div class="mt-2 flex items-end gap-2"><strong class="text-2xl text-neutral-950">3</strong><span class="mb-1 text-xs font-medium text-amber-600">1 {{ t('maintenance.overdue') }}</span></div>
                </div>
            </div>

            <div class="mt-6 overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm">
                <div class="flex flex-col gap-3 border-b border-neutral-200 p-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex gap-1 rounded-lg bg-neutral-100 p-1">
                        <button v-for="tab in [{ value: 'issues', label: t('maintenance.issues') }, { value: 'preventive', label: t('maintenance.preventive') }]" :key="tab.value" class="rounded-md px-3 py-1.5 text-sm font-semibold transition" :class="activeTab === tab.value ? 'bg-white text-neutral-900 shadow-sm' : 'text-neutral-500'" @click="activeTab = tab.value">{{ tab.label }}</button>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <label class="relative block sm:w-64"><Search class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-neutral-400" /><input v-model="query" class="h-9 w-full rounded-lg border-neutral-200 pl-9 text-sm focus:border-emerald-700 focus:ring-emerald-700" :placeholder="t('maintenance.search')" /></label>
                        <button class="inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-neutral-200 px-3 text-sm font-medium text-neutral-600 hover:bg-neutral-50"><SlidersHorizontal class="h-4 w-4" />{{ t('maintenance.filters') }}</button>
                    </div>
                </div>

                <template v-if="activeTab === 'issues'">
                    <div class="overflow-x-auto border-b border-neutral-200 px-4">
                        <div class="flex min-w-max gap-5">
                            <button v-for="tab in statusTabs" :key="tab.value" class="flex items-center gap-2 border-b-2 px-1 py-3 text-sm font-medium" :class="activeStatus === tab.value ? 'border-emerald-700 text-emerald-800' : 'border-transparent text-neutral-500'" @click="activeStatus = tab.value">
                                {{ t(`maintenance.${tab.labelKey}`) }} <span class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs">{{ tab.count }}</span>
                            </button>
                        </div>
                    </div>

                    <div class="grid min-h-[580px] xl:grid-cols-[minmax(0,1fr)_430px]">
                        <div class="divide-y divide-neutral-100 xl:border-r xl:border-neutral-200">
                            <button v-for="issue in filteredIssues" :key="issue.id" class="grid w-full gap-3 p-4 text-left transition hover:bg-neutral-50 sm:grid-cols-[auto_minmax(0,1fr)_auto]" :class="selectedId === issue.id ? 'bg-emerald-50/60' : ''" @click="selectedId = issue.id">
                                <div class="flex h-10 w-10 items-center justify-center rounded-lg" :class="issue.priority === 'critical' ? 'bg-red-100 text-red-700' : 'bg-neutral-100 text-neutral-600'"><AlertTriangle v-if="issue.priority === 'critical'" class="h-5 w-5" /><Wrench v-else class="h-5 w-5" /></div>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2"><span class="text-xs font-semibold text-neutral-400">#MNT-{{ issue.id }}</span><span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ring-inset" :class="priorityClass(issue.priority)">{{ t(`maintenance.${issue.priority}`) }}</span><span v-if="issue.source === 'housekeeping'" class="inline-flex items-center gap-1 rounded-full bg-violet-50 px-2 py-0.5 text-[11px] font-semibold text-violet-700"><Sparkles class="h-3 w-3" />{{ t('maintenance.fromHousekeeping') }}</span></div>
                                    <h3 class="mt-1.5 truncate text-sm font-semibold text-neutral-950">{{ t(`maintenance.mock.${issue.titleKey}`) }}</h3>
                                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-neutral-500"><span class="inline-flex items-center gap-1"><MapPin class="h-3.5 w-3.5" />{{ issueLocation(issue) }}</span><span>{{ t(`maintenance.mock.${issue.categoryKey}`) }}</span><span>{{ issue.reporter }} · {{ issueReportedAt(issue) }}</span></div>
                                </div>
                                <div class="flex items-center justify-between gap-3 sm:flex-col sm:items-end"><div class="flex items-center gap-2 text-xs font-medium text-neutral-600"><span class="h-2 w-2 rounded-full" :class="statusClass(issue.status)"></span>{{ t(`maintenance.${issue.status === 'progress' ? 'inProgress' : issue.status}`) }}</div><span class="text-xs text-neutral-400">{{ t(`maintenance.mock.${issue.elapsedKey}`) }}</span></div>
                            </button>
                            <div v-if="filteredIssues.length === 0" class="p-12 text-center text-sm text-neutral-500">{{ t('maintenance.noResults') }}</div>
                        </div>

                        <aside class="bg-neutral-50/60 p-5" v-if="selected">
                            <div class="flex items-start justify-between gap-4"><div><div class="flex items-center gap-2"><span class="text-xs font-semibold text-neutral-400">#MNT-{{ selected.id }}</span><span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ring-inset" :class="priorityClass(selected.priority)">{{ t(`maintenance.${selected.priority}`) }}</span></div><h2 class="mt-2 text-xl font-bold text-neutral-950">{{ t(`maintenance.mock.${selected.titleKey}`) }}</h2><p class="mt-1 text-sm text-neutral-500">{{ issueLocation(selected) }} · {{ t(`maintenance.mock.${selected.categoryKey}`) }}</p></div><button class="rounded-lg p-2 text-neutral-400 hover:bg-white"><MoreHorizontal class="h-5 w-5" /></button></div>

                            <div v-if="selected.guestImpact" class="mt-4 flex gap-3 rounded-lg border border-red-200 bg-red-50 p-3"><AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-red-600" /><div><p class="text-sm font-semibold text-red-900">{{ t('maintenance.guestImpact') }}</p><p class="mt-0.5 text-xs text-red-700">{{ t('maintenance.guestImpactHint') }}</p></div></div>

                            <div class="mt-5 grid grid-cols-2 gap-3">
                                <div class="rounded-lg border border-neutral-200 bg-white p-3"><span class="text-[11px] font-semibold uppercase tracking-wide text-neutral-400">{{ t('maintenance.assignee') }}</span><p class="mt-1 flex items-center gap-1.5 text-sm font-semibold text-neutral-800"><UserRound class="h-4 w-4 text-neutral-400" />{{ issueAssignee(selected) }}</p></div>
                                <div class="rounded-lg border border-neutral-200 bg-white p-3"><span class="text-[11px] font-semibold uppercase tracking-wide text-neutral-400">SLA</span><p class="mt-1 flex items-center gap-1.5 text-sm font-semibold" :class="selected.priority === 'critical' ? 'text-red-700' : 'text-neutral-800'"><Clock3 class="h-4 w-4" />{{ selected.sla }}</p></div>
                            </div>

                            <div class="mt-5"><h3 class="text-xs font-bold uppercase tracking-wide text-neutral-400">{{ t('maintenance.report') }}</h3><p class="mt-2 text-sm leading-6 text-neutral-700">{{ t(`maintenance.mock.${selected.descriptionKey}`) }}</p><div class="mt-3 flex h-20 items-center justify-center rounded-lg border border-dashed border-neutral-300 bg-white text-xs text-neutral-400"><Image class="mr-2 h-4 w-4" />{{ t('maintenance.photoFromReport') }}</div></div>
                            <div class="mt-5 rounded-lg border border-neutral-200 bg-white p-3"><div class="flex items-center justify-between"><div><span class="text-[11px] font-semibold uppercase tracking-wide text-neutral-400">{{ t('maintenance.asset') }}</span><p class="mt-1 text-sm font-semibold text-neutral-900">{{ issueAsset(selected) }}</p><p class="text-xs text-neutral-500">{{ selected.assetCode }}</p></div><ChevronRight class="h-5 w-5 text-neutral-400" /></div></div>

                            <div class="mt-5"><h3 class="text-xs font-bold uppercase tracking-wide text-neutral-400">{{ t('maintenance.timeline') }}</h3><div class="mt-3 space-y-4 border-l border-neutral-200 pl-4"><div><p class="text-sm font-semibold text-neutral-800">{{ t('maintenance.issueReported') }}</p><p class="text-xs text-neutral-500">{{ selected.reporter }} · {{ issueReportedAt(selected) }} · {{ t(`maintenance.${selected.source === 'housekeeping' ? 'housekeeping' : 'reception'}`) }}</p></div><div v-if="selected.status !== 'new'"><p class="text-sm font-semibold text-neutral-800">{{ t('maintenance.technicianAssigned') }}</p><p class="text-xs text-neutral-500">{{ issueAssignee(selected) }}</p></div></div></div>

                            <div class="mt-6 grid grid-cols-2 gap-2"><button class="inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-neutral-300 bg-white text-sm font-semibold text-neutral-700 hover:bg-neutral-50"><UserRound class="h-4 w-4" />{{ t('maintenance.assign') }}</button><button class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-emerald-800 text-sm font-semibold text-white hover:bg-emerald-900"><Hammer class="h-4 w-4" />{{ t('maintenance.startWork') }}</button></div>
                        </aside>
                    </div>
                </template>

                <div v-else class="grid gap-4 p-4 lg:grid-cols-3">
                    <div v-for="task in preventive" :key="task.id" class="rounded-xl border border-neutral-200 p-4">
                        <div class="flex items-start justify-between"><div class="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-50 text-emerald-700"><CalendarClock class="h-5 w-5" /></div><span class="rounded-full px-2 py-1 text-xs font-semibold" :class="task.tone === 'red' ? 'bg-red-50 text-red-700' : task.tone === 'amber' ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700'">{{ t(`maintenance.mock.${task.dueKey}`) }}</span></div>
                        <h3 class="mt-4 font-bold text-neutral-900">{{ t(`maintenance.mock.${task.titleKey}`) }}</h3><p class="mt-1 text-sm text-neutral-500">{{ t(`maintenance.mock.${task.locationKey}`) }}</p><div class="mt-5 h-1.5 overflow-hidden rounded-full bg-neutral-100"><div class="h-full rounded-full bg-emerald-700" :style="{ width: `${task.progress}%` }"></div></div><button class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-emerald-800">{{ t('maintenance.openPlan') }}<ArrowRight class="h-4 w-4" /></button>
                    </div>
                </div>
            </div>
        </div>

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
