<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\PosFiscalDocument;
use App\Models\PosOrder;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PosFiscalizationService
{
    private const PROVIDER = 'fature_al';

    private const ENVIRONMENT = 'sandbox';

    public function __construct(
        private readonly FatureAlConfiguration $configuration,
        private readonly FatureAlClient $client,
        private readonly TenantContext $tenantContext,
        private readonly VatConfiguration $vatConfiguration,
    ) {}

    public function fiscalize(PosOrder $order): PosFiscalDocument
    {
        $payload = $this->payload($order);
        $requestHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $document = $this->startAttempt($order, $payload, $requestHash);

        if ($document->status === PosFiscalDocument::STATUS_FISCALIZED) {
            return $document;
        }

        try {
            // A retry first reconciles the stable internal id. This prevents a
            // timeout from creating a second fiscal invoice for the same sale.
            $invoice = $document->wasRecentlyCreated
                ? null
                : $this->client->findInvoiceByInternalId($document->internal_id);
            $invoice ??= $this->client->createCashInvoice($payload);
        } catch (Throwable $exception) {
            $this->markFailed($document, $order, $exception);
        }

        return $this->complete($document, $order, $invoice);
    }

    /** @return array<string, mixed> */
    public function payload(PosOrder $order): array
    {
        if (! $this->configuration->configured()) {
            throw ValidationException::withMessages([
                'fiscalization' => 'Aktivizo fature.al për këtë hotel përpara fiskalizimit të POS-it.',
            ]);
        }

        if ($this->configuration->get('environment') !== self::ENVIRONMENT) {
            throw ValidationException::withMessages([
                'fiscalization' => 'Kjo fazë lejon fiskalizim vetëm në sandbox, jo në production.',
            ]);
        }

        if (! $this->configuration->verified()) {
            throw ValidationException::withMessages([
                'fiscalization' => 'Testo me sukses lidhjen fature.al përpara fiskalizimit të POS-it.',
            ]);
        }

        if ($order->status !== 'completed' || ! in_array($order->payment_method, ['cash', 'card'], true)) {
            throw ValidationException::withMessages([
                'fiscalization' => 'Vetëm porositë e mbyllura me cash ose kartë fiskalizohen në POS.',
            ]);
        }

        $order->loadMissing(['items.menuItem']);
        $this->vatConfiguration->ensureConfigured();
        $this->ensureProviderVatStatusMatches();
        $vatRate = $this->vatConfiguration->productRate();

        $lines = $order->items->map(function ($item) use ($vatRate) {
            $quantity = max(1, (int) $item->quantity);
            $price = round((float) $item->unit_price, 2);

            return [
                'product_name' => Str::limit($item->menuItem?->name ?: 'Artikull POS', 255, ''),
                'product_code' => 'POS-ITEM-'.$item->id,
                'unit' => 'copë',
                'quantity' => $quantity,
                'price' => $price,
                'total' => round((float) $item->total_price, 2),
                'vat' => (int) $vatRate,
            ];
        })->values()->all();

        if ($lines === [] || round(collect($lines)->sum('total'), 2) <= 0) {
            throw ValidationException::withMessages([
                'fiscalization' => 'Porosia nuk ka rreshta të fiskalizueshëm.',
            ]);
        }

        $currency = strtoupper((string) ($this->tenantContext->tenant()?->currency ?: 'EUR'));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'EUR';
        }

        $payload = [
            'internalId' => 'LORA-T'.$order->tenant_id.'-POS-'.$order->id,
            'payment_method' => $order->payment_method === 'cash' ? 'BANKNOTE' : 'CARD',
            'currency' => $currency,
            'notes' => 'Lora PMS · POS porosia #'.$order->id,
            'lines' => $lines,
        ];

        if ($currency !== 'ALL') {
            $exchangeRate = BaseCurrency::rate('ALL');
            if ($exchangeRate === null || $exchangeRate <= 0) {
                throw ValidationException::withMessages([
                    'fiscalization' => "Kursi ALL/{$currency} mungon. Vendose te Settings → Monedhat.",
                ]);
            }
            $payload['exchange_rate'] = round($exchangeRate, 4);
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function startAttempt(PosOrder $order, array $payload, string $requestHash): PosFiscalDocument
    {
        return DB::transaction(function () use ($order, $payload, $requestHash) {
            $document = PosFiscalDocument::query()
                ->where('pos_order_id', $order->id)
                ->where('provider', self::PROVIDER)
                ->where('environment', self::ENVIRONMENT)
                ->lockForUpdate()
                ->first();

            if ($document?->status === PosFiscalDocument::STATUS_FISCALIZED) {
                return $document;
            }

            if ($document?->status === PosFiscalDocument::STATUS_PROCESSING
                && $document->attempted_at?->isAfter(now()->subMinutes(5))) {
                throw ValidationException::withMessages([
                    'fiscalization' => 'Fatura POS po përpunohet. Prit pak përpara riprovimit.',
                ]);
            }

            if ($document && ! hash_equals($document->request_hash, $requestHash)) {
                throw ValidationException::withMessages([
                    'fiscalization' => 'Porosia ka ndryshuar pas tentativës së fiskalizimit. Kontrolloje manualisht.',
                ]);
            }

            $values = [
                'provider' => self::PROVIDER,
                'environment' => self::ENVIRONMENT,
                'document_type' => 'cash_invoice',
                'internal_id' => $payload['internalId'],
                'payment_method' => $payload['payment_method'],
                'currency' => $payload['currency'],
                'exchange_rate' => $payload['exchange_rate'] ?? null,
                'total' => round(collect($payload['lines'])->sum('total'), 2),
                'vat_rate' => $this->vatConfiguration->productRate(),
                'invoice_payload' => $payload,
                'request_hash' => $requestHash,
                'status' => PosFiscalDocument::STATUS_PROCESSING,
                'attempted_at' => now(),
                'last_error' => null,
            ];

            if ($document) {
                $document->forceFill($values)->save();

                return $document;
            }

            return PosFiscalDocument::query()->create($values + ['pos_order_id' => $order->id]);
        });
    }

    /** @param array<string, mixed> $invoice */
    private function complete(PosFiscalDocument $document, PosOrder $order, array $invoice): PosFiscalDocument
    {
        $document->forceFill([
            'status' => PosFiscalDocument::STATUS_FISCALIZED,
            'remote_id' => isset($invoice['id']) ? (string) $invoice['id'] : null,
            'fiscal_number' => $invoice['number'] ?? null,
            'iic' => $invoice['iic'] ?? null,
            'fic' => $invoice['fic'] ?? null,
            'tcr_code' => $invoice['tcrCode'] ?? null,
            'business_code' => $invoice['businessCode'] ?? null,
            'operator_code' => $invoice['operatorCode'] ?? null,
            'fiscalized_at' => $invoice['fiscalizedAt'] ?? now(),
            'verify_url' => $invoice['verifyURL'] ?? null,
            'pdf_url' => $invoice['pdf'] ?? null,
            'last_error' => null,
        ])->save();

        AuditLog::record('pos.fiscalization.completed', $order, [
            'fiscal_number' => $document->fiscal_number,
            'total' => (float) $document->total,
        ]);

        return $document;
    }

    private function markFailed(PosFiscalDocument $document, PosOrder $order, Throwable $exception): never
    {
        $message = Str::limit($exception->getMessage(), 1000, '');
        $document->forceFill([
            'status' => PosFiscalDocument::STATUS_FAILED,
            'last_error' => $message,
        ])->save();

        AuditLog::record('pos.fiscalization.failed', $order);

        throw new RuntimeException($message, previous: $exception);
    }

    private function ensureProviderVatStatusMatches(): void
    {
        $providerStatus = data_get($this->configuration->get('account', []), 'issuer_in_vat');
        if (! is_bool($providerStatus) || $providerStatus === $this->vatConfiguration->registered()) {
            return;
        }

        throw ValidationException::withMessages([
            'fiscalization' => 'Statusi “me/pa TVSH” nuk përputhet me llogarinë fature.al. Kontrollo Settings → Financa dhe ritesto integrimin.',
        ]);
    }
}
