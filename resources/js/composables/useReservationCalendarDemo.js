import { computed, ref } from 'vue';

function startOfDay(value) {
    const date = new Date(value);
    date.setHours(12, 0, 0, 0);
    return date;
}

function addDays(value, amount) {
    const date = startOfDay(value);
    date.setDate(date.getDate() + amount);
    return date;
}

function isoDate(value) {
    const date = startOfDay(value);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

const DEMO_ROOMS = [
    [1, '101', 'Deluxe Sea View', 1, 1, 'available'],
    [2, '102', 'Deluxe Sea View', 1, 1, 'available'],
    [3, '103', 'Double Garden', 2, 1, 'available'],
    [4, '104', 'Double Garden', 2, 1, 'available'],
    [5, '201', 'Junior Suite', 3, 2, 'available'],
    [6, '202', 'Junior Suite', 3, 2, 'available'],
    [7, '203', 'Family Suite', 4, 2, 'available'],
    [8, '204', 'Family Suite', 4, 2, 'maintenance'],
].map(([id, room_number, name, room_type_id, floor, status]) => ({
    id,
    room_number,
    floor,
    status,
    room_type_id,
    room_type: { id: room_type_id, name },
}));

const DEMO_RESERVATIONS = [
    [101, 1, 'Elena', 'Rossi', -1, 4, 'checked_in', 'booking.com', 420, 420, 'Late breakfast requested.'],
    [102, 1, 'Lukas', 'Weber', 5, 4, 'confirmed', 'direct', 160, 560, 'Sea-view room preferred.'],
    [103, 2, 'Sophie', 'Martin', 1, 5, 'confirmed', 'airbnb', 725, 725, 'Allergic to nuts.'],
    [104, 3, 'Arben', 'Kola', 0, 2, 'pending', 'direct', 0, 180, 'Confirmation call required.'],
    [105, 3, 'Nora', 'Jensen', 7, 3, 'confirmed', 'expedia', 330, 330, null],
    [106, 4, 'Marco', 'Bianchi', 3, 6, 'confirmed', 'booking.com', 200, 690, 'Needs baby cot.'],
    [107, 5, 'Amelia', 'Brown', -2, 5, 'checked_in', 'direct', 780, 780, null],
    [108, 5, 'Dritan', 'Hoxha', 6, 4, 'confirmed', 'direct', 200, 640, null],
    [109, 6, 'Emma', 'Wilson', 2, 3, 'confirmed', 'airbnb', 465, 465, null],
    [110, 7, 'Familja', 'Gashi', 0, 7, 'checked_in', 'booking.com', 500, 1190, 'Two extra pillows and a baby cot.'],
    [111, 7, 'Oliver', 'Smith', 9, 4, 'pending', 'expedia', 0, 680, null],
    [112, 5, 'Sara', 'Müller', 5, 5, 'confirmed', 'booking.com', 250, 800, 'Airport transfer requested.'],
];

export function useReservationCalendarDemo() {
    const anchorDate = ref(startOfDay(new Date()));
    const visibleDays = ref(14);

    const startDate = computed(() => isoDate(anchorDate.value));
    const endDate = computed(() => isoDate(addDays(anchorDate.value, visibleDays.value - 1)));
    const rooms = computed(() => DEMO_ROOMS);
    const reservations = computed(() => DEMO_RESERVATIONS.map(([
        id, room_id, first_name, last_name, start, nights, status, channel, paid_amount, total_amount, notes,
    ]) => ({
        id,
        room_id,
        guest: {
            id,
            first_name,
            last_name,
            phone: '+355 69 000 0000',
            email: `${first_name}.${last_name}@example.com`.toLowerCase(),
            nationality: 'Demo',
        },
        check_in_date: isoDate(addDays(anchorDate.value, start)),
        check_out_date: isoDate(addDays(anchorDate.value, start + nights)),
        status,
        channel,
        paid_amount,
        total_amount,
        adults: 2,
        children: 0,
        notes,
        channel_ref: `DEMO-${id}`,
        created_at: addDays(anchorDate.value, start - 5).toISOString(),
        eta: '15:00',
        booking_group_id: null,
    })));
    const guests = computed(() => reservations.value.map((reservation) => reservation.guest));
    const conflicts = computed(() => {
        const reservationIds = [108, 112];

        return [{
            id: 'demo-room-201-overlap',
            room_id: 5,
            room_number: '201',
            room_type: 'Junior Suite',
            start_date: isoDate(addDays(anchorDate.value, 6)),
            end_date: isoDate(addDays(anchorDate.value, 10)),
            reservations: reservations.value
                .filter((reservation) => reservationIds.includes(reservation.id))
                .map((reservation) => ({
                    ...reservation,
                    keep_in_room: reservation.id === 108,
                    suggested_rooms: reservation.id === 112 ? [
                        { id: 6, room_number: '202', room_type: 'Junior Suite', same_type: true },
                        { id: 7, room_number: '203', room_type: 'Family Suite', same_type: false },
                    ] : [],
                })),
        }];
    });

    function navigate({ start, days }) {
        anchorDate.value = startOfDay(`${start}T12:00:00`);
        visibleDays.value = days;
    }

    return {
        rooms,
        reservations,
        guests,
        conflicts,
        startDate,
        endDate,
        visibleDays,
        navigate,
    };
}
