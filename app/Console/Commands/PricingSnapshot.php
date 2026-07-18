<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesTenantContext;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomInventorySnapshot;
use App\Models\RoomType;
use App\Services\Reporting\ReportingPeriod;
use App\Services\Reporting\StayRevenueAllocator;
use App\Tenancy\TenantContext;
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

    public function handle(StayRevenueAllocator $revenueAllocator): int
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
        // (the checkout day is free). Only live on-the-books stays hold future nights.
        $reservations = Reservation::whereIn('status', ['confirmed', 'checked_in', 'pending'])
            ->whereNull('no_show_at')
            ->whereDate('check_out_date', '>', $today)
            ->whereDate('check_in_date', '<=', $horizon)
            ->with('room:id,room_type_id')
            ->get(['id', 'room_id', 'check_in_date', 'check_out_date', 'total_amount']);

        $rows = [];
        $now = now();
        $tenantId = app(TenantContext::class)->requireId();
        $snapshotPeriod = new ReportingPeriod($today->toDateString(), $horizon->toDateString());

        foreach ($types as $typeId) {
            $typeRooms = $rooms->get($typeId, collect());
            $total = $typeRooms->count();
            $outOfOrder = $typeRooms->where('status', 'maintenance')->count();
            $typeReservations = $reservations->filter(fn ($r) => $r->room?->room_type_id === $typeId);
            $revenueByReservation = $typeReservations->mapWithKeys(fn ($reservation) => [
                $reservation->id => $revenueAllocator->allocate(
                    $reservation->check_in_date,
                    $reservation->check_out_date,
                    $reservation->total_amount,
                    $snapshotPeriod,
                ),
            ]);

            for ($d = $today->copy(); $d->lte($horizon); $d->addDay()) {
                $night = $d->toDateString();
                $booked = $typeReservations
                    ->filter(fn ($r) => $r->check_in_date?->toDateString() <= $night
                        && $r->check_out_date?->toDateString() > $night)
                    ->pluck('room_id')->unique()->count();
                $bookedRevenue = $typeReservations
                    ->filter(fn ($r) => $r->check_in_date?->toDateString() <= $night
                        && $r->check_out_date?->toDateString() > $night)
                    ->sum(fn ($r) => $revenueByReservation[$r->id][$night] ?? 0);

                $rows[] = [
                    'tenant_id' => $tenantId,
                    'snapshot_date' => $today->toDateString(),
                    'stay_date' => $night,
                    'room_type_id' => $typeId,
                    'total_rooms' => $total,
                    'out_of_order' => $outOfOrder,
                    'booked' => $booked,
                    'booked_revenue' => round($bookedRevenue, 2),
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
                ['total_rooms', 'out_of_order', 'booked', 'booked_revenue', 'available', 'updated_at'],
            );
        }

        $this->info(sprintf('Snapshot %s: %d rows (%d types × %d days).',
            $today->toDateString(), count($rows), $types->count(), $days));

        return self::SUCCESS;
    }
}
