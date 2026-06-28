<script setup>
import { Link } from '@inertiajs/vue3';
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    currency: { type: String, default: '€' },
});

const money = (v) => `${props.currency}${Number(v ?? 0).toLocaleString('sq-AL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const fmtDate = (d) => {
    if (!d) return '—';
    const [y, m, day] = String(d).split('-');
    return `${day}/${m}/${y}`;
};

const kpis = [
    { label: 'Mysafirë gjithsej', value: () => props.summary.total_guests ?? 0 },
    { label: 'Mysafirë kthyes', value: () => props.summary.repeat_guests ?? 0, accent: true },
    { label: 'Norma e kthimit', value: () => `${Number(props.summary.repeat_rate ?? 0).toLocaleString('sq-AL')}%` },
];
</script>

<template>
    <ReportShell title="Mysafirë Kthyes &amp; Top" :filters="null">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <Card v-for="k in kpis" :key="k.label">
                <div class="text-center">
                    <p :class="['text-h3 truncate', k.accent ? 'text-accent-600' : 'text-primary-900']">{{ k.value() }}</p>
                    <p class="text-tiny text-neutral-500 uppercase tracking-wider mt-1">{{ k.label }}</p>
                </div>
            </Card>
        </div>

        <div class="mt-6">
            <Card :padding="false">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Mysafiri</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Qëndrime</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Netë</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Shpenzuar</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Vizita e fundit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in rows" :key="row.id" class="hover:bg-neutral-50">
                            <td class="px-5 py-3 text-body-sm">
                                <div class="flex items-center gap-2">
                                    <Link :href="route('guests.show', row.id)" class="text-primary-900 font-medium hover:underline">{{ row.guest }}</Link>
                                    <Badge v-if="row.is_repeat" color="emerald">Kthyes</Badge>
                                </div>
                                <p v-if="row.email" class="text-tiny text-neutral-500">{{ row.email }}</p>
                            </td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ row.stays }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ row.nights }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-primary-900 font-medium">{{ money(row.total_spent) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-neutral-700">{{ fmtDate(row.last_visit) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rows.length" class="bg-neutral-50 border-t-2 border-neutral-200">
                        <tr class="font-semibold text-neutral-800">
                            <td class="px-5 py-3 text-body-sm">Totali ({{ rows.length }} të shfaqur)</td>
                            <td class="px-5 py-3 text-right text-body-sm">{{ rows.reduce((s, r) => s + (r.stays || 0), 0) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm">{{ rows.reduce((s, r) => s + (r.nights || 0), 0) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm">{{ money(rows.reduce((s, r) => s + (r.total_spent || 0), 0)) }}</td>
                            <td class="px-5 py-3"></td>
                        </tr>
                    </tfoot>
                </table>
                <div v-if="!rows.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë të dhënë.</div>
            </Card>
        </div>
    </ReportShell>
</template>
