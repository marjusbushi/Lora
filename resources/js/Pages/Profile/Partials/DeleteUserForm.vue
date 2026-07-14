<script setup>
import Button from '@/Components/UI/Button.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { AlertTriangle, Trash2 } from 'lucide-vue-next';
import { nextTick, ref } from 'vue';

defineProps({
    compact: { type: Boolean, default: false },
});

const confirmingUserDeletion = ref(false);
const passwordInput = ref(null);
const form = useForm({ password: '' });

function confirmUserDeletion() {
    confirmingUserDeletion.value = true;
    nextTick(() => nextTick(() => passwordInput.value?.focus()));
}

function closeModal() {
    if (form.processing) return;
    confirmingUserDeletion.value = false;
    form.clearErrors();
    form.reset();
}

function deleteUser() {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: closeModal,
        onError: () => passwordInput.value?.focus(),
        onFinish: () => form.reset(),
    });
}
</script>

<template>
    <Button v-if="compact" variant="ghost" class="text-error-700 hover:bg-error-50 hover:text-error-800" @click="confirmUserDeletion">
        <template #icon-left><Trash2 class="h-4 w-4" /></template>
        {{ $t('accountCenter.deleteAccount') }}
    </Button>

    <div v-else class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-error-100 text-error-700">
                <Trash2 class="h-5 w-5" />
            </span>
            <div>
                <h2 class="text-body font-bold text-error-900">{{ $t('accountCenter.dangerZone') }}</h2>
                <p class="mt-0.5 max-w-2xl text-body-sm leading-5 text-neutral-600">{{ $t('accountCenter.deleteDescription') }}</p>
            </div>
        </div>
        <Button variant="danger" class="shrink-0" @click="confirmUserDeletion">{{ $t('accountCenter.deleteAccount') }}</Button>
    </div>

    <Modal :show="confirmingUserDeletion" :title="$t('accountCenter.deleteConfirmTitle')" max-width="md" @close="closeModal">
        <div class="flex gap-3 rounded-lg border border-error-200 bg-error-50 p-3 text-body-sm text-error-800">
            <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0" />
            <p>{{ $t('accountCenter.deleteConfirmDescription') }}</p>
        </div>
        <div class="mt-4">
            <label for="delete-password" class="mb-1.5 block text-body-sm font-semibold text-primary-900">{{ $t('accountCenter.password') }}</label>
            <TextInput id="delete-password" ref="passwordInput" v-model="form.password" type="password" autocomplete="current-password" :error="form.errors.password" @keyup.enter="deleteUser" />
            <p v-if="form.errors.password" class="mt-1 text-tiny text-error-600">{{ form.errors.password }}</p>
        </div>
        <template #footer>
            <Button variant="ghost" :disabled="form.processing" @click="closeModal">{{ $t('accountCenter.cancel') }}</Button>
            <Button variant="danger" :loading="form.processing" :disabled="!form.password" @click="deleteUser">{{ $t('accountCenter.deletePermanently') }}</Button>
        </template>
    </Modal>
</template>
