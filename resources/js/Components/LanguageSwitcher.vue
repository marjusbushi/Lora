<script setup>
import { useI18n } from 'vue-i18n';
import { setLocale } from '@/i18n';

const { locale } = useI18n();
const langs = [
    { code: 'sq', label: 'SQ' },
    { code: 'en', label: 'EN' },
];
</script>

<template>
    <div class="inline-flex items-center gap-1 text-xs font-medium tracking-wide select-none">
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
