<script setup>
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowLeftRight,
    ArrowUp,
    Building2,
    CalendarCheck2,
    ChevronRight,
    FileText,
    Landmark,
    ReceiptText,
    Store,
    X,
} from 'lucide-vue-next';
import { money, sourceBadge } from '../financeShared.js';
import { translate } from '@/i18n';

const props = defineProps({
    payment: { type: Object, default: null },
    baseCurrency: { type: String, default: 'EUR' },
});

const emit = defineEmits(['close']);

const directionLabel = computed(() => ({
    in: translate('admin.finance.transaction.directionIn'),
    out: translate('admin.finance.transaction.directionOut'),
    transfer: translate('admin.finance.transaction.directionTransfer'),
}[props.payment?.direction] || translate('admin.finance.transaction.directionOther')));

const methodLabel = computed(() => ({
    cash: 'Cash',
    card: translate('admin.finance.transaction.card'),
    bank: translate('admin.finance.transaction.bank'),
    pok: 'POK',
    ota: 'OTA',
}[props.payment?.method] || props.payment?.method));

const relatedLinks = computed(() => {
    const related = props.payment?.related || {};
    return [
        related.reservation && { ...related.reservation, icon: CalendarCheck2, kind: translate('admin.finance.transaction.reservation') },
        related.bill && { ...related.bill, icon: ReceiptText, kind: translate('admin.finance.transaction.purchaseBill') },
        related.supplier && { ...related.supplier, icon: Building2, kind: translate('admin.finance.transaction.supplier') },
        related.invoice && { ...related.invoice, icon: FileText, kind: translate('admin.finance.transaction.salesInvoice') },
        related.source && { ...related.source, icon: Store, kind: translate('admin.finance.transaction.source') },
    ].filter(Boolean);
});

function closeOnEscape(event) {
    if (event.key === 'Escape' && props.payment) emit('close');
}

watch(() => props.payment, (payment) => {
    document.body.style.overflow = payment ? 'hidden' : '';
});

onMounted(() => document.addEventListener('keydown', closeOnEscape));
onBeforeUnmount(() => {
    document.removeEventListener('keydown', closeOnEscape);
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="duration-200 ease-out" enter-from-class="opacity-0" leave-active-class="duration-150 ease-in" leave-to-class="opacity-0">
            <div v-if="payment" class="fixed inset-0 z-50 bg-primary-950/35" @click.self="emit('close')">
                <aside class="ml-auto flex h-full w-full max-w-lg flex-col bg-white shadow-modal" role="dialog" aria-modal="true" aria-labelledby="transaction-title">
                    <header class="flex items-start justify-between gap-4 border-b border-neutral-200 px-5 py-4">
                        <div>
                            <p class="text-tiny font-bold uppercase tracking-wide text-neutral-400">{{ $t('admin.finance.transaction.details') }}</p>
                            <h2 id="transaction-title" class="mt-1 text-h4 font-bold text-primary-900">{{ $t('admin.finance.transaction.number') }}{{ String(payment.id).padStart(6, '0') }}</h2>
                        </div>
                        <button type="button" :aria-label="$t('admin.finance.transaction.closeDetails')" class="grid h-8 w-8 place-items-center rounded-md text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" @click="emit('close')">
                            <X class="h-4 w-4" />
                        </button>
                    </header>

                    <div class="flex-1 overflow-y-auto p-5">
                        <div class="rounded-xl p-5 text-center" :class="payment.direction === 'in' ? 'bg-accent-50 text-accent-700' : payment.direction === 'out' ? 'bg-error-50 text-error-600' : 'bg-info-50 text-info-700'">
                            <span class="mx-auto grid h-9 w-9 place-items-center rounded-full bg-white/80">
                                <ArrowDown v-if="payment.direction === 'in'" class="h-4 w-4" />
                                <ArrowUp v-else-if="payment.direction === 'out'" class="h-4 w-4" />
                                <ArrowLeftRight v-else class="h-4 w-4" />
                            </span>
                            <p class="mt-2 text-body-sm font-semibold">{{ directionLabel }}</p>
                            <p class="mt-1 text-h2 font-extrabold tabular-nums">
                                {{ payment.direction === 'in' ? '+' : payment.direction === 'out' ? '−' : '' }} {{ money(payment.amount, payment.currency) }}
                            </p>
                            <p v-if="payment.currency !== baseCurrency" class="mt-1 text-tiny opacity-70">≈ {{ money(payment.amount_base, baseCurrency) }}</p>
                        </div>

                        <dl class="mt-5 divide-y divide-neutral-100 text-body-sm">
                            <div class="flex justify-between gap-5 py-3"><dt class="text-neutral-500">{{ $t('admin.finance.transaction.description') }}</dt><dd class="m-0 text-right font-semibold text-primary-900">{{ payment.description }}</dd></div>
                            <div class="flex justify-between gap-5 py-3"><dt class="text-neutral-500">{{ $t('admin.finance.transaction.date') }}</dt><dd class="m-0 text-right font-semibold text-primary-900">{{ payment.paid_at.slice(0, 16) }}</dd></div>
                            <div class="flex justify-between gap-5 py-3"><dt class="text-neutral-500">{{ $t('admin.finance.transaction.method') }}</dt><dd class="m-0 text-right font-semibold text-primary-900">{{ methodLabel }}</dd></div>
                            <div class="flex justify-between gap-5 py-3"><dt class="text-neutral-500">{{ $t('admin.finance.transaction.source') }}</dt><dd class="m-0 text-right"><span class="rounded-full px-2 py-0.5 text-tiny font-bold" :class="sourceBadge(payment).cls">{{ sourceBadge(payment).text }}</span></dd></div>
                            <div v-if="payment.created_by" class="flex justify-between gap-5 py-3"><dt class="text-neutral-500">{{ $t('admin.finance.transaction.createdBy') }}</dt><dd class="m-0 text-right font-semibold text-primary-900">{{ payment.created_by }}</dd></div>
                        </dl>

                        <section class="mt-6">
                            <h3 class="text-tiny font-bold uppercase tracking-wide text-neutral-400">{{ $t('admin.finance.transaction.accounts') }}</h3>
                            <div class="mt-2 grid gap-2" :class="payment.counter_account_id ? 'sm:grid-cols-2' : 'grid-cols-1'">
                                <Link :href="route('finance.accounts', { account_id: payment.account_id })" class="group flex items-center gap-3 rounded-lg border border-neutral-200 p-3 text-neutral-700 no-underline hover:border-accent-300 hover:bg-accent-50/40">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-neutral-100 text-neutral-500"><Landmark class="h-4 w-4" /></span>
                                    <span class="min-w-0 flex-1"><small class="block text-tiny text-neutral-400">{{ payment.direction === 'transfer' ? $t('admin.generated.k_1dc4a346363f') : $t('admin.generated.k_a93e73074aa2') }}</small><b class="block truncate text-body-sm text-primary-900">{{ payment.account }}</b></span>
                                    <ChevronRight class="h-4 w-4 text-neutral-300 group-hover:text-accent-600" />
                                </Link>
                                <Link v-if="payment.counter_account_id" :href="route('finance.accounts', { account_id: payment.counter_account_id })" class="group flex items-center gap-3 rounded-lg border border-neutral-200 p-3 text-neutral-700 no-underline hover:border-accent-300 hover:bg-accent-50/40">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-neutral-100 text-neutral-500"><Landmark class="h-4 w-4" /></span>
                                    <span class="min-w-0 flex-1"><small class="block text-tiny text-neutral-400">{{ $t('admin.finance.transaction.to') }}</small><b class="block truncate text-body-sm text-primary-900">{{ payment.counter_account }}</b></span>
                                    <ChevronRight class="h-4 w-4 text-neutral-300 group-hover:text-accent-600" />
                                </Link>
                            </div>
                        </section>

                        <section v-if="relatedLinks.length" class="mt-6">
                            <h3 class="text-tiny font-bold uppercase tracking-wide text-neutral-400">{{ $t('admin.finance.transaction.relatedDocuments') }}</h3>
                            <div class="mt-2 divide-y divide-neutral-100 overflow-hidden rounded-lg border border-neutral-200">
                                <component
                                    :is="item.href ? Link : 'div'"
                                    v-for="item in relatedLinks"
                                    :key="`${item.kind}-${item.id}`"
                                    :href="item.href || undefined"
                                    class="group flex items-center gap-3 bg-white p-3 text-neutral-700 no-underline"
                                    :class="item.href ? 'hover:bg-accent-50/50' : ''"
                                >
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-accent-50 text-accent-700"><component :is="item.icon" class="h-4 w-4" /></span>
                                    <span class="min-w-0 flex-1"><small class="block text-tiny text-neutral-400">{{ item.kind }}</small><b class="block truncate text-body-sm text-primary-900">{{ item.label }}</b></span>
                                    <ChevronRight v-if="item.href" class="h-4 w-4 text-neutral-300 group-hover:text-accent-600" />
                                </component>
                            </div>
                        </section>

                        <div v-else class="mt-6 rounded-lg bg-neutral-50 p-3 text-tiny leading-5 text-neutral-500">
                            {{ $t('admin.finance.transaction.noRelatedDocuments') }}
                        </div>
                    </div>

                    <footer class="flex items-center justify-between border-t border-neutral-200 bg-neutral-50 px-5 py-3">
                        <span class="text-tiny text-neutral-400">{{ $t('admin.finance.transaction.readOnly') }}</span>
                        <button type="button" class="text-body-sm font-semibold text-neutral-600 hover:text-primary-900" @click="emit('close')">{{ $t('admin.finance.transaction.close') }}</button>
                    </footer>
                </aside>
            </div>
        </Transition>
    </Teleport>
</template>
