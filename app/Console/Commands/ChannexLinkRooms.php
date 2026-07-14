<?php

namespace App\Console\Commands;

use App\Models\ChannelMapping;
use App\Models\RoomType;
use App\Services\ChannelSync;
use App\Services\ChannexClient;
use App\Console\Concerns\ResolvesTenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Link each PMS room type to its Channex room type + rate plans, writing a
 * channel_mappings row per pair. Matches by exact (case-insensitive) title, so
 * it works whether the Channex room types were created by the API bootstrap or
 * already existed. Rate plans are classified by ROLE from their title: the
 * per-channel plans (Booking.com / Expedia — see ChannelSync's title
 * constants) fill their own columns; the first remaining plan is the BASE.
 * Idempotent (updateOrCreate) — safe to re-run.
 *
 *   php artisan channex:link-rooms          # link + save
 *   php artisan channex:link-rooms --dry     # show matches only
 */
class ChannexLinkRooms extends Command
{
    use ResolvesTenantContext;

    protected $signature = 'channex:link-rooms {--dry : Show the matches without saving} {--tenant= : ID e hotelit — i detyrueshëm për ekzekutim manual}';

    protected $description = 'Map each PMS room type to its Channex room type + rate plan (channel_mappings)';

    public function handle(ChannexClient $channex): int
    {
        if (! $this->ensureTenantContext()) {
            return self::FAILURE;
        }

        if (! $channex->configured()) {
            $this->error('CHANNEX_API_KEY is not set (.env).');

            return self::FAILURE;
        }

        $propertyId = $channex->propertyId();

        try {
            $channexRoomTypes = collect($channex->getRoomTypes());
            $ratePlans = $channex->getRatePlans();
        } catch (\Throwable $e) {
            // A bad key / lost access now surfaces (getList throws) instead of
            // looking like an empty account — fail loud, don't half-link.
            report($e);
            $this->error('Could not read from Channex — check the API key / connection (see logs).');

            return self::FAILURE;
        }

        if ($channexRoomTypes->isEmpty()) {
            $this->warn("No Channex room types found for property {$propertyId}.");

            return self::SUCCESS;
        }

        // Classify each room type's rate plans by ROLE from the title: the
        // Booking.com / Expedia plans are matched exactly; the first plan that
        // is neither becomes the BASE (covers legacy "Standard Rate" setups).
        // Channex is JSON:API — the room type is under relationships, not attributes.
        $bookingTitle = Str::lower(ChannelSync::RATE_PLAN_TITLE_BOOKING);
        $expediaTitle = Str::lower(ChannelSync::RATE_PLAN_TITLE_EXPEDIA);
        $plansByRoomType = [];
        foreach ($ratePlans as $rp) {
            $rtId = $rp['relationships']['room_type']['data']['id'] ?? null;
            if (! $rtId) {
                continue;
            }
            $title = Str::lower(trim($rp['attributes']['title'] ?? ''));
            $role = match ($title) {
                $bookingTitle => 'booking',
                $expediaTitle => 'expedia',
                default => 'base',
            };
            if ($role !== 'base' || ! isset($plansByRoomType[$rtId]['base'])) {
                $plansByRoomType[$rtId][$role] ??= $rp['id'];
            }
        }

        $linked = 0;
        $unmatched = [];
        $dry = (bool) $this->option('dry');

        foreach (RoomType::orderBy('id')->get() as $roomType) {
            $match = $channexRoomTypes->first(fn ($rt) => Str::lower(trim($rt['attributes']['title'] ?? '')) === Str::lower(trim($roomType->name)));

            if (! $match) {
                $unmatched[] = $roomType->name;

                continue;
            }

            $rtId = $match['id'];
            $plans = $plansByRoomType[$rtId] ?? [];
            $this->line(sprintf(
                '  %-34s -> %s (base: %s | booking: %s | expedia: %s)',
                $roomType->name,
                $rtId,
                $plans['base'] ?? '—',
                $plans['booking'] ?? '—',
                $plans['expedia'] ?? '—',
            ));

            if (! $dry) {
                ChannelMapping::updateOrCreate(
                    ['channel' => 'channex', 'room_type_id' => $roomType->id],
                    [
                        'channex_property_id' => $propertyId,
                        'channex_room_type_id' => $rtId,
                        'channex_rate_plan_id' => $plans['base'] ?? null,
                        'channex_booking_rate_plan_id' => $plans['booking'] ?? null,
                        'channex_expedia_rate_plan_id' => $plans['expedia'] ?? null,
                    ],
                );
            }
            $linked++;
        }

        if ($unmatched !== []) {
            $this->warn('Unmatched PMS room types (no Channex room type with the same name): '.implode(', ', $unmatched));
        }
        $this->info(($dry ? '[dry] ' : '')."{$linked} room type(s) linked to Channex.");

        return self::SUCCESS;
    }
}
