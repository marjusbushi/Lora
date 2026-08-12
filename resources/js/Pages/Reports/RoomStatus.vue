<script setup>
import { translate } from '@/i18n';
import ReportShell from '@/Components/UI/ReportShell.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import ReportKpiGrid from '@/Components/UI/ReportKpiGrid.vue';
import InfoTip from '@/Components/UI/InfoTip.vue';
import { BedDouble, BrushCleaning, CircleCheck, TriangleAlert, Wrench } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';
import { useReportDrilldown } from '@/composables/useReportDrilldown';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    counts: { type: Object, default: () => ({}) },
    currency: { type: String, default: '€' },
});
const { can } = useReportDrilldown();
const roomHref = (row) => can('view_rooms') ? route('rooms.index', { room: row.id }) : null;

const statusMeta = {
    available: { label: translate('admin.generated.k_8e465c876aa5'), variant: 'success', color: 'text-success-600' },
    occupied: { label: translate('admin.generated.k_4d6e12a5f5f7'), variant: 'info', color: 'text-info-600' },
    cleaning: { label: translate('admin.generated.k_5daca44ff509'), variant: 'warning', color: 'text-warning-600' },
    maintenance: { label: translate('admin.generated.k_18c1d1d6d854'), variant: 'error', color: 'text-error-600' },
};

const meta = (s) => statusMeta[s] ?? { label: s, variant: 'neutral', color: 'text-neutral-600' };

const tiles = [
    { key: 'available', label: translate('admin.generated.k_982cdadfe721'), tone: 'success', icon: CircleCheck, help: translate('reports360.help.rsAvailable') },
    { key: 'occupied', label: translate('admin.generated.k_c87322ba2f95'), tone: 'info', icon: BedDouble, help: translate('reports360.help.rsOccupied') },
    { key: 'cleaning', label: translate('admin.generated.k_34ccff72a065'), tone: 'warning', icon: BrushCleaning, help: translate('reports360.help.rsCleaning') },
    { key: 'maintenance', label: translate('admin.generated.k_18c1d1d6d854'), tone: 'error', icon: Wrench, help: translate('reports360.help.rsMaintenance') },
];

const kpis = tiles.map((tile) => ({
    label: tile.label,
    help: tile.help,
    value: () => props.counts[tile.key] ?? 0,
    tone: tile.tone,
    icon: tile.icon,
    href: can('view_rooms') ? route('rooms.index', { status: tile.key }) : null,
}));

const staleReason = (row) => row.stale ? translate(`reports360.roomStatus.staleReasons.${row.stale}`) : null;
</script>

<template>
    <ReportShell :title="$t('admin.generated.k_7b1933c35a94')" :filters="null">
        <ReportKpiGrid :items="kpis" />

        <div v-if="(counts.stale ?? 0) > 0" class="mt-4 flex items-center gap-2 rounded-lg border border-warning-200 bg-warning-50 px-4 py-3 text-body-sm text-warning-800">
            <TriangleAlert class="h-4 w-4 shrink-0" />
            <span>{{ $t('reports360.roomStatus.staleCount', { n: counts.stale }) }}</span>
        </div>

        <div class="mt-6">
            <Card :padding="false">
                <div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4">
                    <h2 class="flex items-center gap-1 text-body font-semibold text-primary-900">{{ $t('reports360.roomStatus.allRooms') }}<InfoTip :text="$t('reports360.help.rsStoredVsLive')" :label="$t('reports360.roomStatus.allRooms')" /></h2>
                </div>
                <table class="min-w-full divide-y divide-neutral-200">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_fa41f68f7032') }}</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_183027816056') }}</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_6d029eb0ba6c') }}</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">{{ $t('admin.generated.k_10fba5c38cf7') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="row in rows" :key="row.id" class="hover:bg-neutral-50">
                            <td class="px-5 py-3 text-body-sm text-primary-900 font-medium">
                                <span class="inline-flex items-center gap-1.5">
                                    <Link v-if="roomHref(row)" :href="roomHref(row)" class="hover:underline">{{ row.room_number }}</Link><span v-else>{{ row.room_number }}</span>
                                    <span v-if="row.maintenance_open" :title="$t('reports360.roomStatus.maintenanceOpen')" :aria-label="$t('reports360.roomStatus.maintenanceOpen')" role="img" class="inline-flex text-warning-600"><Wrench class="h-3.5 w-3.5" /></span>
                                </span>
                            </td>
                            <td class="px-5 py-3 text-body-sm text-neutral-700">{{ row.floor ?? '—' }}</td>
                            <td class="px-5 py-3 text-body-sm text-neutral-700">{{ row.room_type }}</td>
                            <td class="px-5 py-3 text-body-sm">
                                <span class="inline-flex flex-wrap items-center gap-1.5">
                                    <Badge :variant="meta(row.status).variant">{{ meta(row.status).label }}</Badge>
                                    <Badge v-if="row.stale" variant="warning">{{ $t('reports360.roomStatus.staleChip') }}</Badge>
                                </span>
                                <p v-if="row.stale" class="mt-1 text-tiny text-warning-700">{{ staleReason(row) }}</p>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rows.length" class="bg-neutral-50 border-t-2 border-neutral-200">
                        <tr class="font-semibold text-neutral-800">
                            <td class="px-5 py-3 text-body-sm" colspan="4">{{ $t('admin.generated.k_d2300f13eaa2') }} {{ counts.total ?? rows.length }} {{ $t('admin.generated.k_a693720ab5a4') }}</td>
                        </tr>
                    </tfoot>
                </table>
                <div v-if="!rows.length" class="px-6 py-10 text-center text-body-sm text-neutral-500">{{ $t('admin.generated.k_9404149fbc59') }}</div>
            </Card>
        </div>
    </ReportShell>
</template>
