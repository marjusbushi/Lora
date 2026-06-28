<script setup>
import { Link } from '@inertiajs/vue3';
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';

const props = defineProps({
    filters: Object,
    rows: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    currency: { type: String, default: '€' },
});

const fmt = (d) => d
    ? new Date(d).toLocaleDateString('sq-AL', { weekday: 'short', day: '2-digit', month: 'short' })
    : '—';
</script>

<template>
    <ReportShell title="Mysafirë në Shtëpi" :filters="null">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <Card>
                <div class="text-center">
                    <p class="text-h3 text-primary-900">{{ summary?.count ?? 0 }}</p>
                    <p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">Dhoma të zëna</p>
                </div>
            </Card>
            <Card>
                <div class="text-center">
                    <p class="text-h3 text-primary-900">{{ summary?.pax ?? 0 }}</p>
                    <p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">Persona gjithsej</p>
                </div>
            </Card>
        </div>

        <Card :padding="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Mysafiri</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Dhoma</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Check-in</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Check-out</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Netë</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Persona</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="r in rows" :key="r.id" class="hover:bg-neutral-50">
                            <td class="px-5 py-3">
                                <Link :href="route('reservations.show', r.id)" class="text-body-sm text-primary-900 font-medium hover:underline">{{ r.guest }}</Link>
                                <p v-if="r.phone" class="text-tiny text-neutral-400">{{ r.phone }}</p>
                            </td>
                            <td class="px-5 py-3">
                                <p class="text-body-sm text-neutral-700">{{ r.room || '—' }}</p>
                                <p v-if="r.room_type" class="text-tiny text-neutral-400">{{ r.room_type }}</p>
                            </td>
                            <td class="px-5 py-3 text-body-sm text-neutral-700 whitespace-nowrap">{{ fmt(r.check_in) }}</td>
                            <td class="px-5 py-3 text-body-sm text-neutral-700 whitespace-nowrap">{{ fmt(r.check_out) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ r.nights }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700 whitespace-nowrap">
                                {{ r.pax }}
                                <span class="text-tiny text-neutral-400">({{ r.adults }}+{{ r.children }})</span>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rows.length" class="bg-neutral-50 border-t-2 border-neutral-200">
                        <tr class="font-semibold">
                            <td class="px-5 py-3 text-body-sm text-neutral-700" colspan="4">Gjithsej ({{ summary?.count ?? 0 }})</td>
                            <td class="px-5 py-3"></td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ summary?.pax ?? 0 }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div v-if="!rows.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë të dhënë.</div>
        </Card>
    </ReportShell>
</template>
