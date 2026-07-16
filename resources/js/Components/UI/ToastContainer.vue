<script setup>
import { ref } from 'vue';
import Toast from './Toast.vue';

const toasts = ref([]);
let nextId = 0;

function add({ variant = 'success', message, duration = 4000 }) {
    const id = nextId++;
    toasts.value.push({ id, variant, message, duration });
}

function remove(id) {
    toasts.value = toasts.value.filter((t) => t.id !== id);
}

defineExpose({ add, success: (msg) => add({ variant: 'success', message: msg }), error: (msg) => add({ variant: 'error', message: msg }), warning: (msg) => add({ variant: 'warning', message: msg }), info: (msg) => add({ variant: 'info', message: msg }) });
</script>

<template>
    <Teleport to="body">
        <div class="fixed bottom-4 right-4 z-[120] flex flex-col-reverse gap-2 max-w-sm w-full pointer-events-none">
            <div v-for="toast in toasts" :key="toast.id" class="pointer-events-auto">
                <Toast
                    :variant="toast.variant"
                    :message="toast.message"
                    :duration="toast.duration"
                    @dismiss="remove(toast.id)"
                />
            </div>
        </div>
    </Teleport>
</template>
