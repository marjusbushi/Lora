<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ReservationCalendar from '@/Components/Reservations/ReservationCalendar.vue';

defineProps({
    rooms: Array,
    reservations: Array,
    guests: Array,
    startDate: String,
    endDate: String,
    visibleDays: { type: Number, default: 14 },
    channelFees: { type: Object, default: () => ({}) },
});

function navigate({ start, days }) {
    router.get(route('reservations.calendar'), { start, days }, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('admin.calendarPreview.title')" />
    <AppLayout>
        <ReservationCalendar
            :rooms="rooms"
            :reservations="reservations"
            :guests="guests"
            :start-date="startDate"
            :end-date="endDate"
            :visible-days="visibleDays"
            :channel-fees="channelFees"
            :available-day-ranges="[14]"
            @navigate="navigate"
        />
    </AppLayout>
</template>
