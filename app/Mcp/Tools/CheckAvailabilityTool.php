<?php

namespace App\Mcp\Tools;

use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\BaseCurrency;
use App\Services\RoomPricing;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class CheckAvailabilityTool extends LoraTool
{
    protected string $name = 'check-availability';

    protected string $description = 'Check real-time room-type availability and current stay prices for this hotel.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'check_in' => $schema->string()->description('YYYY-MM-DD')->required(),
            'check_out' => $schema->string()->description('YYYY-MM-DD')->required(),
            'adults' => $schema->integer()->min(1)->max(20)->default(1),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $this->user($request, 'view_reservations');
        abort_unless($this->enabled('reservations_enabled'), 403);
        $data = $request->validate([
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'adults' => ['nullable', 'integer', 'between:1,20'],
        ]);
        $from = Carbon::parse($data['check_in']);
        $to = Carbon::parse($data['check_out']);
        abort_if($from->diffInDays($to) > 31, 422, 'Maximum stay window is 31 nights.');

        $types = RoomType::withCount(['rooms' => fn ($q) => $q->where('status', '!=', 'maintenance')])
            ->where('max_occupancy', '>=', $data['adults'] ?? 1)->orderBy('name')->get();
        $booked = Reservation::query()->whereNotIn('status', ['cancelled', 'checked_out'])
            ->whereDate('check_in_date', '<', $data['check_out'])
            ->whereDate('check_out_date', '>', $data['check_in'])
            ->join('rooms', 'reservations.room_id', '=', 'rooms.id')
            ->selectRaw('rooms.room_type_id, count(distinct reservations.room_id) as booked')
            ->groupBy('rooms.room_type_id')->pluck('booked', 'rooms.room_type_id');
        $quotes = RoomPricing::quoteMany($types, $from, $to);

        return Response::structured([
            'currency' => BaseCurrency::code(),
            'check_in' => $data['check_in'],
            'check_out' => $data['check_out'],
            'room_types' => $types->map(fn ($type) => [
                'id' => $type->id,
                'name' => $type->name,
                'capacity' => $type->rooms_count,
                'booked' => (int) ($booked[$type->id] ?? 0),
                'available' => max(0, $type->rooms_count - (int) ($booked[$type->id] ?? 0)),
                'stay_total' => $quotes[$type->id]['total'] ?? null,
                'nightly_prices' => $quotes[$type->id]['breakdown'] ?? [],
                'breakfast_included' => (bool) $type->breakfast_included,
                'amenities' => $type->amenities ?? [],
            ])->values()->all(),
        ]);
    }
}
