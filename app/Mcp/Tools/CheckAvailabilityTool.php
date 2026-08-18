<?php

namespace App\Mcp\Tools;

use App\Services\BaseCurrency;
use App\Services\GuestStayQuote;
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
        abort_if($from->diffInDays($to) > GuestStayQuote::MAX_NIGHTS, 422, 'Maximum stay window is 31 nights.');

        // Shared availability+pricing resolver (also feeds Lora's guest chat) —
        // the output shape below is UNCHANGED: ChatGPT-side consumers rely on it.
        $rows = app(GuestStayQuote::class)->rows($from, $to, (int) ($data['adults'] ?? 1));

        return Response::structured([
            'currency' => BaseCurrency::code(),
            'check_in' => $data['check_in'],
            'check_out' => $data['check_out'],
            'room_types' => collect($rows)->map(fn (array $row) => [
                'id' => $row['type']->id,
                'name' => $row['type']->name,
                'capacity' => $row['type']->rooms_count,
                'booked' => $row['booked'],
                'available' => $row['available'],
                'stay_total' => $row['quote']['total'] ?? null,
                'nightly_prices' => $row['quote']['breakdown'] ?? [],
                'breakfast_included' => (bool) $row['type']->breakfast_included,
                'amenities' => $row['type']->amenities ?? [],
            ])->values()->all(),
        ]);
    }
}
