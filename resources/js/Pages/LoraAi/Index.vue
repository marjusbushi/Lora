<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import SettingsSidebar from '@/Components/SettingsSidebar.vue';
import {
    Bot, CalendarDays, Check, Copy, ExternalLink, MessageSquareText,
    PackageSearch, Search, ShieldCheck, Sparkles, SprayCan, UtensilsCrossed,
    WalletCards, Wrench,
} from 'lucide-vue-next';

const props = defineProps({
    connection: { type: Object, required: true },
    aiSettings: { type: Object, required: true },
    aiModules: { type: Object, required: true },
    pricingPolicy: { type: Object, required: true },
    recentActions: { type: Array, default: () => [] },
});

const copied = ref(false);
const promptCopied = ref('');
const form = useForm({ ...props.aiSettings });
const isAdmin = computed(() => usePage().props.auth.user?.role === 'admin');
const breadcrumbs = computed(() => isAdmin.value
    ? [{ label: 'Paneli', href: '/dashboard' }, { label: 'Cilësimet', href: '/pms/settings' }, { label: 'Lora AI' }]
    : [{ label: 'Paneli', href: '/dashboard' }, { label: 'Lora AI' }]);

const quickPrompts = [
    'Më jep përmbledhjen operative të sotme.',
    'Gjej rezervimin ose referencën që do të të jap dhe më jep linkun.',
    'Cilat dhoma kanë check-in sot dhe kërkojnë vëmendje?',
    'Krahaso çmimin aktual, motorin Lora dhe tregun për 14 ditët e ardhshme.',
    'Gjej problemet e hapura të mirëmbajtjes dhe pastrimit.',
    'Gjej faturat, porositë ose produktet që lidhen me këtë referencë.',
];

function save() {
    form.put(route('lora-ai.update'), { preserveScroll: true });
}

async function copyEndpoint() {
    await navigator.clipboard.writeText(props.connection.endpoint);
    copied.value = true;
    setTimeout(() => copied.value = false, 1800);
}

async function copyPrompt(prompt) {
    await navigator.clipboard.writeText(prompt);
    promptCopied.value = prompt;
    setTimeout(() => promptCopied.value = '', 1600);
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
                subtitle="Lidhja me ChatGPT, kërkimi universal dhe lejet e kontrolluara."
                :breadcrumbs="breadcrumbs"
            />

            <div class="flex flex-col gap-6 lg:flex-row">
                <SettingsSidebar v-if="isAdmin" active-item="lora-ai" />

                <div class="min-w-0 flex-1 space-y-5">
            <section class="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm">
                <div class="grid gap-6 bg-white p-6 text-neutral-900 lg:grid-cols-[1fr_auto] lg:items-center">
                    <div class="flex items-start gap-4">
                        <div class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-accent-50 text-accent-700"><Bot class="h-6 w-6" /></div>
                        <div>
                            <div class="mb-1 flex items-center gap-2">
                                <h2 class="text-xl font-semibold">ChatGPT për {{ connection.hotel }}</h2>
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="connection.connected ? 'bg-emerald-50 text-emerald-700' : 'bg-neutral-100 text-neutral-600'">
                                    {{ connection.connected ? 'I lidhur' : 'Pa lidhur' }}
                                </span>
                            </div>
                            <p class="max-w-2xl text-sm leading-6 text-neutral-500">ChatGPT kërkon në modulet që lejon ti, kthen linkun e rekordit burim dhe krahason çmimin live me motorin Lora, tregun dhe rekomandimin e vet. Çdo lidhje izolohet vetëm te ky hotel.</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="inline-flex h-10 items-center gap-2 rounded-lg border border-neutral-300 bg-white px-4 text-sm font-semibold text-neutral-700 hover:bg-neutral-50" @click="copyEndpoint">
                            <Check v-if="copied" class="h-4 w-4" /><Copy v-else class="h-4 w-4" />{{ copied ? 'U kopjua' : 'Kopjo MCP URL' }}
                        </button>
                        <a :href="connection.chatgptUrl" target="_blank" rel="noopener" class="inline-flex h-10 items-center gap-2 rounded-lg bg-accent-700 px-4 text-sm font-semibold text-white hover:bg-accent-800">
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

            <section class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_420px]">
                <div class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-2 text-neutral-900"><Search class="h-5 w-5 text-primary-700" /><h2 class="font-semibold">Pyetje të shpejta për ChatGPT</h2></div>
                            <p class="mt-1 text-sm text-neutral-500">Kopjo një pyetje ose shkruaje natyrshëm në ChatGPT. Rezultatet përfshijnë linkun drejt rekordit në Lora.</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">Vetëm lexim</span>
                    </div>
                    <div class="mt-4 grid gap-2 md:grid-cols-2">
                        <button v-for="prompt in quickPrompts" :key="prompt" type="button" class="flex items-center justify-between gap-3 rounded-xl border border-neutral-200 px-3.5 py-3 text-left text-sm text-neutral-700 transition hover:border-primary-300 hover:bg-primary-50/40" @click="copyPrompt(prompt)">
                            <span>{{ prompt }}</span><Check v-if="promptCopied === prompt" class="h-4 w-4 shrink-0 text-emerald-600" /><Copy v-else class="h-4 w-4 shrink-0 text-neutral-400" />
                        </button>
                    </div>
                </div>

                <div class="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center gap-2 text-neutral-900"><Sparkles class="h-5 w-5 text-amber-600" /><h2 class="font-semibold">Çmimi hibrid</h2></div>
                    <p class="mt-1 text-sm leading-6 text-neutral-500">Një vendim, katër burime të ndara qartë.</p>
                    <div class="mt-4 space-y-2">
                        <div class="flex items-center justify-between rounded-lg bg-neutral-50 px-3 py-2 text-sm"><span class="text-neutral-500">Çmimi live</span><b class="text-neutral-800">Shitet tani</b></div>
                        <div class="flex items-center justify-between rounded-lg bg-blue-50 px-3 py-2 text-sm"><span class="text-blue-700">Motori Lora</span><b class="text-blue-900">Determinist</b></div>
                        <div class="flex items-center justify-between rounded-lg bg-violet-50 px-3 py-2 text-sm"><span class="text-violet-700">ChatGPT</span><b class="text-violet-900">Alternativë + arsye</b></div>
                        <div class="flex items-center justify-between rounded-lg bg-amber-50 px-3 py-2 text-sm"><span class="text-amber-700">Tregu</span><b class="text-amber-900">Kur ka të dhëna</b></div>
                    </div>
                    <p class="mt-3 text-xs leading-5 text-neutral-500">Rekomandimi ChatGPT kufizohet ±{{ pricingPolicy.maxDeviationPct }}% nga referenca e motorit dhe gjithmonë brenda minimumit/maksimumit të hotelit.</p>
                </div>
            </section>

            <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
                <form class="space-y-5" @submit.prevent="save">
                    <section class="rounded-2xl border border-neutral-200 bg-white shadow-sm">
                        <div class="border-b border-neutral-200 px-5 py-4">
                            <div class="flex items-center justify-between gap-4">
                                <div><h2 class="font-semibold text-neutral-900">Të dhënat dhe kërkimi universal</h2><p class="mt-1 text-sm text-neutral-500">Aktivizo vetëm modulet që ChatGPT duhet të kërkojë. Dokumentet e identitetit dhe kartat nuk ekspozohen.</p></div>
                                <label class="flex shrink-0 items-center gap-2 text-sm font-semibold text-neutral-700"><span>Kërkimi universal</span><input v-model="form.universal_search_enabled" type="checkbox" class="h-5 w-5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" /></label>
                            </div>
                        </div>
                        <div class="divide-y divide-neutral-100">
                            <label class="flex cursor-pointer items-center justify-between gap-4 px-5 py-4">
                                <span class="flex items-start gap-3"><span class="grid h-9 w-9 place-items-center rounded-lg bg-blue-50 text-blue-600"><CalendarDays class="h-4 w-4" /></span><span><b class="block text-sm text-neutral-900">Rezervime, dhoma dhe disponueshmëri</b><small class="mt-0.5 block text-neutral-500">Qëndrimi, dhoma, statusi, kontakti dhe bilanci operacional.</small></span></span>
                                <input v-model="form.reservations_enabled" type="checkbox" class="h-5 w-5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                            </label>
                            <label class="flex cursor-pointer items-center justify-between gap-4 px-5 py-4" :class="!aiModules.channel_manager && 'opacity-50'">
                                <span class="flex items-start gap-3"><span class="grid h-9 w-9 place-items-center rounded-lg bg-violet-50 text-violet-600"><MessageSquareText class="h-4 w-4" /></span><span><b class="block text-sm text-neutral-900">Bisedat me mysafirët</b><small class="mt-0.5 block text-neutral-500">Mesazhet e lidhura me rezervimin dhe kontekstin e qëndrimit.</small></span></span>
                                <input v-model="form.messages_enabled" type="checkbox" :disabled="!aiModules.channel_manager" class="h-5 w-5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                            </label>
                            <label class="flex cursor-pointer items-center justify-between gap-4 px-5 py-4" :class="!aiModules.smart_pricing && 'opacity-50'">
                                <span class="flex items-start gap-3"><span class="grid h-9 w-9 place-items-center rounded-lg bg-amber-50 text-amber-600"><Sparkles class="h-4 w-4" /></span><span><b class="block text-sm text-neutral-900">Kalendari dhe motori i çmimeve</b><small class="mt-0.5 block text-neutral-500">Çmimi aktual, sugjerimi, okupimi dhe faktorët për çdo datë.</small></span></span>
                                <input v-model="form.pricing_enabled" type="checkbox" :disabled="!aiModules.smart_pricing" class="h-5 w-5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                            </label>
                            <label class="flex cursor-pointer items-center justify-between gap-4 px-5 py-4" :class="!aiModules.finance && 'opacity-50'">
                                <span class="flex items-start gap-3"><span class="grid h-9 w-9 place-items-center rounded-lg bg-emerald-50 text-emerald-700"><WalletCards class="h-4 w-4" /></span><span><b class="block text-sm text-neutral-900">Financa</b><small class="mt-0.5 block text-neutral-500">Faturat e shitjes/blerjes, llogaritë dhe përmbledhjet operative; pa të dhëna karte.</small></span></span>
                                <input v-model="form.finance_enabled" type="checkbox" :disabled="!aiModules.finance || !form.universal_search_enabled" class="h-5 w-5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                            </label>
                            <label class="flex cursor-pointer items-center justify-between gap-4 px-5 py-4" :class="!aiModules.housekeeping && 'opacity-50'">
                                <span class="flex items-start gap-3"><span class="grid h-9 w-9 place-items-center rounded-lg bg-cyan-50 text-cyan-700"><SprayCan class="h-4 w-4" /></span><span><b class="block text-sm text-neutral-900">Housekeeping</b><small class="mt-0.5 block text-neutral-500">Detyrat, dhomat, prioriteti dhe statusi i pastrimit.</small></span></span>
                                <input v-model="form.housekeeping_enabled" type="checkbox" :disabled="!aiModules.housekeeping || !form.universal_search_enabled" class="h-5 w-5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                            </label>
                            <label class="flex cursor-pointer items-center justify-between gap-4 px-5 py-4">
                                <span class="flex items-start gap-3"><span class="grid h-9 w-9 place-items-center rounded-lg bg-orange-50 text-orange-700"><Wrench class="h-4 w-4" /></span><span><b class="block text-sm text-neutral-900">Mirëmbajtja</b><small class="mt-0.5 block text-neutral-500">Problemet, pajisjet, dhomat, prioriteti dhe statusi.</small></span></span>
                                <input v-model="form.maintenance_enabled" type="checkbox" :disabled="!form.universal_search_enabled" class="h-5 w-5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                            </label>
                            <label class="flex cursor-pointer items-center justify-between gap-4 px-5 py-4" :class="!aiModules.pos && 'opacity-50'">
                                <span class="flex items-start gap-3"><span class="grid h-9 w-9 place-items-center rounded-lg bg-rose-50 text-rose-700"><UtensilsCrossed class="h-4 w-4" /></span><span><b class="block text-sm text-neutral-900">POS Bar/Restorant</b><small class="mt-0.5 block text-neutral-500">Porositë, pagesa, statusi dhe lidhja me rezervimin.</small></span></span>
                                <input v-model="form.pos_enabled" type="checkbox" :disabled="!aiModules.pos || !form.universal_search_enabled" class="h-5 w-5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                            </label>
                            <label class="flex cursor-pointer items-center justify-between gap-4 px-5 py-4" :class="!aiModules.finance && 'opacity-50'">
                                <span class="flex items-start gap-3"><span class="grid h-9 w-9 place-items-center rounded-lg bg-indigo-50 text-indigo-700"><PackageSearch class="h-4 w-4" /></span><span><b class="block text-sm text-neutral-900">Inventari</b><small class="mt-0.5 block text-neutral-500">Produktet, SKU, kategori dhe gjendja aktuale e stokut.</small></span></span>
                                <input v-model="form.inventory_enabled" type="checkbox" :disabled="!aiModules.finance || !form.universal_search_enabled" class="h-5 w-5 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
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
                            <label class="rounded-xl border border-neutral-200 p-4 md:col-span-2" :class="!form.pricing_enabled && 'opacity-50'">
                                <span class="flex items-center justify-between gap-3"><span><b class="block text-sm text-neutral-900">Rekomandim alternativ nga ChatGPT</b><small class="mt-1 block text-neutral-500">Analizon okupimin, ritmin, eventet dhe tregun kur ekziston; nuk kopjon domosdoshmërisht motorin Lora.</small></span><input v-model="form.ai_price_recommendations_enabled" type="checkbox" :disabled="!form.pricing_enabled" class="h-5 w-5 rounded border-neutral-300 text-primary-600" /></span>
                                <span class="mt-2 block text-xs leading-5 text-neutral-500">Kufizohet nga guardrails, jep arsyen dhe besueshmërinë, pastaj krijon vetëm propozim për aprovim.</span>
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
            </div>
        </div>
    </AppLayout>
</template>
