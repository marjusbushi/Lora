<script setup>
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';

const props = defineProps({ settings: Object, toasts: Object });

const form = useForm({
    instagram: props.settings.instagram || '',
    facebook: props.settings.facebook || '',
    maps_url: props.settings.maps_url || '',
    logo: null,
    hero_image: null,
});

const logoInput = ref(null);
const heroInput = ref(null);
const logoPreview = ref(null);
const heroPreview = ref(null);

const currentLogo = computed(() => props.settings.logo ? `/storage/${props.settings.logo}` : null);
const currentHero = computed(() => props.settings.hero_image ? `/storage/${props.settings.hero_image}` : null);

function onLogo(e) {
    const f = e.target.files?.[0] || null;
    form.logo = f;
    logoPreview.value = f ? URL.createObjectURL(f) : null;
}
function onHero(e) {
    const f = e.target.files?.[0] || null;
    form.hero_image = f;
    heroPreview.value = f ? URL.createObjectURL(f) : null;
}

function submit() {
    form.post(route('settings.website'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.logo = null;
            form.hero_image = null;
            logoPreview.value = null;
            heroPreview.value = null;
            if (logoInput.value) logoInput.value.value = '';
            if (heroInput.value) heroInput.value.value = '';
            props.toasts?.success('Faqja web u ruajt.');
        },
    });
}

const fileInputClass = 'block w-full text-small text-neutral-500 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-accent-600 file:text-white file:font-medium file:cursor-pointer hover:file:bg-accent-700';
</script>

<template>
    <Card>
        <template #header>
            <div>
                <h3 class="text-h4 text-primary-900">Faqja Web</h3>
                <p class="text-small text-neutral-500 mt-0.5">Logo, foto kryesore, rrjete sociale dhe harta — dalin direkt te faqja publike.</p>
            </div>
        </template>

        <form @submit.prevent="submit" class="space-y-8">
            <!-- Logo -->
            <div class="grid grid-cols-1 sm:grid-cols-[160px_1fr] gap-4 items-start">
                <div>
                    <p class="text-label text-neutral-700 mb-2">Logo</p>
                    <div class="h-20 w-40 rounded-lg border border-neutral-200 bg-neutral-50 flex items-center justify-center overflow-hidden">
                        <img v-if="logoPreview || currentLogo" :src="logoPreview || currentLogo" alt="Logo" class="max-h-full max-w-full object-contain" />
                        <span v-else class="text-tiny text-neutral-400">Pa logo</span>
                    </div>
                </div>
                <div>
                    <input ref="logoInput" type="file" accept="image/jpeg,image/png,image/webp" :class="fileInputClass" @change="onLogo" />
                    <p class="text-tiny text-neutral-400 mt-2">PNG me sfond transparent funksionon më mirë. JPG/PNG/WebP, max 3MB.</p>
                    <p v-if="form.errors.logo" class="text-small text-error-600 mt-1">{{ form.errors.logo }}</p>
                </div>
            </div>

            <!-- Hero image -->
            <div class="grid grid-cols-1 sm:grid-cols-[160px_1fr] gap-4 items-start">
                <div>
                    <p class="text-label text-neutral-700 mb-2">Foto kryesore (hero)</p>
                    <div class="h-24 w-40 rounded-lg border border-neutral-200 bg-neutral-50 overflow-hidden">
                        <img v-if="heroPreview || currentHero" :src="heroPreview || currentHero" alt="Hero" class="h-full w-full object-cover" />
                        <div v-else class="h-full w-full flex items-center justify-center text-tiny text-neutral-400">Foto e parazgjedhur</div>
                    </div>
                </div>
                <div>
                    <input ref="heroInput" type="file" accept="image/jpeg,image/png,image/webp" :class="fileInputClass" @change="onHero" />
                    <p class="text-tiny text-neutral-400 mt-2">Foto e gjerë (landscape), ideal ≥1920px. Max 6MB. Shfaqet në krye të faqes Home.</p>
                    <p v-if="form.errors.hero_image" class="text-small text-error-600 mt-1">{{ form.errors.hero_image }}</p>
                </div>
            </div>

            <hr class="border-neutral-100" />

            <!-- Social + map -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormGroup label="Instagram (URL)" :error="form.errors.instagram">
                    <TextInput v-model="form.instagram" placeholder="https://instagram.com/villamucho" :error="form.errors.instagram" />
                </FormGroup>
                <FormGroup label="Facebook (URL)" :error="form.errors.facebook">
                    <TextInput v-model="form.facebook" placeholder="https://facebook.com/villamucho" :error="form.errors.facebook" />
                </FormGroup>
            </div>

            <FormGroup label="Vendndodhja (adresa ose emri i vendit)" :error="form.errors.maps_url">
                <TextInput v-model="form.maps_url" placeholder="psh. Villa Mucho, Ksamil  ose  Rruga Mitat Hoxha, Ksamil" :error="form.errors.maps_url" />
                <p class="text-tiny text-neutral-400 mt-1.5">Shkruaj adresën ose emrin e vendit — harta ndërtohet vetë dhe shfaqet te faqja Kontakt. (Mund të ngjisësh edhe një URL 'embed' nga Google Maps nëse do saktësi më të madhe.)</p>
            </FormGroup>

            <div class="flex justify-end pt-2">
                <Button type="submit" variant="primary" :loading="form.processing">Ruaj ndryshimet</Button>
            </div>
        </form>
    </Card>
</template>
