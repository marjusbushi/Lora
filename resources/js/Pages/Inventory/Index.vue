<script setup>
import { Link } from '@inertiajs/vue3';
import { AlertTriangle, ArrowDownToLine, ArrowLeftRight, Boxes, Package, Plus, Warehouse } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import { money } from '@/Pages/Finance/financeShared.js';
import { getIntlLocale, translate } from '@/i18n';

defineProps({ summary: Object, lowItems: Array, warehouses: Array, recentMovements: Array, can: Object });

const movementLabels = Object.fromEntries(['purchase', 'opening_balance', 'transfer_in', 'transfer_out', 'sale', 'adjustment', 'room_charge'].map((key) => [key, translate(`inventory.movementTypes.${key}`)]));
const unitLabels = { piece: translate('inventory.units.piece'), kg: translate('inventory.units.kg'), liter: translate('inventory.units.liter'), pack: translate('inventory.units.pack') };

function formatDate(value) {
    return value ? new Intl.DateTimeFormat(getIntlLocale(), { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : '—';
}
</script>

<template>
    <AppLayout>
        <div class="mx-auto max-w-[1500px] space-y-6">
            <div>
                <PageHeader :title="$t('inventory.overview.title')" :breadcrumbs="[{ label: $t('inventory.title') }]">
                    <template #actions>
                        <Link :href="route('inventory.warehouses')" class="inline-flex items-center gap-2 rounded-md border border-neutral-200 bg-white px-4 py-2 text-body-sm font-medium text-neutral-700 hover:bg-neutral-50">
                            <ArrowLeftRight class="h-4 w-4" /> {{ $t('inventory.actions.transfer') }}
                        </Link>
                        <Link v-if="can.manageInventory" :href="route('finance.bills.create')" class="inline-flex items-center gap-2 rounded-md bg-accent-600 px-4 py-2 text-body-sm font-medium text-white shadow-sm hover:bg-accent-700">
                            <Plus class="h-4 w-4" /> {{ $t('inventory.actions.purchase') }}
                        </Link>
                    </template>
                </PageHeader>
                <p class="mt-1 text-body-sm text-neutral-500">{{ $t('inventory.overview.subtitle') }}</p>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <Card>
                    <div class="flex items-start justify-between"><span class="text-body-sm font-semibold text-neutral-500">{{ $t('inventory.metrics.value') }}</span><span class="rounded-lg bg-accent-50 p-2 text-accent-700"><Boxes class="h-5 w-5" /></span></div>
                    <strong class="mt-4 block text-h2 text-primary-900">{{ money(summary.stock_value) }}</strong>
                    <p class="mt-1 text-tiny text-neutral-400">{{ summary.warehouse_count }} {{ $t('inventory.metrics.warehouses') }}</p>
                </Card>
                <Card>
                    <div class="flex items-start justify-between"><span class="text-body-sm font-semibold text-neutral-500">{{ $t('inventory.metrics.activeItems') }}</span><span class="rounded-lg bg-info-50 p-2 text-info-700"><Package class="h-5 w-5" /></span></div>
                    <strong class="mt-4 block text-h2 text-primary-900">{{ summary.active_items }}</strong>
                    <p class="mt-1 text-tiny text-neutral-400">{{ summary.sale_items }} {{ $t('inventory.metrics.forSale') }} · {{ summary.internal_items }} {{ $t('inventory.metrics.internal') }}</p>
                </Card>
                <Card>
                    <div class="flex items-start justify-between"><span class="text-body-sm font-semibold text-neutral-500">{{ $t('inventory.metrics.lowStock') }}</span><span class="rounded-lg bg-warning-50 p-2 text-warning-700"><AlertTriangle class="h-5 w-5" /></span></div>
                    <strong class="mt-4 block text-h2" :class="summary.low_stock_count ? 'text-warning-700' : 'text-primary-900'">{{ summary.low_stock_count }}</strong>
                    <p class="mt-1 text-tiny text-neutral-400">{{ $t('inventory.metrics.requiresAttention') }}</p>
                </Card>
            </div>

            <div class="grid gap-5 xl:grid-cols-[minmax(0,1.35fr),minmax(340px,.65fr)]">
                <Card :padding="false">
                    <template #header><div class="flex items-center justify-between gap-3"><div><h2 class="text-label font-bold text-primary-900">{{ $t('inventory.lowStock.title') }}</h2><p class="mt-0.5 text-tiny text-neutral-400">{{ $t('inventory.lowStock.subtitle') }}</p></div><Link :href="route('inventory.items', { status: 'low' })" class="text-tiny font-bold text-accent-700">{{ $t('inventory.actions.viewAll') }}</Link></div></template>
                    <div v-if="lowItems.length" class="divide-y divide-neutral-100">
                        <div v-for="item in lowItems" :key="item.id" class="flex items-center gap-4 px-5 py-3.5">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-warning-50 text-warning-700"><Package class="h-4 w-4" /></span>
                            <div class="min-w-0 flex-1"><strong class="block truncate text-body-sm text-primary-900">{{ item.name }}</strong><span class="text-tiny text-neutral-400">{{ item.sku }} · {{ $t('inventory.minimumStockLabel', { value: `${item.minimum_stock} ${unitLabels[item.unit]}` }) }}</span></div>
                            <div class="text-right"><strong class="block text-body-sm text-warning-700">{{ item.stock }} {{ unitLabels[item.unit] }}</strong><span class="text-tiny text-neutral-400">{{ money(item.stock_value) }}</span></div>
                        </div>
                    </div>
                    <div v-else class="px-5 py-12 text-center"><span class="mx-auto grid h-11 w-11 place-items-center rounded-full bg-accent-50 text-accent-700">✓</span><strong class="mt-3 block text-body-sm text-primary-900">{{ $t('inventory.lowStock.empty') }}</strong></div>
                </Card>

                <Card :padding="false">
                    <template #header><div class="flex items-center justify-between"><div><h2 class="text-label font-bold text-primary-900">{{ $t('inventory.movements.title') }}</h2><p class="mt-0.5 text-tiny text-neutral-400">{{ $t('inventory.movements.subtitle') }}</p></div><ArrowDownToLine class="h-5 w-5 text-neutral-400" /></div></template>
                    <div v-if="recentMovements.length" class="divide-y divide-neutral-100 px-5">
                        <div v-for="movement in recentMovements" :key="movement.id" class="flex items-start gap-3 py-3.5">
                            <i class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="movement.quantity > 0 ? 'bg-accent-500' : 'bg-error-500'" />
                            <div class="min-w-0 flex-1"><strong class="block truncate text-body-sm text-primary-900">{{ movement.item }}</strong><span class="block truncate text-tiny text-neutral-400">{{ movementLabels[movement.type] || movement.type }} · {{ movement.warehouse }} · {{ formatDate(movement.occurred_at) }}</span></div>
                            <strong class="shrink-0 text-body-sm tabular-nums" :class="movement.quantity > 0 ? 'text-accent-700' : 'text-error-600'">{{ movement.quantity > 0 ? '+' : '' }}{{ movement.quantity }} {{ unitLabels[movement.unit] }}</strong>
                        </div>
                    </div>
                    <div v-else class="px-5 py-10 text-center text-body-sm text-neutral-400">{{ $t('inventory.movements.empty') }}</div>
                </Card>
            </div>

            <Card :padding="false">
                <template #header><div class="flex items-center justify-between"><div><h2 class="text-label font-bold text-primary-900">{{ $t('inventory.warehouses.title') }}</h2><p class="mt-0.5 text-tiny text-neutral-400">{{ $t('inventory.warehouses.subtitle') }}</p></div><Link :href="route('inventory.warehouses')" class="text-tiny font-bold text-accent-700">{{ $t('inventory.actions.manage') }}</Link></div></template>
                <div class="grid divide-y divide-neutral-100 md:grid-cols-2 md:divide-x md:divide-y-0 xl:grid-cols-4">
                    <div v-for="warehouse in warehouses" :key="warehouse.id" class="p-5">
                        <div class="flex items-center justify-between"><span class="grid h-9 w-9 place-items-center rounded-lg bg-neutral-100 text-neutral-600"><Warehouse class="h-4 w-4" /></span><span v-if="warehouse.is_default" class="rounded-full bg-accent-50 px-2 py-1 text-tiny font-bold text-accent-700">{{ $t('inventory.warehouses.default') }}</span></div>
                        <strong class="mt-3 block text-body-sm text-primary-900">{{ warehouse.name }}</strong><span class="mt-1 block text-tiny text-neutral-400">{{ $t('inventory.itemsValue', { count: warehouse.items_count, value: money(warehouse.stock_value) }) }}</span>
                    </div>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
