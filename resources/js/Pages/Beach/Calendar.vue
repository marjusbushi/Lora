<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BeachCalendar from '@/Components/Beach/BeachCalendar.vue';

const props = defineProps({
    zones: { type: Array, default: () => [] },
    reservations: { type: Array, default: () => [] },
    startDate: { type: String, required: true },
    endDate: { type: String, required: true },
    visibleDays: { type: Number, default: 14 },
    hotelToday: { type: String, required: true },
    season: { type: Object, default: () => ({ start: '', end: '' }) },
});
</script>

<template>
    <AppLayout>
        <Head :title="$t('beach.calendar.title')" />
        <BeachCalendar
            :zones="zones"
            :reservations="reservations"
            :start-date="startDate"
            :visible-days="visibleDays"
            :hotel-today="hotelToday"
            :season="season"
        />
    </AppLayout>
</template>
