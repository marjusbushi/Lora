<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\FiscalDocument;
use App\Models\Reservation;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ReservationFiscalizationService
{
    private const PROVIDER = 'fature_al';

    private const ENVIRONMENT = 'sandbox';

    public function __construct(
        private readonly FatureAlConfiguration $configuration,
        private readonly FatureAlClient $client,
        private readonly TenantContext $tenantContext,
        private readonly VatConfiguration $vatConfiguration,
    ) {}

    public function fiscalize(Reservation $reservation): FiscalDocument
    {
        $payload = $this->payload($reservation);
        $requestHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $existing = FiscalDocument::query()
            ->where('reservation_id', $reservation->id)
            ->where('provider', self::PROVIDER)
            ->where('environment', self::ENVIRONMENT)
            ->first();

        if ($existing?->status === FiscalDocument::STATUS_FISCALIZED) {
            return $existing;
        }

        $payloadChanged = $existing && ! hash_equals($existing->request_hash, $requestHash);
        $previousRequestHash = $payloadChanged ? $existing->request_hash : null;
        $invoice = null;

        if ($payloadChanged) {
            if ($existing->status !== FiscalDocument::STATUS_FAILED
                || $existing->remote_id
                || $existing->fiscal_number) {
                throw ValidationException::withMessages([
                    'fiscalization' => 'Fatura ka ndryshuar pas tentativës së parë. Kontrolloje përpara riprovimit.',
                ]);
            }

            try {
                // Reconcile the old payload first. If fature.al already has the
                // invoice, preserve that fiscal record instead of retrying with
                // amended data under the same idempotency key.
                $invoice = $this->client->findInvoiceByInternalId($existing->internal_id);
            } catch (Throwable $exception) {
                $this->markFailed($existing, $reservation, $exception);
            }

            if ($invoice) {
                return $this->complete($existing, $reservation, $invoice);
            }
        }

        $document = $this->startAttempt($reservation, $payload, $requestHash, $payloadChanged);

        if ($document->status === FiscalDocument::STATUS_FISCALIZED) {
            return $document;
        }

        if ($payloadChanged) {
            AuditLog::record('fiscalization.retry_payload_updated', $reservation, [
                'provider' => self::PROVIDER,
                'environment' => self::ENVIRONMENT,
                'internal_id' => $document->internal_id,
                'previous_request_hash' => $previousRequestHash,
                'request_hash' => $requestHash,
                'exchange_rate' => $payload['exchange_rate'] ?? null,
            ]);
        }

        try {
            // A failed/uncertain attempt may have reached fature.al even when
            // its response did not reach us. Reconcile by the stable internalId
            // before another create request so retries cannot duplicate invoices.
            $invoice ??= $document->wasRecentlyCreated || $payloadChanged
                ? null
                : $this->client->findInvoiceByInternalId($document->internal_id);
            $invoice ??= $this->client->createCashInvoice($payload);
        } catch (Throwable $exception) {
            $this->markFailed($document, $reservation, $exception);
        }

        return $this->complete($document, $reservation, $invoice);
    }

    /** @param array<string, mixed> $invoice */
    private function complete(FiscalDocument $document, Reservation $reservation, array $invoice): FiscalDocument
    {
        $document->forceFill([
            'status' => FiscalDocument::STATUS_FISCALIZED,
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

        AuditLog::record('fiscalization.completed', $reservation, [
            'provider' => self::PROVIDER,
            'environment' => self::ENVIRONMENT,
            'internal_id' => $document->internal_id,
            'fiscal_number' => $document->fiscal_number,
            'payment_method' => $document->payment_method,
            'total' => (float) $document->total,
        ]);

        return $document;
    }

    private function markFailed(FiscalDocument $document, Reservation $reservation, Throwable $exception): never
    {
        $message = Str::limit($exception->getMessage(), 1000, '');
        $document->forceFill([
            'status' => FiscalDocument::STATUS_FAILED,
            'last_error' => $message,
        ])->save();

        AuditLog::record('fiscalization.failed', $reservation, [
            'provider' => self::PROVIDER,
            'environment' => self::ENVIRONMENT,
            'internal_id' => $document->internal_id,
            'total' => (float) $document->total,
        ]);

        throw new RuntimeException($message, previous: $exception);
    }

    /** @return array<string, mixed> */
    public function payload(Reservation $reservation): array
    {
        if (! $this->configuration->configured()) {
            throw ValidationException::withMessages([
                'fiscalization' => 'Aktivizo dhe testo fature.al për këtë hotel përpara fiskalizimit.',
            ]);
        }

        if ($this->configuration->get('environment') !== self::ENVIRONMENT) {
            throw ValidationException::withMessages([
                'fiscalization' => 'Kjo fazë lejon fiskalizim vetëm në sandbox, jo në production.',
            ]);
        }

        if (! $this->configuration->verified()) {
            throw ValidationException::withMessages([
                'fiscalization' => 'Testo me sukses lidhjen fature.al përpara fiskalizimit.',
            ]);
        }

        if ($reservation->status !== 'checked_out') {
            throw ValidationException::withMessages([
                'fiscalization' => 'Rezervimi duhet të ketë përfunduar check-out përpara fiskalizimit.',
            ]);
        }

        $reservation->loadMissing(['room.roomType', 'guest', 'folioItems', 'payments']);

        $this->vatConfiguration->ensureConfigured();
        $this->ensureProviderVatStatusMatches();

        $paymentMethod = $this->paymentMethod($reservation);
        $lines = $this->lines(
            $reservation,
            $this->vatConfiguration->accommodationRate(),
            $this->vatConfiguration->productRate(),
        );
        $discount = round((float) $reservation->folioItems->where('type', 'discount')->sum('amount'), 2);
        $total = round(collect($lines)->sum('total') - $discount, 2);
        if ($total <= 0) {
            throw ValidationException::withMessages([
                'fiscalization' => 'Totali fiskal duhet të jetë më i madh se zero.',
            ]);
        }

        $paid = round((float) $reservation->payments
            ->reject(fn ($payment) => $payment->is_voided)
            ->sum('amount'), 2);
        if (abs($paid - $total) > 0.005) {
            throw ValidationException::withMessages([
                'fiscalization' => 'Pagesat e rezervimit nuk përputhen me totalin e faturës.',
            ]);
        }

        $currency = strtoupper((string) ($this->tenantContext->tenant()?->currency ?: 'EUR'));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'EUR';
        }

        $payload = [
            'internalId' => 'LORA-T'.$reservation->tenant_id.'-RES-'.$reservation->id,
            'payment_method' => $paymentMethod,
            'currency' => $currency,
            'supply_start_date' => $reservation->check_in_date?->toDateString(),
            'supply_end_date' => $reservation->check_out_date?->toDateString(),
            'notes' => 'Lora PMS · Rezervimi #'.$reservation->id,
            'lines' => $lines,
        ];

        if ($client = $this->identifiedClient($reservation)) {
            $payload['client'] = $client;
        }

        if ($currency !== 'ALL') {
            $exchangeRate = BaseCurrency::rate('ALL');
            if ($exchangeRate === null || $exchangeRate <= 0) {
                throw ValidationException::withMessages([
                    'fiscalization' => "Kursi ALL/{$currency} mungon. Vendose te Settings → Monedhat përpara fiskalizimit.",
                ]);
            }

            $payload['exchange_rate'] = round($exchangeRate, 4);
        }

        if ($discount > 0) {
            $payload['invoice_discount_type'] = 'amount';
            $payload['invoice_discount_value'] = $discount;
        }

        return $payload;
    }

    /** @return array<string, mixed>|null */
    private function identifiedClient(Reservation $reservation): ?array
    {
        $guest = $reservation->guest;
        $documentNumber = trim((string) $guest?->document_number);
        $documentType = match ($guest?->document_type) {
            'passport' => 'PASS',
            'id_card' => 'ID',
            default => null,
        };

        if ($documentNumber === '' || $documentType === null) {
            return null;
        }

        return [
            'name' => trim((string) $guest->full_name) ?: 'Klient hotelerie',
            'id' => [
                'type' => $documentType,
                'id' => $documentNumber,
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function startAttempt(
        Reservation $reservation,
        array $payload,
        string $requestHash,
        bool $allowFailedPayloadUpdate = false,
    ): FiscalDocument {
        return DB::transaction(function () use ($reservation, $payload, $requestHash, $allowFailedPayloadUpdate) {
            $document = FiscalDocument::query()
                ->where('reservation_id', $reservation->id)
                ->where('provider', self::PROVIDER)
                ->where('environment', self::ENVIRONMENT)
                ->lockForUpdate()
                ->first();

            if ($document?->status === FiscalDocument::STATUS_FISCALIZED) {
                return $document;
            }

            if ($document?->status === FiscalDocument::STATUS_PROCESSING
                && $document->attempted_at?->isAfter(now()->subMinutes(5))) {
                throw ValidationException::withMessages([
                    'fiscalization' => 'Fatura po përpunohet. Prit pak përpara se ta provosh sërish.',
                ]);
            }

            if ($document
                && ! hash_equals($document->request_hash, $requestHash)
                && ! ($allowFailedPayloadUpdate
                    && $document->status === FiscalDocument::STATUS_FAILED
                    && ! $document->remote_id
                    && ! $document->fiscal_number)) {
                throw ValidationException::withMessages([
                    'fiscalization' => 'Fatura ka ndryshuar pas tentativës së parë. Kontrolloje përpara riprovimit.',
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
                'total' => round(collect($payload['lines'])->sum('total') - (float) ($payload['invoice_discount_value'] ?? 0), 2),
                'vat_rate' => $this->vatConfiguration->accommodationRate(),
                'invoice_payload' => $payload,
                'request_hash' => $requestHash,
                'status' => FiscalDocument::STATUS_PROCESSING,
                'attempted_at' => now(),
                'last_error' => null,
            ];

            if ($document) {
                $document->forceFill($values)->save();

                return $document;
            }

            return FiscalDocument::query()->create($values + [
                'reservation_id' => $reservation->id,
            ]);
        });
    }

    private function paymentMethod(Reservation $reservation): string
    {
        $methods = $reservation->payments
            ->reject(fn ($payment) => $payment->is_voided)
            ->pluck('method')
            ->unique()
            ->values();

        if ($methods->count() !== 1 || ! in_array($methods->first(), ['cash', 'card'], true)) {
            throw ValidationException::withMessages([
                'fiscalization' => 'Fatura sandbox mbështet vetëm një mënyrë pagese: cash ose card.',
            ]);
        }

        return $methods->first() === 'cash' ? 'BANKNOTE' : 'CARD';
    }

    /** @return array<int, array<string, mixed>> */
    private function lines(Reservation $reservation, int $accommodationVatRate, int $productVatRate): array
    {
        $lines = [];
        $roomCharge = round((float) $reservation->total_amount, 2);
        if ($roomCharge > 0) {
            $lines[] = [
                'product_name' => 'Dhomë '.($reservation->room?->room_number ?: '—').' · Akomodim',
                'product_code' => 'ROOM-STAY',
                'unit' => 'shërbim',
                'quantity' => 1,
                'price' => $roomCharge,
                'total' => $roomCharge,
                'vat' => $accommodationVatRate,
            ];
        }

        foreach ($reservation->folioItems->whereNotIn('type', ['discount', 'room']) as $item) {
            $amount = round((float) $item->amount, 2);
            if ($amount <= 0) {
                continue;
            }

            $vatRate = $item->vat_rate !== null
                ? $this->vatConfiguration->folioRate($item->vat_rate)
                : $productVatRate;

            $lines[] = [
                'product_name' => Str::limit($item->description, 255, ''),
                'product_code' => 'FOLIO-'.strtoupper((string) $item->type).'-'.$item->id,
                'unit' => 'shërbim',
                'quantity' => 1,
                'price' => $amount,
                'total' => $amount,
                'vat' => (int) $vatRate,
            ];
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'fiscalization' => 'Fatura nuk ka rreshta të fiskalizueshëm.',
            ]);
        }

        return $lines;
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
