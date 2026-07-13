<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Checkbox from '@/Components/UI/Checkbox.vue';
import TextInput from '@/Components/UI/TextInput.vue';

const props = defineProps({ settings: Object, toasts: Object });

const form = useForm({
    enabled: Boolean(props.settings.enabled),
    api_key: '',
    clear_key: false,
    competitors: [...(props.settings.competitors || [])],
    frequency: props.settings.frequency || '3x_week',
    search_query: props.settings.search_query || 'Hotels Sarande Albania',
});

const newCompetitor = ref('');

function addCompetitor() {
    const name = newCompetitor.value.trim();
    if (name && !form.competitors.includes(name)) form.competitors.push(name);
    newCompetitor.value = '';
}
function removeCompetitor(i) {
    form.competitors.splice(i, 1);
}
function submit() {
    form.put(route('settings.market-rates'), {
        preserveScroll: true,
        onSuccess: () => {
            form.api_key = '';
            form.clear_key = false;
            props.toasts?.success('Çmimet e tregut u ruajtën.');
        },
    });
}
</script>

<template>
    <Card>
        <template #header>
            <div>
                <h3 class="text-h4 text-primary-900">Çmimet e Tregut (konkurrentët)</h3>
                <p class="text-tiny text-neutral-500 mt-1">
                    Merr çmimet e hoteleve konkurrente të zonës (çmimin MË TË LIRË të secilit — dhomën e tyre hyrëse) dhe i shfaq te Çmim Inteligjent si "Tregu".
                    Nuk i ndryshon çmimet e tua — vetëm të informon. Kur është OFF, s'bëhet asnjë thirrje (zero kosto).
                </p>
            </div>
        </template>

        <div class="space-y-6">
            <!-- toggle -->
            <div class="flex items-center gap-3">
                <Checkbox v-model="form.enabled" label="Aktive" />
                <span v-if="!form.enabled" class="text-tiny text-neutral-500">— asnjë thirrje API, asnjë kosto.</span>
            </div>

            <!-- API key -->
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-body-sm font-semibold text-primary-900 mb-1">Çelësi API (SerpAPI)</label>
                    <TextInput
                        v-model="form.api_key"
                        type="password"
                        class="w-full"
                        :placeholder="settings.configured ? 'I ruajtur: ' + settings.api_key_hint + ' — plotëso vetëm për ta ndërruar' : 'Ngjit çelësin nga serpapi.com'"
                        autocomplete="off"
                    />
                    <div v-if="settings.configured" class="mt-2">
                        <Checkbox v-model="form.clear_key" label="Hiq çelësin e ruajtur" />
                    </div>
                </div>
                <div>
                    <label class="block text-body-sm font-semibold text-primary-900 mb-1">Sa shpesh</label>
                    <select v-model="form.frequency" class="w-full rounded-lg border border-neutral-200 px-3 py-2 text-body-sm">
                        <option value="3x_week">3 herë në javë (Hën/Mër/Pre) — kosto më e ulët</option>
                        <option value="daily">Çdo ditë</option>
                    </select>
                    <label class="block text-body-sm font-semibold text-primary-900 mb-1 mt-3">Zona e kërkimit</label>
                    <TextInput v-model="form.search_query" class="w-full" placeholder="Hotels Sarande Albania" />
                </div>
            </div>

            <!-- competitors -->
            <div>
                <label class="block text-body-sm font-semibold text-primary-900 mb-2">
                    Konkurrentët ({{ form.competitors.length }})
                </label>
                <ul class="flex flex-wrap gap-2">
                    <li
                        v-for="(c, i) in form.competitors"
                        :key="c"
                        class="flex items-center gap-2 bg-neutral-50 border border-neutral-200 rounded-full pl-3 pr-1.5 py-1 text-body-sm text-primary-900"
                    >
                        {{ c }}
                        <button
                            type="button"
                            class="w-5 h-5 rounded-full text-neutral-400 hover:text-error-600 hover:bg-error-50 leading-none"
                            title="Hiq"
                            @click="removeCompetitor(i)"
                        >
                            ×
                        </button>
                    </li>
                </ul>
                <div class="flex gap-2 mt-3">
                    <TextInput
                        v-model="newCompetitor"
                        class="flex-1"
                        placeholder="Shto konkurrent (emri si te Google/Booking)"
                        @keyup.enter="addCompetitor"
                    />
                    <Button type="button" variant="secondary" @click="addCompetitor">Shto</Button>
                </div>
                <p class="text-tiny text-neutral-500 mt-2">
                    Emrat përputhen me ata të Google Hotels — nëse një konkurrent s'sjell çmime, provo emrin e tij të saktë të listimit.
                </p>
            </div>

            <div class="flex justify-end">
                <Button :disabled="form.processing" @click="submit">Ruaj</Button>
            </div>
        </div>
    </Card>
</template>
