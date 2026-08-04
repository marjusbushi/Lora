<script setup>
import { getIntlLocale } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import { ArrowDownToLine, ArrowUpFromLine, Landmark, Scale } from 'lucide-vue-next';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    filters: Object,
    accounts: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
    totals: Object,
    bankAccounts: { type: Array, default: () => [] },
    currency: { type: String, default: '€' },
});

const money = (v) => `${props.currency}${Number(v ?? 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const moneyIn = (code, v) => {
    try {
        return new Intl.NumberFormat(getIntlLocale(), { style: 'currency', currency: code || 'EUR' }).format(Number(v ?? 0));
    } catch {
        return `${Number(v ?? 0).toFixed(2)} ${code}`;
    }
};

function changeAccount(event) {
    router.get(route('reports.bankPayments'), {
        from: props.filters?.from,
        to: props.filters?.to,
        account_id: event.target.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

const directionLabel = { in: 'Hyrje', out: 'Dalje', transfer: 'Transfertë' };
const sourceLabel = { auto: 'Automatike', manual: 'Manuale' };
const methodLabel = { cash: 'Cash', card: 'Kartë', bank: 'Bankë', room_charge: 'Në dhomë' };

const kpis = [
    { label: 'Hyrje në bankë', value: () => money(props.totals?.in_base), tone: 'success', icon: ArrowDownToLine },
    { label: 'Dalje nga banka', value: () => money(props.totals?.out_base), tone: 'error', icon: ArrowUpFromLine },
    { label: 'Neto në periudhë', value: () => money(props.totals?.net_base), tone: () => Number(props.totals?.net_base ?? 0) >= 0 ? 'success' : 'error', icon: Scale },
    { label: 'Lëvizje', value: () => props.rows.length, tone: 'neutral', icon: Landmark },
];
</script>

<template>
    <ReportShell title="Pagesat në Bankë" route-name="reports.bankPayments" :filters="filters" :query="{ account_id: filters?.account_id || undefined }">
        <template #filters>
            <div>
                <label class="mb-1.5 block text-label text-neutral-600">Llogaria bankare</label>
                <select
                    class="h-11 rounded-lg border-neutral-300 px-3 text-body-sm focus:border-accent-500 focus:ring-accent-500"
                    :value="filters?.account_id || ''"
                    @change="changeAccount"
                >
                    <option value="">Të gjitha llogaritë</option>
                    <option v-for="account in bankAccounts" :key="account.id" :value="account.id">{{ account.name }} ({{ account.currency }})</option>
                </select>
            </div>
        </template>

        <ReportKpiGrid :items="kpis" />

        <!-- Per-account summaries in the account's own currency -->
        <div v-if="accounts.length" class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <Card v-for="account in accounts" :key="account.id" class="space-y-1.5">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-body-sm font-semibold text-primary-900">{{ account.name }}</p>
                    <Badge variant="neutral" size="sm">{{ account.currency }}</Badge>
                </div>
                <div class="flex justify-between text-body-sm text-neutral-600"><span>Hyrje</span><span class="text-success-700">{{ moneyIn(account.currency, account.in) }}</span></div>
                <div class="flex justify-between text-body-sm text-neutral-600"><span>Dalje</span><span class="text-error-700">{{ moneyIn(account.currency, account.out) }}</span></div>
                <div class="flex justify-between border-t border-neutral-100 pt-1.5 text-body-sm font-semibold text-primary-900"><span>Neto</span><span>{{ moneyIn(account.currency, account.net) }}</span></div>
            </Card>
        </div>

        <Card :padding="false" class="mt-5">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-label text-neutral-600">Data</th>
                            <th class="px-4 py-3 text-left text-label text-neutral-600">Llogaria</th>
                            <th class="px-4 py-3 text-left text-label text-neutral-600">Përshkrimi</th>
                            <th class="px-4 py-3 text-left text-label text-neutral-600">Metoda</th>
                            <th class="px-4 py-3 text-left text-label text-neutral-600">Lloji</th>
                            <th class="px-4 py-3 text-right text-label text-neutral-600">Shuma</th>
                            <th class="px-4 py-3 text-right text-label text-neutral-600">Në bazë</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in rows" :key="row.id" class="hover:bg-neutral-50">
                            <td class="whitespace-nowrap px-4 py-3 text-body-sm text-neutral-500">{{ new Date(row.paid_at).toLocaleString(getIntlLocale(), { dateStyle: 'medium', timeStyle: 'short' }) }}</td>
                            <td class="px-4 py-3 text-body-sm text-primary-900 font-medium whitespace-nowrap">
                                <template v-if="row.direction === 'transfer'">{{ row.account }} → {{ row.counter_account }}</template>
                                <template v-else>{{ row.account }}</template>
                            </td>
                            <td class="max-w-72 truncate px-4 py-3 text-body-sm text-neutral-600">{{ row.description || '—' }}</td>
                            <td class="px-4 py-3 text-body-sm text-neutral-600">{{ methodLabel[row.method] || row.method || '—' }}</td>
                            <td class="px-4 py-3">
                                <Badge :variant="row.incoming === true ? 'success' : (row.incoming === false ? 'error' : 'neutral')" size="sm">
                                    {{ directionLabel[row.direction] || row.direction }}{{ row.source === 'auto' ? '' : ` · ${sourceLabel[row.source] || row.source}` }}
                                </Badge>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-body-sm font-medium" :class="row.incoming === false ? 'text-error-700' : 'text-primary-900'">
                                {{ row.incoming === false ? '−' : '+' }}{{ moneyIn(row.currency, row.amount) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-body-sm text-neutral-600">{{ row.incoming === false ? '−' : '+' }}{{ money(row.amount_base) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rows.length" class="border-t-2 border-neutral-200 bg-neutral-50">
                        <tr>
                            <td class="px-4 py-3 text-body-sm font-semibold text-primary-900" colspan="5">Totali (në {{ currency }})</td>
                            <td class="px-4 py-3 text-right text-body-sm font-semibold" colspan="2">
                                +{{ money(totals?.in_base) }} / −{{ money(totals?.out_base) }} · Neto {{ money(totals?.net_base) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div v-if="!rows.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">Asnjë lëvizje bankare në këtë periudhë.</div>
        </Card>
    </ReportShell>
</template>
