<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Badge from '@/Components/UI/Badge.vue';
import { ArrowRight, FileBarChart } from 'lucide-vue-next';

defineProps({ currency: { type: String, default: '€' } });

// The full board-approved catalog. `to` = route name for built reports; null = coming soon.
const groups = [
    {
        name: 'Të ardhura & Prodhim',
        reports: [
            { name: 'Pasqyra Ekzekutive', desc: 'Të ardhura dhoma+bar, mbushja, ADR, RevPAR, TVSH, komisioni — gjithçka në një faqe.', to: 'reports.executive' },
            { name: 'ADR / RevPAR / Mbushja', desc: 'Tre treguesit mbretër me krahasim periudhe, sipas tipit të dhomës.', to: null },
            { name: 'Tempo & Pickup', desc: 'Sa netë/të ardhura janë rezervuar për ditët në vijim (7/14/30/60/90).', to: null },
        ],
    },
    {
        name: 'Kanalet',
        reports: [
            { name: 'Prodhimi sipas Kanaleve', desc: 'Nga vijnë rezervimet dhe sa kushton secili kanal në komision.', to: 'reports.channels' },
            { name: 'Anulime & No-Show', desc: 'Rrjedhja e rezervimeve: % anulimesh dhe no-show të mundshëm.', to: null },
        ],
    },
    {
        name: 'Klientët',
        reports: [
            { name: 'Direktoria e Mysafirëve', desc: 'Lista kryesore e çdo mysafiri me statistika gjatë jetës (CRM).', to: null },
            { name: 'Kthyes & Top sipas Vlerës', desc: 'Mysafirë që kanë qëndruar 2+ herë dhe top sipas shpenzimit.', to: null },
            { name: 'Përbërja sipas Kombësisë', desc: 'Nga vijnë mysafirët — numra, netë, të ardhura sipas vendit.', to: null },
            { name: 'Sjellja e Rezervimit', desc: 'Sa para rezervojnë dhe sa gjatë qëndrojnë, sipas kanalit.', to: null },
        ],
    },
    {
        name: 'Bar & Restorant',
        reports: [
            { name: 'Shitjet POS sipas Kategorisë & Artikullit', desc: 'Të ardhura Bar vs Restorant dhe sipas artikullit, me numër porosish.', to: null },
            { name: 'Shitjet sipas Orës & Ditës', desc: 'Oraret dhe ditët më të ngarkuara për F&B.', to: null },
            { name: 'Mix i Pagesave POS', desc: 'Si u paguan shitjet — kesh, kartë, folio.', to: null },
            { name: 'Anulime & Voids POS', desc: 'Porosi të anulluara me vlerë — kontroll humbjesh.', to: null },
        ],
    },
    {
        name: 'Operacione',
        reports: [
            { name: 'Manifesti i Mbërritjeve', desc: 'Fletë pune e çdo mysafiri që mbërrin në një periudhë.', to: null },
            { name: 'Manifesti i Nisjeve', desc: 'Çdo nisje me balancë të papaguar dhe porosi POS të hapura.', to: null },
            { name: 'Statusi i Dhomave', desc: 'Foto e çastit e çdo dhome dhe statusit të saj.', to: null },
            { name: 'Raporti i Pastrimit', desc: 'Ngarkesa dhe përfundimi i pastrimit sipas stafit/dhomës.', to: null },
            { name: 'Mysafirë në Shtëpi', desc: 'Lista e çdo mysafiri aktualisht brenda.', to: null },
        ],
    },
    {
        name: 'Financë & Arka',
        reports: [
            { name: 'Bilance të Papaguara', desc: 'Çdo qëndrim që ende ka borxh (folio − pagesa) — debitorët.', to: 'reports.outstanding' },
            { name: 'Z-Report / Mbyllje Turni', desc: 'Pajtimi i arkës për çdo turn: kesh/kartë/folio, pritur vs numëruar.', to: 'reports.shifts' },
            { name: 'Arkëtime & Cash', desc: 'Paratë e mbledhura (jo të faturuara) sipas metodës/ditës/stafit.', to: null },
            { name: 'Raport TVSH', desc: 'Ndarja periodike e TVSH-së për deklarim tatimor.', to: null },
            { name: 'Zbritje të Dhëna', desc: 'Çdo zbritje/falje e dhënë — sa të ardhura janë lëshuar.', to: null },
        ],
    },
];
</script>

<template>
    <AppLayout>
        <PageHeader title="Raporte" :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Raporte' }]" />

        <div class="mt-6 space-y-8">
            <section v-for="g in groups" :key="g.name">
                <h3 class="text-label text-neutral-600 uppercase tracking-wider mb-3">{{ g.name }}</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                    <component
                        :is="r.to ? Link : 'div'"
                        v-for="r in g.reports"
                        :key="r.name"
                        :href="r.to ? route(r.to) : null"
                        class="no-underline"
                    >
                        <Card :class="r.to ? 'h-full transition-shadow hover:shadow-md cursor-pointer' : 'h-full opacity-60'">
                            <div class="flex items-start gap-3">
                                <div class="h-9 w-9 rounded-lg flex items-center justify-center shrink-0" :class="r.to ? 'bg-accent-50' : 'bg-neutral-100'">
                                    <FileBarChart class="h-4.5 w-4.5" :class="r.to ? 'text-accent-600' : 'text-neutral-400'" :stroke-width="1.75" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-body-sm font-medium text-primary-900">{{ r.name }}</p>
                                        <Badge v-if="!r.to" variant="neutral" size="sm">Së shpejti</Badge>
                                        <ArrowRight v-else class="h-4 w-4 text-neutral-300 shrink-0" />
                                    </div>
                                    <p class="text-tiny text-neutral-500 mt-1 leading-relaxed">{{ r.desc }}</p>
                                </div>
                            </div>
                        </Card>
                    </component>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
