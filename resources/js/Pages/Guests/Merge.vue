<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import { countryName } from '@/countries';
import { getIntlLocale, translate } from '@/i18n';
import { ArrowLeft, Bot, CheckCircle2, Merge, ShieldCheck } from 'lucide-vue-next';

const props = defineProps({
    profiles: { type: Array, required: true },
    suggestion: { type: Object, required: true },
    fields: { type: Array, required: true },
});

const suggestion = ref({ ...props.suggestion });
const primaryId = ref(Number(props.suggestion.primary_id));
const fieldSources = reactive({});
const loadingSuggestion = ref(true);
const submitting = ref(false);
const confirmed = ref(false);
const errorMessage = ref('');
const userChanged = ref(false);

const first = computed(() => props.profiles[0]);
const second = computed(() => props.profiles[1]);
const primary = computed(() => props.profiles.find((profile) => profile.id === primaryId.value));
const secondary = computed(() => props.profiles.find((profile) => profile.id !== primaryId.value));
const suggestedProfile = computed(() => props.profiles.find((profile) => profile.id === Number(suggestion.value.primary_id)));

function hasValue(value) {
    return value !== null && value !== undefined && value !== '';
}

function applySuggestion(next, force = false) {
    suggestion.value = next;
    if (userChanged.value && !force) return;
    primaryId.value = Number(next.primary_id);
    const other = props.profiles.find((profile) => profile.id !== primaryId.value);
    const selected = props.profiles.find((profile) => profile.id === primaryId.value);
    for (const field of props.fields) {
        fieldSources[field] = hasValue(selected[field]) ? selected.id : other.id;
    }
}

function choosePrimary(id) {
    userChanged.value = true;
    primaryId.value = Number(id);
    const selected = props.profiles.find((profile) => profile.id === primaryId.value);
    const other = props.profiles.find((profile) => profile.id !== primaryId.value);
    for (const field of props.fields) {
        if (!hasValue(props.profiles.find((profile) => profile.id === fieldSources[field])?.[field])) {
            fieldSources[field] = hasValue(selected[field]) ? selected.id : other.id;
        }
    }
}

function chooseField(field, id) {
    userChanged.value = true;
    fieldSources[field] = Number(id);
}

function displayValue(field, value) {
    if (!hasValue(value)) return translate('admin.guestMerge.empty');
    if (field === 'nationality') return countryName(value);
    if (field === 'date_of_birth') return new Date(`${value}T00:00:00`).toLocaleDateString(getIntlLocale(), { day: '2-digit', month: 'short', year: 'numeric' });
    if (field === 'document_type') return translate(`admin.guestProfileDesign.documentTypes.${value}`);
    return value;
}

function submitMerge() {
    if (!confirmed.value || submitting.value) return;
    submitting.value = true;
    errorMessage.value = '';
    router.post(
        route('guests.merge.store', [first.value.id, second.value.id]),
        {
            primary_id: primaryId.value,
            field_sources: { ...fieldSources },
            suggestion_source: userChanged.value ? 'manual' : suggestion.value.source,
        },
        {
            onError: (errors) => { errorMessage.value = errors.merge || Object.values(errors)[0] || translate('admin.guestMerge.failed'); },
            onFinish: () => { submitting.value = false; },
        },
    );
}

applySuggestion(props.suggestion, true);

onMounted(async () => {
    try {
        const { data } = await axios.post(route('guests.merge.suggest', [first.value.id, second.value.id]));
        applySuggestion(data.suggestion);
    } catch {
        suggestion.value = { ...props.suggestion, source: 'fallback' };
    } finally {
        loadingSuggestion.value = false;
    }
});
</script>

<template>
    <Head :title="$t('admin.guestMerge.title')" />
    <AppLayout>
        <PageHeader
            :title="$t('admin.guestMerge.title')"
            :subtitle="$t('admin.guestMerge.subtitle')"
            :breadcrumbs="[
                { label: $t('admin.guestProfileDesign.dashboard'), href: '/dashboard' },
                { label: $t('admin.guestProfileDesign.guests'), href: route('guests.index') },
                { label: $t('admin.guestMerge.title') },
            ]"
        >
            <template #actions><Link :href="route('guests.show', first.id)" class="no-underline"><Button variant="outline"><ArrowLeft class="h-4 w-4" />{{ $t('admin.guestMerge.cancel') }}</Button></Link></template>
        </PageHeader>

        <div class="mt-5 flex items-start gap-3 rounded-xl border border-info-100 bg-info-50 p-4 text-body-sm text-info-900">
            <ShieldCheck class="mt-0.5 h-5 w-5 shrink-0" />
            <div><p class="font-bold">{{ $t('admin.guestMerge.safeTitle') }}</p><p class="mt-1">{{ $t('admin.guestMerge.safeText') }}</p></div>
        </div>

        <Card class="mt-4" :padding="false">
            <div class="border-b border-neutral-200 bg-gradient-to-r from-primary-950 to-accent-900 p-5 text-white">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex items-start gap-3"><span class="grid h-11 w-11 place-items-center rounded-xl bg-white/10"><Bot class="h-5 w-5" /></span><div><div class="flex flex-wrap items-center gap-2"><h2 class="text-h3 text-white">{{ $t('admin.guestMerge.suggestion') }}</h2><Badge :variant="suggestion.source === 'ai' ? 'success' : 'warning'">{{ loadingSuggestion ? $t('admin.guestMerge.analyzing') : $t(`admin.guestMerge.sources.${suggestion.source}`) }}</Badge></div><p class="mt-1 text-body-sm text-primary-100">{{ $t(`admin.guestMerge.reasons.${suggestion.reason_key}`) }}</p><p class="mt-1 text-tiny text-primary-200">{{ $t('admin.guestMerge.privateSuggestion') }}</p></div></div>
                    <div v-if="suggestedProfile" class="rounded-xl bg-white/10 px-4 py-3 ring-1 ring-white/15"><p class="text-tiny text-primary-200">{{ $t('admin.guestMerge.recommendedPrimary') }}</p><p class="mt-1 text-body font-extrabold">{{ suggestedProfile.full_name }} · #{{ suggestedProfile.id }}</p></div>
                </div>
            </div>
        </Card>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <button v-for="profile in profiles" :key="profile.id" type="button" class="rounded-2xl border-2 bg-white p-5 text-left shadow-card transition" :class="primaryId === profile.id ? 'border-accent-500 ring-4 ring-accent-50' : 'border-neutral-200 hover:border-neutral-300'" @click="choosePrimary(profile.id)">
                <div class="flex items-start justify-between gap-3"><div><p class="text-h3 text-primary-900">{{ profile.full_name }}</p><p class="mt-1 text-tiny text-neutral-400">ID #{{ profile.id }} · {{ $t('admin.guestMerge.created') }} {{ profile.created_at }}</p></div><Badge :variant="primaryId === profile.id ? 'success' : 'neutral'" dot>{{ primaryId === profile.id ? $t('admin.guestMerge.primary') : $t('admin.guestMerge.archive') }}</Badge></div>
                <div class="mt-4 grid grid-cols-4 gap-2 text-center"><div class="rounded-lg bg-neutral-50 p-2"><p class="font-bold text-primary-900">{{ profile.counts.reservations }}</p><p class="text-[10px] text-neutral-500">{{ $t('admin.guestMerge.reservations') }}</p></div><div class="rounded-lg bg-neutral-50 p-2"><p class="font-bold text-primary-900">{{ profile.counts.documents }}</p><p class="text-[10px] text-neutral-500">{{ $t('admin.guestMerge.documents') }}</p></div><div class="rounded-lg bg-neutral-50 p-2"><p class="font-bold text-primary-900">{{ profile.counts.invoices }}</p><p class="text-[10px] text-neutral-500">{{ $t('admin.guestMerge.invoices') }}</p></div><div class="rounded-lg bg-neutral-50 p-2"><p class="font-bold text-primary-900">{{ profile.counts.reviews }}</p><p class="text-[10px] text-neutral-500">{{ $t('admin.guestMerge.reviews') }}</p></div></div>
            </button>
        </div>

        <Card class="mt-4" :padding="false">
            <template #header><div><h2 class="text-body font-bold text-primary-900">{{ $t('admin.guestMerge.chooseData') }}</h2><p class="text-tiny text-neutral-400">{{ $t('admin.guestMerge.chooseDataHelp') }}</p></div></template>
            <div class="divide-y divide-neutral-100">
                <div v-for="field in fields" :key="field" class="grid gap-3 px-5 py-4 md:grid-cols-[170px_1fr_1fr] md:items-stretch">
                    <div class="self-center"><p class="text-tiny font-bold uppercase tracking-wide text-neutral-500">{{ $t(`admin.guestMerge.fields.${field}`) }}</p></div>
                    <label v-for="profile in profiles" :key="profile.id" class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition" :class="fieldSources[field] === profile.id ? 'border-accent-400 bg-accent-50' : 'border-neutral-200 hover:bg-neutral-50'">
                        <input type="radio" class="mt-0.5 text-accent-600 focus:ring-accent-500" :name="field" :value="profile.id" :checked="fieldSources[field] === profile.id" @change="chooseField(field, profile.id)" />
                        <div class="min-w-0"><p class="text-[10px] font-bold uppercase text-neutral-400">{{ profile.full_name }}</p><p class="mt-1 break-words text-body-sm font-semibold" :class="hasValue(profile[field]) ? 'text-primary-900' : 'italic text-neutral-400'">{{ displayValue(field, profile[field]) }}</p></div>
                    </label>
                </div>
            </div>
        </Card>

        <Card class="mt-4">
            <div class="flex items-start gap-3"><Merge class="mt-0.5 h-5 w-5 shrink-0 text-accent-700" /><div><h2 class="text-body font-bold text-primary-900">{{ $t('admin.guestMerge.finalTitle') }}</h2><p class="mt-1 text-body-sm text-neutral-600">{{ $t('admin.guestMerge.finalText', { primary: primary?.full_name, secondary: secondary?.full_name }) }}</p></div></div>
            <label class="mt-4 flex cursor-pointer items-start gap-3 rounded-xl border border-warning-200 bg-warning-50 p-3"><input v-model="confirmed" type="checkbox" class="mt-0.5 rounded border-warning-300 text-accent-600 focus:ring-accent-500" /><span class="text-body-sm font-semibold text-warning-900">{{ $t('admin.guestMerge.confirm') }}</span></label>
            <p v-if="errorMessage" class="mt-3 text-body-sm text-error-700">{{ errorMessage }}</p>
            <div class="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><Link :href="route('guests.show', first.id)" class="no-underline"><Button variant="outline">{{ $t('admin.guestMerge.cancel') }}</Button></Link><Button :loading="submitting" :disabled="!confirmed" @click="submitMerge"><CheckCircle2 class="h-4 w-4" />{{ $t('admin.guestMerge.mergeNow') }}</Button></div>
        </Card>
    </AppLayout>
</template>
