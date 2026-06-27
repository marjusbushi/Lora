<script setup>
import { ref, watch } from 'vue';
import { useForm, router, usePage, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import Select from '@/Components/UI/Select.vue';
import Textarea from '@/Components/UI/Textarea.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';

const props = defineProps({
    guests: Object,
    filters: Object,
    totalGuests: Number,
});

const toasts = ref(null);
const showCreateModal = ref(false);
const showEditModal = ref(false);
const showDeleteModal = ref(false);
const selectedGuest = ref(null);

const page = usePage();
const perms = page.props.auth.user?.permissions || [];
const canCreate = perms.includes('create_guests');
const canUpdate = perms.includes('update_guests');
const canDelete = perms.includes('delete_guests');

const docTypeOptions = [
    { value: 'id_card', label: 'Karte identiteti' },
    { value: 'passport', label: 'Pasaporte' },
    { value: 'drivers_license', label: 'Patente' },
];

const docTypeLabel = { id_card: 'ID', passport: 'Pasaporte', drivers_license: 'Patente' };

// Search
const searchQuery = ref(props.filters?.search || '');
let searchTimeout = null;

watch(searchQuery, (val) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(route('guests.index'), { search: val || undefined }, { preserveState: true, preserveScroll: true });
    }, 300);
});

// Forms
const createForm = useForm({
    first_name: '', last_name: '', email: '', phone: '',
    document_type: '', document_number: '', nationality: '',
    date_of_birth: '', notes: '',
});

const editForm = useForm({
    first_name: '', last_name: '', email: '', phone: '',
    document_type: '', document_number: '', nationality: '',
    date_of_birth: '', notes: '',
});

function openEdit(guest) {
    selectedGuest.value = guest;
    Object.keys(editForm.data()).forEach((key) => {
        editForm[key] = guest[key] || '';
    });
    showEditModal.value = true;
}

function openDelete(guest) {
    selectedGuest.value = guest;
    showDeleteModal.value = true;
}

function submitCreate() {
    createForm.post(route('guests.store'), {
        onSuccess: () => {
            showCreateModal.value = false;
            createForm.reset();
            toasts.value?.success('Mysafiri u regjistrua.');
        },
    });
}

function submitEdit() {
    editForm.put(route('guests.update', selectedGuest.value.id), {
        onSuccess: () => {
            showEditModal.value = false;
            toasts.value?.success('Te dhenat u perditesuan.');
        },
    });
}

function submitDelete() {
    router.delete(route('guests.destroy', selectedGuest.value.id), {
        onSuccess: () => {
            showDeleteModal.value = false;
            toasts.value?.success('Mysafiri u fshi.');
        },
    });
}
</script>

<template>
    <AppLayout>
        <PageHeader
            title="Mysafiret"
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Mysafiret' }]"
        >
            <template #actions>
                <Button v-if="canCreate" variant="primary" @click="showCreateModal = true">+ Shto mysafir</Button>
            </template>
        </PageHeader>

        <!-- Search + stats -->
        <div class="mt-6 flex flex-col sm:flex-row items-start sm:items-center gap-4">
            <div class="w-full sm:w-80">
                <TextInput v-model="searchQuery" placeholder="Kerko me emer, email, telefon..." />
            </div>
            <Badge variant="neutral">{{ totalGuests }} gjithsej</Badge>
        </div>

        <!-- Table -->
        <div class="mt-6">
            <Card :padding="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200">
                        <thead class="bg-neutral-50">
                            <tr>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Emri</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Email</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Telefon</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Kombesia</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Dokument</th>
                                <th class="px-5 py-3 text-right text-label text-neutral-600">Veprime</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="guest in guests.data" :key="guest.id" class="hover:bg-neutral-50 transition-colors duration-100">
                                <td class="px-5 py-3">
                                    <p class="text-body-sm text-primary-900 font-medium">{{ guest.first_name }} {{ guest.last_name }}</p>
                                </td>
                                <td class="px-5 py-3 text-body-sm text-neutral-600">{{ guest.email || '—' }}</td>
                                <td class="px-5 py-3 text-body-sm text-neutral-600">{{ guest.phone || '—' }}</td>
                                <td class="px-5 py-3">
                                    <Badge v-if="guest.nationality" variant="neutral" size="sm">{{ guest.nationality }}</Badge>
                                    <span v-else class="text-neutral-400">—</span>
                                </td>
                                <td class="px-5 py-3 text-body-sm text-neutral-600">
                                    {{ guest.document_type ? docTypeLabel[guest.document_type] : '—' }}
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <Link :href="route('guests.show', guest.id)" class="no-underline">
                                            <Button size="sm" variant="ghost">Shiko</Button>
                                        </Link>
                                        <Button v-if="canUpdate" size="sm" variant="ghost" @click="openEdit(guest)">Edito</Button>
                                        <Button v-if="canDelete" size="sm" variant="ghost" class="text-error-600" @click="openDelete(guest)">Fshi</Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="!guests.data?.length" class="px-6 py-12 text-center">
                    <p class="text-body-sm text-neutral-500">
                        {{ searchQuery ? 'Asnje rezultat per kete kerkim.' : 'Nuk ka mysafire akoma.' }}
                    </p>
                </div>

                <!-- Pagination -->
                <div v-if="guests.last_page > 1" class="flex items-center justify-between border-t border-neutral-200 bg-neutral-50 px-5 py-3">
                    <p class="text-small text-neutral-500">
                        {{ guests.from }}–{{ guests.to }} nga {{ guests.total }}
                    </p>
                    <div class="flex gap-1">
                        <Button
                            v-for="link in guests.links"
                            :key="link.label"
                            size="sm"
                            :variant="link.active ? 'primary' : 'ghost'"
                            :disabled="!link.url"
                            @click="link.url && router.get(link.url, {}, { preserveState: true })"
                            v-html="link.label"
                        />
                    </div>
                </div>
            </Card>
        </div>

        <!-- Create Modal -->
        <Modal :show="showCreateModal" title="Regjistro mysafir te ri" max-width="lg" @close="showCreateModal = false">
            <form @submit.prevent="submitCreate" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <FormGroup label="Emri" :error="createForm.errors.first_name" required>
                        <TextInput v-model="createForm.first_name" placeholder="Emri" :error="createForm.errors.first_name" />
                    </FormGroup>
                    <FormGroup label="Mbiemri" :error="createForm.errors.last_name" required>
                        <TextInput v-model="createForm.last_name" placeholder="Mbiemri" :error="createForm.errors.last_name" />
                    </FormGroup>
                    <FormGroup label="Email" :error="createForm.errors.email">
                        <TextInput type="email" v-model="createForm.email" placeholder="email@example.com" :error="createForm.errors.email" />
                    </FormGroup>
                    <FormGroup label="Telefon" :error="createForm.errors.phone">
                        <TextInput v-model="createForm.phone" placeholder="+355 69..." :error="createForm.errors.phone" />
                    </FormGroup>
                    <FormGroup label="Tipi dokumentit" :error="createForm.errors.document_type">
                        <Select v-model="createForm.document_type" :options="docTypeOptions" placeholder="Zgjidh..." :error="createForm.errors.document_type" />
                    </FormGroup>
                    <FormGroup label="Nr. dokumentit" :error="createForm.errors.document_number">
                        <TextInput v-model="createForm.document_number" placeholder="I12345678" :error="createForm.errors.document_number" />
                    </FormGroup>
                    <FormGroup label="Kombesia (ISO)" :error="createForm.errors.nationality">
                        <TextInput v-model="createForm.nationality" placeholder="ALB" maxlength="3" :error="createForm.errors.nationality" />
                    </FormGroup>
                    <FormGroup label="Data e lindjes" :error="createForm.errors.date_of_birth">
                        <TextInput type="date" v-model="createForm.date_of_birth" :error="createForm.errors.date_of_birth" />
                    </FormGroup>
                </div>
                <FormGroup label="Shenime" :error="createForm.errors.notes">
                    <Textarea v-model="createForm.notes" placeholder="Shenime shtese..." :rows="2" />
                </FormGroup>
            </form>
            <template #footer>
                <Button variant="outline" @click="showCreateModal = false">Anulo</Button>
                <Button variant="primary" :loading="createForm.processing" @click="submitCreate">Regjistro</Button>
            </template>
        </Modal>

        <!-- Edit Modal -->
        <Modal :show="showEditModal" title="Edito mysafirin" max-width="lg" @close="showEditModal = false">
            <form @submit.prevent="submitEdit" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <FormGroup label="Emri" :error="editForm.errors.first_name" required>
                        <TextInput v-model="editForm.first_name" :error="editForm.errors.first_name" />
                    </FormGroup>
                    <FormGroup label="Mbiemri" :error="editForm.errors.last_name" required>
                        <TextInput v-model="editForm.last_name" :error="editForm.errors.last_name" />
                    </FormGroup>
                    <FormGroup label="Email" :error="editForm.errors.email">
                        <TextInput type="email" v-model="editForm.email" :error="editForm.errors.email" />
                    </FormGroup>
                    <FormGroup label="Telefon" :error="editForm.errors.phone">
                        <TextInput v-model="editForm.phone" :error="editForm.errors.phone" />
                    </FormGroup>
                    <FormGroup label="Tipi dokumentit" :error="editForm.errors.document_type">
                        <Select v-model="editForm.document_type" :options="docTypeOptions" placeholder="Zgjidh..." :error="editForm.errors.document_type" />
                    </FormGroup>
                    <FormGroup label="Nr. dokumentit" :error="editForm.errors.document_number">
                        <TextInput v-model="editForm.document_number" :error="editForm.errors.document_number" />
                    </FormGroup>
                    <FormGroup label="Kombesia (ISO)" :error="editForm.errors.nationality">
                        <TextInput v-model="editForm.nationality" maxlength="3" :error="editForm.errors.nationality" />
                    </FormGroup>
                    <FormGroup label="Data e lindjes" :error="editForm.errors.date_of_birth">
                        <TextInput type="date" v-model="editForm.date_of_birth" :error="editForm.errors.date_of_birth" />
                    </FormGroup>
                </div>
                <FormGroup label="Shenime" :error="editForm.errors.notes">
                    <Textarea v-model="editForm.notes" :rows="2" />
                </FormGroup>
            </form>
            <template #footer>
                <Button variant="outline" @click="showEditModal = false">Anulo</Button>
                <Button variant="primary" :loading="editForm.processing" @click="submitEdit">Ruaj</Button>
            </template>
        </Modal>

        <!-- Delete Confirmation -->
        <Modal :show="showDeleteModal" title="Fshi mysafirin" max-width="sm" @close="showDeleteModal = false">
            <p class="text-body-sm text-neutral-600">
                Je i sigurt qe deshiron te fshish <strong>{{ selectedGuest?.first_name }} {{ selectedGuest?.last_name }}</strong>?
            </p>
            <template #footer>
                <Button variant="outline" @click="showDeleteModal = false">Anulo</Button>
                <Button variant="danger" @click="submitDelete">Fshi</Button>
            </template>
        </Modal>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>
