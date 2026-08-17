<script setup>
import { reactive, ref, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { translate } from '@/i18n';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import TextInput from '@/Components/UI/TextInput.vue';

// FAQ e hotelit — njohuritë nga të cilat Lora AI u përgjigjet mysafirëve.
const props = defineProps({
    faqs: { type: Array, default: () => [] },
    // Cikli i mësimit: pyetje që Lora s'i dinte + përgjigjja e stafit, në pritje.
    suggestions: { type: Array, default: () => [] },
    toasts: { type: Object, default: null },
});

// Çdo sugjerim redaktohet lokalisht para ruajtjes; rilidhet kur serveri
// rifreskon listën (pas accept/dismiss vjen payload i ri nga Inertia).
const suggestionDrafts = reactive({});
watch(
    () => props.suggestions,
    (list) => {
        (list || []).forEach((s) => {
            if (!suggestionDrafts[s.id]) {
                suggestionDrafts[s.id] = { question: s.question, answer: s.suggested_answer, processing: false };
            }
        });
    },
    { immediate: true },
);

function acceptSuggestion(s) {
    const draft = suggestionDrafts[s.id];
    if (!draft || !draft.question.trim() || !draft.answer.trim()) return;
    draft.processing = true;
    router.post(route('settings.faqs.suggestions.accept', s.id), {
        question: draft.question,
        answer: draft.answer,
    }, {
        preserveScroll: true,
        onSuccess: () => props.toasts?.success(translate('faqTab.suggestions.saved')),
        onFinish: () => { if (suggestionDrafts[s.id]) suggestionDrafts[s.id].processing = false; },
    });
}

function dismissSuggestion(s) {
    router.post(route('settings.faqs.suggestions.dismiss', s.id), {}, {
        preserveScroll: true,
        onSuccess: () => props.toasts?.success(translate('faqTab.suggestions.dismissed')),
    });
}

const createForm = useForm({ question: '', answer: '' });
const editingId = ref(null);
const editForm = reactive({ question: '', answer: '', is_active: true });

function submitCreate() {
    createForm.post(route('settings.faqs.store'), {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset();
            props.toasts?.success(translate('faqTab.created'));
        },
    });
}

function startEdit(faq) {
    editingId.value = faq.id;
    editForm.question = faq.question;
    editForm.answer = faq.answer;
    editForm.is_active = faq.is_active;
}

function submitEdit(faq) {
    router.put(route('settings.faqs.update', faq.id), { ...editForm }, {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null;
            props.toasts?.success(translate('faqTab.updated'));
        },
    });
}

function toggleActive(faq) {
    router.put(route('settings.faqs.update', faq.id), {
        question: faq.question,
        answer: faq.answer,
        is_active: !faq.is_active,
    }, { preserveScroll: true });
}

function destroy(faq) {
    if (!confirm(translate('faqTab.confirmDelete'))) return;
    router.delete(route('settings.faqs.destroy', faq.id), {
        preserveScroll: true,
        onSuccess: () => props.toasts?.success(translate('faqTab.deleted')),
    });
}
</script>

<template>
    <div class="space-y-5">
        <div
            v-if="!faqs.length"
            class="rounded-xl border border-warning-300 bg-warning-50 px-4 py-3 text-body-sm text-warning-800"
            role="status"
        >
            <b>{{ $t('faqTab.emptyTitle') }}</b> {{ $t('faqTab.emptyBody') }}
        </div>

        <!-- Cikli i mësimit: pyetje nga biseda reale që Lora s'i dinte -->
        <Card v-if="suggestions.length" class="border-[#dcd2f2]">
            <template #header>
                <div>
                    <h3 class="text-h4 text-[#59409e]">✨ {{ $t('faqTab.suggestions.title') }}</h3>
                    <p class="text-small text-neutral-500 mt-0.5">{{ $t('faqTab.suggestions.subtitle') }}</p>
                </div>
            </template>

            <div class="divide-y divide-neutral-100">
                <div v-for="s in suggestions" :key="s.id" class="grid gap-2 py-3 sm:grid-cols-[1fr_1.4fr_auto]">
                    <TextInput v-if="suggestionDrafts[s.id]" v-model="suggestionDrafts[s.id].question" maxlength="300" />
                    <TextInput v-if="suggestionDrafts[s.id]" v-model="suggestionDrafts[s.id].answer" maxlength="2000" />
                    <div class="flex items-center gap-2">
                        <Button size="sm" variant="primary" :loading="suggestionDrafts[s.id]?.processing" @click="acceptSuggestion(s)">
                            {{ $t('faqTab.suggestions.save') }}
                        </Button>
                        <Button size="sm" variant="ghost" class="text-neutral-500" @click="dismissSuggestion(s)">
                            {{ $t('faqTab.suggestions.dismiss') }}
                        </Button>
                    </div>
                </div>
            </div>
        </Card>

        <Card>
            <template #header>
                <div>
                    <h3 class="text-h4 text-primary-900">{{ $t('faqTab.title') }}</h3>
                    <p class="text-small text-neutral-500 mt-0.5">{{ $t('faqTab.subtitle') }}</p>
                </div>
            </template>

            <form class="grid gap-3 sm:grid-cols-[1fr_1.4fr_auto]" @submit.prevent="submitCreate">
                <FormGroup :label="$t('faqTab.question')" :error="createForm.errors.question" required>
                    <TextInput v-model="createForm.question" maxlength="300" :placeholder="$t('faqTab.questionPlaceholder')" :error="createForm.errors.question" />
                </FormGroup>
                <FormGroup :label="$t('faqTab.answer')" :error="createForm.errors.answer" required>
                    <TextInput v-model="createForm.answer" maxlength="2000" :placeholder="$t('faqTab.answerPlaceholder')" :error="createForm.errors.answer" />
                </FormGroup>
                <div class="flex items-end">
                    <Button type="submit" variant="primary" :loading="createForm.processing">{{ $t('faqTab.add') }}</Button>
                </div>
            </form>

            <div class="mt-4 divide-y divide-neutral-100">
                <div v-for="faq in faqs" :key="faq.id" class="py-3">
                    <div v-if="editingId === faq.id" class="grid gap-2 sm:grid-cols-[1fr_1.4fr_auto]">
                        <TextInput v-model="editForm.question" maxlength="300" />
                        <TextInput v-model="editForm.answer" maxlength="2000" />
                        <div class="flex items-center gap-2">
                            <Button size="sm" variant="primary" @click="submitEdit(faq)">{{ $t('faqTab.save') }}</Button>
                            <Button size="sm" variant="ghost" @click="editingId = null">{{ $t('faqTab.cancel') }}</Button>
                        </div>
                    </div>
                    <div v-else class="flex items-start gap-3">
                        <button
                            type="button"
                            class="mt-0.5 h-5 w-9 shrink-0 rounded-full transition"
                            :class="faq.is_active ? 'bg-success-500' : 'bg-neutral-300'"
                            :title="faq.is_active ? $t('faqTab.active') : $t('faqTab.inactive')"
                            @click="toggleActive(faq)"
                        >
                            <span class="block h-4 w-4 rounded-full bg-white shadow transition" :class="faq.is_active ? 'translate-x-4' : 'translate-x-0.5'" />
                        </button>
                        <div class="min-w-0 flex-1" :class="!faq.is_active && 'opacity-50'">
                            <p class="text-body-sm font-semibold text-primary-900">{{ faq.question }}</p>
                            <p class="text-small text-neutral-600 whitespace-pre-wrap">{{ faq.answer }}</p>
                        </div>
                        <Button size="sm" variant="ghost" @click="startEdit(faq)">{{ $t('faqTab.edit') }}</Button>
                        <Button size="sm" variant="ghost" class="text-error-600" @click="destroy(faq)">{{ $t('faqTab.delete') }}</Button>
                    </div>
                </div>
                <p v-if="!faqs.length" class="py-6 text-center text-body-sm text-neutral-500">{{ $t('faqTab.noneYet') }}</p>
            </div>
        </Card>
    </div>
</template>
