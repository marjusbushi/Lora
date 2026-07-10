<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    /**
     * Recent reservations for the staff notification bell.
     *
     * A new OTA booking is normally created as `confirmed`, so filtering only
     * `pending` silently drops the reservations staff most need to notice.
     */
    public function reservations(): JsonResponse
    {
        $recent = Reservation::where('status', '!=', 'cancelled')
            ->with([
                'guest:id,first_name,last_name',
                'room:id,room_number,room_type_id',
                'room.roomType:id,name',
            ])
            ->latest('id')
            ->limit(30)
            ->get();

        return response()->json([
            'count' => $recent->count(),
            'reservations' => $recent->map(fn ($r) => [
                'id' => $r->id,
                'guest' => trim(($r->guest?->first_name ?? '').' '.($r->guest?->last_name ?? '')) ?: 'Mysafir',
                'room' => $r->room?->room_number.($r->room?->roomType ? ' — '.$r->room->roomType->name : ''),
                'check_in' => optional($r->check_in_date)->format('d/m/Y'),
                'check_out' => optional($r->check_out_date)->format('d/m/Y'),
                'total' => $r->total_amount,
                'channel' => $r->channel,
                'created_by' => $r->created_by,
                'created_at' => optional($r->created_at)->toIso8601String(),
            ]),
        ]);
    }
}
