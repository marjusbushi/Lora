<script setup>
import { getIntlLocale, translate } from '@/i18n';
import { computed } from 'vue';
import QrCode from '@/Components/Invoices/QrCode.vue';

const props = defineProps({
    reservation: { type: Object, required: true },
    folio: { type: Object, required: true },
    payments: { type: Array, default: () => [] },
    meta: { type: Object, default: () => ({}) },
    fiscalDocument: { type: Object, default: null },
});

const currency = computed(() => props.fiscalDocument?.currency || props.meta.currency || 'EUR');
const exchangeRate = computed(() => Number(props.fiscalDocument?.exchange_rate ?? props.meta.exchange_rate ?? 0));
const payload = computed(() => props.fiscalDocument?.invoice_payload || null);

const lines = computed(() => {
    if (Array.isArray(payload.value?.lines) && payload.value.lines.length) {
        return payload.value.lines.map((line) => ({
            description: line.product_name,
            quantity: Number(line.quantity || 0),
            price: Number(line.price || 0),
            vat: Number(line.vat || 0),
            total: Number(line.total || 0),
        }));
    }

    const fallback = [];
    if (Number(props.folio.roomCharge || 0) > 0) {
        fallback.push({
            description: translate('invoicePrint.accommodationLine', {
                room: props.reservation.room?.room_number || '—',
                nights: props.reservation.nights || 1,
            }),
            quantity: 1,
            price: Number(props.folio.roomCharge),
            vat: Number(props.folio.taxRate || 0),
            total: Number(props.folio.roomCharge),
        });
    }
    for (const item of (props.folio.items || []).filter((item) => item.type !== 'discount')) {
        fallback.push({
            description: item.description,
            quantity: 1,
            price: Number(item.amount || 0),
            vat: Number(item.vat_rate ?? props.folio.taxRate ?? 0),
            total: Number(item.amount || 0),
        });
    }
    return fallback;
});

const subtotal = computed(() => lines.value.reduce((sum, line) => sum + line.total, 0));
const discount = computed(() => Number(payload.value?.invoice_discount_value ?? props.folio.discounts ?? 0));
const total = computed(() => Number(props.fiscalDocument?.total ?? props.folio.gross ?? subtotal.value - discount.value));
const discountFactor = computed(() => subtotal.value > 0 ? Math.max(0, total.value / subtotal.value) : 1);
const vatSummary = computed(() => {
    const grouped = new Map();
    for (const line of lines.value) {
        const rate = Number(line.vat || 0);
        const gross = line.total * discountFactor.value;
        grouped.set(rate, (grouped.get(rate) || 0) + gross);
    }
    return [...grouped.entries()].sort(([a], [b]) => a - b).map(([rate, gross]) => {
        const net = rate > 0 ? gross / (1 + rate / 100) : gross;
        return { rate, net, tax: gross - net, gross };
    });
});
const netTotal = computed(() => vatSummary.value.reduce((sum, row) => sum + row.net, 0));
const taxTotal = computed(() => vatSummary.value.reduce((sum, row) => sum + row.tax, 0));
const allTotal = computed(() => currency.value === 'ALL' ? total.value : total.value * exchangeRate.value);
const isFiscalized = computed(() => props.fiscalDocument?.status === 'fiscalized');

function number(value) {
    return Number(value || 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function date(value) {
    if (!value) return '—';
    return new Date(value).toLocaleDateString(getIntlLocale(), { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function dateTime(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString(getIntlLocale(), {
        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}

const paymentLabel = computed(() => {
    const method = props.fiscalDocument?.payment_method;
    if (method === 'BANKNOTE') return translate('invoicePrint.cash');
    if (method === 'CARD') return translate('invoicePrint.card');
    const methods = [...new Set(props.payments.map((payment) => payment.method))];
    return methods.length === 1 ? ({
        cash: translate('invoicePrint.cash'),
        card: translate('invoicePrint.card'),
    }[methods[0]] || methods[0]) : translate('invoicePrint.mixedPayment');
});
</script>

<template>
    <section id="hotel-invoice" class="hotel-invoice">
        <div class="invoice-accent" />
        <header class="invoice-header">
            <div>
                <p class="invoice-kicker">{{ isFiscalized ? $t('invoicePrint.fiscalInvoice') : $t('invoicePrint.invoice') }}</p>
                <h1>{{ meta.hotel_name || 'Hotel' }}</h1>
                <p v-if="meta.legal_name && meta.legal_name !== meta.hotel_name">{{ meta.legal_name }}</p>
                <p v-if="meta.address">{{ meta.address }}</p>
                <p><template v-if="meta.nipt">{{ $t('invoicePrint.nipt') }}: {{ meta.nipt }}</template><template v-if="meta.phone"> · {{ meta.phone }}</template></p>
                <p v-if="meta.email">{{ meta.email }}</p>
            </div>
            <div class="invoice-number">
                <span>{{ $t('invoicePrint.invoiceNumber') }}</span>
                <strong>{{ fiscalDocument?.fiscal_number || `HOTEL-${reservation.id}` }}</strong>
                <span>{{ dateTime(fiscalDocument?.fiscalized_at || new Date()) }}</span>
            </div>
        </header>

        <section class="invoice-info-grid">
            <div>
                <span class="info-label">{{ $t('invoicePrint.client') }}</span>
                <strong>{{ reservation.guest?.name || $t('invoicePrint.hotelClient') }}</strong>
                <p v-if="reservation.guest?.email">{{ reservation.guest.email }}</p>
                <p v-if="reservation.guest?.phone">{{ reservation.guest.phone }}</p>
                <p v-if="payload?.client?.id?.id">{{ payload.client.id.type }}: {{ payload.client.id.id }}</p>
            </div>
            <div>
                <span class="info-label">{{ $t('invoicePrint.stay') }}</span>
                <strong>{{ $t('invoicePrint.room') }} {{ reservation.room?.room_number }} · {{ reservation.room?.room_type }}</strong>
                <p>{{ date(reservation.check_in_date) }} - {{ date(reservation.check_out_date) }}</p>
                <p>{{ $t('invoicePrint.stayReference', { nights: reservation.nights, id: reservation.id }) }}</p>
            </div>
            <div>
                <span class="info-label">{{ $t('invoicePrint.payment') }}</span>
                <strong>{{ paymentLabel }}</strong>
                <p>{{ $t('invoicePrint.currency') }}: {{ currency }}</p>
                <p v-if="currency !== 'ALL' && exchangeRate">{{ $t('invoicePrint.exchangeRate') }}: 1 {{ currency }} = {{ exchangeRate.toFixed(4) }} ALL</p>
            </div>
        </section>

        <table class="invoice-lines">
            <thead>
                <tr><th>{{ $t('invoicePrint.description') }}</th><th>{{ $t('invoicePrint.quantity') }}</th><th>{{ $t('invoicePrint.price') }}</th><th>TVSH</th><th>{{ $t('invoicePrint.total') }}</th></tr>
            </thead>
            <tbody>
                <tr v-for="(line, index) in lines" :key="`${line.description}-${index}`">
                    <td>{{ line.description }}</td>
                    <td>{{ line.quantity }}</td>
                    <td>{{ number(line.price) }} {{ currency }}</td>
                    <td>{{ line.vat }}%</td>
                    <td>{{ number(line.total) }} {{ currency }}</td>
                </tr>
                <tr v-if="discount > 0" class="discount-row">
                    <td>{{ $t('invoicePrint.invoiceDiscount') }}</td><td>1</td><td>-{{ number(discount) }} {{ currency }}</td><td>—</td><td>-{{ number(discount) }} {{ currency }}</td>
                </tr>
            </tbody>
        </table>

        <div class="invoice-summary-grid">
            <div>
                <h2>{{ $t('invoicePrint.vatSummary') }}</h2>
                <table class="vat-table">
                    <thead><tr><th>{{ $t('invoicePrint.rate') }}</th><th>{{ $t('invoicePrint.base') }}</th><th>TVSH</th><th>{{ $t('invoicePrint.withVat') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="row in vatSummary" :key="row.rate">
                            <td>{{ row.rate }}%</td><td>{{ number(row.net) }}</td><td>{{ number(row.tax) }}</td><td>{{ number(row.gross) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <dl class="invoice-totals">
                <div><dt>{{ $t('invoicePrint.subtotalWithoutVat') }}</dt><dd>{{ number(netTotal) }} {{ currency }}</dd></div>
                <div><dt>TVSH</dt><dd>{{ number(taxTotal) }} {{ currency }}</dd></div>
                <div class="grand"><dt>TOTALI</dt><dd>{{ number(total) }} {{ currency }}</dd></div>
                <div v-if="currency !== 'ALL' && exchangeRate"><dt>{{ $t('invoicePrint.valueInAll') }}</dt><dd>{{ number(allTotal) }} ALL</dd></div>
            </dl>
        </div>

        <section v-if="isFiscalized" class="fiscal-box">
            <div class="fiscal-data">
                <p class="info-label">{{ $t('invoicePrint.fiscalData') }}</p>
                <p><b>{{ $t('invoicePrint.iic') }}:</b> {{ fiscalDocument.iic || '—' }}</p>
                <p><b>{{ $t('invoicePrint.fic') }}:</b> {{ fiscalDocument.fic || '—' }}</p>
                <p><b>{{ $t('invoicePrint.unit') }}:</b> {{ fiscalDocument.tcr_code || meta.branch || '—' }}</p>
                <p><b>{{ $t('invoicePrint.operator') }}:</b> {{ fiscalDocument.operator_code || meta.operator || '—' }}</p>
            </div>
            <div v-if="fiscalDocument.verify_url" class="invoice-qr">
                <QrCode :value="fiscalDocument.verify_url" :size="108" />
                <span>{{ $t('invoicePrint.verifyInvoice') }}</span>
            </div>
        </section>
        <p v-else class="non-fiscal-note">{{ $t('invoicePrint.hotelNonFiscalHint') }}</p>

        <footer>
            <p>{{ $t('invoicePrint.hotelThankYou', { hotel: meta.hotel_name || $t('invoicePrint.ourHotel') }) }}</p>
            <p v-if="fiscalDocument?.environment === 'sandbox'">{{ $t('invoicePrint.sandboxInvoice') }}</p>
        </footer>
    </section>
</template>

<style scoped>
.hotel-invoice { position: relative; display: flex; width: 210mm; min-height: 297mm; box-sizing: border-box; flex-direction: column; margin: 0 auto; padding: 17mm 17mm 15mm; background: #fff; color: #172033; font-family: Arial, Helvetica, sans-serif; font-size: 11px; line-height: 1.45; }
.invoice-accent { position: absolute; inset: 0 0 auto; height: 7mm; background: linear-gradient(90deg, #172033 0 58%, #216b5b 58% 100%); }
.invoice-header { display: flex; justify-content: space-between; gap: 24px; margin-top: 6mm; padding-bottom: 9mm; border-bottom: 1px solid #dbe2ea; }
.invoice-header h1 { margin: 3px 0 5px; font-size: 25px; line-height: 1.05; }
.invoice-header p { margin: 1px 0; color: #5d6879; }
.invoice-kicker, .info-label { margin: 0 !important; color: #216b5b !important; font-size: 9px; font-weight: 800; letter-spacing: .13em; }
.invoice-number { min-width: 52mm; text-align: right; }
.invoice-number span { display: block; color: #7b8594; }
.invoice-number strong { display: block; margin: 4px 0; color: #172033; font-size: 17px; overflow-wrap: anywhere; }
.invoice-info-grid { display: grid; grid-template-columns: 1.1fr 1.2fr .9fr; gap: 6mm; margin: 8mm 0; padding: 6mm; background: #f5f8fa; border-radius: 3mm; }
.invoice-info-grid strong { display: block; margin: 4px 0 2px; font-size: 12px; }
.invoice-info-grid p { margin: 1px 0; color: #667085; }
.invoice-lines { width: 100%; border-collapse: collapse; table-layout: fixed; }
.invoice-lines th { padding: 3.5mm 2.5mm; background: #172033; color: #fff; text-align: right; font-size: 9px; letter-spacing: .04em; }
.invoice-lines th:first-child { width: 47%; text-align: left; }
.invoice-lines td { padding: 4mm 2.5mm; border-bottom: 1px solid #e7ebf0; text-align: right; vertical-align: top; }
.invoice-lines td:first-child { text-align: left; font-weight: 600; }
.invoice-lines thead { display: table-header-group; }
.invoice-lines tr { break-inside: avoid; page-break-inside: avoid; }
.discount-row td { color: #216b5b; }
.invoice-summary-grid { display: grid; grid-template-columns: 1.1fr .9fr; gap: 10mm; margin-top: 9mm; }
.invoice-summary-grid h2 { margin: 0 0 3mm; font-size: 12px; }
.vat-table { width: 100%; border-collapse: collapse; color: #5d6879; }
.vat-table th, .vat-table td { padding: 2mm; border-bottom: 1px solid #e7ebf0; text-align: right; }
.vat-table th:first-child, .vat-table td:first-child { text-align: left; }
.invoice-totals { margin: 0; }
.invoice-totals div { display: flex; justify-content: space-between; gap: 10px; padding: 2mm 0; border-bottom: 1px solid #e7ebf0; }
.invoice-totals dd { margin: 0; font-weight: 700; }
.invoice-totals .grand { margin-top: 2mm; padding: 3.5mm; border: 0; border-radius: 2.5mm; background: #eaf5f1; color: #175447; font-size: 15px; }
.fiscal-box { display: flex; align-items: center; justify-content: space-between; gap: 8mm; margin-top: 10mm; padding: 5mm 6mm; border: 1px solid #cdd8d4; border-radius: 3mm; background: #fbfdfc; }
.fiscal-data { min-width: 0; }
.fiscal-data p { margin: 2px 0; overflow-wrap: anywhere; }
.invoice-qr { flex: 0 0 auto; text-align: center; }
.invoice-qr span { display: block; margin-top: 2px; color: #667085; font-size: 9px; }
.non-fiscal-note { margin-top: 10mm; padding: 4mm; border: 1px solid #d49a17; background: #fff9e8; color: #805b0b; text-align: center; font-weight: 700; }
.hotel-invoice footer { display: flex; justify-content: space-between; margin-top: auto; border-top: 1px solid #dbe2ea; padding-top: 3mm; color: #7b8594; font-size: 9px; }
.hotel-invoice footer p { margin: 0; }
</style>
