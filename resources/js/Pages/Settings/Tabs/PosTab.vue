<script setup>
import { router, useForm } from '@inertiajs/vue3';
import { Plus, UserRoundCheck } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Checkbox from '@/Components/UI/Checkbox.vue';

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
    staff: { type: Array, default: () => [] },
    accountMode: { type: String, default: 'shared' },
    toasts: Object,
});

// Where POS money lands — saved immediately, independent of the main form.
const accountMode = ref(props.accountMode);
watch(() => props.accountMode, (mode) => { accountMode.value = mode; });

function saveAccountMode(mode) {
    if (accountMode.value === mode) return;
    accountMode.value = mode;
    router.put(route('finance.accounts.pos-mode'), { mode }, {
        preserveScroll: true,
        onSuccess: () => props.toasts?.success('Modaliteti i llogarive POS u ruajt.'),
        onError: () => {
            accountMode.value = props.accountMode;
            props.toasts?.error('Modaliteti nuk u ruajt.');
        },
    });
}

const staffRows = (staff) => staff.map((person) => ({ ...person, pin: '', clear_pin: false }));
const form = useForm({
    service_mode: props.settings.service_mode || 'hybrid',
    opening_view: props.settings.opening_view || 'products',
    salesperson_enabled: props.settings.salesperson_enabled ?? true,
    salesperson_required: props.settings.salesperson_required ?? true,
    staff: staffRows(props.staff),
});
const showCreateModal = ref(false);
const createForm = useForm({ name: '', email: '', password: '', pin: '' });
const enabledCount = computed(() => form.staff.filter((person) => person.enabled).length);

watch(() => props.staff, (staff) => {
    form.staff = staffRows(staff);
}, { deep: true });

function submit() {
    form.put(route('settings.pos'), {
        preserveScroll: true,
        onSuccess: () => props.toasts?.success('Konfigurimi POS u ruajt.'),
    });
}

function closeCreateModal() {
    showCreateModal.value = false;
    createForm.reset();
    createForm.clearErrors();
}

function createSalesperson() {
    createForm.post(route('settings.pos.salespeople.store'), {
        preserveScroll: true,
        onSuccess: () => {
            closeCreateModal();
            props.toasts?.success('Kamarieri u krijua dhe u aktivizua në POS.');
        },
    });
}

function digitsOnly(event) {
    createForm.pin = event.target.value.replace(/\D/g, '').slice(0, 4);
}
</script>

<template>
    <Card>
        <template #header>
            <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-h4 text-primary-900">POS & Shërbimi</h3>
                    <p class="mt-1 text-body-sm text-neutral-500">Mënyra e punës, ekrani fillestar dhe kamarierët.</p>
                </div>
                <Button type="button" variant="outline" @click="showCreateModal = true">
                    <Plus class="h-4 w-4" />
                    Shto kamarier
                </Button>
            </div>
        </template>

        <form class="space-y-6" @submit.prevent="submit">
            <section>
                <h4 class="text-label text-primary-900">Mënyra e shërbimit</h4>
                <p class="mt-1 text-small text-neutral-500">Përcakton nëse POS punon me tavolina, shitje direkte ose të dyja.</p>
                <div class="mt-3 grid gap-3 md:grid-cols-3">
                    <label
                        v-for="option in [{value:'hybrid',title:'Hibrid',text:'Tavolina + shitje direkte'},{value:'tables',title:'Vetëm tavolina',text:'Restorant me llogari tavoline'},{value:'direct',title:'Shitje direkte',text:'Banak, pa tavolina'}]"
                        :key="option.value"
                        class="cursor-pointer rounded-xl border p-4"
                        :class="form.service_mode === option.value ? 'border-accent-500 bg-accent-50 ring-2 ring-accent-500/10' : 'border-neutral-200'"
                    >
                        <input v-model="form.service_mode" type="radio" :value="option.value" class="sr-only">
                        <strong class="text-body-sm text-primary-900">{{ option.title }}</strong>
                        <span class="mt-1 block text-tiny text-neutral-500">{{ option.text }}</span>
                    </label>
                </div>
            </section>

            <section class="border-t border-neutral-100 pt-5">
                <h4 class="text-label text-primary-900">Paratë e POS Bar/Restorant</h4>
                <p class="mt-1 text-small text-neutral-500">Ku derdhen pagesat e POS në Financë. Llogaritë krijohen vetë në shitjen e parë; ndërrimi nuk prek historikun — vetëm pagesat e reja ndjekin routimin e ri. Ruhet menjëherë.</p>
                <div class="mt-3 grid gap-3 md:grid-cols-3">
                    <label
                        v-for="option in [
                            { value: 'shared', title: 'Në llogaritë e hotelit', text: 'Cash te Arka, kartat te Banka — si deri tani' },
                            { value: 'split_cash', title: 'Arkë e veçantë për POS', text: 'Cash-i i barit në sirtarin e vet; kartat te Banka' },
                            { value: 'split_all', title: 'Arkë & bankë të veçanta', text: 'Gjithçka nga POS në llogaritë Bar/Restorant' },
                        ]"
                        :key="option.value"
                        class="cursor-pointer rounded-xl border p-4"
                        :class="accountMode === option.value ? 'border-accent-500 bg-accent-50 ring-2 ring-accent-500/10' : 'border-neutral-200'"
                    >
                        <input :checked="accountMode === option.value" type="radio" name="pos-account-mode" :value="option.value" class="sr-only" @change="saveAccountMode(option.value)">
                        <strong class="text-body-sm text-primary-900">{{ option.title }}</strong>
                        <span class="mt-1 block text-tiny text-neutral-500">{{ option.text }}</span>
                    </label>
                </div>
            </section>

            <section class="grid gap-4 border-t border-neutral-100 pt-5 md:grid-cols-2">
                <label>
                    <span class="text-label text-neutral-700">Ekrani fillestar</span>
                    <select v-model="form.opening_view" class="mt-2 w-full rounded-lg border-neutral-200">
                        <option value="tables">Tavolinat</option>
                        <option value="products">Produktet POS</option>
                    </select>
                </label>
                <div class="space-y-3 rounded-xl bg-neutral-50 p-4">
                    <Checkbox v-model="form.salesperson_enabled" label="Aktivizo salesperson në POS" />
                    <Checkbox v-model="form.salesperson_required" :disabled="!form.salesperson_enabled" label="Salesperson i detyrueshëm për çdo porosi" />
                </div>
            </section>

            <section class="border-t border-neutral-100 pt-5">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h4 class="text-label text-primary-900">Kamarierët / salesperson-at</h4>
                        <p class="mt-1 text-small text-neutral-500">{{ enabledCount }} aktivë. Çdo kamarier ka llogarinë dhe PIN-in unik të vet.</p>
                    </div>
                    <span class="inline-flex w-fit items-center gap-1.5 rounded-full bg-success-50 px-2.5 py-1 text-tiny font-semibold text-success-700">
                        <UserRoundCheck class="h-3.5 w-3.5" />
                        PIN i enkriptuar
                    </span>
                </div>

                <div class="mt-3 divide-y divide-neutral-100 rounded-xl border border-neutral-200">
                    <div v-for="person in form.staff" :key="person.id" class="grid gap-3 p-4 sm:grid-cols-[minmax(0,1fr)_120px_110px] sm:items-center">
                        <label class="flex items-center gap-3">
                            <input v-model="person.enabled" type="checkbox" class="h-4 w-4 rounded border-neutral-300 text-accent-600">
                            <span>
                                <strong class="block text-body-sm text-primary-900">{{ person.name }}</strong>
                                <span class="text-tiny text-neutral-500">{{ person.has_pin && !person.clear_pin ? 'PIN i konfiguruar' : 'Pa PIN' }}</span>
                            </span>
                        </label>
                        <input v-model="person.pin" type="password" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" class="w-full rounded-lg border-neutral-200 text-center tracking-[.3em]" placeholder="PIN i ri">
                        <button v-if="person.has_pin && !person.clear_pin" type="button" class="text-small font-semibold text-error-600" @click="person.clear_pin = true; person.pin = ''">Hiq PIN</button>
                        <button v-else-if="person.clear_pin" type="button" class="text-small font-semibold text-neutral-500" @click="person.clear_pin = false">Anulo heqjen</button>
                    </div>
                    <p v-if="!form.staff.length" class="px-4 py-8 text-center text-body-sm text-neutral-500">Nuk ka ende kamarierë. Kliko “Shto kamarier”.</p>
                </div>
            </section>

            <div v-if="form.hasErrors" class="rounded-lg bg-error-50 px-3 py-2 text-small text-error-700">
                Kontrollo fushat. Çdo PIN duhet të ketë 4 shifra dhe të jetë unik.
            </div>
            <div class="settings-actions">
                <Button type="submit" variant="primary" :loading="form.processing">Ruaj konfigurimin POS</Button>
            </div>
        </form>
    </Card>

    <Modal :show="showCreateModal" title="Shto kamarier" max-width="md" @close="closeCreateModal">
        <form class="space-y-4" @submit.prevent="createSalesperson">
            <div class="rounded-lg border border-accent-100 bg-accent-50 px-3.5 py-3 text-small text-accent-800">
                Krijohet një llogari me rolin <strong>Kamarier POS</strong> dhe një PIN unik për ndërrim të shpejtë në ekranin touch.
            </div>
            <FormGroup label="Emri dhe mbiemri" html-for="waiter-name" :error="createForm.errors.name" required>
                <TextInput id="waiter-name" v-model="createForm.name" placeholder="p.sh. Arta Shehu" :error="createForm.errors.name" autofocus />
            </FormGroup>
            <FormGroup label="Emaili i hyrjes" html-for="waiter-email" :error="createForm.errors.email" required>
                <TextInput id="waiter-email" v-model="createForm.email" type="email" placeholder="arta@hoteli.al" :error="createForm.errors.email" />
            </FormGroup>
            <FormGroup label="Fjalëkalimi fillestar" html-for="waiter-password" :error="createForm.errors.password" required>
                <TextInput id="waiter-password" v-model="createForm.password" type="password" placeholder="Minimumi 8 karaktere" :error="createForm.errors.password" />
            </FormGroup>
            <FormGroup label="PIN-i unik i POS-it" html-for="waiter-pin" :error="createForm.errors.pin" required>
                <TextInput id="waiter-pin" v-model="createForm.pin" type="password" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" placeholder="••••" :error="createForm.errors.pin" @input="digitsOnly" />
            </FormGroup>
        </form>
        <template #footer>
            <Button variant="outline" @click="closeCreateModal">Anulo</Button>
            <Button variant="primary" :loading="createForm.processing" @click="createSalesperson">Krijo kamarierin</Button>
        </template>
    </Modal>
</template>
