<?php

namespace App\Services\Reporting;

use App\Models\FolioItem;
use App\Models\Guest;
use App\Models\PosOrder;
use App\Models\Reservation;
use Illuminate\Support\Collection;

final class GuestLifetimeValueService
{
    /** @return array{as_of:string,summary:array,segments:array,guests:array} */
    public function summary(?int $guestLimit = 100): array
    {
        $guests = Guest::query()
            ->with(['reservations' => fn ($query) => $query
                ->whereIn('status', ['pending', 'confirmed', 'checked_in', 'checked_out'])
                ->whereNull('no_show_at')
                ->select('id', 'guest_id', 'check_in_date', 'check_out_date', 'status', 'total_amount', 'commission_amount')])
            ->get(['id', 'first_name', 'last_name', 'email', 'phone']);

        $realizedIds = $guests->pluck('reservations')->flatten()
            ->whereIn('status', ['checked_in', 'checked_out'])
            ->pluck('id');
        $folioItems = FolioItem::query()
            ->whereIn('reservation_id', $realizedIds)
            ->get(['reservation_id', 'pos_order_id', 'type', 'amount']);
        $folioByReservation = $folioItems->groupBy('reservation_id');
        $folioPosIds = $folioItems->pluck('pos_order_id')->filter()->unique();
        $directPosByReservation = PosOrder::query()
            ->with('payments:id,pos_order_id,direction,amount')
            ->whereIn('reservation_id', $realizedIds)
            ->where('status', 'completed')
            ->whereNotIn('id', $folioPosIds)
            ->get(['id', 'reservation_id', 'total_amount', 'refunded_at'])
            ->groupBy('reservation_id');

        $rows = $guests->map(function (Guest $guest) use ($folioByReservation, $directPosByReservation) {
            $realized = $guest->reservations->whereIn('status', ['checked_in', 'checked_out']);
            if ($realized->isEmpty()) {
                return null;
            }

            $upcoming = $guest->reservations
                ->whereIn('status', ['pending', 'confirmed'])
                ->filter(fn (Reservation $reservation) => $reservation->check_in_date?->isAfter(today()));
            $gross = (float) $realized->sum('total_amount');
            $commission = (float) $realized->sum('commission_amount');
            $reservationIds = $realized->pluck('id');
            $folioValue = $reservationIds->sum(function (int $reservationId) use ($folioByReservation) {
                return $folioByReservation->get($reservationId, collect())->sum(fn (FolioItem $item) => match ($item->type) {
                    'room' => 0.0,
                    'discount' => -abs((float) $item->amount),
                    default => (float) $item->amount,
                });
            });
            $directPosValue = $reservationIds->sum(function (int $reservationId) use ($directPosByReservation) {
                return $directPosByReservation->get($reservationId, collect())->sum(function (PosOrder $order) {
                    if ($order->payments->isNotEmpty()) {
                        return (float) $order->payments->where('direction', 'in')->sum('amount')
                            - (float) $order->payments->where('direction', 'out')->sum('amount');
                    }

                    return $order->refunded_at ? 0.0 : (float) $order->total_amount;
                });
            });
            $ancillary = $folioValue + $directPosValue;
            $stays = $realized->count();
            $lastVisit = $realized->max('check_in_date');

            return [
                'id' => $guest->id,
                'guest' => trim("{$guest->first_name} {$guest->last_name}") ?: '—',
                'email' => $guest->email,
                'phone' => $guest->phone,
                'stays' => $stays,
                'nights' => (int) $realized->sum(fn (Reservation $reservation) => $reservation->nights),
                'gross_value' => round($gross + $ancillary, 2),
                'ancillary_value' => round($ancillary, 2),
                'commission' => round($commission, 2),
                'net_value' => round($gross + $ancillary - $commission, 2),
                'average_stay_value' => round(($gross + $ancillary - $commission) / $stays, 2),
                'first_visit' => $realized->min('check_in_date')?->toDateString(),
                'last_visit' => $lastVisit?->toDateString(),
                'days_since_last' => $lastVisit ? (int) $lastVisit->startOfDay()->diffInDays(today()) : null,
                'upcoming_stays' => $upcoming->count(),
                'upcoming_value' => round((float) $upcoming->sum('total_amount'), 2),
                'segment' => $this->segment($stays),
            ];
        })->filter()->values();

        $repeat = $rows->where('stays', '>=', 2);
        $netValue = (float) $rows->sum('net_value');

        return [
            'as_of' => today()->toDateString(),
            'summary' => [
                'total_guests' => $rows->count(),
                'repeat_guests' => $repeat->count(),
                'repeat_rate' => $rows->isEmpty() ? 0.0 : round($repeat->count() / $rows->count() * 100, 1),
                'loyal_guests' => $rows->where('stays', '>=', 3)->count(),
                'net_lifetime_value' => round($netValue, 2),
                'average_ltv' => $rows->isEmpty() ? 0.0 : round($netValue / $rows->count(), 2),
                'repeat_value_share' => $netValue > 0 ? round((float) $repeat->sum('net_value') / $netValue * 100, 1) : 0.0,
                'upcoming_value' => round((float) $rows->sum('upcoming_value'), 2),
            ],
            'segments' => $this->segments($rows),
            'guests' => ($guestLimit === null ? $rows->sortByDesc('net_value') : $rows->sortByDesc('net_value')->take($guestLimit))->values()->all(),
        ];
    }

    private function segment(int $stays): string
    {
        return $stays >= 3 ? 'loyal' : ($stays === 2 ? 'returning' : 'one_time');
    }

    private function segments(Collection $rows): array
    {
        return collect(['one_time', 'returning', 'loyal'])->map(fn (string $segment) => [
            'key' => $segment,
            'guests' => $rows->where('segment', $segment)->count(),
            'net_value' => round((float) $rows->where('segment', $segment)->sum('net_value'), 2),
        ])->all();
    }
}
