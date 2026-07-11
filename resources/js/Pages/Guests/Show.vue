<script setup>
import { ref } from 'vue';
import { Link, useForm, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Select from '@/Components/UI/Select.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';
import AuditTimeline from '@/Components/AuditTimeline.vue';
import { FileText, Image as ImageIcon, Trash2, Eye, Upload } from 'lucide-vue-next';
import { countryName } from '@/countries';

const props = defineProps({
    guest: Object,
    stays: Array,
    stats: Object,
    duplicates: Array,
    documents: { type: Array, default: () => [] },
    history: { type: Array, default: () => [] },
});

const toasts = ref(null);
const perms = usePage().props.auth.user?.permissions || [];
const canUpdate = perms.includes('update_guests');

const docTypeLabel = { id_card: 'Karte identiteti', passport: 'Pasaporte', drivers_license: 'Patente', visa: 'Vize', other: 'Tjeter' };
const docTypeOptions = [
    { value: 'passport', label: 'Pasaporte' },
    { value: 'id_card', label: 'Karte identiteti' },
    { value: 'drivers_license', label: 'Patente' },
    { value: 'visa', label: 'Vize' },
    { value: 'other', label: 'Tjeter' },
];

const fileInputClass = 'block w-full text-small text-neutral-500 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-accent-600 file:text-white file:font-medium file:cursor-pointer hover:file:bg-accent-700';
const docInput = ref(null);
const uploadForm = useForm({ type: 'passport', file: null });

function fileSize(b) {
    if (!b) return '';
    const kb = b / 1024;
    return kb < 1024 ? `${Math.round(kb)} KB` : `${(kb / 1024).toFixed(1)} MB`;
}
const isImage = (mime) => (mime || '').startsWith('image/');

function onFile(e) {
    uploadForm.file = e.target.files?.[0] || null;
}
function uploadDoc() {
    if (!uploadForm.file) return;
    uploadForm.post(route('guests.documents.store', props.guest.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            uploadForm.reset('file');
            if (docInput.value) docInput.value.value = '';
            toasts.value?.success('Dokumenti u ngarkua.');
        },
    });
}
function deleteDoc(doc) {
    if (!confirm(`Fshi dokumentin "${doc.original_name}"?`)) return;
    router.delete(route('guests.documents.destroy', doc.id), {
        preserveScroll: true,
        onSuccess: () => toasts.value?.success('Dokumenti u fshi.'),
    });
}

const statusBadge = {
    pending: { variant: 'warning', label: 'Ne pritje' },
    confirmed: { variant: 'info', label: 'Konfirmuar' },
    checked_in: { variant: 'success', label: 'Brenda' },
    checked_out: { variant: 'neutral', label: 'Larguar' },
    cancelled: { variant: 'error', label: 'Anulluar' },
};

function formatDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('sq-AL', { day: '2-digit', month: 'short', year: 'numeric' });
}
</script>

<template>
    <AppLayout>
        <PageHeader
            :title="`${guest.first_name} ${guest.last_name}`"
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Mysafiret', href: route('guests.index') }, { label: `${guest.first_name} ${guest.last_name}` }]"
        >
            <template #actions>
                <Link :href="route('guests.index')" class="no-underline">
                    <Button variant="outline">← Mysafiret</Button>
                </Link>
            </template>
        </PageHeader>

        <!-- Stats -->
        <div class="mt-6 grid grid-cols-3 gap-3">
            <Card>
                <div class="text-center">
                    <p class="text-h3 text-primary-900">{{ stats.total_stays }}</p>
                    <p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">Qendrime</p>
                </div>
            </Card>
            <Card>
                <div class="text-center">
                    <p class="text-h3 text-primary-900">{{ stats.total_nights }}</p>
                    <p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">Nete gjithsej</p>
                </div>
            </Card>
            <Card>
                <div class="text-center">
                    <p class="text-h3 text-accent-600">€{{ Number(stats.lifetime_spend).toFixed(2) }}</p>
                    <p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">Vlera totale</p>
                </div>
            </Card>
        </div>

        <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Profile details -->
            <Card class="lg:col-span-1">
                <h3 class="text-label text-neutral-600 uppercase tracking-wider mb-4">Profili</h3>
                <dl class="space-y-3">
                    <div class="flex justify-between">
                        <dt class="text-body-sm text-neutral-500">Email</dt>
                        <dd class="text-body-sm text-neutral-700 text-right">{{ guest.email || '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-body-sm text-neutral-500">Telefon</dt>
                        <dd class="text-body-sm text-neutral-700 text-right">{{ guest.phone || '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-body-sm text-neutral-500">Kombesia</dt>
                        <dd class="text-body-sm text-neutral-700 text-right">{{ guest.nationality ? countryName(guest.nationality) : '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-body-sm text-neutral-500">Dokument</dt>
                        <dd class="text-body-sm text-neutral-700 text-right">
                            {{ guest.document_type ? docTypeLabel[guest.document_type] : '—' }}
                            <span v-if="guest.document_number" class="text-neutral-400">· {{ guest.document_number }}</span>
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-body-sm text-neutral-500">Datelindja</dt>
                        <dd class="text-body-sm text-neutral-700 text-right">{{ formatDate(guest.date_of_birth) }}</dd>
                    </div>
                    <div v-if="guest.notes" class="border-t border-neutral-100 pt-3">
                        <dt class="text-body-sm text-neutral-500 mb-1">Shenime</dt>
                        <dd class="text-body-sm text-neutral-700">{{ guest.notes }}</dd>
                    </div>
                </dl>

                <!-- Possible duplicates -->
                <div v-if="duplicates.length" class="mt-5 rounded-lg bg-warning-50 border border-warning-200 px-4 py-3">
                    <p class="text-body-sm text-warning-800 font-medium mb-1">Mundesi dublikate ({{ duplicates.length }})</p>
                    <ul class="space-y-1">
                        <li v-for="d in duplicates" :key="d.id" class="text-small text-warning-700">
                            <Link :href="route('guests.show', d.id)" class="hover:underline">
                                {{ d.first_name }} {{ d.last_name }}<span v-if="d.email"> · {{ d.email }}</span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </Card>

            <!-- Stay history -->
            <Card class="lg:col-span-2" :padding="false">
                <div class="px-5 py-4 border-b border-neutral-200">
                    <h3 class="text-label text-neutral-600 uppercase tracking-wider">Historiku i qendrimeve</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200">
                        <thead class="bg-neutral-50">
                            <tr>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Dhoma</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Check-in</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Check-out</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Statusi</th>
                                <th class="px-5 py-3 text-right text-label text-neutral-600">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="s in stays" :key="s.id" class="hover:bg-neutral-50">
                                <td class="px-5 py-3 text-body-sm text-primary-900">
                                    <Link :href="route('reservations.show', s.id)" class="hover:underline font-medium">
                                        {{ s.room }} <span class="text-neutral-400">{{ s.room_type }}</span>
                                    </Link>
                                </td>
                                <td class="px-5 py-3 text-body-sm text-neutral-700">{{ formatDate(s.check_in_date) }}</td>
                                <td class="px-5 py-3 text-body-sm text-neutral-700">{{ formatDate(s.check_out_date) }}</td>
                                <td class="px-5 py-3">
                                    <Badge :variant="statusBadge[s.status]?.variant" dot>{{ statusBadge[s.status]?.label }}</Badge>
                                </td>
                                <td class="px-5 py-3 text-right text-body-sm text-primary-900">€{{ Number(s.total_amount).toFixed(2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="!stays.length" class="px-6 py-12 text-center">
                    <p class="text-body-sm text-neutral-500">Ky mysafir nuk ka qendrime akoma.</p>
                </div>
            </Card>
        </div>

        <!-- Documents (passport / ID / …) — stored privately -->
        <div class="mt-6">
            <Card :padding="false">
                <div class="px-5 py-4 border-b border-neutral-200">
                    <h3 class="text-label text-neutral-600 uppercase tracking-wider">Dokumente (pasaportë, ID…)</h3>
                    <p class="text-tiny text-neutral-400 mt-0.5">Ruhen privatisht — shihen vetëm këtu nga stafi me leje.</p>
                </div>

                <ul v-if="documents.length" class="divide-y divide-neutral-100">
                    <li v-for="d in documents" :key="d.id" class="px-5 py-3 flex items-center gap-3 hover:bg-neutral-50">
                        <component :is="isImage(d.mime) ? ImageIcon : FileText" class="h-5 w-5 text-neutral-400 shrink-0" :stroke-width="1.75" />
                        <div class="min-w-0 flex-1">
                            <p class="text-body-sm text-primary-900 font-medium truncate">{{ docTypeLabel[d.type] || d.type }} <span class="text-neutral-400 font-normal">· {{ d.original_name }}</span></p>
                            <p class="text-tiny text-neutral-400">{{ fileSize(d.size) }}<span v-if="d.uploaded_by"> · {{ d.uploaded_by }}</span><span v-if="d.created_at"> · {{ d.created_at }}</span></p>
                        </div>
                        <a :href="d.url" target="_blank" rel="noopener" class="no-underline shrink-0">
                            <Button size="sm" variant="ghost"><Eye class="h-4 w-4 mr-1" :stroke-width="1.75" /> Shiko</Button>
                        </a>
                        <Button v-if="canUpdate" size="sm" variant="ghost" class="text-error-600 shrink-0" @click="deleteDoc(d)"><Trash2 class="h-4 w-4" :stroke-width="1.75" /></Button>
                    </li>
                </ul>
                <div v-else class="px-6 py-8 text-center text-body-sm text-neutral-500">Asnjë dokument i bashkëngjitur.</div>

                <!-- Upload -->
                <div v-if="canUpdate" class="px-5 py-4 border-t border-neutral-200 bg-neutral-50/50">
                    <div class="grid grid-cols-1 sm:grid-cols-[180px_1fr_auto] gap-3 sm:items-end">
                        <FormGroup label="Lloji">
                            <Select v-model="uploadForm.type" :options="docTypeOptions" />
                        </FormGroup>
                        <div>
                            <label class="block text-label text-neutral-700 mb-1.5">Skedari (JPG/PNG/PDF/DOC, max 25MB)</label>
                            <input ref="docInput" type="file" accept="image/*,.pdf,.doc,.docx" :class="fileInputClass" @change="onFile" />
                            <p v-if="uploadForm.errors.file" class="text-small text-error-600 mt-1">{{ uploadForm.errors.file }}</p>
                        </div>
                        <Button variant="primary" :loading="uploadForm.processing" :disabled="!uploadForm.file" @click="uploadDoc"><Upload class="h-4 w-4 mr-1.5" :stroke-width="1.75" /> Ngarko</Button>
                    </div>
                </div>
            </Card>
        </div>

        <Card class="mt-6" :padding="false">
            <div class="border-b border-neutral-200 px-5 py-4">
                <h3 class="text-label uppercase tracking-wider text-neutral-600">Historia e veprimeve</h3>
                <p class="mt-0.5 text-tiny text-neutral-400">Ndryshimet e profilit dhe veprimet e të gjitha rezervimeve të këtij mysafiri.</p>
            </div>
            <AuditTimeline :entries="history" />
        </Card>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
