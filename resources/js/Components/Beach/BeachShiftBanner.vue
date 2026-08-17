<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';

const props = defineProps({
    shift: { type: Object, default: null }, // currentShift nga serveri (me live_expected_cash)
    canOpen: { type: Boolean, default: false },
    canClose: { type: Boolean, default: false },
    currency: { type: String, default: '' },
});

// --- Hapja ---
const showOpen = ref(false);
const openForm = useForm({ opening_float: 0 });

function submitOpen() {
    openForm.post(route('beach.shifts.open'), {
        preserveScroll: true,
        onSuccess: () => { showOpen.value = false; openForm.reset(); },
    });
}

// --- Mbyllja ---
const showClose = ref(false);
const closeForm = useForm({ counted_cash: '', closing_note: '' });

function overShort() {
    if (closeForm.counted_cash === '' || !props.shift) return null;
    return (Number(closeForm.counted_cash) - Number(props.shift.live_expected_cash)).toFixed(2);
}

function submitClose() {
    closeForm.post(route('beach.shifts.close', props.shift.id), {
        preserveScroll: true,
        onSuccess: () => { showClose.value = false; closeForm.reset(); },
    });
}
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-3 rounded-xl border px-4 py-2.5"
        :class="shift ? 'border-emerald-200 bg-emerald-50' : 'border-amber-300 bg-amber-50'"
    >
        <template v-if="shift">
            <span class="h-2.5 w-2.5 shrink-0 animate-pulse rounded-full bg-emerald-500" />
            <span class="text-body-sm font-semibold text-emerald-900">
                {{ $t('beach.shift.openSince', { time: new Date(shift.opened_at).toLocaleTimeString('sq-AL', { hour: '2-digit', minute: '2-digit' }) }) }}
            </span>
            <span class="text-body-sm text-emerald-800">
                {{ $t('beach.shift.expectedNow', { amount: currency + Number(shift.live_expected_cash).toFixed(2) }) }}
            </span>
            <Button v-if="canClose" size="sm" variant="outline" class="ml-auto" @click="showClose = true">
                {{ $t('beach.shift.closeShift') }}
            </Button>
        </template>
        <template v-else>
            <span class="text-body-sm font-semibold text-amber-900">{{ $t('beach.shift.noShift') }}</span>
            <span class="text-body-sm text-amber-800">{{ $t('beach.shift.noShiftHint') }}</span>
            <Button v-if="canOpen" size="sm" variant="primary" class="ml-auto" @click="showOpen = true">
                {{ $t('beach.shift.openShift') }}
            </Button>
        </template>
    </div>

    <!-- Modal: hapja -->
    <Modal :show="showOpen" :title="$t('beach.shift.openShift')" @close="showOpen = false">
        <form class="space-y-4" @submit.prevent="submitOpen">
            <FormGroup :label="$t('beach.shift.openingFloat')" :error="openForm.errors.opening_float" required>
                <TextInput v-model="openForm.opening_float" type="number" min="0" step="0.01" :error="openForm.errors.opening_float" />
                <p class="text-small text-neutral-500 mt-1">{{ $t('beach.shift.openingFloatHint') }}</p>
            </FormGroup>
        </form>
        <template #footer>
            <Button variant="outline" @click="showOpen = false">{{ $t('beach.setup.cancel') }}</Button>
            <Button variant="primary" :loading="openForm.processing" @click="submitOpen">{{ $t('beach.shift.openShift') }}</Button>
        </template>
    </Modal>

    <!-- Modal: mbyllja me numërim -->
    <Modal :show="showClose" :title="$t('beach.shift.closeShift')" @close="showClose = false">
        <form class="space-y-4" @submit.prevent="submitClose">
            <p class="rounded-lg bg-neutral-50 px-3 py-2 text-body-sm text-primary-900">
                {{ $t('beach.shift.expectedNow', { amount: currency + Number(shift?.live_expected_cash ?? 0).toFixed(2) }) }}
            </p>
            <FormGroup :label="$t('beach.shift.countedCash')" :error="closeForm.errors.counted_cash" required>
                <TextInput v-model="closeForm.counted_cash" type="number" min="0" step="0.01" :error="closeForm.errors.counted_cash" />
            </FormGroup>
            <p v-if="overShort() !== null" class="text-body-sm font-semibold" :class="Number(overShort()) === 0 ? 'text-emerald-700' : 'text-amber-800'">
                {{ $t('beach.shift.overShort', { amount: overShort() }) }}
            </p>
            <FormGroup :label="$t('beach.shift.closingNote')" :error="closeForm.errors.closing_note">
                <TextInput v-model="closeForm.closing_note" :error="closeForm.errors.closing_note" />
            </FormGroup>
        </form>
        <template #footer>
            <Button variant="outline" @click="showClose = false">{{ $t('beach.setup.cancel') }}</Button>
            <Button variant="primary" :loading="closeForm.processing" @click="submitClose">{{ $t('beach.shift.closeConfirm') }}</Button>
        </template>
    </Modal>
</template>
