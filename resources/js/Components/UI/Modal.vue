<script setup>
import { watch, onUnmounted, nextTick, ref, useId } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        default: null,
    },
    maxWidth: {
        type: String,
        default: 'lg',
        validator: (v) => ['sm', 'md', 'lg', 'xl', '2xl'].includes(v),
    },
    closeable: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['close']);
const panel = ref(null);
const titleId = useId();
let previouslyFocused = null;

const maxWidthClasses = {
    sm: 'sm:max-w-sm',
    md: 'sm:max-w-md',
    lg: 'sm:max-w-lg',
    xl: 'sm:max-w-xl',
    '2xl': 'sm:max-w-2xl',
};

function close() {
    if (props.closeable) {
        emit('close');
    }
}

function focusableElements() {
    return [...(panel.value?.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
    ) || [])].filter((el) => !el.hasAttribute('hidden') && el.getAttribute('aria-hidden') !== 'true');
}

function onKeydown(e) {
    if (e.key === 'Escape' && props.show) {
        close();
        return;
    }
    if (e.key !== 'Tab' || !props.show || !panel.value) return;

    const focusables = focusableElements();
    if (!focusables.length) {
        e.preventDefault();
        panel.value.focus();
        return;
    }

    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    if (e.shiftKey && (document.activeElement === first || document.activeElement === panel.value)) {
        e.preventDefault();
        last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
    }
}

watch(
    () => props.show,
    (val) => {
        document.body.style.overflow = val ? 'hidden' : '';
        if (val) {
            previouslyFocused = document.activeElement;
            nextTick(() => {
                const first = focusableElements()[0];
                (first || panel.value)?.focus();
            });
        } else if (previouslyFocused instanceof HTMLElement) {
            const target = previouslyFocused;
            previouslyFocused = null;
            nextTick(() => target.isConnected && target.focus());
        }
    },
);

document.addEventListener('keydown', onKeydown);
onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="show" class="fixed inset-0 z-50 overflow-y-auto">
                <!-- Overlay -->
                <div
                    class="fixed inset-0 bg-neutral-900/50"
                    aria-hidden="true"
                    @click="close"
                />

                <!-- Modal panel -->
                <div class="flex min-h-full items-center justify-center p-4">
                    <Transition
                        enter-active-class="duration-200 ease-out"
                        enter-from-class="opacity-0 scale-95 translate-y-4"
                        enter-to-class="opacity-100 scale-100 translate-y-0"
                        leave-active-class="duration-150 ease-in"
                        leave-from-class="opacity-100 scale-100 translate-y-0"
                        leave-to-class="opacity-0 scale-95 translate-y-4"
                    >
                        <div
                            v-if="show"
                            ref="panel"
                            role="dialog"
                            aria-modal="true"
                            :aria-labelledby="title ? titleId : undefined"
                            tabindex="-1"
                            :class="[
                                'relative flex max-h-[calc(100dvh-2rem)] w-full flex-col overflow-hidden rounded-lg bg-white shadow-modal',
                                maxWidthClasses[maxWidth],
                            ]"
                            @click.stop
                        >
                            <!-- Header -->
                            <div v-if="title || closeable" class="flex shrink-0 items-center justify-between border-b border-neutral-200 px-5 py-4">
                                <h3 v-if="title" :id="titleId" class="text-h4 text-neutral-900">{{ title }}</h3>
                                <button
                                    v-if="closeable"
                                    type="button"
                                    :aria-label="$t('admin.generated.k_1fc96a90d625')"
                                    class="ml-auto rounded-md p-1 text-neutral-400 hover:text-neutral-600 hover:bg-neutral-100 transition-colors duration-150"
                                    @click="close"
                                >
                                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                        <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
                                    </svg>
                                </button>
                            </div>

                            <!-- Body -->
                            <div class="min-h-0 overflow-y-auto px-5 py-4">
                                <slot />
                            </div>

                            <!-- Footer -->
                            <div v-if="$slots.footer" class="flex shrink-0 items-center justify-end gap-3 border-t border-neutral-200 bg-neutral-50 px-5 py-3">
                                <slot name="footer" />
                            </div>
                        </div>
                    </Transition>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
