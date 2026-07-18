<?php

namespace App\Services\Reporting;

use App\Models\FolioItem;
use App\Models\Payment;
use App\Models\PosOrder;
use App\Models\Reservation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class GuestMovementService
{
    /** @return array{period:array,arrivals:array,departures:array,in_house:array,summary:array} */
    public function summary(ReportingPeriod $period): array
    {
        $arrivals = Reservation::query()
            ->whereDate('check_in_date', '>=', $period->from->toDateString())
            ->whereDate('check_in_date', '<=', $period->to->toDateString())
            ->whereIn('status', ['pending', 'confirmed', 'checked_in', 'checked_out'])
            ->whereNull('no_show_at')
            ->with(['room:id,room_number,room_type_id', 'room.roomType:id,name', 'guest:id,first_name,last_name,phone'])
            ->orderBy('check_in_date')
            ->get();
        $departures = Reservation::query()
            ->whereDate('check_out_date', '>=', $period->from->toDateString())
            ->whereDate('check_out_date', '<=', $period->to->toDateString())
            ->whereIn('status', ['checked_in', 'checked_out'])
            ->with(['room:id,room_number,room_type_id', 'room.roomType:id,name', 'guest:id,first_name,last_name,phone'])
            ->orderBy('check_out_date')
            ->get();
        $inHouse = Reservation::query()
            ->where('status', 'checked_in')
            ->with(['room:id,room_number,room_type_id', 'room.roomType:id,name', 'guest:id,first_name,last_name,phone'])
            ->orderBy('room_id')
            ->get();
        $all = $arrivals->concat($departures)->concat($inHouse)->unique('id')->values();
        [$folio, $payments] = $this->financials($all->pluck('id'));
        $openPos = PosOrder::query()
            ->whereIn('reservation_id', $departures->pluck('id'))
            ->where('status', 'open')
            ->select('reservation_id', DB::raw('COUNT(*) as open_count'))
            ->groupBy('reservation_id')
            ->pluck('open_count', 'reservation_id');

        $arrivalRows = $arrivals->map(fn (Reservation $reservation) => $this->row($reservation, $folio, $payments));
        $departureRows = $departures->map(function (Reservation $reservation) use ($folio, $payments, $openPos) {
            return array_merge($this->row($reservation, $folio, $payments), [
                'open_pos_count' => (int) ($openPos[$reservation->id] ?? 0),
            ]);
        });
        $inHouseRows = $inHouse->map(fn (Reservation $reservation) => $this->row($reservation, $folio, $payments));

        return [
            'period' => $period->toArray(),
            'arrivals' => $arrivalRows->all(),
            'departures' => $departureRows->all(),
            'in_house' => $inHouseRows->all(),
            'summary' => [
                'arrivals' => $this->totals($arrivalRows),
                'departures' => array_merge($this->totals($departureRows), [
                    'open_pos' => $departureRows->sum('open_pos_count'),
                ]),
                'in_house' => $this->totals($inHouseRows),
            ],
        ];
    }

    /** @return array{0:Collection,1:Collection} */
    private function financials(Collection $ids): array
    {
        $folio = FolioItem::query()
            ->whereIn('reservation_id', $ids)
            ->select(
                'reservation_id',
                DB::raw("SUM(CASE WHEN type NOT IN ('discount','room') THEN amount ELSE 0 END) as charges"),
                DB::raw("SUM(CASE WHEN type = 'discount' THEN amount ELSE 0 END) as discounts"),
            )
            ->groupBy('reservation_id')
            ->get()
            ->keyBy('reservation_id');
        $payments = Payment::query()
            ->whereIn('reservation_id', $ids)
            ->notVoided()
            ->select('reservation_id', DB::raw("SUM(CASE WHEN COALESCE(type, 'payment') IN ('payment', 'deposit', 'writeoff') THEN amount WHEN type = 'refund' THEN -ABS(amount) ELSE 0 END) as paid"))
            ->groupBy('reservation_id')
            ->pluck('paid', 'reservation_id');

        return [$folio, $payments];
    }

    private function row(Reservation $reservation, Collection $folio, Collection $payments): array
    {
        $gross = (float) $reservation->total_amount
            + (float) ($folio[$reservation->id]->charges ?? 0)
            - (float) ($folio[$reservation->id]->discounts ?? 0);

        return [
            'id' => $reservation->id,
            'guest' => trim("{$reservation->guest?->first_name} {$reservation->guest?->last_name}") ?: '—',
            'phone' => $reservation->guest?->phone,
            'room' => $reservation->room?->room_number,
            'room_type' => $reservation->room?->roomType?->name,
            'status' => $reservation->status,
            'check_in' => $reservation->check_in_date?->toDateString(),
            'check_out' => $reservation->check_out_date?->toDateString(),
            'nights' => (int) $reservation->nights,
            'adults' => (int) $reservation->adults,
            'children' => (int) $reservation->children,
            'pax' => (int) $reservation->adults + (int) $reservation->children,
            'channel' => Reservation::normalizeChannel($reservation->channel),
            'balance' => round($gross - (float) ($payments[$reservation->id] ?? 0), 2),
        ];
    }

    private function totals(Collection $rows): array
    {
        return [
            'count' => $rows->count(),
            'pax' => $rows->sum('pax'),
            'nights' => $rows->sum('nights'),
            'balance' => round((float) $rows->sum(fn (array $row) => max(0, (float) $row['balance'])), 2),
            'credit' => round((float) $rows->sum(fn (array $row) => max(0, -(float) $row['balance'])), 2),
        ];
    }
}
