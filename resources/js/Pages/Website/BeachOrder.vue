<script setup>
import { computed, reactive } from 'vue';
import { useForm } from '@inertiajs/vue3';
import WebsiteLayout from '@/Layouts/WebsiteLayout.vue';
import { Minus, Plus, ShoppingBasket } from 'lucide-vue-next';

// Guest ordering from the sunbed: mobile-first, no login, no app — the QR
// carries the unit; the cart lives client-side until one submit.
const props = defineProps({
    unit: { type: Object, required: true },
    qrToken: { type: String, required: true },
    outletName: { type: String, default: '' },
    menu: { type: Array, default: () => [] },
    currency: { type: String, default: '€' },
    reserveUrl: { type: String, default: '' },
});

const cart = reactive({});

function add(item) {
    if (cart[item.id]) cart[item.id].qty = Math.min(20, cart[item.id].qty + 1);
    else cart[item.id] = { item, qty: 1 };
}

function remove(item) {
    if (!cart[item.id]) return;
    cart[item.id].qty -= 1;
    if (cart[item.id].qty <= 0) delete cart[item.id];
}

const lines = computed(() => Object.values(cart));
const count = computed(() => lines.value.reduce((sum, line) => sum + line.qty, 0));
const total = computed(() => lines.value.reduce((sum, line) => sum + line.qty * Number(line.item.price), 0));
const money = (value) => `${props.currency}${Number(value).toFixed(2)}`;

// Same fallback heuristic as the staff till (Pos/Index.vue getItemEmoji) —
// items without a photo still get a face the guest recognises.
function itemEmoji(item) {
    const name = item.name?.toLowerCase() || '';
    if (name.includes('espresso') || name.includes('cappuccino') || name.includes('kafe')) return '☕';
    if (name.includes('caj')) return '🍵';
    if (name.includes('leng') || name.includes('portokall')) return '🍊';
    if (name.includes('bire') || name.includes('birr')) return '🍺';
    if (name.includes('vere')) return '🍷';
    if (name.includes('uje')) return '💧';
    if (name.includes('koktej') || name.includes('mojito') || name.includes('aperol')) return '🍹';
    if (name.includes('sandvic') || name.includes('burger')) return '🍔';
    if (name.includes('salat')) return '🥗';
    if (name.includes('pasta') || name.includes('carbonara')) return '🍝';
    if (name.includes('pizza')) return '🍕';
    if (name.includes('tiramisu')) return '🍫';
    if (name.includes('akullore')) return '🍨';
    if (name.includes('panna')) return '🍮';
    return '🍽️';
}

const form = useForm({ items: [] });

function submit() {
    if (!count.value || form.processing) return;
    form.transform(() => ({
        items: lines.value.map((line) => ({ menu_item_id: line.item.id, quantity: line.qty })),
    })).post(route('website.beach.order.submit', props.qrToken), { preserveScroll: true });
}

const submitError = computed(() => form.errors.order || form.errors.inventory || form.errors.items || Object.values(form.errors)[0]);
</script>

<template>
    <WebsiteLayout>
        <section class="bg-bone min-h-screen pb-36">
            <!-- Hero i çadrës -->
            <div class="bg-gradient-to-br from-ionian to-ionian/80 px-4 pb-10 pt-8 text-bone">
                <div class="mx-auto max-w-lg">
                    <div class="flex items-center gap-4">
                        <span class="grid h-16 w-16 shrink-0 place-items-center rounded-2xl bg-bone/15 text-4xl backdrop-blur-sm">⛱️</span>
                        <div>
                            <h1 class="text-3xl font-semibold leading-tight">{{ $t('beach.order.unitBadge', { number: unit.number }) }}</h1>
                            <p class="text-body-sm text-bone/80">{{ unit.zone_name }} · {{ outletName }}</p>
                        </div>
                    </div>
                    <p class="mt-4 text-body-sm text-bone/90">{{ $t('beach.order.subtitle', { outlet: outletName }) }}</p>
                    <a v-if="reserveUrl" :href="reserveUrl" class="mt-1 inline-block text-body-sm font-medium text-bone underline decoration-bone/40 underline-offset-4">
                        {{ $t('beach.order.reserveLink') }}
                    </a>
                </div>
            </div>

            <div class="mx-auto mt-6 max-w-lg px-4 sm:px-6">
                <div v-if="menu.length" class="space-y-7">
                    <div v-for="category in menu" :key="category.id">
                        <h2 class="mb-3 flex items-center gap-2 text-label font-semibold uppercase tracking-wide text-driftwood">
                            <span class="h-px w-5 bg-driftwood/30" /> {{ category.name }}
                        </h2>
                        <div class="grid grid-cols-2 gap-3">
                            <div v-for="item in category.items" :key="item.id" class="overflow-hidden rounded-2xl border border-driftwood/10 bg-white shadow-sm">
                                <!-- Fotoja ose emoji i madh -->
                                <div class="relative aspect-square w-full">
                                    <img v-if="item.image_path" :src="`/storage/${item.image_path}`" :alt="item.name" class="h-full w-full object-cover">
                                    <div v-else class="grid h-full w-full place-items-center bg-gradient-to-br from-bone to-ionian/10 text-6xl">
                                        {{ itemEmoji(item) }}
                                    </div>
                                    <span v-if="cart[item.id]" class="absolute left-2 top-2 grid h-7 min-w-7 place-items-center rounded-full bg-ionian px-2 text-body-sm font-bold text-bone shadow">
                                        {{ cart[item.id].qty }}
                                    </span>
                                    <!-- Kontrolli i shportës mbi foto -->
                                    <div class="absolute bottom-2 right-2">
                                        <div v-if="cart[item.id]" class="flex items-center gap-1 rounded-full bg-white/95 p-1 shadow-md backdrop-blur">
                                            <button type="button" class="grid h-9 w-9 place-items-center rounded-full text-ink active:bg-bone" :aria-label="$t('beach.order.removeOne', { name: item.name })" @click="remove(item)"><Minus class="h-4 w-4" /></button>
                                            <button type="button" class="grid h-9 w-9 place-items-center rounded-full bg-ionian text-bone active:opacity-80" :aria-label="$t('beach.order.addOne', { name: item.name })" @click="add(item)"><Plus class="h-4 w-4" /></button>
                                        </div>
                                        <button v-else type="button" class="grid h-10 w-10 place-items-center rounded-full bg-ionian text-bone shadow-md active:opacity-80" :aria-label="$t('beach.order.addOne', { name: item.name })" @click="add(item)">
                                            <Plus class="h-5 w-5" />
                                        </button>
                                    </div>
                                </div>
                                <div class="px-3 py-2.5">
                                    <p class="truncate text-body-sm font-medium text-ink">{{ item.name }}</p>
                                    <p class="text-body-sm font-semibold text-ionian">{{ money(item.price) }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="mt-10 rounded-2xl border border-dashed border-driftwood/30 bg-white px-4 py-12 text-center">
                    <p class="text-4xl">🏖️</p>
                    <p class="mt-2 text-body-sm text-driftwood">{{ $t('beach.order.emptyMenu') }}</p>
                </div>

                <p v-if="submitError" class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-body-sm text-red-700">{{ submitError }}</p>
            </div>

            <!-- Shporta sticky -->
            <div v-if="count" class="fixed inset-x-0 bottom-0 z-20 rounded-t-3xl border-t border-driftwood/10 bg-white px-4 pb-4 pt-3 shadow-[0_-8px_30px_rgba(0,0,0,0.08)]">
                <div class="mx-auto flex max-w-lg items-center justify-between gap-3">
                    <div>
                        <p class="text-tiny uppercase tracking-wide text-driftwood">{{ $t('beach.order.totalLabel') }}</p>
                        <p class="text-xl font-semibold tabular-nums text-ink">{{ money(total) }}</p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-13 items-center gap-2 rounded-full bg-ionian px-7 py-3.5 text-body-sm font-semibold text-bone shadow-lg active:opacity-80 disabled:opacity-50"
                        :disabled="form.processing"
                        @click="submit"
                    >
                        <ShoppingBasket class="h-4 w-4" />
                        {{ form.processing ? $t('beach.order.sending') : $t('beach.order.submit', { count }) }}
                    </button>
                </div>
                <p class="mx-auto mt-1.5 max-w-lg text-center text-tiny text-driftwood">🛎️ {{ $t('beach.order.payOnDelivery') }}</p>
            </div>
        </section>
    </WebsiteLayout>
</template>
