<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { money } from './financeShared.js';

const props = defineProps({ suppliers: Array, categories: Array, can: Object });

const editing = ref(null); // null=closed, 'new'=create, object=edit
const form = useForm({
    name: '', nipt: '', category: '', phone: '', email: '', address: '',
    payment_terms_days: 0, is_active: true,
});

function openNew() {
    form.reset();
    form.clearErrors();
    editing.value = 'new';
}
function openEdit(s) {
    Object.assign(form, {
        name: s.name, nipt: s.nipt || '', category: s.category || '', phone: s.phone || '',
        email: s.email || '', address: s.address || '', payment_terms_days: s.payment_terms_days || 0,
        is_active: s.is_active,
    });
    form.clearErrors();
    editing.value = s;
}
function submit() {
    const opts = { preserveScroll: true, onSuccess: () => { editing.value = null; } };
    if (editing.value === 'new') form.post(route('finance.suppliers.store'), opts);
    else form.put(route('finance.suppliers.update', editing.value.id), opts);
}
function destroy() {
    if (!confirm('Ta heqësh këtë furnitor?')) return;
    form.delete(route('finance.suppliers.destroy', editing.value.id), {
        preserveScroll: true, onSuccess: () => { editing.value = null; },
    });
}
</script>

<template>
    <AppLayout>
        <PageHeader title="Furnitorët" :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Financa' }, { label: 'Furnitorët' }]">
            <template #actions>
                <Button v-if="can.manageSuppliers" @click="openNew">+ Furnitor i ri</Button>
            </template>
        </PageHeader>

        <div class="px-4 sm:px-6 pb-10">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <div v-for="s in suppliers" :key="s.id" class="rounded-xl border border-neutral-200 bg-white p-4" :class="!s.is_active && 'opacity-50'">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-bold text-primary-900 truncate">{{ s.name }}</p>
                            <p class="text-tiny text-neutral-400">
                                <span v-if="s.nipt">NIPT {{ s.nipt }} · </span>
                                <span v-if="s.category">{{ s.category }}</span>
                                <span v-if="!s.is_active"> · joaktiv</span>
                            </p>
                        </div>
                        <Button v-if="can.manageSuppliers" size="sm" variant="ghost" @click="openEdit(s)">✎</Button>
                    </div>
                    <p class="text-tiny text-neutral-500 mt-2">
                        <span v-if="s.phone">📞 {{ s.phone }} · </span>pagesa: {{ s.payment_terms_days ? s.payment_terms_days + ' ditë' : 'në dorëzim' }}
                    </p>
                    <div class="mt-3 pt-3 border-t border-neutral-100 grid grid-cols-2 gap-2 text-body-sm tabular-nums">
                        <div>
                            <span class="text-tiny text-neutral-400 block">Borxh i hapur</span>
                            <b :class="s.open_balance > 0 ? 'text-error-600' : 'text-neutral-400'">{{ money(s.open_balance) }}</b>
                        </div>
                        <div>
                            <span class="text-tiny text-neutral-400 block">Blerë këtë vit</span>
                            <b class="text-primary-900">{{ money(s.ytd) }}</b>
                        </div>
                    </div>
                </div>
            </div>
            <p v-if="!suppliers.length" class="text-center text-neutral-400 py-10">Ende pa furnitorë — shto të parin me butonin lart.</p>
        </div>

        <!-- create/edit modal -->
        <Modal :show="!!editing" @close="editing = null">
            <div class="p-5 space-y-4">
                <h3 class="text-h4 font-bold text-primary-900">{{ editing === 'new' ? 'Furnitor i ri' : 'Ndrysho furnitorin' }}</h3>
                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2">
                        <label class="block text-body-sm font-semibold text-primary-900 mb-1">Emri</label>
                        <TextInput v-model="form.name" class="w-full" placeholder="p.sh. EKO Market shpk" />
                        <p v-if="form.errors.name" class="text-tiny text-error-600 mt-1">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="block text-body-sm font-semibold text-primary-900 mb-1">NIPT (ops.)</label>
                        <TextInput v-model="form.nipt" class="w-full" placeholder="K91821507H" />
                    </div>
                    <div>
                        <label class="block text-body-sm font-semibold text-primary-900 mb-1">Kategoria</label>
                        <select v-model="form.category" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm">
                            <option value="">—</option>
                            <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-body-sm font-semibold text-primary-900 mb-1">Telefoni</label>
                        <TextInput v-model="form.phone" class="w-full" />
                    </div>
                    <div>
                        <label class="block text-body-sm font-semibold text-primary-900 mb-1">Email</label>
                        <TextInput v-model="form.email" type="email" class="w-full" />
                        <p v-if="form.errors.email" class="text-tiny text-error-600 mt-1">{{ form.errors.email }}</p>
                    </div>
                    <div class="col-span-2">
                        <label class="block text-body-sm font-semibold text-primary-900 mb-1">Adresa</label>
                        <TextInput v-model="form.address" class="w-full" />
                    </div>
                    <div>
                        <label class="block text-body-sm font-semibold text-primary-900 mb-1">Kushtet e pagesës (ditë)</label>
                        <TextInput v-model="form.payment_terms_days" type="number" min="0" max="365" class="w-full" />
                    </div>
                </div>
                <div class="flex justify-between gap-2">
                    <Button v-if="editing !== 'new'" variant="ghost" class="text-error-600" @click="destroy">Fshi / çaktivizo</Button>
                    <span v-else />
                    <div class="flex gap-2">
                        <Button variant="ghost" @click="editing = null">Anulo</Button>
                        <Button :disabled="form.processing || !form.name" @click="submit">Ruaj</Button>
                    </div>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
