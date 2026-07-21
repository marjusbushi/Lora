<?php

namespace App\Services\Reporting;

use App\Models\FiscalDocument;
use App\Models\PosFiscalDocument;
use App\Models\PosOrder;
use App\Models\Reservation;
use App\Services\VatConfiguration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class FiscalVatReportService
{
    private const PROVIDER = 'fature_al';

    private const ENVIRONMENT = 'sandbox';

    public function __construct(private readonly VatConfiguration $vatConfiguration) {}

    /** @return array{period:array,summary:array,statuses:array,sources:array,rates:array,documents:array} */
    public function summary(ReportingPeriod $period): array
    {
        $start = $period->from->startOfDay();
        $end = $period->to->endOfDay();
        $fiscalReservationIds = FiscalDocument::query()
            ->where('provider', self::PROVIDER)
            ->where('environment', self::ENVIRONMENT)
            ->where(function (Builder $query) use ($start, $end) {
                $query->whereBetween('fiscalized_at', [$start, $end])
                    ->orWhere(fn (Builder $attempted) => $attempted
                        ->whereNull('fiscalized_at')
                        ->whereBetween('attempted_at', [$start, $end]));
            })->pluck('reservation_id');
        $fiscalPosIds = PosFiscalDocument::query()
            ->where('provider', self::PROVIDER)
            ->where('environment', self::ENVIRONMENT)
            ->where(function (Builder $query) use ($start, $end) {
                $query->whereBetween('fiscalized_at', [$start, $end])
                    ->orWhere(fn (Builder $attempted) => $attempted
                        ->whereNull('fiscalized_at')
                        ->whereBetween('attempted_at', [$start, $end]));
            })->pluck('pos_order_id');

        $reservations = Reservation::query()
            ->where('status', 'checked_out')
            ->where(function (Builder $query) use ($period, $fiscalReservationIds) {
                $query->whereBetween('check_out_date', [$period->from->toDateString(), $period->to->toDateString()])
                    ->orWhereIn('id', $fiscalReservationIds);
            })
            ->where('total_amount', '>', 0)
            ->with([
                'guest:id,first_name,last_name',
                'room:id,room_number',
                'folioItems:id,reservation_id,type,amount_base',
            ])
            ->get(['id', 'guest_id', 'room_id', 'check_out_date', 'total_amount_base']);

        $posOrders = $this->posOrdersFor($period, $fiscalPosIds->all())
            ->where('total_amount', '>', 0)
            ->get(['id', 'business_date', 'paid_at', 'created_at', 'total_amount']);

        $reservationDocuments = FiscalDocument::query()
            ->whereIn('reservation_id', $reservations->pluck('id'))
            ->where('provider', self::PROVIDER)
            ->where('environment', self::ENVIRONMENT)
            ->orderByDesc('id')
            ->get()
            ->unique('reservation_id')
            ->keyBy('reservation_id');
        $posDocuments = PosFiscalDocument::query()
            ->whereIn('pos_order_id', $posOrders->pluck('id'))
            ->where('provider', self::PROVIDER)
            ->where('environment', self::ENVIRONMENT)
            ->orderByDesc('id')
            ->get()
            ->unique('pos_order_id')
            ->keyBy('pos_order_id');

        $rows = collect();
        foreach ($reservations as $reservation) {
            $document = $reservationDocuments->get($reservation->id);
            $rows->push($this->row(
                'pms',
                $reservation->id,
                $reservation->check_out_date?->toDateString(),
                $this->reservationGross($reservation),
                $this->vatConfiguration->accommodationRate(),
                $document,
                trim("{$reservation->guest?->first_name} {$reservation->guest?->last_name}") ?: '—',
                $reservation->room?->room_number,
            ));
        }
        foreach ($posOrders as $order) {
            $rows->push($this->row(
                'pos',
                $order->id,
                ($order->business_date ?? $order->paid_at ?? $order->created_at)?->toDateString(),
                (float) $order->total_amount,
                $this->vatConfiguration->productRate(),
                $posDocuments->get($order->id),
            ));
        }

        $covered = $rows->where('status', FiscalDocument::STATUS_FISCALIZED);
        $fiscalized = $covered->filter(fn (array $row) => $row['date'] >= $period->from->toDateString()
            && $row['date'] <= $period->to->toDateString());
        $gross = round((float) $fiscalized->sum('gross'), 2);
        $vat = round((float) $fiscalized->sum('vat'), 2);
        $statuses = collect(['fiscalized', 'failed', 'processing', 'missing'])
            ->map(fn (string $status) => [
                'status' => $status,
                'count' => $rows->where('status', $status)->count(),
                'gross' => round((float) $rows->where('status', $status)->sum('gross'), 2),
            ])->all();
        $sources = collect(['pms', 'pos'])->map(function (string $source) use ($rows, $period) {
            $sourceRows = $rows->where('source', $source);
            $sourceCovered = $sourceRows->where('status', FiscalDocument::STATUS_FISCALIZED);
            $sourceFiscalized = $sourceCovered->filter(fn (array $row) => $row['date'] >= $period->from->toDateString()
                && $row['date'] <= $period->to->toDateString());

            return [
                'source' => $source,
                'documents' => $sourceRows->count(),
                'fiscalized' => $sourceCovered->count(),
                'tax_documents' => $sourceFiscalized->count(),
                'gross' => round((float) $sourceFiscalized->sum('gross'), 2),
                'vat' => round((float) $sourceFiscalized->sum('vat'), 2),
            ];
        })->all();
        $rates = $fiscalized->flatMap(fn (array $row) => $row['vat_breakdown'])
            ->groupBy('rate')->map(function (Collection $rateRows, string|int $rate) {
                return [
                    'rate' => (float) $rate,
                    'documents' => $rateRows->count(),
                    'gross' => round((float) $rateRows->sum('gross'), 2),
                    'vat' => round((float) $rateRows->sum('vat'), 2),
                    'net' => round((float) $rateRows->sum('net'), 2),
                ];
            })->sortBy('rate')->values()->all();

        return [
            'period' => $period->toArray(),
            'summary' => [
                'documents' => $rows->count(),
                'fiscalized' => $covered->count(),
                'tax_documents' => $fiscalized->count(),
                'failed' => $rows->where('status', 'failed')->count(),
                'processing' => $rows->where('status', 'processing')->count(),
                'missing' => $rows->where('status', 'missing')->count(),
                'coverage_rate' => $rows->count() > 0 ? round($covered->count() / $rows->count() * 100, 1) : 100.0,
                'gross' => $gross,
                'vat' => $vat,
                'net' => round($gross - $vat, 2),
                'vat_status' => $this->vatConfiguration->status(),
            ],
            'statuses' => $statuses,
            'sources' => $sources,
            'rates' => $rates,
            'documents' => $rows->sortByDesc('date')->values()->all(),
        ];
    }

    /** @param array<int> $extraIds */
    private function posOrdersFor(ReportingPeriod $period, array $extraIds = []): Builder
    {
        $from = $period->from->toDateString();
        $to = $period->to->toDateString();

        return PosOrder::query()->where('status', 'completed')->where(function (Builder $query) use ($from, $to, $extraIds) {
            $query->where(function (Builder $businessDate) use ($from, $to) {
                $businessDate->whereNotNull('business_date')
                    ->whereDate('business_date', '>=', $from)
                    ->whereDate('business_date', '<=', $to);
            })->orWhere(function (Builder $paidAt) use ($from, $to) {
                $paidAt->whereNull('business_date')->whereBetween('paid_at', ["{$from} 00:00:00", "{$to} 23:59:59"]);
            })->orWhere(function (Builder $legacy) use ($from, $to) {
                $legacy->whereNull('business_date')->whereNull('paid_at')
                    ->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"]);
            });
            if ($extraIds !== []) {
                $query->orWhereIn('id', $extraIds);
            }
        });
    }

    private function row(
        string $source,
        int $sourceId,
        ?string $date,
        float $fallbackGross,
        float $fallbackRate,
        FiscalDocument|PosFiscalDocument|null $document,
        ?string $guest = null,
        ?string $room = null,
    ): array {
        $status = $document?->status ?? 'missing';
        $gross = round((float) ($document?->total ?? $fallbackGross), 2);
        $rate = (float) ($document?->vat_rate ?? $fallbackRate);
        $vatBreakdown = $status === FiscalDocument::STATUS_FISCALIZED
            ? $this->documentVatBreakdown($document, $gross, $rate)
            : [];
        $vat = round((float) collect($vatBreakdown)->sum('vat'), 2);

        return [
            'source' => $source,
            'source_id' => $sourceId,
            'date' => $document?->fiscalized_at?->toDateString() ?? $date,
            'status' => $status,
            'fiscal_number' => $document?->fiscal_number,
            'payment_method' => $document?->payment_method,
            'currency' => $document?->currency,
            'gross' => $gross,
            'vat_rate' => $rate,
            'vat_breakdown' => $vatBreakdown,
            'vat' => $vat,
            'net' => round($gross - $vat, 2),
            'guest' => $guest,
            'room' => $room,
            'verify_url' => $document?->verify_url,
            'last_error' => $document?->last_error,
        ];
    }

    /** @return array<int, array{rate:float,gross:float,vat:float,net:float}> */
    private function documentVatBreakdown(FiscalDocument|PosFiscalDocument $document, float $gross, float $fallbackRate): array
    {
        $lines = collect(data_get($document->invoice_payload, 'lines', []));
        $lineGross = (float) $lines->sum(fn (array $line) => (float) ($line['total'] ?? 0));
        if ($lineGross <= 0) {
            $vat = $this->vatConfiguration->taxPortion($gross, (int) $fallbackRate);

            return [['rate' => $fallbackRate, 'gross' => $gross, 'vat' => $vat, 'net' => round($gross - $vat, 2)]];
        }

        $discountFactor = $gross / $lineGross;

        return $lines->groupBy(fn (array $line) => (int) ($line['vat'] ?? $fallbackRate))
            ->map(function (Collection $rateLines, string|int $rate) use ($discountFactor) {
                $rateGross = round((float) $rateLines->sum(fn (array $line) => (float) ($line['total'] ?? 0)) * $discountFactor, 2);
                $vat = $this->vatConfiguration->taxPortion($rateGross, (int) $rate);

                return ['rate' => (float) $rate, 'gross' => $rateGross, 'vat' => $vat, 'net' => round($rateGross - $vat, 2)];
            })->values()->all();
    }

    private function reservationGross(Reservation $reservation): float
    {
        $charges = (float) $reservation->total_amount_base
            + (float) $reservation->folioItems->whereNotIn('type', ['discount', 'room'])->sum('amount_base');
        $discounts = (float) $reservation->folioItems->where('type', 'discount')->sum('amount_base');

        return round(max(0, $charges - $discounts), 2);
    }
}
