<script setup>
import { useForm, router } from '@inertiajs/vue3';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';

const props = defineProps({ settings: Object, toasts: Object });

const form = useForm({ gemini_key: '' });
const contextForm = useForm({ hotel_context: props.settings.ai_hotel_context || '' });

function saveContext() {
    contextForm.put(route('settings.ai'), {
        preserveScroll: true,
        onSuccess: () => props.toasts?.success('Konteksti i hotelit u ruajt.'),
    });
}

function submit() {
    if (!form.gemini_key.trim()) {
        props.toasts?.error('Ngjit fillimisht çelësin.');
        return;
    }
    form.put(route('settings.ai'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('gemini_key');
            props.toasts?.success('Çelësi u ruajt — asistenti i çmimeve u aktivizua.');
        },
    });
}

function removeKey() {
    if (!confirm('Të heq çelësin? Asistenti AI i çmimeve do të çaktivizohet.')) return;
    router.put(route('settings.ai'), { clear: true }, {
        preserveScroll: true,
        onSuccess: () => props.toasts?.success('Çelësi u hoq.'),
    });
}
</script>

<template>
    <Card>
        <template #header>
            <div class="flex items-center gap-2">
                <h3 class="text-h4 text-primary-900">Asistenti AI i Çmimeve</h3>
                <Badge v-if="settings.gemini_configured" variant="success">Aktiv</Badge>
                <Badge v-else variant="neutral">Joaktiv</Badge>
            </div>
        </template>

        <div class="space-y-6">
            <!-- What this is -->
            <p class="text-body-sm text-neutral-600 leading-relaxed">
                Asistenti AI lexon të dhënat e hotelit tënd (çmimet, rezervimet, ritmin e shitjeve
                krahasuar me vitin e kaluar) plus eventet që i shënon ti, dhe të propozon një
                <strong>plan çmimesh</strong> me arsyetim në shqip te faqja
                <em>Çmim Inteligjent</em>. Ti vendos çfarë aplikon — asgjë s'ndryshon vetë.
                Për të punuar, ka nevojë për një çelës falas nga Google (Gemini).
            </p>

            <!-- Current status -->
            <div
                v-if="settings.gemini_configured"
                class="flex items-center justify-between rounded-md border border-success-200 bg-success-50 px-4 py-3"
            >
                <div class="text-body-sm text-success-800">
                    <span class="font-medium">Çelësi është vendosur.</span>
                    <span v-if="settings.gemini_key_hint" class="ml-1 font-mono text-success-700">{{ settings.gemini_key_hint }}</span>
                    <span v-if="settings.gemini_from_env" class="ml-1 text-success-600">(nga serveri)</span>
                </div>
                <Button v-if="!settings.gemini_from_env" type="button" size="sm" variant="ghost" @click="removeKey">Hiq çelësin</Button>
            </div>

            <!-- Set / replace the key -->
            <form @submit.prevent="submit" class="space-y-3">
                <FormGroup
                    :label="settings.gemini_configured ? 'Zëvendëso çelësin Gemini' : 'Çelësi Gemini'"
                    :error="form.errors.gemini_key"
                >
                    <div class="flex gap-2">
                        <TextInput
                            v-model="form.gemini_key"
                            type="password"
                            placeholder="Ngjit këtu çelësin nga Google AI Studio…"
                            :error="form.errors.gemini_key"
                            class="flex-1"
                        />
                        <Button type="submit" variant="primary" :loading="form.processing">Ruaj</Button>
                    </div>
                </FormGroup>
                <p class="text-body-xs text-neutral-500">
                    Merr një çelës falas te
                    <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener"
                       class="text-accent-600 underline hover:text-accent-700">aistudio.google.com/apikey</a>
                    → “Create API key”. Çelësi ruhet vetëm te ky server dhe nuk shfaqet më i plotë për arsye sigurie.
                </p>
            </form>

            <!-- Hotel context for richer AI reasoning -->
            <form @submit.prevent="saveContext" class="space-y-3 border-t border-neutral-200 pt-5">
                <FormGroup
                    label="Konteksti i hotelit (opsional)"
                    :error="contextForm.errors.hotel_context"
                >
                    <textarea
                        v-model="contextForm.hotel_context"
                        rows="3"
                        maxlength="1000"
                        class="w-full rounded-lg border-neutral-300 text-sm"
                        placeholder="P.sh.: Hotel plazhi në Ksamil; mysafirët kryesisht shqiptarë, italianë, grekë e kosovarë; piku korrik–gusht, Ferragosto e mbush qytetin…"
                    />
                </FormGroup>
                <p class="text-body-xs text-neutral-500">
                    Ky përshkrim i jepet AI-së bashkë me të dhënat — sa më konkret (tregu i mysafirëve,
                    sezonaliteti, çfarë e mbush zonën), aq më të sakta arsyetimet e çmimeve.
                </p>
                <Button type="submit" variant="outline" size="sm" :loading="contextForm.processing">Ruaj kontekstin</Button>
            </form>
        </div>
    </Card>
</template>
