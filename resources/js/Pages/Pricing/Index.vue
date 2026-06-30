<script setup>
import { ref, reactive, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';

const props = defineProps({
    roomTypes: Array,
    seasons: Array,
});

const toasts = ref(null);

// ---- Price matrix (base + per-season) ----
const base = reactive({});
const rates = reactive({});

function buildMatrix() {
    props.roomTypes.forEach((t) => { base[t.id] = t.base_price ?? ''; });
    props.seasons.forEach((s) => {
        rates[s.id] = rates[s.id] || {};
        props.roomTypes.forEach((t) => {
            const v = s.rates?.[t.id];
            rates[s.id][t.id] = (v === undefined || v === null) ? '' : v;
        });
    });
    // drop seasons that no longer exist
    Object.keys(rates).forEach((sid) => {
        if (!props.seasons.some((s) => String(s.id) === String(sid))) delete rates[sid];
    });
}
buildMatrix();
watch(() => [props.roomTypes, props.seasons], buildMatrix);

const savingRates = ref(false);
function saveRates() {
    savingRates.value = true;
    router.post(route('pricing.rates.save'), { base, rates }, {
        preserveScroll: true,
        onSuccess: () => toasts.value?.success('Cmimet u ruajten.'),
        onFinish: () => { savingRates.value = false; },
    });
}

// ---- Seasons CRUD ----
const showSeason = ref(false);
const editingSeason = ref(null);
const sform = useForm({ name: '', start_date: '', end_date: '', priority: 0 });

function openCreateSeason() {
    editingSeason.value = null;
    sform.reset();
    sform.clearErrors();
    showSeason.value = true;
}
function openEditSeason(s) {
    editingSeason.value = s;
    sform.name = s.name;
    sform.start_date = s.start_date;
    sform.end_date = s.end_date;
    sform.priority = s.priority;
    sform.clearErrors();
    showSeason.value = true;
}
function submitSeason() {
    const opts = {
        preserveScroll: true,
        onSuccess: () => { showSeason.value = false; toasts.value?.success('U ruajt.'); },
    };
    if (editingSeason.value) {
        sform.put(route('pricing.seasons.update', editingSeason.value.id), opts);
    } else {
        sform.post(route('pricing.seasons.store'), opts);
    }
}
function deleteSeason(s) {
    if (!confirm(`Fshi sezonin "${s.name}"? (cmimet e tij do hiqen)`)) return;
    router.delete(route('pricing.seasons.destroy', s.id), {
        preserveScroll: true,
        onSuccess: () => toasts.value?.success('Sezoni u fshi.'),
    });
}

function fmtRange(s) {
    return `${s.start_date} → ${s.end_date}`;
}
</script>

<template>
    <AppLayout>
        <PageHeader
            title="Cmimet"
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Cmimet' }]"
        />

        <div class="mt-6 space-y-6">
            <!-- Seasons -->
            <Card>
                <template #header>
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-h4 text-primary-900">Sezonet</h3>
                            <p class="text-small text-neutral-500 mt-0.5">Periudha datash me cmime te ndryshme. Prioriteti me i larte fiton kur datat mbivendosen.</p>
                        </div>
                        <Button size="sm" variant="primary" @click="openCreateSeason">+ Shto sezon</Button>
                    </div>
                </template>

                <div class="divide-y divide-neutral-100">
                    <div v-for="s in seasons" :key="s.id" class="py-3 flex items-center gap-4">
                        <div class="flex-1 min-w-0">
                            <p class="text-body-sm font-medium text-primary-900">{{ s.name }}</p>
                            <p class="text-small text-neutral-500">{{ fmtRange(s) }} · prioritet {{ s.priority }}</p>
                        </div>
                        <Button size="sm" variant="ghost" @click="openEditSeason(s)">Edito</Button>
                        <Button size="sm" variant="ghost" class="text-error-600" @click="deleteSeason(s)">Fshi</Button>
                    </div>
                    <div v-if="!seasons.length" class="py-6 text-center text-body-sm text-neutral-500">
                        Asnje sezon. Shtoni nje (p.sh. "Sezoni i larte: 1 Korrik–31 Gusht").
                    </div>
                </div>
            </Card>

            <!-- Price matrix -->
            <Card>
                <template #header>
                    <div>
                        <h3 class="text-h4 text-primary-900">Cmimet sipas tipit dhe sezonit</h3>
                        <p class="text-small text-neutral-500 mt-0.5">Bosh = perdoret cmimi bazё. Cmimi llogaritet natё-pёr-natё sipas datave.</p>
                    </div>
                </template>

                <div class="overflow-x-auto">
                    <table class="w-full text-body-sm">
                        <thead>
                            <tr class="border-b border-neutral-200">
                                <th class="px-3 py-2 text-left text-label text-neutral-600">Tipi i dhomes</th>
                                <th class="px-3 py-2 text-left text-label text-neutral-600 whitespace-nowrap">Cmimi bazё (€)</th>
                                <th v-for="s in seasons" :key="s.id" class="px-3 py-2 text-left text-label text-neutral-600 whitespace-nowrap">
                                    {{ s.name }} (€)
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="t in roomTypes" :key="t.id">
                                <td class="px-3 py-2 font-medium text-primary-900 whitespace-nowrap">{{ t.name }}</td>
                                <td class="px-3 py-2">
                                    <input v-model="base[t.id]" type="number" min="0" step="1"
                                        class="w-24 rounded-md border border-neutral-300 px-2 py-1.5 text-body-sm focus:border-accent-500 focus:ring-2 focus:ring-accent-500/40" />
                                </td>
                                <td v-for="s in seasons" :key="s.id" class="px-3 py-2">
                                    <input v-if="rates[s.id]" v-model="rates[s.id][t.id]" type="number" min="0" step="1"
                                        :placeholder="String(base[t.id] ?? '')"
                                        class="w-24 rounded-md border border-neutral-300 px-2 py-1.5 text-body-sm focus:border-accent-500 focus:ring-2 focus:ring-accent-500/40" />
                                </td>
                            </tr>
                            <tr v-if="!roomTypes.length">
                                <td :colspan="2 + seasons.length" class="px-3 py-6 text-center text-neutral-500">
                                    Shto tipe dhomash te Settings se pari.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-end mt-4">
                    <Button variant="primary" :loading="savingRates" @click="saveRates">Ruaj cmimet</Button>
                </div>
            </Card>
        </div>

        <!-- Season modal -->
        <Modal :show="showSeason" :title="editingSeason ? 'Edito sezonin' : 'Sezon i ri'" @close="showSeason = false">
            <form @submit.prevent="submitSeason" class="space-y-4">
                <FormGroup label="Emri" :error="sform.errors.name" required>
                    <TextInput v-model="sform.name" placeholder="psh. Sezoni i larte" :error="sform.errors.name" />
                </FormGroup>
                <div class="grid grid-cols-2 gap-4">
                    <FormGroup label="Nga data" :error="sform.errors.start_date" required>
                        <DatePicker v-model="sform.start_date" :error="sform.errors.start_date" />
                    </FormGroup>
                    <FormGroup label="Deri me" :error="sform.errors.end_date" required>
                        <DatePicker v-model="sform.end_date" :error="sform.errors.end_date" />
                    </FormGroup>
                </div>
                <FormGroup label="Prioriteti" :error="sform.errors.priority" required>
                    <TextInput type="number" v-model="sform.priority" min="0" max="1000" />
                    <p class="text-tiny text-neutral-400 mt-1">Me i larte fiton kur dy sezone mbivendosen (p.sh. 'Fundjavё' > 'Sezon i larte').</p>
                </FormGroup>
            </form>
            <template #footer>
                <Button variant="outline" @click="showSeason = false">Anulo</Button>
                <Button variant="primary" :loading="sform.processing" @click="submitSeason">{{ editingSeason ? 'Ruaj' : 'Shto' }}</Button>
            </template>
        </Modal>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
