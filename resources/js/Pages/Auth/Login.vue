<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Button from '@/Components/UI/Button.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import Checkbox from '@/Components/UI/Checkbox.vue';
import Alert from '@/Components/UI/Alert.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: { type: Boolean },
    status: { type: String },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head :title="$t('admin.generated.k_737969ef8672')" />

        <div>
            <h2 class="text-h3 text-primary-900 mb-1">{{ $t('admin.generated.k_9fdd8aa8fc78') }}</h2>
            <p class="text-body-sm text-neutral-500 mb-6">{{ $t('admin.generated.k_20e74fbc4731') }}</p>
        </div>

        <Alert v-if="status" variant="success" class="mb-4">{{ status }}</Alert>

        <form @submit.prevent="submit" class="space-y-5">
            <div class="space-y-1.5">
                <InputLabel for="email">
                    {{ $t('admin.generated.k_46418004f188') }}
                    <span class="text-error-500">*</span>
                </InputLabel>
                <TextInput
                    id="email"
                    name="email"
                    type="email"
                    class="block w-full border-neutral-200 px-3 py-2 text-body-sm text-neutral-900 placeholder:text-neutral-400 focus:border-accent-500 focus:ring-accent-500/40"
                    v-model="form.email"
                    :placeholder="$t('admin.generated.k_53da0e246d4b')"
                    inputmode="email"
                    autocapitalize="none"
                    :spellcheck="false"
                    required
                    autofocus
                    autocomplete="username"
                />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="space-y-1.5">
                <InputLabel for="password">
                    {{ $t('admin.generated.k_2f77fb4fd984') }}
                    <span class="text-error-500">*</span>
                </InputLabel>
                <TextInput
                    id="password"
                    name="password"
                    type="password"
                    class="block w-full border-neutral-200 px-3 py-2 text-body-sm text-neutral-900 placeholder:text-neutral-400 focus:border-accent-500 focus:ring-accent-500/40"
                    v-model="form.password"
                    placeholder="********"
                    required
                    autocomplete="current-password"
                />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <Checkbox v-model="form.remember" :label="$t('admin.generated.k_fcb0f2e8be42')" />

                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="text-body-sm text-neutral-500 hover:text-accent-600 no-underline"
                >
{{ $t('admin.generated.k_a73a425da6c4') }} </Link>
            </div>

            <Button
                type="submit"
                variant="primary"
                size="lg"
                class="w-full"
                :loading="form.processing"
                :disabled="form.processing"
            >
{{ $t('admin.generated.k_f4a86467e5c2') }} </Button>
        </form>
    </GuestLayout>
</template>
