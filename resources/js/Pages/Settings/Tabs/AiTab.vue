<script setup>
import { useForm } from '@inertiajs/vue3';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';

const props = defineProps({ settings: Object, toasts: Object });

const contextForm = useForm({ hotel_context: props.settings.ai_hotel_context || '' });

function saveContext() {
    contextForm.put(route('settings.ai'), {
        preserveScroll: true,
        onSuccess: () => props.toasts?.success('Konteksti i hotelit u ruajt.'),
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
            <!-- Çelësi është QENDROR i platformës (task #407) — hoteli s'konfiguron asgjë. -->
            <div
                :class="settings.gemini_configured
                    ? 'rounded-md border border-success-200 bg-success-50 px-4 py-3 text-body-sm text-success-800'
                    : 'rounded-md border border-warning-200 bg-warning-50 px-4 py-3 text-body-sm text-warning-800'"
            >
                <span class="font-medium">{{ settings.gemini_configured ? $t('admin.ai.platformKeyActive') : $t('admin.ai.platformKeyMissing') }}</span>
                <p class="mt-1 text-body-xs opacity-80">{{ $t('admin.ai.platformKeyExplainer') }}</p>
            </div>

            <!-- Hotel context for richer AI reasoning -->
            <form @submit.prevent="saveContext" class="space-y-3">
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
