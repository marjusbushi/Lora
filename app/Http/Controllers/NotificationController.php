<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    /**
     * Recent NEW (pending) reservations for the admin notification bell.
     * Polled every ~20s by NotificationBell.vue.
     */
    public function reservations(): JsonResponse
    {
        $pending = Reservation::where('status', 'pending')
            ->with([
                'guest:id,first_name,last_name',
                'room:id,room_number,room_type_id',
                'room.roomType:id,name',
            ])
            ->latest('id')
            ->limit(15)
            ->get();

        return response()->json([
            'count' => $pending->count(),
            'reservations' => $pending->map(fn ($r) => [
                'id' => $r->id,
                'guest' => trim(($r->guest?->first_name ?? '') . ' ' . ($r->guest?->last_name ?? '')) ?: 'Mysafir',
                'room' => $r->room?->room_number . ($r->room?->roomType ? ' — ' . $r->room->roomType->name : ''),
                'check_in' => optional($r->check_in_date)->format('d/m/Y'),
                'check_out' => optional($r->check_out_date)->format('d/m/Y'),
                'total' => $r->total_amount,
                'created_at' => optional($r->created_at)->toIso8601String(),
            ]),
        ]);
    }
}
