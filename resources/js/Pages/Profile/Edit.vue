<script setup>
import Button from '@/Components/UI/Button.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { Building2, CheckCircle2, Clock3, Mail, ShieldCheck, SlidersHorizontal } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';

defineProps({
    mustVerifyEmail: { type: Boolean },
    status: { type: String },
});

const page = usePage();
const { locale } = useI18n();
const user = page.props.auth.user;
const tenant = page.props.tenant;
const profileInformation = ref(null);
const profileFormState = computed(() => profileInformation.value?.form);

const initials = computed(() => user.name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase());

const roleLabel = computed(() => {
    const labels = locale.value === 'sq'
        ? { admin: 'Administrator', manager: 'Menaxher', receptionist: 'Recepsionist', housekeeping: 'Housekeeping', maintenance: 'Mirëmbajtje', pos_staff: 'Staf POS' }
        : { admin: 'Administrator', manager: 'Manager', receptionist: 'Receptionist', housekeeping: 'Housekeeping', maintenance: 'Maintenance', pos_staff: 'POS staff' };

    return labels[user.role] || user.role || '—';
});
</script>

<template>
    <Head :title="$t('accountCenter.pageTitle')" />

    <AppLayout>
        <PageHeader
            :title="$t('accountCenter.pageTitle')"
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: $t('accountCenter.pageTitle') }]"
        />
        <p class="mt-1 text-body-sm text-neutral-500">{{ $t('accountCenter.pageSubtitle') }}</p>

        <div class="mt-5 max-w-6xl pb-10">
            <section class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-card">
                <div class="h-1 bg-gradient-to-r from-primary-950 via-accent-800 to-accent-500" />

                <div class="flex flex-col gap-5 border-b border-neutral-200 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <div class="flex min-w-0 items-center gap-4">
                        <div class="grid h-16 w-16 shrink-0 place-items-center rounded-2xl bg-accent-100 text-h3 font-extrabold text-accent-800 ring-4 ring-accent-50">
                            {{ initials }}
                        </div>
                        <div class="min-w-0">
                            <h2 class="truncate text-h3 font-extrabold text-primary-900">{{ user.name }}</h2>
                            <p class="mt-1 flex items-center gap-1.5 truncate text-body-sm text-neutral-500">
                                <Mail class="h-4 w-4 shrink-0" /> {{ user.email }}
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-success-100 bg-success-50 px-3 py-1.5 text-tiny font-bold text-success-700">
                            <CheckCircle2 class="h-3.5 w-3.5" /> {{ $t('accountCenter.accountActive') }}
                        </span>
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-success-100 bg-success-50 px-3 py-1.5 text-tiny font-bold text-success-700">
                            <ShieldCheck class="h-4 w-4" /> {{ roleLabel }}
                        </span>
                    </div>
                </div>

                <div class="grid lg:grid-cols-[minmax(0,1.7fr)_minmax(280px,0.8fr)]">
                    <div class="min-w-0 p-5 sm:p-6">
                        <UpdateProfileInformationForm ref="profileInformation" :must-verify-email="mustVerifyEmail" :status="status" />
                    </div>

                    <aside class="border-t border-neutral-200 bg-neutral-50/60 p-5 sm:p-6 lg:border-l lg:border-t-0">
                        <div class="flex items-start gap-3">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-accent-50 text-accent-700">
                                <SlidersHorizontal class="h-5 w-5" />
                            </span>
                            <div>
                                <h2 class="text-body font-bold text-primary-900">{{ $t('accountCenter.accessPreferences') }}</h2>
                                <p class="mt-0.5 text-body-sm text-neutral-500">{{ $t('accountCenter.accessPreferencesDescription') }}</p>
                            </div>
                        </div>

                        <div class="mt-5 divide-y divide-neutral-200">
                            <div class="flex gap-3 py-4 first:pt-0">
                                <Building2 class="mt-0.5 h-4 w-4 shrink-0 text-neutral-400" />
                                <div class="min-w-0">
                                    <p class="text-body-sm font-semibold text-primary-900">{{ $t('accountCenter.hotel') }}</p>
                                    <p class="mt-1 truncate text-small text-neutral-500">{{ tenant?.name || '—' }}</p>
                                </div>
                            </div>
                            <div class="flex gap-3 py-4">
                                <ShieldCheck class="mt-0.5 h-4 w-4 shrink-0 text-neutral-400" />
                                <div>
                                    <p class="text-body-sm font-semibold text-primary-900">{{ $t('accountCenter.role') }}</p>
                                    <p class="mt-1 text-small text-neutral-500">{{ roleLabel }}</p>
                                </div>
                            </div>
                            <div class="flex gap-3 py-4">
                                <Clock3 class="mt-0.5 h-4 w-4 shrink-0 text-neutral-400" />
                                <div>
                                    <p class="text-body-sm font-semibold text-primary-900">{{ $t('accountCenter.timezone') }}</p>
                                    <p class="mt-1 text-small text-neutral-500">{{ tenant?.timezone || 'Europe/Tirane' }}</p>
                                </div>
                            </div>
                        </div>
                    </aside>
                </div>

                <div class="border-t border-neutral-200 px-5 py-5 sm:px-6">
                    <UpdatePasswordForm />
                </div>

                <footer class="flex flex-col-reverse gap-3 border-t border-neutral-200 bg-neutral-50/50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <DeleteUserForm compact />
                    <div class="flex items-center justify-end gap-3">
                        <Transition enter-active-class="transition" enter-from-class="opacity-0" leave-active-class="transition" leave-to-class="opacity-0">
                            <span v-if="profileFormState?.recentlySuccessful" class="inline-flex items-center gap-1.5 text-body-sm font-semibold text-success-700">
                                <CheckCircle2 class="h-4 w-4" /> {{ $t('accountCenter.saved') }}
                            </span>
                        </Transition>
                        <Button
                            type="submit"
                            form="profile-information-form"
                            :loading="profileFormState?.processing"
                            :disabled="!profileFormState?.isDirty"
                        >
                            {{ $t('accountCenter.saveChanges') }}
                        </Button>
                    </div>
                </footer>
            </section>
        </div>
    </AppLayout>
</template>
