<script setup>
import { getIntlLocale, translate } from '@/i18n';
import { computed } from 'vue';
import QrCode from '@/Components/Invoices/QrCode.vue';

const props = defineProps({
    order: { type: Object, required: true },
    settings: { type: Object, default: () => ({}) },
});

const document = computed(() => props.order?.fiscal_document || null);
const isFiscalized = computed(() => document.value?.status === 'fiscalized');
const currency = computed(() => document.value?.currency || props.settings.currency || 'EUR');
const rate = computed(() => Number(document.value?.vat_rate ?? props.settings.tax_rate ?? 20));
const exchangeRate = computed(() => Number(document.value?.exchange_rate ?? props.settings.exchange_rate ?? 0));

const lines = computed(() => {
    const payloadLines = document.value?.invoice_payload?.lines;
    if (Array.isArray(payloadLines) && payloadLines.length) {
        return payloadLines.map((line) => ({
            name: line.product_name,
            quantity: Number(line.quantity || 0),
            price: Number(line.price || 0),
            total: Number(line.total || 0),
        }));
    }

    return (props.order?.items || []).map((item) => ({
        name: item.menu_item?.name || translate('invoicePrint.posItem'),
        quantity: Number(item.quantity || 0),
        price: Number(item.unit_price || 0),
        total: Number(item.total_price || 0),
    }));
});

const gross = computed(() => Number(document.value?.total ?? props.order?.total_amount ?? 0));
const net = computed(() => rate.value > 0 ? gross.value / (1 + rate.value / 100) : gross.value);
const tax = computed(() => gross.value - net.value);
const allTotal = computed(() => currency.value === 'ALL' ? gross.value : gross.value * exchangeRate.value);
const vatLabel = computed(() => (
    rate.value === 0 && props.settings.vat_status === 'not_registered'
        ? translate('invoicePrint.withoutVat')
        : `TVSH ${rate.value}%`
));

const invoiceDate = computed(() => (
    document.value?.fiscalized_at || props.order?.paid_at || props.order?.created_at
));

function number(value) {
    return Number(value || 0).toLocaleString(getIntlLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function dateTime(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString(getIntlLocale(), {
        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}

function paymentLabel(method) {
    return {
        BANKNOTE: translate('invoicePrint.cash'),
        CARD: translate('invoicePrint.card'),
        cash: translate('invoicePrint.cash'),
        card: translate('invoicePrint.card'),
        room_charge: translate('invoicePrint.roomCharge'),
    }[method] || '—';
}
</script>

<template>
    <section id="pos-receipt" class="pos-receipt">
        <header class="receipt-center">
            <h1>{{ settings.hotel_name || 'Hotel' }}</h1>
            <p v-if="settings.legal_name && settings.legal_name !== settings.hotel_name">{{ settings.legal_name }}</p>
            <p v-if="settings.address">{{ settings.address }}</p>
            <p v-if="settings.nipt">{{ $t('invoicePrint.nipt') }}: {{ settings.nipt }}</p>
            <p v-if="settings.phone">{{ $t('invoicePrint.phoneShort') }}: {{ settings.phone }}</p>
        </header>

        <div class="receipt-rule" />
        <h2 class="receipt-title">{{ isFiscalized ? $t('invoicePrint.taxInvoice') : $t('invoicePrint.posReceipt') }}</h2>

        <dl class="receipt-meta">
            <div><dt>{{ $t('invoicePrint.invoiceNumber') }}</dt><dd>{{ document?.fiscal_number || `POS-${order.id}` }}</dd></div>
            <div><dt>{{ $t('invoicePrint.dateTime') }}</dt><dd>{{ dateTime(invoiceDate) }}</dd></div>
            <div><dt>{{ $t('invoicePrint.order') }}</dt><dd>#{{ order.id }}<template v-if="order.table_number"> · {{ $t('invoicePrint.table') }} {{ order.table_number }}</template></dd></div>
            <div><dt>{{ $t('invoicePrint.operator') }}</dt><dd>{{ document?.operator_code || order.created_by?.name || '—' }}</dd></div>
            <div><dt>{{ $t('invoicePrint.payment') }}</dt><dd>{{ paymentLabel(document?.payment_method || order.payment_method) }}</dd></div>
            <div><dt>{{ $t('invoicePrint.currency') }}</dt><dd>{{ currency }}</dd></div>
            <div v-if="currency !== 'ALL' && exchangeRate"><dt>{{ $t('invoicePrint.exchangeRate') }}</dt><dd>1 {{ currency }} = {{ exchangeRate.toFixed(4) }} ALL</dd></div>
        </dl>

        <div class="receipt-rule" />
        <table class="receipt-items">
            <thead>
                <tr><th>{{ $t('invoicePrint.item') }}</th><th class="num">{{ $t('invoicePrint.quantity') }}</th><th class="num">{{ $t('invoicePrint.price') }}</th><th class="num">{{ $t('invoicePrint.total') }}</th></tr>
            </thead>
            <tbody>
                <tr v-for="(line, index) in lines" :key="`${line.name}-${index}`">
                    <td>{{ line.name }}</td>
                    <td class="num">{{ line.quantity }}</td>
                    <td class="num">{{ number(line.price) }}</td>
                    <td class="num">{{ number(line.total) }}</td>
                </tr>
            </tbody>
        </table>

        <div class="receipt-rule" />
        <dl class="receipt-totals">
            <div><dt>{{ $t('invoicePrint.subtotal') }}</dt><dd>{{ number(net) }} {{ currency }}</dd></div>
            <div><dt>{{ vatLabel }}</dt><dd>{{ number(tax) }} {{ currency }}</dd></div>
            <div class="grand"><dt>TOTALI</dt><dd>{{ number(gross) }} {{ currency }}</dd></div>
            <div v-if="currency !== 'ALL' && exchangeRate"><dt>{{ $t('invoicePrint.valueInAll') }}</dt><dd>{{ number(allTotal) }} ALL</dd></div>
        </dl>

        <template v-if="isFiscalized">
            <div class="receipt-rule" />
            <div class="receipt-fiscal">
                <p><b>{{ $t('invoicePrint.iic') }}:</b> {{ document.iic || '—' }}</p>
                <p><b>{{ $t('invoicePrint.fic') }}:</b> {{ document.fic || '—' }}</p>
                <p v-if="document.tcr_code"><b>{{ $t('invoicePrint.unit') }}:</b> {{ document.tcr_code }}</p>
            </div>
            <div v-if="document.verify_url" class="receipt-qr">
                <QrCode :value="document.verify_url" :size="118" />
                <p>{{ $t('invoicePrint.scanToVerify') }}</p>
            </div>
        </template>
        <p v-else class="receipt-warning">{{ $t('invoicePrint.nonFiscalDocument') }}</p>

        <footer class="receipt-footer">
            <p>{{ $t('invoicePrint.thankYou') }}</p>
            <p v-if="document?.environment === 'sandbox'">{{ $t('invoicePrint.sandboxInvoice') }}</p>
        </footer>
    </section>
</template>

<style scoped>
.pos-receipt { width: 80mm; box-sizing: border-box; margin: 0 auto; padding: 5mm 4mm 6mm; background: #fff; color: #111827; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 10px; line-height: 1.35; }
.receipt-center { text-align: center; }
.receipt-center h1 { margin: 0 0 2px; font-family: Arial, sans-serif; font-size: 18px; line-height: 1.15; font-weight: 800; }
.receipt-center p, .receipt-footer p, .receipt-qr p { margin: 1px 0; }
.receipt-rule { margin: 8px 0; border-top: 1px dashed #111827; }
.receipt-title { margin: 0 0 7px; text-align: center; font-size: 12px; letter-spacing: .08em; }
.receipt-meta, .receipt-totals { margin: 0; }
.receipt-meta div, .receipt-totals div { display: flex; justify-content: space-between; gap: 8px; margin: 2px 0; }
.receipt-meta dt, .receipt-totals dt { color: #374151; }
.receipt-meta dd, .receipt-totals dd { margin: 0; text-align: right; font-weight: 700; overflow-wrap: anywhere; }
.receipt-items { width: 100%; border-collapse: collapse; table-layout: fixed; }
.receipt-items th { border-bottom: 1px solid #111827; padding: 3px 1px; text-align: left; font-size: 9px; }
.receipt-items td { padding: 4px 1px; vertical-align: top; overflow-wrap: anywhere; }
.receipt-items th:first-child, .receipt-items td:first-child { width: 43%; }
.receipt-items .num { text-align: right; white-space: nowrap; }
.receipt-totals .grand { border-top: 1px solid #111827; margin-top: 5px; padding-top: 5px; font-size: 13px; }
.receipt-fiscal p { margin: 3px 0; overflow-wrap: anywhere; }
.receipt-qr { display: grid; justify-items: center; gap: 3px; margin-top: 10px; text-align: center; }
.receipt-warning { margin: 10px 0 0; border: 1px solid #111827; padding: 5px; text-align: center; font-weight: 800; letter-spacing: .08em; }
.receipt-footer { margin-top: 12px; text-align: center; }
</style>
