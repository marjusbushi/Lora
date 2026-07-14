<script setup>
import { useI18n } from 'vue-i18n';
import { setLocale } from '@/i18n';
import { Languages } from 'lucide-vue-next';

defineProps({
    variant: { type: String, default: 'inline' },
});

const { locale } = useI18n();
const langs = [
    { code: 'sq', label: 'SQ', name: 'Shqip' },
    { code: 'en', label: 'EN', name: 'English' },
];
</script>

<template>
    <label v-if="variant === 'select'" class="relative inline-flex items-center">
        <span class="sr-only">{{ locale === 'sq' ? 'Zgjidh gjuhën' : 'Choose language' }}</span>
        <Languages class="pointer-events-none absolute left-2.5 h-4 w-4 text-neutral-400" />
        <select
            :value="locale"
            class="h-9 appearance-none rounded-lg border border-neutral-200 bg-white py-1.5 pl-8 pr-8 text-body-sm font-semibold text-neutral-600 outline-none transition hover:border-neutral-300 hover:bg-neutral-50 focus:border-accent-500 focus:ring-2 focus:ring-accent-500/20"
            @change="setLocale($event.target.value)"
        >
            <option v-for="language in langs" :key="language.code" :value="language.code">{{ language.name }}</option>
        </select>
        <span class="pointer-events-none absolute right-2.5 text-tiny text-neutral-400">⌄</span>
    </label>

    <div v-else class="inline-flex items-center gap-1 text-xs font-medium tracking-wide select-none">
        <template v-for="(l, i) in langs" :key="l.code">
            <button
                type="button"
                :class="[
                    'transition-opacity',
                    locale === l.code ? 'opacity-100 font-semibold underline underline-offset-4' : 'opacity-55 hover:opacity-100',
                ]"
                :aria-pressed="locale === l.code"
                @click="setLocale(l.code)"
            >
                {{ l.label }}
            </button>
            <span v-if="i < langs.length - 1" class="opacity-40">·</span>
        </template>
    </div>
</template>
