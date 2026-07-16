<?php

namespace App\Mcp\Tools;

use App\Models\MessageThread;
use App\Models\Reservation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetReservationContextTool extends LoraTool
{
    protected string $name = 'get-reservation-context';

    protected string $description = 'Get one reservation with guest contact, room, balance, notes, and the linked message thread. Never returns identity documents or payment credentials.';

    public function schema(JsonSchema $schema): array
    {
        return ['reservation_id' => $schema->integer()->minimum(1)->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $this->user($request, 'view_reservations');
        abort_unless($this->enabled('reservations_enabled'), 403);
        $data = $request->validate(['reservation_id' => ['required', 'integer', 'min:1']]);
        $reservation = Reservation::with(['guest', 'room.roomType', 'payments', 'folioItems'])->findOrFail($data['reservation_id']);
        $paid = (float) $reservation->payments->sum('amount');
        $thread = MessageThread::where('reservation_id', $reservation->id)->latest('id')->first();

        return Response::structured(['reservation' => [
            'id' => $reservation->id,
            'reference' => $reservation->channel_ref,
            'guest' => [
                'name' => $reservation->guest?->full_name,
                'email' => $reservation->guest?->email,
                'phone' => $reservation->guest?->phone,
                'nationality' => $reservation->guest?->nationality,
                'preferences' => $reservation->guest?->preferences,
            ],
            'stay' => [
                'check_in' => $reservation->check_in_date?->format('Y-m-d'),
                'check_out' => $reservation->check_out_date?->format('Y-m-d'),
                'adults' => $reservation->adults,
                'children' => $reservation->children,
                'room' => $reservation->room?->room_number,
                'room_type' => $reservation->room?->roomType?->name,
                'status' => $reservation->status,
                'eta' => $reservation->eta,
            ],
            'financial' => [
                'total' => (float) $reservation->total_amount,
                'paid' => $paid,
                'balance' => round((float) $reservation->total_amount - $paid, 2),
            ],
            'notes' => $reservation->notes,
            'channel' => $reservation->channel,
            'message_thread_id' => $thread?->id,
        ]]);
    }
}
