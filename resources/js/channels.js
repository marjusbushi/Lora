// Booking channels (source of a reservation). Single source of truth for the
// "Burimi" column badge + the manual-create dropdown. IDs MUST match the backend
// Reservation::CHANNELS list and the channel-manager vocabulary (lowercase/dotted).
export const CHANNELS = [
    { id: 'manual', label: 'Manual', color: '#6B7280' },
    { id: 'direct', label: 'Direkt', color: '#2E6E72' },
    { id: 'booking.com', label: 'Booking.com', color: '#003B95' },
    { id: 'expedia', label: 'Expedia', color: '#00355F' },
    { id: 'airbnb', label: 'Airbnb', color: '#FF5A5F' },
    { id: 'agoda', label: 'Agoda', color: '#6D28D9' },
    { id: 'hotels.com', label: 'Hotels.com', color: '#D32F2F' },
    { id: 'vrbo', label: 'Vrbo', color: '#1E40AF' },
    { id: 'trip.com', label: 'Trip.com', color: '#287DFA' },
    { id: 'hostelworld', label: 'Hostelworld', color: '#F97316' },
    { id: 'google', label: 'Google', color: '#4285F4' },
    { id: 'tripadvisor', label: 'Tripadvisor', color: '#00AA6C' },
];

const BY_ID = Object.fromEntries(CHANNELS.map((c) => [c.id, c]));

// Null/empty/legacy reservations (created before channels existed) read as Manual.
export function channelMeta(id) {
    if (!id) return BY_ID.manual;
    return BY_ID[id] || { id, label: id, color: '#6B7280' };
}

// For <Select :options="channelOptions" />
export const channelOptions = CHANNELS.map((c) => ({ value: c.id, label: c.label }));
