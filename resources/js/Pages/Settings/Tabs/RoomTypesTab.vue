<script setup>
import { ref, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import Textarea from '@/Components/UI/Textarea.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import Checkbox from '@/Components/UI/Checkbox.vue';

const props = defineProps({ roomTypes: Array, amenities: { type: Array, default: () => [] }, toasts: Object });

const showModal = ref(false);
const showImagesModal = ref(false);
const editingType = ref(null);
const selectedType = ref(null);

const form = useForm({
    name: '', description: '', base_price: '', min_price: '', max_price: '', max_occupancy: 2, amenities: [], breakfast_included: false,
});

const amenityInput = ref('');
const imageFiles = ref(null);
const uploading = ref(false);
const uploadStatus = ref('');

// Photos are optimized IN THE BROWSER before upload: iPhone HEIC is converted to JPG (the
// server has GD only — no HEIF — and Chrome/Firefox can't even display HEIC), and every image
// is downscaled to web size so a 12MB phone photo becomes a fast ~300KB JPG that always uploads.
const MAX_IMAGE_DIM = 2560; // px on the longest side — sharp on retina, tiny on the wire
const JPEG_QUALITY = 0.85;

function isHeic(file) {
    const type = (file.type || '').toLowerCase();
    const name = (file.name || '').toLowerCase();
    return type === 'image/heic' || type === 'image/heif' || name.endsWith('.heic') || name.endsWith('.heif');
}

// HEIC→JPG (if needed) + downscale. Returns a JPEG File ready to upload.
async function prepareImage(file) {
    let source = file;
    if (isHeic(file)) {
        // heic2any bundles libheif (WASM); imported on demand so it never weighs down first paint.
        const heic2any = (await import('heic2any')).default;
        source = await heic2any({ blob: file, toType: 'image/jpeg', quality: JPEG_QUALITY });
        if (Array.isArray(source)) source = source[0]; // a multi-frame HEIC → take the first frame
    }

    // imageOrientation honors EXIF so a portrait phone photo isn't uploaded sideways.
    const bitmap = await createImageBitmap(source, { imageOrientation: 'from-image' });
    const scale = Math.min(1, MAX_IMAGE_DIM / Math.max(bitmap.width, bitmap.height));
    const width = Math.round(bitmap.width * scale);
    const height = Math.round(bitmap.height * scale);

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    canvas.getContext('2d').drawImage(bitmap, 0, 0, width, height);
    bitmap.close?.();

    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', JPEG_QUALITY));
    const baseName = (file.name || 'foto').replace(/\.[^.]+$/, '');
    return new File([blob], `${baseName}.jpg`, { type: 'image/jpeg' });
}

function openCreate() {
    editingType.value = null;
    form.reset();
    showModal.value = true;
}

function openEdit(type) {
    editingType.value = type;
    form.name = type.name;
    form.description = type.description || '';
    form.base_price = type.base_price;
    form.min_price = type.min_price ?? '';
    form.max_price = type.max_price ?? '';
    form.max_occupancy = type.max_occupancy;
    form.amenities = type.amenities || [];
    form.breakfast_included = !!type.breakfast_included;
    showModal.value = true;
}

function openImages(type) {
    selectedType.value = type;
    showImagesModal.value = true;
}

// Master amenities + any already-selected custom names (legacy ones still show as chips).
const allChips = computed(() => {
    const master = props.amenities.map((a) => a.name);
    const extra = (form.amenities || []).filter((n) => !master.includes(n));
    return [...master, ...extra];
});
function isSelected(name) {
    return form.amenities.includes(name);
}
function toggleAmenity(name) {
    const i = form.amenities.indexOf(name);
    if (i === -1) form.amenities.push(name);
    else form.amenities.splice(i, 1);
}
function addAmenity() {
    const v = amenityInput.value.trim();
    if (v && !form.amenities.includes(v)) form.amenities.push(v);
    amenityInput.value = '';
}

function submit() {
    if (editingType.value) {
        form.put(route('settings.room-types.update', editingType.value.id), {
            onSuccess: () => { showModal.value = false; props.toasts?.success('Tipi u perditesua.'); },
        });
    } else {
        form.post(route('settings.room-types.store'), {
            onSuccess: () => { showModal.value = false; form.reset(); props.toasts?.success('Tipi u shtua.'); },
        });
    }
}

function deleteType(type) {
    if (!confirm(`Fshi "${type.name}"?`)) return;
    router.delete(route('settings.room-types.destroy', type.id), {
        preserveScroll: true,
        onSuccess: () => props.toasts?.success('Tipi u fshi.'),
        onError: () => props.toasts?.error(`Nuk mund te fshihet — ka ${type.rooms_count} dhoma.`),
    });
}

async function uploadImages() {
    const files = imageFiles.value?.files;
    if (!files?.length || uploading.value) return;

    uploading.value = true;
    const prepared = [];
    for (let i = 0; i < files.length; i++) {
        uploadStatus.value = `Po përgatitet foto ${i + 1}/${files.length}...`;
        try {
            prepared.push(await prepareImage(files[i]));
        } catch (e) {
            props.toasts?.error(`Fotoja "${files[i].name || i + 1}" s'u lexua dot — provo një format tjetër.`);
        }
    }

    if (!prepared.length) {
        uploading.value = false;
        uploadStatus.value = '';
        return;
    }

    const formData = new FormData();
    prepared.forEach((f) => formData.append('images[]', f));
    uploadStatus.value = `Po ngarkohen ${prepared.length} foto...`;

    router.post(route('settings.room-types.images.upload', selectedType.value.id), formData, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            props.toasts?.success('Fotot u ngarkuan.');
            if (imageFiles.value) imageFiles.value.value = '';
        },
        // Surface the real reason instead of failing silently (this was the whole bug).
        onError: (errors) => {
            const first = Object.values(errors || {})[0];
            props.toasts?.error(first || 'Fotot nuk u ngarkuan. Provo sërish.');
        },
        onFinish: () => {
            uploading.value = false;
            uploadStatus.value = '';
        },
    });
}

function deleteImage(imageId) {
    if (!confirm('Fshi kete foto?')) return;
    router.delete(route('settings.room-types.images.delete', imageId), {
        preserveScroll: true,
        onSuccess: () => props.toasts?.success('Foto u fshi.'),
    });
}

function setAsFeatured(type, imageId) {
    // Reorder: put this image first
    const ids = type.images.map(i => i.id);
    const idx = ids.indexOf(imageId);
    if (idx > 0) {
        ids.splice(idx, 1);
        ids.unshift(imageId);
    }
    router.post(route('settings.room-types.images.reorder', type.id), { image_ids: ids }, {
        preserveScroll: true,
        onSuccess: () => props.toasts?.success('Foto kryesore u vendos.'),
    });
}
</script>

<template>
    <Card>
        <template #header>
            <div class="flex items-center justify-between">
                <h3 class="text-h4 text-primary-900">Tipet e Dhomave</h3>
                <Button size="sm" variant="primary" @click="openCreate">+ Shto tip</Button>
            </div>
        </template>

        <div class="divide-y divide-neutral-100">
            <div v-for="type in roomTypes" :key="type.id" class="py-4">
                <div class="flex items-start gap-4">
                    <!-- Featured image thumbnail -->
                    <div class="h-20 w-28 rounded-lg bg-neutral-100 overflow-hidden shrink-0 flex items-center justify-center">
                        <img v-if="type.images?.length" :src="`/storage/${type.images[0].path}`" :alt="type.name" class="h-full w-full object-cover" />
                        <span v-else class="text-2xl">🏨</span>
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <p class="text-body-sm text-primary-900 font-medium">{{ type.name }}</p>
                            <Badge variant="neutral" size="sm">{{ type.rooms_count }} dhoma</Badge>
                            <Badge v-if="type.images?.length" variant="accent" size="sm">{{ type.images.length }} foto</Badge>
                            <Badge v-else variant="warning" size="sm">Pa foto</Badge>
                        </div>
                        <p class="text-small text-neutral-500 mt-0.5">
                            €{{ type.base_price }}/nate · Max {{ type.max_occupancy }} persona
                        </p>
                    </div>

                    <div class="flex gap-1.5 shrink-0">
                        <Button size="sm" variant="ghost" @click="openImages(type)">📷 Foto</Button>
                        <Button size="sm" variant="ghost" @click="openEdit(type)">Edito</Button>
                        <Button size="sm" variant="ghost" class="text-error-600" @click="deleteType(type)">Fshi</Button>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="!roomTypes?.length" class="py-8 text-center text-body-sm text-neutral-500">Nuk ka tipe dhomash.</div>
    </Card>

    <!-- Edit/Create Modal -->
    <Modal :show="showModal" :title="editingType ? 'Edito tipin' : 'Tip i ri dhome'" @close="showModal = false">
        <form @submit.prevent="submit" class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <FormGroup label="Emri" :error="form.errors.name" required>
                    <TextInput v-model="form.name" placeholder="psh. Deluxe" :error="form.errors.name" />
                </FormGroup>
                <FormGroup label="Cmimi baze (€/nate)" :error="form.errors.base_price" required>
                    <TextInput type="number" v-model="form.base_price" min="0" step="0.01" :error="form.errors.base_price" />
                </FormGroup>
                <FormGroup label="Cmimi minimal (€/nate) — kufi per Cmim Inteligjent" :error="form.errors.min_price">
                    <TextInput type="number" v-model="form.min_price" min="0" step="0.01" :error="form.errors.min_price" placeholder="bosh = pa kufi" />
                </FormGroup>
                <FormGroup label="Cmimi maksimal (€/nate) — kufi per Cmim Inteligjent" :error="form.errors.max_price">
                    <TextInput type="number" v-model="form.max_price" min="0" step="0.01" :error="form.errors.max_price" placeholder="bosh = pa kufi" />
                </FormGroup>
            </div>
            <FormGroup label="Kapaciteti max" :error="form.errors.max_occupancy" required>
                <TextInput type="number" v-model="form.max_occupancy" min="1" max="20" :error="form.errors.max_occupancy" />
            </FormGroup>
            <FormGroup label="Pershkrim" :error="form.errors.description">
                <Textarea v-model="form.description" :rows="2" placeholder="Pershkrim i shkurter..." />
            </FormGroup>
            <div class="py-1">
                <Checkbox v-model="form.breakfast_included" label="Mengjesi i perfshire ne cmim" />
            </div>
            <FormGroup label="Pajisjet (amenities)">
                <div v-if="allChips.length" class="flex flex-wrap gap-1.5 mb-2">
                    <button
                        v-for="name in allChips"
                        :key="name"
                        type="button"
                        :class="[
                            'px-2.5 py-1 rounded-full text-small border transition-colors',
                            isSelected(name)
                                ? 'bg-accent-600 border-accent-600 text-white'
                                : 'bg-white border-neutral-300 text-neutral-600 hover:border-accent-400',
                        ]"
                        @click="toggleAmenity(name)"
                    >
                        <span v-if="isSelected(name)">✓ </span>{{ name }}
                    </button>
                </div>
                <p v-else class="text-small text-neutral-500 mb-2">
                    Asnjë pajisje në listë — shtoji te skeda <b>Pajisjet</b>, ose shkruaj një këtu poshtë.
                </p>
                <div class="flex gap-2">
                    <TextInput v-model="amenityInput" placeholder="Shto pajisje të re..." @keyup.enter.prevent="addAmenity" class="flex-1" />
                    <Button type="button" size="sm" variant="outline" @click="addAmenity">+</Button>
                </div>
            </FormGroup>
        </form>
        <template #footer>
            <Button variant="outline" @click="showModal = false">Anulo</Button>
            <Button variant="primary" :loading="form.processing" @click="submit">{{ editingType ? 'Ruaj' : 'Shto' }}</Button>
        </template>
    </Modal>

    <!-- Images Modal -->
    <Modal :show="showImagesModal" :title="`Foto — ${selectedType?.name}`" max-width="2xl" @close="showImagesModal = false">
        <div v-if="selectedType">
            <!-- Upload -->
            <div class="mb-6 p-4 bg-neutral-50 rounded-lg border border-dashed border-neutral-300">
                <input
                    ref="imageFiles"
                    type="file"
                    accept="image/*,.heic,.heif"
                    multiple
                    :disabled="uploading"
                    class="block w-full text-small text-neutral-500 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-accent-600 file:text-white file:font-medium file:cursor-pointer hover:file:bg-accent-700 disabled:opacity-50"
                />
                <p class="text-tiny text-neutral-400 mt-2">Foto nga telefoni ose kompjuteri — edhe nga iPhone. Optimizohen vetë para ngarkimit. Mund të zgjidhni shumë njëherësh.</p>
                <div class="mt-3 flex items-center gap-3">
                    <Button size="sm" variant="primary" :loading="uploading" :disabled="uploading" @click="uploadImages">Ngarko fotot</Button>
                    <span v-if="uploadStatus" class="text-tiny text-neutral-500">{{ uploadStatus }}</span>
                </div>
            </div>

            <!-- Gallery -->
            <div v-if="selectedType.images?.length" class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                <div v-for="(img, i) in selectedType.images" :key="img.id" class="relative group rounded-lg overflow-hidden border border-neutral-200">
                    <img :src="`/storage/${img.path}`" :alt="`${selectedType.name} foto ${i+1}`" class="h-32 w-full object-cover" />
                    <!-- Featured badge -->
                    <div v-if="i === 0" class="absolute top-1.5 left-1.5 px-2 py-0.5 rounded-md bg-accent-600 text-white text-tiny font-medium">
                        Kryesore
                    </div>
                    <!-- Actions overlay -->
                    <div class="absolute inset-0 bg-primary-950/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2">
                        <button v-if="i !== 0" class="px-2 py-1 rounded bg-white text-tiny font-medium text-primary-900 hover:bg-accent-50" @click="setAsFeatured(selectedType, img.id)">
                            ⭐ Beje kryesore
                        </button>
                        <button class="px-2 py-1 rounded bg-error-600 text-tiny font-medium text-white hover:bg-error-700" @click="deleteImage(img.id)">
                            Fshi
                        </button>
                    </div>
                </div>
            </div>
            <div v-else class="py-8 text-center text-body-sm text-neutral-400">
                Asnje foto akoma. Ngarkoni fotot e para duke klikuar butonin me lart.
            </div>
        </div>
        <template #footer>
            <Button variant="outline" @click="showImagesModal = false">Mbyll</Button>
        </template>
    </Modal>
</template>
