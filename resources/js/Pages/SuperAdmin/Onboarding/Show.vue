<script setup>
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Check, CheckCircle2, Download, ExternalLink, FileText, LoaderCircle, Rocket, Save, Trash2, Upload, UserRound, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps({ tenant: Object, onboarding: Object, documents: Array, staff: Array });
const activeStepKey = ref(props.onboarding.steps.find((step) => step.status !== 'done')?.key || props.onboarding.steps[0]?.key);
const activeStep = computed(() => props.onboarding.steps.find((step) => step.key === activeStepKey.value) || props.onboarding.steps[0]);
const busyTask = ref(null);
const openingTask = ref(null);
const showSettings = ref(false);
const showUpload = ref(false);
const fileInput = ref(null);

const masterForm = useForm({ assigned_to: props.onboarding.assignee?.id || null, due_date: props.onboarding.due_date || '', notes: props.onboarding.notes || '' });
const stepForm = useForm({ status: activeStep.value.status === 'waiting_client' ? 'waiting_client' : 'in_progress', assigned_to: activeStep.value.assigned_to || null, due_date: activeStep.value.due_date || '', notes: activeStep.value.notes || '' });
const uploadForm = useForm({ step_key: activeStepKey.value, document: null });

watch(activeStepKey, () => {
    stepForm.status = activeStep.value.status === 'waiting_client' ? 'waiting_client' : 'in_progress';
    stepForm.assigned_to = activeStep.value.assigned_to || null;
    stepForm.due_date = activeStep.value.due_date || '';
    stepForm.notes = activeStep.value.notes || '';
    uploadForm.step_key = activeStepKey.value;
});

const statusLabel = (value) => ({ not_started: 'Pa filluar', in_progress: 'Në proces', ready: 'Gati', completed: 'Përfunduar' }[value] || value);
const stepMeta = (step) => step.status === 'done' ? 'Përfunduar' : step.status === 'waiting_client' ? 'Në pritje të klientit' : `${step.completed_tasks} nga ${step.total_tasks} detyra`;
const progressStyle = computed(() => ({ background: `conic-gradient(#1d765f ${props.onboarding.progress}%, #e9efec ${props.onboarding.progress}%)` }));
const stepDocuments = computed(() => props.documents.filter((document) => !document.step_key || document.step_key === activeStepKey.value));

function toggleTask(task) {
    busyTask.value = task.key;
    router.patch(`/super-admin/onboarding/${props.tenant.id}/steps/${activeStepKey.value}/tasks/${task.key}`, { completed: !task.completed }, { preserveScroll: true, onFinish: () => { busyTask.value = null; } });
}
function openTask(task) {
    if (!task.action) return;
    openingTask.value = task.key;

    if (task.action.type === 'control') {
        router.get(`/super-admin/tenants/${props.tenant.id}`, { config: task.action.tab }, {
            onFinish: () => { openingTask.value = null; },
        });
        return;
    }

    router.post(`/super-admin/tenants/${props.tenant.id}/switch`, { redirect: task.action.path }, {
        onFinish: () => { openingTask.value = null; },
    });
}
function saveMaster() { masterForm.patch(`/super-admin/onboarding/${props.tenant.id}`, { preserveScroll: true, onSuccess: () => { showSettings.value = false; } }); }
function saveStep() { stepForm.patch(`/super-admin/onboarding/${props.tenant.id}/steps/${activeStepKey.value}`, { preserveScroll: true }); }
function upload() {
    if (!fileInput.value?.files?.[0]) return;
    uploadForm.document = fileInput.value.files[0];
    uploadForm.post(`/super-admin/onboarding/${props.tenant.id}/documents`, { forceFormData: true, preserveScroll: true, onSuccess: () => { showUpload.value = false; uploadForm.reset('document'); } });
}
function removeDocument(document) { if (window.confirm(`Të hiqet ${document.name}?`)) router.delete(`/super-admin/onboarding/${props.tenant.id}/documents/${document.id}`, { preserveScroll: true }); }
function activate() { if (window.confirm('Ta përfundojmë onboarding-un dhe ta shënojmë hotelin gati për dorëzim?')) router.post(`/super-admin/onboarding/${props.tenant.id}/activate`, {}, { preserveScroll: true }); }
</script>

<template>
    <SuperAdminLayout :title="`Onboarding · ${tenant.name}`">
        <div class="sa-page">
            <div class="sa-breadcrumb"><Link href="/super-admin" class="text-inherit no-underline">Control Panel</Link><span class="mx-2">/</span><Link href="/super-admin/onboarding" class="text-inherit no-underline">Onboarding</Link><span class="mx-2">/</span><span>{{ tenant.name }}</span></div>
            <header class="mb-4 mt-2 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3"><span class="grid h-11 w-11 place-items-center rounded-xl bg-emerald-100 text-xs font-bold text-emerald-900">{{ tenant.name.split(' ').map((part) => part[0]).join('').slice(0, 2) }}</span><div><div class="flex flex-wrap items-center gap-2"><h1 class="!m-0 !text-[27px] !font-semibold !tracking-[-.035em]">Onboarding · {{ tenant.name }}</h1><span class="rounded-full px-2.5 py-1 text-[10px] font-bold" :class="onboarding.status === 'completed' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ statusLabel(onboarding.status) }}</span></div><p class="mt-1 text-xs text-neutral-500">Konfigurimi i plotë para dorëzimit te klienti.</p></div></div>
                <div class="flex gap-2"><Link :href="`/super-admin/tenants/${tenant.id}`" class="sa-button sa-button-secondary">Profili i hotelit</Link><button class="sa-button sa-button-primary" @click="showSettings = true"><Save class="h-4 w-4" />Menaxho</button></div>
            </header>

            <section class="sa-card mb-3 grid overflow-hidden lg:grid-cols-[1.3fr_repeat(3,.6fr)]">
                <div class="flex items-center gap-3 p-4"><span class="relative grid h-[50px] w-[50px] shrink-0 place-items-center rounded-full" :style="progressStyle"><span class="absolute inset-[6px] rounded-full bg-white"/><strong class="relative text-[11px]">{{ onboarding.progress }}%</strong></span><div><strong class="block text-sm">{{ onboarding.steps.filter((step) => step.status === 'done').length }} nga {{ onboarding.steps.length }} hapa të përfunduar</strong><span class="mt-1 block text-[11px] text-neutral-500">Përditësuar {{ new Date().toLocaleDateString('sq-AL') }}</span></div></div>
                <div class="border-t border-neutral-200 p-4 lg:border-l lg:border-t-0"><span class="text-[10px] text-neutral-500">Përgjegjësi</span><strong class="mt-1.5 block text-xs">{{ onboarding.assignee?.name || 'Pa caktuar' }}</strong></div>
                <div class="border-t border-neutral-200 p-4 lg:border-l lg:border-t-0"><span class="text-[10px] text-neutral-500">Afati</span><strong class="mt-1.5 block text-xs">{{ onboarding.due_date || 'Pa afat' }}</strong></div>
                <div class="border-t border-neutral-200 p-4 lg:border-l lg:border-t-0"><span class="text-[10px] text-neutral-500">Monedha / timezone</span><strong class="mt-1.5 block text-xs">{{ tenant.currency }} · {{ tenant.timezone }}</strong></div>
            </section>

            <div class="grid gap-3 lg:grid-cols-[290px_minmax(0,1fr)]">
                <aside class="sa-card self-start"><div class="sa-card-header"><div><h2 class="sa-card-title">Hapat e onboarding</h2><p class="sa-card-subtitle">Plotësohen sipas rendit.</p></div></div><div class="space-y-1.5 p-2">
                    <button v-for="(step, index) in onboarding.steps" :key="step.key" class="grid min-h-[60px] w-full grid-cols-[32px_1fr_8px] items-center gap-2 rounded-[10px] border p-2 text-left transition" :class="activeStepKey === step.key ? 'border-emerald-200 bg-emerald-50' : 'border-transparent hover:bg-neutral-50'" @click="activeStepKey = step.key"><span class="grid h-8 w-8 place-items-center rounded-lg text-[10px] font-bold" :class="step.status === 'done' ? 'bg-emerald-700 text-white' : 'bg-neutral-100 text-neutral-600'"><Check v-if="step.status === 'done'" class="h-4 w-4" /><template v-else>{{ index + 1 }}</template></span><span><strong class="block text-[11px]">{{ step.title }}</strong><small class="mt-0.5 block text-[9.5px] text-neutral-500">{{ stepMeta(step) }}</small></span><span class="h-1.5 w-1.5 rounded-full" :class="step.status === 'done' ? 'bg-emerald-600' : step.status === 'waiting_client' ? 'bg-amber-500' : 'bg-neutral-300'" /></button>
                </div></aside>

                <div class="grid gap-3 xl:grid-cols-[minmax(0,1fr)_275px]">
                    <div class="space-y-3">
                        <section class="sa-card"><div class="border-b border-neutral-200 p-4"><p class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-[.08em] text-emerald-700"><CheckCircle2 class="h-4 w-4" />Hapi {{ onboarding.steps.findIndex((step) => step.key === activeStepKey) + 1 }} nga {{ onboarding.steps.length }}</p><h2 class="mt-2 text-lg font-semibold">{{ activeStep.title }}</h2><p class="mt-1 text-[11px] text-neutral-500">{{ activeStep.description }}</p></div><div class="divide-y divide-neutral-100">
                            <div v-for="task in activeStep.tasks" :key="task.key" class="grid min-h-[64px] grid-cols-[34px_1fr_auto] items-center gap-3 px-4 py-2.5"><button class="grid h-[30px] w-[30px] place-items-center rounded-lg border" :class="task.completed ? 'border-emerald-700 bg-emerald-700 text-white' : 'border-neutral-300 text-transparent hover:border-emerald-400'" :disabled="busyTask === task.key" @click="toggleTask(task)"><LoaderCircle v-if="busyTask === task.key" class="h-4 w-4 animate-spin text-emerald-700" /><Check v-else class="h-4 w-4" /></button><div><strong class="block text-[11.5px]">{{ task.title }}</strong><span class="mt-0.5 block text-[10px] text-neutral-500">{{ task.description }}</span></div><div class="flex items-center gap-1.5"><button type="button" class="inline-flex items-center gap-1 rounded-lg border border-neutral-200 px-2.5 py-1.5 text-[10px] font-bold text-neutral-600 hover:border-emerald-200 hover:text-emerald-700" :disabled="openingTask === task.key" @click="openTask(task)"><LoaderCircle v-if="openingTask === task.key" class="h-3 w-3 animate-spin" /><ExternalLink v-else class="h-3 w-3" />Hap</button><button type="button" class="rounded-lg border border-neutral-200 px-2.5 py-1.5 text-[10px] font-bold" :class="task.completed ? 'text-emerald-700' : 'text-neutral-600'" @click="toggleTask(task)">{{ task.completed ? 'Rihap' : 'Përfundo' }}</button></div></div>
                        </div></section>

                        <section class="sa-card"><div class="sa-card-header"><div><h2 class="sa-card-title">Shënimet e hapit</h2><p class="sa-card-subtitle">Vetëm për stafin e Lora PMS.</p></div></div><form class="space-y-3 p-4" @submit.prevent="saveStep"><div class="grid gap-3 sm:grid-cols-3"><label>Statusi<select v-model="stepForm.status" class="mt-1 w-full"><option value="in_progress">Në proces</option><option value="waiting_client">Në pritje të klientit</option><option value="pending">Pa filluar</option></select></label><label>Përgjegjësi<select v-model="stepForm.assigned_to" class="mt-1 w-full"><option :value="null">Përdor përgjegjësin kryesor</option><option v-for="person in staff" :key="person.id" :value="person.id">{{ person.name }}</option></select></label><label>Afati<input v-model="stepForm.due_date" type="date" class="mt-1 w-full"></label></div><label class="block">Shënime<textarea v-model="stepForm.notes" class="mt-1 w-full" placeholder="Vendimet, pyetjet ose çfarë presim nga klienti..." /></label><div class="flex justify-end"><button class="sa-button sa-button-primary" :disabled="stepForm.processing"><Save class="h-4 w-4" />Ruaj hapin</button></div></form></section>

                        <section class="sa-card flex flex-col gap-3 bg-gradient-to-r from-emerald-50/70 to-white p-4 sm:flex-row sm:items-center sm:justify-between"><div><strong class="text-xs">{{ onboarding.progress === 100 ? 'Hoteli është gati për aktivizim' : 'Aktivizimi final është i bllokuar' }}</strong><p class="mt-1 text-[10px] text-neutral-500">Përfundo të gjitha detyrat dhe testet kryesore.</p></div><button class="sa-button sa-button-primary" :disabled="onboarding.progress !== 100 || onboarding.status === 'completed'" @click="activate"><Rocket class="h-4 w-4" />{{ onboarding.status === 'completed' ? 'I aktivizuar' : 'Aktivizo hotelin' }}</button></section>
                    </div>

                    <aside class="space-y-3">
                        <section class="sa-card"><div class="sa-card-header"><div><h2 class="sa-card-title">Pronësia</h2><p class="sa-card-subtitle">Përgjegjësia kryesore.</p></div><button class="text-[10px] font-bold text-emerald-700" @click="showSettings = true">Ndrysho</button></div><div class="divide-y divide-neutral-100 px-4"><div class="flex items-center justify-between gap-3 py-3"><span class="text-[10px] text-neutral-500">Përgjegjësi</span><span class="inline-flex items-center gap-1.5 text-[10.5px] font-semibold"><UserRound class="h-3.5 w-3.5 text-neutral-400" />{{ onboarding.assignee?.name || 'Pa caktuar' }}</span></div><div class="flex items-center justify-between gap-3 py-3"><span class="text-[10px] text-neutral-500">Afati</span><strong class="text-[10.5px]">{{ onboarding.due_date || 'Pa afat' }}</strong></div></div><div v-if="activeStep.status === 'waiting_client'" class="m-3 rounded-[10px] border border-amber-200 bg-amber-50 p-3"><strong class="text-[10.5px] text-amber-800">Në pritje të klientit</strong><p class="mt-1 text-[9.5px] text-amber-700">{{ activeStep.notes || 'Shto çfarë duhet të konfirmojë klienti.' }}</p></div></section>
                        <section class="sa-card"><div class="sa-card-header"><div><h2 class="sa-card-title">Dokumentet</h2><p class="sa-card-subtitle">Materialet e këtij hapi.</p></div><button class="sa-button sa-button-secondary !min-h-8 !px-2" @click="showUpload = true"><Upload class="h-3.5 w-3.5" />Ngarko</button></div><div v-if="stepDocuments.length" class="divide-y divide-neutral-100 px-4"><div v-for="document in stepDocuments" :key="document.id" class="flex items-center gap-2 py-3"><span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-blue-50 text-blue-600"><FileText class="h-4 w-4" /></span><div class="min-w-0 flex-1"><a :href="document.download_url" class="block truncate text-[10.5px] font-semibold text-neutral-800 no-underline hover:text-emerald-700">{{ document.name }}</a><span class="text-[9px] text-neutral-400">{{ Math.max(1, Math.round(document.size / 1024)) }} KB · {{ document.uploaded_by }}</span></div><a :href="document.download_url" class="text-neutral-400 hover:text-emerald-700"><Download class="h-3.5 w-3.5" /></a><button class="text-neutral-300 hover:text-red-600" @click="removeDocument(document)"><Trash2 class="h-3.5 w-3.5" /></button></div></div><p v-else class="px-4 py-7 text-center text-[10px] text-neutral-400">Pa dokumente për këtë hap.</p></section>
                        <section class="sa-card"><div class="sa-card-header"><div><h2 class="sa-card-title">Kontroll i shpejtë</h2><p class="sa-card-subtitle">Konfigurimi i tenantit.</p></div></div><div class="divide-y divide-neutral-100 px-4"><div class="flex justify-between py-3 text-[10.5px]"><span class="text-neutral-500">Monedha bazë</span><strong>{{ tenant.currency }}</strong></div><div class="flex justify-between py-3 text-[10.5px]"><span class="text-neutral-500">Timezone</span><strong>{{ tenant.timezone }}</strong></div><div class="flex justify-between py-3 text-[10.5px]"><span class="text-neutral-500">Domain</span><strong :class="tenant.primary_domain ? 'text-emerald-700' : 'text-amber-700'">{{ tenant.primary_domain || 'Mungon' }}</strong></div></div></section>
                    </aside>
                </div>
            </div>
        </div>

        <div v-if="showSettings" class="fixed inset-0 z-50 flex justify-end bg-neutral-950/45 backdrop-blur-[2px]" @click.self="showSettings = false"><section class="flex h-full w-full max-w-[620px] flex-col bg-white shadow-2xl"><header class="flex h-[70px] items-center justify-between border-b border-neutral-200 px-5"><div><h2 class="text-base font-semibold">Menaxho onboarding-un</h2><p class="mt-1 text-[11px] text-neutral-500">Përgjegjësi, afati dhe shënimet e përgjithshme.</p></div><button class="rounded-lg p-2 text-neutral-500 hover:bg-neutral-100" @click="showSettings = false"><X class="h-5 w-5" /></button></header><form class="flex-1 space-y-4 overflow-auto p-5" @submit.prevent="saveMaster"><div class="grid gap-3 sm:grid-cols-2"><label>Përgjegjësi<select v-model="masterForm.assigned_to" class="mt-1 w-full"><option :value="null">Pa caktuar</option><option v-for="person in staff" :key="person.id" :value="person.id">{{ person.name }}</option></select></label><label>Afati<input v-model="masterForm.due_date" type="date" class="mt-1 w-full"></label></div><label class="block">Shënime të përgjithshme<textarea v-model="masterForm.notes" class="mt-1 w-full" placeholder="Konteksti i klientit dhe marrëveshjet kryesore..." /></label></form><footer class="flex justify-end gap-2 border-t border-neutral-200 p-4"><button class="sa-button sa-button-secondary" @click="showSettings = false">Anulo</button><button class="sa-button sa-button-primary" :disabled="masterForm.processing" @click="saveMaster"><Save class="h-4 w-4" />Ruaj</button></footer></section></div>
        <div v-if="showUpload" class="fixed inset-0 z-50 grid place-items-center bg-neutral-950/45 p-4" @click.self="showUpload = false"><form class="w-full max-w-lg rounded-2xl bg-white shadow-2xl" @submit.prevent="upload"><header class="flex items-center justify-between border-b border-neutral-200 p-4"><div><h2 class="text-sm font-semibold">Ngarko dokument</h2><p class="mt-1 text-[10px] text-neutral-500">{{ activeStep.title }} · maksimumi 10 MB.</p></div><button type="button" class="p-2 text-neutral-500" @click="showUpload = false"><X class="h-5 w-5" /></button></header><div class="p-4"><label class="flex min-h-32 cursor-pointer flex-col items-center justify-center rounded-xl border border-dashed border-neutral-300 bg-neutral-50 text-center hover:border-emerald-300 hover:bg-emerald-50/40"><Upload class="mb-2 h-6 w-6 text-neutral-400" /><strong class="text-xs">Zgjidh dokumentin</strong><span class="mt-1 text-[10px] text-neutral-500">PDF, Excel, Word ose imazh</span><input ref="fileInput" type="file" class="hidden" accept=".pdf,.xls,.xlsx,.csv,.doc,.docx,.png,.jpg,.jpeg,.webp"></label><p v-if="uploadForm.errors.document" class="mt-2 text-[10px] text-red-600">{{ uploadForm.errors.document }}</p></div><footer class="flex justify-end gap-2 border-t border-neutral-200 p-4"><button type="button" class="sa-button sa-button-secondary" @click="showUpload = false">Anulo</button><button class="sa-button sa-button-primary" :disabled="uploadForm.processing"><Upload class="h-4 w-4" />Ngarko</button></footer></form></div>
    </SuperAdminLayout>
</template>
