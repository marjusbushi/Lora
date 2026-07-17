<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { Bot, CalendarDays, Check, Copy, ExternalLink, MessageSquareText, ShieldCheck, Sparkles } from 'lucide-vue-next';

const props = defineProps({
    connection: { type: Object, required: true },
    settings: { type: Object, required: true },
    modules: { type: Object, required: true },
    recentActions: { type: Array, default: () => [] },
});

const copied = ref(false);
const form = useForm({ ...props.settings });

function save() {
    form.put(route('lora-ai.update'), { preserveScroll: true });
}

async function copyEndpoint() {
    await navigator.clipboard.writeText(props.connection.endpoint);
    copied.value = true;
    setTimeout(() => copied.value = false, 1800);
}

function disconnect() {
    if (window.confirm('Ta shkëpusim ChatGPT nga ky hotel?')) {
        router.delete(route('lora-ai.disconnect'), { preserveScroll: true });
    }
}

const actions = {
    'ai.guest_reply.sent': 'Përgjigje për mysafirin u dërgua',
    'ai.pricing_range.applied': 'Çmimet e aprovuara u aplikuan',
};
</script>

<template>
    <Head title="Lora AI" />
    <AppLayout>
        <div class="mx-auto w-full max-w-[1440px] space-y-5 px-4 py-6 sm:px-6 lg:px-8">
            <PageHeader
                title="Lora AI"
                subtitle="Lidhe ChatGPT me të dhënat operative të hotelit, me leje të kontrolluara."
                :breadcrumbs="[{ label: 'Paneli', href: '/dashboard' }, { label: 'Lora AI' }]"
            />

            <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm">
                <div class="grid gap-6 bg-gradient-to-r from-primary-900 to-primary-700 p-6 text-white lg:grid-cols-[1fr_auto] lg:items-center">
                    <div class="flex items-start gap-4">
                        <div class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-white/15"><Bot class="h-6 w-6" /></div>
                        <div>
                            <div class="mb-1 flex items-center gap-2">
                                <h2 class="text-xl font-semibold">ChatGPT për {{ connection.hotel }}</h2>
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="connection.connected ? 'bg-emerald-300/20 text-emerald-100' : 'bg-white/15 text-white/80'">
                                    {{ connection.connected ? 'I lidhur' : 'Pa lidhur' }}
                                </span>
                            </div>
                            <p class="max-w-2xl text-sm leading-6 text-white/75">Pyet për rezervimet, dhomat, disponueshmërinë, çmimet dhe bisedat me mysafirët. Çdo lidhje izolohet vetëm te ky hotel.</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="inline-flex h-10 items-center gap-2 rounded-lg border border-white/25 px-4 text-sm font-semibold hover:bg-white/10" @click="copyEndpoint">
                            <Check v-if="copied" class="h-4 w-4" /><Copy v-else class="h-4 w-4" />{{ copied ? 'U kopjua' : 'Kopjo MCP URL' }}
                        </button>
                        <a :href="connection.chatgptUrl" target="_blank" rel="noopener" class="inline-flex h-10 items-center gap-2 rounded-lg bg-white px-4 text-sm font-semibold text-primary-800 hover:bg-primary-50">
                            Hap ChatGPT <ExternalLink class="h-4 w-4" />
                        </a>
                    </div>
                </div>
                <div class="grid gap-3 border-t border-neutral-200 bg-neutral-50 px-6 py-4 text-sm md:grid-cols-3">
                    <div class="flex items-center gap-2 text-neutral-700"><ShieldCheck class="h-4 w-4 text-primary-600" /> OAuth 2.1 dhe izolim sipas hotelit</div>
                    <div class="flex items-center gap-2 text-neutral-700"><Check class="h-4 w-4 text-primary-600" /> Lejet trashëgohen nga roli i stafit</div>
                    <div class="flex items-center gap-2 text-neutral-700"><Check class="h-4 w-4 text-primary-600" /> Mesazhet dhe çmimet kërkojnë aprovim</div>
                </div>
            </section>

            <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
                <form class="space-y-5" @submit.prevent="save">
                    <section class="rounded-2xl border border-neutral-200 bg-white shadow-sm">
                        <div class="border-b border-neutral-200 px-5 py-4">
                            <h2 class="font-semibold text-neutral-900">Të dhënat që mund të lexojë</h2>
                            <p class="mt-1 text-sm text-neutral-500">Dokumentet e identitetit dhe të dhënat e kartave nuk ekspozohen kurrë.</p>
                        </div>
                        <div class="divide-y divide-neutral-100">
                            <label class="flex cursor-pointer items-center justify-between gap-4 px-5 py-4">
                                <span class="flex items-start gap-3"><span class="grid h-9 w-9 place-items-center rounded-lg bg-blue-50 text-blue-600"><CalendarDays class="h-4 w-4" /></span><span><b class="block text-sm text-neutral-900">Rezervime, dhoma dhe disponueshmëri</b><small class="mt-0.5 block text-neutral-500">Qëndrimi, dhoma, statusi, kontakti dhe bilanci operacional.</small></span></span>
                                <input v-model="form.reservations_enabled" type="checkbox" class="h-5 w-5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                            </label>
                            <label class="flex cursor-pointer items-center justify-between gap-4 px-5 py-4" :class="!modules.channel_manager && 'opacity-50'">
                                <span class="flex items-start gap-3"><span class="grid h-9 w-9 place-items-center rounded-lg bg-violet-50 text-violet-600"><MessageSquareText class="h-4 w-4" /></span><span><b class="block text-sm text-neutral-900">Bisedat me mysafirët</b><small class="mt-0.5 block text-neutral-500">Mesazhet e lidhura me rezervimin dhe kontekstin e qëndrimit.</small></span></span>
                                <input v-model="form.messages_enabled" type="checkbox" :disabled="!modules.channel_manager" class="h-5 w-5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                            </label>
                            <label class="flex cursor-pointer items-center justify-between gap-4 px-5 py-4" :class="!modules.smart_pricing && 'opacity-50'">
                                <span class="flex items-start gap-3"><span class="grid h-9 w-9 place-items-center rounded-lg bg-amber-50 text-amber-600"><Sparkles class="h-4 w-4" /></span><span><b class="block text-sm text-neutral-900">Kalendari dhe motori i çmimeve</b><small class="mt-0.5 block text-neutral-500">Çmimi aktual, sugjerimi, okupimi dhe faktorët për çdo datë.</small></span></span>
                                <input v-model="form.pricing_enabled" type="checkbox" :disabled="!modules.smart_pricing" class="h-5 w-5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                            </label>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-neutral-200 bg-white shadow-sm">
                        <div class="border-b border-neutral-200 px-5 py-4">
                            <h2 class="font-semibold text-neutral-900">Veprime të mbrojtura</h2>
                            <p class="mt-1 text-sm text-neutral-500">ChatGPT përgatit parapamjen; përdoruesi duhet ta konfirmojë para ekzekutimit.</p>
                        </div>
                        <div class="grid gap-4 p-5 md:grid-cols-2">
                            <label class="rounded-xl border border-neutral-200 p-4" :class="!form.messages_enabled && 'opacity-50'">
                                <span class="flex items-center justify-between gap-3"><b class="text-sm text-neutral-900">Dërgo përgjigje te mysafiri</b><input v-model="form.guest_reply_enabled" type="checkbox" :disabled="!form.messages_enabled" class="h-5 w-5 rounded border-neutral-300 text-primary-600" /></span>
                                <span class="mt-2 block text-xs leading-5 text-neutral-500">Gjithmonë me preview, konfirmim, idempotencë dhe audit.</span>
                            </label>
                            <label class="rounded-xl border border-neutral-200 p-4" :class="!form.pricing_enabled && 'opacity-50'">
                                <span class="flex items-center justify-between gap-3"><b class="text-sm text-neutral-900">Apliko propozime çmimesh</b><input v-model="form.price_apply_enabled" type="checkbox" :disabled="!form.pricing_enabled" class="h-5 w-5 rounded border-neutral-300 text-primary-600" /></span>
                                <span class="mt-2 block text-xs leading-5 text-neutral-500">Rillogaritet nga motori dhe bllokohet nëse të dhënat kanë ndryshuar.</span>
                            </label>
                        </div>
                        <div class="flex items-center justify-between border-t border-neutral-200 px-5 py-4">
                            <button v-if="connection.revocable" type="button" class="text-sm font-semibold text-red-600 hover:text-red-700" @click="disconnect">Shkëput ChatGPT</button><span v-else />
                            <button type="submit" :disabled="form.processing" class="h-10 rounded-lg bg-primary-700 px-5 text-sm font-semibold text-white hover:bg-primary-800 disabled:opacity-50">{{ form.processing ? 'Duke ruajtur…' : 'Ruaj lejet' }}</button>
                        </div>
                    </section>
                </form>

                <aside class="space-y-5">
                    <section class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm">
                        <h3 class="font-semibold text-neutral-900">Si lidhet</h3>
                        <ol class="mt-4 space-y-4 text-sm text-neutral-600">
                            <li class="flex gap-3"><span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-primary-50 text-xs font-bold text-primary-700">1</span><span>Kopjo MCP URL dhe hape ChatGPT.</span></li>
                            <li class="flex gap-3"><span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-primary-50 text-xs font-bold text-primary-700">2</span><span>Shto një connector të ri dhe vendos URL-në.</span></li>
                            <li class="flex gap-3"><span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-primary-50 text-xs font-bold text-primary-700">3</span><span>Hyr me llogarinë Lora dhe aprovo vetëm hotelin e shfaqur.</span></li>
                        </ol>
                        <code class="mt-4 block break-all rounded-lg bg-neutral-900 p-3 text-xs leading-5 text-neutral-100">{{ connection.endpoint }}</code>
                    </section>
                    <section class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm">
                        <h3 class="font-semibold text-neutral-900">Aktiviteti i fundit AI</h3>
                        <div v-if="recentActions.length" class="mt-3 divide-y divide-neutral-100">
                            <div v-for="item in recentActions" :key="`${item.action}-${item.created_at}`" class="py-3 text-sm">
                                <b class="block text-neutral-800">{{ actions[item.action] || item.action }}</b>
                                <span class="text-xs text-neutral-500">{{ item.created_at }}</span>
                            </div>
                        </div>
                        <p v-else class="mt-3 text-sm text-neutral-500">Nuk ka ende veprime të ekzekutuara nga AI.</p>
                    </section>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
