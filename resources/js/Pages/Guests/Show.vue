<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps({
    guest: Object,
    stays: Array,
    stats: Object,
    duplicates: Array,
});

const docTypeLabel = { id_card: 'Karte identiteti', passport: 'Pasaporte', drivers_license: 'Patente' };

const statusBadge = {
    pending: { variant: 'warning', label: 'Ne pritje' },
    confirmed: { variant: 'info', label: 'Konfirmuar' },
    checked_in: { variant: 'success', label: 'Brenda' },
    checked_out: { variant: 'neutral', label: 'Larguar' },
    cancelled: { variant: 'error', label: 'Anulluar' },
};

function formatDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('sq-AL', { day: '2-digit', month: 'short', year: 'numeric' });
}
</script>

<template>
    <AppLayout>
        <PageHeader
            :title="`${guest.first_name} ${guest.last_name}`"
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Mysafiret', href: route('guests.index') }, { label: `${guest.first_name} ${guest.last_name}` }]"
        >
            <template #actions>
                <Link :href="route('guests.index')" class="no-underline">
                    <Button variant="outline">← Mysafiret</Button>
                </Link>
            </template>
        </PageHeader>

        <!-- Stats -->
        <div class="mt-6 grid grid-cols-3 gap-3">
            <Card>
                <div class="text-center">
                    <p class="text-h3 text-primary-900">{{ stats.total_stays }}</p>
                    <p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">Qendrime</p>
                </div>
            </Card>
            <Card>
                <div class="text-center">
                    <p class="text-h3 text-primary-900">{{ stats.total_nights }}</p>
                    <p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">Nete gjithsej</p>
                </div>
            </Card>
            <Card>
                <div class="text-center">
                    <p class="text-h3 text-accent-600">€{{ Number(stats.lifetime_spend).toFixed(2) }}</p>
                    <p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">Vlera totale</p>
                </div>
            </Card>
        </div>

        <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Profile details -->
            <Card class="lg:col-span-1">
                <h3 class="text-label text-neutral-600 uppercase tracking-wider mb-4">Profili</h3>
                <dl class="space-y-3">
                    <div class="flex justify-between">
                        <dt class="text-body-sm text-neutral-500">Email</dt>
                        <dd class="text-body-sm text-neutral-700 text-right">{{ guest.email || '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-body-sm text-neutral-500">Telefon</dt>
                        <dd class="text-body-sm text-neutral-700 text-right">{{ guest.phone || '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-body-sm text-neutral-500">Kombesia</dt>
                        <dd class="text-body-sm text-neutral-700 text-right">{{ guest.nationality || '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-body-sm text-neutral-500">Dokument</dt>
                        <dd class="text-body-sm text-neutral-700 text-right">
                            {{ guest.document_type ? docTypeLabel[guest.document_type] : '—' }}
                            <span v-if="guest.document_number" class="text-neutral-400">· {{ guest.document_number }}</span>
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-body-sm text-neutral-500">Datelindja</dt>
                        <dd class="text-body-sm text-neutral-700 text-right">{{ formatDate(guest.date_of_birth) }}</dd>
                    </div>
                    <div v-if="guest.notes" class="border-t border-neutral-100 pt-3">
                        <dt class="text-body-sm text-neutral-500 mb-1">Shenime</dt>
                        <dd class="text-body-sm text-neutral-700">{{ guest.notes }}</dd>
                    </div>
                </dl>

                <!-- Possible duplicates -->
                <div v-if="duplicates.length" class="mt-5 rounded-lg bg-warning-50 border border-warning-200 px-4 py-3">
                    <p class="text-body-sm text-warning-800 font-medium mb-1">Mundesi dublikate ({{ duplicates.length }})</p>
                    <ul class="space-y-1">
                        <li v-for="d in duplicates" :key="d.id" class="text-small text-warning-700">
                            <Link :href="route('guests.show', d.id)" class="hover:underline">
                                {{ d.first_name }} {{ d.last_name }}<span v-if="d.email"> · {{ d.email }}</span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </Card>

            <!-- Stay history -->
            <Card class="lg:col-span-2" :padding="false">
                <div class="px-5 py-4 border-b border-neutral-200">
                    <h3 class="text-label text-neutral-600 uppercase tracking-wider">Historiku i qendrimeve</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200">
                        <thead class="bg-neutral-50">
                            <tr>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Dhoma</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Check-in</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Check-out</th>
                                <th class="px-5 py-3 text-left text-label text-neutral-600">Statusi</th>
                                <th class="px-5 py-3 text-right text-label text-neutral-600">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-for="s in stays" :key="s.id" class="hover:bg-neutral-50">
                                <td class="px-5 py-3 text-body-sm text-primary-900">
                                    <Link :href="route('reservations.show', s.id)" class="hover:underline font-medium">
                                        {{ s.room }} <span class="text-neutral-400">{{ s.room_type }}</span>
                                    </Link>
                                </td>
                                <td class="px-5 py-3 text-body-sm text-neutral-700">{{ formatDate(s.check_in_date) }}</td>
                                <td class="px-5 py-3 text-body-sm text-neutral-700">{{ formatDate(s.check_out_date) }}</td>
                                <td class="px-5 py-3">
                                    <Badge :variant="statusBadge[s.status]?.variant" dot>{{ statusBadge[s.status]?.label }}</Badge>
                                </td>
                                <td class="px-5 py-3 text-right text-body-sm text-primary-900">€{{ Number(s.total_amount).toFixed(2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="!stays.length" class="px-6 py-12 text-center">
                    <p class="text-body-sm text-neutral-500">Ky mysafir nuk ka qendrime akoma.</p>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
