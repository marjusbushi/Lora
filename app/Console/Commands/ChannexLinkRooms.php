<?php

namespace App\Console\Commands;

use App\Models\ChannelMapping;
use App\Models\RoomType;
use App\Services\ChannexClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Link each PMS room type to its Channex room type + rate plan, writing a
 * channel_mappings row per pair. Matches by exact (case-insensitive) title, so
 * it works whether the Channex room types were created by the API bootstrap or
 * already existed. Idempotent (updateOrCreate) — safe to re-run.
 *
 *   php artisan channex:link-rooms          # link + save
 *   php artisan channex:link-rooms --dry     # show matches only
 */
class ChannexLinkRooms extends Command
{
    protected $signature = 'channex:link-rooms {--dry : Show the matches without saving}';

    protected $description = 'Map each PMS room type to its Channex room type + rate plan (channel_mappings)';

    public function handle(ChannexClient $channex): int
    {
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

        // First rate plan per Channex room type (the per_room "Standard Rate").
        // Channex is JSON:API — the room type is under relationships, not attributes.
        $ratePlanByRoomType = [];
        foreach ($ratePlans as $rp) {
            $rtId = $rp['relationships']['room_type']['data']['id'] ?? null;
            if ($rtId && ! isset($ratePlanByRoomType[$rtId])) {
                $ratePlanByRoomType[$rtId] = $rp['id'];
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
            $rpId = $ratePlanByRoomType[$rtId] ?? null;
            $this->line(sprintf('  %-34s -> %s (rate plan: %s)', $roomType->name, $rtId, $rpId ?? '—'));

            if (! $dry) {
                ChannelMapping::updateOrCreate(
                    ['channel' => 'channex', 'room_type_id' => $roomType->id],
                    [
                        'channex_property_id' => $propertyId,
                        'channex_room_type_id' => $rtId,
                        'channex_rate_plan_id' => $rpId,
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
