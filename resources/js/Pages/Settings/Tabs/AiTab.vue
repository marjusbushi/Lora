<script setup>
import { translate } from '@/i18n';
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
        props.toasts?.error(translate('admin.generated.k_fbe5d56df48f'));
        return;
    }
    form.put(route('settings.ai'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('gemini_key');
            props.toasts?.success(translate('admin.generated.k_719c90970d3f'));
        },
    });
}

function removeKey() {
    if (!confirm(translate('admin.generated.k_02cb3e45fb96'))) return;
    router.put(route('settings.ai'), { clear: true }, {
        preserveScroll: true,
        onSuccess: () => props.toasts?.success(translate('admin.generated.k_b33deae300c3')),
    });
}
</script>

<template>
    <Card>
        <template #header>
            <div class="flex items-center gap-2">
                <h3 class="text-h4 text-primary-900">{{ $t('admin.generated.k_b07d32848253') }}</h3>
                <Badge v-if="settings.gemini_configured" variant="success">{{ $t('admin.generated.k_bcbc45bbf6e8') }}</Badge>
                <Badge v-else variant="neutral">{{ $t('admin.generated.k_1307eb39d91f') }}</Badge>
            </div>
        </template>

        <div class="space-y-6">
            <!-- What this is -->
            <p class="text-body-sm text-neutral-600 leading-relaxed">
{{ $t('admin.generated.k_547e9223bd8b') }} <strong>{{ $t('admin.generated.k_b7cb6b965db1') }}</strong> {{ $t('admin.generated.k_ad5b1009d09e') }} <em>{{ $t('admin.generated.k_05503bf1991a') }}</em>{{ $t('admin.generated.k_d322ee199a73') }} </p>

            <!-- Current status -->
            <div
                v-if="settings.gemini_configured"
                class="flex items-center justify-between rounded-md border border-success-200 bg-success-50 px-4 py-3"
            >
                <div class="text-body-sm text-success-800">
                    <span class="font-medium">{{ $t('admin.generated.k_8e339048d8c0') }}</span>
                    <span v-if="settings.gemini_key_hint" class="ml-1 font-mono text-success-700">{{ settings.gemini_key_hint }}</span>
                    <span v-if="settings.gemini_from_env" class="ml-1 text-success-600">{{ $t('admin.generated.k_22d835cd094f') }}</span>
                </div>
                <Button v-if="!settings.gemini_from_env" type="button" size="sm" variant="ghost" @click="removeKey">{{ $t('admin.generated.k_b0bb801066d4') }}</Button>
            </div>

            <!-- Set / replace the key -->
            <form @submit.prevent="submit" class="space-y-3">
                <FormGroup
                    :label="settings.gemini_configured ? $t('admin.generated.k_f46d8f181614') : $t('admin.generated.k_1250b62fdb5c')"
                    :error="form.errors.gemini_key"
                >
                    <div class="flex gap-2">
                        <TextInput
                            v-model="form.gemini_key"
                            type="password"
                            :placeholder="$t('admin.generated.k_d5c2d794d1e6')"
                            :error="form.errors.gemini_key"
                            class="flex-1"
                        />
                        <Button type="submit" variant="primary" :loading="form.processing">{{ $t('admin.generated.k_24b1b1ace52b') }}</Button>
                    </div>
                </FormGroup>
                <p class="text-body-xs text-neutral-500">
{{ $t('admin.generated.k_b86ec194f1c4') }} <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener"
                       class="text-accent-600 underline hover:text-accent-700">{{ $t('admin.generated.k_7c8b4ad51b97') }}</a>
{{ $t('admin.generated.k_f66ce5e93e16') }} </p>
            </form>

            <!-- Hotel context for richer AI reasoning -->
            <form @submit.prevent="saveContext" class="space-y-3 border-t border-neutral-200 pt-5">
                <FormGroup
                    :label="$t('admin.ai.hotelContextOptional')"
                    :error="contextForm.errors.hotel_context"
                >
                    <textarea
                        v-model="contextForm.hotel_context"
                        rows="3"
                        maxlength="1000"
                        class="w-full rounded-lg border-neutral-300 text-sm"
                        :placeholder="$t('admin.ai.hotelContextPlaceholder')"
                    />
                </FormGroup>
                <p class="text-body-xs text-neutral-500">
                    {{ $t('admin.ai.hotelContextHint') }}
                </p>
                <Button type="submit" variant="outline" size="sm" :loading="contextForm.processing">{{ $t('admin.ai.saveContext') }}</Button>
            </form>
        </div>
    </Card>
</template>
