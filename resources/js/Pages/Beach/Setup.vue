<script setup>
import { computed, ref } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { translate } from '@/i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import QrCode from '@/Components/UI/QrCode.vue';

const props = defineProps({
    zones: { type: Array, default: () => [] },
    settings: { type: Object, default: () => ({}) },
});

const page = usePage();
const can = (permission) => page.props.auth?.user?.permissions?.includes(permission) ?? false;

// --- Zona: krijo / edito ---
const showZoneModal = ref(false);
const editingZone = ref(null);
const zoneForm = useForm({ name: '', price_per_day: '', sort_order: 0, is_active: true });

function openCreateZone() {
    editingZone.value = null;
    zoneForm.reset();
    zoneForm.clearErrors();
    showZoneModal.value = true;
}

function openEditZone(zone) {
    editingZone.value = zone;
    zoneForm.name = zone.name;
    zoneForm.price_per_day = zone.price_per_day;
    zoneForm.sort_order = zone.sort_order;
    zoneForm.is_active = zone.is_active;
    zoneForm.clearErrors();
    showZoneModal.value = true;
}

function submitZone() {
    const options = { preserveScroll: true, onSuccess: () => (showZoneModal.value = false) };
    if (editingZone.value) {
        zoneForm.put(route('beach.zones.update', editingZone.value.id), options);
    } else {
        zoneForm.post(route('beach.zones.store'), options);
    }
}

function deleteZone(zone) {
    if (!confirm(translate('beach.setup.deleteZoneConfirm', { name: zone.name }))) return;
    router.delete(route('beach.zones.destroy', zone.id), { preserveScroll: true });
}

// --- Çadrat: gjenerim në grup ---
const showGenerateModal = ref(false);
const generateZone = ref(null);
const generateForm = useForm({ count: 10, start_number: '' });

function openGenerate(zone) {
    generateZone.value = zone;
    generateForm.reset();
    generateForm.clearErrors();
    showGenerateModal.value = true;
}

function submitGenerate() {
    generateForm
        .transform((data) => ({ ...data, start_number: data.start_number === '' ? null : data.start_number }))
        .post(route('beach.units.generate', generateZone.value.id), {
            preserveScroll: true,
            onSuccess: () => (showGenerateModal.value = false),
        });
}

// --- Çadra: edito / fshi ---
const showUnitModal = ref(false);
const editingUnit = ref(null);
const unitForm = useForm({ number: '', sort_order: 0, is_active: true });

function openEditUnit(unit) {
    editingUnit.value = unit;
    unitForm.number = unit.number;
    unitForm.sort_order = unit.sort_order;
    unitForm.is_active = unit.is_active;
    unitForm.clearErrors();
    showUnitModal.value = true;
}

function submitUnit() {
    unitForm.put(route('beach.units.update', editingUnit.value.id), {
        preserveScroll: true,
        onSuccess: () => (showUnitModal.value = false),
    });
}

function deleteUnit(unit) {
    if (!confirm(translate('beach.setup.deleteUnitConfirm', { number: unit.number }))) return;
    router.delete(route('beach.units.destroy', unit.id), {
        preserveScroll: true,
        onSuccess: () => (showUnitModal.value = false),
    });
}

// --- Fleta QR për printim ---
const allUnits = computed(() =>
    props.zones.flatMap((zone) => zone.units.map((unit) => ({ ...unit, zoneName: zone.name }))),
);

function qrUrl(unit) {
    return `${window.location.origin}/s/${unit.qr_token}`;
}

function printQrSheet() {
    window.print();
}
</script>

<template>
    <AppLayout>
        <Head :title="$t('beach.setup.title')" />

        <div class="print:hidden">
            <PageHeader :title="$t('beach.setup.title')">
                <template #actions>
                    <Button v-if="allUnits.length" variant="outline" @click="printQrSheet">
                        {{ $t('beach.setup.printQr') }}
                    </Button>
                    <Button v-if="can('create_beach')" variant="primary" @click="openCreateZone">
                        {{ $t('beach.setup.addZone') }}
                    </Button>
                </template>
            </PageHeader>
            <p class="text-body-sm text-neutral-500 mt-2 mb-6">
                {{ $t('beach.setup.subtitle') }}
                <a href="/pms/settings?tab=beach" class="text-primary-600 underline underline-offset-2">
                    {{ $t('beach.setup.settingsLink') }}
                </a>
            </p>

            <div v-if="!zones.length" class="py-16 text-center">
                <p class="text-h4 text-primary-900">{{ $t('beach.setup.noZonesTitle') }}</p>
                <p class="text-body-sm text-neutral-500 mt-1">{{ $t('beach.setup.noZonesHint') }}</p>
                <Button v-if="can('create_beach')" class="mt-4" variant="primary" @click="openCreateZone">
                    {{ $t('beach.setup.addZone') }}
                </Button>
            </div>

            <div class="space-y-5">
                <Card v-for="zone in zones" :key="zone.id">
                    <template #header>
                        <div class="flex flex-wrap items-center gap-3">
                            <h3 class="text-h4 text-primary-900">{{ zone.name }}</h3>
                            <Badge variant="info" size="sm">
                                {{ $t('beach.setup.pricePerDayBadge', { price: zone.price_per_day }) }}
                            </Badge>
                            <Badge :variant="zone.is_active ? 'success' : 'neutral'" size="sm">
                                {{ zone.is_active ? $t('beach.setup.active') : $t('beach.setup.inactive') }}
                            </Badge>
                            <span class="text-small text-neutral-500">
                                {{ $t('beach.setup.unitsCount', { count: zone.units.length }) }}
                            </span>
                            <div class="ml-auto flex gap-1.5">
                                <Button v-if="can('create_beach')" size="sm" variant="secondary" @click="openGenerate(zone)">
                                    {{ $t('beach.setup.generateUnits') }}
                                </Button>
                                <Button v-if="can('update_beach')" size="sm" variant="ghost" @click="openEditZone(zone)">
                                    {{ $t('beach.setup.edit') }}
                                </Button>
                                <Button v-if="can('delete_beach')" size="sm" variant="ghost" class="text-error-600" @click="deleteZone(zone)">
                                    {{ $t('beach.setup.delete') }}
                                </Button>
                            </div>
                        </div>
                    </template>

                    <div v-if="zone.units.length" class="flex flex-wrap gap-2">
                        <button
                            v-for="unit in zone.units"
                            :key="unit.id"
                            type="button"
                            class="h-11 min-w-11 px-2 rounded-lg border text-body-sm font-semibold transition"
                            :class="unit.is_active
                                ? 'border-primary-200 bg-primary-50 text-primary-900 hover:bg-primary-100'
                                : 'border-neutral-200 bg-neutral-100 text-neutral-400 line-through'"
                            :title="$t('beach.setup.editUnit')"
                            @click="can('update_beach') && openEditUnit(unit)"
                        >
                            {{ unit.number }}
                        </button>
                    </div>
                    <p v-else class="py-4 text-center text-body-sm text-neutral-500">
                        {{ $t('beach.setup.noUnits') }}
                    </p>
                </Card>
            </div>
        </div>

        <!-- Fleta QR — shfaqet VETËM në print (A4) -->
        <div class="hidden print:block beach-print">
            <h1 class="text-xl font-bold mb-4">{{ $t('beach.setup.print.title') }}</h1>
            <div class="grid grid-cols-3 gap-4">
                <div
                    v-for="unit in allUnits"
                    :key="unit.id"
                    class="border border-neutral-300 rounded-lg p-4 flex flex-col items-center text-center break-inside-avoid"
                >
                    <p class="text-xs text-neutral-500">{{ unit.zoneName }}</p>
                    <p class="text-4xl font-black my-1">{{ unit.number }}</p>
                    <QrCode :value="qrUrl(unit)" :size="140" :alt="$t('beach.setup.print.qrAlt', { number: unit.number })" />
                    <p class="text-xs text-neutral-600 mt-2">{{ $t('beach.setup.print.scanToBook') }}</p>
                </div>
            </div>
        </div>

        <!-- Modal: zona -->
        <Modal
            :show="showZoneModal"
            :title="editingZone ? $t('beach.setup.editZone') : $t('beach.setup.addZone')"
            @close="showZoneModal = false"
        >
            <form class="space-y-4" @submit.prevent="submitZone">
                <FormGroup :label="$t('beach.setup.zoneName')" :error="zoneForm.errors.name" required>
                    <TextInput v-model="zoneForm.name" :placeholder="$t('beach.setup.zoneNamePlaceholder')" :error="zoneForm.errors.name" />
                </FormGroup>
                <FormGroup :label="$t('beach.setup.pricePerDay')" :error="zoneForm.errors.price_per_day" required>
                    <TextInput v-model="zoneForm.price_per_day" type="number" min="0" step="0.01" :error="zoneForm.errors.price_per_day" />
                </FormGroup>
                <div class="grid grid-cols-2 gap-4">
                    <FormGroup :label="$t('beach.setup.sortOrder')" :error="zoneForm.errors.sort_order">
                        <TextInput v-model="zoneForm.sort_order" type="number" min="0" :error="zoneForm.errors.sort_order" />
                    </FormGroup>
                    <FormGroup :label="$t('beach.setup.activeLabel')">
                        <label class="flex h-10 items-center gap-2 text-body-sm text-primary-900">
                            <input v-model="zoneForm.is_active" type="checkbox" class="rounded border-neutral-300" />
                            {{ $t('beach.setup.activeHint') }}
                        </label>
                    </FormGroup>
                </div>
            </form>
            <template #footer>
                <Button variant="outline" @click="showZoneModal = false">{{ $t('beach.setup.cancel') }}</Button>
                <Button variant="primary" :loading="zoneForm.processing" @click="submitZone">
                    {{ editingZone ? $t('beach.setup.save') : $t('beach.setup.create') }}
                </Button>
            </template>
        </Modal>

        <!-- Modal: gjenero çadra -->
        <Modal
            :show="showGenerateModal"
            :title="$t('beach.setup.generateTitle', { zone: generateZone?.name ?? '' })"
            @close="showGenerateModal = false"
        >
            <form class="space-y-4" @submit.prevent="submitGenerate">
                <FormGroup :label="$t('beach.setup.count')" :error="generateForm.errors.count" required>
                    <TextInput v-model="generateForm.count" type="number" min="1" max="200" :error="generateForm.errors.count" />
                </FormGroup>
                <FormGroup :label="$t('beach.setup.startNumber')" :error="generateForm.errors.start_number">
                    <TextInput v-model="generateForm.start_number" type="number" min="1" :placeholder="$t('beach.setup.startNumberPlaceholder')" :error="generateForm.errors.start_number" />
                    <p class="text-small text-neutral-500 mt-1">{{ $t('beach.setup.startNumberHint') }}</p>
                </FormGroup>
            </form>
            <template #footer>
                <Button variant="outline" @click="showGenerateModal = false">{{ $t('beach.setup.cancel') }}</Button>
                <Button variant="primary" :loading="generateForm.processing" @click="submitGenerate">
                    {{ $t('beach.setup.generate') }}
                </Button>
            </template>
        </Modal>

        <!-- Modal: çadra -->
        <Modal :show="showUnitModal" :title="$t('beach.setup.editUnitTitle', { number: editingUnit?.number ?? '' })" @close="showUnitModal = false">
            <form class="space-y-4" @submit.prevent="submitUnit">
                <FormGroup :label="$t('beach.setup.unitNumber')" :error="unitForm.errors.number" required>
                    <TextInput v-model="unitForm.number" :error="unitForm.errors.number" />
                </FormGroup>
                <div class="grid grid-cols-2 gap-4">
                    <FormGroup :label="$t('beach.setup.sortOrder')" :error="unitForm.errors.sort_order">
                        <TextInput v-model="unitForm.sort_order" type="number" min="0" :error="unitForm.errors.sort_order" />
                    </FormGroup>
                    <FormGroup :label="$t('beach.setup.activeLabel')">
                        <label class="flex h-10 items-center gap-2 text-body-sm text-primary-900">
                            <input v-model="unitForm.is_active" type="checkbox" class="rounded border-neutral-300" />
                            {{ $t('beach.setup.unitActiveHint') }}
                        </label>
                    </FormGroup>
                </div>
            </form>
            <template #footer>
                <Button v-if="can('delete_beach')" variant="ghost" class="mr-auto text-error-600" @click="deleteUnit(editingUnit)">
                    {{ $t('beach.setup.delete') }}
                </Button>
                <Button variant="outline" @click="showUnitModal = false">{{ $t('beach.setup.cancel') }}</Button>
                <Button variant="primary" :loading="unitForm.processing" @click="submitUnit">{{ $t('beach.setup.save') }}</Button>
            </template>
        </Modal>
    </AppLayout>
</template>

<style>
@media print {
    body * {
        visibility: hidden;
    }
    .beach-print,
    .beach-print * {
        visibility: visible;
    }
    .beach-print {
        position: absolute;
        inset: 0;
        padding: 1cm;
    }
}
</style>
