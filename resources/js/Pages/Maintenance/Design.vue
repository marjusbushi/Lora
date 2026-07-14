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
const selectedId = ref(null);
const reportOpen = ref(false);
const drawerOpen = ref(false);
const assignedTo = ref('');
const statusNote = ref('');
const uploadFile = ref(null);

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

function openIssue(issue) {
    selectedId.value = issue.id;
    drawerOpen.value = true;
}
function submitReport() {
    reportForm.post(route('maintenance.store'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => { reportOpen.value = false; reportForm.reset(); },
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
        <div class="mx-auto max-w-[1600px]">
            <div class="rounded-2xl border border-neutral-200 bg-white px-5 py-5 shadow-sm sm:px-7">
                <div class="flex flex-col gap-5 xl:flex-row xl:items-center xl:justify-between">
                    <div class="flex items-start gap-4">
                        <div class="hidden h-12 w-12 items-center justify-center rounded-xl bg-emerald-950 text-white sm:flex"><Wrench class="h-6 w-6" /></div>
                        <div><div class="flex items-center gap-2"><h1 class="text-2xl font-bold tracking-tight text-neutral-950">{{ t('maintenance.title') }}</h1><span class="rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-emerald-700">{{ t('maintenance.live') }}</span></div><p class="mt-1 text-sm text-neutral-500">{{ t('maintenance.subtitle') }}</p></div>
                    </div>
                    <button v-if="permissions.create" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-emerald-900 px-4 text-sm font-semibold text-white hover:bg-emerald-950" @click="reportOpen = true"><Plus class="h-4 w-4" />{{ t('maintenance.reportIssue') }}</button>
                </div>
                <div class="mt-5 flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-neutral-100 pt-4 text-sm"><span class="font-semibold text-neutral-900">{{ stats.open || 0 }} {{ t('maintenance.openIssues').toLowerCase() }}</span><span class="h-1 w-1 rounded-full bg-neutral-300"></span><span class="inline-flex items-center gap-1.5 font-semibold text-red-700"><CircleAlert class="h-4 w-4" />{{ stats.urgent || 0 }} {{ t('maintenance.urgent').toLowerCase() }}</span><span class="h-1 w-1 rounded-full bg-neutral-300"></span><span class="text-neutral-500">{{ stats.blocked_rooms || 0 }} {{ t('maintenance.blockedRooms').toLowerCase() }}</span><span class="h-1 w-1 rounded-full bg-neutral-300"></span><span class="text-neutral-500">{{ stats.preventive_due || 0 }} {{ t('maintenance.preventiveDue').toLowerCase() }}</span></div>
            </div>

            <div class="mt-5 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="overflow-x-auto"><div class="flex min-w-max gap-2"><button v-for="tab in statusTabs" :key="tab.value" class="rounded-full border px-3.5 py-2 text-xs font-semibold transition" :class="activeStatus === tab.value ? 'border-emerald-900 bg-emerald-900 text-white' : 'border-neutral-200 bg-white text-neutral-600'" @click="activeStatus = tab.value">{{ tab.value === 'all' ? t('maintenance.all') : statusLabel(tab.value) }} <span class="ml-1 opacity-70">{{ tab.count }}</span></button></div></div>
                <label class="relative min-w-0 sm:w-80"><Search class="pointer-events-none absolute left-3 top-3 h-4 w-4 text-neutral-400" /><input v-model="query" class="h-10 w-full rounded-xl border-neutral-200 bg-white pl-9 text-sm focus:border-emerald-800 focus:ring-emerald-800" :placeholder="t('maintenance.search')" /></label>
            </div>

            <section class="mt-4 overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4"><div><h2 class="font-bold text-neutral-950">{{ t('maintenance.operationalList') }}</h2><p class="mt-0.5 text-xs text-neutral-500">{{ t('maintenance.listDescription') }}</p></div><span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">{{ t('maintenance.recommended') }}</span></div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[980px] text-left">
                        <thead class="bg-neutral-50 text-[11px] font-bold uppercase tracking-wider text-neutral-400"><tr><th class="px-5 py-3">{{ t('maintenance.problem') }}</th><th class="px-4 py-3">{{ t('maintenance.roomLocation') }}</th><th class="px-4 py-3">{{ t('maintenance.priority') }}</th><th class="px-4 py-3">{{ t('maintenance.status') }}</th><th class="px-4 py-3">{{ t('maintenance.assignee') }}</th><th class="px-4 py-3">SLA</th><th class="w-12 px-4 py-3"></th></tr></thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="issue in filteredIssues" :key="issue.id" class="cursor-pointer transition hover:bg-emerald-50/40" @click="openIssue(issue)">
                                <td class="px-5 py-4"><div class="flex items-center gap-3"><span class="h-9 w-1 rounded-full" :class="issue.priority === 'critical' ? 'bg-red-500' : issue.priority === 'high' ? 'bg-orange-400' : issue.priority === 'medium' ? 'bg-amber-400' : 'bg-neutral-300'"></span><div><div class="flex items-center gap-2"><span class="font-semibold text-neutral-950">{{ issue.title }}</span><span v-if="issue.source === 'housekeeping'" class="rounded bg-violet-50 px-1.5 py-0.5 text-[10px] font-semibold text-violet-700">HK</span></div><p class="mt-1 text-xs text-neutral-400">#MNT-{{ issue.id }} · {{ issue.reporter?.name || '—' }} · {{ formatDate(issue.created_at) }}</p></div></div></td>
                                <td class="px-4 py-4 text-sm font-medium text-neutral-700">{{ location(issue) }}<span class="mt-0.5 block text-xs font-normal text-neutral-400">{{ categoryLabel(issue.category) }}</span></td>
                                <td class="px-4 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-bold ring-1 ring-inset" :class="priorityClass(issue.priority)">{{ t(`maintenance.${issue.priority}`) }}</span></td>
                                <td class="px-4 py-4"><span class="inline-flex items-center gap-2 text-sm font-medium text-neutral-700"><span class="h-2 w-2 rounded-full" :class="statusClass(issue.status)"></span>{{ statusLabel(issue.status) }}</span></td>
                                <td class="px-4 py-4 text-sm text-neutral-600">{{ issue.assignee?.name || t('maintenance.unassigned') }}</td><td class="px-4 py-4 text-sm font-semibold" :class="sla(issue).includes('-') ? 'text-red-700' : 'text-neutral-600'">{{ sla(issue) }}</td><td class="px-4 py-4"><ChevronRight class="h-4 w-4 text-neutral-300" /></td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="filteredIssues.length === 0" class="p-12 text-center text-sm text-neutral-500">{{ t('maintenance.noResults') }}</div>
                </div>
                <div v-if="preventive.length" class="grid border-t border-neutral-200 bg-stone-50/70 md:grid-cols-3"><div v-for="task in preventive" :key="task.id" class="flex items-center gap-3 border-b border-neutral-200 px-5 py-3 md:border-b-0 md:border-r"><CalendarClock class="h-4 w-4 shrink-0 text-emerald-700" /><div class="min-w-0 flex-1"><p class="truncate text-xs font-semibold text-neutral-800">{{ task.title }}</p><p class="text-[11px] text-neutral-400">{{ location(task) }}</p></div><span class="text-[11px] font-semibold text-amber-600">{{ formatDate(task.scheduled_for) }}</span></div></div>
            </section>
        </div>

        <Teleport to="body">
            <div v-if="drawerOpen && selected" class="fixed inset-0 z-50 bg-neutral-950/30" @click.self="drawerOpen = false">
                <aside class="absolute inset-y-0 right-0 w-full max-w-[560px] overflow-y-auto bg-white shadow-2xl">
                    <div class="sticky top-0 z-10 flex items-center justify-between border-b border-neutral-200 bg-white/95 px-5 py-4 backdrop-blur"><div class="flex items-center gap-2"><span class="text-xs font-bold text-neutral-400">#MNT-{{ selected.id }}</span><span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset" :class="priorityClass(selected.priority)">{{ t(`maintenance.${selected.priority}`) }}</span><span class="inline-flex items-center gap-1.5 text-xs font-semibold text-neutral-600"><span class="h-2 w-2 rounded-full" :class="statusClass(selected.status)"></span>{{ statusLabel(selected.status) }}</span></div><button class="rounded-lg p-2 text-neutral-400 hover:bg-neutral-100" @click="drawerOpen = false"><X class="h-5 w-5" /></button></div>
                    <div class="p-5 sm:p-6"><h2 class="text-2xl font-bold text-neutral-950">{{ selected.title }}</h2><p class="mt-1 text-sm text-neutral-500">{{ location(selected) }} · {{ categoryLabel(selected.category) }}</p>
                        <div v-if="selected.room_blocked" class="mt-5 flex gap-3 rounded-xl border border-red-200 bg-red-50 p-3"><AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-red-600" /><div><p class="text-sm font-semibold text-red-900">{{ t('maintenance.roomIsBlocked') }}</p><p class="mt-0.5 text-xs text-red-700">{{ t('maintenance.blockRoomHint') }}</p></div></div>
                        <div class="mt-5 grid grid-cols-2 gap-3"><div class="rounded-xl border border-neutral-200 p-3"><span class="text-[10px] font-bold uppercase tracking-wider text-neutral-400">{{ t('maintenance.assignee') }}</span><p class="mt-1.5 flex items-center gap-1.5 text-sm font-semibold"><UserRound class="h-4 w-4 text-neutral-400" />{{ selected.assignee?.name || t('maintenance.unassigned') }}</p></div><div class="rounded-xl border border-neutral-200 p-3"><span class="text-[10px] font-bold uppercase tracking-wider text-neutral-400">SLA</span><p class="mt-1.5 flex items-center gap-1.5 text-sm font-semibold"><Clock3 class="h-4 w-4 text-neutral-400" />{{ sla(selected) }}</p></div></div>
                        <div class="mt-6"><h3 class="text-xs font-bold uppercase tracking-wider text-neutral-400">{{ t('maintenance.report') }}</h3><p class="mt-2 whitespace-pre-line text-sm leading-6 text-neutral-700">{{ selected.description || '—' }}</p></div>
                        <div v-if="selected.asset_name || selected.asset_code" class="mt-6 rounded-xl border border-neutral-200 p-4"><span class="text-[10px] font-bold uppercase tracking-wider text-neutral-400">{{ t('maintenance.asset') }}</span><p class="mt-1 text-sm font-semibold text-neutral-900">{{ selected.asset_name || '—' }}</p><p class="text-xs text-neutral-500">{{ selected.asset_code }}</p></div>

                        <div v-if="permissions.update" class="mt-6 rounded-xl border border-neutral-200 p-4"><label class="text-xs font-bold uppercase tracking-wider text-neutral-400">{{ t('maintenance.assignee') }}</label><div class="mt-2 flex gap-2"><select v-model="assignedTo" class="min-w-0 flex-1 rounded-lg border-neutral-200 text-sm"><option value="">{{ t('maintenance.chooseTechnician') }}</option><option v-for="person in staff" :key="person.id" :value="person.id">{{ person.name }}</option></select><button class="rounded-lg bg-neutral-900 px-3 text-sm font-semibold text-white disabled:opacity-40" :disabled="!assignedTo" @click="assignIssue">{{ t('maintenance.assign') }}</button></div></div>

                        <div class="mt-6"><h3 class="text-xs font-bold uppercase tracking-wider text-neutral-400">{{ t('maintenance.attachments') }}</h3><div class="mt-2 space-y-2"><a v-for="file in selected.attachments" :key="file.id" :href="file.url" class="flex items-center gap-2 rounded-lg border border-neutral-200 p-3 text-sm font-medium text-emerald-800"><Image class="h-4 w-4" />{{ file.name }}</a><p v-if="!selected.attachments.length" class="text-sm text-neutral-400">{{ t('maintenance.noAttachments') }}</p></div><div v-if="permissions.update" class="mt-3 flex gap-2"><input type="file" class="min-w-0 flex-1 text-xs" @change="uploadFile = $event.target.files[0]" /><button class="rounded-lg border border-neutral-300 px-3 py-2 text-xs font-semibold disabled:opacity-40" :disabled="!uploadFile" @click="uploadAttachment"><FileUp class="inline h-4 w-4" /> {{ t('maintenance.upload') }}</button></div></div>

                        <div class="mt-6"><h3 class="text-xs font-bold uppercase tracking-wider text-neutral-400">{{ t('maintenance.timeline') }}</h3><div v-for="event in selected.events" :key="event.id" class="mt-3 border-l-2 border-emerald-100 pl-4"><p class="text-sm font-semibold text-neutral-800">{{ event.to_status ? statusLabel(event.to_status) : event.type }}</p><p class="mt-0.5 text-xs text-neutral-500">{{ event.user?.name || '—' }} · {{ formatDate(event.created_at) }}<span v-if="event.note"> · {{ event.note }}</span></p></div></div>

                        <div v-if="permissions.update" class="mt-8 space-y-3"><textarea v-if="nextStatus(selected.status)" v-model="statusNote" rows="2" class="w-full rounded-lg border-neutral-200 text-sm" :placeholder="selected.status === 'in_progress' ? t('maintenance.resolutionRequired') : t('maintenance.actionNote')"></textarea><div class="grid gap-2 sm:grid-cols-2"><button v-if="selected.room" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-neutral-300 text-sm font-semibold" @click="toggleRoomBlock">{{ selected.room_blocked ? t('maintenance.releaseRoom') : t('maintenance.blockRoom') }}</button><button v-if="nextStatus(selected.status)" :disabled="selected.status === 'in_progress' && !statusNote.trim()" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-emerald-900 text-sm font-semibold text-white disabled:opacity-40" @click="advanceStatus"><Hammer class="h-4 w-4" />{{ t(`maintenance.${nextAction(selected.status)}`) }}</button></div></div>
                    </div>
                </aside>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="reportOpen" class="fixed inset-0 z-50 flex items-end justify-center bg-neutral-950/50 sm:items-center sm:p-4" @click.self="reportOpen = false">
                <form class="max-h-[95vh] w-full max-w-2xl overflow-y-auto rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl" @submit.prevent="submitReport">
                    <div class="sticky top-0 z-10 flex items-center justify-between border-b border-neutral-200 bg-white p-5"><div><h2 class="text-lg font-bold text-neutral-950">{{ t('maintenance.reportIssue') }}</h2><p class="mt-1 text-sm text-neutral-500">{{ t('maintenance.reportHint') }}</p></div><button type="button" class="rounded-lg p-2 text-neutral-400" @click="reportOpen = false"><X class="h-5 w-5" /></button></div>
                    <div class="grid gap-4 p-5 sm:grid-cols-2">
                        <label class="text-sm font-semibold text-neutral-700">{{ t('maintenance.roomLocation') }}<select v-model="reportForm.room_id" class="mt-1.5 w-full rounded-lg border-neutral-200 text-sm"><option value="">{{ t('maintenance.commonArea') }}</option><option v-for="room in rooms" :key="room.id" :value="room.id">{{ t('maintenance.room') }} {{ room.room_number }}</option></select></label>
                        <label class="text-sm font-semibold text-neutral-700">{{ t('maintenance.priority') }}<select v-model="reportForm.priority" class="mt-1.5 w-full rounded-lg border-neutral-200 text-sm"><option v-for="p in ['low','medium','high','critical']" :key="p" :value="p">{{ t(`maintenance.${p}`) }}</option></select></label>
                        <label class="text-sm font-semibold text-neutral-700">{{ t('maintenance.category') }}<select v-model="reportForm.category" class="mt-1.5 w-full rounded-lg border-neutral-200 text-sm"><option v-for="c in ['electronics','climate','electrical','plumbing','furniture','safety','other']" :key="c" :value="c">{{ categoryLabel(c) }}</option></select></label>
                        <label class="text-sm font-semibold text-neutral-700">{{ t('maintenance.type') }}<select v-model="reportForm.kind" class="mt-1.5 w-full rounded-lg border-neutral-200 text-sm"><option value="corrective">{{ t('maintenance.corrective') }}</option><option value="preventive">{{ t('maintenance.preventive') }}</option></select></label>
                        <label class="sm:col-span-2 text-sm font-semibold text-neutral-700">{{ t('maintenance.problem') }}<input v-model="reportForm.title" required class="mt-1.5 w-full rounded-lg border-neutral-200 text-sm" :placeholder="t('maintenance.problemPlaceholder')" /><span v-if="reportForm.errors.title" class="mt-1 block text-xs text-red-600">{{ reportForm.errors.title }}</span></label>
                        <label class="sm:col-span-2 text-sm font-semibold text-neutral-700">{{ t('maintenance.description') }}<textarea v-model="reportForm.description" rows="3" class="mt-1.5 w-full rounded-lg border-neutral-200 text-sm" :placeholder="t('maintenance.descriptionPlaceholder')"></textarea></label>
                        <label class="text-sm font-semibold text-neutral-700">{{ t('maintenance.asset') }}<input v-model="reportForm.asset_name" class="mt-1.5 w-full rounded-lg border-neutral-200 text-sm" /></label><label class="text-sm font-semibold text-neutral-700">{{ t('maintenance.assetCode') }}<input v-model="reportForm.asset_code" class="mt-1.5 w-full rounded-lg border-neutral-200 text-sm" /></label>
                        <template v-if="reportForm.kind === 'preventive'"><label class="text-sm font-semibold text-neutral-700">{{ t('maintenance.scheduledFor') }}<input v-model="reportForm.scheduled_for" type="datetime-local" class="mt-1.5 w-full rounded-lg border-neutral-200 text-sm" /></label><label class="text-sm font-semibold text-neutral-700">{{ t('maintenance.recurrenceDays') }}<input v-model="reportForm.recurrence_days" type="number" min="1" class="mt-1.5 w-full rounded-lg border-neutral-200 text-sm" /></label></template>
                        <label class="sm:col-span-2 flex h-20 cursor-pointer items-center justify-center rounded-lg border border-dashed border-neutral-300 text-sm text-neutral-500"><Image class="mr-2 h-5 w-5" />{{ t('maintenance.addPhoto') }}<input multiple type="file" accept="image/*,video/*,.pdf" class="hidden" @change="reportForm.attachments = Array.from($event.target.files)" /></label>
                        <label v-if="reportForm.room_id" class="sm:col-span-2 flex items-start gap-3 rounded-lg bg-red-50 p-3"><input v-model="reportForm.block_room" type="checkbox" class="mt-0.5 rounded border-red-300 text-red-600" /><span><strong class="block text-sm text-red-900">{{ t('maintenance.blockRoom') }}</strong><span class="text-xs text-red-700">{{ t('maintenance.blockRoomHint') }}</span></span></label>
                    </div>
                    <div class="sticky bottom-0 flex justify-end gap-2 border-t border-neutral-200 bg-white p-5"><button type="button" class="h-10 rounded-lg px-4 text-sm font-semibold text-neutral-600" @click="reportOpen = false">{{ t('maintenance.cancel') }}</button><button type="submit" :disabled="reportForm.processing" class="inline-flex h-10 items-center gap-2 rounded-lg bg-emerald-800 px-4 text-sm font-semibold text-white disabled:opacity-50"><Check class="h-4 w-4" />{{ t('maintenance.submitReport') }}</button></div>
                </form>
            </div>
        </Teleport>
    </AppLayout>
</template>
