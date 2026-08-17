<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Alert from '@/Components/UI/Alert.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <GuestLayout>
        <Head :title="$t('auth.forgot.title')" />

        <div>
            <h2 class="text-h3 text-primary-900 mb-1">{{ $t('auth.forgot.title') }}</h2>
            <p class="text-body-sm text-neutral-500 mb-6">{{ $t('auth.forgot.intro') }}</p>
        </div>

        <!--
            Gjendja e suksesit e zëvendëson formën: pas dërgimit s'ka çfarë të bëhet
            këtu, dhe një formë e mbetur e hapur fton dërgime të përsëritura.
        -->
        <template v-if="status">
            <Alert variant="success" class="mb-4">
                <p class="font-semibold">{{ $t('auth.forgot.sent') }}</p>
                <p class="mt-1 text-body-sm">{{ $t('auth.forgot.sentHint') }}</p>
            </Alert>

            <Link :href="route('login')" class="text-body-sm text-neutral-500 hover:text-accent-600 no-underline">
                {{ $t('auth.forgot.backToLogin') }}
            </Link>
        </template>

        <template v-else>
            <form class="space-y-5" @submit.prevent="submit">
                <div class="space-y-1.5">
                    <InputLabel for="email" :value="$t('auth.forgot.emailLabel')" />
                    <TextInput
                        id="email"
                        v-model="form.email"
                        type="email"
                        class="mt-1 block w-full"
                        :placeholder="$t('auth.forgot.emailPlaceholder')"
                        required
                        autofocus
                        autocomplete="username"
                    />
                    <InputError class="mt-2" :message="form.errors.email" />
                </div>

                <Button type="submit" class="w-full justify-center" :disabled="form.processing">
                    {{ form.processing ? $t('auth.forgot.submitting') : $t('auth.forgot.submit') }}
                </Button>
            </form>

            <!-- Rruga e daljes që i mungonte faqes: llogaritë i hap administratori. -->
            <p class="mt-6 text-body-sm text-neutral-500">{{ $t('auth.forgot.noEmailHelp') }}</p>

            <Link :href="route('login')" class="mt-3 inline-block text-body-sm text-neutral-500 hover:text-accent-600 no-underline">
                {{ $t('auth.forgot.backToLogin') }}
            </Link>
        </template>
    </GuestLayout>
</template>
