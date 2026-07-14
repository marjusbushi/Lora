<script setup>
import Dropdown from '@/Components/Dropdown.vue';
import { useI18n } from 'vue-i18n';
import { setLocale } from '@/i18n';
import { Check, Globe2 } from 'lucide-vue-next';

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
    <Dropdown v-if="variant === 'icon'" align="right" width="48" content-classes="overflow-hidden rounded-xl bg-white p-2">
        <template #trigger>
            <button
                type="button"
                class="grid h-9 w-9 place-items-center rounded-lg text-neutral-500 transition hover:bg-neutral-100 hover:text-neutral-800 focus:outline-none focus:ring-2 focus:ring-accent-500/30"
                :aria-label="locale === 'sq' ? 'Zgjidh gjuhën' : 'Choose language'"
                :title="locale === 'sq' ? 'Gjuha' : 'Language'"
            >
                <Globe2 class="h-[18px] w-[18px]" />
            </button>
        </template>

        <template #content>
            <div role="menu" :aria-label="locale === 'sq' ? 'Zgjidh gjuhën' : 'Choose language'">
                <button
                    v-for="language in langs"
                    :key="language.code"
                    type="button"
                    role="menuitemradio"
                    :aria-checked="locale === language.code"
                    class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2.5 text-left text-body-sm transition"
                    :class="locale === language.code
                        ? 'bg-accent-50 font-semibold text-accent-700'
                        : 'text-neutral-700 hover:bg-neutral-50 hover:text-neutral-900'"
                    @click="setLocale(language.code)"
                >
                    <span>{{ language.name }}</span>
                    <Check v-if="locale === language.code" class="h-4 w-4 shrink-0" />
                </button>
            </div>
        </template>
    </Dropdown>

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
