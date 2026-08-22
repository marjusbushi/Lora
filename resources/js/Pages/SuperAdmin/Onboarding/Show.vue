<script setup>
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import DatePicker from '@/Components/UI/DatePicker.vue';
import { translate } from '@/i18n';
import { Check, ExternalLink, FileText, LoaderCircle, Rocket, Save, Trash2, Upload, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps({ tenant: Object, onboarding: Object, documents: Array, staff: Array });
const activeStepKey = ref(props.onboarding.steps.find((step) => step.status !== 'done')?.key || props.onboarding.steps[0]?.key);
const activeStep = computed(() => props.onboarding.steps.find((step) => step.key === activeStepKey.value) || props.onboarding.steps[0]);
const activeStepIndex = computed(() => props.onboarding.steps.findIndex((step) => step.key === activeStepKey.value));
const busyTask = ref(null);
const openingTask = ref(null);
const savingStep = ref(false);
const showSettings = ref(false);
const showUpload = ref(false);
const fileInput = ref(null);

const masterForm = useForm({ assigned_to: props.onboarding.assignee?.id || null, due_date: props.onboarding.due_date || '', notes: props.onboarding.notes || '' });
const uploadForm = useForm({ step_key: activeStepKey.value, document: null });

// Detajet e hapit (statusi/përgjegjësi/afati) ruhen VETË sapo ndryshohen — një
// kontroll = një veprim, pa buton "Ruaj" të dytë që dyfishon atë të shënimeve.
// Çdo ruajtje dërgon GJITHË gjendjen e hapit, jo vetëm fushën e ndryshuar:
// updateStep bën read-modify-write mbi JSON-in e hapave dhe një vizitë Inertia
// e ndërprerë nga pasardhësja mund të humbte fushën e saj (gjetje Codex P2,
// PR #571) — me payload të plotë, kërkesa e fundit mbart gjithmonë çdo gjë.
const stepStatus = ref(null);
const stepAssignee = ref(null);
const stepDue = ref('');
const stepNotes = ref('');
let syncing = false;

function syncStepFields() {
    syncing = true;
    stepStatus.value = activeStep.value.status === 'waiting_client' ? 'waiting_client' : 'in_progress';
    stepAssignee.value = activeStep.value.assigned_to || null;
    stepDue.value = activeStep.value.due_date || '';
    stepNotes.value = activeStep.value.notes || '';
    uploadForm.step_key = activeStepKey.value;
    // Flamuri hiqet pas ciklit që watcher-ët e mëposhtëm të mos e marrin
    // sinkronizimin e ndërrimit të hapit për ndryshim të userit.
    setTimeout(() => { syncing = false; });
}
syncStepFields();
watch(activeStepKey, syncStepFields);

function saveStep() {
    if (syncing) return;
    savingStep.value = true;
    router.patch(`/super-admin/onboarding/${props.tenant.id}/steps/${activeStepKey.value}`, {
        status: stepStatus.value,
        assigned_to: stepAssignee.value,
        due_date: stepDue.value || null,
        notes: stepNotes.value,
    }, {
        preserveScroll: true,
        onFinish: () => { savingStep.value = false; },
    });
}
watch(stepStatus, saveStep);
watch(stepAssignee, saveStep);
watch(stepDue, saveStep);

const statusLabel = (value) => ({
    not_started: translate('superAdmin.onboarding.statusNotStarted'),
    in_progress: translate('superAdmin.onboarding.statusInProgress'),
    ready: translate('superAdmin.onboarding.statusReady'),
    completed: translate('superAdmin.onboarding.statusCompleted'),
}[value] || value);
const stepMeta = (step) => step.status === 'done'
    ? translate('superAdmin.onboarding.statusCompleted')
    : step.status === 'waiting_client'
        ? translate('superAdmin.onboarding.waitingClient')
        : translate('superAdmin.onboarding.tasksProgress', { completed: step.completed_tasks, total: step.total_tasks });

const initials = computed(() => props.tenant.name.split(' ').map((part) => part[0]).join('').slice(0, 2));
const stepsDone = computed(() => props.onboarding.steps.filter((step) => step.status === 'done').length);
const tasksTotal = computed(() => props.onboarding.steps.reduce((sum, step) => sum + step.total_tasks, 0));
const tasksDone = computed(() => props.onboarding.steps.reduce((sum, step) => sum + step.completed_tasks, 0));
const stepDocuments = computed(() => props.documents.filter((document) => !document.step_key || document.step_key === activeStepKey.value));

function toggleTask(task) {
    busyTask.value = task.key;
    router.patch(`/super-admin/onboarding/${props.tenant.id}/steps/${activeStepKey.value}/tasks/${task.key}`, { completed: !task.completed }, { preserveScroll: true, onFinish: () => { busyTask.value = null; } });
}
function openTask(task) {
    if (!task.action) return;

    // Every "Hap" opens a NEW tab so the onboarding checklist stays put.
    if (task.action.type === 'fiscal_onboarding') {
        window.open(`/super-admin/onboarding/${props.tenant.id}/fiscalization`, '_blank', 'noopener');
        return;
    }

    if (task.action.type === 'control') {
        window.open(`/super-admin/tenants/${props.tenant.id}?config=${encodeURIComponent(task.action.tab)}`, '_blank', 'noopener');
        return;
    }

    // Tenant deep-link: the handoff token is single-use and host-bound, so
    // open the tab synchronously (popup blockers allow it inside the click)
    // and let THAT tab consume the URL the server hands back.
    openingTask.value = task.key;
    const tab = window.open('about:blank', '_blank');
    window.axios.post(`/super-admin/tenants/${props.tenant.id}/switch`, { redirect: task.action.path })
        .then(({ data }) => {
            if (data?.url && tab) {
                tab.location.href = data.url;
            } else {
                // A blocked popup or an empty reply must not fail in silence.
                tab?.close();
                window.alert(tab ? translate('superAdmin.onboarding.openFailedNoUrl') : translate('superAdmin.onboarding.popupBlocked'));
            }
        })
        .catch((error) => {
            // The tab used to just close with no explanation ("it just closed").
            tab?.close();
            const message = error?.response?.data?.message
                || (error?.response?.status === 419 ? translate('superAdmin.onboarding.sessionExpired') : null)
                || translate('superAdmin.onboarding.openFailed');
            window.alert(message);
        })
        .finally(() => { openingTask.value = null; });
}
function saveMaster() { masterForm.patch(`/super-admin/onboarding/${props.tenant.id}`, { preserveScroll: true, onSuccess: () => { showSettings.value = false; } }); }
function upload() {
    if (!fileInput.value?.files?.[0]) return;
    uploadForm.document = fileInput.value.files[0];
    uploadForm.post(`/super-admin/onboarding/${props.tenant.id}/documents`, { forceFormData: true, preserveScroll: true, onSuccess: () => { showUpload.value = false; uploadForm.reset('document'); } });
}
function removeDocument(document) { if (window.confirm(translate('superAdmin.onboarding.confirmRemoveDocument', { name: document.name }))) router.delete(`/super-admin/onboarding/${props.tenant.id}/documents/${document.id}`, { preserveScroll: true }); }
function activate() { if (window.confirm(translate('superAdmin.onboarding.confirmActivate'))) router.post(`/super-admin/onboarding/${props.tenant.id}/activate`, {}, { preserveScroll: true }); }
</script>

<template>
    <SuperAdminLayout :title="`Onboarding · ${tenant.name}`" immersive>
        <!-- FOCUS MODE (si Web Studio): chrome i vet + rail hapash = NJË kolonë anësore.
             Çdo info shfaqet VETËM NJË herë: identiteti/statusi + chips në chrome,
             progresi vetëm në shiritin e aktivizimit poshtë. -->
        <header class="sticky top-0 z-40 border-b border-neutral-200 bg-white/95 backdrop-blur">
            <div class="mx-auto flex max-w-[1180px] flex-wrap items-center gap-3 px-4 pt-3 sm:px-6">
                <Link href="/super-admin/onboarding" class="whitespace-nowrap text-xs font-bold text-neutral-500 no-underline hover:text-emerald-700">← Onboarding</Link>
                <span class="h-6 w-px bg-neutral-200" />
                <span class="grid h-9 w-9 place-items-center rounded-[10px] bg-gradient-to-br from-emerald-100 to-emerald-200/80 text-[11px] font-bold text-emerald-900 ring-1 ring-inset ring-emerald-200/60">{{ initials }}</span>
                <h1 class="text-[15px] font-semibold tracking-tight">{{ tenant.name }}</h1>
                <span class="rounded-full px-2.5 py-1 text-[10px] font-bold ring-1 ring-inset" :class="onboarding.status === 'completed' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200/60' : 'bg-amber-50 text-amber-700 ring-amber-200/60'">{{ statusLabel(onboarding.status) }}</span>
                <div class="ml-auto flex gap-2">
                    <Link :href="`/super-admin/tenants/${tenant.id}`" class="sa-button sa-button-secondary">{{ $t('superAdmin.onboarding.openHotelPanel') }} <ExternalLink class="h-3.5 w-3.5" /></Link>
                    <button class="sa-button sa-button-primary" @click="showSettings = true"><Save class="h-4 w-4" />{{ $t('superAdmin.tenantShow.manage') }}</button>
                </div>
            </div>
            <div class="mx-auto flex max-w-[1180px] flex-wrap gap-1.5 px-4 pb-3 pt-2 sm:px-6">
                <span class="chrome-chip">{{ $t('superAdmin.onboarding.assignee') }} <b>{{ onboarding.assignee?.name || $t('superAdmin.onboarding.unassigned') }}</b></span>
                <span class="chrome-chip">{{ $t('superAdmin.onboarding.dueDate') }} <b>{{ onboarding.due_date || $t('superAdmin.onboarding.noDueDate') }}</b></span>
                <span class="chrome-chip">Domain <b :class="tenant.primary_domain ? 'text-emerald-700' : 'text-amber-700'">{{ tenant.primary_domain || $t('superAdmin.dynamic.missing') }}</b></span>
                <span class="chrome-chip"><b>{{ tenant.currency }}</b> · {{ tenant.timezone }}</span>
            </div>
        </header>

        <div class="mx-auto max-w-[1180px] px-4 py-5 pb-28 sm:px-6">
            <div class="grid items-start gap-3 lg:grid-cols-[288px_minmax(0,1fr)]">
                <aside class="sa-card self-start lg:sticky lg:top-[118px]">
                    <div class="sa-card-header"><div><h2 class="sa-card-title">{{ $t('superAdmin.onboarding.onboardingSteps') }}</h2><p class="sa-card-subtitle">{{ $t('superAdmin.onboarding.completedInOrder') }}</p></div></div>
                    <div class="space-y-1.5 p-2">
                        <button v-for="(step, index) in onboarding.steps" :key="step.key" class="grid min-h-[60px] w-full grid-cols-[32px_1fr_8px] items-center gap-2 rounded-xl border p-2 text-left transition-all duration-200" :class="activeStepKey === step.key ? 'border-emerald-200 bg-emerald-50 shadow-sm shadow-emerald-900/5' : 'border-transparent hover:bg-neutral-50'" @click="activeStepKey = step.key">
                            <span class="grid h-8 w-8 place-items-center rounded-full text-[10px] font-bold transition-all duration-200" :class="step.status === 'done' ? 'bg-gradient-to-br from-emerald-500 to-emerald-700 text-white shadow-sm shadow-emerald-700/30' : activeStepKey === step.key ? 'bg-white text-emerald-700 ring-2 ring-emerald-300' : 'bg-neutral-100 text-neutral-500'"><Check v-if="step.status === 'done'" class="h-4 w-4" /><template v-else>{{ index + 1 }}</template></span>
                            <span><strong class="block text-[11px]">{{ step.title }}</strong><small class="mt-0.5 block text-[9.5px]" :class="step.status === 'waiting_client' ? 'font-bold text-amber-600' : 'text-neutral-500'">{{ stepMeta(step) }}</small></span>
                            <span class="h-1.5 w-1.5 rounded-full" :class="step.status === 'done' ? 'bg-emerald-600' : step.status === 'waiting_client' ? 'bg-amber-500' : 'bg-neutral-300'" />
                        </button>
                    </div>
                </aside>

                <section class="sa-card overflow-hidden">
                    <div class="border-b border-neutral-200 p-4 sm:p-5">
                        <p class="text-[10px] font-bold uppercase tracking-[.08em] text-emerald-700">{{ $t('superAdmin.onboarding.stepOf', { current: activeStepIndex + 1, total: onboarding.steps.length }) }}</p>
                        <h2 class="mt-1.5 text-lg font-semibold">{{ activeStep.title }}</h2>
                        <p class="mt-1 text-[11px] text-neutral-500">{{ activeStep.description }}</p>
                        <!-- Detajet e hapit — një strip me vetë-ruajtje, jo kartë më vete -->
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <label class="chrome-chip !py-1">{{ $t('superAdmin.compact.status') }}
                                <select v-model="stepStatus" class="border-0 bg-transparent py-0.5 pl-1 pr-6 text-[11px] font-semibold text-neutral-800 focus:ring-0">
                                    <option value="in_progress">{{ $t('superAdmin.onboarding.statusInProgress') }}</option>
                                    <option value="waiting_client">{{ $t('superAdmin.onboarding.waitingClient') }}</option>
                                    <option value="pending">{{ $t('superAdmin.onboarding.statusNotStarted') }}</option>
                                </select>
                            </label>
                            <label class="chrome-chip !py-1">{{ $t('superAdmin.onboarding.assignee') }}
                                <select v-model="stepAssignee" class="border-0 bg-transparent py-0.5 pl-1 pr-6 text-[11px] font-semibold text-neutral-800 focus:ring-0">
                                    <option :value="null">{{ $t('superAdmin.onboarding.useMainAssignee') }}</option>
                                    <option v-for="person in staff" :key="person.id" :value="person.id">{{ person.name }}</option>
                                </select>
                            </label>
                            <label class="flex items-center gap-1.5 text-[10.5px] font-semibold text-neutral-500">{{ $t('superAdmin.onboarding.dueDate') }}
                                <DatePicker v-model="stepDue" class="w-36" />
                            </label>
                            <LoaderCircle v-if="savingStep" class="h-3.5 w-3.5 animate-spin text-emerald-700" />
                        </div>
                    </div>

                    <div v-if="activeStep.status === 'waiting_client'" class="mx-4 mt-4 rounded-xl border border-amber-200 border-l-4 border-l-amber-400 bg-gradient-to-r from-amber-50 to-amber-50/40 p-3 sm:mx-5">
                        <strong class="text-[11px] text-amber-800">⏳ {{ $t('superAdmin.onboarding.waitingClient') }}.</strong>
                        <p class="mt-1 text-[10.5px] text-amber-700">{{ activeStep.notes || $t('superAdmin.onboarding.addClientConfirmation') }}</p>
                    </div>

                    <div class="divide-y divide-neutral-100">
                        <div v-for="task in activeStep.tasks" :key="task.key" class="grid min-h-[64px] grid-cols-[34px_1fr_auto] items-center gap-3 px-4 py-2.5 transition-colors sm:px-5" :class="task.completed ? 'bg-emerald-50/30' : 'hover:bg-neutral-50/60'">
                            <button class="group grid h-[30px] w-[30px] place-items-center rounded-[10px] border-[1.5px] transition-all duration-200" :class="task.completed ? 'border-emerald-600 bg-gradient-to-br from-emerald-500 to-emerald-700 text-white shadow-md shadow-emerald-700/25' : 'border-neutral-300 bg-white text-transparent hover:border-emerald-400 hover:bg-emerald-50 hover:shadow-sm'" :disabled="busyTask === task.key" :title="task.completed ? $t('superAdmin.onboarding.reopen') : $t('superAdmin.onboarding.finish')" @click="toggleTask(task)"><LoaderCircle v-if="busyTask === task.key" class="h-4 w-4 animate-spin text-emerald-700" /><Check v-else class="h-4 w-4 transition-transform duration-200" :class="!task.completed && 'group-hover:scale-90 group-hover:text-emerald-300'" /></button>
                            <div><strong class="block text-[11.5px] transition-colors" :class="task.completed && 'text-neutral-400 line-through decoration-neutral-300'">{{ task.title }}</strong><span class="mt-0.5 block text-[10px] text-neutral-500">{{ task.description }}</span></div>
                            <button type="button" class="inline-flex items-center gap-1 rounded-full border border-neutral-200 px-3 py-1.5 text-[10px] font-bold text-neutral-600 transition-all hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700 hover:shadow-sm" :disabled="openingTask === task.key" @click="openTask(task)"><LoaderCircle v-if="openingTask === task.key" class="h-3 w-3 animate-spin" /><ExternalLink v-else class="h-3 w-3" />{{ $t('superAdmin.compact.open') }}</button>
                        </div>
                    </div>

                    <!-- Dokumentet + shënimet BRENDA hapit — jo karta në kolonë të tretë -->
                    <div class="border-t border-neutral-200 p-4 sm:p-5">
                        <div class="flex items-center justify-between">
                            <h3 class="text-[10px] font-bold uppercase tracking-[.08em] text-neutral-400">{{ $t('superAdmin.onboarding.stepDocuments') }}<template v-if="stepDocuments.length"> ({{ stepDocuments.length }})</template></h3>
                            <button class="sa-button sa-button-secondary !min-h-8 !px-2.5" @click="showUpload = true"><Upload class="h-3.5 w-3.5" />{{ $t('superAdmin.onboarding.upload') }}</button>
                        </div>
                        <div v-if="stepDocuments.length" class="mt-1 divide-y divide-neutral-100">
                            <div v-for="document in stepDocuments" :key="document.id" class="flex items-center gap-2.5 py-2.5">
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-[10px] bg-gradient-to-br from-blue-50 to-blue-100/70 text-blue-600 ring-1 ring-inset ring-blue-100"><FileText class="h-4 w-4" /></span>
                                <div class="min-w-0 flex-1">
                                    <a :href="document.download_url" class="block truncate text-[11px] font-semibold text-neutral-800 no-underline hover:text-emerald-700">{{ document.name }}</a>
                                    <span class="text-[9px] text-neutral-400">{{ Math.max(1, Math.round(document.size / 1024)) }} KB · {{ document.uploaded_by }}</span>
                                </div>
                                <button class="text-neutral-300 hover:text-red-600" :aria-label="$t('superAdmin.compact.close')" @click="removeDocument(document)"><Trash2 class="h-3.5 w-3.5" /></button>
                            </div>
                        </div>
                        <p v-else class="py-5 text-center text-[10px] text-neutral-400">{{ $t('superAdmin.onboarding.noStepDocuments') }}</p>
                    </div>

                    <form class="border-t border-neutral-200 p-4 sm:p-5" @submit.prevent="saveStep">
                        <div class="flex items-center justify-between">
                            <h3 class="text-[10px] font-bold uppercase tracking-[.08em] text-neutral-400">{{ $t('superAdmin.onboarding.stepNotes') }} · {{ $t('superAdmin.onboarding.loraStaffOnly') }}</h3>
                            <button class="sa-button sa-button-secondary !min-h-8 !px-2.5" :disabled="savingStep"><Save class="h-3.5 w-3.5" />{{ $t('superAdmin.onboarding.save') }}</button>
                        </div>
                        <textarea v-model="stepNotes" class="mt-2.5 w-full rounded-[10px] border-neutral-200 bg-neutral-50/60 text-[11.5px]" rows="2" :placeholder="$t('superAdmin.onboarding.stepNotesPlaceholder')" />
                    </form>
                </section>
            </div>
        </div>

        <!-- Aktivizimi — shirit sticky poshtë: i VETMI vend i progresit + veprimi final -->
        <footer class="fixed inset-x-0 bottom-0 z-40 border-t border-neutral-200 bg-white/95 backdrop-blur">
            <div class="mx-auto flex max-w-[1180px] flex-wrap items-center gap-4 px-4 py-3 sm:px-6">
                <div class="min-w-[220px] max-w-[420px] flex-1">
                    <div class="mb-1.5 flex justify-between text-[10.5px] text-neutral-500">
                        <span><b class="text-neutral-800">{{ $t('superAdmin.onboarding.tasksProgress', { completed: tasksDone, total: tasksTotal }) }}</b> · {{ $t('superAdmin.onboarding.stepsCompleted', { completed: stepsDone, total: onboarding.steps.length }) }}</span>
                        <span>{{ onboarding.progress }}%</span>
                    </div>
                    <div class="h-[7px] overflow-hidden rounded-full bg-neutral-100 shadow-inner"><div class="h-full rounded-full bg-gradient-to-r from-emerald-700 via-emerald-600 to-emerald-500 shadow-sm shadow-emerald-600/40 transition-[width] duration-500" :style="{ width: `${onboarding.progress}%` }" /></div>
                </div>
                <span v-if="onboarding.progress !== 100" class="ml-auto text-[10px] text-neutral-400">{{ $t('superAdmin.onboarding.activationHint') }}</span>
                <button class="sa-button sa-button-primary" :class="onboarding.progress === 100 && 'ml-auto'" :disabled="onboarding.progress !== 100 || onboarding.status === 'completed'" @click="activate"><Rocket class="h-4 w-4" />{{ onboarding.status === 'completed' ? $t('superAdmin.onboarding.activated') : $t('superAdmin.onboarding.activateHotel') }}</button>
            </div>
        </footer>

        <div v-if="showSettings" class="fixed inset-0 z-50 flex justify-end bg-neutral-950/45 backdrop-blur-[2px]" @click.self="showSettings = false"><section class="flex h-full w-full max-w-[620px] flex-col bg-white shadow-2xl"><header class="flex h-[70px] items-center justify-between border-b border-neutral-200 px-5"><div><h2 class="text-base font-semibold">{{ $t('superAdmin.onboarding.manageOnboarding') }}</h2><p class="mt-1 text-[11px] text-neutral-500">{{ $t('superAdmin.onboarding.manageOnboardingSubtitle') }}</p></div><button class="rounded-lg p-2 text-neutral-500 hover:bg-neutral-100" @click="showSettings = false"><X class="h-5 w-5" /></button></header><form class="flex-1 space-y-4 overflow-auto p-5" @submit.prevent="saveMaster"><div class="grid gap-3 sm:grid-cols-2"><label>{{ $t('superAdmin.onboarding.assignee') }}<select v-model="masterForm.assigned_to" class="mt-1 w-full"><option :value="null">{{ $t('superAdmin.onboarding.unassigned') }}</option><option v-for="person in staff" :key="person.id" :value="person.id">{{ person.name }}</option></select></label><label>{{ $t('superAdmin.onboarding.dueDate') }}<DatePicker v-model="masterForm.due_date" class="mt-1 w-full" /></label></div><label class="block">{{ $t('superAdmin.onboarding.generalNotes') }}<textarea v-model="masterForm.notes" class="mt-1 w-full" :placeholder="$t('superAdmin.onboarding.generalNotesPlaceholder')" /></label></form><footer class="flex justify-end gap-2 border-t border-neutral-200 p-4"><button class="sa-button sa-button-secondary" @click="showSettings = false">{{ $t('superAdmin.auto.copy008') }}</button><button class="sa-button sa-button-primary" :disabled="masterForm.processing" @click="saveMaster"><Save class="h-4 w-4" />{{ $t('superAdmin.onboarding.save') }}</button></footer></section></div>
        <div v-if="showUpload" class="fixed inset-0 z-50 grid place-items-center bg-neutral-950/45 p-4" @click.self="showUpload = false"><form class="w-full max-w-lg rounded-2xl bg-white shadow-2xl" @submit.prevent="upload"><header class="flex items-center justify-between border-b border-neutral-200 p-4"><div><h2 class="text-sm font-semibold">{{ $t('superAdmin.onboarding.uploadDocument') }}</h2><p class="mt-1 text-[10px] text-neutral-500">{{ activeStep.title }} · {{ $t('superAdmin.onboarding.maxFileSize') }}</p></div><button type="button" class="p-2 text-neutral-500" @click="showUpload = false"><X class="h-5 w-5" /></button></header><div class="p-4"><label class="flex min-h-32 cursor-pointer flex-col items-center justify-center rounded-xl border border-dashed border-neutral-300 bg-neutral-50 text-center hover:border-emerald-300 hover:bg-emerald-50/40"><Upload class="mb-2 h-6 w-6 text-neutral-400" /><strong class="text-xs">{{ $t('superAdmin.onboarding.chooseDocument') }}</strong><span class="mt-1 text-[10px] text-neutral-500">{{ $t('superAdmin.onboarding.acceptedFormats') }}</span><input ref="fileInput" type="file" class="hidden" accept=".pdf,.xls,.xlsx,.csv,.doc,.docx,.png,.jpg,.jpeg,.webp"></label><p v-if="uploadForm.errors.document" class="mt-2 text-[10px] text-red-600">{{ uploadForm.errors.document }}</p></div><footer class="flex justify-end gap-2 border-t border-neutral-200 p-4"><button type="button" class="sa-button sa-button-secondary" @click="showUpload = false">{{ $t('superAdmin.auto.copy008') }}</button><button class="sa-button sa-button-primary" :disabled="uploadForm.processing"><Upload class="h-4 w-4" />{{ $t('superAdmin.onboarding.upload') }}</button></footer></form></div>
    </SuperAdminLayout>
</template>

<style scoped>
.chrome-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    border: 1px solid #e4eae7;
    border-radius: 999px;
    background: linear-gradient(180deg, #fbfdfc, #f4f8f6);
    padding: 4px 11px;
    font-size: 10.5px;
    color: #68766f;
    box-shadow: 0 1px 1.5px rgba(23, 33, 29, 0.03);
}
.chrome-chip b {
    color: #17211d;
    font-weight: 650;
}
</style>
