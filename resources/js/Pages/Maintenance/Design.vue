<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import {
    AlertTriangle, CalendarClock, Check, ChevronRight, CircleAlert, Clock3,
    FileUp, Hammer, Image, Plus, Search, UserRound, Wrench, X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    issues: { type: Object, required: true },
    rooms: { type: Array, default: () => [] },
    staff: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
    permissions: { type: Object, default: () => ({}) },
});

const { t, locale } = useI18n();
const activeStatus = ref(props.filters.status || 'all');
const query = ref(props.filters.search || '');
const selectedId = ref(props.filters.issue_id ? Number(props.filters.issue_id) : null);
const reportOpen = ref(false);
const reportAdvancedOpen = ref(false);
const drawerOpen = ref(Boolean(props.filters.issue_id));
const assignedTo = ref('');
const statusNote = ref('');
const uploadFile = ref(null);
const previewFile = ref(null);

const rows = computed(() => props.issues?.data || []);
const filteredIssues = computed(() => {
    const needle = query.value.trim().toLowerCase();
    return rows.value.filter((issue) => {
        const statusMatches = activeStatus.value === 'all' || issue.status === activeStatus.value;
        const haystack = `${issue.title} ${issue.description || ''} ${issue.room?.room_number || ''} ${issue.reporter?.name || ''}`.toLowerCase();
        return statusMatches && (!needle || haystack.includes(needle));
    });
});
const selected = computed(() => rows.value.find((issue) => issue.id === selectedId.value) || null);
const preventive = computed(() => rows.value.filter((issue) => issue.kind === 'preventive').slice(0, 3));
const statusTabs = computed(() => [
    { value: 'all', count: rows.value.length },
    ...['reported', 'assigned', 'in_progress', 'resolved', 'verified', 'closed'].map((status) => ({
        value: status,
        count: rows.value.filter((issue) => issue.status === status).length,
    })),
]);

const reportForm = useForm({
    room_id: '', title: '', description: '', category: 'other', kind: 'corrective',
    priority: 'medium', asset_name: '', asset_code: '', block_room: false,
    scheduled_for: '', recurrence_days: '', attachments: [],
});

watch(selected, (issue) => { assignedTo.value = issue?.assignee?.id || ''; }, { immediate: true });

const statusLabel = (status) => t(`maintenance.statuses.${status}`);
const categoryLabel = (category) => t(`maintenance.categories.${category}`);
const priorityClass = (priority) => ({
    critical: 'bg-red-50 text-red-700 ring-red-600/20', high: 'bg-orange-50 text-orange-700 ring-orange-600/20',
    medium: 'bg-amber-50 text-amber-700 ring-amber-600/20', low: 'bg-neutral-100 text-neutral-600 ring-neutral-500/20',
}[priority]);
const statusClass = (status) => ({
    reported: 'bg-red-500', assigned: 'bg-amber-500', in_progress: 'bg-blue-500',
    resolved: 'bg-cyan-500', verified: 'bg-emerald-500', closed: 'bg-neutral-400',
}[status]);
const location = (issue) => issue.room ? `${t('maintenance.room')} ${issue.room.room_number}` : t('maintenance.commonArea');
const formatDate = (value) => value ? new Intl.DateTimeFormat(locale.value === 'sq' ? 'sq-AL' : 'en-GB', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : '—';
const sla = (issue) => {
    if (!issue.due_at || ['verified', 'closed'].includes(issue.status)) return '—';
    const minutes = Math.round((new Date(issue.due_at) - new Date()) / 60000);
    if (minutes <= 0) return t('maintenance.overdueBy', { time: `${Math.abs(minutes)}m` });
    if (minutes < 60) return `${minutes}m`;
    return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
};
const nextStatus = (status) => ({ assigned: 'in_progress', in_progress: 'resolved', resolved: 'verified', verified: 'closed' }[status]);
const nextAction = (status) => ({ assigned: 'startWork', in_progress: 'markResolved', resolved: 'verifyWork', verified: 'closeIssue' }[status]);
const previewKind = (file) => file?.mime_type?.startsWith('image/') ? 'image' : file?.mime_type?.startsWith('video/') ? 'video' : file?.mime_type === 'application/pdf' ? 'pdf' : 'unknown';

function openIssue(issue) {
    selectedId.value = issue.id;
    drawerOpen.value = true;
}
function submitReport() {
    reportForm.post(route('maintenance.store'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => { reportOpen.value = false; reportAdvancedOpen.value = false; reportForm.reset(); },
    });
}
function assignIssue() {
    if (!selected.value || !assignedTo.value) return;
    router.patch(route('maintenance.assign', selected.value.id), { assigned_to: assignedTo.value }, { preserveScroll: true });
}
function advanceStatus() {
    const status = selected.value ? nextStatus(selected.value.status) : null;
    if (!status) return;
    router.patch(route('maintenance.status', selected.value.id), { status, note: statusNote.value }, {
        preserveScroll: true,
        onSuccess: () => { statusNote.value = ''; },
    });
}
function toggleRoomBlock() {
    if (!selected.value?.room) return;
    router.patch(route('maintenance.room-block', selected.value.id), { blocked: !selected.value.room_blocked }, { preserveScroll: true });
}
function uploadAttachment() {
    if (!selected.value || !uploadFile.value) return;
    router.post(route('maintenance.attachments.store', selected.value.id), { file: uploadFile.value }, {
        forceFormData: true, preserveScroll: true, onSuccess: () => { uploadFile.value = null; },
    });
}
</script>

<template>
    <Head :title="t('maintenance.title')" />
    <AppLayout>
        <div class="mx-auto max-w-[1600px] pb-8">
            <header class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3.5">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-950 text-white shadow-sm"><Wrench class="h-5 w-5" /></div>
                    <div><div class="flex items-center gap-2.5"><h1 class="text-2xl font-bold tracking-tight text-neutral-950">{{ t('maintenance.title') }}</h1><span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-emerald-800"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>{{ t('maintenance.live') }}</span></div><p class="mt-1 text-sm text-neutral-500">{{ t('maintenance.subtitle') }}</p></div>
                </div>
                <button v-if="permissions.create" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-emerald-900 px-5 text-sm font-semibold text-white shadow-sm transition hover:-translate-y-0.5 hover:bg-emerald-950 hover:shadow-md" @click="reportOpen = true"><Plus class="h-4 w-4" />{{ t('maintenance.reportIssue') }}</button>
            </header>

            <div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="group rounded-2xl border border-neutral-200 bg-white p-4 shadow-sm"><div class="flex items-center justify-between"><span class="text-xs font-semibold text-neutral-500">{{ t('maintenance.openIssues') }}</span><span class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-700"><Wrench class="h-4 w-4" /></span></div><p class="mt-3 text-2xl font-bold text-neutral-950">{{ stats.open || 0 }}</p><p class="mt-1 text-xs text-neutral-400">{{ t('maintenance.needsAttention') }}</p></div>
                <div class="rounded-2xl border border-red-100 bg-white p-4 shadow-sm"><div class="flex items-center justify-between"><span class="text-xs font-semibold text-neutral-500">{{ t('maintenance.urgent') }}</span><span class="flex h-8 w-8 items-center justify-center rounded-lg bg-red-50 text-red-600"><CircleAlert class="h-4 w-4" /></span></div><p class="mt-3 text-2xl font-bold" :class="stats.urgent ? 'text-red-700' : 'text-neutral-950'">{{ stats.urgent || 0 }}</p><p class="mt-1 text-xs text-neutral-400">{{ t('maintenance.slaRisk') }}</p></div>
                <div class="rounded-2xl border border-neutral-200 bg-white p-4 shadow-sm"><div class="flex items-center justify-between"><span class="text-xs font-semibold text-neutral-500">{{ t('maintenance.blockedRooms') }}</span><span class="flex h-8 w-8 items-center justify-center rounded-lg bg-orange-50 text-orange-600"><AlertTriangle class="h-4 w-4" /></span></div><p class="mt-3 text-2xl font-bold text-neutral-950">{{ stats.blocked_rooms || 0 }}</p><p class="mt-1 text-xs text-neutral-400">{{ t('maintenance.outOfService') }}</p></div>
                <div class="rounded-2xl border border-neutral-200 bg-white p-4 shadow-sm"><div class="flex items-center justify-between"><span class="text-xs font-semibold text-neutral-500">{{ t('maintenance.preventiveDue') }}</span><span class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600"><CalendarClock class="h-4 w-4" /></span></div><p class="mt-3 text-2xl font-bold text-neutral-950">{{ stats.preventive_due || 0 }}</p><p class="mt-1 text-xs text-neutral-400">{{ t('maintenance.nextSevenDays') }}</p></div>
            </div>

            <section class="mt-5 overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm">
                <div class="flex flex-col gap-4 border-b border-neutral-200 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                    <div><h2 class="text-lg font-bold text-neutral-950">{{ t('maintenance.operationalList') }}</h2><p class="mt-0.5 text-xs text-neutral-500">{{ t('maintenance.listDescription') }}</p></div>
                    <label class="relative min-w-0 lg:w-80"><Search class="pointer-events-none absolute left-3 top-3 h-4 w-4 text-neutral-400" /><input v-model="query" class="h-10 w-full rounded-xl border-neutral-200 bg-neutral-50 pl-9 text-sm focus:border-emerald-800 focus:bg-white focus:ring-emerald-800" :placeholder="t('maintenance.search')" /></label>
                </div>
                <div class="overflow-x-auto border-b border-neutral-100 px-5 py-3"><div class="flex min-w-max gap-1.5"><button v-for="tab in statusTabs" :key="tab.value" class="rounded-lg px-3 py-2 text-xs font-semibold transition" :class="activeStatus === tab.value ? 'bg-emerald-950 text-white shadow-sm' : 'text-neutral-500 hover:bg-neutral-100 hover:text-neutral-800'" @click="activeStatus = tab.value">{{ tab.value === 'all' ? t('maintenance.all') : statusLabel(tab.value) }} <span class="ml-1 rounded-md px-1.5 py-0.5" :class="activeStatus === tab.value ? 'bg-white/15 text-white' : 'bg-neutral-100 text-neutral-500'">{{ tab.count }}</span></button></div></div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[980px] text-left">
                        <thead class="bg-neutral-50/80 text-[10px] font-bold uppercase tracking-[0.12em] text-neutral-400"><tr><th class="px-5 py-3.5">{{ t('maintenance.problem') }}</th><th class="px-4 py-3.5">{{ t('maintenance.roomLocation') }}</th><th class="px-4 py-3.5">{{ t('maintenance.priority') }}</th><th class="px-4 py-3.5">{{ t('maintenance.status') }}</th><th class="px-4 py-3.5">{{ t('maintenance.assignee') }}</th><th class="px-4 py-3.5">SLA</th><th class="w-12 px-4 py-3.5"></th></tr></thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="issue in filteredIssues" :key="issue.id" class="group cursor-pointer transition hover:bg-emerald-50/40" @click="openIssue(issue)">
                                <td class="px-5 py-4"><div class="flex items-center gap-3"><span class="h-10 w-1 rounded-full" :class="issue.priority === 'critical' ? 'bg-red-500' : issue.priority === 'high' ? 'bg-orange-400' : issue.priority === 'medium' ? 'bg-amber-400' : 'bg-neutral-300'"></span><div class="min-w-0"><div class="flex items-center gap-2"><span class="font-semibold text-neutral-950 group-hover:text-emerald-900">{{ issue.title }}</span><span v-if="issue.source === 'housekeeping'" class="rounded-md bg-violet-50 px-1.5 py-0.5 text-[10px] font-semibold text-violet-700">HK</span></div><p class="mt-1 text-xs text-neutral-400">#MNT-{{ issue.id }} <span class="mx-1 text-neutral-300">·</span> {{ issue.reporter?.name || '—' }} <span class="mx-1 text-neutral-300">·</span> {{ formatDate(issue.created_at) }}</p></div></div></td>
                                <td class="px-4 py-4 text-sm font-medium text-neutral-700">{{ location(issue) }}<span class="mt-0.5 block text-xs font-normal text-neutral-400">{{ categoryLabel(issue.category) }}</span></td>
                                <td class="px-4 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-bold ring-1 ring-inset" :class="priorityClass(issue.priority)">{{ t(`maintenance.${issue.priority}`) }}</span></td>
                                <td class="px-4 py-4"><span class="inline-flex items-center gap-2 text-sm font-medium text-neutral-700"><span class="h-2 w-2 rounded-full" :class="statusClass(issue.status)"></span>{{ statusLabel(issue.status) }}</span></td>
                                <td class="px-4 py-4"><span class="inline-flex items-center gap-2 text-sm text-neutral-600"><span class="flex h-7 w-7 items-center justify-center rounded-full bg-neutral-100 text-[10px] font-bold text-neutral-600">{{ (issue.assignee?.name || '?').charAt(0) }}</span>{{ issue.assignee?.name || t('maintenance.unassigned') }}</span></td><td class="px-4 py-4 text-sm font-semibold" :class="sla(issue).includes('-') ? 'text-red-700' : 'text-neutral-600'">{{ sla(issue) }}</td><td class="px-4 py-4"><span class="flex h-8 w-8 items-center justify-center rounded-lg text-neutral-300 transition group-hover:bg-white group-hover:text-emerald-700 group-hover:shadow-sm"><ChevronRight class="h-4 w-4" /></span></td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="filteredIssues.length === 0" class="p-12 text-center text-sm text-neutral-500">{{ t('maintenance.noResults') }}</div>
                </div>
                <div v-if="preventive.length" class="grid border-t border-neutral-200 bg-stone-50/70 md:grid-cols-3"><div v-for="task in preventive" :key="task.id" class="flex items-center gap-3 border-b border-neutral-200 px-5 py-3 md:border-b-0 md:border-r"><CalendarClock class="h-4 w-4 shrink-0 text-emerald-700" /><div class="min-w-0 flex-1"><p class="truncate text-xs font-semibold text-neutral-800">{{ task.title }}</p><p class="text-[11px] text-neutral-400">{{ location(task) }}</p></div><span class="text-[11px] font-semibold text-amber-600">{{ formatDate(task.scheduled_for) }}</span></div></div>
            </section>
        </div>

        <Teleport to="body">
            <div v-if="drawerOpen && selected" class="fixed inset-0 z-50 bg-neutral-950/40 backdrop-blur-[1px]" @click.self="drawerOpen = false">
                <aside class="absolute inset-y-0 right-0 flex w-full max-w-[500px] flex-col overflow-hidden bg-neutral-50 shadow-2xl">
                    <div class="flex shrink-0 items-center justify-between border-b border-neutral-200 bg-white px-4 py-3"><div class="flex items-center gap-2"><span class="rounded-md bg-neutral-100 px-2 py-1 text-[10px] font-bold text-neutral-500">#MNT-{{ selected.id }}</span><span class="inline-flex rounded-full px-2 py-1 text-[10px] font-bold ring-1 ring-inset" :class="priorityClass(selected.priority)">{{ t(`maintenance.${selected.priority}`) }}</span><span class="inline-flex items-center gap-1.5 rounded-full bg-neutral-100 px-2 py-1 text-[10px] font-semibold text-neutral-700"><span class="h-1.5 w-1.5 rounded-full" :class="statusClass(selected.status)"></span>{{ statusLabel(selected.status) }}</span></div><button class="rounded-lg p-2 text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-700" @click="drawerOpen = false"><X class="h-4 w-4" /></button></div>
                    <div class="scrollbar-hidden flex-1 overflow-y-auto p-4"><div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm"><div class="flex items-start justify-between gap-3"><div><p class="text-[10px] font-bold uppercase tracking-wider text-emerald-700">{{ location(selected) }} · {{ categoryLabel(selected.category) }}</p><h2 class="mt-1.5 text-xl font-bold leading-tight text-neutral-950">{{ selected.title }}</h2><p class="mt-1.5 text-[11px] text-neutral-400">{{ t('maintenance.reportedBy') }} {{ selected.reporter?.name || '—' }} · {{ formatDate(selected.created_at) }}</p></div><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-950 text-white"><Wrench class="h-4 w-4" /></span></div></div>
                        <div v-if="selected.room_blocked" class="mt-5 flex gap-3 rounded-xl border border-red-200 bg-red-50 p-3"><AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-red-600" /><div><p class="text-sm font-semibold text-red-900">{{ t('maintenance.roomIsBlocked') }}</p><p class="mt-0.5 text-xs text-red-700">{{ t('maintenance.blockRoomHint') }}</p></div></div>
                        <div class="mt-3 grid grid-cols-2 gap-2.5"><div class="rounded-xl border border-neutral-200 bg-white p-3 shadow-sm"><span class="text-[9px] font-bold uppercase tracking-wider text-neutral-400">{{ t('maintenance.assignee') }}</span><p class="mt-1.5 flex items-center gap-2 text-sm font-semibold"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-emerald-50 text-[10px] text-emerald-800">{{ (selected.assignee?.name || '?').charAt(0) }}</span>{{ selected.assignee?.name || t('maintenance.unassigned') }}</p></div><div class="rounded-xl border border-neutral-200 bg-white p-3 shadow-sm"><span class="text-[9px] font-bold uppercase tracking-wider text-neutral-400">SLA</span><p class="mt-1.5 flex items-center gap-2 text-sm font-semibold"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-blue-50 text-blue-700"><Clock3 class="h-3 w-3" /></span>{{ sla(selected) }}</p></div></div>
                        <div class="mt-3 rounded-xl border border-neutral-200 bg-white p-4 shadow-sm"><h3 class="text-[10px] font-bold uppercase tracking-wider text-neutral-400">{{ t('maintenance.report') }}</h3><p class="mt-2 whitespace-pre-line text-sm leading-5 text-neutral-700">{{ selected.description || '—' }}</p><div v-if="selected.asset_name || selected.asset_code" class="mt-3 flex items-center justify-between gap-3 border-t border-neutral-100 pt-3"><div><span class="text-[9px] font-bold uppercase tracking-wider text-neutral-400">{{ t('maintenance.asset') }}</span><p class="mt-1 text-sm font-semibold text-neutral-900">{{ selected.asset_name || '—' }}</p></div><span v-if="selected.asset_code" class="rounded-md bg-neutral-100 px-2 py-1 font-mono text-[10px] text-neutral-500">{{ selected.asset_code }}</span></div></div>

                        <div v-if="permissions.update && !['verified', 'closed'].includes(selected.status)" class="mt-3 rounded-xl border border-neutral-200 bg-white p-4 shadow-sm"><label class="text-[10px] font-bold uppercase tracking-wider text-neutral-400">{{ t('maintenance.assignee') }}</label><div class="mt-2 flex gap-2"><select v-model="assignedTo" class="min-w-0 flex-1 rounded-lg border-neutral-200 bg-neutral-50 py-2 text-sm"><option value="">{{ t('maintenance.chooseTechnician') }}</option><option v-for="person in staff" :key="person.id" :value="person.id">{{ person.name }}</option></select><button class="rounded-lg bg-neutral-900 px-3 text-xs font-semibold text-white disabled:opacity-40" :disabled="!assignedTo" @click="assignIssue">{{ t('maintenance.assign') }}</button></div></div>

                        <div class="mt-3 rounded-xl border border-neutral-200 bg-white p-4 shadow-sm"><h3 class="text-[10px] font-bold uppercase tracking-wider text-neutral-400">{{ t('maintenance.attachments') }}</h3><div class="mt-2 space-y-2"><button v-for="file in selected.attachments" :key="file.id" type="button" class="flex w-full items-center justify-between gap-2 rounded-lg border border-neutral-200 p-2.5 text-left text-xs font-medium text-emerald-800 transition hover:border-emerald-200 hover:bg-emerald-50" @click="previewFile = file"><span class="flex min-w-0 items-center gap-2"><Image class="h-3.5 w-3.5 shrink-0" /><span class="truncate">{{ file.name }}</span></span><span class="text-[10px] font-semibold text-neutral-400">{{ t('maintenance.preview') }}</span></button><p v-if="!selected.attachments.length" class="rounded-lg bg-neutral-50 px-3 py-3 text-center text-xs text-neutral-400">{{ t('maintenance.noAttachments') }}</p></div><div v-if="permissions.update && selected.status !== 'closed'" class="mt-2 flex gap-2"><input type="file" class="min-w-0 flex-1 text-xs" @change="uploadFile = $event.target.files[0]" /><button class="rounded-lg border border-neutral-300 px-3 py-2 text-xs font-semibold disabled:opacity-40" :disabled="!uploadFile" @click="uploadAttachment"><FileUp class="inline h-3.5 w-3.5" /> {{ t('maintenance.upload') }}</button></div></div>

                        <div class="mt-3 rounded-xl border border-neutral-200 bg-white p-4 shadow-sm"><h3 class="text-[10px] font-bold uppercase tracking-wider text-neutral-400">{{ t('maintenance.timeline') }}</h3><div class="relative mt-3 space-y-3 before:absolute before:bottom-2 before:left-[4px] before:top-2 before:w-px before:bg-neutral-200"><div v-for="event in selected.events" :key="event.id" class="relative pl-5"><span class="absolute left-0 top-1.5 h-[9px] w-[9px] rounded-full border-2 border-white bg-emerald-500 ring-1 ring-emerald-200"></span><p class="text-xs font-semibold text-neutral-800">{{ event.to_status ? statusLabel(event.to_status) : event.type }}</p><p class="mt-0.5 text-[11px] leading-4 text-neutral-500">{{ event.user?.name || '—' }} · {{ formatDate(event.created_at) }}<span v-if="event.note" class="mt-1 block text-neutral-700">{{ event.note }}</span></p></div></div></div>
                    </div>
                    <div v-if="permissions.update && (nextStatus(selected.status) || (selected.room && selected.status !== 'closed'))" class="shrink-0 border-t border-neutral-200 bg-white p-3 shadow-[0_-8px_24px_rgba(0,0,0,0.04)]"><textarea v-if="nextStatus(selected.status)" v-model="statusNote" rows="1" class="mb-2 w-full resize-none rounded-lg border-neutral-200 bg-neutral-50 text-sm focus:bg-white" :placeholder="selected.status === 'in_progress' ? t('maintenance.resolutionRequired') : t('maintenance.actionNote')"></textarea><div class="grid gap-2" :class="nextStatus(selected.status) && selected.room ? 'grid-cols-2' : 'grid-cols-1'"><button v-if="selected.room && selected.status !== 'closed'" class="inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-neutral-300 px-3 text-xs font-semibold text-neutral-700 hover:bg-neutral-50" @click="toggleRoomBlock">{{ selected.room_blocked ? t('maintenance.releaseRoom') : t('maintenance.blockRoom') }}</button><button v-if="nextStatus(selected.status)" :disabled="selected.status === 'in_progress' && !statusNote.trim()" class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-emerald-900 px-3 text-xs font-semibold text-white shadow-sm hover:bg-emerald-950 disabled:opacity-40" @click="advanceStatus"><Hammer class="h-3.5 w-3.5" />{{ t(`maintenance.${nextAction(selected.status)}`) }}</button></div></div>
                </aside>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="reportOpen" class="fixed inset-0 z-50 flex items-end justify-center bg-neutral-950/50 sm:items-center sm:p-4" @click.self="reportOpen = false">
                <form class="scrollbar-hidden max-h-[92vh] w-full max-w-xl overflow-y-auto rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl" @submit.prevent="submitReport">
                    <div class="sticky top-0 z-10 flex items-center justify-between border-b border-neutral-200 bg-white px-4 py-3"><div class="flex items-center gap-3"><span class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-950 text-white"><Wrench class="h-3.5 w-3.5" /></span><div><h2 class="text-base font-bold text-neutral-950">{{ t('maintenance.reportIssue') }}</h2><p class="mt-0.5 text-[11px] text-neutral-500">{{ t('maintenance.reportHint') }}</p></div></div><button type="button" class="rounded-lg p-2 text-neutral-400 hover:bg-neutral-100" @click="reportOpen = false"><X class="h-4 w-4" /></button></div>
                    <div class="grid gap-3 p-4 sm:grid-cols-2">
                        <label class="text-xs font-semibold text-neutral-700">{{ t('maintenance.roomLocation') }}<select v-model="reportForm.room_id" class="mt-1.5 w-full rounded-lg border-neutral-200 bg-neutral-50 py-2 text-sm focus:bg-white"><option value="">{{ t('maintenance.commonArea') }}</option><option v-for="room in rooms" :key="room.id" :value="room.id">{{ t('maintenance.room') }} {{ room.room_number }}</option></select></label>
                        <label class="text-xs font-semibold text-neutral-700">{{ t('maintenance.priority') }}<select v-model="reportForm.priority" class="mt-1.5 w-full rounded-lg border-neutral-200 bg-neutral-50 py-2 text-sm focus:bg-white"><option v-for="p in ['low','medium','high','critical']" :key="p" :value="p">{{ t(`maintenance.${p}`) }}</option></select></label>
                        <label class="text-xs font-semibold text-neutral-700">{{ t('maintenance.category') }}<select v-model="reportForm.category" class="mt-1.5 w-full rounded-lg border-neutral-200 bg-neutral-50 py-2 text-sm focus:bg-white"><option v-for="c in ['electronics','climate','electrical','plumbing','furniture','safety','other']" :key="c" :value="c">{{ categoryLabel(c) }}</option></select></label>
                        <label class="text-xs font-semibold text-neutral-700">{{ t('maintenance.type') }}<select v-model="reportForm.kind" class="mt-1.5 w-full rounded-lg border-neutral-200 bg-neutral-50 py-2 text-sm focus:bg-white"><option value="corrective">{{ t('maintenance.corrective') }}</option><option value="preventive">{{ t('maintenance.preventive') }}</option></select></label>
                        <label class="sm:col-span-2 text-xs font-semibold text-neutral-700">{{ t('maintenance.problem') }}<input v-model="reportForm.title" required class="mt-1.5 w-full rounded-lg border-neutral-200 bg-neutral-50 py-2 text-sm focus:bg-white" :placeholder="t('maintenance.problemPlaceholder')" /><span v-if="reportForm.errors.title" class="mt-1 block text-xs text-red-600">{{ reportForm.errors.title }}</span></label>
                        <label class="sm:col-span-2 text-xs font-semibold text-neutral-700">{{ t('maintenance.description') }}<textarea v-model="reportForm.description" rows="2" class="mt-1.5 w-full resize-none rounded-lg border-neutral-200 bg-neutral-50 text-sm focus:bg-white" :placeholder="t('maintenance.descriptionPlaceholder')"></textarea></label>
                        <template v-if="reportForm.kind === 'preventive'"><label class="text-xs font-semibold text-neutral-700">{{ t('maintenance.scheduledFor') }}<input v-model="reportForm.scheduled_for" type="datetime-local" class="mt-1.5 w-full rounded-lg border-neutral-200 bg-neutral-50 py-2 text-sm" /></label><label class="text-xs font-semibold text-neutral-700">{{ t('maintenance.recurrenceDays') }}<input v-model="reportForm.recurrence_days" type="number" min="1" class="mt-1.5 w-full rounded-lg border-neutral-200 bg-neutral-50 py-2 text-sm" /></label></template>
                        <div class="sm:col-span-2 rounded-xl border border-neutral-200 bg-neutral-50/70"><button type="button" class="flex w-full items-center justify-between px-3.5 py-2.5 text-left text-xs font-semibold text-neutral-700" @click="reportAdvancedOpen = !reportAdvancedOpen"><span>{{ t('maintenance.assetDetails') }} <small class="ml-1 font-normal text-neutral-400">{{ t('maintenance.optional') }}</small></span><span class="text-neutral-400">{{ reportAdvancedOpen ? '−' : '+' }}</span></button><div v-if="reportAdvancedOpen" class="grid gap-3 border-t border-neutral-200 p-3 sm:grid-cols-2"><label class="text-xs font-semibold text-neutral-700">{{ t('maintenance.asset') }}<input v-model="reportForm.asset_name" class="mt-1.5 w-full rounded-lg border-neutral-200 bg-white py-2 text-sm" /></label><label class="text-xs font-semibold text-neutral-700">{{ t('maintenance.assetCode') }}<input v-model="reportForm.asset_code" class="mt-1.5 w-full rounded-lg border-neutral-200 bg-white py-2 text-sm" /></label></div></div>
                        <label class="sm:col-span-2 flex h-12 cursor-pointer items-center justify-center rounded-xl border border-dashed border-neutral-300 bg-neutral-50 text-xs font-medium text-neutral-500 transition hover:border-emerald-400 hover:bg-emerald-50/50"><Image class="mr-2 h-4 w-4" /><span>{{ reportForm.attachments.length ? `${reportForm.attachments.length} ${t('maintenance.filesSelected')}` : t('maintenance.addPhoto') }}</span><input multiple type="file" accept="image/*,video/*,.pdf" class="hidden" @change="reportForm.attachments = Array.from($event.target.files)" /></label>
                        <label v-if="reportForm.room_id" class="sm:col-span-2 flex items-start gap-3 rounded-xl border border-red-100 bg-red-50/70 p-3"><input v-model="reportForm.block_room" type="checkbox" class="mt-0.5 rounded border-red-300 text-red-600 focus:ring-red-500" /><span><strong class="block text-xs text-red-900">{{ t('maintenance.blockRoom') }}</strong><span class="mt-0.5 block text-[11px] text-red-700">{{ t('maintenance.blockRoomHint') }}</span></span></label>
                    </div>
                    <div class="sticky bottom-0 flex justify-end gap-2 border-t border-neutral-200 bg-white px-4 py-3"><button type="button" class="h-9 rounded-lg px-4 text-sm font-semibold text-neutral-600 hover:bg-neutral-100" @click="reportOpen = false">{{ t('maintenance.cancel') }}</button><button type="submit" :disabled="reportForm.processing" class="inline-flex h-9 items-center gap-2 rounded-lg bg-emerald-800 px-4 text-sm font-semibold text-white shadow-sm hover:bg-emerald-900 disabled:opacity-50"><Check class="h-4 w-4" />{{ t('maintenance.submitReport') }}</button></div>
                </form>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="previewFile" class="fixed inset-0 z-[70] flex items-center justify-center bg-neutral-950/85 p-4 backdrop-blur-sm" @click.self="previewFile = null">
                <div class="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl bg-neutral-900 shadow-2xl">
                    <div class="flex shrink-0 items-center justify-between border-b border-white/10 px-4 py-3"><div class="min-w-0"><p class="truncate text-sm font-semibold text-white">{{ previewFile.name }}</p><p class="mt-0.5 text-[10px] uppercase tracking-wider text-neutral-400">{{ t('maintenance.privatePreview') }}</p></div><button type="button" class="rounded-lg p-2 text-neutral-300 hover:bg-white/10 hover:text-white" :aria-label="t('maintenance.closePreview')" @click="previewFile = null"><X class="h-5 w-5" /></button></div>
                    <div class="flex min-h-0 flex-1 items-center justify-center bg-neutral-950 p-3">
                        <img v-if="previewKind(previewFile) === 'image'" :src="previewFile.url" :alt="previewFile.name" class="max-h-[80vh] max-w-full rounded-lg object-contain" />
                        <video v-else-if="previewKind(previewFile) === 'video'" :src="previewFile.url" controls controlsList="nodownload" class="max-h-[80vh] max-w-full rounded-lg"></video>
                        <iframe v-else-if="previewKind(previewFile) === 'pdf'" :src="previewFile.url" class="h-[80vh] w-full rounded-lg bg-white" :title="previewFile.name"></iframe>
                        <p v-else class="p-10 text-sm text-neutral-300">{{ t('maintenance.previewUnavailable') }}</p>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<style scoped>
.scrollbar-hidden {
    -ms-overflow-style: none;
    scrollbar-width: none;
}

.scrollbar-hidden::-webkit-scrollbar {
    display: none;
}
</style>
