<script setup>
import { Link } from '@inertiajs/vue3';
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    currency: { type: String, default: '€' },
});

const money = (v) => `${props.currency}${Number(v ?? 0).toLocaleString('sq-AL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const statusBadge = {
    confirmed: { variant: 'info', label: 'Konfirmuar' },
    checked_in: { variant: 'success', label: 'Brenda' },
    checked_out: { variant: 'neutral', label: 'Larguar' },
};
const fmt = (d) => d ? new Date(d).toLocaleDateString('sq-AL', { day: '2-digit', month: 'short' }) : '—';
</script>

<template>
    <ReportShell title="Bilance të Papaguara">
        <Card class="mb-4 bg-error-50/50 ring-1 ring-error-200/40">
            <div class="flex items-center justify-between">
                <p class="text-body-sm text-neutral-600">Borxhi total i papaguar</p>
                <p class="text-h2 text-error-600">{{ money(total) }}</p>
            </div>
            <p class="text-tiny text-neutral-500 mt-1">{{ rows.length }} qëndrim(e) me balancë të hapur</p>
        </Card>

        <Card :padding="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Mysafiri</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Dhoma</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Statusi</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Periudha</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Faturë</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Paguar</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Mbetet</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="r in rows" :key="r.id" class="hover:bg-neutral-50">
                            <td class="px-5 py-3">
                                <Link :href="route('reservations.show', r.id)" class="text-body-sm text-primary-900 font-medium hover:underline">{{ r.guest }}</Link>
                                <p v-if="r.phone" class="text-tiny text-neutral-400">{{ r.phone }}</p>
                            </td>
                            <td class="px-5 py-3 text-body-sm text-neutral-700">{{ r.room }}</td>
                            <td class="px-5 py-3"><Badge :variant="statusBadge[r.status]?.variant || 'neutral'" size="sm">{{ statusBadge[r.status]?.label || r.status }}</Badge></td>
                            <td class="px-5 py-3 text-body-sm text-neutral-500 whitespace-nowrap">{{ fmt(r.check_in) }} → {{ fmt(r.check_out) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ money(r.gross) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-success-700">{{ money(r.paid) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm font-semibold text-error-600">{{ money(r.balance) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="!rows.length" class="px-6 py-12 text-center text-body-sm text-neutral-500">Asnjë borxh i hapur. Të gjitha faturat janë të mbyllura. ✅</div>
        </Card>
    </ReportShell>
</template>
