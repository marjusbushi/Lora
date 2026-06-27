<script setup>
import { useForm } from '@inertiajs/vue3';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import Select from '@/Components/UI/Select.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';

const props = defineProps({ settings: Object, toasts: Object });

const form = useForm({
    name: props.settings.name || '',
    address: props.settings.address || '',
    phone: props.settings.phone || '',
    email: props.settings.email || '',
    timezone: props.settings.timezone || 'Europe/Tirane',
    currency: props.settings.currency || 'EUR',
    check_in_time: props.settings.check_in_time || '14:00',
    check_out_time: props.settings.check_out_time || '11:00',
    hero_eyebrow_sq: props.settings.hero_eyebrow_sq || '',
    hero_eyebrow_en: props.settings.hero_eyebrow_en || '',
    hero_title_sq: props.settings.hero_title_sq || '',
    hero_title_en: props.settings.hero_title_en || '',
    hero_subtitle_sq: props.settings.hero_subtitle_sq || '',
    hero_subtitle_en: props.settings.hero_subtitle_en || '',
    logo: null,
});

const currencyOptions = [
    { value: 'EUR', label: 'Euro (€)' },
    { value: 'ALL', label: 'Lek (L)' },
    { value: 'USD', label: 'Dollar ($)' },
    { value: 'GBP', label: 'Pound (£)' },
];

const timezoneOptions = [
    { value: 'Europe/Tirane', label: 'Europe/Tirane (CET)' },
    { value: 'Europe/Rome', label: 'Europe/Rome (CET)' },
    { value: 'Europe/London', label: 'Europe/London (GMT)' },
    { value: 'Europe/Berlin', label: 'Europe/Berlin (CET)' },
];

function submit() {
    form.put(route('settings.hotel'), {
        onSuccess: () => props.toasts?.success('Informacionet u ruajten.'),
    });
}
</script>

<template>
    <Card>
        <template #header>
            <h3 class="text-h4 text-primary-900">Informacionet e Hotelit</h3>
        </template>

        <form @submit.prevent="submit" class="space-y-5">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormGroup label="Emri i hotelit" :error="form.errors.name" required>
                    <TextInput v-model="form.name" placeholder="Hotel Demo" :error="form.errors.name" />
                </FormGroup>
                <FormGroup label="Email" :error="form.errors.email">
                    <TextInput type="email" v-model="form.email" placeholder="info@hotel.com" :error="form.errors.email" />
                </FormGroup>
                <FormGroup label="Telefon" :error="form.errors.phone">
                    <TextInput v-model="form.phone" placeholder="+355 4 234 5678" :error="form.errors.phone" />
                </FormGroup>
                <FormGroup label="Valuta" :error="form.errors.currency" required>
                    <Select v-model="form.currency" :options="currencyOptions" :error="form.errors.currency" />
                </FormGroup>
            </div>

            <FormGroup label="Adresa" :error="form.errors.address">
                <TextInput v-model="form.address" placeholder="Rruga, qyteti" :error="form.errors.address" />
            </FormGroup>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <FormGroup label="Timezone" :error="form.errors.timezone" required>
                    <Select v-model="form.timezone" :options="timezoneOptions" :error="form.errors.timezone" />
                </FormGroup>
                <FormGroup label="Ora check-in" :error="form.errors.check_in_time" required>
                    <TextInput type="time" v-model="form.check_in_time" :error="form.errors.check_in_time" />
                </FormGroup>
                <FormGroup label="Ora check-out" :error="form.errors.check_out_time" required>
                    <TextInput type="time" v-model="form.check_out_time" :error="form.errors.check_out_time" />
                </FormGroup>
            </div>

            <hr class="border-neutral-100" />

            <!-- Hero text (per language) — shown at the top of the public Home page -->
            <div>
                <p class="text-label text-neutral-700 mb-1">Teksti i hero-s (faqja Home)</p>
                <p class="text-tiny text-neutral-400 mb-3">Mbititulli, titulli i madh dhe nëntitulli në krye të faqes. Shkruaji në të dyja gjuhët — vizitori i sheh sipas gjuhës (SQ/EN). Bosh = teksti i parazgjedhur.</p>
                <div class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <FormGroup label="Mbititull — Shqip">
                            <TextInput v-model="form.hero_eyebrow_sq" placeholder="Ksamil · Bregu Jon" />
                        </FormGroup>
                        <FormGroup label="Eyebrow — English">
                            <TextInput v-model="form.hero_eyebrow_en" placeholder="Ksamil · Ionian Shore" />
                        </FormGroup>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <FormGroup label="Titulli i madh — Shqip">
                            <TextInput v-model="form.hero_title_sq" placeholder="Nje shtepi e madhe mbi detin Jon" />
                        </FormGroup>
                        <FormGroup label="Big title — English">
                            <TextInput v-model="form.hero_title_en" placeholder="A grand house above the Ionian Sea" />
                        </FormGroup>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <FormGroup label="Nëntitulli — Shqip">
                            <TextInput v-model="form.hero_subtitle_sq" placeholder="Qetesi, gur i bardhe dhe mikpritje..." />
                        </FormGroup>
                        <FormGroup label="Subtitle — English">
                            <TextInput v-model="form.hero_subtitle_en" placeholder="Calm, white stone and true hospitality..." />
                        </FormGroup>
                    </div>
                </div>
            </div>

            <div class="flex justify-end pt-2">
                <Button type="submit" variant="primary" :loading="form.processing">Ruaj ndryshimet</Button>
            </div>
        </form>
    </Card>
</template>
