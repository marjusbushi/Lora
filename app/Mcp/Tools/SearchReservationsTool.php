<?php

namespace App\Mcp\Tools;

use App\Models\Reservation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class SearchReservationsTool extends LoraTool
{
    protected string $name = 'search-reservations';

    protected string $description = 'Search this hotel reservations by guest, booking reference, stay dates, or reservation ID. Returns operational data only; identity documents are never exposed.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'reservation_id' => $schema->integer()->min(1),
            'query' => $schema->string()->max(120)->description('Guest name, email, phone, or channel booking reference.'),
            'date_from' => $schema->string()->description('Optional YYYY-MM-DD overlap start.'),
            'date_to' => $schema->string()->description('Optional YYYY-MM-DD overlap end.'),
            'limit' => $schema->integer()->min(1)->max(20)->default(10),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $this->user($request, 'view_reservations');
        abort_unless($this->enabled('reservations_enabled'), 403);
        $data = $request->validate([
            'reservation_id' => ['nullable', 'integer', 'min:1'],
            'query' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'limit' => ['nullable', 'integer', 'between:1,20'],
        ]);

        $rows = Reservation::query()->with(['guest:id,first_name,last_name,email,phone', 'room:id,room_number,room_type_id', 'room.roomType:id,name'])
            ->when($data['reservation_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($data['query'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_').'%';
                $q->where(function ($query) use ($like) {
                    $query->where('channel_ref', 'like', $like)
                        ->orWhereHas('guest', fn ($guest) => $guest
                            ->where('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone', 'like', $like));
                });
            })
            ->when($data['date_from'] ?? null, fn ($q, $date) => $q->whereDate('check_out_date', '>', $date))
            ->when($data['date_to'] ?? null, fn ($q, $date) => $q->whereDate('check_in_date', '<=', $date))
            ->latest('check_in_date')->limit($data['limit'] ?? 10)->get();

        return Response::structured([
            'count' => $rows->count(),
            'reservations' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'reference' => $r->channel_ref,
                'guest' => $r->guest?->full_name,
                'contact' => ['email' => $r->guest?->email, 'phone' => $r->guest?->phone],
                'check_in' => $r->check_in_date?->format('Y-m-d'),
                'check_out' => $r->check_out_date?->format('Y-m-d'),
                'status' => $r->status,
                'room' => $r->room?->room_number,
                'room_type' => $r->room?->roomType?->name,
                'total_amount' => (float) $r->total_amount,
                'channel' => $r->channel,
            ])->values()->all(),
        ]);
    }
}
