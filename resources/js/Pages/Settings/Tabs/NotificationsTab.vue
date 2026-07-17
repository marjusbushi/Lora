<script setup>
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
    hotelEmail: { type: String, default: '' },
    toasts: Object,
});

const form = useForm({
    email_new_reservations: props.settings.email_new_reservations ?? true,
});

function submit() {
    form.put(route('settings.notifications'), {
        preserveScroll: true,
        onSuccess: () => props.toasts?.success('Njoftimet u ruajtën.'),
    });
}
</script>

<template>
    <Card>
        <template #header>
            <div>
                <h3 class="text-h4 text-primary-900">Njoftimet</h3>
                <p class="mt-1 text-body-sm text-neutral-500">Zgjidh cilat ngjarje operative dërgojnë email.</p>
            </div>
        </template>

        <form class="space-y-5" @submit.prevent="submit">
            <label class="flex items-start justify-between gap-5 rounded-xl border border-neutral-200 p-4">
                <span>
                    <strong class="block text-body-sm text-primary-900">Rezervim i ri</strong>
                    <small class="mt-1 block text-small text-neutral-500">
                        Dërgo email te {{ hotelEmail || 'email-i i hotelit' }} kur krijohet një rezervim.
                    </small>
                </span>
                <input v-model="form.email_new_reservations" type="checkbox" class="mt-0.5 h-5 w-5 rounded border-neutral-300 text-accent-700 focus:ring-accent-600" />
            </label>

            <p v-if="!hotelEmail" class="rounded-lg border border-warning-200 bg-warning-50 px-4 py-3 text-body-sm text-warning-800">
                Vendos email-in te “Të dhënat e hotelit” që njoftimet të mund të dërgohen.
            </p>

            <div class="flex justify-end">
                <Button type="submit" :loading="form.processing">Ruaj njoftimet</Button>
            </div>
        </form>
    </Card>
</template>
