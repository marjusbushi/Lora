<script setup>
import Card from '@/Components/UI/Card.vue';
import UpdatePasswordForm from '@/Pages/Profile/Partials/UpdatePasswordForm.vue';
</script>

<template>
    <Card>
        <template #header>
            <div>
                <h3 class="text-h4 text-primary-900">{{ $t('settingsTabs.security.title') }}</h3>
                <p class="mt-1 text-body-sm text-neutral-500">{{ $t('settingsTabs.security.subtitle') }}</p>
            </div>
        </template>

        <UpdatePasswordForm />
    </Card>
</template>
