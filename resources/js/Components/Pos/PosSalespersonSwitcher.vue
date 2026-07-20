<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { ChevronDown, UserRound } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Modal from '@/Components/UI/Modal.vue';

const props = defineProps({
    current: { type: Object, default: null },
    salespeople: { type: Array, default: () => [] },
    orderId: { type: Number, default: null },
    compact: { type: Boolean, default: false },
    dense: { type: Boolean, default: false },
});
const show = ref(false);
const selectedId = ref(null);
const pin = ref('');
const error = ref('');
const processing = ref(false);
const selected = computed(() => props.salespeople.find((item) => Number(item.id) === Number(selectedId.value)) || null);

function open() { selectedId.value = null; pin.value = ''; error.value = ''; show.value = true; }
function choose(person) { if (!person.has_pin) return; selectedId.value = person.id; pin.value = ''; error.value = ''; }
function addDigit(digit) { if (pin.value.length < 4) pin.value += String(digit); if (pin.value.length === 4) submit(); }
function submit() {
    if (!selected.value || pin.value.length !== 4 || processing.value) return;
    processing.value = true;
    const target = props.orderId ? route('pos.salesperson.transfer', props.orderId) : route('pos.salesperson.switch');
    router.post(target, { user_id: selected.value.id, pin: pin.value }, {
        preserveScroll: true,
        onSuccess: () => { show.value = false; },
        onError: (errors) => { error.value = errors.pin || errors.user_id || 'Nuk u krye ndërrimi.'; pin.value = ''; },
        onFinish: () => { processing.value = false; },
    });
}
</script>

<template>
    <button v-if="compact" type="button" class="rounded-lg border border-neutral-200 bg-white px-3 py-2 text-small font-semibold text-accent-700 transition hover:border-accent-300 hover:bg-accent-50" @click="open">Ndrysho</button>
    <button v-else type="button" class="flex shrink-0 items-center rounded-xl border border-neutral-200 bg-white text-left shadow-card transition hover:border-accent-300 hover:bg-accent-50" :class="dense ? 'h-12 min-w-40 gap-2 px-3' : 'h-14 min-w-44 gap-3 px-3.5'" @click="open">
        <span class="grid shrink-0 place-items-center rounded-lg bg-accent-50 text-accent-700" :class="dense ? 'h-8 w-8' : 'h-9 w-9'"><UserRound class="h-4 w-4" /></span>
        <span class="min-w-0 flex-1"><span class="block text-tiny font-semibold uppercase tracking-wide text-neutral-400">Salesperson</span><strong class="block truncate text-body-sm text-primary-900">{{ current?.name || 'Zgjidh' }}</strong></span>
        <ChevronDown class="h-4 w-4 shrink-0 text-neutral-400" />
    </button>

    <Modal :show="show" :title="orderId ? 'Transfero salesperson' : 'Ndërro salesperson'" max-width="sm" @close="show = false">
        <template v-if="!selected">
            <p class="mb-3 text-body-sm text-neutral-500">Zgjidh stafin dhe konfirmo me PIN-in e tij.</p>
            <div class="grid gap-2">
                <button v-for="person in salespeople" :key="person.id" type="button" class="flex items-center gap-3 rounded-xl border border-neutral-200 p-3 text-left transition" :class="person.has_pin ? 'hover:border-accent-300 hover:bg-accent-50' : 'cursor-not-allowed opacity-50'" @click="choose(person)">
                    <span class="grid h-10 w-10 place-items-center rounded-full bg-neutral-100 font-bold text-neutral-700">{{ person.name.slice(0, 1).toUpperCase() }}</span>
                    <span class="flex-1"><strong class="block text-body-sm text-primary-900">{{ person.name }}</strong><span class="text-tiny text-neutral-500">{{ person.has_pin ? 'PIN aktiv' : 'Vendos PIN te Konfigurimi POS' }}</span></span>
                    <span v-if="Number(person.id) === Number(current?.id)" class="rounded-full bg-success-50 px-2 py-1 text-tiny font-semibold text-success-700">Aktiv</span>
                </button>
            </div>
        </template>
        <template v-else>
            <div class="text-center"><p class="text-body-sm text-neutral-500">PIN për</p><p class="mt-1 text-h4 text-primary-900">{{ selected.name }}</p></div>
            <div class="my-5 flex justify-center gap-3"><span v-for="index in 4" :key="index" class="h-3 w-3 rounded-full" :class="pin.length >= index ? 'bg-accent-600' : 'bg-neutral-200'"></span></div>
            <p v-if="error" class="mb-3 rounded-lg bg-error-50 px-3 py-2 text-center text-small text-error-700">{{ error }}</p>
            <div class="mx-auto grid max-w-[250px] grid-cols-3 gap-2">
                <button v-for="digit in [1,2,3,4,5,6,7,8,9]" :key="digit" type="button" class="h-14 rounded-xl border border-neutral-200 bg-white text-h4 font-bold text-primary-900 hover:bg-neutral-50" @click="addDigit(digit)">{{ digit }}</button>
                <button type="button" class="h-14 rounded-xl text-small text-neutral-500 hover:bg-neutral-50" @click="selectedId = null">Stafi</button>
                <button type="button" class="h-14 rounded-xl border border-neutral-200 bg-white text-h4 font-bold text-primary-900 hover:bg-neutral-50" @click="addDigit(0)">0</button>
                <button type="button" class="h-14 rounded-xl text-small text-neutral-500 hover:bg-neutral-50" @click="pin = pin.slice(0, -1)">Fshi</button>
            </div>
        </template>
        <template #footer><Button variant="ghost" @click="show = false">Anulo</Button></template>
    </Modal>
</template>
