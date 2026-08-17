<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ReservationCalendar from '@/Components/Reservations/ReservationCalendar.vue';
import { useRealtimeReload } from '@/composables/useRealtimeReload';

// Realtime (task #345): rezervimi i ri/i ndryshuar nga çdo burim (web, OTA,
// recepsion tjetër) shfaqet vetiu — vetëm të dhënat, pa prekur gjendjen e UI.
useRealtimeReload('reservations', '.reservation.changed', ['reservations', 'rooms', 'guests', 'conflicts']);

defineProps({
    rooms: Array,
    reservations: Array,
    guests: Array,
    startDate: String,
    endDate: String,
    visibleDays: { type: Number, default: 14 },
    channelFees: { type: Object, default: () => ({}) },
    conflicts: { type: Array, default: () => [] },
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
            :conflicts="conflicts"
            :available-day-ranges="[7, 14, 30]"
            @navigate="navigate"
        />
    </AppLayout>
</template>
