<script setup>
import Button from '@/Components/UI/Button.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import UpdatePasswordForm from '@/Pages/Profile/Partials/UpdatePasswordForm.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { CheckCircle2, Mail, ShieldCheck, UserRound } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

defineProps({
    mustVerifyEmail: Boolean,
    status: String,
});

const user = usePage().props.auth.user;
const { t } = useI18n();
const form = useForm({ name: user.name, email: user.email });
const initials = computed(() => String(user.name || 'A')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase());

function saveProfile() {
    form.patch(route('super-admin.profile.update'), { preserveScroll: true });
}
</script>

<template>
    <SuperAdminLayout :title="t('superAdmin.compact.profile')">
        <div class="sa-page max-w-5xl space-y-4">
            <header>
                <div class="sa-breadcrumb"><span>Control Panel</span><span class="mx-2">/</span><span>{{ t('superAdmin.compact.profile') }}</span></div>
                <h1 class="sa-page-title">{{ t('superAdmin.compact.profile') }}</h1>
                <p class="sa-page-subtitle">{{ t('superAdmin.compact.profileSubtitle') }}</p>
            </header>

            <section class="sa-card">
                <div class="flex flex-col gap-4 border-b border-[var(--sa-line)] p-5 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 items-center gap-4">
                        <span class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-emerald-100 text-lg font-bold text-emerald-900">{{ initials }}</span>
                        <div class="min-w-0"><h2 class="truncate text-lg font-semibold text-neutral-900">{{ user.name }}</h2><p class="mt-1 flex items-center gap-1.5 truncate text-xs text-neutral-500"><Mail class="h-3.5 w-3.5" />{{ user.email }}</p></div>
                    </div>
                    <span class="inline-flex w-fit items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1.5 text-[11px] font-semibold text-emerald-700"><ShieldCheck class="h-3.5 w-3.5" /> Super Admin</span>
                </div>

                <form class="p-5" @submit.prevent="saveProfile">
                    <div class="mb-5 flex items-start gap-3"><span class="sa-icon-box-lg bg-emerald-50 text-emerald-700"><UserRound class="sa-icon-lg" /></span><div><h2 class="sa-card-title">{{ t('superAdmin.compact.profileData') }}</h2><p class="sa-card-subtitle">{{ t('superAdmin.compact.profileDataDescription') }}</p></div></div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block"><span class="mb-1.5 block text-xs font-semibold text-neutral-800">{{ t('superAdmin.compact.name') }}</span><TextInput v-model="form.name" type="text" autocomplete="name" required :error="form.errors.name" /><span v-if="form.errors.name" class="mt-1 block text-[11px] text-red-600">{{ form.errors.name }}</span></label>
                        <label class="block"><span class="mb-1.5 block text-xs font-semibold text-neutral-800">Email</span><TextInput v-model="form.email" type="email" autocomplete="username" required :error="form.errors.email" /><span v-if="form.errors.email" class="mt-1 block text-[11px] text-red-600">{{ form.errors.email }}</span></label>
                    </div>
                    <div class="mt-5 flex items-center justify-end gap-3"><span v-if="form.recentlySuccessful" class="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700"><CheckCircle2 class="h-4 w-4" /> {{ t('superAdmin.compact.saved') }}</span><Button type="submit" :loading="form.processing" :disabled="!form.isDirty">{{ t('superAdmin.compact.saveChanges') }}</Button></div>
                </form>

                <div class="border-t border-[var(--sa-line)] p-5"><UpdatePasswordForm /></div>
            </section>
        </div>
    </SuperAdminLayout>
</template>
