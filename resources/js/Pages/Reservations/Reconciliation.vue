<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, CalendarDays, CalendarPlus, CircleCheckBig, Link2, ReceiptText } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import Select from '@/Components/UI/Select.vue';
import { getIntlLocale, translate } from '@/i18n';

const props = defineProps({
    issues: Object,
    filters: Object,
    summary: Object,
});

const statusOptions = [
    { value: 'open', label: translate('reservationsReconciliation.statusOpen') },
    { value: 'resolved', label: translate('reservationsReconciliation.statusResolved') },
];

const status = computed({
    get: () => props.filters?.status || 'open',
    set: (value) => router.get(route('reservations.reconciliation'), { status: value }, { preserveState: true, replace: true }),
});

const typeMeta = {
    missing_in_pms: { label: translate('reservationsReconciliation.typeMissingInPmsLabel'), variant: 'error', description: translate('reservationsReconciliation.typeMissingInPmsDescription') },
    possible_manual_duplicate: { label: translate('reservationsReconciliation.typeManualDuplicateLabel'), variant: 'warning', description: translate('reservationsReconciliation.typeManualDuplicateDescription') },
    amount_mismatch: { label: translate('reservationsReconciliation.typeAmountMismatchLabel'), variant: 'warning', description: translate('reservationsReconciliation.typeAmountMismatchDescription') },
    stay_mismatch: { label: translate('reservationsReconciliation.typeStayMismatchLabel'), variant: 'warning', description: translate('reservationsReconciliation.typeStayMismatchDescription') },
    status_mismatch: { label: translate('reservationsReconciliation.typeStatusMismatchLabel'), variant: 'error', description: translate('reservationsReconciliation.typeStatusMismatchDescription') },
    cancelled_ota_manual_twin: { label: translate('reservationsReconciliation.typeCancelledOtaTwinLabel'), variant: 'warning', description: translate('reservationsReconciliation.typeCancelledOtaTwinDescription') },
};

const cards = computed(() => [
    { label: translate('reservationsReconciliation.cardOpenIssues'), value: props.summary?.open || 0, icon: AlertTriangle, tone: 'text-error-700', iconBg: 'bg-error-50' },
    { label: translate('reservationsReconciliation.cardMissingInPms'), value: props.summary?.missing || 0, icon: CalendarDays, tone: 'text-warning-700', iconBg: 'bg-warning-50' },
    { label: translate('reservationsReconciliation.cardAmountMismatch'), value: props.summary?.amount || 0, icon: ReceiptText, tone: 'text-info-700', iconBg: 'bg-info-50' },
    { label: translate('reservationsReconciliation.cardManualCandidates'), value: props.summary?.manual_candidates || 0, icon: Link2, tone: 'text-accent-700', iconBg: 'bg-accent-50' },
]);

function money(value, currency = 'EUR') {
    if (value === null || value === undefined) return '—';
    return new Intl.NumberFormat(getIntlLocale(), { style: 'currency', currency: currency || 'EUR' }).format(Number(value));
}

function dateTime(value) {
    return value ? new Date(value).toLocaleString(getIntlLocale(), { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

function goToPage(url) {
    if (url) router.visit(url, { preserveState: true, preserveScroll: true });
}

// --- Linking a manual candidate to the OTA booking (the panel's only write) ---
const perms = usePage().props.auth.user?.permissions || [];
const canLink = perms.includes('update_reservations');
const linkBusy = ref(false);
const linkError = ref('');

// "Zgjatur në recepsion": the guest extended/changed the stay at the desk —
// the difference vs the channel is legitimate; close the booking's mismatch
// cards and keep them closed until the reservation changes again.
const resolveBusy = ref(false);
const deskResolvable = (issue) => ['amount_mismatch', 'stay_mismatch'].includes(issue.type) && issue.status === 'open';
function resolveExtendedOnDesk(issue) {
    if (!confirm(translate('reservationsReconciliation.extendedOnDeskConfirm', { ref: issue.external_ref }))) return;
    resolveBusy.value = true;
    router.post(route('reservations.reconciliation.resolve', issue.id), { reason: 'extended_on_desk' }, {
        preserveScroll: true,
        onError: (errors) => { linkError.value = errors.reason || translate('reservationsReconciliation.extendedOnDeskFailed'); },
        onFinish: () => { resolveBusy.value = false; },
    });
}

function linkCandidate(issue, candidate) {
    if (!window.confirm(translate('reservationsReconciliation.confirmLink', {
        id: candidate.id,
        guest: candidate.guest || translate('reservationsReconciliation.unnamed'),
        channel: issue.channel,
        ref: issue.external_ref,
    }))) return;
    linkError.value = '';
    linkBusy.value = true;
    router.post(route('reservations.reconciliation.link', issue.id), { reservation_id: candidate.id }, {
        preserveScroll: true,
        onError: (errors) => { linkError.value = errors.reservation_id || translate('reservationsReconciliation.linkFailed'); },
        onFinish: () => { linkBusy.value = false; },
    });
}
</script>

<template>
    <AppLayout>
        <PageHeader
            :title="$t('reservationsReconciliation.pageTitle')"
            :breadcrumbs="[
                { label: 'Dashboard', href: '/dashboard' },
                { label: $t('reservationsReconciliation.reservations'), href: route('reservations.index') },
                { label: $t('reservationsReconciliation.pageTitle') },
            ]"
        >
            <template #actions>
                <Link :href="route('reservations.index')" class="no-underline">
                    <Button variant="outline"><ArrowLeft class="mr-2 h-4 w-4" />{{ $t('reservationsReconciliation.reservations') }}</Button>
                </Link>
            </template>
        </PageHeader>
        <p class="mt-1 text-body-sm text-neutral-500">{{ $t('reservationsReconciliation.subtitle') }}</p>

        <div class="mt-5 rounded-xl border border-info-200 bg-info-50 px-4 py-3">
            <div class="flex items-start gap-3">
                <CircleCheckBig class="mt-0.5 h-5 w-5 shrink-0 text-info-600" />
                <div>
                    <p class="text-body-sm font-semibold text-info-900">{{ $t('reservationsReconciliation.noAutoChangesTitle') }}</p>
                    <p class="mt-0.5 text-small text-info-700">{{ $t('reservationsReconciliation.noAutoChangesDescription') }}</p>
                </div>
            </div>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Card v-for="card in cards" :key="card.label">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-small font-medium text-neutral-500">{{ card.label }}</p>
                        <p class="mt-2 text-h3" :class="card.tone">{{ card.value }}</p>
                    </div>
                    <span class="grid h-10 w-10 place-items-center rounded-xl" :class="[card.iconBg, card.tone]"><component :is="card.icon" class="h-5 w-5" /></span>
                </div>
            </Card>
        </div>

        <Card class="mt-5" :padding="false">
            <div class="flex flex-col gap-3 border-b border-neutral-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-h4 text-primary-900">{{ $t('reservationsReconciliation.issuesTitle') }}</h2>
                    <p class="mt-0.5 text-small text-neutral-500">{{ $t('reservationsReconciliation.lastChecked', { date: dateTime(summary?.last_checked_at) }) }}</p>
                </div>
                <div class="w-44"><Select v-model="status" :options="statusOptions" /></div>
            </div>

            <div v-if="issues.data?.length" class="divide-y divide-neutral-200">
                <article v-for="issue in issues.data" :key="issue.id" class="px-5 py-5">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <Badge :variant="typeMeta[issue.type]?.variant || 'neutral'" dot>{{ typeMeta[issue.type]?.label || issue.type }}</Badge>
                                <span class="text-small font-semibold uppercase text-neutral-500">{{ issue.channel }}</span>
                                <span class="font-mono text-small text-neutral-500">#{{ issue.external_ref }}</span>
                            </div>
                            <p class="mt-2 text-body-sm text-neutral-700">{{ typeMeta[issue.type]?.description }}</p>
                            <p class="mt-1 text-small text-neutral-400">{{ $t('reservationsReconciliation.detectedAt', { date: dateTime(issue.last_detected_at) }) }}</p>
                        </div>

                        <div v-if="issue.type === 'amount_mismatch'" class="grid shrink-0 grid-cols-2 gap-2 text-right">
                            <div class="rounded-lg bg-neutral-50 px-3 py-2"><p class="text-tiny uppercase text-neutral-400">{{ $t('reservationsReconciliation.channelLabel') }}</p><p class="font-semibold text-primary-900">{{ money(issue.expected_total, issue.currency) }}</p></div>
                            <div class="rounded-lg bg-warning-50 px-3 py-2"><p class="text-tiny uppercase text-warning-600">PMS</p><p class="font-semibold text-warning-900">{{ money(issue.actual_total, issue.currency) }}</p></div>
                        </div>
                    </div>

                    <div v-if="issue.candidates?.length" class="mt-4 rounded-xl border border-accent-200 bg-accent-50/60 p-4">
                        <p class="text-small font-semibold text-accent-900">{{ $t('reservationsReconciliation.manualCandidateTitle') }}</p>
                        <p class="mt-0.5 text-small text-accent-700">{{ $t('reservationsReconciliation.manualCandidateDescription') }}</p>
                        <p v-if="linkError" class="mt-2 text-small font-medium text-error-700">{{ linkError }}</p>
                        <div class="mt-3 space-y-2">
                            <div
                                v-for="candidate in issue.candidates"
                                :key="candidate.id"
                                class="flex flex-wrap items-center gap-2"
                            >
                                <Link
                                    :href="route('reservations.show', candidate.id)"
                                    class="inline-flex items-center gap-2 rounded-lg border border-accent-200 bg-white px-3 py-2 text-small font-semibold text-accent-800 no-underline hover:border-accent-300"
                                >
                                    #{{ candidate.id }} · {{ candidate.guest || $t('reservationsReconciliation.noName') }} · {{ $t('reservationsReconciliation.room') }} {{ candidate.room || '—' }} · {{ money(candidate.amount, candidate.currency) }}
                                </Link>
                                <Button
                                    v-if="canLink && issue.status === 'open'"
                                    size="sm"
                                    variant="primary"
                                    :disabled="linkBusy"
                                    @click="linkCandidate(issue, candidate)"
                                >
                                    <Link2 class="mr-1.5 h-4 w-4" />{{ $t('reservationsReconciliation.linkToThis') }}
                                </Button>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <Link v-if="issue.reservation_id" :href="route('reservations.show', issue.reservation_id)" class="no-underline"><Button size="sm" variant="outline">{{ $t('reservationsReconciliation.openInPms') }}</Button></Link>
                        <Button
                            v-if="canLink && deskResolvable(issue)"
                            size="sm"
                            variant="outline"
                            :disabled="resolveBusy"
                            @click="resolveExtendedOnDesk(issue)"
                        >
                            <CalendarPlus class="mr-1.5 h-4 w-4" />{{ $t('reservationsReconciliation.extendedOnDesk') }}
                        </Button>
                        <Badge v-if="issue.resolution === 'extended_on_desk'" variant="info">{{ $t('reservationsReconciliation.resolvedExtendedOnDesk') }}</Badge>
                        <span v-else-if="!issue.candidates?.length" class="inline-flex items-center gap-1.5 text-small text-error-700"><AlertTriangle class="h-4 w-4" />{{ $t('reservationsReconciliation.noManualFound') }}</span>
                    </div>
                </article>
            </div>

            <div v-else class="px-6 py-16 text-center">
                <CircleCheckBig class="mx-auto h-10 w-10 text-success-500" />
                <p class="mt-3 font-semibold text-primary-900">{{ status === 'open' ? $t('reservationsReconciliation.emptyOpen') : $t('reservationsReconciliation.emptyResolved') }}</p>
                <p class="mt-1 text-body-sm text-neutral-500">{{ $t('reservationsReconciliation.emptyDescription') }}</p>
            </div>

            <div v-if="issues.total > issues.per_page" class="flex items-center justify-between border-t border-neutral-200 bg-neutral-50 px-5 py-3">
                <span class="text-small text-neutral-500">{{ $t('reservationsReconciliation.paginationRange', { from: issues.from, to: issues.to, total: issues.total }) }}</span>
                <div class="flex gap-2">
                    <Button size="sm" variant="outline" :disabled="!issues.prev_page_url" @click="goToPage(issues.prev_page_url)">{{ $t('reservationsReconciliation.previous') }}</Button>
                    <Button size="sm" variant="outline" :disabled="!issues.next_page_url" @click="goToPage(issues.next_page_url)">{{ $t('reservationsReconciliation.next') }}</Button>
                </div>
            </div>
        </Card>
    </AppLayout>
</template>
