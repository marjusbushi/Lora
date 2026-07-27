<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, CalendarDays, CircleCheckBig, Link2, ReceiptText } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import Select from '@/Components/UI/Select.vue';
import { getIntlLocale } from '@/i18n';

const props = defineProps({
    issues: Object,
    filters: Object,
    summary: Object,
});

const statusOptions = [
    { value: 'open', label: 'Të hapura' },
    { value: 'resolved', label: 'Të zgjidhura' },
];

const status = computed({
    get: () => props.filters?.status || 'open',
    set: (value) => router.get(route('reservations.reconciliation'), { status: value }, { preserveState: true, replace: true }),
});

const typeMeta = {
    missing_in_pms: { label: 'Mungon në PMS', variant: 'error', description: 'Rezervimi ekziston në Channex, por nuk u gjet me referencën OTA në PMS.' },
    possible_manual_duplicate: { label: 'Dublikatë e mundshme', variant: 'warning', description: 'Rezervimi OTA ekziston në PMS, por u gjet edhe një rezervim i ngjashëm i futur manualisht.' },
    amount_mismatch: { label: 'Vlerë e ndryshme', variant: 'warning', description: 'Totali në PMS nuk përputhet me totalin e ardhur nga kanali.' },
    stay_mismatch: { label: 'Data të ndryshme', variant: 'warning', description: 'Datat ose numri i dhomave ndryshojnë mes kanalit dhe PMS-së.' },
    status_mismatch: { label: 'Status i ndryshëm', variant: 'error', description: 'Anulimi ose statusi aktiv nuk përputhet mes dy sistemeve.' },
};

const cards = computed(() => [
    { label: 'Probleme të hapura', value: props.summary?.open || 0, icon: AlertTriangle, tone: 'text-error-700', iconBg: 'bg-error-50' },
    { label: 'Mungojnë në PMS', value: props.summary?.missing || 0, icon: CalendarDays, tone: 'text-warning-700', iconBg: 'bg-warning-50' },
    { label: 'Vlerë e ndryshme', value: props.summary?.amount || 0, icon: ReceiptText, tone: 'text-info-700', iconBg: 'bg-info-50' },
    { label: 'Kandidatë manualë', value: props.summary?.manual_candidates || 0, icon: Link2, tone: 'text-accent-700', iconBg: 'bg-accent-50' },
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
</script>

<template>
    <AppLayout>
        <PageHeader
            title="Kontrolli OTA–PMS"
            :breadcrumbs="[
                { label: 'Dashboard', href: '/dashboard' },
                { label: 'Rezervimet', href: route('reservations.index') },
                { label: 'Kontrolli OTA–PMS' },
            ]"
        >
            <template #actions>
                <Link :href="route('reservations.index')" class="no-underline">
                    <Button variant="outline"><ArrowLeft class="mr-2 h-4 w-4" />Rezervimet</Button>
                </Link>
            </template>
        </PageHeader>
        <p class="mt-1 text-body-sm text-neutral-500">Krahason Channex me PMS-në dhe veçon rezervimet e futura manualisht.</p>

        <div class="mt-5 rounded-xl border border-info-200 bg-info-50 px-4 py-3">
            <div class="flex items-start gap-3">
                <CircleCheckBig class="mt-0.5 h-5 w-5 shrink-0 text-info-600" />
                <div>
                    <p class="text-body-sm font-semibold text-info-900">Kontroll pa ndryshime automatike</p>
                    <p class="mt-0.5 text-small text-info-700">Sistemi vetëm tregon diferencat dhe kandidatët manualë. Lidhja ose korrigjimi bëhet pasi stafi e verifikon.</p>
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
                    <h2 class="text-h4 text-primary-900">Diferencat e rezervimeve</h2>
                    <p class="mt-0.5 text-small text-neutral-500">Kontrolli i fundit: {{ dateTime(summary?.last_checked_at) }}</p>
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
                            <p class="mt-1 text-small text-neutral-400">U kontrollua {{ dateTime(issue.last_detected_at) }}</p>
                        </div>

                        <div v-if="issue.type === 'amount_mismatch'" class="grid shrink-0 grid-cols-2 gap-2 text-right">
                            <div class="rounded-lg bg-neutral-50 px-3 py-2"><p class="text-tiny uppercase text-neutral-400">Kanali</p><p class="font-semibold text-primary-900">{{ money(issue.expected_total, issue.currency) }}</p></div>
                            <div class="rounded-lg bg-warning-50 px-3 py-2"><p class="text-tiny uppercase text-warning-600">PMS</p><p class="font-semibold text-warning-900">{{ money(issue.actual_total, issue.currency) }}</p></div>
                        </div>
                    </div>

                    <div v-if="issue.candidates?.length" class="mt-4 rounded-xl border border-accent-200 bg-accent-50/60 p-4">
                        <p class="text-small font-semibold text-accent-900">Mund të jetë futur manualisht</p>
                        <p class="mt-0.5 text-small text-accent-700">Të njëjtat data/dhomë dhe emër ose vlerë. Verifikoje para çdo lidhjeje.</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <Link
                                v-for="candidate in issue.candidates"
                                :key="candidate.id"
                                :href="route('reservations.show', candidate.id)"
                                class="inline-flex items-center gap-2 rounded-lg border border-accent-200 bg-white px-3 py-2 text-small font-semibold text-accent-800 no-underline hover:border-accent-300"
                            >
                                #{{ candidate.id }} · {{ candidate.guest || 'Pa emër' }} · Dhoma {{ candidate.room || '—' }} · {{ money(candidate.amount, candidate.currency) }}
                            </Link>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <Link v-if="issue.reservation_id" :href="route('reservations.show', issue.reservation_id)" class="no-underline"><Button size="sm" variant="outline">Hap rezervimin në PMS</Button></Link>
                        <span v-else-if="!issue.candidates?.length" class="inline-flex items-center gap-1.5 text-small text-error-700"><AlertTriangle class="h-4 w-4" />Nuk u gjet rezervim manual i mundshëm.</span>
                    </div>
                </article>
            </div>

            <div v-else class="px-6 py-16 text-center">
                <CircleCheckBig class="mx-auto h-10 w-10 text-success-500" />
                <p class="mt-3 font-semibold text-primary-900">{{ status === 'open' ? 'Nuk ka diferenca të hapura' : 'Nuk ka diferenca të zgjidhura' }}</p>
                <p class="mt-1 text-body-sm text-neutral-500">Rezervimet e kontrolluara përputhen mes kanalit dhe PMS-së.</p>
            </div>

            <div v-if="issues.total > issues.per_page" class="flex items-center justify-between border-t border-neutral-200 bg-neutral-50 px-5 py-3">
                <span class="text-small text-neutral-500">{{ issues.from }}–{{ issues.to }} nga {{ issues.total }}</span>
                <div class="flex gap-2">
                    <Button size="sm" variant="outline" :disabled="!issues.prev_page_url" @click="goToPage(issues.prev_page_url)">Para</Button>
                    <Button size="sm" variant="outline" :disabled="!issues.next_page_url" @click="goToPage(issues.next_page_url)">Pas</Button>
                </div>
            </div>
        </Card>
    </AppLayout>
</template>
