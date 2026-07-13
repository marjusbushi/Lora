<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import GuestProfileWorkspace from '@/Components/Guests/GuestProfileWorkspace.vue';

defineProps({
    guest: { type: Object, required: true },
    stays: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
    duplicates: { type: Array, default: () => [] },
    documents: { type: Array, default: () => [] },
    history: { type: Array, default: () => [] },
    aiConfigured: { type: Boolean, default: false },
});

const permissions = usePage().props.auth.user?.permissions || [];
const canUpdate = permissions.includes('update_guests');
const canMerge = canUpdate && permissions.includes('delete_guests');
</script>

<template>
    <Head :title="`${guest.first_name} ${guest.last_name}`" />
    <AppLayout>
        <GuestProfileWorkspace
            :initial-guest="guest"
            :stats="stats"
            :stays="stays"
            :documents="documents"
            :history="history"
            :duplicates="duplicates"
            :can-update="canUpdate"
            :can-merge="canMerge"
            :ai-configured="aiConfigured"
        />
    </AppLayout>
</template>
