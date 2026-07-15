<script setup>
const props = defineProps({
    variant: {
        type: String,
        default: 'primary',
        validator: (v) => ['primary', 'secondary', 'outline', 'danger', 'success', 'ghost'].includes(v),
    },
    size: {
        type: String,
        default: 'md',
        validator: (v) => ['sm', 'md', 'lg'].includes(v),
    },
    type: {
        type: String,
        default: 'button',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    href: {
        type: String,
        default: null,
    },
});

const emit = defineEmits(['click']);

const variantClasses = {
    primary:
        'bg-accent-600 text-white hover:bg-accent-700 active:bg-accent-800 focus:ring-accent-500/40 shadow-sm',
    secondary:
        'bg-primary-900 text-white hover:bg-primary-800 active:bg-primary-950 focus:ring-primary-500/40 shadow-sm',
    outline:
        'border border-neutral-200 bg-white text-neutral-700 hover:bg-neutral-50 hover:border-neutral-300 active:bg-neutral-100 focus:ring-accent-500/40',
    danger:
        'bg-error-600 text-white hover:bg-error-700 active:bg-error-800 focus:ring-error-500/40 shadow-sm',
    success:
        'bg-success-600 text-white hover:bg-success-700 active:bg-success-800 focus:ring-success-500/40 shadow-sm',
    ghost:
        'bg-transparent text-neutral-600 hover:bg-neutral-100 active:bg-neutral-200 focus:ring-accent-500/40',
};

const sizeClasses = {
    sm: 'px-3 py-1.5 text-body-sm gap-1.5',
    md: 'px-4 py-2 text-body-sm gap-2',
    lg: 'px-6 py-3 text-body gap-2.5',
};

function handleClick(e) {
    if (props.disabled || props.loading) {
        e.preventDefault();
        return;
    }
    emit('click', e);
}
</script>

<template>
    <component
        :is="href ? 'a' : 'button'"
        :href="href"
        :type="href ? undefined : type"
        :disabled="disabled || loading"
        data-ui="button"
        :data-size="size"
        :class="[
            'inline-flex items-center justify-center font-medium rounded-md transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-offset-1',
            variantClasses[variant],
            sizeClasses[size],
            (disabled || loading) && 'opacity-50 cursor-not-allowed',
        ]"
        @click="handleClick"
    >
        <!-- Loading spinner -->
        <svg
            v-if="loading"
            class="animate-spin shrink-0"
            :class="{ 'h-4 w-4': size === 'sm' || size === 'md', 'h-5 w-5': size === 'lg' }"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
        >
            <circle
                class="opacity-25"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                stroke-width="4"
            />
            <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
            />
        </svg>

        <!-- Left icon slot -->
        <slot name="icon-left" />

        <!-- Default content -->
        <slot />

        <!-- Right icon slot -->
        <slot name="icon-right" />
    </component>
</template>
