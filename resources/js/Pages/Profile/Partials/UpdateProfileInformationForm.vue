<script setup>
import TextInput from '@/Components/UI/TextInput.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { CheckCircle2, UserRound } from 'lucide-vue-next';

defineProps({
    mustVerifyEmail: Boolean,
    status: String,
});

const user = usePage().props.auth.user;
const form = useForm({
    name: user.name,
    email: user.email,
});

defineExpose({ form });
</script>

<template>
    <div class="flex items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-accent-50 text-accent-700">
                <UserRound class="h-5 w-5" />
            </span>
            <div>
                <h2 class="text-body font-bold text-primary-900">{{ $t('accountCenter.profileTitle') }}</h2>
                <p class="mt-0.5 text-body-sm text-neutral-500">{{ $t('accountCenter.profileDescription') }}</p>
            </div>
        </div>
        <span v-if="user.email_verified_at" class="hidden items-center gap-1.5 rounded-full bg-success-50 px-3 py-1 text-tiny font-bold text-success-700 sm:inline-flex">
            <CheckCircle2 class="h-3.5 w-3.5" /> {{ $t('accountCenter.verifiedEmail') }}
        </span>
    </div>

    <form id="profile-information-form" class="mt-6 space-y-5" @submit.prevent="form.patch(route('profile.update'), { preserveScroll: true })">
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="name" class="mb-1.5 block text-body-sm font-semibold text-primary-900">{{ $t('accountCenter.name') }}</label>
                <TextInput id="name" v-model="form.name" type="text" autocomplete="name" required autofocus :error="form.errors.name" />
                <p v-if="form.errors.name" class="mt-1 text-tiny text-error-600">{{ form.errors.name }}</p>
            </div>

            <div>
                <label for="email" class="mb-1.5 block text-body-sm font-semibold text-primary-900">{{ $t('accountCenter.email') }}</label>
                <TextInput id="email" v-model="form.email" type="email" autocomplete="username" required :error="form.errors.email" />
                <p v-if="form.errors.email" class="mt-1 text-tiny text-error-600">{{ form.errors.email }}</p>
                <p v-else class="mt-1 text-tiny text-neutral-400">{{ $t('accountCenter.emailHint') }}</p>
            </div>

        </div>

        <div v-if="mustVerifyEmail && !user.email_verified_at" class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-body-sm text-warning-800">
            {{ $t('accountCenter.unverifiedEmail') }}
            <Link :href="route('verification.send')" method="post" as="button" class="font-semibold underline underline-offset-2">
                {{ $t('accountCenter.resendVerification') }}
            </Link>
            <p v-if="status === 'verification-link-sent'" class="mt-2 font-semibold text-success-700">{{ $t('accountCenter.verificationSent') }}</p>
        </div>

    </form>
</template>
