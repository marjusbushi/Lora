<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomInventorySnapshot;
use App\Models\RoomType;
use App\Tenancy\TenantContext;
use App\Console\Concerns\ResolvesTenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Nightly on-the-books snapshot: for every future stay date × room type,
 * record how many rooms are booked right now. Re-running the same night
 * updates in place (unique snapshot_date × stay_date × room_type_id), so the
 * history stays one row per day per night per type — the raw material for the
 * pickup-pace pricing factor ("Aug 15 as seen 30 days out vs 7 days out").
 */
class PricingSnapshot extends Command
{
    use ResolvesTenantContext;

    protected $signature = 'pricing:snapshot {--days=365 : How many future stay dates to snapshot} {--tenant= : ID e hotelit — i detyrueshëm për ekzekutim manual}';

    protected $description = 'Record on-the-books occupancy per future stay date and room type';

    public function handle(): int
    {
        if (! $this->ensureTenantContext()) {
            return self::FAILURE;
        }

        $today = Carbon::today();
        $days = max(1, (int) $this->option('days'));
        $horizon = $today->copy()->addDays($days - 1);

        // Supply per type: total rooms + how many are out of order (maintenance).
        $rooms = Room::get(['id', 'room_type_id', 'status'])->groupBy('room_type_id');
        $types = RoomType::pluck('id');

        // Every active reservation touching the window, mapped to its room's type.
        // Same night semantics as SmartPricing: check_in <= night < check_out
        // (the checkout day is free). Cancelled and checked_out don't hold future nights.
        $reservations = Reservation::whereNotIn('status', ['cancelled', 'checked_out'])
            ->whereDate('check_out_date', '>', $today)
            ->whereDate('check_in_date', '<=', $horizon)
            ->with('room:id,room_type_id')
            ->get(['id', 'room_id', 'check_in_date', 'check_out_date']);

        $rows = [];
        $now = now();
        $tenantId = app(TenantContext::class)->idOrDefault();

        foreach ($types as $typeId) {
            $typeRooms = $rooms->get($typeId, collect());
            $total = $typeRooms->count();
            $outOfOrder = $typeRooms->where('status', 'maintenance')->count();
            $typeReservations = $reservations->filter(fn ($r) => $r->room?->room_type_id === $typeId);

            for ($d = $today->copy(); $d->lte($horizon); $d->addDay()) {
                $night = $d->toDateString();
                $booked = $typeReservations
                    ->filter(fn ($r) => $r->check_in_date?->toDateString() <= $night
                        && $r->check_out_date?->toDateString() > $night)
                    ->pluck('room_id')->unique()->count();

                $rows[] = [
                    'tenant_id' => $tenantId,
                    'snapshot_date' => $today->toDateString(),
                    'stay_date' => $night,
                    'room_type_id' => $typeId,
                    'total_rooms' => $total,
                    'out_of_order' => $outOfOrder,
                    'booked' => $booked,
                    'available' => max(0, $total - $outOfOrder - $booked),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            RoomInventorySnapshot::upsert(
                $chunk,
                ['tenant_id', 'snapshot_date', 'stay_date', 'room_type_id'],
                ['total_rooms', 'out_of_order', 'booked', 'available', 'updated_at'],
            );
        }

        $this->info(sprintf('Snapshot %s: %d rows (%d types × %d days).',
            $today->toDateString(), count($rows), $types->count(), $days));

        return self::SUCCESS;
    }
}
