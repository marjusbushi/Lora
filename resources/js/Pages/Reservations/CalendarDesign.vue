<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ReservationCalendar from '@/Components/Reservations/ReservationCalendar.vue';
import { useReservationCalendarDemo } from '@/composables/useReservationCalendarDemo';

const {
    rooms,
    reservations,
    guests,
    startDate,
    endDate,
    visibleDays,
    navigate,
} = useReservationCalendarDemo();
</script>

<template>
    <Head :title="$t('admin.calendarPreview.pageTitle')" />
    <AppLayout>
        <ReservationCalendar
            demo
            :rooms="rooms"
            :reservations="reservations"
            :guests="guests"
            :start-date="startDate"
            :end-date="endDate"
            :visible-days="visibleDays"
            :available-day-ranges="[7, 14, 30]"
            @navigate="navigate"
        />
    </AppLayout>
</template>
