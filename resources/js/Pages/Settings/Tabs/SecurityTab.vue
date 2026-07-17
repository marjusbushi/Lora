<script setup>
import Card from '@/Components/UI/Card.vue';
import UpdatePasswordForm from '@/Pages/Profile/Partials/UpdatePasswordForm.vue';
</script>

<template>
    <Card>
        <template #header>
            <div>
                <h3 class="text-h4 text-primary-900">Siguria</h3>
                <p class="mt-1 text-body-sm text-neutral-500">Menaxho fjalëkalimin e llogarisë me të cilën je futur.</p>
            </div>
        </template>

        <UpdatePasswordForm />
    </Card>
</template>
