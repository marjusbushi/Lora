<script setup>
import { ref, computed } from 'vue';
import { router, usePage, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Card from '@/Components/UI/Card.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import Modal from '@/Components/UI/Modal.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import DatePicker from '@/Components/UI/DatePicker.vue';
import Select from '@/Components/UI/Select.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';
import { channelMeta } from '@/channels';

const props = defineProps({
    reservation: Object,
    folio: Object,
    payments: Array,
    openPosOrders: Array,
    currency: { type: String, default: '€' },
});

const toasts = ref(null);
const checkingOut = ref(false);
const showLineModal = ref(false);
const showPayModal = ref(false);
const showInvoice = ref(false);
const checkoutMode = ref(false);

const perms = usePage().props.auth.user?.permissions || [];
const canUpdate = perms.includes('update_reservations');

// Front desk asks housekeeping for a daily (stayover) clean while the guest is in-house.
const requestingCleaning = ref(false);
function requestCleaning() {
    requestingCleaning.value = true;
    router.post(route('reservations.request-cleaning', props.reservation.id), {}, {
        preserveScroll: true,
        onSuccess: (page) => {
            const flash = page.props?.flash || {};
            if (flash.error) toasts.value?.error(flash.error);
            else toasts.value?.success(flash.success || 'Pastrimi ditor u kerkua.');
        },
        onFinish: () => (requestingCleaning.value = false),
    });
}

const statusBadge = {
    pending: { variant: 'warning', label: 'Ne pritje' },
    confirmed: { variant: 'info', label: 'Konfirmuar' },
    checked_in: { variant: 'success', label: 'Brenda' },
    checked_out: { variant: 'neutral', label: 'Larguar' },
    cancelled: { variant: 'error', label: 'Anulluar' },
};

const typeLabel = {
    room: 'Dhoma', restaurant: 'Restorant', bar: 'Bar',
    minibar: 'Minibar', extra: 'Ekstra', tax: 'Taksa', discount: 'Zbritje',
};
const methodLabel = { cash: 'Kesh', card: 'Karte' };

const lineTypeOptions = [
    { value: 'restaurant', label: 'Restorant' },
    { value: 'bar', label: 'Bar' },
    { value: 'minibar', label: 'Minibar' },
    { value: 'extra', label: 'Ekstra' },
    { value: 'discount', label: 'Zbritje' },
];
const methodOptions = [
    { value: 'cash', label: 'Kesh' },
    { value: 'card', label: 'Karte' },
];

const hasOpenOrders = computed(() => (props.openPosOrders?.length || 0) > 0);
const unsettled = computed(() => Number(props.folio.outstanding) > 0.005);
const hotelName = usePage().props.settings?.hotel_name || 'Hotel';

// Group folio charges by category for the invoice (room + bar + restaurant + ...).
const invoiceGroups = computed(() => {
    const g = { room: Number(props.folio.roomCharge) || 0, bar: 0, restaurant: 0, minibar: 0, extra: 0, discount: 0 };
    for (const it of (props.folio.items || [])) g[it.type] = (g[it.type] || 0) + Number(it.amount);
    return ['room', 'bar', 'restaurant', 'minibar', 'extra', 'discount']
        .filter((k) => g[k] > 0)
        .map((k) => ({ key: k, label: typeLabel[k], amount: g[k] }));
});

function printInvoice() {
    window.print();
}

const lineForm = useForm({ type: 'extra', description: '', amount: '', charge_date: '' });
const payForm = useForm({ amount: '', method: 'cash' });

function money(v) {
    return `${props.currency}${Number(v ?? 0).toFixed(2)}`;
}
function formatDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('sq-AL', { day: '2-digit', month: 'short', year: 'numeric' });
}

function submitLine() {
    lineForm.post(route('reservations.folio.add', props.reservation.id), {
        preserveScroll: true,
        onSuccess: () => { showLineModal.value = false; lineForm.reset(); toasts.value?.success('Rreshti u shtua.'); },
        onError: () => toasts.value?.error('Deshtoi shtimi i rreshtit.'),
    });
}
function submitPay() {
    payForm.post(route('reservations.payment', props.reservation.id), {
        preserveScroll: true,
        onSuccess: () => { showPayModal.value = false; payForm.reset(); toasts.value?.success('Pagesa u regjistrua.'); },
        onError: () => toasts.value?.error('Deshtoi regjistrimi i pageses.'),
    });
}
// "Faturë" just views/prints the bill. "Check-out" opens the SAME invoice in checkout mode,
// where you settle the outstanding (cash/card) and only THEN does the guest leave.
function openInvoice() {
    checkoutMode.value = false;
    showInvoice.value = true;
}
function openCheckout() {
    if (hasOpenOrders.value) { toasts.value?.error('Mbyll porosite POS te hapura perpara check-out.'); return; }
    checkoutMode.value = true;
    showInvoice.value = true;
}
function settleAndCheckout(method) {
    if (hasOpenOrders.value) { toasts.value?.error('Mbyll porosite POS te hapura perpara check-out.'); return; }
    checkingOut.value = true;
    router.post(
        route('reservations.check-out', props.reservation.id),
        method ? { settle_method: method } : {},
        {
            preserveScroll: true,
            onSuccess: () => {
                showInvoice.value = false;
                checkoutMode.value = false;
                toasts.value?.success(
                    method
                        ? `Pagesa u regjistrua (${methodLabel[method]}) dhe check-out u krye.`
                        : 'Check-out u krye.'
                );
            },
            onError: (errors) => toasts.value?.error(errors.settle_method || 'Check-out deshtoi.'),
            onFinish: () => { checkingOut.value = false; },
        }
    );
}
</script>

<template>
    <AppLayout>
        <PageHeader
            :title="`Rezervimi #${reservation.id}`"
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Rezervimet', href: route('reservations.index') }, { label: `#${reservation.id}` }]"
        >
            <template #actions>
                <Badge :variant="statusBadge[reservation.status]?.variant" dot>
                    {{ statusBadge[reservation.status]?.label }}
                </Badge>
                <Button v-if="canUpdate" variant="outline" @click="showLineModal = true">+ Rresht</Button>
                <Button v-if="canUpdate && reservation.status !== 'cancelled' && unsettled" variant="success" @click="showPayModal = true">Paguaj</Button>
                <Button variant="outline" @click="openInvoice">Fature</Button>
                <Button
                    v-if="canUpdate && reservation.status === 'checked_in'"
                    variant="outline"
                    :loading="requestingCleaning"
                    @click="requestCleaning"
                >
                    Kërko pastrim
                </Button>
                <Button
                    v-if="canUpdate && reservation.status === 'checked_in'"
                    variant="primary"
                    :disabled="hasOpenOrders"
                    @click="openCheckout"
                >
                    Check-out
                </Button>
            </template>
        </PageHeader>

        <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Reservation details -->
            <Card class="lg:col-span-1">
                <h3 class="text-label text-neutral-600 uppercase tracking-wider mb-4">Detajet</h3>
                <dl class="space-y-3">
                    <div class="flex justify-between">
                        <dt class="text-body-sm text-neutral-500">Mysafiri</dt>
                        <dd class="text-body-sm text-primary-900 font-medium text-right">{{ reservation.guest?.name }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-body-sm text-neutral-500">Email</dt>
                        <dd class="text-body-sm text-neutral-700 text-right">{{ reservation.guest?.email || '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-body-sm text-neutral-500">Telefon</dt>
                        <dd class="text-body-sm text-neutral-700 text-right">{{ reservation.guest?.phone || '—' }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-neutral-100 pt-3">
                        <dt class="text-body-sm text-neutral-500">Dhoma</dt>
                        <dd class="text-body-sm text-primary-900 text-right">{{ reservation.room?.room_number }} — {{ reservation.room?.room_type }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-body-sm text-neutral-500">Check-in</dt>
                        <dd class="text-body-sm text-neutral-700 text-right">{{ formatDate(reservation.check_in_date) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-body-sm text-neutral-500">Check-out</dt>
                        <dd class="text-body-sm text-neutral-700 text-right">{{ formatDate(reservation.check_out_date) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-body-sm text-neutral-500">Nete</dt>
                        <dd class="text-body-sm text-neutral-700 text-right">{{ reservation.nights }} ({{ reservation.adults }} te rritur<span v-if="reservation.children">, {{ reservation.children }} femije</span>)</dd>
                    </div>
                    <div class="flex justify-between items-center">
                        <dt class="text-body-sm text-neutral-500">Burimi</dt>
                        <dd class="text-body-sm text-right">
                            <span class="inline-flex items-center gap-1.5 rounded-md bg-neutral-100 px-2 py-0.5 text-tiny font-medium uppercase tracking-wide text-neutral-700 ring-1 ring-neutral-200/60">
                                <span class="h-1.5 w-1.5 rounded-full" :style="{ backgroundColor: channelMeta(reservation.channel).color }" />
                                {{ channelMeta(reservation.channel).label }}
                            </span>
                            <span v-if="reservation.channel_ref" class="ml-2 text-tiny text-neutral-400">#{{ reservation.channel_ref }}</span>
                        </dd>
                    </div>
                    <div v-if="reservation.payment_collect" class="flex justify-between items-center">
                        <dt class="text-body-sm text-neutral-500">Pagesa</dt>
                        <dd class="text-body-sm text-right">
                            <Badge v-if="reservation.payment_collect === 'ota'" variant="success">
                                ✓ Paguar online ({{ channelMeta(reservation.channel).label }})
                            </Badge>
                            <Badge v-else variant="warning">Paguhet në hotel</Badge>
                        </dd>
                    </div>
                    <div v-if="reservation.notes" class="border-t border-neutral-100 pt-3">
                        <dt class="text-body-sm text-neutral-500 mb-1">Shenime</dt>
                        <dd class="text-body-sm text-neutral-700">{{ reservation.notes }}</dd>
                    </div>
                </dl>
            </Card>

            <!-- Folio -->
            <Card class="lg:col-span-2" :padding="false">
                <div class="px-5 py-4 border-b border-neutral-200">
                    <h3 class="text-label text-neutral-600 uppercase tracking-wider">Folio — Llogaria e mysafirit</h3>
                </div>

                <!-- Open POS warning -->
                <div v-if="hasOpenOrders" class="mx-5 mt-4 rounded-lg bg-warning-50 border border-warning-200 px-4 py-3">
                    <p class="text-body-sm text-warning-800 font-medium">
                        {{ openPosOrders.length }} porosi POS te hapura — duhen mbyllur perpara check-out.
                    </p>
                    <ul class="mt-1 text-small text-warning-700">
                        <li v-for="o in openPosOrders" :key="o.id">
                            Porosia #{{ o.id }}<span v-if="o.table_number"> (Tavolina {{ o.table_number }})</span> — {{ money(o.total_amount) }}
                        </li>
                    </ul>
                </div>

                <table class="min-w-full divide-y divide-neutral-200 mt-2">
                    <thead class="bg-neutral-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Pershkrimi</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Lloji</th>
                            <th class="px-5 py-3 text-left text-label text-neutral-600">Data</th>
                            <th class="px-5 py-3 text-right text-label text-neutral-600">Shuma</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr>
                            <td class="px-5 py-3 text-body-sm text-primary-900 font-medium">Qendrimi ne dhome</td>
                            <td class="px-5 py-3"><Badge variant="info">{{ typeLabel.room }}</Badge></td>
                            <td class="px-5 py-3 text-body-sm text-neutral-500">{{ formatDate(reservation.check_in_date) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm text-primary-900">{{ money(folio.roomCharge) }}</td>
                        </tr>
                        <tr v-for="item in folio.items" :key="item.id">
                            <td class="px-5 py-3 text-body-sm text-neutral-700">{{ item.description }}</td>
                            <td class="px-5 py-3"><Badge :variant="item.type === 'discount' ? 'success' : 'neutral'">{{ typeLabel[item.type] || item.type }}</Badge></td>
                            <td class="px-5 py-3 text-body-sm text-neutral-500">{{ formatDate(item.charge_date) }}</td>
                            <td class="px-5 py-3 text-right text-body-sm" :class="item.type === 'discount' ? 'text-success-600' : 'text-neutral-700'">
                                {{ item.type === 'discount' ? '−' : '' }}{{ money(item.amount) }}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Payments -->
                <div v-if="payments.length" class="border-t border-neutral-200">
                    <p class="px-5 pt-3 text-label text-neutral-500 uppercase tracking-wider">Pagesat</p>
                    <ul class="px-5 py-2 space-y-1">
                        <li v-for="p in payments" :key="p.id" class="flex justify-between text-body-sm">
                            <span class="text-neutral-600">{{ methodLabel[p.method] || p.method }} · {{ formatDate(p.date) }}</span>
                            <span class="text-success-600">− {{ money(p.amount) }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Summary -->
                <div class="border-t border-neutral-200 px-5 py-4 space-y-2">
                    <div class="flex justify-between text-body-sm text-neutral-500">
                        <span>Nentotali (pa TVSH)</span>
                        <span>{{ money(folio.net) }}</span>
                    </div>
                    <div class="flex justify-between text-body-sm text-neutral-500">
                        <span>TVSH ({{ folio.taxRate }}%)</span>
                        <span>{{ money(folio.taxAmount) }}</span>
                    </div>
                    <div v-if="folio.discounts > 0" class="flex justify-between text-body-sm text-success-600">
                        <span>Zbritje</span>
                        <span>− {{ money(folio.discounts) }}</span>
                    </div>
                    <div class="flex justify-between text-body-sm text-neutral-700 border-t border-neutral-100 pt-2">
                        <span>Total</span>
                        <span>{{ money(folio.gross) }}</span>
                    </div>
                    <div class="flex justify-between text-body-sm text-neutral-500">
                        <span>Paguar</span>
                        <span>− {{ money(folio.paid) }}</span>
                    </div>
                    <div v-if="reservation.status !== 'cancelled'" class="flex justify-between border-t border-neutral-200 pt-2">
                        <span class="text-label text-neutral-700">Mbetur per t'u paguar</span>
                        <span class="text-h4" :class="unsettled ? 'text-error-600' : 'text-success-600'">{{ money(folio.outstanding) }}</span>
                    </div>
                    <div v-else class="flex justify-between border-t border-neutral-200 pt-2">
                        <span class="text-label text-neutral-700">Rezervimi</span>
                        <span class="text-h4 text-neutral-400">Anulluar</span>
                    </div>
                </div>
            </Card>
        </div>

        <!-- Add folio line modal -->
        <Modal :show="showLineModal" title="Shto rresht ne folio" max-width="md" @close="showLineModal = false">
            <form @submit.prevent="submitLine" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <FormGroup label="Lloji" :error="lineForm.errors.type" required>
                        <Select v-model="lineForm.type" :options="lineTypeOptions" :error="lineForm.errors.type" />
                    </FormGroup>
                    <FormGroup label="Shuma" :error="lineForm.errors.amount" required>
                        <TextInput type="number" step="0.01" min="0.01" v-model="lineForm.amount" placeholder="0.00" :error="lineForm.errors.amount" />
                    </FormGroup>
                </div>
                <FormGroup label="Pershkrimi" :error="lineForm.errors.description" required>
                    <TextInput v-model="lineForm.description" placeholder="P.sh. Minibar, transferim..." :error="lineForm.errors.description" />
                </FormGroup>
                <FormGroup label="Data (opsionale)" :error="lineForm.errors.charge_date">
                    <DatePicker v-model="lineForm.charge_date" :error="lineForm.errors.charge_date" />
                </FormGroup>
            </form>
            <template #footer>
                <Button variant="outline" @click="showLineModal = false">Anulo</Button>
                <Button variant="primary" :loading="lineForm.processing" @click="submitLine">Shto</Button>
            </template>
        </Modal>

        <!-- Record payment modal -->
        <Modal :show="showPayModal" title="Regjistro pagese" max-width="sm" @close="showPayModal = false">
            <form @submit.prevent="submitPay" class="space-y-4">
                <FormGroup label="Shuma" :error="payForm.errors.amount" required>
                    <TextInput type="number" step="0.01" min="0.01" v-model="payForm.amount" placeholder="0.00" :error="payForm.errors.amount" />
                </FormGroup>
                <FormGroup label="Menyra" :error="payForm.errors.method" required>
                    <Select v-model="payForm.method" :options="methodOptions" :error="payForm.errors.method" />
                </FormGroup>
            </form>
            <template #footer>
                <Button variant="outline" @click="showPayModal = false">Anulo</Button>
                <Button variant="primary" :loading="payForm.processing" @click="submitPay">Regjistro</Button>
            </template>
        </Modal>

        <!-- Invoice (Fature) modal — also the settle-then-checkout flow -->
        <Modal :show="showInvoice" :title="checkoutMode ? 'Mbyll llogarine & Check-out' : 'Fature'" max-width="lg" @close="showInvoice = false">
            <div id="invoice" class="space-y-4 text-primary-900">
                <div class="text-center border-b border-neutral-200 pb-3">
                    <p class="text-h3">{{ hotelName }}</p>
                    <p class="text-body-sm text-neutral-500">Fature — Rezervimi #{{ reservation.id }}</p>
                </div>

                <div class="flex justify-between text-body-sm">
                    <div>
                        <p class="text-neutral-500">Mysafiri</p>
                        <p class="font-medium">{{ reservation.guest?.name }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-neutral-500">Dhoma {{ reservation.room?.room_number }} — {{ reservation.room?.room_type }}</p>
                        <p>{{ formatDate(reservation.check_in_date) }} → {{ formatDate(reservation.check_out_date) }} · {{ reservation.nights }} net</p>
                    </div>
                </div>

                <table class="w-full text-body-sm">
                    <tbody>
                        <tr v-for="g in invoiceGroups" :key="g.key" class="border-b border-neutral-100">
                            <td class="py-2">{{ g.label }}</td>
                            <td class="py-2 text-right" :class="g.key === 'discount' ? 'text-success-600' : ''">{{ g.key === 'discount' ? '−' : '' }}{{ money(g.amount) }}</td>
                        </tr>
                    </tbody>
                </table>

                <div class="space-y-1.5 text-body-sm border-t border-neutral-200 pt-3">
                    <div class="flex justify-between text-neutral-500"><span>Nentotali (pa TVSH)</span><span>{{ money(folio.net) }}</span></div>
                    <div class="flex justify-between text-neutral-500"><span>TVSH ({{ folio.taxRate }}%)</span><span>{{ money(folio.taxAmount) }}</span></div>
                    <div class="flex justify-between font-medium border-t border-neutral-100 pt-1.5"><span>Total</span><span>{{ money(folio.gross) }}</span></div>
                    <div class="flex justify-between text-neutral-500"><span>Paguar</span><span>− {{ money(folio.paid) }}</span></div>
                    <div class="flex justify-between border-t border-neutral-200 pt-2">
                        <span class="font-semibold">Mbetur per t'u paguar</span>
                        <span class="text-h4" :class="unsettled ? 'text-error-600' : 'text-success-600'">{{ money(folio.outstanding) }}</span>
                    </div>
                </div>

                <p class="text-tiny text-neutral-400 text-center pt-2">Faleminderit per qendrimin!</p>
            </div>

            <!-- Checkout call-to-action (not part of the printed invoice) -->
            <div v-if="checkoutMode" class="mt-4 rounded-lg border px-4 py-3 print:hidden"
                 :class="hasOpenOrders ? 'border-warning-200 bg-warning-50' : 'border-primary-200 bg-primary-50'">
                <p v-if="hasOpenOrders" class="text-body-sm text-warning-800 font-medium">
                    Ka porosi POS te hapura — mbyllini perpara se te mbyllni llogarine.
                </p>
                <template v-else>
                    <p class="text-body-sm text-primary-800 font-medium mb-0.5">Mbyllja e llogarise</p>
                    <p v-if="unsettled" class="text-small text-neutral-600">
                        Zgjidh menyren e pageses per te shlyer <b>{{ money(folio.outstanding) }}</b> dhe per te bere check-out.
                    </p>
                    <p v-else class="text-small text-success-700">
                        Llogaria eshte e shlyer plotesisht. Konfirmo check-out.
                    </p>
                </template>
            </div>

            <template #footer>
                <template v-if="checkoutMode">
                    <Button variant="outline" @click="showInvoice = false">Anulo</Button>
                    <Button variant="outline" @click="printInvoice">Printo</Button>
                    <template v-if="!hasOpenOrders">
                        <template v-if="unsettled">
                            <Button variant="outline" :loading="checkingOut" @click="settleAndCheckout('cash')">Paguaj Kesh & Check-out</Button>
                            <Button variant="primary" :loading="checkingOut" @click="settleAndCheckout('card')">Paguaj Karte & Check-out</Button>
                        </template>
                        <Button v-else variant="primary" :loading="checkingOut" @click="settleAndCheckout(null)">Konfirmo Check-out</Button>
                    </template>
                </template>
                <template v-else>
                    <Button variant="outline" @click="showInvoice = false">Mbyll</Button>
                    <Button variant="primary" @click="printInvoice">Printo</Button>
                </template>
            </template>
        </Modal>

        <ToastContainer ref="toasts" />
    </AppLayout>
</template>

<style>
@media print {
    body * { visibility: hidden !important; }
    #invoice, #invoice * { visibility: visible !important; }
    #invoice { position: absolute; left: 0; top: 0; width: 100%; padding: 24px; }
}
</style>
